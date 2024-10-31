<?php

/* Included on on Admin views only */


// ********** Add the menu entry **********
function fix_duplicates_menu() {
	add_menu_page( 'Noindex Duplicates', 'Noindex Duplicates', 'edit_pages', 'fix_duplicates', 'fix_duplicates_admin_main', plugins_url( '/images/fix-duplicates-icon-16.png', __FILE__ ) );
	add_submenu_page( 'fix_duplicates', 'Duplicate Entries', 'Duplicate Entries', 'edit_pages', 'fix_duplicates', 'fix_duplicates_admin_main' );
}
add_action( 'admin_menu', 'fix_duplicates_menu', 8 );	// priority 9 to fire before CPT added to menu
// **************************************


// ********** Add settings link to plugin page (2.8+) **********
function fix_duplicates_settings_link( $links, $file ) {
	// Static so we don't call plugin_basename on every plugin row. Thanks to Joost de Valk's WordPress SEO for this code.
	static $this_plugin;
	if( empty( $this_plugin ) ) 
		$this_plugin = dirname( plugin_basename( __FILE__ ) ) . '/fix-duplicates.php';
	if ( $file == $this_plugin ) {
		$settings_link = '<a href="' . admin_url( 'admin.php?page=fix_duplicates' ) . '">Settings</a>';
		array_unshift( $links, $settings_link );
	}
	return $links;
}
add_filter( 'plugin_action_links', 'fix_duplicates_settings_link', 10, 2 );
// *****************************************************


// ********** load the necessary scripts and stylesheets **********
function fix_duplicates_enqueue( $hook ) {
	// if it's not one of our pages, let's bail
	if ( ( ! isset( $_GET[ 'post_type' ] ) || ! $_GET[ 'post_type' ] == 'fix_dups_redirects' ) && ! stristr ( $hook, 'fix_duplicates' ) )
		return;

	// register and enqueue our stylesheets and scripts as necessary
	$fix_duplicates_options = get_option( 'fix_duplicates_options' );
	wp_register_style( 'fix_duplicates_admin_css', plugins_url( '/includes/fix-duplicates.css', __FILE__ ), false, $fix_duplicates_options[ 'version' ] );
	wp_enqueue_style( 'fix_duplicates_admin_css' );
	wp_enqueue_script( 'fix_duplicates_admin_js', plugins_url( '/includes/fix-duplicates.js', __FILE__ ), array( 'jquery' ), $fix_duplicates_options[ 'version' ] );

}
add_action( 'admin_enqueue_scripts', 'fix_duplicates_enqueue' );
// *********************************************************


// ********** Add styles / scripts to head element just for our settings pages **********
function fix_duplicates_admin_head() {
	// can't easily move to the external CSS file because of the PHP included to get the correct URL
	// if not one of our pages, don't include the scripts
	if ( ! isset( $_GET[ 'page' ] ) || ! stristr ( $_GET[ 'page' ] , 'fix_duplicates' ) )
		return;
?>
	<!-- Start Fix Duplicates plugin additions -->
	<style type="text/css" media>
		.row-actions {
			color:#444;
		}
	</style>
	<!-- End Fix Duplicates plugin additions -->
<?php
}
add_action( 'admin_head', 'fix_duplicates_admin_head' );
// ********** End styles / scripts to head element just for our settings pages **********


