<?php

class Transient_Mod_Command extends Transient_Command {

	public function delete_all() {
		global $wpdb, $_wp_using_ext_object_cache;

		// Transients are still being saved in options table as well, so we get all the transient names from there
		$records = $wpdb->get_results(
			"Select option_name, option_id FROM $wpdb->options
			WHERE option_name LIKE '\_transient\_%'
			OR option_name LIKE '\_site\_transient\_%'"
		);
		$counter = 0;
		if ( count( $records ) > 0 ) {
			foreach( $records as $record ) {

				if ( strpos( $record->option_name, '_transient_timeout' ) !== false ) {
					continue;
				}

				$counter++;
				if ( strpos( $record->option_name, '_site_transient_' ) !== false ) {
					delete_transient( str_replace( '_site_transient_', '', $record->option_name ) );
				} else {
					delete_transient( str_replace( '_transient_', '', $record->option_name ) );
				}
			}
			WP_CLI::success( $counter . " transients deleted from the database." );
		} else {
			WP_CLI::success( "No transients found" );
		}
		if ( $_wp_using_ext_object_cache ) {
			WP_CLI::warning( 'Transients are stored in an external object cache, and this command only deletes those stored in the database. You must flush the cache to delete all transients.');
		}
	}
}

WP_CLI::add_command( 'transient', 'Transient_Mod_Command' );