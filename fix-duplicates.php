<?php
/*
Plugin Name: Noindex Duplicates
Plugin URI: https://github.com/process-street/noindex-duplicates
Description: This plugin is based on fix-duplicates plugin. Find and tag as noindex duplicate posts, the oldest one will be kept. Check our <a href="https://github.com/process-street/noindex-duplicates"> Github repository </a> for further details.
Version: 1.0.0
Date: March 2016
Author: Process Street
Author URI: http://www.process.st/

*/


// ***********************************************
function fix_duplicates_init() {

	// set the current plugin version
	$fix_duplicates_version = '1.0.0';

	// get the plugin options
	$fix_duplicates_options = get_option( 'fix_duplicates_options' );

	// if it's not the latest version, let's make sure that we have all the settings we need for this version (more to come)
	if ( version_compare( $fix_duplicates_version, $fix_duplicates_options[ 'version' ], '>' ) ) {
		$fix_duplicates_options[ 'version' ] = $fix_duplicates_version;
		update_option( 'fix_duplicates_options', $fix_duplicates_options );
	}

	// ****** Load admin scripts if it's a admin visit ******
	if ( is_admin() )
		require_once( 'fix-duplicates-admin.php' );
	// *********************************************

}
add_action( 'init', 'fix_duplicates_init', 0 );
// ***********************************************

?>