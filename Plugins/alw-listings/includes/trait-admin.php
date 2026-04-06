<?php
/**
 * ALW Listings — Admin Trait
 *
 * Handles all WordPress admin enhancements:
 *  - Admin asset enqueueing
 *  - User profile phone field
 *  - Listing details meta box (all fields including slogan)
 *  - Status control meta box
 *  - Admin list view post states and query filtering
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait ALW_Trait_Admin {

	// -------------------------------------------------------------------------
	// Admin assets
	// -------------------------------------------------------------------------
	public function enqueue_admin_assets( string $hook ) {
		global $post;
		if ( in_array( $hook, [ 'post.php', 'post-new.php' ], true )
			&& isset( $post->post_type )
			&& $post->post_type === 'listing'
		) {
			wp_enqueue_media();
		}
	}

	// -------------------------------------------------------------------------
	// User profile — phone field
	// -------------------------------------------------------------------------
	public function add_user_phone_field( WP_User $user ) {
		?>
		<h3><?php esc_html_e( 'Contact Information (ALW Listings)', 'alw-listings' ); ?></h3>
		<table class="form-table">
			<tr>
				<th><label for="alw_phone"><?php esc_html_e( 'Phone Number', 'alw-listings' ); ?></label></th>
				<td>
					<input type="tel" name="alw_phone" id="alw_phone"
						   value="<?php echo esc_attr( get_user_meta( $user->ID, 'alw_phone', true ) ); ?>"
						   class="regular-text">
					<p class="description"><?php esc_html_e( 'Enter 10-digit phone number (digits only).', 'alw-listings' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_user_phone_field( int $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) return;
		if ( isset( $_POST['alw_phone'] ) ) {
			$digits = preg_replace( '/\D/', '', sanitize_text_field( $_POST['alw_phone'] ) );
			update_user_meta( $user_id, 'alw_phone', $digits );
		}
	}

	// -------------------------------------------------------------------------
	// Listing details meta box
	// -------------------------------------------------------------------------
	public function alw_add_custom_listing_metabox() {
		add_meta_box(
			'alw_listing_details_metabox',
			__( 'Listing Details', 'alw-listings' ),
			[ $this, 'alw_display_listing_meta_box' ],
			'listing',
			'normal',
			'high'
		);
	}

	public function alw_display_listing_meta_box( WP_Post $post ) {
		wp_nonce_field( 'alw_save_listing_meta', 'alw_listing_meta_nonce' );

		$listing_type     = get_post_meta( $post->ID, 'alw_listing_type', true );
		$cats_str         = get_post_meta( $post->ID, 'alw_listing_categories', true );
		$current_cats     = $cats_str ? explode( ',', $cats_str ) : [];

		echo '<style>
			.alw-meta-table { width:100%;border-collapse:collapse; }
			.alw-meta-table td,.alw-meta-table th { text-align:left;padding:8px;vertical-align:top; }
			.alw-meta-table th { width:160px;font-weight:700; }
			.alw-meta-table input[type="text"],.alw-meta-table input[type="url"],.alw-meta-table textarea { width:98%;box-sizing:border-box; }
			.alw-meta-table .radio-label,.alw-meta-table .checkbox-label { display:block;margin-bottom:5px; }
			.alw-meta-table .radio-label input,.alw-meta-table .checkbox-label input { margin-right:8px; }
			.alw-gallery-thumb { max-width:100px;height:auto;margin:5px;border:1px solid #ddd;vertical-align:middle; }
			.alw-admin-gallery-preview { display:flex;flex-wrap:wrap;gap:10px; }
		</style>';

		// --- Basic Details ---
		echo '<h4>' . esc_html__( 'Basic Details', 'alw-listings' ) . '</h4>';
		echo '<table class="alw-meta-table">';

		$text_fields = [
			'slogan'   => __( 'Slogan / Tagline', 'alw-listings' ),
			'phone'    => __( 'Contact Phone',    'alw-listings' ),
			'email'    => __( 'Contact Email',    'alw-listings' ),
			'address1' => __( 'Address Line 1',   'alw-listings' ),
			'address2' => __( 'Address Line 2',   'alw-listings' ),
			'city'     => __( 'City',             'alw-listings' ),
			'state'    => __( 'State',            'alw-listings' ),
			'zipcode'  => __( 'Zipcode',          'alw-listings' ),
			'website'  => __( 'Website',          'alw-listings' ),
		];

		foreach ( $text_fields as $key => $label ) {
			$meta_key = 'alw_listing_' . $key;
			$value    = get_post_meta( $post->ID, $meta_key, true );
			$type_attr = ( $key === 'website' ) ? 'url' : 'text';
			echo '<tr>';
			echo '<th><label for="' . esc_attr( $meta_key ) . '">' . esc_html( $label ) . ':</label></th>';
			echo '<td><input type="' . esc_attr( $type_attr ) . '" id="' . esc_attr( $meta_key ) . '" name="' . esc_attr( $meta_key ) . '" value="' . esc_attr( $value ) . '"></td>';
			echo '</tr>';
		}

		// Shopify order info (read-only)
		$order_id  = get_post_meta( $post->ID, 'alw_shopify_order_id',     true );
		$order_num = get_post_meta( $post->ID, 'alw_shopify_order_number', true );
		if ( $order_id ) {
			echo '<tr><th>' . esc_html__( 'Shopify Order', 'alw-listings' ) . '</th>';
			echo '<td>#' . esc_html( $order_num ?: $order_id ) . ' <em>(read-only)</em></td></tr>';
		}

		echo '</table>';

		// --- Category Selection ---
		echo '<hr><h4>' . esc_html__( 'Category Selection', 'alw-listings' ) . '</h4>';
		echo '<table class="alw-meta-table"><tr><th>' . esc_html__( 'Categories', 'alw-listings' ) . '</th><td>';

		if ( $listing_type === 'premium' ) {
			foreach ( $this->listing_categories as $slug => $name ) {
				echo '<label class="checkbox-label">';
				echo '<input type="checkbox" name="alw_listing_categories[]" value="' . esc_attr( $slug ) . '" ' . ( in_array( $slug, $current_cats, true ) ? 'checked' : '' ) . '> ';
				echo esc_html( $name );
				echo '</label>';
			}
		} else {
			$single_cat = $current_cats[0] ?? '';
			foreach ( $this->listing_categories as $slug => $name ) {
				echo '<label class="radio-label">';
				echo '<input type="radio" name="alw_listing_categories" value="' . esc_attr( $slug ) . '" ' . checked( $single_cat, $slug, false ) . '> ';
				echo esc_html( $name );
				echo '</label>';
			}
		}

		echo '</td></tr></table>';

		// --- Premium Details: Hours & Gallery ---
		if ( $listing_type === 'premium' ) {
			echo '<hr><h4>' . esc_html__( 'Premium Details', 'alw-listings' ) . '</h4>';
			echo '<table class="alw-meta-table">';

			foreach ( [ 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday' ] as $day ) {
				$key   = 'alw_hours_' . strtolower( $day );
				$value = get_post_meta( $post->ID, $key, true );
				echo '<tr>';
				echo '<th><label for="' . esc_attr( $key ) . '">' . esc_html( $day ) . ' ' . esc_html__( 'Hours', 'alw-listings' ) . ':</label></th>';
				echo '<td><input type="text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" placeholder="hh:mm am - hh:mm pm"></td>';
				echo '</tr>';
			}

			// Gallery preview
			echo '<tr><th>' . esc_html__( 'Gallery Images', 'alw-listings' ) . '</th><td>';
			echo '<div class="alw-admin-gallery-preview">';
			$gallery_str = get_post_meta( $post->ID, 'alw_gallery_ids', true );
			if ( $gallery_str ) {
				foreach ( explode( ',', $gallery_str ) as $img_id ) {
					$img_id = absint( $img_id );
					if ( $img_id ) {
						echo wp_get_attachment_image( $img_id, 'thumbnail', false, [ 'class' => 'alw-gallery-thumb' ] );
					}
				}
			}
			echo '</div>';
			echo '<p class="description">' . esc_html__( 'Gallery images are managed by the user on the front-end form.', 'alw-listings' ) . '</p>';
			echo '</td></tr></table>';
		}

		// --- Submitted by ---
		$author = get_userdata( $post->post_author );
		if ( $author ) {
			echo '<hr><h4>' . esc_html__( 'Submitted By', 'alw-listings' ) . '</h4>';
			echo '<p>';
			echo '<strong>' . esc_html__( 'Name:', 'alw-listings' ) . '</strong> ' . esc_html( $author->first_name . ' ' . $author->last_name ) . '<br>';
			echo '<strong>' . esc_html__( 'Email:', 'alw-listings' ) . '</strong> ' . esc_html( $author->user_email ) . '<br>';
			echo '<strong>' . esc_html__( 'Phone:', 'alw-listings' ) . '</strong> ' . esc_html( get_user_meta( $author->ID, 'alw_phone', true ) );
			echo '</p>';
			echo '<p><a href="' . esc_url( get_edit_user_link( $author->ID ) ) . '" target="_blank">' . esc_html__( 'View User Profile', 'alw-listings' ) . ' &raquo;</a></p>';
		}
	}

	public function alw_save_listing_meta_box_data( int $post_id ) {
		if ( ! isset( $_POST['alw_listing_meta_nonce'] ) || ! wp_verify_nonce( $_POST['alw_listing_meta_nonce'], 'alw_save_listing_meta' ) ) return;
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		$text_fields = [ 'slogan', 'phone', 'email', 'address1', 'address2', 'city', 'state', 'zipcode' ];
		foreach ( $text_fields as $key ) {
			$meta_key = 'alw_listing_' . $key;
			if ( isset( $_POST[ $meta_key ] ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( $_POST[ $meta_key ] ) );
			}
		}
		if ( isset( $_POST['alw_listing_website'] ) ) {
			update_post_meta( $post_id, 'alw_listing_website', sanitize_url( $_POST['alw_listing_website'] ) );
		}

		// Hours
		$hour_keys = [ 'alw_hours_monday','alw_hours_tuesday','alw_hours_wednesday','alw_hours_thursday','alw_hours_friday','alw_hours_saturday','alw_hours_sunday' ];
		foreach ( $hour_keys as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, sanitize_text_field( $_POST[ $key ] ) );
			}
		}

		// Categories
		if ( isset( $_POST['alw_listing_categories'] ) ) {
			$raw = $_POST['alw_listing_categories'];
			$cats_to_save = is_array( $raw )
				? implode( ',', array_map( 'sanitize_key', $raw ) )
				: sanitize_key( $raw );
			update_post_meta( $post_id, 'alw_listing_categories', $cats_to_save );
		}
	}

	// -------------------------------------------------------------------------
	// Status control meta box
	// -------------------------------------------------------------------------
	public function alw_add_status_control_metabox() {
		add_meta_box(
			'alw_status_control_metabox',
			__( 'Listing Status Control', 'alw-listings' ),
			[ $this, 'alw_display_status_control_metabox' ],
			'listing',
			'side',
			'high'
		);
	}

	public function alw_display_status_control_metabox( WP_Post $post ) {
		wp_nonce_field( 'alw_save_status_metabox', 'alw_status_nonce' );
		$current = $post->post_status;
		$statuses = [
			'pending'             => __( 'Pending Review', 'alw-listings' ),
			'alw_pending_payment' => __( 'Pending Payment', 'alw-listings' ),
			'alw_active'          => __( 'Active (Publicly Visible)', 'alw-listings' ),
			'alw_inactive'        => __( 'Inactive (Hidden)', 'alw-listings' ),
		];
		echo '<p><strong>' . esc_html__( 'Set the status for this listing:', 'alw-listings' ) . '</strong></p>';
		foreach ( $statuses as $value => $label ) {
			echo '<label style="display:block;margin:8px 0;">';
			echo '<input type="radio" name="_alw_listing_status_control" value="' . esc_attr( $value ) . '" ' . checked( $current, $value, false ) . '> ';
			echo esc_html( $label );
			echo '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Select a status then click Update to save.', 'alw-listings' ) . '</p>';
	}

	public function alw_save_listing_status_from_metabox( array $data, array $postarr ): array {
		if ( ! isset( $_POST['alw_status_nonce'] ) || ! wp_verify_nonce( $_POST['alw_status_nonce'], 'alw_save_status_metabox' ) ) return $data;
		if ( $data['post_type'] !== 'listing' || ! current_user_can( 'edit_post', $postarr['ID'] ) ) return $data;
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return $data;

		if ( isset( $_POST['_alw_listing_status_control'] ) ) {
			$new_status = sanitize_key( $_POST['_alw_listing_status_control'] );
			$allowed    = [ 'pending', 'alw_pending_payment', 'alw_active', 'alw_inactive' ];
			if ( in_array( $new_status, $allowed, true ) ) {
				$data['post_status'] = $new_status;
			}
		}
		return $data;
	}

	// -------------------------------------------------------------------------
	// Admin list view enhancements
	// -------------------------------------------------------------------------
	public function alw_display_listing_states( array $post_states, WP_Post $post ): array {
		if ( get_post_type( $post->ID ) !== 'listing' ) return $post_states;

		$custom = [
			'alw_active'          => __( 'Active', 'alw-listings' ),
			'alw_inactive'        => __( 'Inactive', 'alw-listings' ),
			'alw_pending_payment' => __( 'Pending Payment', 'alw-listings' ),
		];

		if ( isset( $custom[ $post->post_status ] ) ) {
			$post_states[ $post->post_status ] = $custom[ $post->post_status ];
		} elseif ( $post->post_status === 'pending' ) {
			$post_states['pending'] = __( 'Pending Review', 'alw-listings' );
		}

		return $post_states;
	}

	public function alw_add_custom_post_status_to_admin_list( WP_Query $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || $query->get( 'post_type' ) !== 'listing' ) return;
		$current = $query->get( 'post_status' );
		if ( empty( $current ) || $current === 'all' ) {
			$query->set( 'post_status', array_merge(
				get_post_stati(),
				[ 'alw_active', 'alw_inactive', 'alw_pending_payment' ]
			) );
		}
	}
}
