<?php
/**
 * ALW Listings — Settings Trait
 *
 * Registers the WP admin settings page under Settings → ALW Listings.
 * Sensitive keys (reCAPTCHA, Maps, Shopify) can be overridden via
 * wp-config.php constants; those fields are locked in the UI.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait ALW_Trait_Settings {

	// -------------------------------------------------------------------------
	// Admin menu
	// -------------------------------------------------------------------------
	public function alw_add_settings_page() {
		add_options_page(
			__( 'ALW Listings Settings', 'alw-listings' ),
			__( 'ALW Listings', 'alw-listings' ),
			'manage_options',
			'alw-listings-settings',
			[ $this, 'alw_render_settings_page' ]
		);
	}

	// -------------------------------------------------------------------------
	// Register settings, sections, and fields
	// -------------------------------------------------------------------------
	public function alw_register_settings() {
		register_setting( 'alw_listings_settings_group', 'alw_listings_settings', [
			'sanitize_callback' => [ $this, 'alw_sanitize_settings' ],
		] );

		// --- reCAPTCHA ---
		add_settings_section(
			'alw_recaptcha',
			__( 'reCAPTCHA', 'alw-listings' ),
			function () {
				echo '<p>' . wp_kses(
					__( 'Google reCAPTCHA v2 ("I\'m not a robot") keys. Get yours at <a href="https://www.google.com/recaptcha/admin" target="_blank">google.com/recaptcha/admin</a>. If either key is missing, reCAPTCHA is silently disabled on forms.', 'alw-listings' ),
					[ 'a' => [ 'href' => [], 'target' => [] ] ]
				) . '</p>';
				echo '<p class="description">' . wp_kses( __( 'You can also define <code>ALW_RECAPTCHA_SITE_KEY</code> and <code>ALW_RECAPTCHA_SECRET_KEY</code> in <code>wp-config.php</code> to keep keys out of the database.', 'alw-listings' ), [ 'code' => [] ] ) . '</p>';
			},
			'alw-listings-settings'
		);
		$this->_alw_text_field( 'recaptcha_site_key',   __( 'Site Key',   'alw-listings' ), 'alw_recaptcha' );
		$this->_alw_text_field( 'recaptcha_secret_key', __( 'Secret Key', 'alw-listings' ), 'alw_recaptcha', true );

		// --- Google Maps ---
		add_settings_section(
			'alw_maps',
			__( 'Google Maps', 'alw-listings' ),
			function () {
				echo '<p>' . wp_kses(
					__( 'A Google Maps API key with the <strong>Maps Embed API</strong> enabled. Used to display an interactive location map on premium listing pages only. Leave blank to show a text address instead.', 'alw-listings' ),
					[ 'strong' => [] ]
				) . '</p>';
				echo '<p class="description">' . wp_kses( __( 'Can also be set via <code>ALW_MAPS_API_KEY</code> in <code>wp-config.php</code>.', 'alw-listings' ), [ 'code' => [] ] ) . '</p>';
			},
			'alw-listings-settings'
		);
		$this->_alw_text_field( 'maps_api_key', __( 'Maps API Key', 'alw-listings' ), 'alw_maps', true );

		// --- Shopify ---
		add_settings_section(
			'alw_shopify',
			__( 'Shopify Payments (Premium Listings)', 'alw-listings' ),
			function () {
				$webhook_url = rest_url( 'alw-listings/v1/shopify-webhook' );
				echo '<p>' . esc_html__( 'When a store domain and variant ID are configured, submitting a premium listing redirects users to Shopify checkout. After payment is confirmed the listing is queued for admin review. If left blank, premium listings are submitted directly (no payment required — useful for development).', 'alw-listings' ) . '</p>';
				echo '<table class="alw-settings-info" style="border-collapse:collapse;margin-bottom:10px;">';
				echo '<tr><th style="text-align:left;padding:4px 10px 4px 0;">' . esc_html__( 'Webhook URL', 'alw-listings' ) . '</th><td><code>' . esc_url( $webhook_url ) . '</code></td></tr>';
				echo '</table>';
				echo '<p class="description">' . esc_html__( 'In Shopify Admin → Settings → Notifications → Webhooks, add the Webhook URL above for the "Order payment" event.', 'alw-listings' ) . '</p>';
			},
			'alw-listings-settings'
		);
		$this->_alw_text_field( 'shopify_store_domain',   __( 'Store Domain (e.g. mystore.myshopify.com)', 'alw-listings' ), 'alw_shopify', false, 'mystore.myshopify.com' );
		$this->_alw_text_field( 'shopify_variant_id',     __( 'Premium Listing Product Variant ID',        'alw-listings' ), 'alw_shopify', false, '1234567890' );
		$this->_alw_text_field( 'shopify_webhook_secret', __( 'Webhook Signing Secret',                    'alw-listings' ), 'alw_shopify', true );
		$this->_alw_text_field( 'premium_listing_price',  __( 'Display Price (shown on payment step)',     'alw-listings' ), 'alw_shopify', false, '99.00' );

		// --- Listing Options ---
		add_settings_section(
			'alw_listing_options',
			__( 'Listing Options', 'alw-listings' ),
			null,
			'alw-listings-settings'
		);
		$this->_alw_text_field( 'terms_page_slug',        __( 'Terms & Conditions Page Slug',                                    'alw-listings' ), 'alw_listing_options', false, 'terms-and-conditions' );
		$this->_alw_text_field( 'category_listings_base', __( 'Category Listings URL Base (e.g. /members/ → /members/home-care-listings/)', 'alw-listings' ), 'alw_listing_options', false, '/members/' );
		$this->_alw_text_field( 'placeholder_image_url',  __( 'Placeholder Image URL (for premium listings with no featured image)', 'alw-listings' ), 'alw_listing_options' );
	}

	/**
	 * Helper: register a single text settings field.
	 */
	private function _alw_text_field( string $key, string $label, string $section, bool $is_password = false, string $placeholder = '' ) {
		add_settings_field(
			'alw_field_' . $key,
			$label,
			function () use ( $key, $is_password, $placeholder ) {
				$settings = (array) get_option( 'alw_listings_settings', [] );
				$val      = $settings[ $key ] ?? '';
				$type     = $is_password ? 'password' : 'text';

				$const_map = [
					'recaptcha_site_key'     => 'ALW_RECAPTCHA_SITE_KEY',
					'recaptcha_secret_key'   => 'ALW_RECAPTCHA_SECRET_KEY',
					'maps_api_key'           => 'ALW_MAPS_API_KEY',
					'shopify_store_domain'   => 'ALW_SHOPIFY_STORE_DOMAIN',
					'shopify_variant_id'     => 'ALW_SHOPIFY_VARIANT_ID',
					'shopify_webhook_secret' => 'ALW_SHOPIFY_WEBHOOK_SECRET',
				];

				if ( isset( $const_map[ $key ] ) && defined( $const_map[ $key ] ) ) {
					echo '<input type="' . esc_attr( $type ) . '" value="*** set in wp-config.php ***" class="regular-text" disabled>';
					echo '<input type="hidden" name="alw_listings_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $val ) . '">';
					echo '<p class="description">' . esc_html__( 'Override active via wp-config.php constant. Edit wp-config.php to change this value.', 'alw-listings' ) . '</p>';
				} else {
					$ph = $placeholder ? ' placeholder="' . esc_attr( $placeholder ) . '"' : '';
					echo '<input type="' . esc_attr( $type ) . '" name="alw_listings_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $val ) . '" class="regular-text"' . $ph . '>';
				}
			},
			'alw-listings-settings',
			$section
		);
	}

	// -------------------------------------------------------------------------
	// Sanitize on save
	// -------------------------------------------------------------------------
	public function alw_sanitize_settings( $input ): array {
		$sanitized   = [];
		$text_fields = [
			'recaptcha_site_key', 'recaptcha_secret_key',
			'maps_api_key',
			'shopify_store_domain', 'shopify_variant_id', 'shopify_webhook_secret',
			'premium_listing_price',
			'terms_page_slug', 'category_listings_base',
		];
		foreach ( $text_fields as $f ) {
			$sanitized[ $f ] = sanitize_text_field( $input[ $f ] ?? '' );
		}
		$sanitized['placeholder_image_url'] = esc_url_raw( $input['placeholder_image_url'] ?? '' );
		return $sanitized;
	}

	// -------------------------------------------------------------------------
	// Render settings page
	// -------------------------------------------------------------------------
	public function alw_render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ALW Listings Settings', 'alw-listings' ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'alw_listings_settings_group' );
				do_settings_sections( 'alw-listings-settings' );
				submit_button();
				?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Shopify Setup Checklist', 'alw-listings' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Create a product in Shopify for "Premium Listing" at your desired price.', 'alw-listings' ); ?></li>
				<li><?php esc_html_e( 'Find the Variant ID: open the product in Shopify Admin, click on the variant, and copy the number at the end of the URL.', 'alw-listings' ); ?></li>
				<li><?php echo wp_kses( __( 'In Shopify Admin → Settings → Notifications → Webhooks, add a webhook for the <strong>Order payment</strong> event pointing to your Webhook URL shown above.', 'alw-listings' ), [ 'strong' => [] ] ); ?></li>
				<li><?php esc_html_e( 'Copy the webhook signing secret Shopify shows you and paste it into the field above.', 'alw-listings' ); ?></li>
			</ol>

			<hr>
			<h2><?php esc_html_e( 'wp-config.php Constants (Optional)', 'alw-listings' ); ?></h2>
			<p><?php esc_html_e( 'Define any of these in wp-config.php to keep sensitive keys out of the database. They override the values above.', 'alw-listings' ); ?></p>
			<pre style="background:#f1f1f1;padding:15px;border-radius:4px;overflow:auto;">define( 'ALW_RECAPTCHA_SITE_KEY',     'your_recaptcha_site_key' );
define( 'ALW_RECAPTCHA_SECRET_KEY',   'your_recaptcha_secret_key' );
define( 'ALW_MAPS_API_KEY',           'your_google_maps_api_key' );
define( 'ALW_SHOPIFY_STORE_DOMAIN',   'mystore.myshopify.com' );
define( 'ALW_SHOPIFY_VARIANT_ID',     '1234567890' );
define( 'ALW_SHOPIFY_WEBHOOK_SECRET', 'your_webhook_signing_secret' );</pre>
		</div>
		<?php
	}
}
