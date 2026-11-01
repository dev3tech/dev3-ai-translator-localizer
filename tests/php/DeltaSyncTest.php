<?php
/**
 * Translation memory + delta sync through the real Polylang adapter, AI mocked via pre_http_request.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

use Dev3\AiTranslatorLocalizer\Data\Translation_Memory;
use Dev3\AiTranslatorLocalizer\Providers\Providers;
use Dev3\AiTranslatorLocalizer\Sync\Review;
use Dev3\AiTranslatorLocalizer\Sync\Sync_Engine;

class DeltaSyncTest extends Dev3tl_Test_Case {

	const CONTENT = "<!-- wp:paragraph -->\n<p>First paragraph about desks.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Second paragraph with a <a href=\"/x\">link</a>.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Third: a heading</h2>\n<!-- /wp:heading -->";

	public function set_up() {
		parent::set_up();
		if ( ! $this->polylang() ) {
			$this->markTestSkipped( 'Polylang is not loadable in this environment.' );
		}
		$this->admin();
		$this->mock = new Dev3tl_Mock_Gemini(
			static function ( $text ) {
				return '[DE] ' . $text;
			}
		);
	}

	public function test_first_sync_creates_linked_translation_and_fills_tm() {
		$this->assertSame( 'polylang', Providers::active()->id() );
		$post  = $this->source_post( self::CONTENT );
		$stats = Sync_Engine::sync( $post, 'de' );
		$this->assertIsArray( $stats, is_wp_error( $stats ) ? $stats->get_error_message() : '' );
		$this->assertSame( 'done', $stats['status'] );
		$this->assertSame( 4, $stats['total'] ); // Title + 3 blocks.
		$this->assertSame( 0, $stats['reused'] );
		$this->assertSame( 4, $stats['sent'] );
		$this->assertCount( 1, $this->mock->requests ); // One batched request.

		$target = pll_get_post( $post, 'de' );
		$this->assertSame( $stats['target_id'], $target );
		$this->assertSame( 'de', pll_get_post_language( $target ) );
		$this->assertSame( 'en', pll_get_post_language( $post ) );
		$tr = get_post( $target );
		$this->assertSame( '[DE] Standing desk guide', $tr->post_title );
		$this->assertStringContainsString( '<p>[DE] Second paragraph with a <a href="/x">link</a>.</p>', $tr->post_content );
		$this->assertStringContainsString( '<!-- wp:heading -->', $tr->post_content );
		$this->assertSame( 4, Translation_Memory::stats()['by_status']['machine'] );

		// Schema + model + thinking budget in the request.
		$body = $this->mock->requests[0]['body'];
		$this->assertSame( 'ARRAY', $body['generationConfig']['responseSchema']['type'] );
		$this->assertArrayHasKey( 'maxLength', $body['generationConfig']['responseSchema']['items']['properties']['translation'] );
		$this->assertSame( 1024, $body['generationConfig']['thinkingConfig']['thinkingBudget'] );
		$this->assertStringContainsString( 'gemini-2.5-pro', $this->mock->requests[0]['url'] );
	}

	public function test_editing_one_paragraph_sends_only_that_segment() {
		$post   = $this->source_post( self::CONTENT );
		$first  = Sync_Engine::sync( $post, 'de' );
		$target = $first['target_id'];

		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => str_replace( 'Second paragraph with a', 'Second paragraph, now edited, with a', self::CONTENT ),
			)
		);
		$this->mock->requests = array();
		$second               = Sync_Engine::sync( $post, 'de' );

		$this->assertSame( 4, $second['total'] );
		$this->assertSame( 3, $second['reused'] );
		$this->assertSame( 1, $second['sent'] );
		$this->assertCount( 1, $this->mock->items() );
		$this->assertSame( 'Second paragraph, now edited, with a <1>link</1>.', $this->mock->items()[0]['text'] );
		$this->assertSame( $target, $second['target_id'] ); // Updated in place.
		$content = get_post( $target )->post_content;
		$this->assertStringContainsString( '[DE] Second paragraph, now edited, with a <a href="/x">link</a>.', $content );
		$this->assertStringContainsString( '[DE] First paragraph about desks.', $content );
		$recorded = Sync_Engine::stats( $post )['de'];
		$this->assertSame( 3, $recorded['reused'] );
		$this->assertSame( 4, $recorded['total'] );

		// Nothing changed → no request at all.
		$this->mock->requests = array();
		$third                = Sync_Engine::sync( $post, 'de' );
		$this->assertSame( 0, $third['sent'] );
		$this->assertCount( 0, $this->mock->requests );
	}

	public function test_human_edits_survive_delta_and_forced_sync() {
		$post = $this->source_post( self::CONTENT );
		Sync_Engine::sync( $post, 'de' );
		$review = Review::get( $post, 'de' );
		$row    = null;
		foreach ( $review['segments'] as $segment ) {
			if ( 0 === strpos( $segment['source'], 'First paragraph' ) ) {
				$row = $segment;
			}
		}
		$this->assertSame( 'machine', $row['status'] );
		$saved = Review::save(
			$post,
			'de',
			array(
				array(
					'hash'        => $row['hash'],
					'translation' => 'Erster Absatz über Schreibtische (menschlich).',
				),
			)
		);
		$this->assertIsArray( $saved );
		$target = pll_get_post( $post, 'de' );
		$this->assertStringContainsString( 'Erster Absatz über Schreibtische (menschlich).', get_post( $target )->post_content );

		// Forced re-translation: human segment is not sent and not overwritten.
		$this->mock->requests = array();
		$forced               = Sync_Engine::sync( $post, 'de', array( 'force' => true ) );
		$this->assertSame( 3, $forced['sent'] );
		foreach ( $this->mock->items() as $item ) {
			$this->assertStringNotContainsString( 'First paragraph', $item['text'] );
		}
		$this->assertStringContainsString( 'Erster Absatz über Schreibtische (menschlich).', get_post( $target )->post_content );

		// A machine write never replaces a human entry.
		$this->assertFalse( Translation_Memory::save( 'First paragraph about desks.', 'x', 'en_US', 'de_DE', 'machine' ) );
	}

	public function test_review_rejects_broken_placeholders_and_approves() {
		$post   = $this->source_post( self::CONTENT );
		Sync_Engine::sync( $post, 'de' );
		$review = Review::get( $post, 'de' );
		$link   = null;
		foreach ( $review['segments'] as $segment ) {
			if ( false !== strpos( $segment['source'], '<1>' ) ) {
				$link = $segment;
			}
		}
		$bad = Review::save(
			$post,
			'de',
			array(
				array(
					'hash'        => $link['hash'],
					'translation' => 'Zweiter Absatz ohne Link.',
				),
			)
		);
		$this->assertWPError( $bad );
		$ok = Review::save(
			$post,
			'de',
			array(
				array(
					'hash'    => $link['hash'],
					'approve' => true,
				),
			)
		);
		$statuses = array_column( $ok['segments'], 'status', 'hash' );
		$this->assertSame( 'approved', $statuses[ $link['hash'] ] );
	}

	public function test_manual_edit_of_translation_is_protected() {
		$post   = $this->source_post( self::CONTENT );
		$first  = Sync_Engine::sync( $post, 'de' );
		wp_update_post(
			array(
				'ID'           => $first['target_id'],
				'post_content' => 'Hand-written German.',
			)
		);
		$again = Sync_Engine::sync( $post, 'de' );
		$this->assertSame( 'skipped', $again['status'] );
		$this->assertSame( 'Hand-written German.', get_post( $first['target_id'] )->post_content );
		$forced = Sync_Engine::sync( $post, 'de', array( 'overwrite_manual' => true ) );
		$this->assertSame( 'done', $forced['status'] );
	}

	public function test_terms_seo_meta_and_custom_fields() {
		\Dev3\AiTranslatorLocalizer\Core3\Core\Settings_Store::update( array( 'custom_fields' => array( 'subtitle' ) ) );
		$cat  = self::factory()->category->create( array( 'name' => 'Office furniture' ) );
		pll_set_term_language( $cat, 'en' );
		$post = $this->source_post(
			self::CONTENT,
			array(
				'post_type'     => 'post',
				'post_category' => array( $cat ),
			)
		);
		update_post_meta( $post, '_yoast_wpseo_metadesc', 'Find the best standing desk for your office.' );
		update_post_meta( $post, '_yoast_wpseo_title', '%%title%% %%sep%% %%sitename%%' );
		update_post_meta( $post, 'subtitle', 'Work better' );
		update_post_meta( $post, 'internal_code', 'ABC-1' );
		$stats  = Sync_Engine::sync( $post, 'de' );
		$target = $stats['target_id'];
		$this->assertSame( '[DE] Find the best standing desk for your office.', get_post_meta( $target, '_yoast_wpseo_metadesc', true ) );
		$this->assertSame( '%%title%% %%sep%% %%sitename%%', get_post_meta( $target, '_yoast_wpseo_title', true ) ); // Variables only → not sent.
		$this->assertSame( '[DE] Work better', get_post_meta( $target, 'subtitle', true ) );
		$this->assertSame( 'ABC-1', get_post_meta( $target, 'internal_code', true ) ); // Copied, not translated.
		$de_cat = pll_get_term( $cat, 'de' );
		$this->assertNotEmpty( $de_cat );
		$this->assertSame( '[DE] Office furniture', get_term( $de_cat )->name );
		$this->assertSame( array( $de_cat ), wp_get_post_categories( $target ) );
		$this->assertSame( sanitize_title( '[DE] Standing desk guide' ), get_post( $target )->post_name );
	}
}
