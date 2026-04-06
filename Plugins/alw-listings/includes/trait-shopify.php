<?php
/**
 * ALW Listings — Shopify Trait
 *
 * Handles the full Shopify payment flow for premium listings:
 *   1. REST endpoint receives Shopify "order payment" webhook.
 *   2. Verifies HMAC signature, finds the listing by order line-item property.
 *   3. Promotes the listing from alw_pending_payment → pending (admin review).
 *   4. Sends admin + user confirmation emails.
 *
 * Payment step rendering (shown after form submission, before Shopify redirect)
 * lives here too.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait ALW_Trait_Shopify {

	// -------------------------------------------------------------------------
	// REST route registration
	// -------------------------------------------------------------------------
	public function register_rest_routes() {
		register_rest_route( 'alw-listings/v1', '/shopify-webhook', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_shopify_webhook' ],
			'permission_callback' => '__return_true', // HMAC checked inside
		] );
	}

	// -------------------------------------------------------------------------
	// Shopify webhook handler
	// -------------------------------------------------------------------------

	/**
	 * Receives Shopify "Order payment" webhooks.
	 * Verifies HMAC, finds listing by line-item property "listing_id",
	 * promotes the listing to "pending" for admin review.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_shopify_webhook( $request ) {
		$raw_body       = $request->get_body();
		$hmac_header    = $request->get_header( 'x_shopify_hmac_sha256' );
		$webhook_secret = $this->get_setting( 'shopify_webhook_secret' );

		// Verify HMAC when a secret is configured
		if ( ! empty( $webhook_secret ) ) {
			$computed = base64_encode( hash_hmac( 'sha256', $raw_body, $webhook_secret, true ) );
			if ( ! hash_equals( $computed, (string) $hmac_header ) ) {
				return new WP_REST_Response( 'Unauthorized', 401 );
			}
		}

		$order = json_decode( $raw_body, true );
		if ( ! is_array( $order ) ) {
			return new WP_REST_Response( 'Bad Request', 400 );
		}

		$listing_id = $this->_extract_listing_id_from_order( $order );
		if ( ! $listing_id ) {
			// Not an ALW order — acknowledge and move on
			return new WP_REST_Response( 'OK', 200 );
		}

		$listing = get_post( $listing_id );
		if ( ! $listing
			|| $listing->post_type   !== 'listing'
			|| $listing->post_status !== 'alw_pending_payment'
		) {
			return new WP_REST_Response( 'OK', 200 );
		}

		// Promote listing to pending admin review
		wp_update_post( [ 'ID' => $listing_id, 'post_status' => 'pending' ] );
		update_post_meta( $listing_id, 'alw_shopify_order_id',     sanitize_text_field( $order['id'] ?? '' ) );
		update_post_meta( $listing_id, 'alw_shopify_order_number', sanitize_text_field( $order['order_number'] ?? '' ) );

		$this->_notify_payment_confirmed( $listing );

		return new WP_REST_Response( 'OK', 200 );
	}

	/**
	 * Extracts the ALW listing ID from a Shopify order payload.
	 * Checks line-item properties first (most reliable), then falls back to order note.
	 *
	 * @param array $order Decoded Shopify order object.
	 * @return int Listing post ID, or 0 if not found.
	 */
	private function _extract_listing_id_from_order( array $order ): int {
		// Primary: line-item property "listing_id" = "alw-{id}"
		foreach ( $order['line_items'] ?? [] as $item ) {
			foreach ( $item['properties'] ?? [] as $prop ) {
				if ( ( $prop['name'] ?? '' ) === 'listing_id'
					&& strpos( $prop['value'] ?? '', 'alw-' ) === 0
				) {
					return absint( str_replace( 'alw-', '', $prop['value'] ) );
				}
			}
		}

		// Fallback: order note "alw-{id}"
		$note = $order['note'] ?? '';
		if ( strpos( $note, 'alw-' ) === 0 ) {
			return absint( str_replace( 'alw-', '', trim( $note ) ) );
		}

		return 0;
	}

	/**
	 * Sends admin and user notifications when payment is confirmed.
	 *
	 * @param WP_Post $listing
	 */
	private function _notify_payment_confirmed( WP_Post $listing ) {
		$admin_email = get_option( 'admin_email' );
		$edit_link   = admin_url( 'post.php?post=' . $listing->ID . '&action=edit' );

		// Admin
		wp_mail(
			$admin_email,
			/* translators: %s: listing title */
			sprintf( __( 'New Premium Listing Payment Confirmed: %s', 'alw-listings' ), $listing->post_title ),
			sprintf(
				__( "Payment confirmed for the premium listing \"%s\".\n\nReview and activate it here:\n%s", 'alw-listings' ),
				$listing->post_title,
				$edit_link
			)
		);

		// Listing author
		$author = get_userdata( $listing->post_author );
		if ( $author && $author->user_email ) {
			wp_mail(
				$author->user_email,
				__( 'Payment Confirmed — Your Listing Is Under Review', 'alw-listings' ),
				sprintf(
					/* translators: 1: first name  2: listing title */
					__( "Hi %1\$s,\n\nYour payment for the premium listing \"%2\$s\" has been confirmed.\nYour listing is now under review and will be published shortly.\n\nThank you!", 'alw-listings' ),
					$author->first_name ?: $author->display_name,
					$listing->post_title
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// Shopify return-URL handler
	// -------------------------------------------------------------------------

	/**
	 * Fires on template_redirect.
	 * When Shopify sends the customer back with ?alw_listing_id=X, redirect
	 * them to a friendly confirmation page if one is configured.
	 */
	public function handle_shopify_return() {
		if ( empty( $_GET['alw_listing_id'] ) ) return;

		$listing_id = absint( $_GET['alw_listing_id'] );
		if ( ! $listing_id ) return;

		$listing = get_post( $listing_id );
		if ( ! $listing || $listing->post_type !== 'listing' ) return;

		// Only the post author should trigger this
		if ( is_user_logged_in() && get_current_user_id() !== (int) $listing->post_author ) return;

		// If Shopify already fired the webhook, listing is "pending" — show success
		$status = $listing->post_status;
		$redirect_url = add_query_arg( [
			'alw_payment_return' => '1',
			'listing_id'         => $listing_id,
			'status'             => $status,
		], get_permalink( $listing_id ) ?: home_url() );

		wp_safe_redirect( $redirect_url );
		exit;
	}

	// -------------------------------------------------------------------------
	// Payment step — rendered after form submit, before Shopify redirect
	// -------------------------------------------------------------------------

	/**
	 * Renders the "Proceed to payment" panel shown after a premium listing
	 * has been saved with alw_pending_payment status.
	 * If Shopify is not configured, immediately promotes the listing to pending.
	 *
	 * @param int $listing_id
	 */
	private function render_payment_step( int $listing_id ) {
		$listing = get_post( $listing_id );

		if ( ! $listing || $listing->post_type !== 'listing' ) {
			echo '<p class="alw-alert alw-alert-danger">' . esc_html__( 'Listing not found.', 'alw-listings' ) . '</p>';
			return;
		}

		// Already paid / submitted
		if ( $listing->post_status !== 'alw_pending_payment' ) {
			echo '<div class="alw-alert alw-alert-success">' . esc_html__( 'Your listing has already been submitted. Thank you!', 'alw-listings' ) . '</div>';
			return;
		}

		$store_domain = $this->get_setting( 'shopify_store_domain' );
		$variant_id   = $this->get_setting( 'shopify_variant_id' );
		$price        = $this->get_setting( 'premium_listing_price', '99.00' );

		// Shopify not configured — skip payment, promote to pending
		if ( empty( $store_domain ) || empty( $variant_id ) ) {
			wp_update_post( [ 'ID' => $listing_id, 'post_status' => 'pending' ] );
			$this->_send_basic_submission_emails( $listing );
			echo '<div class="alw-alert alw-alert-success">' .
				esc_html__( 'Your listing has been submitted and is pending review. We will notify you once it\'s live.', 'alw-listings' ) .
			'</div>';
			return;
		}

		// Build Shopify cart URL with line-item property so the webhook can
		// trace the order back to this listing
		$shopify_url = add_query_arg(
			[ 'properties[listing_id]' => 'alw-' . $listing_id ],
			'https://' . $store_domain . '/cart/' . $variant_id . ':1'
		);

		?>
		<div class="alw-payment-step container">
			<div class="alw-alert alw-alert-success">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: listing title */
						__( 'Your listing "%s" has been saved. Complete your payment below to publish it.', 'alw-listings' ),
						$listing->post_title
					)
				);
				?>
			</div>

			<div class="group" style="text-align:center;padding:30px;">
				<h2><?php esc_html_e( 'Complete Your Premium Listing', 'alw-listings' ); ?></h2>
				<p class="alw-price" style="font-size:1.4em;margin:20px 0;">
					<strong><?php esc_html_e( 'Premium Listing Fee:', 'alw-listings' ); ?>
					$<?php echo esc_html( $price ); ?></strong>
				</p>
				<a href="<?php echo esc_url( $shopify_url ); ?>" class="alw-learn-more-button">
					<?php esc_html_e( 'Pay Now via Shopify', 'alw-listings' ); ?>
				</a>
				<p class="description" style="margin-top:20px;color:#666;">
					<?php esc_html_e( 'You will be redirected to our secure Shopify checkout. Your listing will be reviewed and activated after payment is confirmed.', 'alw-listings' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Send admin + user submission emails for listings submitted without payment.
	 *
	 * @param WP_Post $listing
	 */
	private function _send_basic_submission_emails( WP_Post $listing ) {
		$admin_email = get_option( 'admin_email' );
		$type_label  = get_post_meta( $listing->ID, 'alw_listing_type', true ) === 'premium' ? 'Premium' : 'Basic';

		wp_mail(
			$admin_email,
			/* translators: 1: type (Basic/Premium)  2: listing title */
			sprintf( __( 'New %1$s Listing Submitted: %2$s', 'alw-listings' ), $type_label, $listing->post_title ),
			sprintf(
				__( "A new listing requires your review.\n\nType: %1\$s\nBusiness: %2\$s\n\nReview at:\n%3\$s", 'alw-listings' ),
				$type_label,
				$listing->post_title,
				admin_url( 'post.php?post=' . $listing->ID . '&action=edit' )
			)
		);

		$author = get_userdata( $listing->post_author );
		if ( $author && $author->user_email ) {
			wp_mail(
				$author->user_email,
				__( 'Your Listing Has Been Submitted', 'alw-listings' ),
				sprintf(
					/* translators: 1: first name  2: listing title */
					__( "Hi %1\$s,\n\nThank you for submitting your listing \"%2\$s\".\nWe will review it and notify you once it's live.\n\nThanks!", 'alw-listings' ),
					$author->first_name ?: $author->display_name,
					$listing->post_title
				)
			);
		}
	}
}
