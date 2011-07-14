<?php

class Document_Revisions_Admin extends Document_Revisions {

	/**
	 * Register's admin hooks
	 * Note: we are at admin_menu, first possible hook is admin_init
	 * @since 0.5
	 */
	function __construct() {
	
		//help and messages
		add_filter( 'post_updated_messages', array(&$this, 'update_messages') );
		add_action( 'contextual_help',array(&$this, 'add_help_text'), 10, 3 );

		//edit document screen
	 	add_action( 'admin_head', array( &$this, 'make_private' ) );
		add_action( 'admin_init', array( &$this, 'disable_richeditor' ), 1 );
	 	add_filter( 'media_meta', array( &$this, 'media_meta_hack'), 10, 1);
		add_filter( 'media_upload_form_url', array( &$this, 'post_upload_handler' ) );
		add_action( 'save_post', array( &$this, 'workflow_state_save' ) );
		add_action( 'admin_init', array( &$this, 'enqueue_autosave' ) );

		//document list
		add_filter( 'manage_edit-document_columns', array( &$this, 'add_workflow_state_column' ) );
		add_action( 'manage_document_posts_custom_column', array( &$this, 'workflow_state_column_cb' ), 10, 2 );

		//settings
		add_action( 'admin_init', array( &$this, 'settings_fields') );	
		
		//profile
		add_action( 'show_user_profile', array( $this, 'rss_key_display' ) );
		add_action( 'personal_options_update', array( &$this, 'profile_update_cb' ) );
		add_action( 'edit_user_profile_update', array( &$this, 'profile_update_cb' ) );
				
		//translation
		load_plugin_textdomain( 'wp-document-revisions', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
		
		//Queue up JS
		add_action( 'admin_init', array( &$this, 'enqueue_js' ) );
	
	}

	/**
	 * Registers update messages
	 * @param array $messages messages array
	 * @returns array messages array with doc. messages
	 * @since 0.5
	 */
	function update_messages( $messages ) {
	 	global $post, $post_ID;
	
		$messages['document'] = array(
			1 => sprintf( __( 'Document updated. <a href="%s">Download document</a>' ), esc_url( get_permalink($post_ID) ) ),
			2 => __( 'Custom field updated.' ),
			3 => __( 'Custom field deleted.' ),
			4 => __( 'Document updated.' ),
			5 => isset($_GET['revision']) ? sprintf( __( 'Document restored to revision from %s' ), wp_post_revision_title( (int) $_GET['revision'], false ) ) : false,
			6 => sprintf( __( 'Document published. <a href="%s">Download document</a>' ), esc_url( get_permalink($post_ID) ) ),
			7 => __( 'Document saved.' ),
			8 => sprintf( __( 'Document submitted. <a target="_blank" href="%s">Download document</a>' ), esc_url( add_query_arg( 'preview', 'true', get_permalink( $post_ID ) ) ) ),
			9 => sprintf( __( 'Document scheduled for: <strong>%1$s</strong>. <a target="_blank" href="%2$s">Preview document</a>'), date_i18n( __( 'M j, Y @ G:i' ), strtotime( $post->post_date ) ), esc_url( get_permalink( $post_ID ) ) ),
			10 => sprintf( __( 'Document draft updated. <a target="_blank" href="%s">Download document</a>'), esc_url( add_query_arg( 'preview', 'true', get_permalink( $post_ID ) ) ) ),
			);
	
		return $messages;
	}
	
	/**
	 * Registers help text with WP
	 * @todo the help text
	 * @since 0.5
	 */
	function add_help_text( $contextual_help, $screen_id, $screen ) { 

		if ( isset( $screen->post_type) && $screen->post_type != 'document' )
			return $contextual_help;
		
		if ( $screen_id == 'document' ) {
			$contextual_help = __( 'Document Edit Help Here' );
		}
		
		if ( $screen_id == 'edit-document' ) {
			$contextual_help = __( 'Document List Help Here' );
		}		
		
		return apply_filters( 'document_help', $contextual_help );
	}

	/**
	 * Callback to manage metaboxes on edit page
	 * @ since 0.5
	 */
	function meta_cb() {
		
		global $post;
		
		//remove unused meta boxes
		remove_meta_box( 'revisionsdiv', 'document', 'normal' );
		remove_meta_box( 'postexcerpt', 'document', 'normal' );	
		remove_meta_box( 'postcustom', 'document', 'normal' );
		remove_meta_box( 'workflow_statediv', 'document', 'side' );
		
		//add our meta boxes
		add_meta_box( 'revision-summary', 'Revision Summary', array(&$this, 'revision_summary_cb'), 'document', 'normal', 'default' );
		add_meta_box( 'document', 'Document', array(&$this, 'document_metabox'), 'document', 'normal', 'high' );
		
		if ( sizeof( wp_get_post_revisions( $post->ID ) ) > 0 )
			add_meta_box('revision-log', 'Revision Log', array( &$this, 'revision_metabox'), 'document', 'normal', 'low' );
		
		add_meta_box( 'workflow-state', 'Workflow State', array( &$this, 'workflow_state_metabox_cb'), 'document', 'side', 'default' );
		add_action( 'admin_head', array( &$this, 'admin_css') );

		//move author div to make room for ours
		remove_meta_box( 'authordiv', 'document', 'normal' );
		add_meta_box( 'authordiv', 'Author', array( &$this, 'post_author_meta_box' ), 'document', 'side', 'low' );

		//lock notice
		add_action( 'admin_notices', array( &$this,'lock_notice' ) );
		
		do_action( 'document_edit' );
	}
	
	/**
	 * Inject CSS into admin head
	 * @since 0.5
	 */
	function admin_css(){ 
			global $post; ?>
		<style>
			#postdiv {display:none;}
			#lock-notice {background-color: #D4E8BA; border-color: #92D43B; }
			#document-revisions {width: 100%; text-align: left;}
			#document-revisions td {padding: 5px 0 0 0;}
			#workflow-state select {margin-left: 25px; width: 150px;}
			#authordiv select {width: 150px;}
			#lock_override {float:right; text-align: right; margin-top: 10px; padding-bottom: 5px; }
			<?php if ( $this->get_document_lock( $post ) ) { ?>
			#publish, #add_media, #lock-notice {display: none;}
			<?php } ?>
		</style>
	<?php }

	/**
	 * Metabox to provide common document functions
	 * @param object $post the post object
	 * @since 0.5
	 */
	function document_metabox($post) {
		if ( $lock_holder = $this->get_document_lock( $post ) ) { ?>
		<div id="lock_override"> <?php printf( __('$s has prevented other users from making changes.<br />If you believe this is in error you can <a href="#" id="override_link">override the lock</a>, but their changes will be lost.', 'wp-document-revisions'), $lock_holder ); ?></div>
		<?php } ?>
		<div id="lock_override"><a href='media-upload.php?post_id=<?php echo $post->ID; ?>&TB_iframe=1&document=1' id='add_media' class='thickbox button' title='Upload Document' onclick='return false;' >Upload New Version</a></div>
		<?php
			$latest_version = $this->get_latest_version( $post->ID ); 
			if ( $latest_version) {			
		?>
		<p><strong><?php _e( 'Latest Version of the Document', 'wp-document-revisions' ); ?>:</strong>
		<strong><a href="<?php echo get_permalink( $post->ID ); ?>" target="_BLANK"><?php _e( 'Download', 'wp-document-revisions' ); ?></a></strong><br />
			<em><?php printf( __( 'Checked in %1$s ago by %2$s', 'wp-document-revisions' ), human_time_diff( get_the_modified_time( 'U' ), current_time( 'timestamp' ) ), get_the_author_meta( 'display_name', $latest_version->post_author ) ) ?></em>
		</p>
		<?php } //end if latest version ?>
		<div class="clear"></div>	
		<?php }

	/**
	 * Custom excerpt metabox CB
	 * @since 0.5
	 */
	function revision_summary_cb() { ?>
		<label class="screen-reader-text" for="excerpt"><?php _e('Revision Summary'); ?></label>
		<textarea rows="1" cols="40" name="excerpt" tabindex="6" id="excerpt"></textarea> 
		<p><?php _e( 'Revision summaries are optional notes to store along with this revision that allow other users to quickly and easily see what changes you made without needing to open the actual file.', 'wp-document-revisions' ); ?></a></p> 
	<?php }

	/**
	 * Creates revision log metabox
	 * @param object $post the post object
	 * @since 0.5
	 */
	function revision_metabox( $post ) {
 	
 		$can_edit_post = current_user_can( 'edit_post', $post->ID );
		$revisions = wp_get_post_revisions( $post->ID );
		
		$post->post_date = date( 'Y-m-d H:i:s', get_the_modified_time( 'U' ) );
				
		if ( sizeof( $revisions ) > 0 ) {
			
			//include currrent version in the revision list	
			array_unshift( $revisions, $post );
			
			?>			
		<table id="document-revisions">
			<tr class="header">
				<th><?php _e( 'Modified', 'wp_document_revisions'); ?></th>
				<th><?php _e( 'User', 'wp_document_revisions' ); ?></th>
				<th style="width:50%"><?php _e( 'Summary', 'wp_document_revisions' ); ?></th>
				<?php if ( $can_edit_post ) { ?><th><?php _e( 'Actions', 'wp_document_revisions' ); ?></th><?php } ?>
			</tr>
		<?php
		
		foreach ( $revisions as $revision ) {
		
			if ( !current_user_can( 'read_post', $revision->ID ) || wp_is_post_autosave( $revision ) )
				continue;
		?>
		<tr>
			<td><a href="<?php echo get_permalink( $revision->ID ); ?>" title="<?php echo $revision->post_date; ?>"><?php echo human_time_diff( strtotime( $revision->post_date ), current_time('timestamp') ); ?></a></td>
			<td><?php echo get_the_author_meta( 'display_name', $revision->post_author ); ?></td>
			<td><?php echo $revision->post_excerpt; ?></td>
			<?php if ( $can_edit_post ) { ?><td><?php if ( $post->ID != $revision->ID )
				echo '<a href="' . wp_nonce_url( add_query_arg( array( 'revision' => $revision->ID, 'action' => 'restore' ), 'revision.php' ), "restore-post_$post->ID|$revision->ID" ) . '" class="revision">' . __( 'Restore', 'wp-document-revisions') . '</a>'; ?></td><?php } ?>
		</tr>
		<?php		
		}
		?>
		</table>
		<?php $key = $this->get_feed_key(); ?>
		<p style="padding-top: 10px;";><a href="<?php echo add_query_arg( 'key', $key, get_permalink( $post->ID ) . '/feed/' ); ?>"><?php _e( 'RSS Feed', 'wp-document-revisions' ); ?></a></p>
		<?php
 		}
 	
 	}
	
	/**
	 * Disables the rich editor
	 * @since 0.5
	 */
	function disable_richeditor() { 
 	
 	if ( $this->verify_post_type() )
 		 	add_filter( 'get_user_option_rich_editing', '__return_false' ); 	
 		
 	}
 	
	/**
	 * Forces autosave to load
	 * By default, if there's a lock on the post, auto save isn't loaded; we want it in case lock is overridden
	 * @since 0.5
	 */
 	function enqueue_autosave() {
 	
 		if ( !$this->verify_post_type() )
			return;
			
 		wp_enqueue_script( 'autosave' );

 	}
 	
 	/**
 	 * Registers the document settings
 	 * @since 0.5
 	 */
 	function settings_fields() { 
 		register_setting( 'media', 'document_upload_directory' ); 
	 	add_settings_field( 'document_upload_directory', 'Document Upload Directory', array( &$this, 'upload_location_cb' ), 'media', 'uploads' ); 
 	
 	}
 	
 	/**
 	 * Callback to create the upload location settings field
 	 * @since 0.5
 	 */
 	function upload_location_cb() { ?>
 	<input name="document_upload_directory" type="text" id="document_upload_directory" value="<?php echo esc_attr( $this->document_upload_dir() ); ?>" class="regular-text code" /> 
<span class="description"><?php _e( 'Directory in which to store uploaded documents. The default is in your <code>wp_content/uploads</code> folder, but it may be moved to a folder outside of the <code>htdocs</code> or <code>public_html</code> folder for added security.', 'wp-document-revisions' ); ?></span> 
 	<?php }
 	
 	/**
 	 * Callback to inject JavaScript in page after upload is complete
 	 * @param int $id the ID of the attachment
 	 * @since 0.5
 	 */ 
 	function post_upload_js( $id ) {
 	
 		//can this be appended to the wp_document_revisions localization object?
 		
 		//get the post object
 		$post = get_post( $id );
 		
 		//get the extension from the post object to pass along to the client
 		$extension = $this->get_file_type( $post );
 		
 		//begin output buffer so the javascript can be returned as a string, rather than output directly to the browser
 		ob_start();
 		
 		?><script>
 		var attachmentID = <?php echo $id; ?>;
 		var extension = '<?php echo $extension; ?>';
 		jQuery(document).ready(function($) { $(this).trigger('documentUpload') });
 		</script><?php 
 		
 		//get contents of output buffer
 		$js = ob_get_contents();
 		
 		//dump output buffer
 		ob_end_clean();
 		
 		//return javascript
 		return $js;
 	}
 	
 	/**
 	 * Ugly, Ugly hack to sneak post-upload JS into the iframe
 	 * If there was a hook there, I wouldn't have to do this
 	 * @param string $meta dimensions / post meta
 	 * @returns string meta + js to process post
 	 * @since 0.5
 	 */
 	function media_meta_hack( $meta ) {
 		 
		if ( !$this->verify_post_type( ) )
			return $meta;
			
		global $post;			
		$latest = $this->get_latest_attachment( $post->ID );
		
		$meta .= $this->post_upload_js( $latest->ID );
		
		return $meta;
 	
 	}
 	
 	/**
	 * Hook to follow file uploads to automate attaching the document to the post
	 * @param string $filter whatever we really should be filtering
	 * @returns string the same stuff they gave us, like we were never here
	 * @since 0.5
	 */
	function post_upload_handler( $filter ) {
		
		//if we're not posting this is the initial form load, kick
		if ( !$_POST )
			return $filter;
		
		if ( !$this->verify_post_type ( $_POST['post_id'] ) )
			return $filter;	
			
		//get the object that is our new attachment
		$latest = $this->get_latest_attachment( $_POST['post_id'] );
		
		do_action('document_upload', $latest, $_POST['post_id'] );
		
		echo $this->post_upload_js( $latest->ID );
		
		//should probably give this back...
		return $filter; 

 }
	 
	 /**
	* Retrieves the most recent file attached to a post
	* @param int $post_id the parent post
	* @returns object the attachment object
	* @since 0.5
	*/
	 function get_latest_attachment( $post_id ) {
	 	$attachments = $this->get_attachments( $post_id );
		return reset( $attachments );
	 }

	/**
	* Callback to display lock notice on top of edit page
	* @since 0.5
	*/
 	function lock_notice() { 
 		global $post;
 	
 		do_action( 'document_lock_notice', $post );
 	
 		//if there is no page var, this is a new document, no need to warn
 		if ( !isset( $_GET['post'] ) )
 			return; 
 		
		?>
 		<div class="error" id="lock-notice"><p><?php _e( 'You currently have this file checked out. No other user can edit this document so long as you remain on this page.', 'wp-document-revisions' ); ?></p></div>
 	<?php }
 
 	/**
	* Callback to add RSS key field to profile page
	* @since 0.5
	*/
	function rss_key_display( ) { 
		$key = $this->get_feed_key();
		?>
		<div class="tool-box">
		<h3> <?php _e( 'Feed Privacy', 'wp-document-revisions' ); ?></h3>
		<table class="form-table"> 
			<tr id="document_revisions_feed_key"> 
				<th><label for="feed_key"><?php _e( 'Secret Feed Key', 'wp-document-revisions' ); ?></label></th> 
				<td>
					<input type="text" value="<?php echo esc_attr( $key ); ?>" class="regular-text" readonly="readonly" /><br />
					<span class="description"><?php _e( 'To protect your privacy, you need to append a key to feeds for use in feed readers.','wp-document-revisions' ); ?></span><br /> 
					<?php wp_nonce_field( 'generate-new-feed-key', '_document_revisions_nonce' ); ?>
					<?php submit_button( __( 'Generate New Key', 'wp-document-revisions' ), 'secondary', 'generate-new-feed-key', false ); ?>

				</td> 
			</tr> 
		</table> 
		<?php
	}
	
	/**
	 * Retrieves feed key user meta; generates if necessary
	 * @since 0.5
	 * @param int $user UserID
	 * @returns string the feed key
	 */
	function get_feed_key( $user = null ) {
		
		$key = get_user_option( self::meta_key, $user );
		
		if ( !$key )
			$key = $this->generate_new_feed_key();
			
		return $key;
		
	}
	
	/**
	 * Generates, saves, and returns new feed key
	 * @param int $user UserID
	 * @returns string feed key
	 * @since 0.5
	 */
	function generate_new_feed_key( $user = null ) {
		
		if ( !$user )
			$user = get_current_user_id();
	
		$key = wp_generate_password( self::key_length, false, false );
		update_user_option( $user, self::meta_key, $key );
		
		return $key;
	}
	
	/**
	 * Callback to handle profile updates
	 * @since 0.5
	 */
	function profile_update_cb() {
	
		if ( isset( $_POST['generate-new-feed-key'] ) && isset( $_POST['_document_revisions_nonce'] ) && wp_verify_nonce( $_POST['_document_revisions_nonce'], 'generate-new-feed-key' ) ) 
			$this->generate_new_feed_key();
	
	}
	
	/**
	 * Splices workflow state column as 2nd (3rd) column on documents page
	 * @since 0.5
	 * @param array $defaults the original columns
	 * @returns array our spliced columns
	 */
	function add_workflow_state_column( $defaults ) {
		
		//get checkbox and title
		$output = array_slice( $defaults, 0, 2 );
		
		//splice in workflow state
		$output['workflow_state'] = __( 'Workflow State', 'wp-document-revisions' );
		
		//get the rest of the columns
		$output = array_merge( $output, array_slice( $defaults, 2 ) );
		
		//return
		return $output;
	}
	
	/**
	 * Callback to output data for workflow state column
	 * @param string $column_name the name of the column being propegated
	 * @param int $post_id the ID of the post being displayed
	 * @since 0.5
	 */
	function workflow_state_column_cb( $column_name, $post_id ) {

		//verify column
		if ( $column_name != 'workflow_state' )
			return;
		
		//verify post type
		if ( !$this->verify_post_type( $post_id ) )
			return;
			
		//get terms	
		$state = wp_get_post_terms( $post_id, 'workflow_state' );

		//verify state exists
		if ( sizeof( $state ) == 0)
			return;
		
		//output (no return)
		echo $state[0]->name;
		
	}
	
	/**
	 * Callback to generate metabox for workflow state
	 * @param object $post the post object
	 * @since 0.5
	 */
	function workflow_state_metabox_cb( $post ) {
	
		wp_nonce_field( plugin_basename( __FILE__ ), 'workflow_state_nonce' );
		
		$current_state = wp_get_post_terms( $post->ID, 'workflow_state' );
		$states = get_terms( 'workflow_state', array( 'hide_empty'=> false ) );
		?>
		<label for="workflow_state"><?php _e( 'Current State', 'wp_document_revisions' ); ?>:</label>
		<select name="workflow_state" id="workflow_state">
			<option></option>
			<?php foreach ( $states as $state ) { ?>
			<option value="<?php echo $state->term_id; ?>" <?php if ( $current_state ) selected( $current_state[0]->slug, $state->slug ); ?>><?php echo $state->name; ?></option>
			<?php } ?>
		</select>
		<?php
	
	}
	
	/**
	 * Callback to save workflow_state metbox
	 * @param int $post_id the ID of the post being edited
	 * @since 0.5
	 */
	function workflow_state_save( $post_id ) {
	
		//verify form submit
		if ( !$_POST )
			return;
			
		//autosave check
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			return;
		
		//nonce is a funny word	
		if ( !wp_verify_nonce( $_POST['workflow_state_nonce'], plugin_basename( __FILE__ ) ) )
			return;
		
		//verify CPT
		if ( !$this->verify_post_type( $post_id ) )
			return;
		
		//check permissions
		if ( !current_user_can( 'edit_post', $post_id ) )
			return;

		//all's good, let's save		
		wp_set_post_terms( $post_id, array( $_POST['workflow_state'] ), 'workflow_state' );
	
	}
	
	/**
	 * Filters permalink displayed on edit screen in the event that there is no attachment yet uploaded
	 * @param string $html original HTML
	 * @param int $id Post ID
	 * @rerurns string modified HTML
	 * @since 0.5
	 */
	function sample_permalink_filter($html, $id ) {

		return $html;
		
 	}
 	
 	/**
 	 * Slightly modified document author metabox because the current one is ugly
 	 * @since 0.5
 	 * @param object $post the post object
 	 */
 	function post_author_meta_box( $post ) {
		global $user_ID;
		?>
		<label class="screen-reader-text" for="post_author_override"><?php _e( 'Author', 'wp_document_revisions' ); ?></label>
		<?php _e( 'Document Author', 'wp_document_revisions' ); ?>: 
		<?php
		wp_dropdown_users( array(
			'who' => 'authors',
			'name' => 'post_author_override',
			'selected' => empty($post->ID) ? $user_ID : $post->post_author,
			'include_selected' => true
		) );
	}
	
	function enqueue_js() {
	
		//only include JS on document pages
		if ( !$this->verify_post_type() )
			return;
	
		//translation strings
		$data = array(
			'restoreConfirmation' => __( 'Are you sure you want to restore this revision?\n\nIf you do, no history will be lost. This revision will be copied and become the most recent revision.', 'wp_document_revisions'),
			'lockNeedle' => __( 'is currently editing this'), //purposely left out text domain
			'postUploadNotice' => __( '<div id="message" class="updated" style="display:none"><p>File uploaded successfully. Add a revision summary below (optional) or press <em>Update</em> to save your changes.</p></div>'),
			'lostLockNotice' => __( 'CHANGE THIS: You no longer have this file checked out.', 'wp_document_revisions' ),
			'lockError' => __( 'An error has occured, please try reloading the page.', 'wp_document_revisions' ),
		);
		
		$suffix = ( WP_DEBUG ) ? '.dev' : '';
		wp_enqueue_script( 'wp_document_revisions',plugins_url('/wp-document-revisions' . $suffix . '.js', __FILE__), array('jquery') );
		wp_localize_script( 'wp_document_revisions', 'wp_document_revisions', $data );
	
	}
		 
}
?>