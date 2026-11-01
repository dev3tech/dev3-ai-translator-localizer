<?php
/**
 * Bulk translation queue: jobs + items, processed by Action Scheduler (WP-Cron fallback).
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Queue;

use Dev3\AiTranslatorLocalizer\Core3\Core\Settings_Store;
use Dev3\AiTranslatorLocalizer\Data\Tables;
use Dev3\AiTranslatorLocalizer\Providers\Providers;
use Dev3\AiTranslatorLocalizer\Sync\Sync_Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Job statuses: queued, running, paused, cancelled, done. Item statuses: pending, running, done, partial,
 * failed, skipped, cancelled.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
 */
class Queue {

	const HOOK  = 'dev3tl_process_job';
	const GROUP = 'dev3tl';

	/**
	 * Selects source posts.
	 *
	 * @param string[] $post_types Post types.
	 * @param int      $limit      Max posts.
	 * @return int[]
	 */
	public static function source_posts( array $post_types, $limit = 5000 ) {
		$provider = Providers::active();
		if ( ! $provider || ! $post_types ) {
			return array();
		}
		$post_types = array_values( array_filter( $post_types, array( $provider, 'is_translatable_post_type' ) ) );
		if ( ! $post_types ) {
			return array();
		}
		$args = array(
			'post_type'        => $post_types,
			'post_status'      => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'fields'           => 'ids',
			'posts_per_page'   => (int) $limit,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => false,
		);
		if ( 'polylang' === $provider->id() ) {
			$args['lang'] = ''; // All languages, including posts without one yet; filtered below.
		}
		$ids     = get_posts( $args );
		$default = $provider->default_language();
		$out     = array();
		foreach ( $ids as $id ) {
			if ( get_post_meta( $id, '_dev3tl_source', true ) ) {
				continue; // Our own translations are never sources.
			}
			$lang = $provider->post_language( $id );
			if ( '' === $lang || $lang === $default ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * Creates a job.
	 *
	 * @param array $args { post_types, languages, only_missing, force, post_ids, type }.
	 * @return array|\WP_Error Job.
	 */
	public static function create( array $args ) {
		global $wpdb;
		$provider = Providers::active();
		if ( ! $provider ) {
			return new \WP_Error( 'dev3tl_no_provider', __( 'No supported multilingual plugin is active.', 'dev3-ai-translator-localizer' ), array( 'status' => 400 ) );
		}
		$valid     = wp_list_pluck( $provider->target_languages(), 'code' );
		$languages = array_values( array_intersect( array_map( 'strval', (array) ( isset( $args['languages'] ) ? $args['languages'] : array() ) ), $valid ) );
		if ( ! $languages ) {
			return new \WP_Error( 'dev3tl_no_languages', __( 'Choose at least one target language.', 'dev3-ai-translator-localizer' ), array( 'status' => 400 ) );
		}
		$types    = array_map( 'sanitize_key', (array) ( isset( $args['post_types'] ) ? $args['post_types'] : array() ) );
		$post_ids = ! empty( $args['post_ids'] ) ? array_map( 'intval', (array) $args['post_ids'] ) : self::source_posts( $types );
		$only     = ! empty( $args['only_missing'] );
		$type     = isset( $args['type'] ) && in_array( $args['type'], array( 'bulk', 'sync', 'single' ), true ) ? $args['type'] : 'bulk';
		$items    = array();
		foreach ( $post_ids as $post_id ) {
			$stats = Sync_Engine::stats( $post_id );
			foreach ( $languages as $lang ) {
				if ( $only && ( ( 'posts' === $provider->mode() && $provider->get_translation( $post_id, $lang ) ) || ( 'strings' === $provider->mode() && isset( $stats[ $lang ] ) ) ) ) {
					continue;
				}
				$items[] = array( $post_id, $lang );
			}
		}
		if ( ! $items ) {
			return new \WP_Error( 'dev3tl_nothing', __( 'Nothing to translate: every selected item already has a translation.', 'dev3-ai-translator-localizer' ), array( 'status' => 400 ) );
		}
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			Tables::name( 'jobs' ),
			array(
				'type'       => $type,
				'status'     => 'queued',
				'post_types' => wp_json_encode( $types ),
				'languages'  => wp_json_encode( $languages ),
				'options'    => wp_json_encode(
					array(
						'force'        => ! empty( $args['force'] ),
						'only_missing' => $only,
					)
				),
				'total'      => count( $items ),
				'created_by' => get_current_user_id(),
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$job_id = (int) $wpdb->insert_id;
		$table  = Tables::name( 'job_items' );
		foreach ( array_chunk( $items, 200 ) as $chunk ) {
			$rows   = array();
			$values = array();
			foreach ( $chunk as $item ) {
				$rows[] = '(%d, %d, %s, %s, %s, %s)';
				array_push( $values, $job_id, $item[0], $item[1], 'pending', '', $now );
			}
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (job_id, post_id, lang, status, message, updated_at) VALUES " . implode( ',', $rows ), $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $rows holds only placeholders.
		}
		self::schedule( $job_id );
		return self::get( $job_id );
	}

	/**
	 * Job row.
	 *
	 * @param int $job_id Job.
	 * @return array|null
	 */
	public static function get( $job_id ) {
		global $wpdb;
		$table = Tables::name( 'jobs' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $job_id ), ARRAY_A );
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * Recent jobs.
	 *
	 * @param int $limit Limit.
	 * @return array[]
	 */
	public static function recent( $limit = 20 ) {
		global $wpdb;
		$table = Tables::name( 'jobs' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", (int) $limit ), ARRAY_A );
		return array_map( array( __CLASS__, 'shape' ), (array) $rows );
	}

	/**
	 * Items of a job (paged).
	 *
	 * @param int $job_id Job.
	 * @param int $page   Page.
	 * @param int $per    Per page.
	 * @return array { items, total }
	 */
	public static function items( $job_id, $page = 1, $per = 50 ) {
		global $wpdb;
		$table = Tables::name( 'job_items' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %d ORDER BY id ASC LIMIT %d OFFSET %d", (int) $job_id, (int) $per, max( 0, ( (int) $page - 1 ) * (int) $per ) ), ARRAY_A );
		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'id'        => (int) $row['id'],
				'post_id'   => (int) $row['post_id'],
				'title'     => html_entity_decode( get_the_title( (int) $row['post_id'] ), ENT_QUOTES, 'UTF-8' ),
				'post_type' => get_post_type( (int) $row['post_id'] ),
				'lang'      => $row['lang'],
				'status'    => $row['status'],
				'segments'  => (int) $row['segments'],
				'reused'    => (int) $row['reused'],
				'sent'      => (int) $row['sent'],
				'tokens'    => (int) $row['tokens'],
				'target_id' => (int) $row['target_id'],
				'message'   => $row['message'],
				'updated'   => $row['updated_at'],
			);
		}
		return array(
			'items' => $items,
			'total' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE job_id = %d", (int) $job_id ) ),
		);
	}

	/**
	 * Normalises a job row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function shape( array $row ) {
		foreach ( array( 'id', 'total', 'done', 'failed', 'skipped', 'segments', 'reused', 'sent', 'tokens', 'created_by' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		foreach ( array( 'post_types', 'languages', 'options' ) as $json ) {
			$decoded      = json_decode( (string) $row[ $json ], true );
			$row[ $json ] = is_array( $decoded ) ? $decoded : array();
		}
		$finished        = $row['done'] + $row['failed'] + $row['skipped'];
		$row['progress'] = $row['total'] ? (int) floor( 100 * $finished / $row['total'] ) : 100;
		return $row;
	}

	/**
	 * Changes the status (pause / resume / cancel).
	 *
	 * @param int    $job_id Job.
	 * @param string $action pause|resume|cancel.
	 * @return array|\WP_Error Job.
	 */
	public static function control( $job_id, $action ) {
		global $wpdb;
		$job = self::get( $job_id );
		if ( ! $job ) {
			return new \WP_Error( 'dev3tl_no_job', __( 'Job not found.', 'dev3-ai-translator-localizer' ), array( 'status' => 404 ) );
		}
		$table = Tables::name( 'jobs' );
		$now   = current_time( 'mysql', true );
		if ( 'pause' === $action && in_array( $job['status'], array( 'queued', 'running' ), true ) ) {
			$wpdb->update(
				$table,
				array(
					'status'     => 'paused',
					'updated_at' => $now,
				),
				array( 'id' => (int) $job_id )
			);
		} elseif ( 'resume' === $action && 'paused' === $job['status'] ) {
			$wpdb->update(
				$table,
				array(
					'status'     => 'queued',
					'updated_at' => $now,
				),
				array( 'id' => (int) $job_id )
			);
			self::schedule( $job_id );
		} elseif ( 'cancel' === $action && ! in_array( $job['status'], array( 'done', 'cancelled' ), true ) ) {
			$wpdb->update(
				$table,
				array(
					'status'     => 'cancelled',
					'updated_at' => $now,
				),
				array( 'id' => (int) $job_id )
			);
			$wpdb->update(
				Tables::name( 'job_items' ),
				array(
					'status'     => 'cancelled',
					'updated_at' => $now,
				),
				array(
					'job_id' => (int) $job_id,
					'status' => 'pending',
				)
			);
			self::unschedule( $job_id );
		}
		return self::get( $job_id );
	}

	/**
	 * Deletes a finished job.
	 *
	 * @param int $job_id Job.
	 * @return bool
	 */
	public static function delete( $job_id ) {
		global $wpdb;
		$job = self::get( $job_id );
		if ( ! $job || in_array( $job['status'], array( 'queued', 'running' ), true ) ) {
			return false;
		}
		$wpdb->delete( Tables::name( 'job_items' ), array( 'job_id' => (int) $job_id ) );
		return (bool) $wpdb->delete( Tables::name( 'jobs' ), array( 'id' => (int) $job_id ) );
	}

	/**
	 * Schedules background processing.
	 *
	 * @param int $job_id Job.
	 * @param int $delay  Seconds.
	 */
	public static function schedule( $job_id, $delay = 0 ) {
		$args = array( (int) $job_id );
		if ( self::uses_action_scheduler() ) {
			if ( ! as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
				if ( $delay > 0 ) {
					as_schedule_single_action( time() + $delay, self::HOOK, $args, self::GROUP );
				} else {
					as_enqueue_async_action( self::HOOK, $args, self::GROUP );
				}
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK, $args ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK, $args );
		}
	}

	/**
	 * Removes scheduled processing.
	 *
	 * @param int $job_id Job.
	 */
	private static function unschedule( $job_id ) {
		$args = array( (int) $job_id );
		if ( self::uses_action_scheduler() ) {
			as_unschedule_all_actions( self::HOOK, $args, self::GROUP );
		}
		wp_clear_scheduled_hook( self::HOOK, $args );
	}

	/**
	 * Action Scheduler available and enabled.
	 *
	 * @return bool
	 */
	public static function uses_action_scheduler() {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' ) && Settings_Store::get( 'use_action_scheduler', true );
	}

	/**
	 * Processes a job for up to $budget seconds (cron/Action Scheduler handler and admin "tick").
	 *
	 * @param int $job_id    Job.
	 * @param int $budget    Seconds.
	 * @param int $max_items Stop after this many items (0 = no limit).
	 * @return array|null Job after processing.
	 */
	public static function process( $job_id, $budget = 0, $max_items = 0 ) {
		global $wpdb;
		$job_id = (int) $job_id;
		$job    = self::get( $job_id );
		if ( ! $job || ! in_array( $job['status'], array( 'queued', 'running' ), true ) ) {
			return $job;
		}
		if ( ! self::lock( $job_id ) ) {
			return $job;
		}
		$budget  = $budget > 0 ? $budget : (int) Settings_Store::get( 'tick_seconds', 20 );
		$started = microtime( true );
		$jobs    = Tables::name( 'jobs' );
		$items   = Tables::name( 'job_items' );
		$user    = get_current_user_id();
		if ( ! $user && $job['created_by'] ) {
			wp_set_current_user( $job['created_by'] ); // Cron: act as the user who queued the job.
		}
		$wpdb->update(
			$jobs,
			array(
				'status'     => 'running',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $job_id )
		);
		$processed = 0;
		try {
			do {
				$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$items} WHERE job_id = %d AND status = 'pending' ORDER BY id ASC LIMIT 1", $job_id ), ARRAY_A );
				if ( ! $item ) {
					break;
				}
				$wpdb->update( $items, array( 'status' => 'running' ), array( 'id' => (int) $item['id'] ) );
				$result = Sync_Engine::sync(
					(int) $item['post_id'],
					$item['lang'],
					array(
						'force'   => ! empty( $job['options']['force'] ),
						'context' => wp_doing_cron() ? 'cron' : 'bulk',
					)
				);
				self::finish_item( $job_id, $item, $result );
				++$processed;
				$current = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$jobs} WHERE id = %d", $job_id ) );
				if ( 'running' !== $current || ( $max_items && $processed >= $max_items ) ) {
					break;
				}
			} while ( microtime( true ) - $started < $budget );

			$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$items} WHERE job_id = %d AND status = 'pending'", $job_id ) );
			$current = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$jobs} WHERE id = %d", $job_id ) );
			if ( 'running' === $current ) {
				if ( $pending ) {
					self::schedule( $job_id, 1 );
				} else {
					$wpdb->update(
						$jobs,
						array(
							'status'     => 'done',
							'updated_at' => current_time( 'mysql', true ),
						),
						array( 'id' => $job_id )
					);
				}
			}
		} finally {
			self::unlock( $job_id );
			if ( ! $user ) {
				wp_set_current_user( 0 );
			}
		}
		return self::get( $job_id );
	}