// ********** Start main function for the Admin area (fix_duplicates_admin_main) ***********
function fix_duplicates_admin_main() {

	// check the nonce if we've got any URL parameters set (apart from page which is always set)
	if ( count( $_GET ) > 1 && ! check_admin_referer( 'fix_duplicates_main_form_nonce' ) )
		wp_die( 'Sorry, you cannot access this page directly' );
	
	// if we have any POST items, call the functions to process it
	if ( count( $_POST ) && check_admin_referer( 'fix_duplicates_main_form_nonce' ) )
		fix_duplicates_process_post_actions();

	// get the options
	$fix_duplicates_options = get_option( 'fix_duplicates_options' );
	
	// get the list of custom post types, excluding revisions, attachments, nav_menu_item and our redirection CPT (we use this below)
	$fix_duplicates_post_types = get_post_types( array( 'show_ui' => true ), 'objects' );
	if ( isset( $fix_duplicates_post_types[ 'fix_dups_redirects' ] ) )
		unset( $fix_duplicates_post_types[ 'fix_dups_redirects' ] );
	
	// set up the database details
	global $wpdb;
	$tablename = $wpdb->prefix . 'posts';
	$meta_tbname = $wpdb->prefix . 'postmeta';

	// setup variable based on mode URL parameter
	if ( isset( $_GET[ 'mode' ] ) && esc_html( $_GET[ 'mode' ] ) ) {
		$current_mode = esc_html( $_GET[ 'mode' ] );
	}
	 else {
		$current_mode = 'list';
	 }
	 
	// setup variable and SQL string based on post type URL variable. 
	if ( isset( $_GET[ 'post_type' ] ) && esc_html( $_GET[ 'post_type' ] ) ) {
		$post_type = esc_html( $_GET[ 'post_type' ] );
		$post_type_string = $wpdb->prepare( "$tablename.post_type = %s", $post_type );
	}
	 else {
		$post_type = 0;
		$post_type_string_array = array();
		foreach ( $fix_duplicates_post_types as $key => $value ) {
			$post_type_string_array[] = $wpdb->prepare( "$tablename.post_type = '%s'", $key );
		}
		$post_type_string = '(' . implode( $post_type_string_array, ' OR ' ) . ')';
	}
	
	// setup variable and SQL string based on search term URL parameter (note %% required for LIKE when wpdb->prepare is used)
	if ( isset( $_GET[ 's' ] ) && esc_html( $_GET[ 's' ] ) ) {
		$fix_duplicates_search_term = esc_html( $_GET[ 's' ] );
		$fix_duplicates_search_string = $wpdb->prepare( " AND $tablename.post_title LIKE %s", '%' . like_escape( $fix_duplicates_search_term ) . '%' );
	}
	else {
		$fix_duplicates_search_term = '';
		$fix_duplicates_search_string = '';
	}

	// setup variable and SQL string based on show draft parameter
	if ( isset( $_GET[ 'show_drafts' ] ) && esc_html( $_GET[ 'show_drafts' ] ) == 1 ) {
		$fix_duplicates_show_drafts = 1;
		$fix_duplicates_show_drafts_string = "( $tablename.post_status ='publish' OR $tablename.post_status ='draft' )";
	}
	else {
		$fix_duplicates_show_drafts = 0;
		$fix_duplicates_show_drafts_string = "$tablename.post_status ='publish'";
	}

	$meta_tag_noindex_select = "SELECT post_id FROM $meta_tbname WHERE $meta_tbname.meta_key = 'noindex' and $meta_tbname.meta_value =1";
	$no_empty_posts_string = "$tablename.post_content != ' '";

	// Get the number of duplicate titles
	$fix_duplicates_count_query = 
		"SELECT COUNT(post_content) FROM (
			SELECT post_content FROM $tablename
			WHERE $post_type_string $fix_duplicates_search_string AND $fix_duplicates_show_drafts_string
			AND $no_empty_posts_string
			 	AND $tablename.id NOT IN ( $meta_tag_noindex_select )
			GROUP BY post_content HAVING COUNT(*)>1
		) AS t";
	
	$fix_duplicates_result_count = $wpdb->get_var( $fix_duplicates_count_query );

	// deal with pagination (needs to happen after we have total results, but before we query for individual entries)
	if ( isset( $_GET[ 'no' ] ) && absint( $_GET[ 'no' ] ) ) {
		$numtoshow = absint( $_GET[ 'no' ] );
	}
	else {
		$numtoshow = 20;
	}
	// using intval instead of absint as we don't want it to be absolute (we will make negative numbers page 1)
	if ( isset( $_GET[ 'pageno' ] ) && intval( $_GET[ 'pageno' ] ) ) {
		$page = intval( $_GET[ 'pageno' ] );
		// deal with page numbers below 1 (make them page 1)
		if ( $page < 1 ) {
			$page = 1;
		}
		// deal with page numbers over the maximum (make them the last page)
		elseif ( ( $page - 1 ) * $numtoshow >= $fix_duplicates_result_count ) {
			$page = ceil( $fix_duplicates_result_count / $numtoshow );
		}
		// calculate offset now that the page number has been normalised
		$offset = ( $page - 1 ) * $numtoshow;
	}
	else {
		$page = 1;
		$offset = 0; 
	}
	$total_pages = ceil( $fix_duplicates_result_count / $numtoshow );

	// get the duplicates from the DB
	$fix_duplicates_query = 
		"SELECT t1.* FROM $tablename AS t1 INNER JOIN ( 
			SELECT post_content FROM $tablename
			WHERE $post_type_string $fix_duplicates_search_string
				AND $fix_duplicates_show_drafts_string
				AND $tablename.id not in ( ". $meta_tag_noindex_select. "  )
			GROUP BY post_content HAVING COUNT(*)>1 LIMIT $offset, $numtoshow
		) AS t2 
		ON TRIM(t1.post_content) = TRIM(t2.post_content)
		WHERE " . str_replace( $tablename, 't1', $post_type_string ) . "
			AND " . str_replace( $tablename, 't1', $fix_duplicates_show_drafts_string ) . "
		ORDER BY t1.post_content, char_length(t1.post_content), t1. post_title, t1.post_date DESC;";
	$fix_duplicates_result = $wpdb->get_results( $fix_duplicates_query, ARRAY_A );

	// if there are no duplicates, tell them (but don't worry for search results as it will tell them there are 0 titles)
	if ( $fix_duplicates_result_count == 0 && empty( $fix_duplicates_search_term ) ) {
		echo '<div id="message" class="updated"><p><strong>GREAT NEWS: There are no duplicate posts!</strong></p></div>';
	}
	// should never occur
	else if ( count( $fix_duplicates_result ) == 0 && $fix_duplicates_result_count > 0 ) {
		echo '<div id="message" class="updated"><p><strong>ERROR: Page number out of range!</strong></p></div>';					
	}

	// Drop to HTML to create page. 
	/* 
	Rolling our own table that uses core styles so it fits with the rest of Admin. 
	Not using wp_list_table because Andrew Nacin says plugins shouldn't use it (http://wpengineer.com/2426/wp_list_table-a-step-by-step-guide/#comment-9617)
	In hindsight that was a mistake as this will probably face many of the same issues - if the Admin styles change I'll need to update this - but gets none of the advantages 
	*/
	?>
	
	<div class="wrap"> 
		<h2><img style="vertical-align:middle;margin-right:12px;" src="<?php echo plugins_url( '/images/fix-duplicates-icon-32.png', __FILE__ ); ?>" alt="Fix Duplicates icon" />Fix Duplicates - Duplicate Entries</h2>			
		<div id="poststuff">
			<div class="stuffbox">
				<div class="inside">
					
					<!-- Display information about search and or filter, with number of entries found -->
					<div class="fix-duplicates-results">
						<?php // If it's a search or page type filter (or both), tell them what the query was and give them the option to clear ?>
						<?php if( ! empty( $fix_duplicates_search_term ) || ! empty( $post_type ) ) : ?>
						<div class="fix-duplicates-results-search">
						<span>
							<?php if( ! empty( $fix_duplicates_search_term ) ) echo 'Search results for "' . $fix_duplicates_search_term . '"'; ?>
							<?php if( ! empty( $fix_duplicates_search_term ) && ! empty( $post_type ) ) echo ' and '; ?>
							<?php if( ! empty( $post_type ) ) echo 'Post type of ' . $post_type; ?>
						</span>
						<a class="fix-duplicates-results-clear" href="<?php echo esc_url( remove_query_arg( array( 's', 'post_type', 'pageno', 'no' ) ) ); ?>">Clear</a>
						</div>
						<?php endif; ?>
						
						<div class="fix-duplicates-results-count">
						<?php echo $fix_duplicates_result_count; ?> duplicate groups
						</div>
					</div>
					
					<!-- Top navigation area -->
					<div class="tablenav top">
					
						<!-- Form: Form to search and or filter the results -->
						<form id="fix-duplicates-main-form" action="" method="GET">

							<!-- Form: Hidden fields for the form -->	<?php // nonce code is before closing </form> ?>
							<input type="hidden" name="page" value="fix_duplicates" />
							<input type="hidden" id="mode" name="mode" value="<?php echo $current_mode; ?>" />
							<?php if ( $numtoshow ) : ?><input type="hidden" name="no" value="<?php echo $numtoshow; ?>" /><?php endif; ?>

							<!-- Form: Search box -->
							<p class="search-box">
								<label class="screen-reader-text" for="post-search-input">Search Duplicates:</label>
								<input type="search" id="post-search-input" name="s" value="<?php echo $fix_duplicates_search_term; ?>" />			
								<input type="submit" name="" id="search-submit" class="button" value="Search Duplicates"  />
							</p>

							<!-- Form: Post Type Filter-->	
							<div class="fix-duplicates-post-type-filter alignleft actions">
								<select name="post_type">
									<option <?php if ( $post_type === 0 ) echo 'selected="selected" '; ?>value="0">All post types</option>
									<?php
									// add custom post types to the options (using the array we got above)
									foreach ( $fix_duplicates_post_types as $key => $value ) {
										echo "\t\t\t\t" . '<option value="'. $key . '"';
										if ( $post_type === $key ) echo ' selected="selected"';
										echo '>'. $value->label . '</option>' . "\n";
									}
									?>
								</select>
								<label>&nbsp;<input type="checkbox" id="show_drafts" name="show_drafts" value="1" <?php if ( $fix_duplicates_show_drafts ) echo 'checked="checked" class="drafts_showing" '; ?>/> Show drafts</label>&nbsp;
								<input type="submit" name="" id="post-type-submit" class="button-secondary" value="Filter Posts"  />
							</div>

							<!-- Form: Paging controls -->
							<?php // mostly links except 'jump to page'; using WordPress functions to build the links ?>
							<div class='tablenav-pages<?php if ( $total_pages <= 1 ) echo ' one-page'; ?>'>
								<span class="displaying-num"><?php echo $fix_duplicates_result_count; ?></span>
								<span class="pagination-links">
									<a class="first-page<?php if ( $total_pages > 1 &&  $page == 1 ) echo ' disabled'; ?>" title="Go to the first page" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'mode' => $current_mode, 'pageno' => '1', '_wpnonce' => false ) ), 'fix_duplicates_main_form_nonce' ) ); ?>">&laquo;</a>
									<a class="prev-page<?php if ( $total_pages > 1 &&  $page == 1 ) echo ' disabled'; ?>" title="Go to the previous page" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'mode' => $current_mode, 'pageno' => $page - 1, '_wpnonce' => false ) ), 'fix_duplicates_main_form_nonce' ) ); ?>">&lsaquo;</a>
									<span class="paging-input"><input class="current-page" title="Current page" type="text" name="pageno" value="<?php echo $page; ?>" size="1" /> of <span class="total-pages"><?php echo $total_pages; ?></span></span>
									<input type="submit" name="" id="go-to-page" class="button-secondary hidden" value="Go to"  />
									<a class="next-page<?php if ( $total_pages > 1 &&  $page == $total_pages ) echo ' disabled'; ?>" title="Go to the next page" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'mode' => $current_mode, 'pageno' => $page + 1, '_wpnonce' => false ) ), 'fix_duplicates_main_form_nonce' ) ); ?>">&rsaquo;</a>
									<a class="last-page<?php if ( $total_pages > 1 &&  $page == $total_pages ) echo ' disabled'; ?>" title="Go to the last page" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'mode' => $current_mode, 'pageno' => $total_pages, '_wpnonce' => false ) ), 'fix_duplicates_main_form_nonce' ) ); ?>">&raquo;</a>
								</span>
							</div>		

							<!-- Form: Show titles or show entries? -->
							<div class="view-switch">
							<?php // build the mode selector, creating links and showing the correct icon, etc. JavaScript is used elsewhere to supplement switching.
								$modes = array(
									'list'    => __( 'List View' ),
									'expanded' => __( 'Expanded' )
								);
								foreach ( $modes as $mode => $title ) {
									$class = ( $current_mode == $mode ) ? 'class="current"' : '';
									echo "<a id='fd-view-switch-$mode' href='" . esc_url( add_query_arg( 'mode', $mode ) ) . "' $class><img id='view-switch-$mode' src='" . esc_url( includes_url( 'images/blank.gif' ) ) . "' width='20' height='20' title='$title' alt='$title' /></a>\n";
								}			
							?>
							</div>

							<!-- Form: nonce for security purposes -->
							<?php // false because wp_http_referer is added recursively, making the URL too long. I don't use it anyway. ?>
							<?php wp_nonce_field( 'fix_duplicates_main_form_nonce', '_wpnonce', false ); ?>

						</form>
						<!-- Form: End form to search and or filter the results -->
						
						<!-- Form: Form for bulk actions etc -->
						<form id="fix-duplicates-bulk-form" class="fix-duplicates-bulk-form" action="" method="POST">
						<!-- 	<div class="alignleft actions">
								<select name='action'>
									<option value='-1' selected='selected'>Bulk Actions</option>
									<option value='noindexer'>Noindex Selected</option>
								</select>
								<input type="submit" name="" id="doaction" class="button-secondary action" value="Apply"  />
							</div>
							This was made to apply for selected items, but it's still keeping the oldest one, which can be confusing
						-->


						<br class="clear" />
					</div>
					<!-- End top navigation area -->

					<!-- Top extra buttons area -->
					<div class="fix-duplicates-top-buttons tablenav top">
						<fieldset>
							<strong>Noindex:</strong>
							<label class="fix-duplicate-rhs"><input id="duplicate_entry_noindex_below" name="duplicate_entry_noindex_all" type="radio" value="1" checked="checked" /> All listed below </label>
						</fieldset>
						<label><input type="submit" id="duplicate_entry_all_apply" name="duplicate_entry_all_apply" class="button-secondary action" value="Apply"  /></label>
					</div>
					<div id="duplicate_entry_warning" class="fix-duplicates-warning">
						<p>Choosing 'All' should be used with care. Note the following:
							<ul>
							<li>This might take a little longer.</li>
							<li>The process will be applied for all entries identified with a duplicate.</li>
							</ul>
						</p>
					</div>

					<!-- End extra buttons area -->

					<!-- Table listing the duplicates -->
					<table class="wp-list-table widefat fixed posts" cellspacing="0">
					
						<!-- Table headers -->
						<thead>
							<tr>
								<th scope="col" id="cb" class="manage-column column-cb check-column check-title"><!--<input type="checkbox" />--></th>
								<th scope="col" id="title" class="manage-column column-title">Title</th>
								<th scope="col" id="post_id" class="manage-column column-post_id">ID</th>
								<th scope="col" id="author" class="manage-column column-author">Author</th>
								<th scope="col" id="categories" class="manage-column column-categories">Categories</th>
								<th scope="col" id="words" class="manage-column column-words">Words</th>
								<th scope="col" id="comments" class="manage-column column-comments num"><span class="vers"><img alt="Comments" src="<?php echo esc_url( admin_url( 'images/comment-grey-bubble.png' ) ); ?>" /></span></th>
								<?php do_action( 'fix_duplicates_th' ); ?>
								<th scope="col" id="date" class="manage-column column-date sortable asc">Last Modified</th>
							</tr>
						</thead>
						
				<?php 
				// variable used group duplicate titles together
				$fix_duplicates_group_no = 1;
				
				// Start loop for each duplicate item (individual entries, we group them below by adding a control row for the first duplicate)
				foreach ( $fix_duplicates_result as $key => $value ) : 
				
					// if this title doesn't match previous row's title, we're on to a new duplicate, so start a new tbody and add summary / control row
					if ( trim( strtolower( $fix_duplicates_result[ $key-1 ][ 'post_content' ] ) ) != trim( strtolower( $value[ 'post_content' ] ) ) ) :
				?>
						<tbody id="fix-duplicates-group-<?php echo $fix_duplicates_group_no; ?>">

							<!-- Summary row showing the duplicate title rather than individual entries -->
							<tr id="duplicate-control-<?php echo $fix_duplicates_group_no; ?>" class="fixed-duplicates-control" valign="top">
								<td scope="row" class="check-column"
										<?php echo apply_filters( 'fix_duplicates_columns_colspan', 'colspan="8"' );
										?>>
									<strong>
										<?php
											echo $value[ 'post_title' ];
										?></strong>
										<?php
										// Work out the count by looping through the results. Also store all entries for this title for noindexing
										$count = 0;
										$fix_duplicates_this_duplicate_array = array();
										foreach ( $fix_duplicates_result as $key2 => $value2 ) {
											if ( trim( strtolower( $fix_duplicates_result[ $key2 ][ 'post_content' ] ) ) ==
														trim( strtolower( $value[ 'post_content' ] ) ) ) {
												$fix_duplicates_this_duplicate_array[] = $fix_duplicates_result[ $key2 ][ 'ID' ]; 
												$count++;
											}
										}
										echo '<span class="fix-duplicates-post-number">(' . $count . ' posts <span class="fix-duplicates-more-less">&uarr;</span>)</span>';
									?>
									
									<!-- Summary row actions which appear on hover -->
									<div class="row-actions">
										<input id="duplicate_entry_items_to_noindex_<?php echo $fix_duplicates_group_no; ?>" name="duplicate_entry_items_to_noindex[<?php echo $fix_duplicates_group_no; ?>]" type="hidden" value="<?php echo implode( '+', $fix_duplicates_this_duplicate_array ); ?>" />
										<label class="fix-duplicate-lhs"><input type="submit" id="duplicate_entry_apply_<?php echo $fix_duplicates_group_no; ?>" name="duplicate_entry_apply_<?php echo $fix_duplicates_group_no; ?>" class="button-secondary action" value="Apply"  /></label>
									</div>
								</td>
							</tr>
							<!-- End summary row showing the duplicate title rather than individual entries -->
				<?php
						$fix_duplicates_group_no ++;
					endif;  // end of special stuff for first row / new duplicate.
					// Next, we list the individual duplicate title. This happens for all entries whether first row or not (we're still in the loop).
				?>

							<!-- Individual title -->
							<tr id="post-<?php echo absint( $value[ 'ID' ] ); ?>" class="duplicate-group-<?php echo $fix_duplicates_group_no - 1; ?>" valign="top">
								<th scope="row" class="check-column">
									<!-- <input type="checkbox" name="post[]" value="<?php //echo absint( $value[ 'ID' ] ); ?>" /> -->
								</th>
								<td class="post-title page-title column-title">
									<strong>
										<a class="row-title" href="<?php echo admin_url( 'post.php?post=' . absint( $value[ 'ID' ] ) . '&amp;action=edit' ); ?>" title="Edit &#8220;<?php echo $value[ 'post_title' ]; ?>&#8221;"><?php echo get_permalink( absint( $value[ 'ID' ] ) ); ?></a>
										<?php if( $value[ 'post_status' ] != 'publish' ) : ?>
											- <span class='post-state'><?php echo $value[ 'post_status' ]; ?></span>
										<?php endif; ?>
									</strong>

									<div class="row-actions">
										<span class='view'><a href="<?php echo get_permalink( absint( $value[ 'ID' ] ) ); ?>" rel="permalink">View</a></span>
									</div>
								</td>
								<td class="post_id column-post_id"><?php echo absint( $value[ 'ID' ] ); ?></td>
								<td class="author column-author"><a href="edit.php?post_type=post&#038;author=<?php echo $value[ 'post_author' ]; ?>"><?php the_author_meta( 'display_name', $value[ 'post_author' ] ); ?></a></td>
								<td class="categories column-categories">
									<?php
									$fix_duplicates_category_array = get_the_category( absint( $value[ 'ID' ] ) );
									if ( $fix_duplicates_category_array ) {
										foreach( $fix_duplicates_category_array as $fix_duplicates_cat_key => $fix_duplicates_cat ) {
											echo '<a href="' . esc_url( 'edit.php?category_name=' . $fix_duplicates_cat->slug ) . '">' . esc_html( $fix_duplicates_cat->name ) . '</a>';
											if ( count( $fix_duplicates_category_array ) > $fix_duplicates_cat_key + 1 ) echo ', ';
										}
									}
									?>
								</td>
								<td class="words column-words"><?php echo str_word_count( strip_tags( $value[ 'post_content' ] ) ); ?></td>
								<td class="comments column-comments">
									<div class="post-com-count-wrapper">
										<a href="<?php echo admin_url( 'edit-comments.php?p=' . absint( $value[ 'ID' ] ) ); ?>" title='<?php echo number_format_i18n( get_pending_comments_num( absint( $value[ 'ID' ] ) ) ); ?> pending' class='post-com-count'><span class='comment-count'><?php echo number_format_i18n( get_comments_number( absint( $value[ 'ID' ] ) ) ); ?></span></a>
									</div>
								</td>
								<?php do_action( 'fix_duplicates_td' ); ?>
								<td class="date column-date"><abbr title="<?php echo $value[ 'post_date' ]; ?>"><?php echo $value[ 'post_date' ]; ?></abbr></td>
							</tr>
							<!-- End individual title -->

				<?php
					// if this title doesn't match next row's title, close the tbody
					if ( trim( strtolower( $fix_duplicates_result[ $key + 1 ][ 'post_content' ] ) ) != trim( strtolower( $value[ 'post_content' ] ) ) ) :	?>
						</tbody>
				<?php endif;
				endforeach;
				?>
					</table>
					<!-- End table listing the duplicates -->

					<!-- Bottom navigation area -->
					<div class="tablenav bottom">
						<div class="alignleft actions">
							<!--
							<select name='action2'>
								<option value='-1' selected='selected'>Bulk Actions</option>
								<option value='noindexer'>Noindex Selected</option>
							</select>
							<input type="submit" name="" id="doaction2" class="button-secondary action" value="Apply"  />
							PS taken out since could cause confusion
							-->
						</div>

						<!-- Form: nonce for security purposes -->
						<?php // false because wp_http_referer is added recursively, making the URL too long. I don't use it anyway. ?>
						<?php wp_nonce_field( 'fix_duplicates_main_form_nonce', '_wpnonce', false ); ?>

						<!-- Form: End the bulk action form -->
						</form>

						<!-- Form: Paging controls -->
						<?php // mostly links except 'jump to page'; using WordPress functions to build the links ?>
						<div class='tablenav-pages<?php if ( $total_pages <= 1 ) echo ' one-page'; ?>'>
							<span class="displaying-num"><?php echo $fix_duplicates_result_count; ?></span>
							<span class="pagination-links">
								<a class="first-page<?php if ( $total_pages > 1 &&  $page == 1 ) echo ' disabled'; ?>" title="Go to the first page" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'mode' => $current_mode, 'pageno' => '1', '_wpnonce' => false ) ), 'fix_duplicates_main_form_nonce' ) ); ?>">&laquo;</a>
								<a class="prev-page<?php if ( $total_pages > 1 &&  $page == 1 ) echo ' disabled'; ?>" title="Go to the previous page" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'mode' => $current_mode, 'pageno' => $page - 1, '_wpnonce' => false ) ), 'fix_duplicates_main_form_nonce' ) ); ?>">&lsaquo;</a>
								<span class="paging-input"><?php echo $page; ?> of <?php echo $total_pages; ?></span>
								<a class="next-page<?php if ( $total_pages > 1 &&  $page == $total_pages ) echo ' disabled'; ?>" title="Go to the next page" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'mode' => $current_mode, 'pageno' => $page + 1, '_wpnonce' => false ) ), 'fix_duplicates_main_form_nonce' ) ); ?>">&rsaquo;</a>
								<a class="last-page<?php if ( $total_pages > 1 &&  $page == $total_pages ) echo ' disabled'; ?>" title="Go to the last page" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'mode' => $current_mode, 'pageno' => $total_pages, '_wpnonce' => false ) ), 'fix_duplicates_main_form_nonce' ) ); ?>">&raquo;</a>
							</span>
						</div>

						<br class="clear" />
					</div>
					<!-- End bottom navigation area -->

				</div> <!-- class="inside" -->
			</div> <!-- class="stuffbox" -->

		</div> <!-- end id="poststuff" -->

		<?php fix_duplicates_admin_footer(); ?>

	</div> <!-- end class="wrap" -->

	<?php
}
// ********** End main function for the Admin area (fix_duplicates_admin_main) ***********


