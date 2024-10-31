=== Plugin Name ===
Contributors: Process Street
Tags: duplicates, find duplicates, fix duplicates, duplicate posts, user submitted posts, classifieds
Stable tag: 1.0.0
Description: This plugin is based on fix-duplicates plugin. Find and tag as noindex duplicate posts, the oldest one will be kept.

== Description ==
This plugin is based on Fix-Duplicates plugin.

It's very useful mainly when you have users content included into your blog/site.

This plugin fixes duplicate content by adding a custom field that marks that post as 'noindex'. The oldest one of that duplicate group will not be marked so the search engines will be able to index it.
The plugin shows all the duplicates even if only two of them are 'noindexed'. When there is only one not indexed, the plugin doesn't show as duplicates.

In order to make use of this functionality, the code below has to be added to the functions.php file:

	function apply_noindex_meta_tag(){

    	$noindex = get_post_meta( get_the_ID(), 'noindex', true );

    	if ( (int) $noindex === 1 ) {
        	echo '<meta name="robots" content="noindex">' . "\n";
    	}
	}
	add_action( 'wp_head', 'apply_noindex_meta_tag' );
	

= Compatibility =
* This plugin requires WordPress 2.8 or above.
* Not currently aware of any compatibility issues with any other WordPress plugins.

= Disclaimer =
This plugin is released under the [GPL licence](http://www.gnu.org/copyleft/gpl.html). I do not accept any responsibility for any damages or losses, direct or indirect, that may arise from using the plugin or these instructions. This software is provided as is, with absolutely no warranty. Please refer to the full version of the GPL license for more information.

== Installation ==
1. Download the plugin file and unzip it.
1. Upload the `noindex-duplicates` folder to the `wp-content/plugins/` folder.
1. Activate the Noindex Duplicates plugin within WordPress.


== Changelog ==

= 1.0.0 (March 2016) =
* Initial Release.
