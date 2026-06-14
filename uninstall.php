<?php
/**
 * Amelia External Events uninstall cleanup.
 *
 * Removes the per-event URL options created by the plugin. The EXTERNAL tags
 * themselves belong to Amelia and are intentionally left untouched.
 *
 * @package LcAmeliaExternalEvents
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lcaee_event_%_url'" );

wp_cache_flush();