// ********** Function to handle Ajax request for control row ***********
function duplicate_entry_apply_callback() {

	// check the nonce if we've got any URL parameters set (apart from page which is always set)
	if ( ! check_admin_referer( 'fix_duplicates_main_form_nonce', 'wp_nonce' ) )
		wp_die( 'Sorry, you cannot access this page directly' );

	// Process the form POST actions by looping through them
	fix_duplicates_process_post_actions();
	die();

}
add_action( 'wp_ajax_duplicate_entry_apply', 'duplicate_entry_apply_callback' );
// **************************************************


// ********** Function to handle Ajax request for individual post noindexes ***********
function duplicate_noindex_individual_callback() {

	// check the nonce if we've got any URL parameters set (apart from page which is always set)
	if ( ! check_admin_referer( 'fix_duplicates_main_form_nonce', 'wp_nonce' ) )
		wp_die( 'Sorry, you cannot access this page directly' );

	// Process the form POST actions by looping through them
	fix_duplicates_process_noindex_individual( absint( $_POST[ 'post' ] ));
	die();

}
add_action( 'wp_ajax_duplicate_noindex_individual', 'duplicate_noindex_individual_callback' );
// **************************************************


// ********** Function to handle POST / GET actions (from both Ajax and form) for noindexing individual entries ***********
function fix_duplicates_process_noindex_individual( $fix_duplicates_id_to_process) {

	apply_custom_meta($fix_duplicates_id_to_process);
	echo '<div id="message" class="updated"><p><strong>SUCCESS: The following item was maked as noindex '
		. $fix_duplicates_id_to_process . '</strong></p></div>';

}
// *************************************************************************************************


