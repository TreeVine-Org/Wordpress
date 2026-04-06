<?php
/**
 * ALW Listings — Forms Trait
 *
 * Handles:
 *  - Asset enqueueing for form pages
 *  - Rendering the basic and premium submission forms
 *  - Processing form submissions (validation, user creation, post creation)
 *  - Routing premium submissions through the Shopify payment step when configured
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait ALW_Trait_Forms {

	// -------------------------------------------------------------------------
	// Asset enqueueing
	// -------------------------------------------------------------------------
	public function enqueue_plugin_assets() {
		$post = get_post();
		if ( ! is_a( $post, 'WP_Post' ) ) return;

		$content = $post->post_content;

		$has_any_shortcode = (
			has_shortcode( $content, 'alw_basic_listing_form' )   ||
			has_shortcode( $content, 'alw_premium_listing_form' ) ||
			has_shortcode( $content, 'alw_listings_by_category' ) ||
			has_shortcode( $content, 'alw_all_listings' )         ||
			has_shortcode( $content, 'alw_my_listings' )
		);

		$has_form_shortcode = (
			has_shortcode( $content, 'alw_basic_listing_form' ) ||
			has_shortcode( $content, 'alw_premium_listing_form' )
		);

		if ( $has_any_shortcode || is_singular( 'listing' ) ) {
			wp_enqueue_style(
				'alw-listing-styles',
				ALW_LISTINGS_URL . 'assets/css/alw-form-styles.css',
				[],
				ALW_LISTINGS_VERSION
			);
		}

		if ( $has_form_shortcode ) {
			// reCAPTCHA — only enqueue if keys are configured
			$site_key = $this->get_setting( 'recaptcha_site_key' );
			if ( $site_key ) {
				wp_enqueue_script( 'google-recaptcha', 'https://www.google.com/recaptcha/api.js', [], null, true );
			}

			// Form scripts (password visibility toggle)
			wp_enqueue_script(
				'alw-form-scripts',
				ALW_LISTINGS_URL . 'assets/js/alw-form-scripts.js',
				[],
				ALW_LISTINGS_VERSION,
				true
			);

			// Premium media uploader
			wp_enqueue_media();
			wp_enqueue_script(
				'alw-premium-scripts',
				ALW_LISTINGS_URL . 'assets/js/alw-premium-scripts.js',
				[ 'jquery' ],
				ALW_LISTINGS_VERSION,
				true
			);
		}
	}

	// -------------------------------------------------------------------------
	// Shortcode entry points
	// -------------------------------------------------------------------------
	public function basic_listing_form_shortcode(): string {
		ob_start();
		$this->render_form_template( 'basic' );
		return ob_get_clean();
	}

	public function premium_listing_form_shortcode(): string {
		ob_start();
		// If returning from a successful validation redirect, show payment step
		if ( isset( $_GET['alw_proceed_payment'] ) ) {
			$this->render_payment_step( absint( $_GET['alw_proceed_payment'] ) );
		} elseif ( isset( $_GET['alw_payment_return'] ) ) {
			$status = sanitize_key( $_GET['status'] ?? '' );
			if ( $status === 'pending' || $status === 'alw_active' ) {
				echo '<div class="alw-alert alw-alert-success">' . esc_html__( 'Payment confirmed! Your listing is under review and will be published shortly.', 'alw-listings' ) . '</div>';
			} else {
				echo '<div class="alw-alert alw-alert-danger">' . esc_html__( 'We received your payment but could not confirm your listing status. Please contact us.', 'alw-listings' ) . '</div>';
			}
		} else {
			$this->render_form_template( 'premium' );
		}
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Form HTML renderer
	// -------------------------------------------------------------------------
	private function render_form_template( string $type ) {
		$current_user = wp_get_current_user();
		$is_logged_in = is_user_logged_in();
		$site_key     = $this->get_setting( 'recaptcha_site_key' );
		$terms_slug   = $this->get_setting( 'terms_page_slug', 'terms-and-conditions' );

		// Success / error messages from redirects
		if ( isset( $_GET['submission_success'] ) ) {
			echo '<div class="alw-alert alw-alert-success">' . esc_html__( 'Thanks for submitting your listing! We will review it and notify you once it\'s live.', 'alw-listings' ) . '</div>';
		}
		if ( isset( $_GET['submission_errors'] ) ) {
			$errors = json_decode( base64_decode( sanitize_text_field( $_GET['submission_errors'] ) ), true );
			if ( is_array( $errors ) && ! empty( $errors ) ) {
				echo '<div class="alw-alert alw-alert-danger"><ul>';
				foreach ( $errors as $error ) {
					echo '<li>' . esc_html( $error ) . '</li>';
				}
				echo '</ul></div>';
			}
		}

		// Pre-populate fields
		$fields = [ 'email', 'first_name', 'last_name', 'phone', 'company', 'slogan', 'description', 'address1', 'address2', 'zipcode', 'city', 'state', 'website' ];
		$data   = [];
		foreach ( $fields as $field ) {
			$user_val = '';
			if ( $is_logged_in ) {
				switch ( $field ) {
					case 'email':      $user_val = $current_user->user_email; break;
					case 'first_name': $user_val = $current_user->first_name; break;
					case 'last_name':  $user_val = $current_user->last_name;  break;
					case 'phone':      $user_val = get_user_meta( $current_user->ID, 'alw_phone', true ); break;
				}
			}
			$data[ $field ] = $_POST[ $field ] ?? $user_val;
		}
		$data['agree_checked'] = ( isset( $_POST['agree'] ) && $_POST['agree'] === '1' ) ? 'checked' : '';
		if ( $type === 'basic' ) {
			$data['category']   = $_POST['category']    ?? '';
		} else {
			$data['categories'] = $_POST['categories']  ?? [];
		}

		$form_id       = $type . '-listing-form';
		$nonce_action  = 'alw_' . $type . '_listing_nonce';
		$submit_action = 'alw_submit_' . $type . '_listing';
		$submit_text   = ( $type === 'premium' ) ? __( 'Save & Proceed to Payment', 'alw-listings' ) : __( 'Submit Basic Listing', 'alw-listings' );
		?>
		<div class="container">
		<form name="<?php echo esc_attr( $form_id ); ?>" id="<?php echo esc_attr( $form_id ); ?>"
			  action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			  method="post" autocomplete="off" enctype="multipart/form-data">

			<?php wp_nonce_field( $nonce_action, 'alw_listing_form_nonce' ); ?>
			<input type="hidden" name="action"      value="<?php echo esc_attr( $submit_action ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( get_the_permalink() ); ?>">

			<div class="row">
				<!-- Account Details -->
				<div class="col-lg-5 col-md-5 col-sm-12 col-12 group">
					<h3 class="form-heading"><?php esc_html_e( 'Account Details', 'alw-listings' ); ?></h3>

					<?php if ( ! $is_logged_in ) : ?>
						<div class="form-group has-feedback">
							<label class="control-label" for="email"><?php esc_html_e( 'Email', 'alw-listings' ); ?> <span class="red-asterisk">*</span></label>
							<input type="email" name="email" id="email" value="<?php echo esc_attr( $data['email'] ); ?>" class="form-control" required placeholder="<?php esc_attr_e( 'Enter Email Address Here', 'alw-listings' ); ?>">
							<i class="input-add-on fa-solid fa-envelope"></i>
						</div>
						<div class="form-group has-feedback">
							<label class="control-label" for="password"><?php esc_html_e( 'Password', 'alw-listings' ); ?> <span class="red-asterisk">*</span></label>
							<input type="password" name="password" id="password" class="password form-control" required placeholder="<?php esc_attr_e( 'Enter Password Here', 'alw-listings' ); ?>">
							<i class="input-add-on fa-solid fa-eye"></i>
						</div>
						<div class="form-group has-feedback">
							<label class="control-label" for="password2"><?php esc_html_e( 'Confirm Password', 'alw-listings' ); ?> <span class="red-asterisk">*</span></label>
							<input type="password" name="password2" id="password2" class="password form-control" required placeholder="<?php esc_attr_e( 'Confirm Password Here', 'alw-listings' ); ?>">
							<i class="input-add-on fa-solid fa-eye"></i>
						</div>
					<?php else : ?>
						<p><?php esc_html_e( 'Logged in as:', 'alw-listings' ); ?> <strong><?php echo esc_html( $current_user->display_name ); ?> (<?php echo esc_html( $current_user->user_email ); ?>)</strong></p>
						<input type="hidden" name="email" value="<?php echo esc_attr( $data['email'] ); ?>">
					<?php endif; ?>

					<div class="form-group has-feedback">
						<label class="control-label" for="first_name"><?php esc_html_e( 'Your First Name', 'alw-listings' ); ?> <span class="red-asterisk">*</span></label>
						<input type="text" name="first_name" id="first_name" value="<?php echo esc_attr( $data['first_name'] ); ?>" class="form-control" required placeholder="<?php esc_attr_e( 'First Name', 'alw-listings' ); ?>">
						<i class="input-add-on fa-solid fa-user"></i>
					</div>
					<div class="form-group has-feedback">
						<label class="control-label" for="last_name"><?php esc_html_e( 'Your Last Name', 'alw-listings' ); ?> <span class="red-asterisk">*</span></label>
						<input type="text" name="last_name" id="last_name" value="<?php echo esc_attr( $data['last_name'] ); ?>" class="form-control" required placeholder="<?php esc_attr_e( 'Last Name', 'alw-listings' ); ?>">
						<i class="input-add-on fa-solid fa-user"></i>
					</div>
					<div class="form-group has-feedback">
						<label class="control-label" for="phone"><?php esc_html_e( 'Contact Phone #', 'alw-listings' ); ?> <span class="red-asterisk">*</span></label>
						<input type="tel" name="phone" id="phone" value="<?php echo esc_attr( $data['phone'] ); ?>" class="form-control" required placeholder="<?php esc_attr_e( '(555) 555-5555', 'alw-listings' ); ?>">
						<i class="input-add-on fa-solid fa-phone"></i>
					</div>
				</div>

				<div class="col-lg-1 col-md-1 col-sm-0 col-12">&nbsp;</div>

				<!-- Listing Details -->
				<div class="col-lg-5 col-md-5 col-sm-12 col-12 group">
					<h3 class="form-heading"><?php esc_html_e( 'For Listing', 'alw-listings' ); ?></h3>

					<div class="form-group has-feedback">
						<label class="control-label" for="company"><?php esc_html_e( 'Business Name', 'alw-listings' ); ?> <span class="red-asterisk">*</span></label>
						<input type="text" name="company" id="company" value="<?php echo esc_attr( $data['company'] ); ?>" class="form-control" required placeholder="<?php esc_attr_e( 'Enter Business Name Here', 'alw-listings' ); ?>">
						<i class="input-add-on fa-solid fa-building"></i>
					</div>

					<?php if ( $type === 'premium' ) : ?>
					<div class="form-group has-feedback">
						<label class="control-label" for="slogan"><?php esc_html_e( 'Slogan / Tagline', 'alw-listings' ); ?></label>
						<input type="text" name="slogan" id="slogan" value="<?php echo esc_attr( $data['slogan'] ); ?>" class="form-control" placeholder="<?php esc_attr_e( 'A short tagline for your listing', 'alw-listings' ); ?>">
						<i class="input-add-on fa-solid fa-quote-left"></i>
					</div>
					<?php endif; ?>

					<div class="form-group has-feedback">
						<label class="control-label" for="description"><?php esc_html_e( 'Business Description', 'alw-listings' ); ?> <span class="red-asterisk">*</span></label>
						<textarea name="description" id="description" class="form-control" required rows="4" placeholder="<?php esc_attr_e( 'Short description of your business', 'alw-listings' ); ?>"><?php echo esc_textarea( $data['description'] ); ?></textarea>
					</div>
					<div class="form-group has-feedback">
						<label class="control-label" for="address1"><?php esc_html_e( 'Address 1', 'alw-listings' ); ?> <span class="red-asterisk">*</span></label>
						<input type="text" name="address1" id="address1" value="<?php echo esc_attr( $data['address1'] ); ?>" class="form-control" required placeholder="<?php esc_attr_e( 'Street Address', 'alw-listings' ); ?>">
						<i class="input-add-on fa-solid fa-address-card"></i>
					</div>
					<div class="form-group has-feedback">
						<label class="control-label" for="address2"><?php esc_html_e( 'Address 2', 'alw-listings' ); ?></label>
						<input type="text" name="address2" id="address2" value="<?php echo esc_attr( $data['address2'] ); ?>" class="form-control" placeholder="<?php esc_attr_e( 'Suite, Unit, etc.', 'alw-listings' ); ?>">
						<i class="input-add-on fa-solid fa-address-card"></i>
					</div>
					<div class="form-group has-feedback">
						<label class="control-label" for="city"><?php esc_html_e( 'City', 'alw-listings' ); ?> <span class="red-asterisk">*</span></label>
						<input type="text" name="city" id="city" value="<?php echo esc_attr( $data['city'] ); ?>" class="form-control" required placeholder="<?php esc_attr_e( 'City', 'alw-listings' ); ?>">
						<i class="input-add-on fa-solid fa-location-dot"></i>
					</div>
					<div class="form-group has-feedback">
						<label class="control-label" for="state"><?php esc_html_e( 'State', 'alw-listings' ); ?> <span class="red-asterisk">*</span></label>
						<input type="text" name="state" id="state" value="<?php echo esc_attr( $data['state'] ); ?>" class="form-control" required placeholder="<?php esc_attr_e( 'State', 'alw-listings' ); ?>">
						<i class="input-add-on fa-solid fa-location-dot"></i>
					</div>
					<div class="form-group has-feedback">
						<label class="control-label" for="zipcode"><?php esc_html_e( 'Zipcode', 'alw-listings' ); ?> <span class="red-asterisk">*</span></label>
						<input type="text" name="zipcode" id="zipcode" value="<?php echo esc_attr( $data['zipcode'] ); ?>" class="form-control" required placeholder="<?php esc_attr_e( '5-digit zipcode', 'alw-listings' ); ?>">
						<i class="input-add-on fa-solid fa-location-dot"></i>
					</div>
					<div class="form-group has-feedback">
						<label class="control-label" for="website"><?php esc_html_e( 'Website', 'alw-listings' ); ?></label>
						<input type="url" name="website" id="website" value="<?php echo esc_attr( $data['website'] ); ?>" class="form-control" placeholder="<?php esc_attr_e( 'https://yourwebsite.com', 'alw-listings' ); ?>">
						<i class="input-add-on fa-solid fa-globe"></i>
					</div>
				</div>
			</div><!-- /.row -->

			<?php if ( $type === 'premium' ) : ?>
			<hr style="margin:30px 0;">
			<div class="row">
				<div class="col-md-6 group">
					<h3 class="form-heading"><?php esc_html_e( 'Hours of Operation', 'alw-listings' ); ?></h3>
					<p class="description"><?php esc_html_e( 'e.g. 8:00 am - 5:00 pm, or "Closed"', 'alw-listings' ); ?></p>
					<?php foreach ( [ 'monday','tuesday','wednesday','thursday','friday','saturday','sunday' ] as $day ) : ?>
					<div class="form-group">
						<label class="control-label" for="alw_hours_<?php echo esc_attr( $day ); ?>"><?php echo esc_html( ucfirst( $day ) ); ?></label>
						<input type="text" name="hours[<?php echo esc_attr( $day ); ?>]" id="alw_hours_<?php echo esc_attr( $day ); ?>"
							   value="<?php echo esc_attr( $_POST['hours'][ $day ] ?? '' ); ?>"
							   class="form-control" placeholder="<?php esc_attr_e( 'hh:mm am - hh:mm pm', 'alw-listings' ); ?>">
					</div>
					<?php endforeach; ?>
				</div>
				<div class="col-md-6 group">
					<h3 class="form-heading"><?php esc_html_e( 'Image Gallery (Max 6)', 'alw-listings' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Select up to 6 images for your listing gallery.', 'alw-listings' ); ?></p>
					<div class="form-group">
						<a href="#" id="alw-upload-gallery-button" class="button"><?php esc_html_e( 'Select Images', 'alw-listings' ); ?></a>
						<div id="alw-gallery-container" style="margin-top:15px;"></div>
						<input type="hidden" name="alw_gallery_ids" id="alw_gallery_ids" value="">
					</div>
				</div>
			</div>
			<?php endif; ?>

			<!-- Category Selection -->
			<hr style="margin:30px 0;">
			<div class="row">
				<div class="col-md-12 group">
					<h3 class="form-heading"><?php esc_html_e( 'Listing Category', 'alw-listings' ); ?> <span class="red-asterisk">*</span></h3>
					<div class="alw-category-selection <?php echo $type === 'basic' ? 'basic-categories' : 'premium-categories'; ?>">
						<div class="alw-category-options">
							<?php if ( $type === 'basic' ) : ?>
								<?php foreach ( $this->listing_categories as $slug => $name ) : ?>
								<div class="alw-category-option">
									<label>
										<input type="radio" name="category" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $data['category'], $slug ); ?> required>
										<?php echo esc_html( $name ); ?>
									</label>
								</div>
								<?php endforeach; ?>
							<?php else : ?>
								<?php foreach ( $this->listing_categories as $slug => $name ) : ?>
								<div class="alw-category-option">
									<label>
										<input type="checkbox" name="categories[]" value="<?php echo esc_attr( $slug ); ?>" <?php echo in_array( $slug, (array) $data['categories'], true ) ? 'checked' : ''; ?>>
										<?php echo esc_html( $name ); ?>
									</label>
								</div>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>

			<!-- Terms -->
			<div class="row">
				<div class="col-md-12 agree-container">
					<label for="agree">
						<?php esc_html_e( 'I accept the', 'alw-listings' ); ?>
						<a target="_blank" href="<?php echo esc_url( home_url( '/' . ltrim( $terms_slug, '/' ) . '/' ) ); ?>"><?php esc_html_e( 'Terms & Conditions', 'alw-listings' ); ?></a>
						<span class="red-asterisk">*</span>
					</label>
					<input type="checkbox" name="agree" id="agree" value="1" <?php echo esc_attr( $data['agree_checked'] ); ?> required>
				</div>
			</div>

			<?php if ( $site_key ) : ?>
			<div class="row" style="margin-top:20px;">
				<div class="col-md-12" style="display:flex;justify-content:center;">
					<div class="g-recaptcha" data-sitekey="<?php echo esc_attr( $site_key ); ?>"></div>
				</div>
			</div>
			<?php endif; ?>

			<div class="row" style="margin-top:20px;">
				<div class="col-sm-12" style="text-align:center;">
					<button type="submit" id="sub-btn" class="btn btn-info"><?php echo esc_html( $submit_text ); ?></button>
				</div>
			</div>
		</form>
		</div><!-- /.container -->
		<?php
	}

	// -------------------------------------------------------------------------
	// Form submission handlers
	// -------------------------------------------------------------------------
	public function handle_basic_listing_submission() {
		$this->_handle_common_listing_submission( false );
	}

	public function handle_premium_listing_submission() {
		$this->_handle_common_listing_submission( true );
	}

	private function _handle_common_listing_submission( bool $is_premium ) {
		$nonce_action = $is_premium ? 'alw_premium_listing_nonce' : 'alw_basic_listing_nonce';

		if ( ! isset( $_POST['alw_listing_form_nonce'] ) || ! wp_verify_nonce( $_POST['alw_listing_form_nonce'], $nonce_action ) ) {
			wp_die( 'Security check failed. Please try again.' );
		}

		$errors      = [];
		$redirect_url = esc_url_raw( $_POST['redirect_to'] ?? home_url() );

		// --- Sanitize inputs ---
		$email       = sanitize_email( $_POST['email'] ?? '' );
		$password    = $_POST['password']  ?? '';
		$password2   = $_POST['password2'] ?? '';
		$first_name  = sanitize_text_field( $_POST['first_name']  ?? '' );
		$last_name   = sanitize_text_field( $_POST['last_name']   ?? '' );
		$phone_raw   = sanitize_text_field( $_POST['phone']       ?? '' );
		$company     = sanitize_text_field( $_POST['company']     ?? '' );
		$slogan      = sanitize_text_field( $_POST['slogan']      ?? '' );
		$description = sanitize_textarea_field( $_POST['description'] ?? '' );
		$address1    = sanitize_text_field( $_POST['address1']    ?? '' );
		$address2    = sanitize_text_field( $_POST['address2']    ?? '' );
		$zipcode     = sanitize_text_field( $_POST['zipcode']     ?? '' );
		$city        = sanitize_text_field( $_POST['city']        ?? '' );
		$state       = sanitize_text_field( $_POST['state']       ?? '' );
		$website     = sanitize_url( $_POST['website']            ?? '' );
		$agree       = isset( $_POST['agree'] ) ? '1' : '0';

		// Normalize phone to digits only
		$phone_digits = preg_replace( '/\D/', '', $phone_raw );

		// --- reCAPTCHA ---
		$secret_key = $this->get_setting( 'recaptcha_secret_key' );
		if ( $secret_key ) {
			if ( empty( $_POST['g-recaptcha-response'] ) ) {
				$errors[] = __( 'Please complete the CAPTCHA verification.', 'alw-listings' );
			} else {
				$recaptcha_result = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', [
					'body' => [
						'secret'   => $secret_key,
						'response' => sanitize_text_field( $_POST['g-recaptcha-response'] ),
						'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
					],
				] );
				if ( is_wp_error( $recaptcha_result ) ) {
					$errors[] = __( 'Could not connect to the reCAPTCHA service. Please try again.', 'alw-listings' );
				} else {
					$body = json_decode( wp_remote_retrieve_body( $recaptcha_result ) );
					if ( ! $body->success ) {
						$errors[] = __( 'CAPTCHA verification failed. Please try again.', 'alw-listings' );
					}
				}
			}
		}

		// --- Validate account fields ---
		if ( ! is_user_logged_in() ) {
			if ( empty( $email ) || ! is_email( $email ) )                   $errors[] = __( 'Please provide a valid email address.', 'alw-listings' );
			if ( ! empty( $email ) && email_exists( $email ) )               $errors[] = __( 'This email is already registered. Please log in or use a different email.', 'alw-listings' );
			if ( empty( $password ) || strlen( $password ) < 8 )             $errors[] = __( 'Password must be at least 8 characters.', 'alw-listings' );
			if ( $password !== $password2 )                                   $errors[] = __( 'Passwords do not match.', 'alw-listings' );
		}
		if ( empty( $first_name ) )                                           $errors[] = __( 'First name is required.', 'alw-listings' );
		if ( empty( $last_name ) )                                            $errors[] = __( 'Last name is required.', 'alw-listings' );
		if ( strlen( $phone_digits ) !== 10 )                                 $errors[] = __( 'Please provide a valid 10-digit phone number.', 'alw-listings' );
		if ( empty( $company ) )                                              $errors[] = __( 'Business name is required.', 'alw-listings' );
		if ( empty( $description ) )                                          $errors[] = __( 'Business description is required.', 'alw-listings' );
		if ( empty( $address1 ) )                                             $errors[] = __( 'Address is required.', 'alw-listings' );
		if ( empty( $city ) )                                                 $errors[] = __( 'City is required.', 'alw-listings' );
		if ( empty( $state ) )                                                $errors[] = __( 'State is required.', 'alw-listings' );
		if ( ! preg_match( '/^\d{5}$/', $zipcode ) )                         $errors[] = __( 'Please provide a valid 5-digit zipcode.', 'alw-listings' );
		if ( ! empty( $website ) && ! filter_var( $website, FILTER_VALIDATE_URL ) ) $errors[] = __( 'Please provide a valid website URL.', 'alw-listings' );
		if ( $agree !== '1' )                                                 $errors[] = __( 'You must accept the Terms & Conditions.', 'alw-listings' );

		// --- Category validation ---
		$selected_categories = [];
		if ( $is_premium ) {
			if ( isset( $_POST['categories'] ) && is_array( $_POST['categories'] ) ) {
				$selected_categories = array_filter(
					array_map( 'sanitize_key', $_POST['categories'] ),
					fn( $s ) => array_key_exists( $s, $this->listing_categories )
				);
			}
			if ( empty( $selected_categories ) ) $errors[] = __( 'Please select at least one category.', 'alw-listings' );
		} else {
			$cat = sanitize_key( $_POST['category'] ?? '' );
			if ( empty( $cat ) || ! array_key_exists( $cat, $this->listing_categories ) ) {
				$errors[] = __( 'Please select a category.', 'alw-listings' );
			} else {
				$selected_categories[] = $cat;
			}
		}

		// --- Gallery validation (premium) ---
		$gallery_ids = [];
		if ( $is_premium && isset( $_POST['alw_gallery_ids'] ) && $_POST['alw_gallery_ids'] !== '' ) {
			$gallery_ids = array_values( array_filter( explode( ',', sanitize_text_field( $_POST['alw_gallery_ids'] ) ), 'is_numeric' ) );
			if ( count( $gallery_ids ) > 6 ) $errors[] = __( 'You may upload a maximum of 6 gallery images.', 'alw-listings' );
		}

		// Return errors
		if ( ! empty( $errors ) ) {
			wp_safe_redirect( add_query_arg( 'submission_errors', base64_encode( json_encode( $errors ) ), $redirect_url ) );
			exit;
		}

		// --- Create / get user ---
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$user_id = wp_create_user( $email, $password, $email );
			if ( is_wp_error( $user_id ) ) {
				wp_die( 'User registration failed: ' . esc_html( $user_id->get_error_message() ) );
			}
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id, true );
		}
		wp_update_user( [ 'ID' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name ] );
		update_user_meta( $user_id, 'alw_phone', $phone_digits );

		// --- Create listing post ---
		$initial_status = $is_premium && ! empty( $this->get_setting( 'shopify_store_domain' ) )
			? 'alw_pending_payment'
			: 'pending';

		$new_listing_id = wp_insert_post( [
			'post_title'   => $company,
			'post_content' => $description,
			'post_status'  => $initial_status,
			'post_type'    => 'listing',
			'post_author'  => $user_id,
		] );

		if ( is_wp_error( $new_listing_id ) ) {
			wp_die( 'Error creating listing: ' . esc_html( $new_listing_id->get_error_message() ) );
		}

		// --- Save meta ---
		update_post_meta( $new_listing_id, 'alw_listing_type',        $is_premium ? 'premium' : 'basic' );
		update_post_meta( $new_listing_id, 'alw_listing_categories',  implode( ',', $selected_categories ) );
		update_post_meta( $new_listing_id, 'alw_listing_address1',    $address1 );
		update_post_meta( $new_listing_id, 'alw_listing_address2',    $address2 );
		update_post_meta( $new_listing_id, 'alw_listing_city',        $city );
		update_post_meta( $new_listing_id, 'alw_listing_state',       $state );
		update_post_meta( $new_listing_id, 'alw_listing_zipcode',     $zipcode );
		update_post_meta( $new_listing_id, 'alw_listing_website',     $website );
		update_post_meta( $new_listing_id, 'alw_listing_phone',       $phone_digits );
		update_post_meta( $new_listing_id, 'alw_listing_email',       $email );
		if ( $slogan )   update_post_meta( $new_listing_id, 'alw_listing_slogan', $slogan );

		if ( $is_premium ) {
			$hours = isset( $_POST['hours'] ) && is_array( $_POST['hours'] )
				? array_map( 'sanitize_text_field', $_POST['hours'] )
				: [];
			foreach ( $hours as $day => $time ) {
				update_post_meta( $new_listing_id, 'alw_hours_' . sanitize_key( $day ), $time );
			}
			if ( ! empty( $gallery_ids ) ) {
				update_post_meta( $new_listing_id, 'alw_gallery_ids', implode( ',', $gallery_ids ) );
			}
		}

		// --- Redirect ---
		if ( $initial_status === 'alw_pending_payment' ) {
			// Show payment step
			wp_safe_redirect( add_query_arg( 'alw_proceed_payment', $new_listing_id, $redirect_url ) );
		} else {
			// Send emails and confirm
			$listing = get_post( $new_listing_id );
			$this->_send_basic_submission_emails( $listing );
			wp_safe_redirect( add_query_arg( 'submission_success', '1', $redirect_url ) );
		}
		exit;
	}
}
