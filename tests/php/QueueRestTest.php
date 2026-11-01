<?php
/**
 * Bulk queue (progress, pause/resume/cancel, estimate, auto-sync) and REST permissions.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

use Dev3\AiTranslatorLocalizer\Core3\Core\Settings_Store;
use Dev3\AiTranslatorLocalizer\Queue\Estimator;
use Dev3\AiTranslatorLocalizer\Queue\Queue;
use Dev3\AiTranslatorLocalizer\Sync\Sync_Engine;

class QueueRestTest extends Dev3tl_Test_Case {

	public function set_up() {
		parent::set_up();
		if ( ! $this->polylang() ) {
			$this->markTestSkipped( 'Polylang not loadable.' );
		}
		$this->mock = new Dev3tl_Mock_Gemini();
	}

	/**
	 * Three source pages.
	 *
	 * @return int[]
	 */
	private function pages() {
		$ids = array();
		foreach ( array( 'Alpha', 'Beta', 'Gamma' ) as $title ) {
			$ids[] = $this->source_post( '<p>' . $title . ' paragraph.</p>', array( 'post_title' => $title ) );
		}
		return $ids;
	}

	public function test_job_progress_pause_resume_cancel() {
		$this->admin();
		$pages = $this->pages();
		$job   = Queue::create(
			array(
				'post_types' => array( 'page' ),
				'languages'  => array( 'de', 'fr', 'xx' ),
			)
		);
		$this->assertSame( 6, $job['total'] ); // 3 pages × de, fr (xx ignored).
		$this->assertSame( array( 'de', 'fr' ), $job['languages'] );
		$this->assertSame( 'queued', $job['status'] );

		// Process exactly one item.
		$after = Queue::process( $job['id'], 30, 1 );
		$this->assertSame( 1, $after['done'] );
		$this->assertSame( 'running', $after['status'] );
		$this->assertSame( 16, $after['progress'] );

		$paused = Queue::control( $job['id'], 'pause' );
		$this->assertSame( 'paused', $paused['status'] );
		$still = Queue::process( $job['id'] );
		$this->assertSame( $after['done'], $still['done'] ); // Paused jobs do not move.

		Queue::control( $job['id'], 'resume' );
		$done = Queue::process( $job['id'], 60 );
		$this->assertSame( 'done', $done['status'] );
		$this->assertSame( 6, $done['done'] );
		$this->assertSame( 100, $done['progress'] );
		$this->assertSame( 12, $done['segments'] ); // Title + paragraph per item.
		$items = Queue::items( $job['id'] );
		$this->assertSame( 6, $items['total'] );
		$this->assertSame( 'done', $items['items'][0]['status'] );
		$this->assertNotEmpty( pll_get_post( $pages[0], 'fr' ) );

		// Only-missing on a fully translated site → nothing to do.
		$this->assertWPError(
			Queue::create(
				array(
					'post_types'   => array( 'page' ),
					'languages'    => array( 'de' ),
					'only_missing' => true,
				)
			)
		);

		// Cancel.
		$job2      = Queue::create(
			array(
				'post_types' => array( 'page' ),
				'languages'  => array( 'de' ),
			)
		);
		$cancelled = Queue::control( $job2['id'], 'cancel' );
		$this->assertSame( 'cancelled', $cancelled['status'] );
		$this->assertSame( 'cancelled', Queue::items( $job2['id'] )['items'][0]['status'] );
		$this->assertSame( 'cancelled', Queue::process( $job2['id'] )['status'] );
		$this->assertTrue( Queue::delete( $job2['id'] ) );
	}

	public function test_failures_are_logged_per_item() {
		$this->admin();
		$this->pages();
		$this->mock->stop();
		$this->mock = null;
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Quota exceeded', 'status' => 'RESOURCE_EXHAUSTED' ) ) ),
					'response' => array( 'code' => 429 ),
					'cookies'  => array(),
				);
			}
		);
		$job  = Queue::create(
			array(
				'post_types' => array( 'page' ),
				'languages'  => array( 'de' ),
			)
		);
		$done = Queue::process( $job['id'], 60 );
		$this->assertSame( 3, $done['failed'] );
		$this->assertSame( 'done', $done['status'] );
		$this->assertNotEmpty( Queue::items( $job['id'] )['items'][0]['message'] );
	}

	public function test_estimate_counts_segments_and_tm_reuse() {
		$this->admin();
		$pages = $this->pages();
		$est   = Estimator::estimate(
			array(
				'post_types' => array( 'page' ),
				'languages'  => array( 'de' ),
			)
		);
		$this->assertSame( 3, $est['posts'] );
		$this->assertSame( 6, $est['segments'] );
		$this->assertSame( 0, $est['reused'] );
		$this->assertGreaterThan( 0, $est['tokens'] );
		$this->assertGreaterThan( 0, $est['cost_usd'] + 0.001 );
		$this->assertSame( 'gemini-2.5-pro', $est['model'] );
		Sync_Engine::sync( $pages[0], 'de' );
		$est = Estimator::estimate(
			array(
				'post_types' => array( 'page' ),
				'languages'  => array( 'de' ),
			)
		);
		$this->assertSame( 2, $est['reused'] );
	}

	public function test_auto_sync_queues_a_delta_job_on_update() {
		$this->admin();
		Settings_Store::update( array( 'auto_sync' => true ) );
		$page = $this->source_post( '<p>Original.</p>' );
		Sync_Engine::sync( $page, 'de' );
		wp_update_post(
			array(
				'ID'           => $page,
				'post_content' => '<p>Changed.</p>',
			)
		);
		$jobs = Queue::recent( 1 );
		$this->assertSame( 'sync', $jobs[0]['type'] );
		$this->assertSame( array( 'de' ), $jobs[0]['languages'] );
		$this->mock->requests = array();
		$done                 = Queue::process( $jobs[0]['id'], 30 );
		$this->assertSame( 1, $done['sent'] );
		$this->assertSame( 1, $done['reused'] );
		$this->assertStringContainsString( '[T] Changed.', get_post( pll_get_post( $page, 'de' ) )->post_content );
		// Our own write did not queue another job.
		$this->assertSame( $jobs[0]['id'], Queue::recent( 1 )[0]['id'] );
	}

	/**
	 * REST request helper.
	 *
	 * @param string $method Method.
	 * @param string $route  Route.
	 * @param array  $params Params.
	 * @param bool   $nonce  Send nonce.
	 * @return WP_REST_Response
	 */
	private function rest( $method, $route, array $params = array(), $nonce = true ) {
		$req = new WP_REST_Request( $method, '/dev3tl/v1/' . $route );
		if ( $nonce ) {
			$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return rest_do_request( $req );
	}

	public function test_rest_permissions() {
		do_action( 'rest_api_init' );
		$manage = array( 'dashboard', 'jobs', 'glossary', 'locales', 'post-types' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		foreach ( $manage as $route ) {
			$this->assertSame( 403, $this->rest( 'GET', $route )->get_status(), $route );
		}
		$this->assertSame( 403, $this->rest( 'GET', 'review' )->get_status() );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		foreach ( $manage as $route ) {
			$this->assertSame( 403, $this->rest( 'GET', $route )->get_status(), $route );
		}
		$this->assertSame( 403, $this->rest( 'POST', 'jobs/create', array( 'languages' => array( 'de' ) ) )->get_status() );
		$this->assertSame( 200, $this->rest( 'GET', 'review' )->get_status() );

		$page = $this->source_post( '<p>Hello.</p>' );
		$this->assertSame( 200, $this->rest( 'POST', 'translate', array( 'post_id' => $page, 'languages' => array( 'de' ) ) )->get_status() );

		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$this->assertSame( 403, $this->rest( 'POST', 'translate', array( 'post_id' => $page, 'languages' => array( 'de' ) ) )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', 'review/' . $page . '/de' )->get_status() );

		$this->admin();
		$this->assertSame( 403, $this->rest( 'GET', 'dashboard', array(), false )->get_status() ); // Nonce required.
		$res = $this->rest( 'GET', 'dashboard' );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'polylang', $res->get_data()['active']['id'] );
		$this->assertSame( 200, $this->rest( 'POST', 'glossary/save', array( 'term' => 'DEV3', 'dnt' => true ) )->get_status() );
		$saved = $this->rest(
			'POST',
			'glossary/save',
			array(
				'term'         => 'Cart',
				'translations' => array( 'de_DE' => 'Warenkorb' ),
			)
		)->get_data();
		$this->assertSame( 'Warenkorb', $saved['translations']['de_DE'] );
		$this->assertCount( 2, $this->rest( 'GET', 'glossary' )->get_data() );
		$this->assertSame( 2, $this->rest( 'POST', 'glossary/import', array( 'csv' => "term,dnt,de_DE\nOakline,yes,\nchair,no,Stuhl" ) )->get_data()['imported'] );
		$locale = $this->rest( 'POST', 'locales/de_DE', array( 'formality' => 'informal' ) )->get_data();
		$this->assertSame( 'informal', $locale['formality'] );
		$review = $this->rest( 'GET', 'review/' . $page . '/de' )->get_data();
		$this->assertNotEmpty( $review['segments'] );
		$this->assertSame( 'Hello.', $review['segments'][1]['source'] );
	}
}
