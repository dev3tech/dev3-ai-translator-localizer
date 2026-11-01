<?php
/**
 * REST routes for the admin app.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Rest;

use Dev3\AiTranslatorLocalizer\Content\Document;
use Dev3\AiTranslatorLocalizer\Content\Segmenter;
use Dev3\AiTranslatorLocalizer\Core3\Rest\Base_Controller;
use Dev3\AiTranslatorLocalizer\Data\Glossary;
use Dev3\AiTranslatorLocalizer\Data\Translation_Memory;
use Dev3\AiTranslatorLocalizer\Localization\Locale_Profiles;
use Dev3\AiTranslatorLocalizer\Providers\Providers;
use Dev3\AiTranslatorLocalizer\Queue\Estimator;
use Dev3\AiTranslatorLocalizer\Queue\Queue;
use Dev3\AiTranslatorLocalizer\Sync\Review;
use Dev3\AiTranslatorLocalizer\Sync\Sync_Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard, bulk jobs, glossary, locales, review, translate.
 */
class Translator_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$manage = array( $this, 'can_manage' );
		$routes = array(
			'/dashboard'                          => array( 'GET', 'dashboard', $manage ),
			'/post-types'                         => array( 'GET', 'post_types', $manage ),
			'/estimate'                           => array( 'POST', 'estimate', $manage ),
			'/jobs'                               => array( 'GET', 'jobs', $manage ),
			'/jobs/create'                        => array( 'POST', 'create_job', $manage ),
			'/jobs/tick'                          => array( 'POST', 'tick', $manage ),
			'/jobs/(?P<id>\d+)'                   => array( 'GET', 'job', $manage ),
			'/jobs/(?P<id>\d+)/(?P<action>pause|resume|cancel|delete)' => array( 'POST', 'control_job', $manage ),
			'/glossary'                           => array( 'GET', 'glossary', $manage ),
			'/glossary/save'                      => array( 'POST', 'save_term', $manage ),
			'/glossary/import'                    => array( 'POST', 'import_terms', $manage ),
			'/glossary/(?P<id>\d+)/delete'        => array( 'POST', 'delete_term', $manage ),
			'/locales'                            => array( 'GET', 'locales', $manage ),
			'/locales/(?P<locale>[A-Za-z0-9_-]+)' => array( 'POST', 'save_locale', $manage ),
			'/tm/clear'                           => array( 'POST', 'clear_tm', $manage ),
			'/strings/site'                       => array( 'POST', 'site_strings', $manage ),
			'/review'                             => array( 'GET', 'review_list', array( $this, 'can_generate' ) ),
			'/review/(?P<post>\d+)/(?P<lang>[A-Za-z0-9_-]+)' => array( 'GET,POST', 'review', array( $this, 'can_edit_post' ) ),
			'/translate'                          => array( 'POST', 'translate', array( $this, 'can_edit_post' ) ),
		);
		foreach ( $routes as $route => $spec ) {
			register_rest_route(
				self::NS,
				$route,
				array(
					'methods'             => $spec[0],
					'callback'            => array( $this, $spec[1] ),
					'permission_callback' => $spec[2],
				)
			);
		}
	}

	/**
	 * Permission: may translate this post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_edit_post( \WP_REST_Request $request ) {
		$base = $this->can_generate( $request );
		if ( true !== $base ) {
			return $base;
		}
		$post_id = (int) ( $request['post'] ? $request['post'] : $request->get_param( 'post_id' ) );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'dev3tl_forbidden', __( 'You do not have permission to translate this item.', 'dev3-ai-translator-localizer' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * Provider summary.
	 *
	 * @return array
	 */
	private function provider_info() {
		$provider = Providers::active();
		return array(
			'active'    => $provider ? array(
				'id'    => $provider->id(),
				'label' => $provider->label(),
				'mode'  => $provider->mode(),
			) : null,
			'detected'  => Providers::detected(),
			'languages' => $provider ? array_map(
				static function ( $language ) {
					$language['profile'] = Locale_Profiles::get( $language['locale'] );
					return $language;
				},
				$provider->languages()
			) : array(),
			'default'   => $provider ? $provider->default_language() : '',
		);
	}

	/**
	 * GET /dashboard.
	 *
	 * @return \WP_REST_Response
	 */
	public function dashboard() {
		global $wpdb;
		$totals = array(
			'items'    => 0,
			'segments' => 0,
			'reused'   => 0,
			'sent'     => 0,
			'tokens'   => 0,
		);
		// Last-sync stats of every translated item/language.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 5000", Sync_Engine::META ) ) as $raw ) {
			foreach ( (array) maybe_unserialize( $raw ) as $stats ) {
				if ( is_array( $stats ) ) {
					++$totals['items'];
					$totals['segments'] += isset( $stats['total'] ) ? (int) $stats['total'] : 0;
					$totals['reused']   += isset( $stats['reused'] ) ? (int) $stats['reused'] : 0;
					$totals['sent']     += isset( $stats['sent'] ) ? (int) $stats['sent'] : 0;
				}
			}
		}
		$usage = \Dev3\AiTranslatorLocalizer\Core3\Core\Schema::usage_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$totals['tokens'] = (int) $wpdb->get_var( "SELECT COALESCE(SUM(total_tokens),0) FROM {$usage} WHERE task = 'translate'" );
		return $this->respond(
			$this->provider_info() + array(
				'tm'       => Translation_Memory::stats(),
				'glossary' => count( Glossary::all() ),
				'totals'   => $totals,
				'jobs'     => Queue::recent( 5 ),
				'recent'   => Review::list_items( array( 'per_page' => 6 ) )['items'],
				'queue'    => Queue::uses_action_scheduler() ? 'action-scheduler' : 'wp-cron',
			)
		);
	}

	/**
	 * GET /post-types.
	 *
	 * @return \WP_REST_Response
	 */
	public function post_types() {
		$provider = Providers::active();
		$out      = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, array( 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'product_variation', 'shop_order', 'shop_coupon' ), true ) ) {
				continue;
			}
			$translatable = $provider ? $provider->is_translatable_post_type( $type->name ) : false;
			$counts       = (array) wp_count_posts( $type->name );
			unset( $counts['auto-draft'], $counts['trash'], $counts['inherit'] );
			$out[] = array(
				'name'         => $type->name,
				'label'        => $type->labels->name,
				'translatable' => $translatable,
				// Source-language items only (translations are not sources).
				'count'        => $translatable ? count( Queue::source_posts( array( $type->name ) ) ) : (int) array_sum( array_map( 'intval', $counts ) ),
			);
		}
		return $this->respond( $out );
	}

	/**
	 * POST /estimate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function estimate( \WP_REST_Request $request ) {
		return $this->respond(
			Estimator::estimate(
				array(
					'post_types'   => (array) $request->get_param( 'post_types' ),
					'languages'    => (array) $request->get_param( 'languages' ),
					'only_missing' => (bool) $request->get_param( 'only_missing' ),
				)
			)
		);
	}

	/**
	 * GET /jobs.
	 *
	 * @return \WP_REST_Response
	 */
	public function jobs() {
		return $this->respond( Queue::recent( 30 ) );
	}

	/**
	 * POST /jobs/create.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_job( \WP_REST_Request $request ) {
		$job = Queue::create(
			array(
				'post_types'   => (array) $request->get_param( 'post_types' ),
				'languages'    => (array) $request->get_param( 'languages' ),
				'only_missing' => (bool) $request->get_param( 'only_missing' ),
				'force'        => (bool) $request->get_param( 'force' ),
			)
		);
		return $this->respond( $job, 201 );
	}

	/**
	 * POST /jobs/tick — processes a slice of the active job.
	 *
	 * @return \WP_REST_Response
	 */
	public function tick() {
		$job = Queue::tick( 12 );
		return $this->respond( array( 'job' => $job ) );
	}

	/**
	 * GET /jobs/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function job( \WP_REST_Request $request ) {
		$job = Queue::get( (int) $request['id'] );
		if ( ! $job ) {
			return $this->respond( new \WP_Error( 'dev3tl_no_job', __( 'Job not found.', 'dev3-ai-translator-localizer' ), array( 'status' => 404 ) ) );
		}
		$job['items'] = Queue::items( $job['id'], max( 1, (int) $request->get_param( 'page' ) ), 50 );
		return $this->respond( $job );
	}

	/**
	 * POST /jobs/{id}/{action}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function control_job( \WP_REST_Request $request ) {
		if ( 'delete' === $request['action'] ) {
			return $this->respond( array( 'deleted' => Queue::delete( (int) $request['id'] ) ) );
		}
		return $this->respond( Queue::control( (int) $request['id'], (string) $request['action'] ) );
	}

	/**
	 * GET /glossary.
	 *
	 * @return \WP_REST_Response
	 */
	public function glossary() {
		Glossary::flush();
		return $this->respond( Glossary::all() );
	}

	/**
	 * POST /glossary/save.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_term( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();
		return $this->respond( Glossary::save( $params, isset( $params['id'] ) ? (int) $params['id'] : 0 ) );
	}

	/**
	 * POST /glossary/import — CSV: term, dnt, case_sensitive, notes, then locale columns.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function import_terms( \WP_REST_Request $request ) {
		$csv   = (string) $request->get_param( 'csv' );
		$lines = array_values( array_filter( preg_split( '/\r\n|\r|\n/', $csv ), 'strlen' ) );
		if ( count( $lines ) < 2 ) {
			return $this->respond( new \WP_Error( 'dev3tl_csv', __( 'Paste a header row and at least one term.', 'dev3-ai-translator-localizer' ), array( 'status' => 400 ) ) );
		}
		$header = array_map( 'trim', str_getcsv( array_shift( $lines ) ) );
		$count  = 0;
		foreach ( array_slice( $lines, 0, 2000 ) as $line ) {
			$cells = str_getcsv( $line );
			$row   = array( 'translations' => array() );
			foreach ( $header as $i => $col ) {
				$value = isset( $cells[ $i ] ) ? trim( $cells[ $i ] ) : '';
				if ( in_array( $col, array( 'term', 'notes' ), true ) ) {
					$row[ $col ] = $value;
				} elseif ( in_array( $col, array( 'dnt', 'case_sensitive' ), true ) ) {
					$row[ $col ] = in_array( strtolower( $value ), array( '1', 'yes', 'true', 'y' ), true );
				} elseif ( '' !== $col ) {
					$row['translations'][ $col ] = $value;
				}
			}
			if ( ! is_wp_error( Glossary::save( $row ) ) ) {
				++$count;
			}
		}
		return $this->respond( array( 'imported' => $count ) );
	}

	/**
	 * POST /glossary/{id}/delete.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_term( \WP_REST_Request $request ) {
		return $this->respond( array( 'deleted' => Glossary::delete( (int) $request['id'] ) ) );
	}

	/**
	 * GET /locales.
	 *
	 * @return \WP_REST_Response
	 */
	public function locales() {
		$info = $this->provider_info();
		return $this->respond(
			array(
				'languages' => $info['languages'],
				'default'   => $info['default'],
				'formality' => Locale_Profiles::FORMALITY,
			)
		);
	}

	/**
	 * POST /locales/{locale}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_locale( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		return $this->respond( Locale_Profiles::save( (string) $request['locale'], is_array( $params ) ? $params : $request->get_params() ) );
	}

	/**
	 * POST /tm/clear.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function clear_tm( \WP_REST_Request $request ) {
		$status = (string) $request->get_param( 'status' );
		$status = in_array( $status, array( 'machine', 'review' ), true ) ? $status : 'machine';
		return $this->respond( array( 'deleted' => Translation_Memory::clear( $status ) ) );
	}

	/**
	 * POST /strings/site — translates the site title and tagline through the provider's string translation.
	 *
	 * @return \WP_REST_Response
	 */
	public function site_strings() {
		$provider = Providers::active();
		if ( ! $provider ) {
			return $this->respond( new \WP_Error( 'dev3tl_no_provider', __( 'No supported multilingual plugin is active.', 'dev3-ai-translator-localizer' ), array( 'status' => 400 ) ) );
		}
		$strings = array(
			'blogname'        => (string) get_option( 'blogname' ),
			'blogdescription' => (string) get_option( 'blogdescription' ),
		);
		$done    = array();
		foreach ( $provider->target_languages() as $language ) {
			$doc = new Document();
			foreach ( $strings as $name => $value ) {
				Segmenter::add_text( $doc, $name, $value, 'site ' . $name );
			}
			$resolved = Sync_Engine::resolve( $doc, $provider->locale( $provider->default_language() ), $language['locale'], array() );
			if ( is_wp_error( $resolved ) ) {
				return $this->respond( $resolved );
			}
			foreach ( $doc->render( $resolved['translations'] ) as $name => $value ) {
				if ( '' !== $strings[ $name ] ) {
					$provider->translate_string(
						$strings[ $name ],
						$value,
						$language['code'],
						array(
							'name'  => $name,
							'group' => 'WordPress',
						)
					);
					$done[ $language['code'] ][ $name ] = $value;
				}
			}
		}
		return $this->respond( array( 'translated' => $done ) );
	}

	/**
	 * GET /review.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function review_list( \WP_REST_Request $request ) {
		return $this->respond(
			Review::list_items(
				array(
					'search'   => (string) $request->get_param( 'search' ),
					'page'     => (int) $request->get_param( 'page' ),
					'per_page' => 20,
					'lang'     => (string) $request->get_param( 'lang' ),
				)
			)
		);
	}

	/**
	 * GET|POST /review/{post}/{lang}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function review( \WP_REST_Request $request ) {
		$post = (int) $request['post'];
		$lang = (string) $request['lang'];
		if ( 'POST' === $request->get_method() ) {
			$changes = $request->get_param( 'segments' );
			return $this->respond( Review::save( $post, $lang, is_array( $changes ) ? $changes : array() ) );
		}
		return $this->respond( Review::get( $post, $lang ) );
	}

	/**
	 * POST /translate { post_id, languages[], force }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function translate( \WP_REST_Request $request ) {
		$post_id   = (int) $request->get_param( 'post_id' );
		$languages = array_map( 'strval', (array) $request->get_param( 'languages' ) );
		$out       = array();
		foreach ( $languages as $lang ) {
			$result = Sync_Engine::sync(
				$post_id,
				$lang,
				array(
					'force'            => (bool) $request->get_param( 'force' ),
					'overwrite_manual' => (bool) $request->get_param( 'overwrite_manual' ),
				)
			);
			if ( is_wp_error( $result ) && 1 === count( $languages ) ) {
				return $this->respond( $result );
			}
			$out[ $lang ] = is_wp_error( $result ) ? array(
				'status'  => 'failed',
				'message' => $result->get_error_message(),
			) : $result;
		}
		return $this->respond( $out );
	}
}