	/**
	 * Records an item result and updates job counters.
	 *
	 * @param int             $job_id Job.
	 * @param array           $item   Item row.
	 * @param array|\WP_Error $result Result.
	 */
	private static function finish_item( $job_id, array $item, $result ) {
		global $wpdb;
		$jobs  = Tables::name( 'jobs' );
		$items = Tables::name( 'job_items' );
		$now   = current_time( 'mysql', true );
		if ( is_wp_error( $result ) ) {
			$wpdb->update(
				$items,
				array(
					'status'     => 'failed',
					'message'    => $result->get_error_message(),
					'updated_at' => $now,
				),
				array( 'id' => (int) $item['id'] )
			);
			$wpdb->query( $wpdb->prepare( "UPDATE {$jobs} SET failed = failed + 1, updated_at = %s WHERE id = %d", $now, $job_id ) );
			return;
		}
		$status = in_array( $result['status'], array( 'done', 'partial', 'skipped' ), true ) ? $result['status'] : 'done';
		$wpdb->update(
			$items,
			array(
				'status'     => $status,
				'segments'   => (int) $result['total'],
				'reused'     => (int) $result['reused'],
				'sent'       => (int) $result['sent'],
				'tokens'     => (int) $result['tokens'],
				'target_id'  => (int) $result['target_id'],
				'message'    => (string) $result['message'] . ( $result['review'] ? ' ' . sprintf( /* translators: %d: segments */ _n( '%d segment flagged for review.', '%d segments flagged for review.', (int) $result['review'], 'dev3-ai-translator-localizer' ), (int) $result['review'] ) : '' ),
				'updated_at' => $now,
			),
			array( 'id' => (int) $item['id'] )
		);
		$column = 'skipped' === $status ? 'skipped' : 'done';
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$jobs} SET {$column} = {$column} + 1, segments = segments + %d, reused = reused + %d, sent = sent + %d, tokens = tokens + %d, updated_at = %s WHERE id = %d",
				(int) $result['total'],
				(int) $result['reused'],
				(int) $result['sent'],
				(int) $result['tokens'],
				$now,
				$job_id
			)
		);
	}

	/**
	 * Processes whichever job is active (admin pages call this while they are open, which also covers sites
	 * where WP-Cron loopback requests are blocked).
	 *
	 * @param int $budget Seconds.
	 * @return array|null Processed job.
	 */
	public static function tick( $budget = 15 ) {
		global $wpdb;
		$jobs   = Tables::name( 'jobs' );
		$job_id = (int) $wpdb->get_var( "SELECT id FROM {$jobs} WHERE status IN ('running','queued') ORDER BY id ASC LIMIT 1" );
		return $job_id ? self::process( $job_id, $budget ) : null;
	}

	/**
	 * Option-based mutex (atomic add_option).
	 *
	 * @param int $job_id Job.
	 * @return bool
	 */
	private static function lock( $job_id ) {
		$key = 'dev3tl_job_lock_' . $job_id;
		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}
		$since = (int) get_option( $key );
		if ( $since && time() - $since > 300 ) {
			update_option( $key, time(), false );
			return true;
		}
		return false;
	}

	/**
	 * Releases the mutex.
	 *
	 * @param int $job_id Job.
	 */
	private static function unlock( $job_id ) {
		delete_option( 'dev3tl_job_lock_' . $job_id );
	}

	/**
	 * Whether a post already has a pending sync item.
	 *
	 * @param int $post_id Post.
	 * @return bool
	 */
	public static function has_pending( $post_id ) {
		global $wpdb;
		$items = Tables::name( 'job_items' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$items} WHERE post_id = %d AND status = 'pending' LIMIT 1", (int) $post_id ) );
	}
}
