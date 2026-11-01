<?php
/**
 * Plugin tables (added to the core schema through dev3tl_schema_sql).
 *
 * @package Dev3\AiTranslatorLocalizer
 */

namespace Dev3\AiTranslatorLocalizer\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Translation memory, glossary and queue tables.
 */
class Tables {

	/**
	 * Table name.
	 *
	 * @param string $name tm|glossary|jobs|job_items.
	 * @return string
	 */
	public static function name( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'dev3tl_' . $name;
	}

	/**
	 * CREATE TABLE statements.
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset collate.
	 * @return array
	 */
	public static function sql( $sql, $charset ) {
		$tm       = self::name( 'tm' );
		$glossary = self::name( 'glossary' );
		$jobs     = self::name( 'jobs' );
		$items    = self::name( 'job_items' );
		$sql[]    = "CREATE TABLE {$tm} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			hash char(40) NOT NULL,
			source_locale varchar(20) NOT NULL,
			target_locale varchar(20) NOT NULL,
			source longtext NOT NULL,
			translation longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'machine',
			context varchar(120) NOT NULL DEFAULT '',
			uses int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY lookup (hash,source_locale,target_locale),
			KEY target_status (target_locale,status)
		) {$charset};";
		$sql[]    = "CREATE TABLE {$glossary} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			term varchar(191) NOT NULL,
			dnt tinyint(1) NOT NULL DEFAULT 0,
			case_sensitive tinyint(1) NOT NULL DEFAULT 0,
			translations longtext NOT NULL,
			notes text NOT NULL,
			updated datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY term (term)
		) {$charset};";
		$sql[]    = "CREATE TABLE {$jobs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL DEFAULT 'bulk',
			status varchar(20) NOT NULL DEFAULT 'queued',
			post_types text NOT NULL,
			languages text NOT NULL,
			options longtext NOT NULL,
			total int(10) unsigned NOT NULL DEFAULT 0,
			done int(10) unsigned NOT NULL DEFAULT 0,
			failed int(10) unsigned NOT NULL DEFAULT 0,
			skipped int(10) unsigned NOT NULL DEFAULT 0,
			segments int(10) unsigned NOT NULL DEFAULT 0,
			reused int(10) unsigned NOT NULL DEFAULT 0,
			sent int(10) unsigned NOT NULL DEFAULT 0,
			tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset};";
		$sql[]    = "CREATE TABLE {$items} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			lang varchar(20) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			segments int(10) unsigned NOT NULL DEFAULT 0,
			reused int(10) unsigned NOT NULL DEFAULT 0,
			sent int(10) unsigned NOT NULL DEFAULT 0,
			tokens int(10) unsigned NOT NULL DEFAULT 0,
			target_id bigint(20) unsigned NOT NULL DEFAULT 0,
			message text NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY job_status (job_id,status),
			KEY post_lang (post_id,lang)
		) {$charset};";
		return $sql;
	}

	/**
	 * Drops plugin tables (uninstall).
	 */
	public static function drop() {
		global $wpdb;
		foreach ( array( 'tm', 'glossary', 'jobs', 'job_items' ) as $name ) {
			$table = self::name( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}
}
