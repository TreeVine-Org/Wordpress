<?php
/**
 * Plugin Name: ALW Listings
 * Plugin URI:  https://treevine.life/
 * Description: Basic and Premium custom listing directory with Shopify payments, Google Maps, and configurable reCAPTCHA.
 * Version:     1.0.0
 * Author:      jtrevino@treevine.life
 * Author URI:  https://treevine.life
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: alw-listings
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ALW_LISTINGS_VERSION', '1.0.0' );
define( 'ALW_LISTINGS_FILE',    __FILE__ );
define( 'ALW_LISTINGS_DIR',     plugin_dir_path( __FILE__ ) );
define( 'ALW_LISTINGS_URL',     plugin_dir_url( __FILE__ ) );

require_once ALW_LISTINGS_DIR . 'includes/trait-settings.php';
require_once ALW_LISTINGS_DIR . 'includes/trait-shopify.php';
require_once ALW_LISTINGS_DIR . 'includes/trait-forms.php';
require_once ALW_LISTINGS_DIR . 'includes/trait-display.php';
require_once ALW_LISTINGS_DIR . 'includes/trait-admin.php';

if ( ! class_exists( 'ALW_Listings' ) ) :

class ALW_Listings {

	use ALW_Trait_Settings;
	use ALW_Trait_Shopify;
	use ALW_Trait_Forms;
	use ALW_Trait_Display;
	use ALW_Trait_Admin;

	/**
	 * Listing categories — slug => display name.
	 * @var array<string,string>
	 */
	private $listing_categories = [
		'assisted-living' => 'Assisted Living',
		'senior-care'     => 'Senior Care',
		'hospice-care'    => 'Hospice Care',
		'home-care'       => 'Home Care',
		'memory-care'     => 'Memory Care',
	];

	/** @var array|null Cached plugin settings from wp_options */
	private $settings_cache = null;

	// -------------------------------------------------------------------------
	// Constructor — wire up all hooks
	// -------------------------------------------------------------------------
	public function __construct() {
		// Core registration
		add_action( 'init',          [ $this, 'register_custom_post_types' ] );
		add_action( 'init',          [ $this, 'register_custom_post_statuses' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

		// Settings page
		add_action( 'admin_menu', [ $this, 'alw_add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'alw_register_settings' ] );

		// User profile
		add_action( 'show_user_profile',       [ $this, 'add_user_phone_field' ] );
		add_action( 'edit_user_profile',        [ $this, 'add_user_phone_field' ] );
		add_action( 'personal_options_update',  [ $this, 'save_user_phone_field' ] );
		add_action( 'edit_user_profile_update', [ $this, 'save_user_phone_field' ] );

		// Shortcodes
		add_shortcode( 'alw_basic_listing_form',   [ $this, 'basic_listing_form_shortcode' ] );
		add_shortcode( 'alw_premium_listing_form', [ $this, 'premium_listing_form_shortcode' ] );
		add_shortcode( 'alw_all_listings',         [ $this, 'alw_all_listings_shortcode' ] );
		add_shortcode( 'alw_my_listings',          [ $this, 'alw_my_listings_shortcode' ] );
		add_shortcode( 'alw_listings_by_category', [ $this, 'alw_listings_by_category_shortcode' ] );

		// Form submissions (logged-in and guest)
		add_action( 'admin_post_nopriv_alw_submit_basic_listing',   [ $this, 'handle_basic_listing_submission' ] );
		add_action( 'admin_post_alw_submit_basic_listing',          [ $this, 'handle_basic_listing_submission' ] );
		add_action( 'admin_post_nopriv_alw_submit_premium_listing', [ $this, 'handle_premium_listing_submission' ] );
		add_action( 'admin_post_alw_submit_premium_listing',        [ $this, 'handle_premium_listing_submission' ] );

		// Shopify return redirect
		add_action( 'template_redirect', [ $this, 'handle_shopify_return' ] );

		// Assets
		add_action( 'wp_enqueue_scripts',    [ $this, 'enqueue_plugin_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

		// Admin meta boxes
		add_action( 'add_meta_boxes_listing', [ $this, 'alw_add_custom_listing_metabox' ] );
		add_action( 'save_post_listing',       [ $this, 'alw_save_listing_meta_box_data' ] );
		add_action( 'add_meta_boxes_listing', [ $this, 'alw_add_status_control_metabox' ] );
		add_filter( 'wp_insert_post_data',    [ $this, 'alw_save_listing_status_from_metabox' ], 99, 2 );

		// Admin list view
		add_filter( 'display_post_states', [ $this, 'alw_display_listing_states' ], 10, 2 );
		add_filter( 'parse_query',          [ $this, 'alw_add_custom_post_status_to_admin_list' ] );

		// Single listing content enrichment
		add_filter( 'the_content', [ $this, 'alw_enhance_single_listing_content' ] );
	}

	// -------------------------------------------------------------------------
	// Settings helper — used by all traits
	// -------------------------------------------------------------------------

	/**
	 * Retrieve a plugin setting. Constants defined in wp-config.php override
	 * the settings page for sensitive keys (API keys, secrets).
	 */
	private function get_setting( string $key, string $default = '' ): string {
		if ( $this->settings_cache === null ) {
			$this->settings_cache = (array) get_option( 'alw_listings_settings', [] );
		}

		// wp-config constants take priority over the DB for these keys
		$const_map = [
			'recaptcha_site_key'     => 'ALW_RECAPTCHA_SITE_KEY',
			'recaptcha_secret_key'   => 'ALW_RECAPTCHA_SECRET_KEY',
			'maps_api_key'           => 'ALW_MAPS_API_KEY',
			'shopify_store_domain'   => 'ALW_SHOPIFY_STORE_DOMAIN',
			'shopify_variant_id'     => 'ALW_SHOPIFY_VARIANT_ID',
			'shopify_webhook_secret' => 'ALW_SHOPIFY_WEBHOOK_SECRET',
		];

		if ( isset( $const_map[ $key ] ) && defined( $const_map[ $key ] ) ) {
			return (string) constant( $const_map[ $key ] );
		}

		return isset( $this->settings_cache[ $key ] )
			? (string) $this->settings_cache[ $key ]
			: $default;
	}

	// -------------------------------------------------------------------------
	// Custom Post Type: listing
	// -------------------------------------------------------------------------
	public function register_custom_post_types() {
		$labels = [
			'name'               => _x( 'Listings', 'Post Type General Name', 'alw-listings' ),
			'singular_name'      => _x( 'Listing',  'Post Type Singular Name', 'alw-listings' ),
			'menu_name'          => __( 'Listings', 'alw-listings' ),
			'all_items'          => __( 'All Listings', 'alw-listings' ),
			'add_new_item'       => __( 'Add New Listing', 'alw-listings' ),
			'add_new'            => __( 'Add New', 'alw-listings' ),
			'edit_item'          => __( 'Edit Listing', 'alw-listings' ),
			'new_item'           => __( 'New Listing', 'alw-listings' ),
			'view_item'          => __( 'View Listing', 'alw-listings' ),
			'search_items'       => __( 'Search Listings', 'alw-listings' ),
			'not_found'          => __( 'No listings found', 'alw-listings' ),
			'not_found_in_trash' => __( 'No listings found in Trash', 'alw-listings' ),
		];

		register_post_type( 'listing', [
			'label'               => __( 'Listing', 'alw-listings' ),
			'labels'              => $labels,
			'supports'            => [ 'title', 'editor', 'author', 'thumbnail', 'custom-fields' ],
			'hierarchical'        => false,
			'public'              => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_position'       => 5,
			'menu_icon'           => 'dashicons-store',
			'show_in_admin_bar'   => true,
			'show_in_nav_menus'   => true,
			'can_export'          => true,
			'has_archive'         => true,
			'exclude_from_search' => false,
			'publicly_queryable'  => true,
			'capability_type'     => 'post',
			'rewrite'             => [ 'slug' => 'listing', 'with_front' => false ],
			'show_in_rest'        => true,
		] );
	}

	// -------------------------------------------------------------------------
	// Custom Post Statuses
	// -------------------------------------------------------------------------
	public function register_custom_post_statuses() {
		register_post_status( 'alw_active', [
			'label'                     => _x( 'Active', 'post status', 'alw-listings' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Active <span class="count">(%s)</span>', 'Active <span class="count">(%s)</span>', 'alw-listings' ),
			'show_in_rest'              => true,
		] );

		register_post_status( 'alw_inactive', [
			'label'                     => _x( 'Inactive', 'post status', 'alw-listings' ),
			'public'                    => false,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Inactive <span class="count">(%s)</span>', 'Inactive <span class="count">(%s)</span>', 'alw-listings' ),
			'show_in_rest'              => true,
		] );

		// New: listings awaiting Shopify payment confirmation
		register_post_status( 'alw_pending_payment', [
			'label'                     => _x( 'Pending Payment', 'post status', 'alw-listings' ),
			'public'                    => false,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Pending Payment <span class="count">(%s)</span>', 'Pending Payment <span class="count">(%s)</span>', 'alw-listings' ),
		] );
	}
}

new ALW_Listings();

register_activation_hook( __FILE__, function () {
	$instance = new ALW_Listings();
	$instance->register_custom_post_types();
	$instance->register_custom_post_statuses();
	flush_rewrite_rules();
} );

endif; // class_exists ALW_Listings
