<?php
/**
 * Translation memory: {prefix}dev3tl_tm keyed by sha1(normalized source) + locale pair.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Data;

use Dev3\AiTranslatorLocalizer\Content\Document;

defined( 'ABSPATH' ) || exit;

/**
 * Statuses: machine (AI), review (AI output that failed a check), approved (a reviewer accepted it),
 * human (a reviewer edited it — never overwritten by the AI).
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
 */
class Translation_Memory {

	const STATUSES = array( 'machine', 'review', 'approved', 'human' );

	/**
	 * Looks up many hashes at once.
	 *
	 * @param string[] $hashes        Hashes.
	 * @param string   $source_locale Source locale.
	 * @param string   $target_locale Target locale.
	 * @return array hash => { id, translation, status, updated }
	 */
	public static function lookup( array $hashes, $source_locale, $target_locale ) {
		global $wpdb;
		$hashes = array_values( array_unique( array_filter( array_map( 'strval', $hashes ) ) ) );
		if ( ! $hashes ) {
			return array();
		}
		$table = Tables::name( 'tm' );
		$out   = array();
		foreach ( array_chunk( $hashes, 200 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, hash, translation, status, updated FROM {$table} WHERE source_locale = %s AND target_locale = %s AND hash IN ({$in})",
					array_merge( array( $source_locale, $target_locale ), $chunk )
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$out[ $row['hash'] ] = $row;
			}
		}
		return $out;
	}

	/**
	 * Stores a translation.
	 *
	 * Human-edited entries are protected: an AI write (machine/review) never replaces them.
	 *
	 * @param string $source        Masked source.
	 * @param string $translation   Masked translation.
	 * @param string $source_locale Source locale.
	 * @param string $target_locale Target locale.
	 * @param string $status        Status.
	 * @param string $context       Context hint.
	 * @return bool Written.
	 */
	public static function save( $source, $translation, $source_locale, $target_locale, $status = 'machine', $context = '' ) {
		global $wpdb;
		$status = in_array( $status, self::STATUSES, true ) ? $status : 'machine';
		$hash   = Document::hash( $source );
		$table  = Tables::name( 'tm' );
		$now    = current_time( 'mysql', true );
		$found  = self::lookup( array( $hash ), $source_locale, $target_locale );
		if ( isset( $found[ $hash ] ) ) {
			$current = $found[ $hash ];
			if ( 'human' === $current['status'] && in_array( $status, array( 'machine', 'review' ), true ) ) {
				return false;
			}
			return false !== $wpdb->update(
				$table,
				array(
					'translation' => (string) $translation,
					'status'      => $status,
					'updated'     => $now,
				),
				array( 'id' => (int) $current['id'] ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
		}
		return false !== $wpdb->insert(
			$table,
			array(
				'hash'          => $hash,
				'source_locale' => (string) $source_locale,
				'target_locale' => (string) $target_locale,
				'source'        => Document::normalize( $source ),
				'translation'   => (string) $translation,
				'status'        => $status,
				'context'       => substr( (string) $context, 0, 120 ),
				'uses'          => 0,
				'created_at'    => $now,
				'updated'       => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Changes the status of an entry.
	 *
	 * @param string $hash          Hash.
	 * @param string $source_locale Source locale.
	 * @param string $target_locale Target locale.
	 * @param string $status        Status.
	 * @return bool
	 */
	public static function set_status( $hash, $source_locale, $target_locale, $status ) {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		return (bool) $wpdb->update(
			Tables::name( 'tm' ),
			array(
				'status'  => $status,
				'updated' => current_time( 'mysql', true ),
			),
			array(
				'hash'          => $hash,
				'source_locale' => $source_locale,
				'target_locale' => $target_locale,
			)
		);
	}

	/**
	 * Increments the reuse counter.
	 *
	 * @param int[] $ids Entry ids.
	 */
	public static function touch( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return;
		}
		$table = Tables::name( 'tm' );
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET uses = uses + 1 WHERE id IN ({$in})", $ids ) );
	}

	/**
	 * Dashboard statistics.
	 *
	 * @return array { entries, by_status, by_pair, reused }
	 */
	public static function stats() {
		global $wpdb;
		$table     = Tables::name( 'tm' );
		$by_status = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		$by_pair   = $wpdb->get_results( "SELECT source_locale, target_locale, COUNT(*) AS n FROM {$table} GROUP BY source_locale, target_locale ORDER BY n DESC LIMIT 20", ARRAY_A );
		$status    = array_fill_keys( self::STATUSES, 0 );
		$total     = 0;
		foreach ( (array) $by_status as $row ) {
			$status[ $row['status'] ] = (int) $row['n'];
			$total                   += (int) $row['n'];
		}
		return array(
			'entries'   => $total,
			'by_status' => $status,
			'by_pair'   => array_map(
				static function ( $row ) {
					return array(
						'source' => $row['source_locale'],
						'target' => $row['target_locale'],
						'count'  => (int) $row['n'],
					);
				},
				(array) $by_pair
			),
			'reused'    => (int) $wpdb->get_var( "SELECT COALESCE(SUM(uses),0) FROM {$table}" ),
		);
	}

	/**
	 * Removes everything (tools).
	 *
	 * @param string $status Optional status filter.
	 * @return int Rows deleted.
	 */
	public static function clear( $status = '' ) {
		global $wpdb;
		$table = Tables::name( 'tm' );
		if ( '' !== $status ) {
			return (int) $wpdb->delete( $table, array( 'status' => $status ) );
		}
		return (int) $wpdb->query( "DELETE FROM {$table}" );
	}
}