// ********** Function to handle POST actions (from both Ajax and form) ***********
function fix_duplicates_process_post_actions() {

	// arrays to record items to noindex, items noindexed
	$fix_duplicates_items_to_process = array();
	$fix_duplicates_noindexed_items = array();

	// if we have $_POST['post'] set and either action or action2, then this is the bulk noindex action
	if ( is_array( $_POST[ 'post' ] ) && ( esc_html( $_POST[ 'action' ] == 'noindexer' ) || esc_html( $_POST[ 'action2' ] == 'noindexer' ) ) ) {
		// set the $fix_duplicates_items_to_process array to sanitized post array
		$fix_duplicates_items_to_process = array_map( 'absint', $_POST[ 'post' ] );

		if ( count($fix_duplicates_items_to_process) == 1){
			fix_duplicates_process_noindex_individual($fix_duplicates_items_to_process[0]);
			return;
		}
		else {
			// loop through the array of items to noindex and noindex them (recording their id to report as noindexed)
			$fix_duplicates_noindexed_items = fix_duplicates_noindex($fix_duplicates_items_to_process);
		}
	}

	// else if $_POST['duplicate_entry_all_apply'] is set, then this the bulk "noindex, keep" action
	elseif ( esc_html( $_POST[ 'duplicate_entry_all_apply' ] ) ) {
		// if this is set to one, then we are noindexing all on this page

		if ( absint( $_POST[ 'duplicate_entry_noindex_all' ] ) ) {
			foreach ( $_POST[ 'duplicate_entry_items_to_noindex' ] as $key => $value ) {

				// get the items to noindex
				$fix_duplicates_items_to_process = explode( '+', esc_html( $value ) );

				// call function to set as noindex
				$fix_duplicates_noindexed_items = fix_duplicates_noindex( $fix_duplicates_items_to_process);
			}
		}
	}

	// else it's from control row Apply and we have to loop through to see which one we're dealing with
	else {
		// loop through all the POST actions and work out what we're dealing with
		foreach( $_POST as $key => $value ) {

			// if this POST item is from an apply button
			if ( stristr( esc_html( $key ), 'duplicate_entry_apply_' ) ) {

				// get the number that apply was clicked for (slightly different for Ajax)
				if ( strtolower( esc_html( $value ) ) == 'apply' ) {
					$fix_duplicates_apply_no = absint( str_replace( esc_html( 'duplicate_entry_apply_' ), '', $key ) );
				}
				else {
					$fix_duplicates_apply_no = absint( $value );
				}

				// get the details for that number
				$fix_duplicates_items_to_process = explode( '+', esc_html( $_POST[ 'duplicate_entry_items_to_noindex' ][ $fix_duplicates_apply_no ] ) );

				// call function to noindex these
				$fix_duplicates_noindexed_items = fix_duplicates_noindex( $fix_duplicates_items_to_process);
			}
		}
	}
	
	// report on the items noindexed
	if ( count( $fix_duplicates_noindexed_items ) == 0 ) {
		echo '<div id="message" class="updated"><p><strong>
				Warning: There are no duplicate entries to include noindex tag.</strong></p></div>';
	}
	else{
		echo '<div id="message" class="updated"><p><strong>SUCCESS: The following items were marked with noindex custom field:
			' . implode( ', ', $fix_duplicates_noindexed_items[0] ) . '</strong></p></div>';
	}
					
}
// ********** End function to handle POST actions (from both Ajax and form) ***********


