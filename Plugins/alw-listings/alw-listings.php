<?php
/**
 * Plugin Name: Assisted Living Works Listings
 * Plugin URI:  https://assistedlivingworks.com/
 * Description: Basic and Premium custom functionality for AssistedLivingWorks listings, user profile enhancements, and form submissions.
 * Version:     0.8.3 // Updates css and Cat Listings function.
 * Author:      jtrevino@treevine.life
 * Author URI:  https://treevine.life
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: alw-listings
 * Domain Path: /languages
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure unique class name to prevent conflicts
if ( ! class_exists( 'ALW_Listings' ) ) {

	class ALW_Listings {

		// Define the listing categories
		private $listing_categories = [
			'assisted-living' => 'Assisted Living',
			'senior-care'       => 'Senior Care',
			'hospice-care'      => 'Hospice Care',
			'home-care'         => 'Home Care',
			'memory-care'       => 'Memory Care',
		];
		
		// Replace with your actual keys from the Google reCAPTCHA admin console.
		private $recaptcha_site_key   = '6LffKZYrAAAAAJUEUMVHIfsOGuUsQWpCsA-FKug_';
		private $recaptcha_secret_key = '6LffKZYrAAAAABn189HYB1_cmMSl40x1q9lanXQu';

		public function __construct() {
			// Core Functionality
			add_action( 'init', array( $this, 'register_custom_post_types' ) );
			add_action( 'init', array( $this, 'register_custom_post_statuses' ) );

			// User Profile Enhancements
			add_action( 'show_user_profile', array( $this, 'add_user_phone_field' ) );
			add_action( 'edit_user_profile', array( $this, 'add_user_phone_field' ) );
			add_action( 'personal_options_update', array( $this, 'save_user_phone_field' ) );
			add_action( 'edit_user_profile_update', array( $this, 'save_user_phone_field' ) );

			// Shortcodes
			add_shortcode( 'alw_basic_listing_form', array( $this, 'basic_listing_form_shortcode' ) );
			add_shortcode( 'alw_premium_listing_form', array( $this, 'premium_listing_form_shortcode' ) );
			add_shortcode( 'alw_all_listings', array( $this, 'alw_all_listings_shortcode' ) );
			add_shortcode( 'alw_my_listings', array( $this, 'alw_my_listings_shortcode' ) );
			add_shortcode( 'alw_listings_by_category', array( $this, 'alw_listings_by_category_shortcode' ) ); // New shortcode

			// Form Submission Handling
			add_action( 'admin_post_nopriv_alw_submit_basic_listing', array( $this, 'handle_basic_listing_submission' ) );
			add_action( 'admin_post_alw_submit_basic_listing', array( $this, 'handle_basic_listing_submission' ) );
			add_action( 'admin_post_nopriv_alw_submit_premium_listing', array( $this, 'handle_premium_listing_submission' ) );
			add_action( 'admin_post_alw_submit_premium_listing', array( $this, 'handle_premium_listing_submission' ) );

			// Asset Enqueueing
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_plugin_assets' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

			// Meta Boxes and Saving
			add_action( 'add_meta_boxes_listing', array( $this, 'alw_add_custom_listing_metabox' ) );
			add_action( 'save_post_listing', array( $this, 'alw_save_listing_meta_box_data' ) );
			add_action( 'add_meta_boxes_listing', array( $this, 'alw_add_status_control_metabox' ) );
			add_filter( 'wp_insert_post_data', array( $this, 'alw_save_listing_status_from_metabox' ), 99, 2 );

			// Admin List Enhancements
			add_filter( 'display_post_states', array( $this, 'alw_display_listing_states' ), 10, 2 );
			add_filter( 'parse_query', array( $this, 'alw_add_custom_post_status_to_admin_list' ) );
		}

		/**
		 * Removes the 'listing' post type from Elementor's post type selector.
		 */
		public function alw_remove_listing_from_elementor( $post_types ) {
			if ( isset( $post_types['listing'] ) ) {
				unset( $post_types['listing'] );
			}
			return $post_types;
		}

		/**
		 * Adds a custom meta box for listing status control in the admin.
		 */
		public function alw_add_status_control_metabox() {
			add_meta_box('alw_status_control_metabox', __('Listing Status Control','alw-listings'), array( $this, 'alw_display_status_control_metabox' ), 'listing', 'side', 'high');
		}

		/**
		 * Displays the content of the listing status control meta box.
		 */
		public function alw_display_status_control_metabox( $post ) {
			wp_nonce_field( 'alw_save_status_metabox', 'alw_status_nonce' );
			$current_status = $post->post_status;
			// Ensure 'pending' is included if it's not explicitly handled elsewhere as a default
			$statuses = array('pending' => __('Pending Review','alw-listings'), 'alw_active' => __('Active (Publicly Visible)','alw-listings'), 'alw_inactive' => __('Inactive (Hidden)','alw-listings'));
			echo '<p><strong>' . __('Set the status for this listing:','alw-listings') . '</strong></p>';
			foreach ( $statuses as $status_value => $status_label ) {
				echo '<label style="display: block; margin: 8px 0;"><input type="radio" name="_alw_listing_status_control" value="' . esc_attr( $status_value ) . '" ' . checked( $current_status, $status_value, false ) . '> ' . esc_html( $status_label ) . '</label>';
			}
			echo '<p class="description">' . __('Select a status and click "Update" to save.','alw-listings') . '</p>';
		}

		/**
		 * Saves the listing status from the meta box, overriding default save behavior.
		 */
		public function alw_save_listing_status_from_metabox( $data, $postarr ) {
			// Check nonce and user capabilities
			if ( !isset( $_POST['alw_status_nonce'] ) || !wp_verify_nonce( $_POST['alw_status_nonce'], 'alw_save_status_metabox' ) ) { return $data; }
			if ( 'listing' !== $data['post_type'] || ! current_user_can( 'edit_post', $postarr['ID'] ) ) { return $data; }
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return $data; }

			// If the custom status field is set, update the post status
			if ( isset( $_POST['_alw_listing_status_control'] ) ) {
				$new_status = sanitize_key( $_POST['_alw_listing_status_control'] );
				// Validate against allowed custom statuses
				if ( in_array( $new_status, array( 'pending', 'alw_active', 'alw_inactive' ) ) ) {
					$data['post_status'] = $new_status;
				}
			}
			return $data;
		}

		/**
		 * Registers the 'listing' custom post type.
		 */
		public function register_custom_post_types() {
			$labels = array(
				'name'                  => _x( 'Listings', 'Post Type General Name', 'alw-listings' ),
				'singular_name'         => _x( 'Listing', 'Post Type Singular Name', 'alw-listings' ),
				'menu_name'             => __( 'Listings', 'alw-listings' ),
				'all_items'             => __( 'All Listings', 'alw-listings' ),
				'add_new_item'          => __( 'Add New Listing', 'alw-listings' ),
				'add_new'               => __( 'Add New', 'alw-listings' ),
				'edit_item'             => __( 'Edit Listing', 'alw-listings' ),
				'new_item'              => __( 'New Listing', 'alw-listings' ),
				'view_item'             => __( 'View Listing', 'alw-listings' ),
				'search_items'          => __( 'Search Listing', 'alw-listings' ),
				'not_found'             => __( 'No listings found', 'alw-listings' ),
				'not_found_in_trash'    => __( 'No listings found in Trash', 'alw-listings' ),
			);
			$args = array(
				'label'                 => __( 'Listing', 'alw-listings' ),
				'description'           => __( 'Post Type for Business Listings', 'alw-listings' ),
				'labels'                => $labels,
				'supports'              => array( 'title', 'editor', 'author', 'custom-fields' ),
				'hierarchical'          => false,
				'public'                => true,
				'show_ui'               => true,
				'show_in_menu'          => true,
				'menu_position'         => 5,
				'menu_icon'             => 'dashicons-store',
				'show_in_admin_bar'     => true,
				'show_in_nav_menus'     => true,
				'can_export'            => true,
				'has_archive'           => true,
				'exclude_from_search'   => false,
				'publicly_queryable'    => true,
				'capability_type'       => 'post',
				'rewrite'               => array( 'slug' => 'listing', 'with_front' => false ),
				'show_in_rest'          => true, // Enable Gutenberg editor and REST API access
			);
			register_post_type( 'listing', $args );
		}

		/**
		 * Registers custom post statuses for 'alw_active' and 'alw_inactive'.
		 */
		public function register_custom_post_statuses() {
			// Register 'Active' status
			register_post_status('alw_active', array(
				'label'                     => _x('Active', 'post status', 'alw-listings'),
				'public'                    => true, // Make it visible in queries
				'exclude_from_search'       => false, // Include in searches
				'show_in_admin_all_list'    => true, // Show in "All" list in admin
				'show_in_admin_status_list' => true, // Show in status filter dropdown
				'label_count'               => _n_noop('Active <span class="count">(%s)</span>', 'Active <span class="count">(%s)</span>', 'alw-listings'),
				'show_in_rest'              => true, // Make available via REST API
			));
			// Register 'Inactive' status
			register_post_status('alw_inactive', array(
				'label'                     => _x('Inactive', 'post status', 'alw-listings'),
				'public'                    => false, // Not publicly queryable by default
				'exclude_from_search'       => true, // Exclude from searches
				'show_in_admin_all_list'    => true, // Show in "All" list in admin
				'show_in_admin_status_list' => true, // Show in status filter dropdown
				'label_count'               => _n_noop('Inactive <span class="count">(%s)</span>', 'Inactive <span class="count">(%s)</span>', 'alw-listings'),
				'show_in_rest'              => true, // Make available via REST API
			));
		}

		/**
		 * Adds custom post states (like 'Active', 'Inactive') to the admin list view.
		 */
		public function alw_display_listing_states( $post_states, $post ) {
			if ( get_post_type( $post->ID ) === 'listing' ) {
				$status_obj = get_post_status_object( $post->post_status );
				// Display custom statuses if they have a label and are one of our custom ones
				if ( $status_obj && in_array( $post->post_status, ['alw_active', 'alw_inactive'] ) ) {
					$post_states[$post->post_status] = $status_obj->label;
				} elseif ( $post->post_status === 'pending' ) {
					// Custom label for pending status if needed
					$post_states['pending'] = _x('Pending Review', 'post status', 'alw-listings');
				}
			}
			return $post_states;
		}

		/**
		 * Modifies the admin query to include custom post statuses when 'all' is selected.
		 */
		public function alw_add_custom_post_status_to_admin_list( $query ) {
			// Only modify the main query in the admin area for the 'listing' post type
			if ( is_admin() && $query->is_main_query() && $query->get( 'post_type' ) === 'listing' ) {
				// If 'all' statuses are requested or no status is specified, include our custom statuses
				if ( empty( $query->get( 'post_status' ) ) || $query->get( 'post_status' ) === 'all' ) {
					$query->set( 'post_status', array_merge( get_post_stati(), array( 'alw_active', 'alw_inactive' ) ) );
				}
			}
		}

		/**
		 * Adds the phone number field to the user profile page.
		 */
		public function add_user_phone_field( $user ) {
			?>
			<h3><?php _e('Contact Information (AssistedLivingWorks)','alw-listings');?></h3>
			<table class="form-table">
				<tr>
					<th><label for="alw_phone"><?php _e('Phone Number','alw-listings');?></label></th>
					<td>
						<input type="tel" name="alw_phone" id="alw_phone" value="<?php echo esc_attr( get_user_meta( $user->ID, 'alw_phone', true ) ); ?>" class="regular-text" />
						<br/>
						<span class="description"><?php _e('Please enter your 10-digit phone number.','alw-listings');?></span>
					</td>
				</tr>
			</table>
			<?php
		}

		/**
		 * Saves the phone number field from the user profile page.
		 */
		public function save_user_phone_field( $user_id ) {
			if ( ! current_user_can( 'edit_user', $user_id ) ) {
				return false;
			}
			if ( isset( $_POST['alw_phone'] ) ) {
				update_user_meta( $user_id, 'alw_phone', sanitize_text_field( $_POST['alw_phone'] ) );
			}
		}

		/**
		 * Enqueues scripts and styles for the frontend.
		 */
		public function enqueue_plugin_assets() {
			$post = get_post();

			// Check if the post content contains any of our plugin's shortcodes
			if ( is_a( $post, 'WP_Post' ) && (
				has_shortcode( $post->post_content, 'alw_basic_listing_form' ) ||
				has_shortcode( $post->post_content, 'alw_premium_listing_form' ) ||
				has_shortcode( $post->post_content, 'alw_listings_by_category' ) ||
				has_shortcode( $post->post_content, 'alw_all_listings' ) ||
				has_shortcode( $post->post_content, 'alw_my_listings' )
			) ) {
				// Enqueue the main stylesheet for all our shortcodes
				wp_enqueue_style( 'alw-listing-styles', plugin_dir_url( __FILE__ ) . 'assets/css/alw-form-styles.css', array(), '0.8.4' );
			}

			if ( is_a( $post, 'WP_Post' ) && (
				has_shortcode( $post->post_content, 'alw_basic_listing_form' ) ||
				has_shortcode( $post->post_content, 'alw_premium_listing_form' )
			) ) {
				// Enqueue Google's reCAPTCHA script
				wp_enqueue_script( 'google-recaptcha', 'https://www.google.com/recaptcha/api.js', array(), null, true );

				// Enqueue your other form-specific scripts
				wp_enqueue_media();
				wp_enqueue_script( 'alw-premium-scripts', plugin_dir_url( __FILE__ ) . 'assets/js/alw-premium-scripts.js', array( 'jquery' ), '0.8.4', true );
			}
		}

		/**
		 * Enqueues scripts and styles for the admin area, specifically for the 'listing' post type.
		 */
		public function enqueue_admin_assets( $hook ) {
			global $post;
			// Check if we are on a post edit or new post screen
			if ( $hook == 'post-new.php' || $hook == 'post.php' ) {
				// Check if the current post type is 'listing'
				if ( isset( $post->post_type ) && 'listing' === $post->post_type ) {
					// Enqueue WordPress Media Uploader scripts for admin meta boxes
					wp_enqueue_media();
				}
			}
		}
 
		/**
		 * Shortcode to display the basic listing submission form.
		 * Usage: [alw_basic_listing_form]
		 */
		public function basic_listing_form_shortcode() {
			ob_start();
			$this->render_form_template('basic');
			return ob_get_clean();
		}

		/**
		 * Shortcode to display the premium listing submission form.
		 * Usage: [alw_premium_listing_form]
		 */
		public function premium_listing_form_shortcode() {
			ob_start();
			$this->render_form_template('premium');
			return ob_get_clean();
		}
 
		/**
		 * Renders the HTML for the listing submission forms (basic or premium).
		 * @param string $type 'basic' or 'premium'.
		 */
		private function render_form_template($type = 'basic') {
			$current_user = wp_get_current_user();
			$is_logged_in = is_user_logged_in();

			// Display success or error messages from redirects
			if (isset($_GET['submission_success'])) { echo '<div class="alw-alert alw-alert-success">Thanks for submitting your Listing! We will review it and notify you once it\'s live.</div>'; }
			if (isset($_GET['submission_errors'])) {
				$errors = json_decode(base64_decode(sanitize_text_field($_GET['submission_errors'])), true);
				if(is_array($errors) && !empty($errors)) {
					echo '<div class="alw-alert alw-alert-danger"><ul>';
					foreach($errors as $error) { echo '<li>'.esc_html($error).'</li>'; }
					echo '</ul></div>';
				}
			}
 
			// Pre-populate form fields with user data or previous submission data
			$data = [];
			$fields = ['email', 'first_name', 'last_name', 'phone', 'company', 'description', 'address1', 'address2', 'zipcode', 'city', 'state', 'website'];
			foreach ($fields as $field) {
				$user_val = '';
				if ($is_logged_in) {
					if ($field === 'email') $user_val = $current_user->user_email;
					if ($field === 'first_name') $user_val = $current_user->first_name;
					if ($field === 'last_name') $user_val = $current_user->last_name;
					if ($field === 'phone') $user_val = get_user_meta($current_user->ID, 'alw_phone', true);
				}
				// Use POST data if available, otherwise user data, otherwise empty
				$data[$field] = $_POST[$field] ?? $user_val;
			}
			$data['agree_checked'] = isset($_POST['agree']) && $_POST['agree'] == '1' ? 'checked' : '';
			// Pre-populate categories
			if ($type === 'basic') {
				$data['category'] = $_POST['category'] ?? '';
			} else { // premium
				$data['categories'] = $_POST['categories'] ?? array();
			}
 
			$form_id = $type . '-listing-form';
			$nonce_action = 'alw_' . $type . '_listing_nonce';
			$submit_action = 'alw_submit_' . $type . '_listing';
			$submit_text = 'Submit ' . ucfirst($type) . ' Listing';

			?>
			<div class="container">
			<form name="<?php echo $form_id; ?>" id="<?php echo $form_id; ?>" class="<?php echo $form_id; ?>" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" autocomplete="off" enctype="multipart/form-data">
				<?php wp_nonce_field( $nonce_action, 'alw_listing_form_nonce' ); ?>
				<input type="hidden" name="action" value="<?php echo $submit_action; ?>">
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( get_the_permalink() ); ?>">

				<div class="row">
					<div class="col-lg-5 col-md-5 col-sm-12 col-12 group">
						<h3 class="form-heading">Account Details</h3>
						<?php if ( ! $is_logged_in ) : ?>
							<div class="form-group has-feedback"><label class="control-label" for="email">Email <span class="red-asterisk">*</span></label><input type="email" name="email" id="email" value="<?php echo esc_attr( $data['email'] ); ?>" class="form-control" required placeholder="Enter Email Address Here"><i class="input-add-on fa-solid fa-envelope"></i></div>
							<div class="form-group has-feedback"><label class="control-label" for="password">Password <span class="red-asterisk">*</span></label><input type="password" name="password" id="password" class="password form-control" required placeholder="Enter Password Here"><i class="input-add-on fa-solid fa-eye"></i></div>
							<div class="form-group has-feedback"><label class="control-label" for="password2">Confirm Password <span class="red-asterisk">*</span></label><input type="password" name="password2" id="password2" class="password form-control" required placeholder="Confirm Password Here"><i class="input-add-on fa-solid fa-eye"></i></div>
						<?php else : ?>
							<p>Logged in as: <strong><?php echo esc_html( $current_user->display_name ); ?> (<?php echo esc_html( $current_user->user_email ); ?>)</strong></p>
							<input type="hidden" name="email" value="<?php echo esc_attr( $data['email'] ); ?>">
						<?php endif; ?>
						<div class="form-group has-feedback"><label class="control-label" for="first_name">Your Name <span class="red-asterisk">*</span></label><input type="text" name="first_name" id="first_name" value="<?php echo esc_attr( $data['first_name'] ); ?>" class="form-control" required placeholder="Enter Your First Name Here"><i class="input-add-on fa-solid fa-user"></i></div>
						<div class="form-group has-feedback"><label class="control-label" for="last_name">Your Last Name <span class="red-asterisk">*</span></label><input type="text" name="last_name" id="last_name" value="<?php echo esc_attr( $data['last_name'] ); ?>" class="form-control" required placeholder="Enter Your Last Name Here"><i class="input-add-on fa-solid fa-user"></i></div>
						<div class="form-group has-feedback"><label class="control-label" for="phone">Contact Phone # <span class="red-asterisk">*</span></label><input type="tel" name="phone" id="phone" value="<?php echo esc_attr( $data['phone'] ); ?>" pattern="[0-9]{10}" title="Phone number must be 10 digits (e.g., 5551234567)" class="form-control" required placeholder="Enter Phone Number Here"><i class="input-add-on fa-solid fa-phone"></i></div>
					</div>
					<div class="col-lg-1 col-md-1 col-sm-0 col-12">&nbsp;</div>
					<div class="col-lg-5 col-md-5 col-sm-12 col-12 group">
						<h3 class="form-heading">For Listing</h3>
						<div class="form-group has-feedback"><label class="control-label" for="company">Business Name <span class="red-asterisk">*</span></label><input type="text" name="company" id="company" value="<?php echo esc_attr( $data['company'] ); ?>" class="form-control" required placeholder="Enter Business Name Here"><i class="input-add-on fa-solid fa-building"></i></div>
						<div class="form-group has-feedback"><label class="control-label" for="description">Business Description (short) <span class="red-asterisk">*</span></label><textarea name="description" id="description" class="form-control" required rows="4" placeholder="Enter Business Description Here"><?php echo esc_textarea( $data['description'] ); ?></textarea><i class="input-add-on fa-solid fa-comment"></i></div>
						<div class="form-group has-feedback"><label class="control-label" for="address1">Address 1 <span class="red-asterisk">*</span></label><input type="text" name="address1" id="address1" value="<?php echo esc_attr( $data['address1'] ); ?>" class="form-control" required placeholder="Enter Address Line 1 Here"><i class="input-add-on fa-solid fa-address-card"></i></div>
						<div class="form-group has-feedback"><label class="control-label" for="address2">Address 2</label><input type="text" name="address2" id="address2" value="<?php echo esc_attr( $data['address2'] ); ?>" class="form-control" placeholder="Enter Address Line 2 Here"><i class="input-add-on fa-solid fa-address-card"></i></div>
						<div class="form-group has-feedback"><label class="control-label" for="city">City <span class="red-asterisk">*</span></label><input type="text" name="city" id="city" value="<?php echo esc_attr( $data['city'] ); ?>" class="form-control" required placeholder="Enter City Here"><i class="input-add-on fa-solid fa-location-dot"></i></div>
						<div class="form-group has-feedback"><label class="control-label" for="state">State <span class="red-asterisk">*</span></label><input type="text" name="state" id="state" value="<?php echo esc_attr( $data['state'] ); ?>" class="form-control" required placeholder="Enter State Here"><i class="input-add-on fa-solid fa-location-dot"></i></div>
						<div class="form-group has-feedback"><label class="control-label" for="zipcode">Zipcode <span class="red-asterisk">*</span></label><input type="text" name="zipcode" id="zipcode" value="<?php echo esc_attr( $data['zipcode'] ); ?>" pattern="[0-9]{5}" title="Zipcode must be 5 digits (e.g., 90210)" class="form-control" required placeholder="Enter Zipcode Here"><i class="input-add-on fa-solid fa-location-dot"></i></div>
						<div class="form-group has-feedback"><label class="control-label" for="website">Website</label><input type="url" name="website" id="website" value="<?php echo esc_attr( $data['website'] ); ?>" class="form-control" placeholder="Enter Website URL Here"><i class="input-add-on fa-solid fa-globe"></i></div>
					</div>
				</div>

				<?php if ($type === 'premium'): ?>
					<hr style="margin: 30px 0;">
					<div class="row">
						<div class="col-md-6 group">
							<h3 class="form-heading">Hours of Operation</h3>
							<p class="description">Ex: 8:00 am - 5:00 pm, or "Closed"</p>
							<?php $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']; ?>
							<?php foreach($days as $day): ?>
							<div class="form-group has-feedback">
								<label class="control-label" for="alw_hours_<?php echo $day; ?>"><?php echo ucfirst($day); ?></label>
								<input type="text" name="hours[<?php echo $day; ?>]" id="alw_hours_<?php echo $day; ?>" value="<?php echo esc_attr( $_POST['hours'][$day] ?? '' ); ?>" class="form-control" placeholder="hh:mm am - hh:mm pm">
							</div>
							<?php endforeach; ?>
						</div>
						<div class="col-md-6 group">
							<h3 class="form-heading">Image Gallery (Max 6)</h3>
							<p class="description">You can upload a maximum of 6 images. File size limits are determined by your server configuration.</p>
							<div class="form-group">
								<a href="#" id="alw-upload-gallery-button" class="button">Select Images</a>
								<div id="alw-gallery-container" style="margin-top: 15px;"></div>
								<input type="hidden" name="alw_gallery_ids" id="alw_gallery_ids" value="">
							</div>
						</div>
					</div>
				<?php endif; ?>

				<!-- Category Selection -->
				<hr style="margin: 30px 0;">
				<div class="row">
					<div class="col-md-12 group">
						<h3 class="form-heading">Listing Category <span class="red-asterisk">*</span></h3>
						<!-- Add specific class for basic/premium and general category selection -->
						<div class="alw-category-selection <?php echo ($type === 'basic') ? 'basic-categories' : 'premium-categories'; ?>">
							<div class="alw-category-options"> <!-- Wrapper for options -->
								<?php if ($type === 'basic'): ?>
									<?php foreach ($this->listing_categories as $slug => $name): ?>
										<div class="alw-category-option"> <!-- Wrapper for each radio -->
											<label>
												<input type="radio" name="category" value="<?php echo esc_attr($slug); ?>" <?php checked($data['category'], $slug, true); ?> required>
												<?php echo esc_html($name); ?>
											</label>
										</div>
									<?php endforeach; ?>
								<?php else: // premium ?>
									<?php foreach ($this->listing_categories as $slug => $name): ?>
										<div class="alw-category-option"> <!-- Wrapper for each checkbox -->
											<label>
												<input type="checkbox" name="categories[]" value="<?php echo esc_attr($slug); ?>" <?php echo in_array($slug, $data['categories']) ? 'checked' : ''; ?>>
												<?php echo esc_html($name); ?>
											</label>
										</div>
									<?php endforeach; ?>
								<?php endif; ?>
							</div> <!-- /.alw-category-options -->
						</div> <!-- /.alw-category-selection -->
					</div>
				</div>
				<!-- End Category Selection -->

				<div class="row"><div class="col-md-12 agree-container"><label for="agree">I accept the <a target="_blank" href="<?php echo esc_url( home_url( '/terms-and-conditions/' ) ); ?>">Terms & Conditions</a> <span class="red-asterisk">*</span></label><input type="checkbox" name="agree" id="agree" value="1" <?php echo $data['agree_checked']; ?> required></div></div>
				
				<div class="row" style="margin-top: 20px;">
                    <div class="col-md-12" style="display: flex; justify-content: center;">
                        <div class="g-recaptcha" data-sitekey="<?php echo esc_attr( $this->recaptcha_site_key ); ?>"></div>
                    </div>
                </div>
				
				<div class="row" style="margin-top: 20px;"><div class="col-sm-12" style="text-align:center;"><button type="submit" id="sub-btn" class="btn btn-info"><?php echo $submit_text; ?></button></div></div>
			</form>
			</div>
			<?php
		}

		/**
		 * Handles the submission of basic listing forms.
		 */
		public function handle_basic_listing_submission() {
			$this->_handle_common_listing_submission(false);
		}
 
		/**
		 * Handles the submission of premium listing forms.
		 */
		public function handle_premium_listing_submission() {
			$this->_handle_common_listing_submission(true);
		}
 
		/**
		 * Common handler for both basic and premium listing submissions.
		 * @param bool $is_premium Whether this is a premium submission.
		 */
		private function _handle_common_listing_submission( $is_premium = false ) {
			$nonce_key = 'alw_listing_form_nonce';
			$nonce_action = $is_premium ? 'alw_premium_listing_nonce' : 'alw_basic_listing_nonce';

			// Verify nonce
			if ( ! isset( $_POST[$nonce_key] ) || ! wp_verify_nonce( $_POST[$nonce_key], $nonce_action ) ) {
				wp_die( 'Security check failed. Please try again.' );
			}

			$errors = array();
			$redirect_url = esc_url_raw( $_POST['redirect_to'] ?? home_url() );

			// --- Field Sanitization ---
			$email = sanitize_email( $_POST['email'] ?? '' );
			$password = $_POST['password'] ?? '';
			$password2 = $_POST['password2'] ?? '';
			$first_name = sanitize_text_field( $_POST['first_name'] ?? '' );
			$last_name = sanitize_text_field( $_POST['last_name'] ?? '' );
			$phone = sanitize_text_field( $_POST['phone'] ?? '' );
			$company = sanitize_text_field( $_POST['company'] ?? '' );
			$description = sanitize_textarea_field( $_POST['description'] ?? '' );
			$address1 = sanitize_text_field( $_POST['address1'] ?? '' );
			$address2 = sanitize_text_field( $_POST['address2'] ?? '' );
			$zipcode = sanitize_text_field( $_POST['zipcode'] ?? '' );
			$city = sanitize_text_field( $_POST['city'] ?? '' );
			$state = sanitize_text_field( $_POST['state'] ?? '' );
			$website = sanitize_url( $_POST['website'] ?? '' );
			$agree = isset( $_POST['agree'] ) ? '1' : '0';

			// --- Validation ---
			if ( empty( $_POST['g-recaptcha-response'] ) ) {
                $errors[] = 'Please complete the CAPTCHA verification.';
            } else {
                $recaptcha_response = sanitize_text_field( $_POST['g-recaptcha-response'] );

                // Send a request to Google's verification server
                $response = wp_remote_post(
                    'https://www.google.com/recaptcha/api/siteverify',
                    array(
                        'body' => array(
                            'secret'   => $this->recaptcha_secret_key,
                            'response' => $recaptcha_response,
                            'remoteip' => $_SERVER['REMOTE_ADDR'],
                        ),
                    )
                );

                if ( is_wp_error( $response ) ) {
                    // Handle case where we can't connect to Google's server
                    $errors[] = 'Could not connect to the reCAPTCHA service. Please try again later.';
                } else {
                    $body = wp_remote_retrieve_body( $response );
                    $result = json_decode( $body );

                    if ( ! $result->success ) {
                        // Google returned a failure response
                        $errors[] = 'CAPTCHA verification failed. Please try again.';
                    }
                }
            }
            // End of reCAPTCHA verification
			
			if ( ! is_user_logged_in() ) {
				if ( empty( $email ) || ! is_email( $email ) ) $errors[] = 'Please provide a valid Email address.';
				if ( email_exists( $email ) ) $errors[] = 'This email is already registered. Please login or use a different email.';
				if ( empty( $password ) || strlen( $password ) < 8 ) $errors[] = 'Password must be at least 8 characters long.';
				if ( $password !== $password2 ) $errors[] = 'Passwords do not match.';
			}
			if ( empty( $first_name ) ) $errors[] = 'Your First Name is required.';
			if ( empty( $last_name ) ) $errors[] = 'Your Last Name is required.';
			// Updated phone pattern to be more specific for 10 digits
			if ( empty( $phone ) || ! preg_match( '/^[0-9]{10}$/', $phone ) ) { $errors[] = 'Please provide a valid 10-digit Contact Phone number (e.g., 5551234567).'; }
			if ( empty( $company ) ) $errors[] = 'Business Name is required.';
			if ( empty( $description ) ) $errors[] = 'Business Description is required.';
			if ( empty( $address1 ) ) $errors[] = 'Address 1 is required.';
			if ( empty( $city ) ) $errors[] = 'City is required.';
			if ( empty( $state ) ) $errors[] = 'State is required.';
			// Updated zipcode pattern to be more specific for 5 digits
			if ( empty( $zipcode ) || ! preg_match( '/^\d{5}$/', $zipcode ) ) { $errors[] = 'Please provide a valid 5-digit Zipcode.'; }
			if ( ! empty( $website ) && ! filter_var( $website, FILTER_VALIDATE_URL ) ) $errors[] = 'Please provide a valid Website URL.';
			if ( $agree !== '1' ) $errors[] = 'You must accept the Terms & Conditions.';

			// Category Validation
			$selected_categories = array();
			if ( $is_premium ) {
				if ( isset( $_POST['categories'] ) && is_array( $_POST['categories'] ) ) {
					$selected_categories = array_map( 'sanitize_key', $_POST['categories'] );
					// Ensure selected categories are valid
					$selected_categories = array_filter($selected_categories, function($slug) {
						return array_key_exists($slug, $this->listing_categories);
					});
				}
				if ( empty( $selected_categories ) ) {
					$errors[] = 'Please select at least one category for your premium listing.';
				}
			} else { // Basic listing
				$selected_category = sanitize_key( $_POST['category'] ?? '' );
				if ( empty( $selected_category ) || !array_key_exists($selected_category, $this->listing_categories) ) {
					$errors[] = 'Please select a category for your basic listing.';
				} else {
					$selected_categories[] = $selected_category; // Store as an array for consistency
				}
			}

			// Gallery Validation (Premium only)
			if ( $is_premium ) {
				$gallery_ids_raw = isset($_POST['alw_gallery_ids']) ? sanitize_text_field($_POST['alw_gallery_ids']) : '';
				if(!empty($gallery_ids_raw)) {
					$gallery_ids = array_filter(explode(',', $gallery_ids_raw), 'is_numeric');
					if (count($gallery_ids) > 6) { $errors[] = 'You may upload a maximum of 6 gallery images.'; }
				}
			}

			// If there are errors, redirect back to the form with errors
			if ( ! empty( $errors ) ) {
				wp_safe_redirect( add_query_arg( 'submission_errors', base64_encode( json_encode( $errors ) ), $redirect_url ) );
				exit;
			}

			// --- User and Post Creation/Update ---
			$user_id = get_current_user_id();
			if ( ! $user_id ) { // If not logged in, create a new user
				$user_id = wp_create_user( $email, $password, $email );
				if ( is_wp_error( $user_id ) ) {
					// Handle user creation failure
					wp_die( 'User registration failed: ' . $user_id->get_error_message() );
				}
				// Log the new user in
				wp_set_current_user( $user_id );
				wp_set_auth_cookie( $user_id, true );
			}

			// Update user details (name, phone)
			wp_update_user( array( 'ID' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name ) );
			update_user_meta( $user_id, 'alw_phone', $phone );

			// Prepare post data
			$post_data = array(
				'post_title'    => $company,
				'post_content'  => $description,
				'post_status'   => 'pending', // Default to pending review
				'post_type'     => 'listing',
				'post_author'   => $user_id,
			);

			// Insert the new listing post
			$new_listing_id = wp_insert_post( $post_data );
			if ( is_wp_error( $new_listing_id ) ) {
				wp_die( 'Error creating listing: ' . $new_listing_id->get_error_message() );
			}

			// --- Save Meta Data ---
			update_post_meta($new_listing_id, 'alw_listing_address1', $address1 );
			update_post_meta($new_listing_id, 'alw_listing_address2', $address2 );
			update_post_meta($new_listing_id, 'alw_listing_zipcode', $zipcode );
			update_post_meta($new_listing_id, 'alw_listing_city', $city );
			update_post_meta($new_listing_id, 'alw_listing_state', $state );
			update_post_meta($new_listing_id, 'alw_listing_website', $website );

			// Save listing type
			update_post_meta($new_listing_id, 'alw_listing_type', $is_premium ? 'premium' : 'basic');

			// Save categories (using a single meta key for all)
			$categories_to_save = implode(',', $selected_categories);
			update_post_meta($new_listing_id, 'alw_listing_categories', $categories_to_save);

			// Save premium-specific data
			if( $is_premium ) {
				// Save hours of operation
				$hours = isset($_POST['hours']) && is_array($_POST['hours']) ? array_map('sanitize_text_field', $_POST['hours']) : array();
				if (!empty($hours)) {
					foreach($hours as $day => $time) {
						update_post_meta($new_listing_id, 'alw_hours_' . $day, $time);
					}
				}
				// Save gallery image IDs
				if (isset($gallery_ids) && !empty($gallery_ids)) {
					update_post_meta($new_listing_id, 'alw_gallery_ids', implode(',', $gallery_ids));
				}
			}

			// --- Notifications ---
			$subject_prefix = $is_premium ? 'New Premium Listing' : 'New Basic Listing';
			$admin_email = get_option( 'admin_email' );
			$subject = $subject_prefix . ' Submitted: ' . $company;
			$message = "A new listing requires your review.\n\n";
			$message .= "Type: ".($is_premium ? 'Premium':'Basic')."\n";
			$message .= "Business Name: " . $company . "\n";
			$message .= "Submitted by: " . $first_name . " " . $last_name . " (" . $email . ")\n";
			$message .= "Categories: " . $this->get_category_names_from_slugs($categories_to_save) . "\n\n";
			$message .= "Review and activate at:\n" . admin_url( 'post.php?post=' . $new_listing_id . '&action=edit' );

			wp_mail( $admin_email, $subject, $message );

			// Redirect to the success page (or back to the form with success message)
			wp_safe_redirect( add_query_arg( 'submission_success', '1', $redirect_url ) );
			exit;
		}

		/**
		 * Adds a custom meta box to display and edit listing details in the admin.
		 */
		public function alw_add_custom_listing_metabox() {
			add_meta_box('alw_listing_details_metabox', __('Listing Details', 'alw-listings'), array( $this, 'alw_display_listing_meta_box' ), 'listing' , 'normal', 'high' );
		}

		/**
		 * Displays the content of the listing details meta box.
		 */
		public function alw_display_listing_meta_box( $post ) {
			// Add nonce for security
			wp_nonce_field( 'alw_save_listing_meta', 'alw_listing_meta_nonce' );

			// Get existing meta data
			$listing_type = get_post_meta( $post->ID, 'alw_listing_type', true );
			$current_categories_str = get_post_meta( $post->ID, 'alw_listing_categories', true );
			$current_categories = !empty($current_categories_str) ? explode(',', $current_categories_str) : array();

			// Basic styling for the meta box table
			echo '<style>
				.alw-meta-table { width: 100%; }
				.alw-meta-table td, .alw-meta-table th { text-align: left; padding: 8px; vertical-align: top; }
				.alw-meta-table th { width: 150px; font-weight: 700; }
				.alw-meta-table input[type="text"], .alw-meta-table textarea { width: 98%; box-sizing: border-box; }
				.alw-meta-table input[type="url"] { width: 98%; box-sizing: border-box; }
				.alw-meta-table .radio-label, .alw-meta-table .checkbox-label { display: block; margin-bottom: 5px; }
				.alw-meta-table .radio-label input, .alw-meta-table .checkbox-label input { margin-right: 8px; }
				.alw-gallery-thumb { max-width: 100px; height: auto; margin: 5px; border: 1px solid #ddd; vertical-align: middle; }
				.alw-admin-gallery-preview { display: flex; flex-wrap: wrap; gap: 10px; }
			</style>';

			// --- Basic Details Section ---
			echo '<h4>Basic Details</h4><table class="alw-meta-table">';
			$basic_fields = array('address1' => 'Address Line 1', 'address2' => 'Address Line 2', 'city' => 'City', 'state' => 'State', 'zipcode' => 'Zipcode', 'website' => 'Website');
			foreach ( $basic_fields as $key => $label ) {
				$value = get_post_meta( $post->ID, 'alw_listing_' . $key, true );
				echo '<tr><th><label for="alw_listing_' . esc_attr($key) . '">' . esc_html( $label ) . ':</label></th><td><input type="text" id="alw_listing_' . esc_attr($key) . '" name="alw_listing_' . esc_attr($key) . '" value="' . esc_attr( $value ) . '"></td></tr>';
			}
			echo '</table>';

			// --- Category Selection (Admin View) ---
			echo '<hr><h4>Category Selection</h4><table class="alw-meta-table">';
			echo '<tr><th><label>Categories:</label></th><td>';
			if ($listing_type === 'premium') {
				// Premium: Show checkboxes
				foreach ($this->listing_categories as $slug => $name) {
					echo '<label class="checkbox-label"><input type="checkbox" name="alw_listing_categories[]" value="' . esc_attr($slug) . '" ' . (in_array($slug, $current_categories) ? 'checked' : '') . '> ' . esc_html($name) . '</label>';
				}
			} else {
				// Basic: Show radio buttons
				foreach ($this->listing_categories as $slug => $name) {
					echo '<label class="radio-label"><input type="radio" name="alw_listing_categories" value="' . esc_attr($slug) . '" ' . checked($current_categories[0] ?? '', $slug, false) . '> ' . esc_html($name) . '</label>';
				}
			}
			echo '</td></tr>';
			echo '</table>';


			// --- Premium Details Section ---
			if ($listing_type === 'premium') {
				echo '<hr><h4>Premium Details</h4><table class="alw-meta-table">';
				$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
				foreach ($days as $day) {
					$key = 'alw_hours_' . strtolower($day);
					$value = get_post_meta($post->ID, $key, true);
					echo '<tr><th><label for="'.esc_attr($key).'">' . esc_html($day) . ' Hours:</label></th><td><input type="text" id="'.esc_attr($key).'" name="'.esc_attr($key).'" value="' . esc_attr( $value ) . '" placeholder="hh:mm am - hh:mm pm"></td></tr>';
				}
				echo '<tr><th><label>Gallery Images</label></th><td><div class="alw-admin-gallery-preview">';
				$gallery_ids_str = get_post_meta($post->ID, 'alw_gallery_ids', true);
				if (!empty($gallery_ids_str)) {
					$gallery_ids = explode(',', $gallery_ids_str);
					foreach($gallery_ids as $id) {
						// Ensure attachment exists before trying to get image
						if (wp_get_attachment_image($id, 'thumbnail', false, ['class' => 'alw-gallery-thumb'])) {
							echo wp_get_attachment_image($id, 'thumbnail', false, ['class' => 'alw-gallery-thumb']);
						}
					}
				}
				echo '</div><p class="description">Image gallery is managed by the user on the frontend form.</p></td></tr>';
				echo '</table>';
			}

			// --- Submitted By User Info ---
			$author = get_userdata($post->post_author);
			if($author) {
				echo '<hr><h4>Submitted By User</h4>';
				echo '<p><strong>Name:</strong> ' . esc_html( $author->first_name . ' ' . $author->last_name ) . '<br>';
				echo '<strong>Email:</strong> ' . esc_html( $author->user_email ) . '<br>';
				echo '<strong>Phone:</strong> ' . esc_html( get_user_meta( $author->ID, 'alw_phone', true ) ) . '</p>';
				echo '<p><a href="' . get_edit_user_link( $author->ID ) . '" target="_blank">View User Profile &raquo;</a></p>';
			}
		}

		/**
		 * Saves the meta data for the listing post when it's updated in the admin.
		 */
		public function alw_save_listing_meta_box_data( $post_id ) {
			// Verify nonce and check for autosave
			if ( ! isset( $_POST['alw_listing_meta_nonce'] ) || ! wp_verify_nonce( $_POST['alw_listing_meta_nonce'], 'alw_save_listing_meta' ) ) {
				return;
			}
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}
			// Check user permissions
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			// Define keys for basic and premium meta fields
			$basic_meta_keys = ['address1', 'address2', 'city', 'state', 'zipcode', 'website'];
			$premium_meta_keys = ['alw_hours_monday', 'alw_hours_tuesday', 'alw_hours_wednesday', 'alw_hours_thursday', 'alw_hours_friday', 'alw_hours_saturday', 'alw_hours_sunday', 'alw_gallery_ids'];

			// Save basic fields
			foreach ($basic_meta_keys as $key) {
				$post_key = 'alw_listing_' . $key;
				if (isset($_POST[$post_key])) {
					$value = ($key === 'website') ? sanitize_url($_POST[$post_key]) : sanitize_text_field($_POST[$post_key]);
					update_post_meta($post_id, $post_key, $value);
				}
			}

			// Save premium fields
			foreach ($premium_meta_keys as $key) {
				if (isset($_POST[$key])) {
					update_post_meta($post_id, $key, sanitize_text_field($_POST[$key]));
				}
			}

			// Save categories (handle single vs multiple based on admin input)
			$categories_to_save = '';
			if (isset($_POST['alw_listing_categories'])) {
				$posted_categories = $_POST['alw_listing_categories'];
				if (is_array($posted_categories)) {
					// Premium: multiple categories selected
					$categories_to_save = implode(',', array_map('sanitize_key', $posted_categories));
				} else {
					// Basic: single category selected
					$categories_to_save = sanitize_key($posted_categories);
				}
			}
			update_post_meta($post_id, 'alw_listing_categories', $categories_to_save);
		}

		/**
		 * Helper function to get display names for category slugs.
		 * @param string $category_slugs_string Comma-separated string of category slugs.
		 * @return string Comma-separated string of category display names.
		 */
		private function get_category_names_from_slugs($category_slugs_string) {
			if (empty($category_slugs_string)) {
				return '';
			}
			$slugs = explode(',', $category_slugs_string);
			$names = [];
			foreach ($slugs as $slug) {
				$slug = trim($slug);
				if (isset($this->listing_categories[$slug])) {
					$names[] = esc_html($this->listing_categories[$slug]);
				}
			}
			return implode(', ', $names);
		}

		/**
		 * Shortcode to display all active listings.
		 * Usage: [alw_all_listings]
		 */
		public function alw_all_listings_shortcode() {
			ob_start();
			// Query for active listings, ordered by title
			$args = array(
				'post_type'      => 'listing',
				'post_status'    => 'alw_active', // Use our custom active status
				'posts_per_page' => -1, // Show all
				'orderby'        => 'title',
				'order'          => 'ASC',
			);
			$listings_query = new WP_Query( $args );

			if ( $listings_query->have_posts() ) {
				echo '<div class="alw-all-listings-wrapper">';
				while ( $listings_query->have_posts() ) : $listings_query->the_post();
					$listing_id = get_the_ID();
					$listing_type = get_post_meta( $listing_id, 'alw_listing_type', true );
					$categories_str = get_post_meta( $listing_id, 'alw_listing_categories', true );
					$category_names = $this->get_category_names_from_slugs($categories_str);

					echo '<div class="alw-listing-item" style="border:1px solid #ddd;padding:20px;margin-bottom:25px;border-radius:5px;">';
					echo '<h3><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a>';
					if ( $listing_type === 'premium' ) {
						echo ' <span style="font-size:0.7em;color:#b49b3c;vertical-align:middle;">&#9733; Premium</span>';
					}
					echo '</h3>';

					// Display Categories
					if (!empty($category_names)) {
						echo '<p><strong>Categories:</strong> ' . $category_names . '</p>';
					}

					echo '<div class="alw-listing-description">' . wpautop( get_the_content() ) . '</div>';

					// Address and Website
					$address1 = get_post_meta( $listing_id, 'alw_listing_address1', true );
					$address2 = get_post_meta( $listing_id, 'alw_listing_address2', true );
					$city = get_post_meta( $listing_id, 'alw_listing_city', true );
					$state = get_post_meta( $listing_id, 'alw_listing_state', true );
					$zipcode = get_post_meta( $listing_id, 'alw_listing_zipcode', true );
					$website = get_post_meta( $listing_id, 'alw_listing_website', true );

					$full_address = esc_html($address1);
					if ( ! empty( $address2 ) ) {
						$full_address .= '<br>' . esc_html( $address2 );
					}
					$full_address .= '<br>' . esc_html( $city ) . ', ' . esc_html( $state ) . ' ' . esc_html( $zipcode );
					echo '<p><strong>Address:</strong><br>' . $full_address . '</p>';

					if ( ! empty( $website ) ) {
						$website_host = parse_url( $website, PHP_URL_HOST ) ?: $website;
						echo '<p><strong>Website:</strong> <a href="' . esc_url( $website ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $website_host ) . '</a></p>';
					}

					// Premium Details: Hours and Gallery
					if ( $listing_type === 'premium' ) {
						echo '<div style="margin-top:15px; border-top:1px dashed #ccc; padding-top:15px;"><h4>Hours of Operation</h4><ul>';
						$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
						$has_hours = false;
						foreach ( $days as $day ) {
							$hours = get_post_meta( $listing_id, 'alw_hours_' . strtolower( $day ), true );
							if ( trim( $hours ) !== '' ) {
								echo '<li><strong>' . esc_html($day) . ':</strong> ' . esc_html( $hours ) . '</li>';
								$has_hours = true;
							}
						}
						if ( ! $has_hours ) { echo '<li>Hours not provided.</li>'; }
						echo '</ul></div>';

						$gallery_ids_str = get_post_meta( $listing_id, 'alw_gallery_ids', true );
						if ( ! empty( $gallery_ids_str ) ) {
							echo '<div style="margin-top:15px; border-top:1px dashed #ccc; padding-top:15px;"><h4>Gallery</h4><div>';
							$gallery_ids = explode( ',', $gallery_ids_str );
							foreach ( $gallery_ids as $id ) {
								// Ensure attachment exists before trying to get image
								if (wp_get_attachment_image($id, 'thumbnail', false, ['style' => 'margin:5px;'])) {
									echo '<a href="' . esc_url( wp_get_attachment_url( $id ) ) . '" target="_blank">' . wp_get_attachment_image( $id, 'thumbnail', false, ['style' => 'margin:5px;'] ) . '</a>';
								}
							}
							echo '</div></div>';
						}
					}
					echo '</div>'; // End alw-listing-item
				endwhile;
				echo '</div>'; // End alw-all-listings-wrapper
				wp_reset_postdata(); // Restore original Post Data
			} else {
				echo '<p>No active listings found.</p>';
			}
			return ob_get_clean();
		}

		/**
		 * Shortcode to display listings submitted by the current logged-in user.
		 * Usage: [alw_my_listings]
		 */
		public function alw_my_listings_shortcode() {
			ob_start();
			// Check if user is logged in
			if ( ! is_user_logged_in() ) {
				echo '<p>Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">log in</a> to view your listings.</p>';
				return ob_get_clean();
			}

			$current_user_id = get_current_user_id();
			// Query for listings submitted by the current user, including all statuses
			$args = array(
				'post_type'      => 'listing',
				'author'         => $current_user_id,
				'posts_per_page' => -1, // Show all
				'orderby'        => 'date',
				'order'          => 'DESC',
				'post_status'    => array( 'pending', 'alw_active', 'alw_inactive', 'draft', 'publish' ), // Include all relevant statuses
			);
			$my_listings_query = new WP_Query( $args );

			if ( $my_listings_query->have_posts() ) {
				echo '<div class="alw-my-listings-wrapper"><h2>Your Submitted Listings:</h2><ul>';
				while ( $my_listings_query->have_posts() ) : $my_listings_query->the_post();
					$listing_id = get_the_ID();
					$listing_type = get_post_meta( $listing_id, 'alw_listing_type', true );
					$status = get_post_status();
					$status_obj = get_post_status_object( $status );
					$status_label = $status_obj ? esc_html( $status_obj->label ) : esc_html( $status );

					// Custom label for pending status
					if ( $status === 'pending' ) {
						$status_label = _x('Pending Review', 'post status', 'alw-listings');
					}

					echo '<li>';
					echo '<a href="' . esc_url( get_permalink() ) . '">' . get_the_title() . '</a>';
					if ( $listing_type === 'premium' ) {
						echo ' &#9733;'; // Premium star icon
					}
					echo ' - Status: ' . $status_label;
					// Link to edit the post if the user has permission
					if ( current_user_can( 'edit_post', $listing_id ) ) {
						echo ' (<a href="' . esc_url( get_edit_post_link( $listing_id ) ) . '">Edit</a>)';
					}
					echo '</li>';
				endwhile;
				echo '</ul></div>';
				wp_reset_postdata(); // Restore original Post Data
			} else {
				echo '<p>You have not submitted any listings yet.</p>';
			}
			return ob_get_clean();
		}

		/**
		 * Shortcode to display listings filtered by a specific category.
		 * Usage: [alw_listings_by_category category="assisted-living"]
		 * Replace "assisted-living" with one of the valid category slugs:
		 * assisted-living, senior-care, hospice-care, home-care, memory-care
		 *
		 * @param array $atts Shortcode attributes.
		 * @return string HTML output.
		 */
		public function alw_listings_by_category_shortcode( $atts ) {
			ob_start();

			// --- Initial Setup & Validation (No changes here) ---
			$atts = shortcode_atts( array('category' => ''), $atts, 'alw_listings_by_category' );
			$category_slug = sanitize_key( $atts['category'] );
			if ( empty( $category_slug ) || ! array_key_exists( $category_slug, $this->listing_categories ) ) {
				echo '<p class="alw-error">Error: Invalid or missing category slug.</p>';
				return ob_get_clean();
			}
			$category_name = $this->listing_categories[$category_slug];
			$current_page_url = get_permalink();

			// --- WP_Query Arguments (No changes here) ---
			$args = array(
				'post_type'      => 'listing',
				'post_status'    => 'alw_active',
				'posts_per_page' => -1,
				'meta_query'     => array( array('key' => 'alw_listing_categories', 'value' => $category_slug, 'compare' => 'LIKE') ),
				'orderby'        => 'rand', 
			);

			$listings_query = new WP_Query( $args );
			echo '<h2>' . esc_html( sprintf( 'Listings in %s', $category_name ) ) . '</h2>';

			if ( $listings_query->have_posts() ) {
				echo '<div class="alw-category-listings-container">';
				while ( $listings_query->have_posts() ) : $listings_query->the_post();
					$listing_id   = get_the_ID();
					$listing_type = get_post_meta( $listing_id, 'alw_listing_type', true ) ?: 'basic';
					$slogan = get_post_meta( $listing_id, 'alw_listing_slogan', true );
					

					$all_categories_str = get_post_meta( $listing_id, 'alw_listing_categories', true );
					$all_categories_arr = !empty($all_categories_str) ? explode(',', $all_categories_str) : array();

					$featured_image_url = get_the_post_thumbnail_url( $listing_id, 'medium_large' );
					// use 90, the ID of your placeholder.png image.
					$placeholder_image_url = wp_get_attachment_image_url( 90, 'medium_large' );

					echo '<div class="alw-listing-item alw-listing-type-' . esc_attr( $listing_type ) . '">';

					if ( $listing_type === 'premium' ) {
						// --- PREMIUM LISTING MARKUP ---
						echo '<div class="alw-listing-image-column">';
						$image_url_to_use = $featured_image_url ?: $placeholder_image_url;
						echo '<div class="alw-listing-image" style="background-image: url(' . esc_url($image_url_to_use) . ');" aria-label="' . esc_attr(get_the_title()) . '"></div>';
						echo '</div>';
						echo '<div class="alw-listing-details-column">';
						echo '<h3 class="alw-listing-title"><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h3>';
						if ( ! empty( $slogan ) ) {
							echo '<h4 class="alw-listing-slogan">' . esc_html( $slogan ) . '</h4>';
						}
						
						echo '<span class="alw-listing-category">CATEGORIES: <a href="' . esc_url( $current_page_url ) . '">' . esc_html( $category_name ) . '</a>';
						// Loop through all categories and display links for the others
						foreach ($all_categories_arr as $other_cat_slug) {
							if ($other_cat_slug !== $category_slug && isset($this->listing_categories[$other_cat_slug])) {
								// IMPORTANT: Adjust the URL structure if needed. This assumes a structure like /members/assisted-living-listings/
								$other_cat_url = home_url('/members/' . $other_cat_slug . '-listings/');
								echo ', <a href="' . esc_url($other_cat_url) . '">' . esc_html($this->listing_categories[$other_cat_slug]) . '</a>';
							}
						}
						echo '</span>';

						echo '<div class="alw-listing-description">' . wpautop( get_the_excerpt() ) . '</div>';
						echo '<a href="' . esc_url( get_permalink() ) . '" class="alw-learn-more-button">Learn More</a>';
						echo '</div>';

					} else {
						// --- BASIC LISTING MARKUP ---
						echo '<h3 class="alw-listing-title"><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h3>';
						// Basic listings only show the one category
						echo '<span class="alw-listing-category">CATEGORY: <a href="' . esc_url( $current_page_url ) . '">' . esc_html( $category_name ) . '</a></span>';
						echo '<div class="alw-listing-description">' . wpautop( get_the_excerpt() ) . '</div>';
						echo '<a href="' . esc_url( get_permalink() ) . '" class="alw-learn-more-button">Learn More</a>';
					}

					echo '</div>';
				endwhile;
				echo '</div>';
				wp_reset_postdata();
			} else {
				echo '<p>No active listings found in the "' . esc_html( $category_name ) . '" category.</p>';
			}

			return ob_get_clean();
		}

	} // End class ALW_Listings

	// Instantiate the plugin class
	new ALW_Listings();

	// Activation hook to flush rewrite rules when the plugin is activated
	register_activation_hook( __FILE__, function() {
		// Ensure custom post types and statuses are registered before flushing rules
		$temp_plugin_instance = new ALW_Listings();
		$temp_plugin_instance->register_custom_post_types();
		$temp_plugin_instance->register_custom_post_statuses();
		flush_rewrite_rules();
	} );
}