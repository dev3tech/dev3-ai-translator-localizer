<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 *
 * @package Dev3\AiTranslatorLocalizer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3tl_settings = get_option( 'dev3tl_settings', array() );
if ( empty( $dev3tl_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'usage', 'tm', 'glossary', 'jobs', 'job_items', 'core_cache', 'core_jobs', 'core_embeddings' ) as $dev3tl_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dev3tl_{$dev3tl_table}" );
}
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_query_meta_key
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_dev3tl_sync','_dev3tl_source','_dev3tl_written','_dev3tl_lang')" );
foreach ( array( 'dev3tl_settings', 'dev3tl_license', 'dev3tl_db_version', 'dev3tl_locales' ) as $dev3tl_option ) {
	delete_option( $dev3tl_option );
}
delete_transient( 'dev3tl_models' );
delete_site_transient( 'dev3tl_update_info' );