// ********** Function to handle noindex (called from fix_duplicates_process_post_actions() ) ***********
function fix_duplicates_noindex($fix_duplicates_items_to_process) {


	//take the oldest out of the array
	$oldest_element = array_pop( $fix_duplicates_items_to_process );

	if ((int) get_post_meta( $oldest_element, 'noindex', true ) === 1){
		delete_post_meta( $oldest_element, 'noindex' );
	}

	// loop through the items to be noindexed and report them (recording their id to report as noindexed)
	$fix_duplicates_noindexed_items = array();
	foreach ( $fix_duplicates_items_to_process as $post_id ) {
		apply_custom_meta( $post_id);
		$fix_duplicates_noindexed_items[] = $post_id;
	}
	
	// return the array of items noindexed and the success message
	return array( $fix_duplicates_noindexed_items);
}
// ********** End function to handle noindex  (called from fix_duplicates_process_post_actions() ) ***********

function apply_custom_meta($post_id){
	update_post_meta( $post_id, 'noindex', 1 );
}


// ********** Function to display footer in Admin area **********
function fix_duplicates_admin_footer() {
	echo '<div style="clear:both;"></div><p class="fix-duplicates-copyright"><small><a href="https://github.com/process-street/noindex-duplicates" >Noindex Duplicates </a> is based on <a href="http://scratch99.com/">Stephen Cronin\'s </a> Fix Duplicates.</small></p>';
}
// *******************************************************
?>