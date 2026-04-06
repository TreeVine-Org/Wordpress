<?php
/**
 * ALW Listings — Display Trait
 *
 * Handles all front-end display:
 *  - the_content filter to enrich single listing pages (premium + basic)
 *  - Google Maps Embed API iframe on premium single pages
 *  - Shortcodes: alw_all_listings, alw_my_listings, alw_listings_by_category
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait ALW_Trait_Display {

	// -------------------------------------------------------------------------
	// Single listing content enrichment
	// -------------------------------------------------------------------------

	/**
	 * Appends structured listing details (contact, hours, map, gallery) to the
	 * post content on single listing pages. Premium listings get the map.
	 * Fires on the_content filter; safe-guarded to main query only.
	 *
	 * @param string $content Original post content.
	 * @return string Enhanced content.
	 */
	public function alw_enhance_single_listing_content( string $content ): string {
		if ( ! is_singular( 'listing' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$listing_id   = get_the_ID();
		$listing_type = get_post_meta( $listing_id, 'alw_listing_type', true );

		$address1 = get_post_meta( $listing_id, 'alw_listing_address1', true );
		$address2 = get_post_meta( $listing_id, 'alw_listing_address2', true );
		$city     = get_post_meta( $listing_id, 'alw_listing_city',     true );
		$state    = get_post_meta( $listing_id, 'alw_listing_state',    true );
		$zipcode  = get_post_meta( $listing_id, 'alw_listing_zipcode',  true );
		$website  = get_post_meta( $listing_id, 'alw_listing_website',  true );
		$phone    = get_post_meta( $listing_id, 'alw_listing_phone',    true );
		$email    = get_post_meta( $listing_id, 'alw_listing_email',    true );
		$slogan   = get_post_meta( $listing_id, 'alw_listing_slogan',   true );
		$cats_str = get_post_meta( $listing_id, 'alw_listing_categories', true );

		ob_start();

		if ( $listing_type === 'premium' ) {
			$this->_render_premium_single(
				$listing_id, $address1, $address2, $city, $state, $zipcode,
				$website, $phone, $email, $slogan, $cats_str
			);
		} else {
			$this->_render_basic_single( $listing_id, $address1, $address2, $city, $state, $zipcode, $website, $cats_str );
		}

		return $content . ob_get_clean();
	}

	/**
	 * Renders the premium single listing detail panel: contact grid, hours,
	 * Google Maps embed, gallery, website button.
	 */
	private function _render_premium_single(
		int $id, string $address1, string $address2, string $city, string $state,
		string $zipcode, string $website, string $phone, string $email,
		string $slogan, string $cats_str
	) {
		$maps_api_key    = $this->get_setting( 'maps_api_key' );
		$gallery_ids_str = get_post_meta( $id, 'alw_gallery_ids', true );
		$days            = [ 'monday','tuesday','wednesday','thursday','friday','saturday','sunday' ];

		// Build full address string for map query
		$addr_parts = array_filter( [ $address1, $address2, $city . ( $state ? ', ' . $state : '' ) . ' ' . $zipcode ] );
		$address_q  = urlencode( implode( ', ', $addr_parts ) );

		echo '<div class="alw-single-listing-premium">';

		// Slogan
		if ( $slogan ) {
			echo '<p class="alw-slogan">' . esc_html( $slogan ) . '</p>';
		}

		// Categories
		if ( $cats_str ) {
			echo '<p class="alw-single-categories">' . esc_html( $this->get_category_names_from_slugs( $cats_str ) ) . '</p>';
		}

		// Contact grid — phone, email, website
		$has_contact = $phone || $email || $website;
		if ( $has_contact ) {
			echo '<div class="alw-contact-grid">';
			if ( $phone ) {
				$phone_digits = preg_replace( '/\D/', '', $phone );
				echo '<div class="alw-contact-item">';
				echo '<a href="tel:' . esc_attr( $phone_digits ) . '">';
				echo '<span class="dashicons dashicons-phone"></span>';
				echo '<label>' . esc_html( $phone ) . '</label>';
				echo '</a></div>';
			}
			if ( $email ) {
				echo '<div class="alw-contact-item">';
				echo '<a href="mailto:' . esc_attr( antispambot( $email ) ) . '">';
				echo '<span class="dashicons dashicons-email-alt"></span>';
				echo '<label>' . esc_html( antispambot( $email ) ) . '</label>';
				echo '</a></div>';
			}
			if ( $website ) {
				$host = parse_url( $website, PHP_URL_HOST ) ?: $website;
				echo '<div class="alw-contact-item">';
				echo '<a href="' . esc_url( $website ) . '" target="_blank" rel="noopener noreferrer">';
				echo '<span class="dashicons dashicons-admin-site-alt3"></span>';
				echo '<label>' . esc_html( $host ) . '</label>';
				echo '</a></div>';
			}
			echo '</div>';
		}

		// Address text
		if ( $address1 ) {
			echo '<p class="alw-single-address">' . esc_html( implode( ', ', array_filter( $addr_parts ) ) ) . '</p>';
		}

		// Hours + Map two-column grid
		echo '<div class="alw-details-grid">';

		// Hours
		echo '<div class="alw-hours-section">';
		echo '<h3 class="alw-section-title">' . esc_html__( 'Hours of Operation', 'alw-listings' ) . '</h3>';
		echo '<table class="alw-hours-table"><tbody>';
		$has_hours = false;
		foreach ( $days as $day ) {
			$hours = get_post_meta( $id, 'alw_hours_' . $day, true );
			if ( $hours !== '' && $hours !== null ) {
				echo '<tr><td class="alw-day">' . esc_html( ucfirst( $day ) ) . '</td><td class="alw-time">' . esc_html( $hours ) . '</td></tr>';
				$has_hours = true;
			}
		}
		if ( ! $has_hours ) {
			echo '<tr><td colspan="2">' . esc_html__( 'Hours not available.', 'alw-listings' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';

		// Google Map
		echo '<div class="alw-map-section">';
		echo '<h3 class="alw-section-title">' . esc_html__( 'Location', 'alw-listings' ) . '</h3>';
		if ( $maps_api_key && $address_q ) {
			$map_src = 'https://www.google.com/maps/embed/v1/place?key=' . rawurlencode( $maps_api_key ) . '&q=' . $address_q;
			echo '<iframe loading="lazy" style="border:0;width:100%;height:300px;border-radius:5px;" src="' . esc_url( $map_src ) . '" allowfullscreen></iframe>';
		} elseif ( $address_q ) {
			echo '<p>' . esc_html( urldecode( $address_q ) ) . '</p>';
			echo '<p class="description"><em>' . esc_html__( 'Interactive map not available — Google Maps API key not configured.', 'alw-listings' ) . '</em></p>';
		} else {
			echo '<p class="description">' . esc_html__( 'No address provided.', 'alw-listings' ) . '</p>';
		}
		echo '</div>'; // alw-map-section

		echo '</div>'; // alw-details-grid

		// Gallery
		if ( ! empty( $gallery_ids_str ) ) {
			$ids = array_filter( explode( ',', $gallery_ids_str ), 'is_numeric' );
			if ( $ids ) {
				echo '<div class="alw-gallery-section">';
				echo '<h3 class="alw-section-title">' . esc_html__( 'Gallery', 'alw-listings' ) . '</h3>';
				echo '<div class="alw-gallery-grid">';
				foreach ( $ids as $img_id ) {
					$img = wp_get_attachment_image( absint( $img_id ), 'medium' );
					if ( $img ) {
						$full = wp_get_attachment_url( absint( $img_id ) );
						echo '<div class="alw-gallery-item"><a href="' . esc_url( $full ) . '" target="_blank">' . $img . '</a></div>';
					}
				}
				echo '</div></div>';
			}
		}

		// Website button
		if ( $website ) {
			echo '<div class="alw-single-footer">';
			echo '<a href="' . esc_url( $website ) . '" target="_blank" rel="noopener noreferrer" class="alw-website-button">' . esc_html__( 'Visit Website', 'alw-listings' ) . '</a>';
			echo '</div>';
		}

		echo '</div>'; // alw-single-listing-premium
	}

	/**
	 * Renders basic listing details: category, address, website.
	 */
	private function _render_basic_single( int $id, string $address1, string $address2, string $city, string $state, string $zipcode, string $website, string $cats_str ) {
		echo '<div class="alw-single-listing-basic">';

		if ( $cats_str ) {
			echo '<p class="alw-single-categories"><strong>' . esc_html__( 'Category:', 'alw-listings' ) . '</strong> ' . esc_html( $this->get_category_names_from_slugs( $cats_str ) ) . '</p>';
		}

		if ( $address1 ) {
			$parts = array_filter( [ $address1, $address2, $city . ( $state ? ', ' . $state : '' ) . ' ' . $zipcode ] );
			echo '<p><strong>' . esc_html__( 'Address:', 'alw-listings' ) . '</strong><br>' . nl2br( esc_html( implode( "\n", $parts ) ) ) . '</p>';
		}

		if ( $website ) {
			$host = parse_url( $website, PHP_URL_HOST ) ?: $website;
			echo '<p><strong>' . esc_html__( 'Website:', 'alw-listings' ) . '</strong> ';
			echo '<a href="' . esc_url( $website ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $host ) . '</a></p>';
		}

		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// Shortcode: [alw_all_listings]
	// -------------------------------------------------------------------------
	public function alw_all_listings_shortcode(): string {
		ob_start();
		$query = new WP_Query( [
			'post_type'      => 'listing',
			'post_status'    => 'alw_active',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );

		if ( $query->have_posts() ) {
			echo '<div class="alw-all-listings-wrapper">';
			while ( $query->have_posts() ) {
				$query->the_post();
				$lid   = get_the_ID();
				$type  = get_post_meta( $lid, 'alw_listing_type', true );
				$cats  = $this->get_category_names_from_slugs( get_post_meta( $lid, 'alw_listing_categories', true ) );
				$a1    = get_post_meta( $lid, 'alw_listing_address1', true );
				$a2    = get_post_meta( $lid, 'alw_listing_address2', true );
				$city  = get_post_meta( $lid, 'alw_listing_city',     true );
				$state = get_post_meta( $lid, 'alw_listing_state',    true );
				$zip   = get_post_meta( $lid, 'alw_listing_zipcode',  true );
				$web   = get_post_meta( $lid, 'alw_listing_website',  true );

				echo '<div class="alw-listing-item" style="border:1px solid #ddd;padding:20px;margin-bottom:25px;border-radius:5px;">';
				echo '<h3><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a>';
				if ( $type === 'premium' ) echo ' <span style="font-size:0.7em;color:#b49b3c;vertical-align:middle;">&#9733; Premium</span>';
				echo '</h3>';
				if ( $cats )  echo '<p><strong>' . esc_html__( 'Categories:', 'alw-listings' ) . '</strong> ' . esc_html( $cats ) . '</p>';

				echo '<div class="alw-listing-description">' . wpautop( get_the_content() ) . '</div>';

				// Address
				$parts = array_filter( [ $a1, $a2, $city . ( $state ? ', ' . $state : '' ) . ' ' . $zip ] );
				if ( $parts ) echo '<p><strong>' . esc_html__( 'Address:', 'alw-listings' ) . '</strong><br>' . nl2br( esc_html( implode( "\n", $parts ) ) ) . '</p>';

				if ( $web ) {
					$host = parse_url( $web, PHP_URL_HOST ) ?: $web;
					echo '<p><strong>' . esc_html__( 'Website:', 'alw-listings' ) . '</strong> <a href="' . esc_url( $web ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $host ) . '</a></p>';
				}
				echo '</div>';
			}
			echo '</div>';
			wp_reset_postdata();
		} else {
			echo '<p>' . esc_html__( 'No active listings found.', 'alw-listings' ) . '</p>';
		}
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Shortcode: [alw_my_listings]
	// -------------------------------------------------------------------------
	public function alw_my_listings_shortcode(): string {
		ob_start();
		if ( ! is_user_logged_in() ) {
			echo '<p>' . sprintf(
				/* translators: %s: login URL */
				wp_kses( __( 'Please <a href="%s">log in</a> to view your listings.', 'alw-listings' ), [ 'a' => [ 'href' => [] ] ] ),
				esc_url( wp_login_url( get_permalink() ) )
			) . '</p>';
			return ob_get_clean();
		}

		$query = new WP_Query( [
			'post_type'      => 'listing',
			'author'         => get_current_user_id(),
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'post_status'    => [ 'pending', 'alw_active', 'alw_inactive', 'alw_pending_payment', 'draft' ],
		] );

		if ( $query->have_posts() ) {
			echo '<div class="alw-my-listings-wrapper"><h2>' . esc_html__( 'Your Submitted Listings', 'alw-listings' ) . '</h2><ul>';
			while ( $query->have_posts() ) {
				$query->the_post();
				$lid    = get_the_ID();
				$type   = get_post_meta( $lid, 'alw_listing_type', true );
				$status = get_post_status();
				$status_labels = [
					'pending'             => __( 'Pending Review', 'alw-listings' ),
					'alw_active'          => __( 'Active', 'alw-listings' ),
					'alw_inactive'        => __( 'Inactive', 'alw-listings' ),
					'alw_pending_payment' => __( 'Awaiting Payment', 'alw-listings' ),
				];
				$status_label = $status_labels[ $status ] ?? ucfirst( $status );

				echo '<li>';
				echo '<a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a>';
				if ( $type === 'premium' ) echo ' &#9733;';
				echo ' — ' . esc_html( $status_label );

				// Payment link for listings awaiting payment
				if ( $status === 'alw_pending_payment' ) {
					$referer = get_post_meta( $lid, 'alw_form_referer', true );
					if ( $referer ) {
						echo ' &nbsp;<a href="' . esc_url( add_query_arg( 'alw_proceed_payment', $lid, $referer ) ) . '">' . esc_html__( 'Complete Payment', 'alw-listings' ) . '</a>';
					}
				}
				echo '</li>';
			}
			echo '</ul></div>';
			wp_reset_postdata();
		} else {
			echo '<p>' . esc_html__( 'You have not submitted any listings yet.', 'alw-listings' ) . '</p>';
		}
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Shortcode: [alw_listings_by_category category="slug"]
	// -------------------------------------------------------------------------
	public function alw_listings_by_category_shortcode( $atts ): string {
		ob_start();

		$atts          = shortcode_atts( [ 'category' => '' ], $atts, 'alw_listings_by_category' );
		$category_slug = sanitize_key( $atts['category'] );

		if ( empty( $category_slug ) || ! array_key_exists( $category_slug, $this->listing_categories ) ) {
			echo '<p class="alw-error">' . esc_html__( 'Error: Invalid or missing category slug.', 'alw-listings' ) . '</p>';
			return ob_get_clean();
		}

		$category_name    = $this->listing_categories[ $category_slug ];
		$current_page_url = get_permalink();
		$cat_base         = $this->get_setting( 'category_listings_base', '' );
		$placeholder_url  = $this->get_setting( 'placeholder_image_url', '' );

		$query = new WP_Query( [
			'post_type'      => 'listing',
			'post_status'    => 'alw_active',
			'posts_per_page' => -1,
			'orderby'        => 'rand',
			'meta_query'     => [ [
				'key'     => 'alw_listing_categories',
				'value'   => $category_slug,
				'compare' => 'LIKE',
			] ],
		] );

		echo '<h2>' . esc_html( sprintf( __( 'Listings in %s', 'alw-listings' ), $category_name ) ) . '</h2>';

		if ( $query->have_posts() ) {
			echo '<div class="alw-category-listings-container">';
			while ( $query->have_posts() ) {
				$query->the_post();
				$lid  = get_the_ID();
				$type = get_post_meta( $lid, 'alw_listing_type', true ) ?: 'basic';

				$all_cats_str = get_post_meta( $lid, 'alw_listing_categories', true );
				$all_cats     = $all_cats_str ? explode( ',', $all_cats_str ) : [];
				$slogan       = get_post_meta( $lid, 'alw_listing_slogan', true );

				// Featured image — premium only. Fallback to configured placeholder.
				$feat_url = ( $type === 'premium' ) ? get_the_post_thumbnail_url( $lid, 'medium_large' ) : '';
				$img_url  = $feat_url ?: $placeholder_url;

				echo '<div class="alw-listing-item alw-listing-type-' . esc_attr( $type ) . '">';

				if ( $type === 'premium' ) {
					// Image column
					echo '<div class="alw-listing-image-column">';
					$bg = $img_url ? 'background-image:url(' . esc_url( $img_url ) . ');' : '';
					echo '<div class="alw-listing-image" style="' . esc_attr( $bg ) . '" role="img" aria-label="' . esc_attr( get_the_title() ) . '"></div>';
					echo '</div>';

					// Details column
					echo '<div class="alw-listing-details-column">';
					echo '<h3 class="alw-listing-title"><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h3>';
					if ( $slogan ) echo '<h4 class="alw-listing-slogan">' . esc_html( $slogan ) . '</h4>';

					// Categories with links
					echo '<span class="alw-listing-category">' . esc_html__( 'CATEGORIES:', 'alw-listings' ) . ' ';
					echo '<a href="' . esc_url( $current_page_url ) . '">' . esc_html( $category_name ) . '</a>';
					foreach ( $all_cats as $other_slug ) {
						$other_slug = trim( $other_slug );
						if ( $other_slug === $category_slug || ! isset( $this->listing_categories[ $other_slug ] ) ) continue;
						if ( $cat_base ) {
							$other_url = home_url( trailingslashit( $cat_base ) . $other_slug . '-listings/' );
							echo ', <a href="' . esc_url( $other_url ) . '">' . esc_html( $this->listing_categories[ $other_slug ] ) . '</a>';
						} else {
							echo ', ' . esc_html( $this->listing_categories[ $other_slug ] );
						}
					}
					echo '</span>';

					echo '<div class="alw-listing-description">' . wpautop( get_the_excerpt() ) . '</div>';
					echo '<a href="' . esc_url( get_permalink() ) . '" class="alw-learn-more-button">' . esc_html__( 'Learn More', 'alw-listings' ) . '</a>';
					echo '</div>'; // details column

				} else {
					// Basic listing
					echo '<h3 class="alw-listing-title"><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h3>';
					echo '<span class="alw-listing-category">' . esc_html__( 'CATEGORY:', 'alw-listings' ) . ' <a href="' . esc_url( $current_page_url ) . '">' . esc_html( $category_name ) . '</a></span>';
					echo '<div class="alw-listing-description">' . wpautop( get_the_excerpt() ) . '</div>';
					echo '<a href="' . esc_url( get_permalink() ) . '" class="alw-learn-more-button">' . esc_html__( 'Learn More', 'alw-listings' ) . '</a>';
				}

				echo '</div>'; // alw-listing-item
			}
			echo '</div>';
			wp_reset_postdata();
		} else {
			echo '<p>' . esc_html( sprintf( __( 'No active listings found in the "%s" category.', 'alw-listings' ), $category_name ) ) . '</p>';
		}

		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

	/**
	 * Converts a comma-separated string of category slugs to display names.
	 *
	 * @param string $slugs_string e.g. "assisted-living,home-care"
	 * @return string              e.g. "Assisted Living, Home Care"
	 */
	private function get_category_names_from_slugs( string $slugs_string ): string {
		if ( empty( $slugs_string ) ) return '';
		$names = [];
		foreach ( explode( ',', $slugs_string ) as $slug ) {
			$slug = trim( $slug );
			if ( isset( $this->listing_categories[ $slug ] ) ) {
				$names[] = $this->listing_categories[ $slug ];
			}
		}
		return implode( ', ', $names );
	}
}
