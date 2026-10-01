<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Pangaea_Mobile_API {
	const NS = 'pangaea-mobile/v1';
	const REFRESH_META = '_pangaea_mobile_refresh_tokens';
	const DEVICES_META = '_pangaea_mobile_push_devices';
	const NOTIFICATION_META = '_pangaea_mobile_notification_preferences';
	const NOTIFICATIONS_FEED_META = '_pangaea_mobile_notifications_feed';
	const NOTIFICATIONS_FEED_CAP  = 200;
	const TRAVELLERS_META = '_pangaea_mobile_saved_travellers';
	// Reuses WTE's own real meta key (not a mobile-only shadow copy) so a
	// trip wishlisted on the website shows up in the app and vice versa.
	const WISHLIST_META = 'wptravelengine_wishlists';
	const PROFILE_META = '_pangaea_mobile_profile';
	const HEALTH_META = '_pangaea_mobile_health';
	const CONSENTS_META = '_pangaea_mobile_consents';
	const CART_META = '_pangaea_mobile_cart';
	const ORDER_PRODUCT_OPTION = 'pangaea_mobile_booking_product_id';
	const BADGE_LABEL_META = '_pangaea_mobile_badge_label';
	const BADGE_DATE_META = '_pangaea_mobile_badge_date';
	const IS_BOOKABLE_META = '_pangaea_mobile_is_bookable';
	const CARD_FIELDS_NONCE = 'pangaea_mobile_card_fields_nonce';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ), 20 );
		add_action( 'init', array( __CLASS__, 'register_card_meta' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_card_fields_metabox' ), 20 );
		add_action( 'save_post_trip', array( __CLASS__, 'save_card_fields' ), 20, 2 );
		// Same real event pangaea-booking-ticket.php already uses to send the
		// QR ticket email — piggybacking on it here means a notification only
		// ever fires for a genuine booking confirmation, not a guessed one.
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'notify_booking_confirmed' ), 20, 2 );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'prevent_edge_caching' ), 10, 4 );
	}

	/**
	 * This site's Kinsta hosting (with Cloudflare in front of it) edge-caches
	 * GET responses for up to an hour by default (`s-maxage=3600`), with no
	 * awareness of REST API routes specifically — it caches this API's GET
	 * endpoints the same as any other page. Kinsta's autopurge only clears a
	 * post's own permalink on a normal wp-admin edit, never this API's
	 * response for that same post, and a direct DB/meta update (e.g. via
	 * WP-CLI) doesn't trigger autopurge at all. Confirmed live: a real data
	 * fix and multiple already-deployed field additions all appeared "not
	 * live" to the mobile team for up to an hour after deploying, purely
	 * from stale edge-cached responses — the code was correct the whole
	 * time. Explicit no-store tells any standards-compliant HTTP cache
	 * (Cloudflare, Kinsta's edge, the app's own HTTP client) never to store
	 * a response from this API at all, so every deploy is visible
	 * immediately without a manual cache purge.
	 */
	public static function prevent_edge_caching( $served, $result, $request, $server ) {
		if ( 0 === strpos( $request->get_route(), '/' . self::NS ) ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
		}
		return $served;
	}

	public static function notify_booking_confirmed( $order_id, $order = null ) {
		if ( ! $order && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order || ! method_exists( $order, 'get_customer_id' ) ) {
			return;
		}
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id <= 0 ) {
			return; // Guest checkout — no app account to notify.
		}
		self::notify_user(
			$customer_id,
			'booking_confirmed',
			__( 'Booking confirmed', 'pangaea-mobile' ),
			sprintf( __( 'Your booking #%d is confirmed. Check My Trips for details.', 'pangaea-mobile' ), $order_id ),
			array( 'order_id' => (int) $order_id )
		);
	}

	/**
	 * Append a notification to a user's feed. Public so other mu-plugins can
	 * call Pangaea_Mobile_API::notify_user(...) directly for their own real
	 * events (custom-trip status changes, price drops, etc.) without each one
	 * reinventing storage — this is the only place the feed is written.
	 */
	public static function notify_user( int $user_id, string $type, string $title, string $body, array $data = array() ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		$feed   = get_user_meta( $user_id, self::NOTIFICATIONS_FEED_META, true );
		$feed   = is_array( $feed ) ? $feed : array();
		$feed[] = array(
			'id'         => wp_generate_uuid4(),
			'type'       => sanitize_key( $type ),
			'title'      => $title,
			'body'       => $body,
			'data'       => $data,
			'read'       => false,
			'created_at' => current_time( 'mysql' ),
		);
		// Bounded so a very active account's meta row can't grow forever —
		// oldest entries drop off first.
		if ( count( $feed ) > self::NOTIFICATIONS_FEED_CAP ) {
			$feed = array_slice( $feed, -self::NOTIFICATIONS_FEED_CAP );
		}
		update_user_meta( $user_id, self::NOTIFICATIONS_FEED_META, $feed );
	}

	public static function register_card_meta() {
		register_post_meta(
			'trip',
			self::BADGE_LABEL_META,
			array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
				'auth_callback'     => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		register_post_meta(
			'trip',
			self::BADGE_DATE_META,
			array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_badge_date' ),
				'show_in_rest'      => true,
				'auth_callback'     => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		register_post_meta(
			'trip',
			self::IS_BOOKABLE_META,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
				'default'           => false,
				'auth_callback'     => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	public static function register_card_fields_metabox() {
		add_meta_box(
			'pangaea_mobile_card_fields',
			'PANGAEA Mobile Card',
			array( __CLASS__, 'render_card_fields_metabox' ),
			'trip',
			'side',
			'default'
		);
	}

	public static function render_card_fields_metabox( $post ) {
		$badge_label = (string) get_post_meta( $post->ID, self::BADGE_LABEL_META, true );
		$badge_date  = self::sanitize_badge_date( get_post_meta( $post->ID, self::BADGE_DATE_META, true ) );
		$is_bookable = rest_sanitize_boolean( get_post_meta( $post->ID, self::IS_BOOKABLE_META, true ) );
		wp_nonce_field( self::CARD_FIELDS_NONCE, self::CARD_FIELDS_NONCE );
		?>
		<p>
			<label for="pangaea_mobile_badge_label"><strong>Featured badge label</strong></label>
			<input type="text" class="widefat" id="pangaea_mobile_badge_label" name="pangaea_mobile_badge_label" value="<?php echo esc_attr( $badge_label ); ?>" placeholder="TOP RATED">
			<small>Only shown on featured trips. Leave empty for the app default.</small>
		</p>
		<p>
			<label for="pangaea_mobile_badge_date"><strong>Badge date</strong></label>
			<input type="date" class="widefat" id="pangaea_mobile_badge_date" name="pangaea_mobile_badge_date" value="<?php echo esc_attr( $badge_date ); ?>">
			<small>An active sale badge takes priority over this date.</small>
		</p>
		<p>
			<label><input type="checkbox" name="pangaea_mobile_is_bookable" value="1" <?php checked( $is_bookable ); ?>> <strong>Show Book CTA in the mobile app</strong></label>
		</p>
		<?php
	}

	public static function save_card_fields( $post_id, $post ) {
		if ( ! $post instanceof WP_Post || 'trip' !== $post->post_type ||
			( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ||
			! current_user_can( 'edit_post', $post_id ) ||
			! isset( $_POST[ self::CARD_FIELDS_NONCE ] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::CARD_FIELDS_NONCE ] ) ), self::CARD_FIELDS_NONCE )
		) {
			return;
		}

		$badge_label = isset( $_POST['pangaea_mobile_badge_label'] ) ? sanitize_text_field( wp_unslash( $_POST['pangaea_mobile_badge_label'] ) ) : '';
		$badge_date  = isset( $_POST['pangaea_mobile_badge_date'] ) ? self::sanitize_badge_date( wp_unslash( $_POST['pangaea_mobile_badge_date'] ) ) : '';
		self::save_optional_meta( $post_id, self::BADGE_LABEL_META, $badge_label );
		self::save_optional_meta( $post_id, self::BADGE_DATE_META, $badge_date );
		update_post_meta( $post_id, self::IS_BOOKABLE_META, isset( $_POST['pangaea_mobile_is_bookable'] ) ? '1' : '0' );
	}

	public static function sanitize_badge_date( $value ) {
		$value = sanitize_text_field( (string) $value );
		$date  = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return $date && $date->format( 'Y-m-d' ) === $value ? $value : '';
	}

	private static function save_optional_meta( $post_id, $key, $value ) {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
			return;
		}
		update_post_meta( $post_id, $key, $value );
	}

	public static function register_routes() {
		self::route( '/auth/register/start', 'POST', 'auth_register_start', false );
		self::route( '/auth/register/verify', 'POST', 'auth_register_verify', false );
		self::route( '/auth/login', 'POST', 'auth_login', false );
		self::route( '/auth/google', 'POST', 'auth_google', false );
		self::route( '/auth/apple', 'POST', 'auth_apple', false );
		self::route( '/auth/me', 'GET', 'auth_me', true );
		self::route( '/auth/logout', 'POST', 'auth_logout', true );
		self::route( '/auth/refresh', 'POST', 'auth_refresh', false );
		self::route( '/auth/password/forgot', 'POST', 'auth_password_forgot', false );
		self::route( '/auth/password/change', 'POST', 'auth_password_change', true );

		self::route( '/user/profile', 'GET', 'get_profile', true );
		self::route( '/user/profile', 'PATCH', 'update_profile', true );
		self::route( '/user/billing', 'GET', 'get_billing', true );
		self::route( '/user/billing', 'PATCH', 'update_billing', true );
		self::route( '/user/health', 'GET', 'get_health', true );
		self::route( '/user/health', 'PATCH', 'update_health', true );
		self::route( '/user/health/passport', 'POST', 'upload_passport', true );
		self::route( '/user/avatar', 'POST', 'upload_avatar', true );
		self::route( '/user/avatar', 'DELETE', 'delete_avatar', true );

		self::route( '/travellers', 'GET', 'list_travellers', true );
		self::route( '/travellers', 'POST', 'create_traveller', true );
		self::route( '/travellers/(?P<traveller_id>[A-Za-z0-9_-]+)', 'GET', 'get_traveller', true );
		self::route( '/travellers/(?P<traveller_id>[A-Za-z0-9_-]+)', 'PATCH', 'update_traveller', true );
		self::route( '/travellers/(?P<traveller_id>[A-Za-z0-9_-]+)', 'DELETE', 'delete_traveller', true );
		self::route( '/travellers/(?P<traveller_id>[A-Za-z0-9_-]+)/passport', 'POST', 'upload_traveller_passport', true );

		self::route( '/device-token', 'POST', 'register_device_token', true );
		self::route( '/device-token', 'DELETE', 'unregister_device_token', true );

		self::route( '/user/wishlist', 'GET', 'get_wishlist', true );
		self::route( '/user/wishlist/(?P<trip_id>\d+)', 'POST', 'add_to_wishlist', true );
		self::route( '/user/wishlist/(?P<trip_id>\d+)', 'DELETE', 'remove_from_wishlist', true );

		self::route( '/home', 'GET', 'get_home', false );
		self::route( '/home/popups', 'GET', 'get_home_popups', false );
		self::route( '/onboarding', 'GET', 'get_onboarding', false );
		self::route( '/about', 'GET', 'get_about', false );
		self::route( '/destinations', 'GET', 'list_destinations', false );
		self::route( '/destinations/(?P<id_or_slug>[^/]+)', 'GET', 'get_destination', false );
		self::route( '/destinations/(?P<id_or_slug>[^/]+)/trips', 'GET', 'get_destination_trips', false );
		self::route( '/activities', 'GET', 'list_activities', false );
		self::route( '/activities/(?P<id_or_slug>[^/]+)', 'GET', 'get_activity', false );
		self::route( '/activities/(?P<id_or_slug>[^/]+)/trips', 'GET', 'get_activity_trips', false );

		self::route( '/trips', 'GET', 'list_trips', false );
		self::route( '/trips/search', 'GET', 'search_trips', false );
		self::route( '/search', 'GET', 'search_all', false );
		self::route( '/trips/filters', 'GET', 'trip_filters', false );
		self::route( '/trips/calendar', 'GET', 'trip_calendar', false );
		self::route( '/trips/(?P<trip_id>\d+)', 'GET', 'get_trip', false );
		self::route( '/trips/(?P<trip_id>\d+)/itinerary', 'GET', 'get_trip_itinerary', false );
		self::route( '/trips/(?P<trip_id>\d+)/gallery', 'GET', 'get_trip_gallery', false );
		self::route( '/trips/(?P<trip_id>\d+)/faqs', 'GET', 'get_trip_faqs', false );
		self::route( '/trips/(?P<trip_id>\d+)/departures', 'GET', 'get_trip_departures', false );
		self::route( '/trips/(?P<trip_id>\d+)/packages', 'GET', 'get_trip_packages', false );
		self::route( '/trips/(?P<trip_id>\d+)/addons', 'GET', 'get_trip_addons', false );
		self::route( '/trips/(?P<trip_id>\d+)/guides', 'GET', 'get_trip_guides', false );
		self::route( '/trips/(?P<trip_id>\d+)/suggested-flights', 'GET', 'get_trip_suggested_flights', false );
		self::route( '/trips/(?P<trip_id>\d+)/enquiry', 'POST', 'trip_enquiry', false );
		self::route( '/trips/(?P<trip_id>\d+)/local-enquiry', 'POST', 'trip_local_enquiry', false );
		self::route( '/trips/(?P<trip_id>\d+)/enquiry-fields', 'GET', 'get_trip_enquiry_fields', false );
		self::route( '/contact', 'GET', 'get_contact_channels', false );

		self::route( '/experiences', 'GET', 'list_experiences', false );
		self::route( '/experiences/filters', 'GET', 'experience_filters', false );
		self::route( '/experiences/(?P<experience_id>\d+)', 'GET', 'get_experience', false );
		self::route( '/experiences/(?P<experience_id>\d+)/quote', 'POST', 'experience_quote', true );
		self::route( '/experiences/checkout/orders', 'POST', 'checkout_create_experience_order', true );
		self::route( '/experiences/(?P<experience_id>\d+)/local-enquiry', 'POST', 'experience_local_enquiry', false );

		self::route( '/quote', 'POST', 'quote', false );
		self::route( '/cart', 'GET', 'cart_get', true );
		self::route( '/cart/items', 'POST', 'cart_add_item', true );
		self::route( '/cart/coupons', 'POST', 'cart_apply_coupon', true );
		self::route( '/cart/coupons/(?P<code>[^/]+)', 'DELETE', 'cart_remove_coupon', true );
		self::route( '/checkout/schema', 'GET', 'checkout_schema', false );
		self::route( '/checkout/payment-modes', 'GET', 'checkout_payment_modes', false );
		self::route( '/checkout/validate', 'POST', 'checkout_validate', true );
		self::route( '/checkout/orders', 'POST', 'checkout_create_order', true );
		self::route( '/checkout/orders/(?P<order_id>\d+)', 'GET', 'checkout_order', true );
		self::route( '/orders/(?P<order_id>\d+)/passport', 'POST', 'upload_order_passport', true );
		self::route( '/orders/(?P<order_id>\d+)/travellers', 'POST', 'save_order_travellers', true );

		self::route( '/payments/cards', 'GET', 'list_saved_cards', true );
		self::route( '/payments/cards/(?P<token_id>\d+)', 'DELETE', 'delete_saved_card', true );
		self::route( '/payments/tap/session', 'POST', 'payment_tap_session', true );
		self::route( '/payments/tap/verify', 'POST', 'payment_tap_verify', false );
		self::route( '/payments/paytabs/session', 'POST', 'payment_paytabs_session', true );
		self::route( '/payments/paytabs/verify', 'POST', 'payment_paytabs_verify', false );
		self::route( '/webhooks/paytabs', 'POST', 'webhook_paytabs', false );
		self::route( '/payments/tamara/options', 'GET', 'payment_tamara_options', false );
		self::route( '/payments/tamara/session', 'POST', 'payment_tamara_session', true );
		self::route( '/payments/tamara/verify', 'POST', 'payment_tamara_verify', false );
		self::route( '/webhooks/tamara', 'POST', 'webhook_tamara', false );

		self::route( '/bookings/(?P<booking_id>\d+)', 'GET', 'get_booking', true );
		self::route( '/bookings/(?P<booking_id>\d+)/payments', 'GET', 'get_booking_payments', true );
		self::route( '/bookings/(?P<booking_id>\d+)/pay-balance/session', 'POST', 'pay_booking_balance', true );
		self::route( '/bookings/(?P<booking_id>\d+)/cancel-request', 'POST', 'booking_cancel_request', true );
		self::route( '/me/trips', 'GET', 'my_trips', true );
		self::route( '/me/trips/(?P<booking_id>\d+)', 'GET', 'my_trip', true );
		self::route( '/me/trips/(?P<booking_id>\d+)/ticket', 'GET', 'my_trip_ticket', true );
		self::route( '/guest/booking-lookup', 'POST', 'guest_booking_lookup', false );

		self::route( '/custom-trip/destinations', 'GET', 'custom_trip_destinations', false );
		self::route( '/custom-trip/addons', 'GET', 'custom_trip_addons', false );
		self::route( '/custom-trip/estimate', 'POST', 'custom_trip_estimate', false );
		self::route( '/custom-trip/ai-itinerary', 'POST', 'custom_trip_ai', false );
		self::route( '/custom-trip/submit', 'POST', 'custom_trip_submit', false );
		self::route( '/custom-trip/requests/(?P<request_id>\d+)', 'GET', 'custom_trip_request', true );

		self::route( '/pangawi/chat', 'POST', 'pangawi_chat', false );
		self::route( '/pangawi/event', 'POST', 'pangawi_event', false );
		self::route( '/pangawi/feedback', 'POST', 'pangawi_feedback', false );
		self::route( '/pangawi/booking-link', 'POST', 'pangawi_booking_link', true );

		self::route( '/pages/(?P<slug>[^/]+)', 'GET', 'get_page', false );
		self::route( '/blogs', 'GET', 'list_blogs', false );
		self::route( '/blogs/(?P<id_or_slug>[^/]+)', 'GET', 'get_blog', false );
		self::route( '/faqs', 'GET', 'list_faqs', false );
		self::route( '/team', 'GET', 'list_team', false );
		self::route( '/guides', 'GET', 'list_guides', false );
		self::route( '/stories-map', 'GET', 'stories_map', false );
		self::route( '/newsletter/subscribe', 'POST', 'newsletter_subscribe', false );
		self::route( '/newsletter/unsubscribe', 'POST', 'newsletter_unsubscribe', false );
		self::route( '/newsletter/status', 'GET', 'newsletter_status', false );
		self::route( '/forms/local-trip', 'POST', 'local_trip_form', false );
		self::route( '/social-proof', 'GET', 'social_proof', false );

		self::route( '/notifications', 'GET', 'list_notifications', true );
		self::route( '/notifications/(?P<notification_id>[A-Za-z0-9_-]+)/read', 'POST', 'mark_notification_read', true );
		self::route( '/notifications/read-all', 'POST', 'mark_all_notifications_read', true );
		self::route( '/notifications/devices', 'POST', 'notifications_register_device', true );
		self::route( '/notifications/devices/(?P<device_id>[A-Za-z0-9_-]+)', 'DELETE', 'notifications_delete_device', true );
		self::route( '/notifications/preferences', 'GET', 'notifications_get_preferences', true );
		self::route( '/notifications/preferences', 'PATCH', 'notifications_update_preferences', true );

		self::route( '/stays', 'GET', 'list_stays', false );
		self::route( '/stays/filters', 'GET', 'stays_filters', false );
		self::route( '/stays/locations', 'GET', 'list_stay_locations', false );
		self::route( '/stays/(?P<room_id>\d+)', 'GET', 'get_stay', false );
		self::route( '/policies', 'GET', 'policies', false );
		self::route( '/consents', 'POST', 'consents', true );
		self::route( '/analytics/events', 'POST', 'analytics_event', false );
		self::route( '/logs/client', 'POST', 'client_log', false );
		self::route( '/health', 'GET', 'health', false );
	}

	private static function route( $path, $methods, $callback, $auth ) {
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => array( __CLASS__, $callback ),
				'permission_callback' => $auth ? array( __CLASS__, 'permission_auth' ) : '__return_true',
			)
		);
	}

	public static function permission_auth( $request ) {
		$user_id = self::user_id_from_request( $request );
		if ( $user_id <= 0 ) {
			return new WP_Error( 'pangaea_mobile_unauthorized', 'Authentication required.', array( 'status' => 401 ) );
		}
		wp_set_current_user( $user_id );
		return true;
	}

	private static function request_data( $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			$data = $request->get_body_params();
		}
		if ( ! is_array( $data ) ) {
			$data = $request->get_params();
		}
		return is_array( $data ) ? $data : array();
	}

	private static function ok( $data = array(), $status = 200 ) {
		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			),
			$status
		);
	}

	private static function fail( $code, $message, $status = 400, $details = array() ) {
		return new WP_Error( $code, $message, array( 'status' => $status, 'details' => $details ) );
	}

	private static function lang( $request ) {
		$lang = sanitize_key( (string) ( $request->get_param( 'lang' ) ?: $request->get_header( 'x-pangaea-lang' ) ) );
		if ( ! in_array( $lang, array( 'en', 'ar' ), true ) ) {
			$lang = '';
		}
		if ( $lang && has_action( 'wpml_switch_language' ) ) {
			do_action( 'wpml_switch_language', $lang );
		}
		return $lang;
	}

	/**
	 * A trip is a real, separate WPML-translated post per language, so its
	 * title is already reliably in the right language once translated_id()
	 * resolves it. An Experience is one post with an optional `ar_title`
	 * meta override — some were only ever authored in Arabic, with no
	 * English title anywhere (no override needed because there was nothing
	 * to override), so asking for lang=en still hands back the only title
	 * that exists: Arabic. There's no way to invent the missing English
	 * name, and showing the wrong script violates an explicit requirement
	 * (an English-locale result must never show Arabic, or vice versa) —
	 * so a result whose actual title doesn't match the requested language
	 * is excluded from search/list results entirely, rather than shown
	 * incorrectly. Empty text is never judged either way (nothing to
	 * contradict the request).
	 */
	private static function text_matches_lang( $text, $lang ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return true;
		}
		$has_arabic = (bool) preg_match( '/[\x{0600}-\x{06FF}]/u', $text );
		return 'ar' === $lang ? $has_arabic : ! $has_arabic;
	}

	private static function translated_id( $id, $type, $lang ) {
		$id = (int) $id;
		if ( $id > 0 && $lang && has_filter( 'wpml_object_id' ) ) {
			$translated = (int) apply_filters( 'wpml_object_id', $id, $type, true, $lang );
			return $translated > 0 ? $translated : $id;
		}
		return $id;
	}

	private static function b64e( $value ) {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private static function b64d( $value ) {
		return base64_decode( strtr( $value, '-_', '+/' ) );
	}

	private static function sign( $header, $payload ) {
		return self::b64e( hash_hmac( 'sha256', $header . '.' . $payload, wp_salt( 'auth' ), true ) );
	}

	private static function make_token( $user_id, $type = 'access', $ttl = 86400 ) {
		$header  = self::b64e( wp_json_encode( array( 'alg' => 'HS256', 'typ' => 'JWT' ) ) );
		$payload = self::b64e(
			wp_json_encode(
				array(
					'uid'  => (int) $user_id,
					'type' => $type,
					'iat'  => time(),
					'exp'  => time() + absint( $ttl ),
					'jti'  => wp_generate_uuid4(),
				)
			)
		);
		return $header . '.' . $payload . '.' . self::sign( $header, $payload );
	}

	private static function verify_token( $token, $type = 'access' ) {
		$parts = explode( '.', (string) $token );
		if ( count( $parts ) !== 3 ) {
			return 0;
		}
		if ( ! hash_equals( self::sign( $parts[0], $parts[1] ), $parts[2] ) ) {
			return 0;
		}
		$payload = json_decode( self::b64d( $parts[1] ), true );
		if ( ! is_array( $payload ) ) {
			return 0;
		}
		if ( (string) ( $payload['type'] ?? '' ) !== $type ) {
			return 0;
		}
		if ( (int) ( $payload['exp'] ?? 0 ) < time() ) {
			return 0;
		}
		$user_id = (int) ( $payload['uid'] ?? 0 );
		return get_user_by( 'id', $user_id ) ? $user_id : 0;
	}

	private static function bearer_token( $request ) {
		$auth = (string) $request->get_header( 'authorization' );
		if ( preg_match( '/Bearer\s+(.+)/i', $auth, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}

	private static function user_id_from_request( $request ) {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			return $user_id;
		}
		return self::verify_token( self::bearer_token( $request ), 'access' );
	}

	private static function auth_payload( $user_id ) {
		$access  = self::make_token( $user_id, 'access', 86400 );
		$refresh = self::make_token( $user_id, 'refresh', 2592000 );
		self::store_refresh_token( $user_id, $refresh );
		return array(
			'access_token'  => $access,
			'refresh_token' => $refresh,
			'token_type'    => 'Bearer',
			'expires_in'    => 86400,
			'user'          => self::user_payload( $user_id ),
		);
	}

	private static function store_refresh_token( $user_id, $token ) {
		$tokens   = get_user_meta( $user_id, self::REFRESH_META, true );
		$tokens   = is_array( $tokens ) ? $tokens : array();
		$tokens[] = array(
			'hash'       => wp_hash_password( $token ),
			'created_at' => current_time( 'mysql' ),
		);
		$tokens = array_slice( $tokens, -5 );
		update_user_meta( $user_id, self::REFRESH_META, $tokens );
	}

	private static function refresh_token_exists( $user_id, $token ) {
		$tokens = get_user_meta( $user_id, self::REFRESH_META, true );
		$tokens = is_array( $tokens ) ? $tokens : array();
		foreach ( $tokens as $row ) {
			if ( ! empty( $row['hash'] ) && wp_check_password( $token, $row['hash'], $user_id ) ) {
				return true;
			}
		}
		return false;
	}

	private static function revoke_refresh_token( $user_id, $token ) {
		$tokens = get_user_meta( $user_id, self::REFRESH_META, true );
		$tokens = is_array( $tokens ) ? $tokens : array();
		$out    = array();
		foreach ( $tokens as $row ) {
			if ( empty( $row['hash'] ) || ! wp_check_password( $token, $row['hash'], $user_id ) ) {
				$out[] = $row;
			}
		}
		update_user_meta( $user_id, self::REFRESH_META, $out );
	}

	private static function user_payload( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return null;
		}
		// display_name defaults to the WP username for accounts created
		// directly in wp-admin (rather than through this API's own register/
		// social flows, which always set it properly) — prefer a real
		// first+last name whenever one exists, matching what Profile shows.
		$first_last = trim( get_user_meta( $user->ID, 'first_name', true ) . ' ' . get_user_meta( $user->ID, 'last_name', true ) );
		$name       = '' !== $first_last ? $first_last : $user->display_name;
		return array(
			'id'           => (int) $user->ID,
			'name'         => $name,
			'email'        => $user->user_email,
			'first_name'   => get_user_meta( $user->ID, 'first_name', true ),
			'last_name'    => get_user_meta( $user->ID, 'last_name', true ),
			'avatar_url'   => self::real_avatar_url( $user->ID ),
			'roles'        => array_values( (array) $user->roles ),
			'registered_at'=> $user->user_registered,
		);
	}

	/**
	 * Was checking a Google-login avatar meta key, then falling straight to
	 * Gravatar — never the real photo a customer uploads on the website's own
	 * "Account Details" > Upload Image field, which WP Travel Engine stores as
	 * an attachment ID under `wte_users_meta['user_profile_image_id']` (the
	 * SAME real storage upload_avatar()/delete_avatar() below read and write).
	 * Confirmed live: a real account's uploaded photo was invisible to the
	 * app for exactly this reason.
	 */
	private static function real_avatar_url( int $uid ): string {
		$wte_meta = get_user_meta( $uid, 'wte_users_meta', true );
		$image_id = is_array( $wte_meta ) ? (int) ( $wte_meta['user_profile_image_id'] ?? 0 ) : 0;
		if ( $image_id > 0 ) {
			$url = wp_get_attachment_image_url( $image_id, 'full' );
			if ( $url ) {
				return $url;
			}
		}
		$google_avatar = get_user_meta( $uid, 'pga_google_avatar', true );
		return $google_avatar ?: get_avatar_url( $uid );
	}

	private static function public_post_payload( $post, $lang = '' ) {
		if ( ! $post instanceof WP_Post ) {
			$post = get_post( $post );
		}
		if ( ! $post ) {
			return null;
		}
		$image_id = get_post_thumbnail_id( $post );
		return array(
			'id'          => (int) $post->ID,
			'type'        => $post->post_type,
			'slug'        => $post->post_name,
			'title'       => get_the_title( $post ),
			'excerpt'     => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'content'     => apply_filters( 'the_content', $post->post_content ),
			'image'       => $image_id ? self::image_payload( $image_id ) : null,
			'link'        => get_permalink( $post ),
			'date'        => get_the_date( 'c', $post ),
			'language'    => $lang,
		);
	}

	private static function image_payload( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return null;
		}
		return array(
			'id'    => $attachment_id,
			'url'   => wp_get_attachment_url( $attachment_id ),
			'alt'   => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'sizes' => array(
				'thumbnail' => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
				'medium'    => wp_get_attachment_image_url( $attachment_id, 'medium' ),
				'large'     => wp_get_attachment_image_url( $attachment_id, 'large' ),
				'full'      => wp_get_attachment_image_url( $attachment_id, 'full' ),
			),
		);
	}

	private static function term_payload( $term, $taxonomy = '', $lang = '' ) {
		if ( ! $term instanceof WP_Term ) {
			return null;
		}
		// Unlike a post, a taxonomy term isn't automatically returned in the
		// requested language just because self::lang() switched WPML's
		// active language earlier in the request — get_terms() has no
		// equivalent of translated_id() built in, so this was always
		// handing back whichever language the term happened to be queried
		// in (its "default"/original language), regardless of the app's
		// own language — every filter list (destinations, activities, trip
		// types, etc.) mixed languages depending on which term originally
		// got created in which language. Resolve to the real translated
		// term the same way translated_id() already does for posts.
		if ( $lang && $term->taxonomy && has_filter( 'wpml_object_id' ) ) {
			$translated_id = (int) apply_filters( 'wpml_object_id', $term->term_id, $term->taxonomy, false, $lang );
			if ( $translated_id > 0 && $translated_id !== $term->term_id ) {
				$translated_term = get_term( $translated_id, $term->taxonomy );
				if ( $translated_term instanceof WP_Term ) {
					$term = $translated_term;
				}
			}
		}
		if ( function_exists( 'pangaea_trip_data_destination' ) && 'destination' === $term->taxonomy ) {
			$payload = pangaea_trip_data_destination( $term );
			if ( is_array( $payload ) ) {
				$payload = array_merge( $payload, self::destination_geo_fields( $term->term_id ) );
			}
			return $payload;
		}
		// Same treatment for activities (id/parent/slug/name/description/url/
		// trip_count/image, plus the trip-photo fallback when there's no
		// dedicated category-image-id) — added for the /activities archive
		// endpoints; see pangaea_trip_data_activity() in
		// pangaea-trip-data-formatter.php.
		if ( function_exists( 'pangaea_trip_data_activity' ) && 'activities' === $term->taxonomy ) {
			$payload = pangaea_trip_data_activity( $term );
			return is_array( $payload ) ? $payload : null;
		}
		$image_id = (int) get_term_meta( $term->term_id, 'category-image-id', true );
		$payload  = array(
			'id'          => (int) $term->term_id,
			'parent'      => (int) $term->parent,
			'slug'        => $term->slug,
			'name'        => $term->name,
			'description' => wp_strip_all_tags( $term->description ),
			'taxonomy'    => $taxonomy ?: $term->taxonomy,
			'count'       => (int) $term->count,
			'image'       => $image_id ? self::image_payload( $image_id ) : null,
		);
		if ( 'destination' === ( $taxonomy ?: $term->taxonomy ) ) {
			$payload = array_merge( $payload, self::destination_geo_fields( $term->term_id ) );
		}
		return $payload;
	}

	/**
	 * lat/lng/region for a destination term.
	 * Term meta keys: pangaea_destination_lat, pangaea_destination_lng, pangaea_destination_region
	 * Returns null for each field until a value is entered in the admin.
	 */
	private static function destination_geo_fields( $term_id ) {
		$lat    = get_term_meta( $term_id, 'pangaea_destination_lat',    true );
		$lng    = get_term_meta( $term_id, 'pangaea_destination_lng',    true );
		$region = get_term_meta( $term_id, 'pangaea_destination_region', true );
		$lat    = '' !== $lat ? (float) $lat : null;
		$lng    = '' !== $lng ? (float) $lng : null;

		// No destination term has ever had its own lat/lng entered directly
		// (there's no admin UI field for it) — but individual trips tagged
		// with that destination often have a real Google Maps embed in
		// their own "Map iframe Code" field. Fall back to the first one
		// found rather than leaving the destination stuck at null forever.
		if ( null === $lat || null === $lng ) {
			$fallback = self::destination_latlng_from_trips( (int) $term_id );
			if ( $fallback ) {
				$lat = $lat ?? $fallback['lat'];
				$lng = $lng ?? $fallback['lng'];
			}
		}

		return array(
			'lat'    => $lat,
			'lng'    => $lng,
			'region' => '' !== $region ? (string) $region : null,
		);
	}

	private static function destination_latlng_from_trips( $term_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'trip',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'update_post_meta_cache' => true,
				'tax_query'      => array(
					array( 'taxonomy' => 'destination', 'field' => 'term_id', 'terms' => array( $term_id ) ),
				),
			)
		);
		foreach ( $ids as $trip_id ) {
			$geo = self::trip_latlng( (int) $trip_id );
			if ( null !== $geo['lat'] && null !== $geo['lng'] ) {
				return $geo;
			}
		}
		return null;
	}

	private static function trip_latlng( $trip_id ) {
		$settings = get_post_meta( $trip_id, 'wp_travel_engine_setting', true );
		$iframe   = is_array( $settings ) ? (string) ( $settings['map']['iframe'] ?? '' ) : '';
		return self::latlng_from_map_iframe( $iframe );
	}

	/**
	 * The trip "Map" meta box stores a pasted Google Maps embed <iframe>, not
	 * a plain lat/lng field. Google's embed URLs encode the coordinates in
	 * the `pb` parameter as `!2d{lng}!3d{lat}` — this is the one format seen
	 * across every trip checked live — with a plainer `?q=lat,lng` /
	 * `@lat,lng` URL as a fallback for a differently-pasted map link.
	 */
	private static function latlng_from_map_iframe( $iframe ) {
		$iframe = (string) $iframe;
		if ( '' !== $iframe && preg_match( '/!2d(-?[0-9]+\.[0-9]+)!3d(-?[0-9]+\.[0-9]+)/', $iframe, $m ) ) {
			return array( 'lat' => (float) $m[2], 'lng' => (float) $m[1] );
		}
		if ( '' !== $iframe && ( preg_match( '/[?&]q=(-?[0-9]+\.[0-9]+),(-?[0-9]+\.[0-9]+)/', $iframe, $m ) || preg_match( '/@(-?[0-9]+\.[0-9]+),(-?[0-9]+\.[0-9]+)/', $iframe, $m ) ) ) {
			return array( 'lat' => (float) $m[1], 'lng' => (float) $m[2] );
		}
		return array( 'lat' => null, 'lng' => null );
	}

	private static function trip_payload( $post, $detail = false, $lang = '' ) {
		if ( ! $post instanceof WP_Post ) {
			$post = get_post( $post );
		}
		if ( ! $post || 'trip' !== $post->post_type ) {
			return null;
		}
		if ( function_exists( 'pangaea_trip_data_trip' ) ) {
			$payload = pangaea_trip_data_trip( $post );
			if ( is_array( $payload ) ) {
				$payload = self::add_mobile_card_fields( $payload, $post->ID );
				if ( $detail ) {
					$payload['packages']            = self::trip_packages_payload( $post->ID );
					// pangaea_trip_data_trip()'s own `packages[].departures` already
					// has this same data per-package — this flat, top-level array is
					// added for parity with the other trip_payload() branch below
					// (which never goes through pangaea_trip_data_trip() at all), so
					// the app has one consistent place to read departure dates from
					// regardless of which internal path a given trip happens to hit.
					$payload['departures']           = self::departures_payload( $post->ID );
					$payload['suggested_flights']    = self::suggested_flights_payload( $post->ID );
					$payload['travel_requirements']  = self::travel_requirements_payload( $post->ID );
					$payload['guides']              = self::trip_guides_payload( $post->ID, $lang );
				}
				return $payload;
			}
		}
		$settings = get_post_meta( $post->ID, 'wp_travel_engine_setting', true );
		$settings = is_array( $settings ) ? $settings : array();
		$image_id = get_post_thumbnail_id( $post );
		$terms    = array();
		foreach ( array( 'destination', 'activities', 'trip_types', 'difficulty', 'trip_tag', 'travelstyle' ) as $tax ) {
			$terms[ $tax ] = array();
			$items = get_the_terms( $post, $tax );
			if ( is_array( $items ) ) {
				foreach ( $items as $term ) {
					$terms[ $tax ][] = self::term_payload( $term, $tax, $lang );
				}
			}
		}
		$payload = array(
			'id'          => (int) $post->ID,
			'slug'        => $post->post_name,
			'title'       => get_the_title( $post ),
			'excerpt'     => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'content'     => $detail ? apply_filters( 'the_content', $post->post_content ) : '',
			'image'       => $image_id ? self::image_payload( $image_id ) : null,
			'link'        => get_permalink( $post ),
			'price'       => (float) ( get_post_meta( $post->ID, 'wp_travel_engine_setting_trip_price', true ) ?: ( $settings['trip_price'] ?? 0 ) ),
			'currency'    => function_exists( 'wptravelengine_currency_code' ) ? wptravelengine_currency_code() : ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'SAR' ),
			'duration'    => $settings['trip_duration'] ?? '',
			'terms'       => $terms,
			'language'    => $lang,
		);
		$payload = self::add_mobile_card_fields( $payload, $post->ID );
		if ( $detail ) {
			$payload['settings']            = $settings;
			$payload['packages']            = self::trip_packages_payload( $post->ID );
			$payload['departures']          = self::departures_payload( $post->ID );
			$payload['gallery']             = self::gallery_payload( $post->ID );
			$payload['itinerary']           = self::itinerary_payload( $post->ID );
			$payload['suggested_flights']   = self::suggested_flights_payload( $post->ID );
			$payload['travel_requirements'] = self::travel_requirements_payload( $post->ID );
			$payload['guides']              = self::trip_guides_payload( $post->ID, $lang );
		}
		return $payload;
	}

	private static function add_mobile_card_fields( $payload, $trip_id ) {
		$badge_label = trim( (string) get_post_meta( $trip_id, self::BADGE_LABEL_META, true ) );
		$badge_date  = self::sanitize_badge_date( get_post_meta( $trip_id, self::BADGE_DATE_META, true ) );
		$payload['badge_label'] = '' !== $badge_label ? $badge_label : null;
		$payload['badge_date']  = '' !== $badge_date ? $badge_date : null;

		// is_bookable used to be computed purely from departure/seat data, with
		// no regard for is_coming_soon (already sitting right here in $payload,
		// set upstream by pangaea_trip_data_trip()) or the site's Enquiry-only
		// override — so a Coming-Soon trip could come back with
		// is_coming_soon:true AND is_bookable:true at once, and an Enquiry-only
		// trip had no signal at all telling the app it isn't really bookable
		// (confirmed live on real trips of both kinds). booking_type now gives
		// the app one unambiguous field instead of three that could disagree.
		//
		// Local Form ('_pangaea_trip_enquiry' === 'internal') used to be
		// silently folded into generic Enquiry here — 'internal' !== 'enable'
		// and !== 'disable', so it fell through to the sitewide default branch
		// exactly like an unset trip. That's wrong: the Local Form
		// (Pangaea_Trip_Local_Forms, CPT pangaea_local_enq) is a completely
		// different submission path/email system from WTE's own generic
		// Enquiry (EnquiryMail/Enquiry CPT). It now gets its own top-priority
		// state, same as is_coming_soon.
		$is_coming_soon  = ! empty( $payload['is_coming_soon'] );
		$is_local_form   = ! $is_coming_soon && self::trip_is_local_form( $trip_id );
		$is_enquiry_only = ! $is_coming_soon && ! $is_local_form && self::trip_is_enquiry_only( $trip_id );
		$has_open_seats  = self::compute_is_bookable( $trip_id );

		$payload['is_coming_soon']  = $is_coming_soon;
		$payload['is_local_form']   = $is_local_form;
		$payload['is_enquiry_only'] = $is_enquiry_only;
		$payload['is_bookable']     = ! $is_coming_soon && ! $is_local_form && ! $is_enquiry_only && $has_open_seats;
		$payload['booking_type']    = $is_coming_soon
			? 'coming_soon'
			: ( $is_local_form ? 'local_form' : ( $is_enquiry_only ? 'enquiry' : ( $has_open_seats ? 'book_now' : 'sold_out' ) ) );

		$geo = self::trip_latlng( $trip_id );
		$payload['lat'] = $geo['lat'];
		$payload['lng'] = $geo['lng'];
		return $payload;
	}

	/**
	 * True only for '_pangaea_trip_enquiry' === 'internal' — the Local Form
	 * (Pangaea_Trip_Local_Forms, mu-plugins/pangaea-trip-local-forms.php),
	 * a distinct submission path/CPT/email system from WTE's own generic
	 * Enquiry. Takes priority over trip_is_enquiry_only() below.
	 */
	private static function trip_is_local_form( $trip_id ) {
		return 'internal' === (string) get_post_meta( $trip_id, '_pangaea_trip_enquiry', true );
	}

	/**
	 * Mirrors the website's own resolution order (theme override
	 * wp-content/themes/tourm/wp-travel-engine/single-trip/trip-sidebar.php):
	 * a per-trip override wins outright ('enable' forces Enquiry, 'disable'
	 * forces a real Book Now, 'internal' forces the Local Form — handled by
	 * trip_is_local_form() above, checked by the caller before this runs).
	 * '' (unset) and 'domestic' both fall through to the sitewide WP Travel
	 * Engine default, whose 'enquiry' option is empty on this site — meaning
	 * Enquiry is the sitewide default unless a trip explicitly opts out with
	 * 'disable'.
	 *
	 * NEEDS SITE OWNER CONFIRMATION: 'domestic' ("Domestic Trip Form" in the
	 * admin dropdown, pangaea-wte-trip-enquiry-toggle.php) is not wired to any
	 * distinct behavior anywhere in the codebase — confirmed by reading the
	 * toggle mu-plugin, this file, and pangaea-trip-local-forms.php. It
	 * currently falls back to generic Enquiry, same as unset, purely because
	 * nothing else claims it.
	 */
	private static function trip_is_enquiry_only( $trip_id ) {
		$override = (string) get_post_meta( $trip_id, '_pangaea_trip_enquiry', true );
		if ( 'enable' === $override ) {
			return true;
		}
		if ( 'disable' === $override ) {
			return false;
		}
		if ( 'internal' === $override ) {
			// Handled by trip_is_local_form(); never generic Enquiry.
			return false;
		}
		$global = get_option( 'wp_travel_engine_settings' );
		$global = is_array( $global ) ? $global : array();
		return empty( $global['enquiry'] );
	}

	/**
	 * is_bookable used to read a manual per-trip wp-admin checkbox
	 * (IS_BOOKABLE_META) that nobody had ever checked for any trip — every
	 * trip detail/list response reported false regardless of real
	 * bookability. Computed instead, from the same package/departure data
	 * the app already shows: true when at least one package has a future
	 * departure with seats left. The metabox checkbox (and its meta) is left
	 * in place but no longer read here.
	 */
	private static function compute_is_bookable( $trip_id ) {
		$today = wp_date( 'Y-m-d' );
		foreach ( self::trip_packages_payload( $trip_id ) as $package ) {
			foreach ( (array) ( $package['departures'] ?? array() ) as $departure ) {
				if ( ! is_array( $departure ) ) {
					continue;
				}
				$date = (string) ( $departure['start_date'] ?? $departure['date'] ?? '' );
				if ( '' === $date || substr( $date, 0, 10 ) < $today ) {
					continue;
				}
				// A null seats_left/capacity means "no cap configured" (this
				// site's own uncapped-departure semantics), not zero seats —
				// treating it as 0 would misreport a genuinely open, unlimited
				// departure as sold out.
				$seats_left = $departure['seats_left'] ?? null;
				$capacity   = $departure['capacity'] ?? null;
				if ( null === $seats_left && null === $capacity ) {
					return true;
				}
				$seats = null !== $seats_left ? (int) $seats_left : (int) $capacity;
				if ( $seats > 0 ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function posts_query_payload( $post_type, $request, $mapper ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 50, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 12 ) ) );
		$args     = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			's'              => sanitize_text_field( (string) $request->get_param( 'search' ) ),
		);
		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = call_user_func( $mapper, $post );
		}
		return array(
			'items'      => array_values( array_filter( $items ) ),
			'pagination' => array(
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
			),
		);
	}

	public static function auth_register_start( $request ) {
		$data  = self::request_data( $request );
		$email = sanitize_email( (string) ( $data['email'] ?? '' ) );
		$name  = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		$pass  = (string) ( $data['password'] ?? '' );
		if ( ! is_email( $email ) ) {
			return self::fail( 'invalid_email', 'Valid email is required.' );
		}
		if ( email_exists( $email ) ) {
			return self::fail( 'email_exists', 'This email is already registered.', 409 );
		}
		if ( strlen( $pass ) < 8 ) {
			return self::fail( 'weak_password', 'Password must be at least 8 characters.' );
		}

		// Prevents spamming the same address with repeated verification-code
		// emails (cost + a real email-bombing nuisance for the victim).
		$cooldown_key = 'pangaea_mobile_register_cooldown_' . md5( strtolower( $email ) );
		if ( get_transient( $cooldown_key ) ) {
			return self::fail( 'too_many_requests', 'Please wait a moment before requesting another code.', 429 );
		}
		set_transient( $cooldown_key, 1, 45 );

		$otp = (string) wp_rand( 100000, 999999 );
		set_transient(
			'pangaea_mobile_register_' . md5( strtolower( $email ) ),
			array(
				'email'    => $email,
				'name'     => $name,
				'password' => $pass,
				'otp'      => $otp,
				'attempts' => 0,
			),
			10 * MINUTE_IN_SECONDS
		);
		wp_mail( $email, 'Your PANGAEA verification code', 'Your verification code is: ' . $otp );
		return self::ok( array( 'email_masked' => self::mask_email( $email ), 'expires_in' => 600 ) );
	}

	public static function auth_register_verify( $request ) {
		$data  = self::request_data( $request );
		$email = sanitize_email( (string) ( $data['email'] ?? '' ) );
		$otp   = preg_replace( '/\D+/', '', (string) ( $data['otp'] ?? '' ) );
		$key   = 'pangaea_mobile_register_' . md5( strtolower( $email ) );
		$row   = get_transient( $key );
		if ( ! is_array( $row ) || ! is_email( $email ) ) {
			return self::fail( 'otp_expired', 'Verification code expired or invalid.', 410 );
		}
		if ( (int) ( $row['attempts'] ?? 0 ) >= 5 ) {
			delete_transient( $key );
			return self::fail( 'otp_locked', 'Too many attempts.', 429 );
		}
		if ( (string) $row['otp'] !== $otp ) {
			$row['attempts'] = (int) ( $row['attempts'] ?? 0 ) + 1;
			set_transient( $key, $row, 10 * MINUTE_IN_SECONDS );
			return self::fail( 'otp_invalid', 'Invalid verification code.' );
		}
		if ( email_exists( $email ) ) {
			delete_transient( $key );
			return self::fail( 'email_exists', 'This email is already registered.', 409 );
		}
		$user_id = wp_create_user( $email, (string) $row['password'], $email );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		$name_parts = self::split_full_name( (string) ( $row['name'] ?? '' ) );
		wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => $row['name'] ?: $email,
				'first_name'   => $name_parts['first'],
				'last_name'    => $name_parts['last'],
			)
		);
		delete_transient( $key );
		return self::ok( self::auth_payload( $user_id ), 201 );
	}

	/**
	 * Registration only collects one free-text "name" field, not separate
	 * first/last inputs, so split it here rather than dumping the whole
	 * string into first_name and leaving last_name empty — that previously
	 * left every new account with no last name on record.
	 */
	private static function split_full_name( $full_name ) {
		$full_name = trim( $full_name );
		if ( '' === $full_name ) {
			return array( 'first' => '', 'last' => '' );
		}
		$parts = preg_split( '/\s+/', $full_name );
		$first = array_shift( $parts );
		return array( 'first' => $first, 'last' => implode( ' ', $parts ) );
	}

	public static function auth_login( $request ) {
		$data  = self::request_data( $request );
		$email = sanitize_email( (string) ( $data['email'] ?? '' ) );
		$pass  = (string) ( $data['password'] ?? '' );

		if ( ! is_email( $email ) ) {
			return self::fail( 'invalid_credentials', 'Invalid email or password.', 401 );
		}

		// Same transient-based throttle pattern as the OTP attempt limiter
		// below — wp_authenticate() has no brute-force protection of its own,
		// and no login-limiting plugin is active on this site.
		$throttle_key = 'pangaea_mobile_login_fail_' . md5( strtolower( $email ) );
		if ( (int) get_transient( $throttle_key ) >= 8 ) {
			return self::fail( 'too_many_attempts', 'Too many login attempts. Please try again in a few minutes.', 429 );
		}

		$user = wp_authenticate( $email, $pass );
		if ( is_wp_error( $user ) ) {
			set_transient( $throttle_key, (int) get_transient( $throttle_key ) + 1, 15 * MINUTE_IN_SECONDS );
			return self::fail( 'invalid_credentials', 'Invalid email or password.', 401 );
		}

		delete_transient( $throttle_key );
		return self::ok( self::auth_payload( $user->ID ) );
	}

	public static function auth_google( $request ) {
		$data     = self::request_data( $request );
		$id_token = sanitize_text_field( (string) ( $data['id_token'] ?? '' ) );
		if ( '' === $id_token ) {
			return self::fail( 'missing_token', 'Google ID token is required.' );
		}
		$response = wp_remote_get( 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode( $id_token ), array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) ) {
			return self::fail( 'google_unavailable', 'Could not verify Google token.', 502 );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['email'] ) || ( isset( $body['email_verified'] ) && 'true' !== (string) $body['email_verified'] ) ) {
			return self::fail( 'google_invalid', 'Invalid Google token.', 401 );
		}
		// Fail CLOSED, not open: without this, an id_token issued by Google for
		// any unrelated app (not just this one) would be accepted here as long
		// as its email is verified — a known "token confusion" flaw. Previously
		// this check only ran when pga_google_client_id happened to be set;
		// now a missing/misconfigured client id rejects the login instead of
		// silently skipping the audience check.
		$client_id = (string) get_option( 'pga_google_client_id', '' );
		if ( '' === $client_id ) {
			return self::fail( 'google_not_configured', 'Google sign-in is not available right now.', 503 );
		}
		if ( empty( $body['aud'] ) || $client_id !== (string) $body['aud'] ) {
			return self::fail( 'google_audience', 'Google token audience mismatch.', 401 );
		}
		$email = sanitize_email( (string) $body['email'] );
		$user  = get_user_by( 'email', $email );
		if ( ! $user ) {
			$user_id = wp_create_user( $email, wp_generate_password( 24, true, true ), $email );
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
			wp_update_user(
				array(
					'ID'           => $user_id,
					'display_name' => sanitize_text_field( (string) ( $body['name'] ?? $email ) ),
					'first_name'   => sanitize_text_field( (string) ( $body['given_name'] ?? '' ) ),
					'last_name'    => sanitize_text_field( (string) ( $body['family_name'] ?? '' ) ),
				)
			);
		} else {
			$user_id = (int) $user->ID;
		}
		if ( ! empty( $body['sub'] ) ) {
			update_user_meta( $user_id, 'pga_google_sub', sanitize_text_field( (string) $body['sub'] ) );
		}
		if ( ! empty( $body['picture'] ) ) {
			update_user_meta( $user_id, 'pga_google_avatar', esc_url_raw( (string) $body['picture'] ) );
		}
		return self::ok( self::auth_payload( $user_id ) );
	}

	/**
	 * Sign in with Apple. The identity token is a JWT signed by Apple with
	 * RS256, verified here against Apple's own rotating public keys — the
	 * same trust model as auth_google() above (fetch the provider's keys,
	 * verify the signature, then fail closed on audience/issuer/expiry)
	 * rather than trusting anything the client claims about the token.
	 * Apple only returns the user's name on the FIRST authorization (never
	 * inside the token itself), so the app must pass it in `first_name`/
	 * `last_name` on that first call — trusted only for display, never for
	 * identity, which always comes from the verified token's `sub`/`email`.
	 */
	public static function auth_apple( $request ) {
		$data     = self::request_data( $request );
		$id_token = sanitize_text_field( (string) ( $data['id_token'] ?? '' ) );
		if ( '' === $id_token ) {
			return self::fail( 'missing_token', 'Apple identity token is required.' );
		}

		// Fail CLOSED: without a configured audience, any Apple id_token for
		// any unrelated app would otherwise verify successfully (same class
		// of "token confusion" bug the Google audience check guards above).
		$allowed_auds = array_filter( array_map( 'trim', explode( ',', (string) get_option( 'pga_apple_client_ids', '' ) ) ) );
		if ( empty( $allowed_auds ) ) {
			return self::fail( 'apple_not_configured', 'Apple sign-in is not available right now.', 503 );
		}

		$claims = self::verify_apple_id_token( $id_token );
		if ( is_wp_error( $claims ) ) {
			return $claims;
		}
		if ( empty( $claims['iss'] ) || 'https://appleid.apple.com' !== $claims['iss'] ) {
			return self::fail( 'apple_invalid', 'Invalid Apple token issuer.', 401 );
		}
		if ( empty( $claims['aud'] ) || ! in_array( (string) $claims['aud'], $allowed_auds, true ) ) {
			return self::fail( 'apple_audience', 'Apple token audience mismatch.', 401 );
		}
		if ( empty( $claims['sub'] ) ) {
			return self::fail( 'apple_invalid', 'Invalid Apple token.', 401 );
		}

		$apple_sub = sanitize_text_field( (string) $claims['sub'] );
		$email     = ! empty( $claims['email'] ) ? sanitize_email( (string) $claims['email'] ) : '';

		$users = get_users(
			array(
				'meta_key'   => 'pga_apple_sub',
				'meta_value' => $apple_sub,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		$user_id = ! empty( $users ) ? (int) $users[0] : 0;

		if ( ! $user_id && $email && is_email( $email ) ) {
			$existing = get_user_by( 'email', $email );
			if ( $existing ) {
				$user_id = (int) $existing->ID;
			}
		}

		if ( ! $user_id ) {
			if ( ! $email || ! is_email( $email ) ) {
				return self::fail( 'apple_email_missing', 'Apple did not provide an email for this account.', 422 );
			}
			$first = sanitize_text_field( (string) ( $data['first_name'] ?? '' ) );
			$last  = sanitize_text_field( (string) ( $data['last_name'] ?? '' ) );
			$name  = trim( $first . ' ' . $last ) ?: $email;
			$user_id = wp_create_user( $email, wp_generate_password( 24, true, true ), $email );
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
			wp_update_user(
				array(
					'ID'           => $user_id,
					'display_name' => $name,
					'first_name'   => $first,
					'last_name'    => $last,
				)
			);
		}

		update_user_meta( $user_id, 'pga_apple_sub', $apple_sub );
		return self::ok( self::auth_payload( $user_id ) );
	}

	/**
	 * Verifies an Apple identity token's signature against Apple's published
	 * JWKS (cached 1h — the same keys serve every request; Apple rotates
	 * them infrequently and always overlaps old/new keys). Returns the
	 * decoded payload claims, or a WP_Error on any failure. Does NOT check
	 * aud/iss/exp itself — the caller (auth_apple) makes those decisions.
	 */
	private static function verify_apple_id_token( $id_token ) {
		$parts = explode( '.', $id_token );
		if ( count( $parts ) !== 3 ) {
			return self::fail( 'apple_invalid', 'Malformed Apple token.', 401 );
		}
		list( $header_b64, $payload_b64, $sig_b64 ) = $parts;

		$header = json_decode( self::b64d( $header_b64 ), true );
		$payload = json_decode( self::b64d( $payload_b64 ), true );
		if ( ! is_array( $header ) || ! is_array( $payload ) ) {
			return self::fail( 'apple_invalid', 'Malformed Apple token.', 401 );
		}
		if ( 'RS256' !== ( $header['alg'] ?? '' ) ) {
			return self::fail( 'apple_invalid', 'Unsupported Apple token algorithm.', 401 );
		}
		if ( (int) ( $payload['exp'] ?? 0 ) < time() ) {
			return self::fail( 'apple_expired', 'Apple token has expired.', 401 );
		}

		$kid = (string) ( $header['kid'] ?? '' );
		$jwk = self::apple_public_key_for_kid( $kid );
		if ( ! $jwk ) {
			return self::fail( 'apple_invalid', 'Could not find a matching Apple signing key.', 401 );
		}

		$pem = self::rsa_jwk_to_pem( (string) $jwk['n'], (string) $jwk['e'] );
		if ( ! $pem ) {
			return self::fail( 'apple_invalid', 'Could not verify Apple token.', 401 );
		}

		$verified = openssl_verify( $header_b64 . '.' . $payload_b64, self::b64d( $sig_b64 ), $pem, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $verified ) {
			return self::fail( 'apple_invalid', 'Apple token signature is invalid.', 401 );
		}

		return $payload;
	}

	private static function apple_public_key_for_kid( string $kid ) {
		if ( '' === $kid ) {
			return null;
		}
		$keys = get_transient( 'pangaea_mobile_apple_jwks' );
		if ( ! is_array( $keys ) ) {
			$response = wp_remote_get( 'https://appleid.apple.com/auth/keys', array( 'timeout' => 10 ) );
			if ( is_wp_error( $response ) ) {
				return null;
			}
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$keys = is_array( $body['keys'] ?? null ) ? $body['keys'] : array();
			if ( ! empty( $keys ) ) {
				set_transient( 'pangaea_mobile_apple_jwks', $keys, HOUR_IN_SECONDS );
			}
		}
		foreach ( (array) $keys as $key ) {
			if ( is_array( $key ) && ( $key['kid'] ?? '' ) === $kid ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Builds a PEM-encoded RSA public key from a JWK's raw modulus/exponent
	 * (both base64url, per RFC 7518) by hand-assembling the fixed, standard
	 * ASN.1 DER SubjectPublicKeyInfo structure PHP's openssl_* functions need
	 * — there is no built-in "verify with n/e directly" API. This exact
	 * construction (the DER prefix bytes below are the fixed RSA
	 * AlgorithmIdentifier + BIT STRING/SEQUENCE wrapper, not something
	 * invented per-key) is the standard way vanilla PHP verifies a JWKS-based
	 * RS256 token without pulling in a JOSE library.
	 */
	private static function rsa_jwk_to_pem( string $n_b64url, string $e_b64url ) {
		$n = self::b64d( $n_b64url );
		$e = self::b64d( $e_b64url );
		if ( '' === $n || '' === $e ) {
			return null;
		}

		$encode_length = static function ( int $len ) {
			if ( $len <= 0x7f ) {
				return chr( $len );
			}
			$bytes = ltrim( pack( 'N', $len ), "\x00" );
			return chr( 0x80 | strlen( $bytes ) ) . $bytes;
		};
		$encode_integer = static function ( string $bin ) use ( $encode_length ) {
			// Prepend a 0x00 if the high bit is set, so it isn't read as negative.
			if ( '' !== $bin && ( ord( $bin[0] ) & 0x80 ) ) {
				$bin = "\x00" . $bin;
			}
			return "\x02" . $encode_length( strlen( $bin ) ) . $bin;
		};

		$modulus  = $encode_integer( $n );
		$exponent = $encode_integer( $e );
		$rsa_pub_seq = "\x30" . $encode_length( strlen( $modulus ) + strlen( $exponent ) ) . $modulus . $exponent;

		// RSA encryption OID (1.2.840.113549.1.1.1) + NULL params, then the
		// RSAPublicKey SEQUENCE above wrapped in a BIT STRING.
		$alg_id       = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
		$bit_string   = "\x03" . $encode_length( strlen( $rsa_pub_seq ) + 1 ) . "\x00" . $rsa_pub_seq;
		$spki         = "\x30" . $encode_length( strlen( $alg_id ) + strlen( $bit_string ) ) . $alg_id . $bit_string;

		$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
		$key = openssl_pkey_get_public( $pem );
		return $key ? $pem : null;
	}

	public static function auth_me( $request ) {
		return self::ok( self::user_payload( self::user_id_from_request( $request ) ) );
	}

	public static function auth_logout( $request ) {
		$data = self::request_data( $request );
		if ( ! empty( $data['refresh_token'] ) ) {
			self::revoke_refresh_token( self::user_id_from_request( $request ), (string) $data['refresh_token'] );
		}
		return self::ok( array( 'logged_out' => true ) );
	}

	public static function auth_refresh( $request ) {
		$data  = self::request_data( $request );
		$token = (string) ( $data['refresh_token'] ?? '' );
		$uid   = self::verify_token( $token, 'refresh' );
		if ( $uid <= 0 || ! self::refresh_token_exists( $uid, $token ) ) {
			return self::fail( 'invalid_refresh', 'Invalid refresh token.', 401 );
		}
		self::revoke_refresh_token( $uid, $token );
		return self::ok( self::auth_payload( $uid ) );
	}

	public static function auth_password_forgot( $request ) {
		$data  = self::request_data( $request );
		$email = sanitize_email( (string) ( $data['email'] ?? '' ) );
		if ( is_email( $email ) ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				retrieve_password( $user->user_login );
			}
		}
		return self::ok( array( 'message' => 'If this email exists, a reset link will be sent.' ) );
	}

	public static function auth_password_change( $request ) {
		$data = self::request_data( $request );
		$uid  = self::user_id_from_request( $request );
		$user = get_userdata( $uid );
		$old  = (string) ( $data['current_password'] ?? '' );
		$new  = (string) ( $data['new_password'] ?? '' );
		if ( ! $user || ! wp_check_password( $old, $user->user_pass, $uid ) ) {
			return self::fail( 'invalid_password', 'Current password is invalid.', 401 );
		}
		if ( strlen( $new ) < 8 ) {
			return self::fail( 'weak_password', 'New password must be at least 8 characters.' );
		}
		wp_set_password( $new, $uid );
		delete_user_meta( $uid, self::REFRESH_META );
		return self::ok( array( 'changed' => true ) );
	}

	private static function mask_email( $email ) {
		$parts = explode( '@', $email );
		if ( count( $parts ) !== 2 ) {
			return $email;
		}
		return substr( $parts[0], 0, 2 ) . '***@' . $parts[1];
	}

	public static function get_profile( $request ) {
		$uid     = self::user_id_from_request( $request );
		$profile = get_user_meta( $uid, self::PROFILE_META, true );
		$profile = is_array( $profile ) ? $profile : array();
		// birthday is real, shared storage (the website's own "Account
		// Details" > Birthday field, used for the loyalty birthday bonus) —
		// not a mobile-only value, so it's merged in here rather than kept
		// in the free-form _pangaea_mobile_profile blob.
		$profile['birthday'] = (string) get_user_meta( $uid, '_pga_loyalty_birthday', true );
		return self::ok(
			array(
				'user'    => self::user_payload( $uid ),
				'profile' => self::as_object( $profile ),
			)
		);
	}

	/**
	 * PHP has no distinct "empty object" type, so json_encode()/wp_json_encode()
	 * always renders an empty array as `[]`, even when it represents a map the
	 * client expects as `{}` (an empty PHP array with string keys still encodes
	 * as `{}` correctly — only the EMPTY case is ambiguous). Cast here at every
	 * object-shaped response field, not just profile/health, since this is the
	 * same root cause the app already had to defensively work around for
	 * `social_links`/notification `data`.
	 */
	private static function as_object( array $value ) {
		return empty( $value ) ? new stdClass() : $value;
	}

	public static function update_profile( $request ) {
		$uid  = self::user_id_from_request( $request );
		$data = self::sanitize_deep( self::request_data( $request ) );
		$user_update = array( 'ID' => $uid );
		if ( isset( $data['first_name'] ) ) {
			$user_update['first_name'] = sanitize_text_field( $data['first_name'] );
		}
		if ( isset( $data['last_name'] ) ) {
			$user_update['last_name'] = sanitize_text_field( $data['last_name'] );
		}
		if ( count( $user_update ) > 1 ) {
			wp_update_user( $user_update );
		}
		if ( array_key_exists( 'birthday', $data ) ) {
			$birthday = (string) $data['birthday'];
			if ( '' === $birthday || strtotime( $birthday ) ) {
				update_user_meta( $uid, '_pga_loyalty_birthday', $birthday );
			}
			unset( $data['birthday'] );
		}
		update_user_meta( $uid, self::PROFILE_META, $data );
		return self::get_profile( $request );
	}

	/**
	 * Default billing details — reads/writes the same WooCommerce customer
	 * billing storage the website's own checkout already uses, so a
	 * customer's info stays consistent whether they book on web or mobile.
	 * This is intentionally shared storage, not a separate mobile-only copy.
	 */
	public static function get_billing( $request ) {
		if ( ! class_exists( 'WC_Customer' ) ) {
			return self::fail( 'woocommerce_missing', 'WooCommerce is not available.', 503 );
		}
		$customer = new WC_Customer( self::user_id_from_request( $request ) );
		return self::ok(
			array(
				'first_name' => $customer->get_billing_first_name(),
				'last_name'  => $customer->get_billing_last_name(),
				'company'    => $customer->get_billing_company(),
				'address_1'  => $customer->get_billing_address_1(),
				'address_2'  => $customer->get_billing_address_2(),
				'city'       => $customer->get_billing_city(),
				'state'      => $customer->get_billing_state(),
				'postcode'   => $customer->get_billing_postcode(),
				'country'    => $customer->get_billing_country(),
				'email'      => $customer->get_billing_email(),
				'phone'      => $customer->get_billing_phone(),
			)
		);
	}

	public static function update_billing( $request ) {
		if ( ! class_exists( 'WC_Customer' ) ) {
			return self::fail( 'woocommerce_missing', 'WooCommerce is not available.', 503 );
		}
		$data = self::request_data( $request );
		if ( isset( $data['country'] ) && '' !== $data['country'] ) {
			$country = strtoupper( sanitize_text_field( (string) $data['country'] ) );
			$valid   = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : array();
			if ( ! isset( $valid[ $country ] ) ) {
				return self::fail( 'invalid_country', 'Country must be a valid ISO 3166-1 alpha-2 code.' );
			}
			$data['country'] = $country;
		}
		$customer = new WC_Customer( self::user_id_from_request( $request ) );
		$setters  = array(
			'first_name' => 'set_billing_first_name',
			'last_name'  => 'set_billing_last_name',
			'company'    => 'set_billing_company',
			'address_1'  => 'set_billing_address_1',
			'address_2'  => 'set_billing_address_2',
			'city'       => 'set_billing_city',
			'state'      => 'set_billing_state',
			'postcode'   => 'set_billing_postcode',
			'country'    => 'set_billing_country',
			'phone'      => 'set_billing_phone',
		);
		foreach ( $setters as $key => $setter ) {
			if ( isset( $data[ $key ] ) ) {
				$customer->$setter( sanitize_text_field( (string) $data[ $key ] ) );
			}
		}
		if ( isset( $data['email'] ) && is_email( (string) $data['email'] ) ) {
			$customer->set_billing_email( sanitize_email( (string) $data['email'] ) );
		}
		$customer->save();
		return self::get_billing( $request );
	}

	/**
	 * Real, shared storage — the exact same flat `_pga_acct_*` user meta keys
	 * the website's own "Account Details" > "Travel & Emergency Details"
	 * section reads/writes (see mu-plugins/pangaea-account-profile-unify.php),
	 * which is itself what the real website checkout pre-fills from. Age/
	 * sex/blood type/nationality/emergency contact/referral/medical notes
	 * used to live in a separate, mobile-only `_pangaea_mobile_health` blob
	 * here — meaning a customer who'd already filled all of this in on the
	 * website saw a completely empty form in the app, and vice versa.
	 * Confirmed live against a real account with real data in every field.
	 */
	private static function health_field_map() {
		return array(
			'age'                 => '_pga_acct_age',
			'sex'                 => '_pga_acct_sex',
			'blood_type'          => '_pga_acct_blood_type',
			'nationality'         => '_pga_acct_nationality',
			'emergency_name'      => '_pga_acct_emergency_name',
			'emergency_phone'     => '_pga_acct_emergency_phone',
			'referral_staff_name' => '_pga_acct_referral_staff_name',
			'medical_notes'       => '_pga_acct_medical_notes',
		);
	}

	public static function get_health( $request ) {
		$uid = self::user_id_from_request( $request );
		$out = array();
		foreach ( self::health_field_map() as $key => $meta_key ) {
			$out[ $key ] = (string) get_user_meta( $uid, $meta_key, true );
		}
		$out['age']               = '' === $out['age'] ? '' : (int) $out['age'];
		$out['passport_url']      = (string) get_user_meta( $uid, '_pga_acct_passport_url', true );
		$out['passport_filename'] = (string) get_user_meta( $uid, '_pga_acct_passport_filename', true );
		return self::ok( self::as_object( $out ) );
	}

	public static function update_health( $request ) {
		$uid  = self::user_id_from_request( $request );
		$data = self::sanitize_deep( self::request_data( $request ) );
		$map  = self::health_field_map();

		// Same validation rules as the website's own save handler
		// (pga_acct_save_billing_and_extra_fields()) — age 1-120, sex must be
		// male/female or left blank; both are quietly ignored there rather
		// than rejected, but a clean 4xx here is more useful to an API client
		// than a silent no-op.
		if ( isset( $data['age'] ) && '' !== $data['age'] ) {
			if ( ! is_numeric( $data['age'] ) ) {
				return self::fail( 'invalid_age', 'Age must be a number.' );
			}
			$age = (int) $data['age'];
			if ( $age < 1 || $age > 120 ) {
				return self::fail( 'invalid_age', 'Age must be between 1 and 120.' );
			}
		}
		if ( isset( $data['sex'] ) && '' !== $data['sex'] && ! in_array( $data['sex'], array( 'male', 'female' ), true ) ) {
			return self::fail( 'invalid_sex', 'Sex must be "male" or "female".' );
		}

		foreach ( $map as $key => $meta_key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			$value = 'age' === $key
				? ( '' === $data[ $key ] ? '' : (int) $data[ $key ] )
				: sanitize_text_field( (string) $data[ $key ] );
			update_user_meta( $uid, $meta_key, $value );
		}

		return self::get_health( $request );
	}

	/**
	 * Field name must be `passport` in the multipart upload — mirrors the
	 * website's own upload (pga_acct_save_billing_and_extra_fields()): same
	 * 5MB limit, same PDF/JPG/PNG restriction, same storage keys, so a
	 * document uploaded from either surface shows up on the other.
	 */
	public static function upload_passport( $request ) {
		$uid   = self::user_id_from_request( $request );
		$files = $request->get_file_params();
		$file  = $files['passport'] ?? null;
		if ( empty( $file ) || empty( $file['name'] ) ) {
			return self::fail( 'file_required', 'A passport document file is required.' );
		}
		$size    = isset( $file['size'] ) ? (int) $file['size'] : 0;
		$type    = isset( $file['type'] ) ? sanitize_text_field( $file['type'] ) : '';
		$ok_type = in_array( $type, array( 'application/pdf', 'image/jpeg', 'image/png' ), true );
		if ( $size <= 0 || $size > 5 * 1024 * 1024 ) {
			return self::fail( 'file_too_large', 'Passport document is too large (max 5MB).' );
		}
		if ( ! $ok_type ) {
			return self::fail( 'invalid_file_type', 'Invalid passport document type. Use PDF/JPG/PNG.' );
		}
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$uploaded = wp_handle_upload( $file, array( 'test_form' => false ) );
		if ( empty( $uploaded['url'] ) || ! empty( $uploaded['error'] ) ) {
			return self::fail( 'upload_failed', 'There was an issue uploading your passport document.', 500 );
		}
		update_user_meta( $uid, '_pga_acct_passport_url', esc_url_raw( $uploaded['url'] ) );
		update_user_meta( $uid, '_pga_acct_passport_filename', wp_basename( $uploaded['file'] ) );
		return self::get_health( $request );
	}

	/**
	 * Field name must be `avatar` in the multipart upload. Writes the SAME
	 * `wte_users_meta['user_profile_image_id']` attachment ID the website's
	 * own "Upload Image" field uses (real_avatar_url() above reads this same
	 * key) — a real WordPress media attachment, not a mobile-only file.
	 */
	public static function upload_avatar( $request ) {
		$uid   = self::user_id_from_request( $request );
		$files = $request->get_file_params();
		if ( empty( $files['avatar'] ) || empty( $files['avatar']['name'] ) ) {
			return self::fail( 'file_required', 'An image file is required.' );
		}
		if ( ! function_exists( 'media_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		$_FILES['avatar'] = $files['avatar'];
		$attachment_id    = media_handle_upload( 'avatar', 0 );
		if ( is_wp_error( $attachment_id ) ) {
			return self::fail( 'upload_failed', $attachment_id->get_error_message(), 500 );
		}
		$wte_meta = get_user_meta( $uid, 'wte_users_meta', true );
		$wte_meta = is_array( $wte_meta ) ? $wte_meta : array();
		$wte_meta['user_profile_image_id'] = (int) $attachment_id;
		update_user_meta( $uid, 'wte_users_meta', $wte_meta );
		return self::ok( array( 'avatar_url' => self::real_avatar_url( $uid ) ) );
	}

	public static function delete_avatar( $request ) {
		$uid      = self::user_id_from_request( $request );
		$wte_meta = get_user_meta( $uid, 'wte_users_meta', true );
		$wte_meta = is_array( $wte_meta ) ? $wte_meta : array();
		unset( $wte_meta['user_profile_image_id'] );
		update_user_meta( $uid, 'wte_users_meta', $wte_meta );
		return self::ok( array( 'avatar_url' => self::real_avatar_url( $uid ) ) );
	}

	private static function sanitize_deep( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ sanitize_key( (string) $key ) ?: $key ] = self::sanitize_deep( $item );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			return sanitize_textarea_field( $value );
		}
		return $value;
	}

	private static function travellers( $uid ) {
		$travellers = get_user_meta( $uid, self::TRAVELLERS_META, true );
		return is_array( $travellers ) ? array_values( $travellers ) : array();
	}

	private static function save_travellers( $uid, $travellers ) {
		update_user_meta( $uid, self::TRAVELLERS_META, array_values( $travellers ) );
	}

	public static function list_travellers( $request ) {
		return self::ok( array( 'items' => self::travellers( self::user_id_from_request( $request ) ) ) );
	}

	/**
	 * Per-traveller `date_of_birth` (YYYY-MM-DD) and `passport_number` (plain
	 * string, alongside the existing passport document upload) — traveller
	 * records are schemaless (see save_travellers()), so these need no new
	 * storage, just validation on the one field worth validating.
	 */
	private static function validate_traveller_fields( array $data ) {
		if ( isset( $data['date_of_birth'] ) && '' !== $data['date_of_birth'] ) {
			$dob = (string) $data['date_of_birth'];
			$d   = DateTime::createFromFormat( 'Y-m-d', $dob );
			if ( ! $d || $d->format( 'Y-m-d' ) !== $dob || $d > new DateTime() ) {
				return new WP_Error( 'invalid_date_of_birth', 'date_of_birth must be a valid past date in YYYY-MM-DD format.' );
			}
		}
		return true;
	}

	public static function create_traveller( $request ) {
		$uid        = self::user_id_from_request( $request );
		$travellers = self::travellers( $uid );
		$item       = self::sanitize_deep( self::request_data( $request ) );
		$valid      = self::validate_traveller_fields( $item );
		if ( is_wp_error( $valid ) ) {
			return self::fail( $valid->get_error_code(), $valid->get_error_message() );
		}
		$item['id'] = wp_generate_uuid4();
		$item['created_at'] = current_time( 'mysql' );
		$travellers[] = $item;
		self::save_travellers( $uid, $travellers );
		return self::ok( $item, 201 );
	}

	public static function get_traveller( $request ) {
		$item = self::find_traveller( self::user_id_from_request( $request ), $request['traveller_id'] );
		return $item ? self::ok( $item ) : self::fail( 'not_found', 'Traveller not found.', 404 );
	}

	public static function update_traveller( $request ) {
		$uid        = self::user_id_from_request( $request );
		$id         = (string) $request['traveller_id'];
		$travellers = self::travellers( $uid );
		$data       = self::sanitize_deep( self::request_data( $request ) );
		$valid      = self::validate_traveller_fields( $data );
		if ( is_wp_error( $valid ) ) {
			return self::fail( $valid->get_error_code(), $valid->get_error_message() );
		}
		foreach ( $travellers as &$item ) {
			if ( (string) ( $item['id'] ?? '' ) === $id ) {
				$item = array_merge( $item, $data, array( 'id' => $id, 'updated_at' => current_time( 'mysql' ) ) );
				self::save_travellers( $uid, $travellers );
				return self::ok( $item );
			}
		}
		return self::fail( 'not_found', 'Traveller not found.', 404 );
	}

	public static function delete_traveller( $request ) {
		$uid        = self::user_id_from_request( $request );
		$id         = (string) $request['traveller_id'];
		$travellers = array_values(
			array_filter(
				self::travellers( $uid ),
				function ( $item ) use ( $id ) {
					return (string) ( $item['id'] ?? '' ) !== $id;
				}
			)
		);
		self::save_travellers( $uid, $travellers );
		return self::ok( array( 'deleted' => true ) );
	}

	public static function upload_traveller_passport( $request ) {
		$upload = self::handle_upload( $request, 'passport' );
		if ( is_wp_error( $upload ) ) {
			return $upload;
		}
		$request->set_param( 'passport', $upload );
		return self::update_traveller( $request );
	}

	private static function find_traveller( $uid, $id ) {
		foreach ( self::travellers( $uid ) as $item ) {
			if ( (string) ( $item['id'] ?? '' ) === (string) $id ) {
				return $item;
			}
		}
		return null;
	}

	private static function wishlist_ids( $uid ) {
		$ids = get_user_meta( $uid, self::WISHLIST_META, true );
		$ids = is_array( $ids ) ? $ids : array();
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * Registers (or re-registers) this device's FCM token against the
	 * logged-in user, for Pangaea Push Notifications
	 * (mu-plugins/pangaea-push-notifications.php) to send to. `token` is
	 * UNIQUE across the table — re-sending the same token just moves it to
	 * the current user (covers the shared-device / different-account-login
	 * case) and bumps updated_at, rather than erroring on duplicate.
	 */
	public static function register_device_token( $request ) {
		$uid = self::user_id_from_request( $request );
		$data = self::request_data( $request );
		$token = sanitize_text_field( (string) ( $data['token'] ?? '' ) );
		$platform = sanitize_key( (string) ( $data['platform'] ?? '' ) );

		if ( '' === $token ) {
			return self::fail( 'token_required', 'A device token is required.' );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'pga_push_tokens';
		$now   = current_time( 'mysql' );

		$existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE token = %s", $token ) );
		if ( $existing_id ) {
			$wpdb->update(
				$table,
				array( 'user_id' => $uid, 'platform' => $platform, 'updated_at' => $now ),
				array( 'id' => (int) $existing_id )
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'user_id'    => $uid,
					'token'      => $token,
					'platform'   => $platform,
					'created_at' => $now,
					'updated_at' => $now,
				)
			);
		}

		return self::ok( array( 'registered' => true ) );
	}

	public static function unregister_device_token( $request ) {
		$data  = self::request_data( $request );
		$token = sanitize_text_field( (string) ( $data['token'] ?? '' ) );
		if ( '' === $token ) {
			return self::fail( 'token_required', 'A device token is required.' );
		}
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'pga_push_tokens', array( 'token' => $token ) );
		return self::ok( array( 'unregistered' => true ) );
	}

	public static function get_wishlist( $request ) {
		$uid  = self::user_id_from_request( $request );
		$lang = self::lang( $request );
		$items = array();
		foreach ( self::wishlist_ids( $uid ) as $trip_id ) {
			$payload = self::trip_payload( $trip_id, false, $lang );
			if ( $payload ) {
				$items[] = $payload;
			}
		}
		return self::ok( array( 'items' => $items ) );
	}

	public static function add_to_wishlist( $request ) {
		$uid     = self::user_id_from_request( $request );
		$trip_id = (int) $request['trip_id'];
		if ( 'trip' !== get_post_type( $trip_id ) ) {
			return self::fail( 'trip_not_found', 'Trip not found.', 404 );
		}
		$ids = self::wishlist_ids( $uid );
		if ( ! in_array( $trip_id, $ids, true ) ) {
			$ids[] = $trip_id;
			update_user_meta( $uid, self::WISHLIST_META, $ids );
		}
		return self::ok( array( 'trip_ids' => $ids ) );
	}

	public static function remove_from_wishlist( $request ) {
		$uid     = self::user_id_from_request( $request );
		$trip_id = (int) $request['trip_id'];
		$ids     = array_values( array_diff( self::wishlist_ids( $uid ), array( $trip_id ) ) );
		update_user_meta( $uid, self::WISHLIST_META, $ids );
		return self::ok( array( 'trip_ids' => $ids ) );
	}

	public static function get_home( $request ) {
		$lang = self::lang( $request );
		return self::ok(
			array(
				'popups'             => self::home_popups_payload(),
				'destinations'       => self::terms_payload( 'destination', 8, false, $lang ),
				// The 3 real homepage sections: "Explore the Treasures of
				// Arabia" (Saudi Arabia journeys), "Explore the World with
				// Us" (latest journeys tagged `worldwide`), "Experiences
				// Worth Discovering!" (latest experiences) — 10 each. The
				// generic, unfiltered "Trips Departing Soon" list
				// (featured_trips) is deliberately no longer returned — the
				// app doesn't show that section on the homepage.
				'saudi_arabia_trips' => self::simple_trip_list( 10, $lang, 'saudi-arabia' ),
				'world_trips'        => self::simple_trip_list( 10, $lang, 'worldwide' ),
				'experiences'        => self::home_experiences_payload( 10 ),
				'stays'              => self::home_stays_payload( 10 ),
				'blogs'          => self::simple_post_list( 'post', 6, $lang ),
				'ratings'        => self::home_ratings_payload(),
			)
		);
	}

	/**
	 * Up to $limit rooms across stay.pangaeaclub.net's locations for the home
	 * screen, positioned right after Experiences. Same raw room-object shape
	 * list_stays()/get_stay() already return (no new "card" transform), so
	 * the app can reuse whatever it already has for rendering a Stay room —
	 * this is just a shorter, unfiltered slice for a teaser section, not a
	 * separate data shape. Reuses stay_rooms_full_request()'s own hourly
	 * transient cache, so this costs nothing extra beyond what
	 * list_stays()/list_stay_locations() already pay for.
	 *
	 * Today there's a single location (AlUla, 4 rooms) so this simply returns
	 * all of them, but it walks every location and stops once $limit rooms
	 * are collected, so it keeps working correctly if more locations are
	 * added later without needing a code change.
	 */
	private static function home_stays_payload( $limit ) {
		$locations_body = self::stay_rooms_full_request( '/locations' );
		$locations      = is_array( $locations_body ) ? (array) ( $locations_body['data'] ?? array() ) : array();
		if ( empty( $locations ) ) {
			return array();
		}

		$items = array();
		foreach ( $locations as $location ) {
			if ( count( $items ) >= $limit ) {
				break;
			}
			$slug = sanitize_title( (string) ( $location['slug'] ?? '' ) );
			if ( '' === $slug ) {
				continue;
			}
			$remaining  = $limit - count( $items );
			$rooms_body = self::stay_rooms_full_request( '', array( 'location' => $slug, 'per_page' => $remaining ) );
			$rooms      = is_array( $rooms_body ) ? (array) ( $rooms_body['data'] ?? array() ) : array();
			foreach ( $rooms as $room ) {
				$items[] = $room;
				if ( count( $items ) >= $limit ) {
					break;
				}
			}
		}
		return $items;
	}

	/**
	 * Up to $limit newest experiences for the home screen — same
	 * Pangaea_Experiences_Archive::query_cards() call list_experiences() uses,
	 * with no filters and the default 'recommended' sort (newest-first).
	 */
	private static function home_experiences_payload( $limit ) {
		if ( ! self::experiences_available() ) {
			return array();
		}
		$cards = Pangaea_Experiences_Archive::query_cards( array( 'sort' => 'recommended' ) );
		return array_values( array_slice( $cards, 0, $limit ) );
	}

	/**
	 * Review/rating summary for the home screen.
	 * Managed via WordPress option `pangaea_home_ratings`.
	 * tripadvisor_url returns "" until set — client should guard before making CTA tappable.
	 */
	private static function home_ratings_payload() {
		$opt = get_option( 'pangaea_home_ratings', array() );
		$opt = is_array( $opt ) ? $opt : array();
		return array(
			'average_rating'  => (string) ( $opt['average_rating']  ?? '5.0' ),
			'review_count'    => (string) ( $opt['review_count']    ?? '1000' ),
			'review_label'    => (string) ( $opt['review_label']    ?? '+1000 reviews on Tripadvisor' ),
			'tripadvisor_url' => esc_url_raw( (string) ( $opt['tripadvisor_url'] ?? '' ) ),
			'platform'        => (string) ( $opt['platform']        ?? 'Tripadvisor' ),
		);
	}

	public static function get_home_popups( $request ) {
		return self::ok( self::home_popups_payload() );
	}

	/**
	 * Onboarding slides are admin-controlled from wp-admin → Settings →
	 * Mobile Onboarding (Pangaea_Mobile_Onboarding, mu-plugins/pangaea-mobile-onboarding.php)
	 * — image + bilingual title/subtitle per slide, stored in display order.
	 * No auth: this is shown before login/signup.
	 */
	public static function get_onboarding( $request ) {
		$lang  = self::lang( $request );
		$is_ar = 'ar' === $lang;
		$rows  = class_exists( 'Pangaea_Mobile_Onboarding' ) ? Pangaea_Mobile_Onboarding::get_slides() : array();

		$items = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$title = $is_ar ? (string) ( $row['title_ar'] ?? '' ) : (string) ( $row['title_en'] ?? '' );
			$title = '' !== $title ? $title : (string) ( $row['title_en'] ?? '' );
			$sub   = $is_ar ? (string) ( $row['subtitle_ar'] ?? '' ) : (string) ( $row['subtitle_en'] ?? '' );
			$sub   = '' !== $sub ? $sub : (string) ( $row['subtitle_en'] ?? '' );

			$items[] = array(
				'image_url' => (string) ( $row['image_url'] ?? '' ),
				'title'     => $title,
				'subtitle'  => $sub,
			);
		}

		return self::ok( array( 'items' => $items, 'language' => $lang ) );
	}

	/**
	 * About Us content is admin-controlled from wp-admin → Settings →
	 * Mobile About Us (Pangaea_Mobile_About, mu-plugins/pangaea-mobile-about.php)
	 * — full rich text (headings, paragraphs, lists, links, images), edited
	 * with the standard WordPress visual editor, stored as sanitized HTML.
	 * No auth: this is a public info screen. Arabic falls back to English
	 * if the admin hasn't filled in the Arabic field yet.
	 */
	public static function get_about( $request ) {
		$lang  = self::lang( $request );
		$is_ar = 'ar' === $lang;
		$data  = class_exists( 'Pangaea_Mobile_About' )
			? Pangaea_Mobile_About::get_content()
			: array( 'content_en' => '', 'content_ar' => '' );

		$content = $is_ar ? (string) ( $data['content_ar'] ?? '' ) : (string) ( $data['content_en'] ?? '' );
		$content = '' !== $content ? $content : (string) ( $data['content_en'] ?? '' );

		return self::ok( array( 'content' => $content, 'language' => $lang ) );
	}

	private static function home_popups_payload() {
		$settings = get_option( 'pangaea_homepage_image_popup_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		return array(
			'primary'   => array(
				'enabled'      => ! empty( $settings['enabled'] ) && ! empty( $settings['image_url'] ),
				'image_url'    => esc_url_raw( (string) ( $settings['image_url'] ?? '' ) ),
				'link_url'     => esc_url_raw( (string) ( $settings['link_url'] ?? '' ) ),
				'delay_ms'     => (int) ( $settings['delay_ms'] ?? 5000 ),
				'cookie_days'  => (int) ( $settings['cookie_days'] ?? 365 ),
				'open_new_tab' => ! empty( $settings['open_new_tab'] ),
			),
			'secondary' => array(
				'enabled'        => ! empty( $settings['secondary_enabled'] ) && ! empty( $settings['secondary_image_url'] ),
				'image_url'      => esc_url_raw( (string) ( $settings['secondary_image_url'] ?? '' ) ),
				'link_url'       => esc_url_raw( (string) ( $settings['secondary_link_url'] ?? '' ) ),
				'title'          => (string) ( $settings['secondary_title'] ?? '' ),
				'text'           => (string) ( $settings['secondary_text'] ?? '' ),
				'badge'          => (string) ( $settings['secondary_badge'] ?? '' ),
				'cta_text'       => (string) ( $settings['secondary_cta_text'] ?? '' ),
				'trigger_type'   => (string) ( $settings['secondary_trigger_type'] ?? 'delay' ),
				'delay_ms'       => (int) ( $settings['secondary_delay_ms'] ?? 20000 ),
				'scroll_percent' => (int) ( $settings['secondary_scroll_percent'] ?? 35 ),
				'cookie_days'    => (int) ( $settings['secondary_cookie_days'] ?? 3 ),
				'open_new_tab'   => ! empty( $settings['secondary_open_new_tab'] ),
			),
		);
	}

	private static function terms_payload( $taxonomy, $limit = 0, $hide_empty = false, $lang = '' ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		// For destinations, filter empty ones out before applying $limit —
		// capping the query itself would risk trimming away real
		// destinations in favor of empty ones that get filtered out anyway.
		$hide_empty_destinations = 'destination' === $taxonomy && function_exists( 'pangaea_trip_data_destination_has_trips' );
		// Activities has no real term hierarchy in use (every term's parent
		// is 0), so unlike destinations it doesn't need the recursive
		// descendant check above — a plain hide_empty=true query already
		// excludes 0-count terms. Callers opt in via $hide_empty; existing
		// callers (trip_filters(), get_home()) don't pass it, so their
		// behavior is unchanged.
		$args = array( 'taxonomy' => $taxonomy, 'hide_empty' => $hide_empty_destinations ? false : $hide_empty );
		if ( $limit > 0 && ! $hide_empty_destinations ) {
			$args['number'] = $limit;
		}
		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		if ( $hide_empty_destinations ) {
			$terms = array_values( array_filter( $terms, 'pangaea_trip_data_destination_has_trips' ) );
			if ( $limit > 0 ) {
				$terms = array_slice( $terms, 0, $limit );
			}
		}
		$out = array();
		foreach ( $terms as $term ) {
			$out[] = self::term_payload( $term, $taxonomy, $lang );
		}
		return array_values( array_filter( $out ) );
	}

	private static function simple_trip_list( $limit, $lang = '', $destination_slugs = array() ) {
		$args = array(
			'post_type'      => 'trip',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
		);
		// Optional destination filter for home-screen sections ("Explore the
		// Treasures of Arabia", "Explore the World with Us") — a single slug
		// or an array of slugs, OR'd together since they're all the same
		// taxonomy (no 'relation' needed for a single tax_query clause).
		// include_children is explicitly false: WP_Tax_Query defaults it to
		// true, which silently pulled in every sub-region's trips too (e.g.
		// AlUla/Asir/Riyadh trips under the saudi-arabia parent term) — 17
		// trips instead of the real destination archive page's 14. The real
		// page at /destinations/saudi-arabia/ only ever shows trips tagged
		// with that exact term, not its children, so this matches it
		// exactly instead of over-including.
		if ( ! empty( $destination_slugs ) ) {
			$args['tax_query'] = array(
				array(
					'taxonomy'         => 'destination',
					'field'            => 'slug',
					'terms'            => (array) $destination_slugs,
					'include_children' => false,
				),
			);
		}
		$query = new WP_Query( $args );
		$out = array();
		foreach ( $query->posts as $post ) {
			$out[] = self::trip_payload( $post, false, $lang );
		}
		return $out;
	}

	private static function simple_post_list( $post_type, $limit, $lang = '' ) {
		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
			)
		);
		$out = array();
		foreach ( $query->posts as $post ) {
			$out[] = self::public_post_payload( $post, $lang );
		}
		return $out;
	}

	public static function list_destinations( $request ) {
		$lang  = self::lang( $request );
		$items = self::terms_payload( 'destination', 0, false, $lang );
		$search = strtolower( sanitize_text_field( (string) $request->get_param( 'search' ) ) );
		if ( $search ) {
			$items = array_values(
				array_filter(
					$items,
					function ( $item ) use ( $search ) {
						return false !== strpos( strtolower( $item['name'] . ' ' . $item['slug'] ), $search );
					}
				)
			);
		}
		return self::ok( array( 'items' => $items ) );
	}

	public static function get_destination( $request ) {
		$lang = self::lang( $request );
		$term = self::destination_from_id_or_slug( $request['id_or_slug'] );
		return $term ? self::ok( self::term_payload( $term, 'destination', $lang ) ) : self::fail( 'not_found', 'Destination not found.', 404 );
	}

	public static function get_destination_trips( $request ) {
		$term = self::destination_from_id_or_slug( $request['id_or_slug'] );
		if ( ! $term ) {
			return self::fail( 'not_found', 'Destination not found.', 404 );
		}
		$request->set_param( 'destination', $term->slug );
		return self::list_trips( $request );
	}

	private static function destination_from_id_or_slug( $value ) {
		if ( is_numeric( $value ) ) {
			$term = get_term( (int) $value, 'destination' );
		} else {
			$term = get_term_by( 'slug', sanitize_title( $value ), 'destination' );
		}
		return ( $term instanceof WP_Term ) ? $term : null;
	}

	/**
	 * Activities previously had no dedicated archive endpoint at all — only
	 * a raw, image-fallback-less term list buried inside /trips/filters, and
	 * the ability to filter /trips by an activities slug with no activity-
	 * page metadata (hero image/description/count) attached. Mirrors the
	 * destinations routes above.
	 */
	public static function list_activities( $request ) {
		$lang   = self::lang( $request );
		$items  = self::terms_payload( 'activities', 0, true, $lang );
		$search = strtolower( sanitize_text_field( (string) $request->get_param( 'search' ) ) );
		if ( $search ) {
			$items = array_values(
				array_filter(
					$items,
					function ( $item ) use ( $search ) {
						return false !== strpos( strtolower( $item['name'] . ' ' . $item['slug'] ), $search );
					}
				)
			);
		}
		return self::ok( array( 'items' => $items ) );
	}

	public static function get_activity( $request ) {
		$lang = self::lang( $request );
		$term = self::activity_from_id_or_slug( $request['id_or_slug'] );
		return $term ? self::ok( self::term_payload( $term, 'activities', $lang ) ) : self::fail( 'not_found', 'Activity not found.', 404 );
	}

	public static function get_activity_trips( $request ) {
		$term = self::activity_from_id_or_slug( $request['id_or_slug'] );
		if ( ! $term ) {
			return self::fail( 'not_found', 'Activity not found.', 404 );
		}
		$request->set_param( 'activities', $term->slug );
		return self::list_trips( $request );
	}

	private static function activity_from_id_or_slug( $value ) {
		if ( is_numeric( $value ) ) {
			$term = get_term( (int) $value, 'activities' );
		} else {
			$term = get_term_by( 'slug', sanitize_title( $value ), 'activities' );
		}
		return ( $term instanceof WP_Term ) ? $term : null;
	}

	/**
	 * Advanced trip search/filter. Backward compatible with the original
	 * four single-value params, but each now also accepts a comma-separated
	 * list for multi-select (e.g. `destination=alula,neom`), OR'd within a
	 * taxonomy and AND'd across taxonomies. Adds real, data-backed filters:
	 * price range, duration range (days), departure date/month, an "on
	 * sale" toggle, an "available to book now" toggle, and a Ladies Only
	 * toggle — plus a `sort` param. See trip_filters() for the matching
	 * price bounds / duration buckets / sort option / toggle metadata the
	 * app renders its filter UI from.
	 *
	 * Departure date, on-sale and availability can't be expressed as a
	 * plain postmeta range (departures live inside per-package serialized
	 * data; on-sale/bookable status is computed, not stored), so whenever
	 * one of those — or a non-relevance sort — is requested, every matching
	 * trip is fetched and filtered/sorted/paginated in PHP instead of SQL.
	 * The trip catalogue is a few hundred posts, so this stays fast; the
	 * plain-filter path (the common case) is untouched and still paginates
	 * in SQL via WP_Query.
	 */
	public static function list_trips( $request ) {
		$lang     = self::lang( $request );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 50, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 12 ) ) );
		$args     = array(
			'post_type'      => 'trip',
			'post_status'    => 'publish',
			's'              => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'tax_query'      => array(),
			'meta_query'     => array(),
		);

		foreach ( array( 'destination', 'activities', 'difficulty', 'travelstyle' ) as $tax ) {
			$raw = (string) $request->get_param( $tax );
			if ( '' === trim( $raw ) || ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$values = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), 'strlen' ) );
			if ( empty( $values ) ) {
				continue;
			}
			$all_numeric         = count( array_filter( $values, 'is_numeric' ) ) === count( $values );
			$args['tax_query'][] = array(
				'taxonomy' => $tax,
				'field'    => $all_numeric ? 'term_id' : 'slug',
				'terms'    => $all_numeric ? array_map( 'intval', $values ) : $values,
			);
		}

		if ( rest_sanitize_boolean( $request->get_param( 'ladies_only' ) ) ) {
			// The 'destination' taxonomy has an old "Ladies Only Trips" term
			// (id 262) with zero trips actually tagged on it — confirmed
			// live. The term genuinely in use for this is 'travelstyle'
			// "Women-Only Trips" (id 550, 7 real trips as of this writing).
			$args['tax_query'][] = array(
				'taxonomy' => 'travelstyle',
				'field'    => 'term_id',
				'terms'    => array( 550 ),
			);
		}

		if ( count( $args['tax_query'] ) > 1 ) {
			$args['tax_query']['relation'] = 'AND';
		}
		if ( empty( $args['tax_query'] ) ) {
			unset( $args['tax_query'] );
		}

		// `_s_price` mirrors the same trip price shown to the app as a flat,
		// indexed postmeta value (kept in sync by WP Travel Engine) — the
		// only way to do a real SQL range filter, since the price itself
		// lives inside a serialized settings blob.
		$price_min = $request->get_param( 'price_min' );
		$price_max = $request->get_param( 'price_max' );
		if ( is_numeric( $price_min ) || is_numeric( $price_max ) ) {
			$clause = array( 'key' => '_s_price', 'type' => 'NUMERIC' );
			if ( is_numeric( $price_min ) && is_numeric( $price_max ) ) {
				$clause['value']   = array( (float) $price_min, (float) $price_max );
				$clause['compare'] = 'BETWEEN';
			} elseif ( is_numeric( $price_min ) ) {
				$clause['value']   = (float) $price_min;
				$clause['compare'] = '>=';
			} else {
				$clause['value']   = (float) $price_max;
				$clause['compare'] = '<=';
			}
			$args['meta_query'][] = $clause;
		}

		// `_s_duration` mirrors the same duration in HOURS, so day values
		// from the app are converted (×24) before querying.
		$duration_min = $request->get_param( 'duration_min' );
		$duration_max = $request->get_param( 'duration_max' );
		if ( is_numeric( $duration_min ) || is_numeric( $duration_max ) ) {
			$clause = array( 'key' => '_s_duration', 'type' => 'NUMERIC' );
			if ( is_numeric( $duration_min ) && is_numeric( $duration_max ) ) {
				$clause['value']   = array( (float) $duration_min * 24, (float) $duration_max * 24 );
				$clause['compare'] = 'BETWEEN';
			} elseif ( is_numeric( $duration_min ) ) {
				$clause['value']   = (float) $duration_min * 24;
				$clause['compare'] = '>=';
			} else {
				$clause['value']   = (float) $duration_max * 24;
				$clause['compare'] = '<=';
			}
			$args['meta_query'][] = $clause;
		}

		if ( count( $args['meta_query'] ) > 1 ) {
			$args['meta_query']['relation'] = 'AND';
		}
		if ( empty( $args['meta_query'] ) ) {
			unset( $args['meta_query'] );
		}

		$departure_from  = sanitize_text_field( (string) $request->get_param( 'departure_from' ) );
		$departure_to    = sanitize_text_field( (string) $request->get_param( 'departure_to' ) );
		$departure_month = sanitize_text_field( (string) $request->get_param( 'departure_month' ) );
		if ( preg_match( '/^\d{4}-\d{2}$/', $departure_month ) ) {
			$departure_from = $departure_month . '-01';
			$departure_to   = gmdate( 'Y-m-t', strtotime( $departure_from ) );
		}
		$on_sale        = rest_sanitize_boolean( $request->get_param( 'on_sale' ) );
		$available_only = rest_sanitize_boolean( $request->get_param( 'available_only' ) );
		$sort           = sanitize_text_field( (string) $request->get_param( 'sort' ) );

		$needs_post_filter = $on_sale || $available_only || '' !== $departure_from || '' !== $departure_to
			|| in_array( $sort, array( 'price_asc', 'price_desc', 'soonest_departure' ), true );

		if ( ! $needs_post_filter ) {
			$args['posts_per_page'] = $per_page;
			$args['paged']          = $page;
			$query                  = new WP_Query( $args );
			$items                  = array();
			foreach ( $query->posts as $post ) {
				$items[] = self::trip_payload( $post, false, $lang );
			}
			return self::ok(
				array(
					'items'      => array_values( array_filter( $items ) ),
					'pagination' => array(
						'page'        => $page,
						'per_page'    => $per_page,
						'total'       => (int) $query->found_posts,
						'total_pages' => (int) $query->max_num_pages,
					),
				)
			);
		}

		$args['posts_per_page'] = -1;
		$query                  = new WP_Query( $args );
		$from_ts                = '' !== $departure_from ? strtotime( $departure_from ) : null;
		$to_ts                  = '' !== $departure_to ? strtotime( $departure_to . ' 23:59:59' ) : null;
		$rows                   = array();

		foreach ( $query->posts as $post ) {
			$payload = self::trip_payload( $post, false, $lang );
			if ( ! $payload ) {
				continue;
			}
			if ( $on_sale && empty( $payload['has_sale'] ) ) {
				continue;
			}
			if ( $available_only && empty( $payload['is_bookable'] ) ) {
				continue;
			}
			$next_ts = self::trip_next_departure_ts( $post->ID );
			if ( $from_ts || $to_ts ) {
				if ( ! $next_ts ) {
					continue;
				}
				if ( $from_ts && $next_ts < $from_ts ) {
					continue;
				}
				if ( $to_ts && $next_ts > $to_ts ) {
					continue;
				}
			}
			$payload['next_departure'] = $next_ts ? wp_date( 'Y-m-d', $next_ts ) : null;
			$rows[]                    = array( 'payload' => $payload, 'sort_ts' => $next_ts );
		}

		if ( 'price_asc' === $sort ) {
			usort( $rows, function ( $a, $b ) { return $a['payload']['price'] <=> $b['payload']['price']; } );
		} elseif ( 'price_desc' === $sort ) {
			usort( $rows, function ( $a, $b ) { return $b['payload']['price'] <=> $a['payload']['price']; } );
		} elseif ( 'soonest_departure' === $sort ) {
			usort(
				$rows,
				function ( $a, $b ) {
					if ( null === $a['sort_ts'] ) {
						return null === $b['sort_ts'] ? 0 : 1;
					}
					if ( null === $b['sort_ts'] ) {
						return -1;
					}
					return $a['sort_ts'] <=> $b['sort_ts'];
				}
			);
		}

		$total       = count( $rows );
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
		$slice       = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );
		$items       = array_map(
			function ( $row ) {
				return $row['payload'];
			},
			$slice
		);

		return self::ok(
			array(
				'items'      => $items,
				'pagination' => array(
					'page'        => $page,
					'per_page'    => $per_page,
					'total'       => $total,
					'total_pages' => $total_pages,
				),
			)
		);
	}

	/**
	 * Earliest still-upcoming departure date across every package, as a
	 * Unix timestamp (or null if none). Walks the same per-package
	 * departure data compute_is_bookable() already reads — kept as a
	 * separate helper rather than changing that one, so existing
	 * is_bookable behaviour can't regress.
	 */
	private static function trip_next_departure_ts( $trip_id ) {
		$today_ts = strtotime( wp_date( 'Y-m-d' ) );
		$earliest = null;
		foreach ( self::trip_packages_payload( $trip_id ) as $package ) {
			foreach ( (array) ( $package['departures'] ?? array() ) as $departure ) {
				if ( ! is_array( $departure ) ) {
					continue;
				}
				$date = (string) ( $departure['start_date'] ?? $departure['date'] ?? '' );
				if ( '' === $date ) {
					continue;
				}
				$ts = strtotime( substr( $date, 0, 10 ) );
				if ( ! $ts || $ts < $today_ts ) {
					continue;
				}
				if ( null === $earliest || $ts < $earliest ) {
					$earliest = $ts;
				}
			}
		}
		return $earliest;
	}

	public static function search_trips( $request ) {
		$lang  = self::lang( $request );
		$query = sanitize_text_field( (string) $request->get_param( 'query' ) );
		$limit = min( 50, max( 1, (int) ( $request->get_param( 'limit' ) ?: 10 ) ) );
		if ( strlen( $query ) < 2 ) {
			return self::fail( 'query_required', 'Search query must be at least 2 characters.' );
		}
		$items = array();
		$seen  = array();
		if ( function_exists( 'pangaea_smart_trip_search_ranked_ids' ) && function_exists( 'pangaea_smart_trip_search_result_payload' ) ) {
			$ids = array_slice( (array) pangaea_smart_trip_search_ranked_ids( $query ), 0, $limit );
			foreach ( $ids as $id ) {
				$id = (int) $id;
				// Defensive guard: skip any ID the smart engine's cache
				// returned that isn't currently a real, published trip
				// (stale/trashed/deleted since the ranked-ID cache was
				// built, which is only invalidated on save, not on trash/
				// delete) — confirmed as the root cause of a real production
				// crash (uncaught InvalidArgumentException) on this endpoint.
				if ( ! self::trip_is_valid_published( $id ) ) {
					continue;
				}
				try {
					$payload = pangaea_smart_trip_search_result_payload( $id, $query );
				} catch ( \Throwable $e ) {
					continue;
				}
				if ( ! self::text_matches_lang( $payload['title'] ?? '', $lang ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$items[]     = self::add_mobile_card_fields( $payload, $id );
			}
		}
		// The smart engine has a scoring floor and can miss a plain substring
		// match that the native ?search= WP_Query finds trivially (reported:
		// "sahara" returned 0 results here but was found via /trips?search=).
		// Filling the gap with a native search — rather than relying on the
		// smart engine alone — means /trips/search is never a strict subset
		// of /trips?search= for the same word.
		if ( count( $items ) < $limit ) {
			$fallback = new WP_Query(
				array(
					'post_type'      => 'trip',
					'post_status'    => 'publish',
					'posts_per_page' => $limit - count( $items ),
					's'              => $query,
					'post__not_in'   => array_keys( $seen ),
				)
			);
			foreach ( $fallback->posts as $post ) {
				$payload = self::trip_payload( $post, false, $lang );
				if ( is_array( $payload ) && ! self::text_matches_lang( $payload['title'] ?? '', $lang ) ) {
					continue;
				}
				$items[] = $payload;
			}
		}
		return self::ok(
			array(
				'query'        => $query,
				'did_you_mean' => self::did_you_mean_suggestion( $query ),
				'items'        => array_values( array_filter( $items ) ),
				'language'     => $lang,
			)
		);
	}

	/**
	 * Combined "search everything" endpoint for the mobile app's full-text
	 * search overlay: one ranked result set spanning both Journeys/Trips
	 * (WTE `trip` CPT) and Experiences (`pga_experience` CPT), each result
	 * tagged with `type` so the app can route/render it correctly.
	 *
	 * Trips are ranked with the existing engine (pangaea_smart_trip_search_*,
	 * mu-plugins/pangaea-smart-trip-search.php); Experiences are ranked with
	 * experience_search_ranked() above, which follows the exact same
	 * tokenize -> normalize -> weighted-field -> similar_text()/levenshtein()
	 * fuzzy approach. The two engines produce differently-scaled raw scores,
	 * so each side is normalized to 0..1 of its own top score before the two
	 * lists are merged and sorted together — otherwise whichever engine
	 * happens to produce bigger numbers would dominate the order regardless
	 * of actual relevance.
	 *
	 * Defensively re-validates every candidate ID against its real, current
	 * post type + status right before building its payload, on BOTH sides —
	 * the same stale-cached-ID crash class fixed in search_trips() above is
	 * just as possible here, and this makes sure neither engine can ever
	 * fatal the whole request.
	 */
	public static function search_all( $request ) {
		$lang  = self::lang( $request );
		$query = sanitize_text_field( (string) $request->get_param( 'query' ) );
		$limit = min( 50, max( 1, (int) ( $request->get_param( 'limit' ) ?: 20 ) ) );

		if ( strlen( $query ) < 2 ) {
			return self::fail( 'query_required', 'Search query must be at least 2 characters.' );
		}

		// Advanced-filter type toggle: omitted/anything else searches all
		// three (unchanged default behavior, now with Stays added to it);
		// 'journey'/'experience'/'stay' searches only that one, skipping
		// the others' work entirely rather than computing and discarding it.
		$type          = sanitize_key( (string) $request->get_param( 'type' ) );
		$include_trips = ! in_array( $type, array( 'experience', 'stay' ), true );
		$include_exp   = ! in_array( $type, array( 'journey', 'stay' ), true );
		$include_stays = ! in_array( $type, array( 'journey', 'experience' ), true );

		// Cap how many candidates per side get a full payload built (expensive:
		// pricing, images, review aggregates) while still giving the merge
		// enough headroom that a strong result from either side can win.
		$candidate_cap = max( $limit * 3, 30 );

		$trip_matches = array();
		if ( $include_trips
			&& function_exists( 'pangaea_smart_trip_search_ranked_ids' )
			&& function_exists( 'pangaea_smart_trip_search_score' )
			&& function_exists( 'pangaea_smart_trip_search_result_payload' ) ) {
			$ids = array_slice( (array) pangaea_smart_trip_search_ranked_ids( $query ), 0, $candidate_cap );
			foreach ( $ids as $id ) {
				$id = (int) $id;
				if ( ! self::trip_is_valid_published( $id ) ) {
					continue;
				}
				try {
					$score   = pangaea_smart_trip_search_score( $query, $id );
					$payload = self::add_mobile_card_fields( pangaea_smart_trip_search_result_payload( $id, $query, $score ), $id );
				} catch ( \Throwable $e ) {
					continue;
				}
				if ( ! self::text_matches_lang( $payload['title'] ?? '', $lang ) ) {
					continue;
				}
				$payload['type'] = 'trip';
				$trip_matches[]  = array( 'score' => (float) $score, 'payload' => $payload );
			}
		}

		$experience_matches = array();
		if ( $include_exp && self::experiences_available() ) {
			$ranked = array_slice( self::experience_search_ranked( $query ), 0, $candidate_cap, true );
			foreach ( $ranked as $id => $score ) {
				$id = (int) $id;
				if ( ! self::experience_is_valid_published( $id ) ) {
					continue;
				}
				try {
					$card = Pangaea_Experiences_Archive::card_data( $id );
				} catch ( \Throwable $e ) {
					continue;
				}
				if ( ! self::text_matches_lang( $card['title'] ?? '', $lang ) ) {
					continue;
				}
				$card['type']         = 'experience';
				$experience_matches[] = array( 'score' => (float) $score, 'payload' => $card );
			}
		}

		$normalize = static function ( array $matches ) {
			if ( empty( $matches ) ) {
				return $matches;
			}
			$max = max( array_column( $matches, 'score' ) );
			$max = $max > 0 ? $max : 1;
			foreach ( $matches as &$match ) {
				$match['norm'] = $match['score'] / $max;
			}
			unset( $match );
			return $matches;
		};

		$stay_matches = array();
		if ( $include_stays ) {
			$stay_matches = self::stay_search_matches( $query, $candidate_cap );
		}

		$combined = array_merge( $normalize( $trip_matches ), $normalize( $experience_matches ), $normalize( $stay_matches ) );

		usort(
			$combined,
			static function ( $a, $b ) {
				return $b['norm'] <=> $a['norm'];
			}
		);

		$combined = array_slice( $combined, 0, $limit );
		$items    = array_map(
			static function ( $match ) {
				$match['payload']['match_score'] = round( $match['norm'], 4 );
				return $match['payload'];
			},
			$combined
		);

		return self::ok(
			array(
				'query'        => $query,
				'did_you_mean' => self::did_you_mean_suggestion( $query ),
				'items'        => array_values( $items ),
				'language'     => $lang,
			)
		);
	}

	/**
	 * Every word (3+ Latin letters) worth spell-correcting against: term
	 * names from the four filterable taxonomies, plus every published trip
	 * title. Cached — this scans the whole catalogue, but only ever changes
	 * when a trip/term is added or renamed.
	 */
	private static function did_you_mean_vocabulary() {
		$cache_key = 'pangaea_did_you_mean_vocab_v1';
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$words = array();
		$add   = function ( $text ) use ( &$words ) {
			foreach ( preg_split( '/[\s,\/\-]+/', (string) $text ) as $word ) {
				$word = strtolower( trim( $word, ".,!?'\"" ) );
				if ( strlen( $word ) >= 3 && ! preg_match( '/[^a-z]/', $word ) ) {
					$words[ $word ] = true;
				}
			}
		};

		foreach ( array( 'destination', 'activities', 'travelstyle', 'difficulty' ) as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$add( $term->name );
				}
			}
		}

		$trip_ids = get_posts(
			array( 'post_type' => 'trip', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' )
		);
		foreach ( $trip_ids as $trip_id ) {
			$add( get_the_title( $trip_id ) );
		}

		$vocab = array_keys( $words );
		set_transient( $cache_key, $vocab, 6 * HOUR_IN_SECONDS );
		return $vocab;
	}

	/**
	 * "Did you mean" spelling correction for the app's search box —
	 * separate from the existing fuzzy-scoring search engine (which already
	 * tolerates typos when ranking results, but never tells the customer
	 * what it matched). Word-by-word nearest-vocabulary-match via
	 * levenshtein(), English/Latin-script only for now (Arabic needs a
	 * different normalization approach — diacritics/letter-shape variants
	 * — rather than plain character-distance matching, so it's intentionally
	 * left alone here rather than giving bad corrections). Returns null when
	 * the query already matches known vocabulary, or no confident
	 * correction exists.
	 */
	private static function did_you_mean_suggestion( $query ) {
		$query = trim( (string) $query );
		if ( strlen( $query ) < 3 || preg_match( '/[^\x00-\x7F]/', $query ) ) {
			return null;
		}

		$vocab   = self::did_you_mean_vocabulary();
		$words   = preg_split( '/\s+/', strtolower( $query ) );
		$changed = false;
		$out     = array();

		foreach ( $words as $word ) {
			$clean = trim( $word, ".,!?'\"" );
			if ( strlen( $clean ) < 3 || in_array( $clean, $vocab, true ) ) {
				$out[] = $word;
				continue;
			}
			$best      = null;
			$best_dist = null;
			foreach ( $vocab as $candidate ) {
				if ( abs( strlen( $candidate ) - strlen( $clean ) ) > 3 ) {
					continue;
				}
				$dist = levenshtein( $clean, $candidate );
				if ( null === $best_dist || $dist < $best_dist ) {
					$best_dist = $dist;
					$best      = $candidate;
				}
			}
			$threshold = max( 1, (int) round( strlen( $clean ) * 0.4 ) );
			if ( $best && $best_dist > 0 && $best_dist <= $threshold ) {
				$out[]   = $best;
				$changed = true;
			} else {
				$out[] = $word;
			}
		}

		return $changed ? implode( ' ', $out ) : null;
	}

	/**
	 * Filter/sort metadata for the app's filter UI. `destination`/
	 * `activities`/`difficulty`/`travelstyle` are now multi-select on the
	 * app side (see list_trips()) — same term lists as before, just pass
	 * a comma-separated list of the returned slugs/ids back in.
	 */
	public static function trip_filters( $request ) {
		$lang = self::lang( $request );
		$out  = array();
		// trip_types/trip_tag deliberately excluded — not wanted as filters
		// (also the two taxonomies with the most incomplete Arabic
		// translations in WPML).
		foreach ( array( 'destination', 'activities', 'difficulty', 'travelstyle' ) as $tax ) {
			$out[ $tax ] = self::terms_payload( $tax, 0, false, $lang );
		}

		global $wpdb;
		$row = $wpdb->get_row(
			"SELECT MIN(CAST(pm.meta_value AS UNSIGNED)) AS min_price, MAX(CAST(pm.meta_value AS UNSIGNED)) AS max_price
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_s_price' AND p.post_type = 'trip' AND p.post_status = 'publish' AND pm.meta_value != ''"
		);
		$out['price_range'] = array(
			'min' => $row ? (float) $row->min_price : 0,
			'max' => $row ? (float) $row->max_price : 0,
		);

		$is_ar                    = 'ar' === $lang;
		$out['duration_buckets']  = array(
			array(
				'key'      => 'weekend',
				'label'    => $is_ar ? 'نهاية أسبوع (1-3 أيام)' : 'Weekend (1–3 days)',
				'min_days' => 1,
				'max_days' => 3,
			),
			array(
				'key'      => 'short',
				'label'    => $is_ar ? 'قصيرة (4-7 أيام)' : 'Short (4–7 days)',
				'min_days' => 4,
				'max_days' => 7,
			),
			array(
				'key'      => 'long',
				'label'    => $is_ar ? 'طويلة (8 أيام فأكثر)' : 'Long (8+ days)',
				'min_days' => 8,
				'max_days' => null,
			),
		);

		$out['sort_options'] = array(
			array( 'value' => 'relevance', 'label' => $is_ar ? 'الأنسب' : 'Best Match' ),
			array( 'value' => 'price_asc', 'label' => $is_ar ? 'السعر: من الأقل للأعلى' : 'Price: Low to High' ),
			array( 'value' => 'price_desc', 'label' => $is_ar ? 'السعر: من الأعلى للأقل' : 'Price: High to Low' ),
			array( 'value' => 'soonest_departure', 'label' => $is_ar ? 'أقرب موعد مغادرة' : 'Soonest Departure' ),
		);

		$out['toggles'] = array(
			array( 'key' => 'ladies_only', 'label' => $is_ar ? 'رحلات نسائية فقط' : 'Ladies Only' ),
			array( 'key' => 'on_sale', 'label' => $is_ar ? 'عروض وخصومات' : 'On Sale' ),
			array( 'key' => 'available_only', 'label' => $is_ar ? 'متاح للحجز الآن' : 'Available to Book Now' ),
		);

		return self::ok( $out );
	}

	public static function trip_calendar( $request ) {
		$trip_id = (int) $request->get_param( 'trip_id' );
		if ( $trip_id > 0 ) {
			return self::ok( array( 'items' => self::departures_payload( $trip_id ) ) );
		}
		$items = array();
		$query = new WP_Query( array( 'post_type' => 'trip', 'post_status' => 'publish', 'posts_per_page' => 50 ) );
		foreach ( $query->posts as $post ) {
			foreach ( self::departures_payload( $post->ID ) as $departure ) {
				$departure['trip_id'] = (int) $post->ID;
				$departure['trip_title'] = get_the_title( $post );
				$items[] = $departure;
			}
		}
		return self::ok( array( 'items' => $items ) );
	}

	public static function get_trip( $request ) {
		$lang = self::lang( $request );
		$id   = self::translated_id( (int) $request['trip_id'], 'trip', $lang );
		$post = get_post( $id );
		if ( ! $post || 'trip' !== $post->post_type ) {
			return self::fail( 'not_found', 'Trip not found.', 404 );
		}
		return self::ok( self::trip_payload( $post, true, $lang ) );
	}

	public static function get_trip_itinerary( $request ) {
		return self::ok( array( 'items' => self::itinerary_payload( (int) $request['trip_id'] ) ) );
	}

	public static function get_trip_gallery( $request ) {
		return self::ok( array( 'items' => self::gallery_payload( (int) $request['trip_id'] ) ) );
	}

	/**
	 * Real, current WTE generic-Enquiry flow, replicated rather than called
	 * through WPTravelEngine\Core\Controllers\Ajax\EnquiryMail::process_request()
	 * (wp-content/plugins/wp-travel-engine/includes/classes/Core/Controllers/Ajax/EnquiryMail.php)
	 * — that method is bound to WPTravelEngine\Abstracts\AjaxController's
	 * handle()/create_request() lifecycle (reads php://input/$_POST/$_FILES
	 * directly, no public way to hand it a WP_REST_Request) and ends by
	 * calling wp_send_json_success()/wp_send_json_error(), which wp_die() —
	 * fatal to call from inside another REST response. This mirrors its
	 * validation, recipient-resolution, and email/model calls exactly, so
	 * the resulting `enquiry` CPT record and admin/customer emails are
	 * identical to a website submission.
	 */
	public static function trip_enquiry( $request ) {
		$trip_id = (int) $request['trip_id'];
		$trip    = get_post( $trip_id );
		if ( ! $trip || 'trip' !== $trip->post_type || 'publish' !== $trip->post_status ) {
			return self::fail( 'not_found', 'Trip not found.', 404 );
		}

		$data = self::request_data( $request );

		if ( ! class_exists( 'WTE_Default_Form_Fields' ) ) {
			return self::fail( 'enquiry_unavailable', 'Enquiry form is not available.', 503 );
		}

		// Reads the REAL, currently-configured field set — not a fixed list.
		// This site customizes the enquiry form well beyond WTE's own 8
		// default fields via the "WP Travel Engine Form Editor" add-on
		// (wp-content/plugins/wp-travel-engine-form-editor/src/FormEditor.php,
		// hooked on the same 'wp_travel_engine_enquiry_fields_display' filter
		// WTE_Default_Form_Fields::enquiry() applies internally), configured
		// through the option `wpte_form_editor_form_fields['enquiry_form']`.
		// On this site that adds WhatsApp number, Emergency Contact Number,
		// Instagram account, Date of Birth, Blood Type, and Diet/Disease
		// notes on top of the vanilla fields, and renames a couple (Country
		// → "Nationality", Contact → "WhatsApp number"). A previous version
		// of this endpoint hardcoded only the 8 vanilla field names, so real
		// submissions from the app silently dropped every custom field —
		// confirmed live (a real test enquiry was missing all 6 custom
		// fields). Reading the live definitions here means this always
		// matches the real form, including future admin changes to it.
		$form_fields  = \WTE_Default_Form_Fields::enquiry();
		$sanitized    = array();
		$field_errors = array();

		foreach ( $form_fields as $field ) {
			$key = $field['name'] ?? null;
			if ( ! $key ) {
				continue;
			}
			$present  = array_key_exists( $key, $data );
			$value    = $present ? $data[ $key ] : null;
			$required = isset( $field['validations']['required'] )
				&& ( true === $field['validations']['required'] || 'true' === (string) $field['validations']['required'] );

			if ( $required && ( ! $present || '' === trim( (string) $value ) ) ) {
				$field_errors[ $key ] = 'Required.';
				continue;
			}
			if ( ! $present || '' === trim( (string) $value ) ) {
				// Optional and not supplied — omit entirely, same as
				// EnquiryMail::sanitize_form_data()'s own behavior.
				continue;
			}

			switch ( $field['type'] ?? 'text' ) {
				case 'number':
					$sanitized[ $key ] = abs( (float) $value );
					break;
				case 'email':
					$email_value = sanitize_email( (string) $value );
					if ( ! is_email( $email_value ) ) {
						$field_errors[ $key ] = 'Invalid email address.';
						break;
					}
					$sanitized[ $key ] = $email_value;
					break;
				case 'textarea':
					$sanitized[ $key ] = sanitize_textarea_field( (string) $value );
					break;
				default:
					$sanitized[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		if ( ! empty( $field_errors ) ) {
			return self::fail( 'invalid_enquiry', 'Please check the enquiry details.', 422, array( 'errors' => $field_errors ) );
		}

		$formdata               = $sanitized;
		$formdata['package_id'] = $trip_id;

		$name  = (string) ( $formdata['enquiry_name'] ?? '' );
		$email = (string) ( $formdata['enquiry_email'] ?? '' );
		if ( '' === $name || '' === $email ) {
			return self::fail( 'invalid_enquiry', 'Name and email are required.', 422 );
		}

		$wp_travel_engine_settings = get_option( 'wp_travel_engine_settings', true );
		$subject                   = $wp_travel_engine_settings['query_subject'] ?? __( 'Enquiry received', 'wp-travel-engine' );
		if ( ! empty( $formdata['enquiry_subject'] ) ) {
			$subject = $formdata['enquiry_subject'];
		}
		$subject = str_replace( array( '{enquirer_name}', '{enquirer_email}' ), array( $name, $email ), $subject );

		$admin_email               = get_option( 'admin_email' );
		$wp_travel_engine_settings = get_option( 'wp_travel_engine_settings' );

		if ( ! empty( $wp_travel_engine_settings['email']['enquiry_emailaddress'] ) ) {
			$to = array_map( 'sanitize_email', explode( ',', $wp_travel_engine_settings['email']['enquiry_emailaddress'] ) );
		} else {
			$emails = array_filter( array_map( 'sanitize_email', explode( ',', $wp_travel_engine_settings['email']['emails'] ?? '' ) ) );
			$to     = ! empty( $emails ) ? $emails : array( sanitize_email( $admin_email ) );
		}

		$ipaddress = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ipaddress = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			if ( ! filter_var( $ipaddress, FILTER_VALIDATE_IP ) ) {
				$ipaddress = '';
			}
		}

		$formdata['package_name'] = '<a href=' . esc_url( get_permalink( $trip ) ) . '>' . esc_attr( $trip->post_title ) . '</a>';
		$formdata['IP Address:']  = $ipaddress;

		require_once plugin_dir_path( WP_TRAVEL_ENGINE_FILE_PATH ) . 'includes/class-wp-travel-engine-emails.php';
		$admin_email_template_content = wte_get_template_html( 'emails/enquiry.php', compact( 'formdata' ) );

		$admin_sent = false;
		foreach ( $to as $val ) {
			$email_instance = new \WPTravelEngine\Email\Email();
			$admin_sent     = $email_instance->add_headers( array( 'reply_to' => "Reply-To: {$name}<{$email}>" ) )
											  ->set( 'to', $val )
											  ->set( 'my_subject', esc_html( $subject ) )
											  ->set( 'attachments', array() )
											  ->set( 'content', $admin_email_template_content )
											  ->send();
		}

		if ( isset( $wp_travel_engine_settings['email']['cust_notif'] ) && '1' === $wp_travel_engine_settings['email']['cust_notif'] ) {
			$user = (object) array(
				'user_login' => $name,
				'user_email' => $email,
			);
			$mail = new \WPTravelEngine\Email\UserEmail( $user );
			$mail->set( 'to', $email )
				 ->set( 'my_subject', $wp_travel_engine_settings['customer_email_notify_tabs']['enquiry']['subject'] ?? __( 'Enquiry Sent.', 'wp-travel-engine' ) )
				 ->set( 'content', $wp_travel_engine_settings['customer_email_notify_tabs']['enquiry']['content'] ?? '' )
				 ->send();
		}

		$post_id = \WPTravelEngine\Core\Models\Post\Enquiry::insert( $formdata );
		do_action( 'wp_travel_engine_after_enquiry_post_insert', $post_id );

		if ( is_wp_error( $post_id ) ) {
			return self::fail( 'enquiry_failed', 'Sorry, your query could not be sent at the moment. Please try again later.', 500 );
		}

		if ( $admin_sent ) {
			do_action( 'wp_travel_engine_after_enquiry_sent', $post_id );
		}

		return self::ok( array(
			'message' => __( 'Your query has been successfully sent. Thank You.', 'wp-travel-engine' ),
		) );
	}

	/**
	 * Mirrors Pangaea_Trip_Local_Forms::handle_submission()
	 * (wp-content/mu-plugins/pangaea-trip-local-forms.php) exactly — that
	 * method reads raw $_POST and terminates via wp_send_json_*(), so it
	 * can't be called directly. Uses clean field names (name/email/phone/
	 * company/date) rather than the plf_-prefixed ones, which are just that
	 * HTML form's own field names, then maps to the same _plf_* meta keys so
	 * the resulting pangaea_local_enq post is identical in shape to a
	 * website submission and shows correctly on the "Local Enquiries" screen.
	 */
	public static function trip_local_enquiry( $request ) {
		$trip_id = (int) $request['trip_id'];
		$trip    = get_post( $trip_id );
		if ( ! $trip || 'trip' !== $trip->post_type || 'publish' !== $trip->post_status ) {
			return self::fail( 'not_found', 'Trip not found.', 404 );
		}

		if ( ! class_exists( 'Pangaea_Trip_Local_Forms' ) ) {
			return self::fail( 'local_form_unavailable', 'Local enquiry form is not available.', 503 );
		}

		$data = self::request_data( $request );

		$name    = isset( $data['name'] )    ? sanitize_text_field( (string) $data['name'] )    : '';
		$email   = isset( $data['email'] )   ? sanitize_email( (string) $data['email'] )         : '';
		$phone   = isset( $data['phone'] )   ? sanitize_text_field( (string) $data['phone'] )    : '';
		$company = isset( $data['company'] ) ? sanitize_text_field( (string) $data['company'] )  : '';
		$date    = isset( $data['date'] )    ? sanitize_text_field( (string) $data['date'] )     : '';

		$errors = array();
		if ( '' === $name ) {
			$errors['name'] = 'Required.';
		}
		if ( '' === $email ) {
			$errors['email'] = 'Required.';
		} elseif ( ! is_email( $email ) ) {
			$errors['email'] = 'Invalid email address.';
		}
		if ( '' === $phone ) {
			$errors['phone'] = 'Required.';
		}
		if ( ! empty( $errors ) ) {
			return self::fail( 'invalid_enquiry', 'Please check the enquiry details.', 422, array( 'errors' => $errors ) );
		}

		$form_type  = 'internal';
		$trip_title = get_the_title( $trip_id );
		$post_id    = wp_insert_post( array(
			'post_title'  => sprintf( 'Local Enquiry from %s — %s', $name, $trip_title ),
			'post_type'   => Pangaea_Trip_Local_Forms::POST_TYPE,
			'post_status' => 'publish',
		) );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return self::fail( 'enquiry_failed', 'Could not save your enquiry. Please try again.', 500 );
		}

		$meta_map = array(
			'_plf_form_type' => $form_type,
			'_plf_trip_id'   => $trip_id,
			'_plf_name'      => $name,
			'_plf_company'   => $company,
			'_plf_email'     => $email,
			'_plf_phone'     => $phone,
			'_plf_date'      => $date,
		);
		foreach ( $meta_map as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		Pangaea_Trip_Local_Forms::send_emails( $post_id, $form_type, array(
			'post_id'      => $post_id,
			'form_type'    => $form_type,
			'trip_id'      => $trip_id,
			'trip_title'   => $trip_title,
			'name'         => $name,
			'company'      => $company,
			'email'        => $email,
			'phone'        => $phone,
			'date'         => $date,
			'submitted_at' => current_time( 'mysql' ),
		) );

		return self::ok( array(
			'message' => __( 'Thank you for your enquiry. Our team will get in touch with you shortly.', 'tourm' ),
		) );
	}

	/**
	 * Experiences never use WTE's generic Enquiry form (that system is
	 * inherently trip-only — WTE_Default_Form_Fields/EnquiryMail don't apply
	 * to the pga_experience CPT at all). When an Experience is set to
	 * enquiry-only (is_enquiry_only / Pangaea_Experiences::is_enquiry()),
	 * the website's own popup form is this SAME Local Form
	 * (Pangaea_Trip_Local_Forms) — see that plugin's enqueue_frontend(),
	 * which explicitly branches on is_singular('pga_experience') and reuses
	 * its 'internal' form/AJAX handler for experiences too. This endpoint
	 * mirrors trip_local_enquiry() exactly for that same reason: previously
	 * there was no submission endpoint for Experience enquiries at all.
	 */
	public static function experience_local_enquiry( $request ) {
		if ( ! self::experiences_available() ) {
			return self::fail( 'experiences_unavailable', 'Experiences module is not active.', 503 );
		}
		$experience_id = (int) $request['experience_id'];
		$experience    = get_post( $experience_id );
		if ( ! $experience || Pangaea_Experiences::CPT !== $experience->post_type || 'publish' !== $experience->post_status ) {
			return self::fail( 'not_found', 'Experience not found.', 404 );
		}

		if ( ! class_exists( 'Pangaea_Trip_Local_Forms' ) ) {
			return self::fail( 'local_form_unavailable', 'Local enquiry form is not available.', 503 );
		}

		$data = self::request_data( $request );

		$name    = isset( $data['name'] )    ? sanitize_text_field( (string) $data['name'] )    : '';
		$email   = isset( $data['email'] )   ? sanitize_email( (string) $data['email'] )         : '';
		$phone   = isset( $data['phone'] )   ? sanitize_text_field( (string) $data['phone'] )    : '';
		$company = isset( $data['company'] ) ? sanitize_text_field( (string) $data['company'] )  : '';
		$date    = isset( $data['date'] )    ? sanitize_text_field( (string) $data['date'] )     : '';

		$errors = array();
		if ( '' === $name ) {
			$errors['name'] = 'Required.';
		}
		if ( '' === $email ) {
			$errors['email'] = 'Required.';
		} elseif ( ! is_email( $email ) ) {
			$errors['email'] = 'Invalid email address.';
		}
		if ( '' === $phone ) {
			$errors['phone'] = 'Required.';
		}
		if ( ! empty( $errors ) ) {
			return self::fail( 'invalid_enquiry', 'Please check the enquiry details.', 422, array( 'errors' => $errors ) );
		}

		$form_type        = 'internal';
		$experience_title = get_the_title( $experience_id );
		$post_id          = wp_insert_post( array(
			'post_title'  => sprintf( 'Local Enquiry from %s — %s', $name, $experience_title ),
			'post_type'   => Pangaea_Trip_Local_Forms::POST_TYPE,
			'post_status' => 'publish',
		) );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return self::fail( 'enquiry_failed', 'Could not save your enquiry. Please try again.', 500 );
		}

		// Reuses the same _plf_trip_id meta key the website's own popup form
		// already does for Experiences — the admin "Local Enquiries" column/
		// metabox just calls get_the_title()/get_edit_post_link() on whatever
		// ID is stored, which works correctly for either post type unchanged.
		$meta_map = array(
			'_plf_form_type' => $form_type,
			'_plf_trip_id'   => $experience_id,
			'_plf_name'      => $name,
			'_plf_company'   => $company,
			'_plf_email'     => $email,
			'_plf_phone'     => $phone,
			'_plf_date'      => $date,
		);
		foreach ( $meta_map as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		Pangaea_Trip_Local_Forms::send_emails( $post_id, $form_type, array(
			'post_id'      => $post_id,
			'form_type'    => $form_type,
			'trip_id'      => $experience_id,
			'trip_title'   => $experience_title,
			'name'         => $name,
			'company'      => $company,
			'email'        => $email,
			'phone'        => $phone,
			'date'         => $date,
			'submitted_at' => current_time( 'mysql' ),
		) );

		return self::ok( array(
			'message' => __( 'Thank you for your enquiry. Our team will get in touch with you shortly.', 'tourm' ),
		) );
	}

	/**
	 * Drives the app's Enquiry form so it never has to hardcode the field
	 * set — reads the exact same live, admin-configurable definitions
	 * trip_enquiry() validates against (WTE_Default_Form_Fields::enquiry(),
	 * see that method's own docblock for why this is dynamic rather than
	 * fixed). Not actually trip-specific today (the field set is sitewide),
	 * but kept under /trips/{id}/ to match the shape the mobile team asked
	 * for and leave room for real per-trip customization later.
	 */
	public static function get_trip_enquiry_fields( $request ) {
		if ( ! class_exists( 'WTE_Default_Form_Fields' ) ) {
			return self::fail( 'enquiry_unavailable', 'Enquiry form is not available.', 503 );
		}
		$fields = array();
		foreach ( \WTE_Default_Form_Fields::enquiry() as $field ) {
			$key = $field['name'] ?? null;
			if ( ! $key ) {
				continue;
			}
			$required = isset( $field['validations']['required'] )
				&& ( true === $field['validations']['required'] || 'true' === (string) $field['validations']['required'] );
			$fields[] = array(
				'name'        => $key,
				'label'       => (string) ( $field['field_label'] ?? $key ),
				'type'        => (string) ( $field['type'] ?? 'text' ),
				'required'    => $required,
				'placeholder' => (string) ( $field['placeholder'] ?? '' ),
			);
		}
		return self::ok( array( 'items' => $fields ) );
	}

	/**
	 * These were previously hardcoded independently in both the app and
	 * pangaea-footer.php's shortcode defaults (which drift out of sync with
	 * each other by nature — two separate hardcoded copies). This is now
	 * the single source of truth, seeded with the same real live values, and
	 * admin-editable via the `pangaea_contact_channels` option without
	 * needing an app release OR another backend deploy.
	 */
	private static function contact_channels_payload() {
		$opt = get_option( 'pangaea_contact_channels', array() );
		$opt = is_array( $opt ) ? $opt : array();
		return array(
			'whatsapp_number' => (string) ( $opt['whatsapp_number'] ?? '966552713769' ),
			'whatsapp_url'    => (string) ( $opt['whatsapp_url'] ?? 'https://api.whatsapp.com/send/?phone=966552713769&text&type=phone_number&app_absent=0' ),
			'phone'           => (string) ( $opt['phone'] ?? '+966552713769' ),
			'email'           => (string) ( $opt['email'] ?? 'info@pangaeaclub.net' ),
			'instagram_url'   => (string) ( $opt['instagram_url'] ?? 'https://www.instagram.com/pangaeaclub/' ),
			'x_url'           => (string) ( $opt['x_url'] ?? 'https://x.com/pangaeaclub?lang=en' ),
			'linkedin_url'    => (string) ( $opt['linkedin_url'] ?? 'https://www.linkedin.com/company/pangaea-adventure-club/' ),
		);
	}

	public static function get_contact_channels( $request ) {
		return self::ok( self::contact_channels_payload() );
	}

	/**
	 * Was returning the raw wp_travel_engine_setting['faq'] shape directly —
	 * two parallel arrays ({faq_title:[...], faq_content:[...]}), not a
	 * usable list of questions. Confirmed live (trip 34818, "Climb AlQuron")
	 * that real content is there, just never paired up. Tried delegating to
	 * WTE's own Trip::get_faq_data(), but wte_get_trip() actually returns
	 * the older WPTravelEngine\Posttype\Trip class, which doesn't have that
	 * method (fatals) — get_faq_data() only exists on a different, newer
	 * WTE model class this helper never gets back. Parsing the raw setting
	 * directly instead avoids depending on WTE's internal class split.
	 * faq_content is stored as Gutenberg block HTML, not plain text — routed
	 * through the same plain-text helper already used elsewhere for exactly
	 * this kind of WTE rich-text field.
	 */
	public static function get_trip_faqs( $request ) {
		$settings = get_post_meta( (int) $request['trip_id'], 'wp_travel_engine_setting', true );
		$faq_data = is_array( $settings ) ? ( $settings['faq'] ?? array() ) : array();
		$titles   = is_array( $faq_data['faq_title'] ?? null ) ? $faq_data['faq_title'] : array();
		$contents = is_array( $faq_data['faq_content'] ?? null ) ? $faq_data['faq_content'] : array();

		$items = array();
		foreach ( $titles as $i => $question ) {
			$question = trim( wp_strip_all_tags( (string) $question ) );
			if ( '' === $question ) {
				continue;
			}
			$answer = function_exists( 'pangaea_trip_data_plain_text' )
				? pangaea_trip_data_plain_text( (string) ( $contents[ $i ] ?? '' ) )
				: trim( wp_strip_all_tags( (string) ( $contents[ $i ] ?? '' ) ) );
			$items[] = array( 'question' => $question, 'answer' => $answer );
		}

		return self::ok( array( 'items' => $items ) );
	}

	public static function get_trip_departures( $request ) {
		return self::ok( array( 'items' => self::departures_payload( (int) $request['trip_id'] ) ) );
	}

	public static function get_trip_packages( $request ) {
		return self::ok( array( 'items' => self::trip_packages_payload( (int) $request['trip_id'] ) ) );
	}

	/**
	 * Real per-trip add-ons (airport transfer, private guide, extra night,
	 * etc.) — the "WP Travel Engine Extra Services" official add-on plugin's
	 * data, associated to this trip via its `wp_travel_engine_setting` meta
	 * (`wte_services_ids` + per-trip `trip_extra_services` overrides).
	 *
	 * This route previously delegated to PCTAI_Rest::get_addons(), which is
	 * an unrelated AI custom-trip-builder feature that happens to share a
	 * similarly-named endpoint — it was never actually the per-trip add-ons
	 * data this route is documented to return. Reads the same settings the
	 * official plugin's own REST filter (prepare_extra_services() in
	 * class-extra-services-wp-travel-engine.php) merges, directly from post
	 * meta rather than through WTE's internal Trip/TripController objects.
	 */
	public static function get_trip_addons( $request ) {
		$trip_id = (int) $request['trip_id'];
		return self::ok( array( 'items' => self::trip_addons_data( $trip_id ) ) );
	}

	/**
	 * Shared by GET /trips/{id}/addons and quote()'s own add-on pricing (B13) —
	 * one source of truth for "what add-ons exist for this trip and what do
	 * they cost", so the price quoted can never drift from what the addons
	 * endpoint advertised.
	 */
	private static function trip_addons_data( $trip_id ) {
		if ( ! function_exists( 'wptravelengine_is_addon_active' ) || ! wptravelengine_is_addon_active( 'extra-services' ) ) {
			return array();
		}

		$settings = get_post_meta( $trip_id, 'wp_travel_engine_setting', true );
		$settings = is_array( $settings ) ? $settings : array();
		$ids      = array_filter( array_map( 'absint', explode( ',', (string) ( $settings['wte_services_ids'] ?? '' ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$services = get_posts(
			array(
				'post_type'      => 'wte-services',
				'post_status'    => 'publish',
				'post__in'       => $ids,
				'posts_per_page' => -1,
				'orderby'        => 'post__in',
			)
		);

		$overrides = isset( $settings['trip_extra_services'] ) && is_array( $settings['trip_extra_services'] ) ? $settings['trip_extra_services'] : array();
		$items     = array();

		foreach ( $services as $index => $service ) {
			$service_data = get_post_meta( $service->ID, 'wte_services', true );
			if ( empty( $service_data ) || ! is_array( $service_data ) ) {
				continue;
			}

			$override     = isset( $overrides[ $index ] ) && is_array( $overrides[ $index ] ) ? $overrides[ $index ] : array();
			$options      = $override['options'] ?? ( $service_data['options'] ?? array() );
			$descriptions = $override['descriptions'] ?? ( $service_data['descriptions'] ?? array() );
			$prices       = $override['prices'] ?? ( $service_data['prices'] ?? array() );
			$is_custom    = ( ( $service_data['service_type'] ?? '' ) === 'custom' );

			if ( ! $is_custom ) {
				if ( ! isset( $prices[0] ) || '' === $prices[0] ) {
					$prices = array( 0 => (float) ( $service_data['service_cost'] ?? 0 ) );
				}
				if ( ! isset( $descriptions[0] ) || '' === $descriptions[0] ) {
					$descriptions = array( 0 => (string) get_the_content( '', false, $service->ID ) );
				}
			}

			$image_id = get_post_thumbnail_id( $service->ID );
			$items[]  = array(
				'id'           => (int) $service->ID,
				'label'        => (string) $service->post_title,
				'type'         => $is_custom ? 'advanced' : 'default',
				'options'      => (array) $options,
				'descriptions' => (array) $descriptions,
				'prices'       => (array) $prices,
				'image'        => $image_id ? self::image_payload( $image_id ) : null,
			);
		}

		return $items;
	}

	/**
	 * B13: POST /quote previously ignored add-ons under every key the mobile
	 * team tried, because nothing in quote() ever read one — this was a real
	 * gap, not an undocumented existing key. Request shape:
	 *
	 *   "addons": [ { "id": 123, "option_index": 0, "quantity": 2 }, ... ]
	 *
	 * `id` must be one of the trip's own GET /trips/{id}/addons item ids;
	 * `option_index` picks which of that addon's `prices`/`options` entries
	 * to charge (default 0 — the only entry a "default"-type addon has);
	 * `quantity` defaults to 1. Returned as their own `addons` array (kept
	 * separate from `lines`, which stays traveller-only) with `total` already
	 * folded into `subtotal`/`total` above.
	 */
	private static function quote_addon_lines( $trip_id, $data ) {
		$requested = isset( $data['addons'] ) && is_array( $data['addons'] ) ? $data['addons'] : array();
		if ( empty( $requested ) ) {
			return array();
		}
		$catalog = array();
		foreach ( self::trip_addons_data( $trip_id ) as $addon ) {
			$catalog[ (int) $addon['id'] ] = $addon;
		}
		$out = array();
		foreach ( $requested as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$addon_id = (int) ( $row['id'] ?? 0 );
			if ( ! isset( $catalog[ $addon_id ] ) ) {
				continue; // Unknown/foreign addon id — silently dropped rather than trusting a client-supplied price.
			}
			$addon        = $catalog[ $addon_id ];
			$option_index = max( 0, (int) ( $row['option_index'] ?? 0 ) );
			$qty          = max( 0, (int) ( $row['quantity'] ?? 1 ) );
			if ( $qty <= 0 || ! isset( $addon['prices'][ $option_index ] ) ) {
				continue;
			}
			$unit_price = (float) $addon['prices'][ $option_index ];
			$out[]      = array(
				'addon_id'      => $addon_id,
				'label'         => $addon['label'],
				'option_index'  => $option_index,
				'option_label'  => (string) ( $addon['options'][ $option_index ] ?? '' ),
				'quantity'      => $qty,
				'unit_price'    => round( $unit_price, 2 ),
				'total'         => round( $unit_price * $qty, 2 ),
			);
		}
		return $out;
	}

	public static function get_trip_guides( $request ) {
		$lang = self::lang( $request );
		return self::ok( array( 'items' => self::trip_guides_payload( (int) $request['trip_id'], $lang ) ) );
	}

	public static function get_trip_suggested_flights( $request ) {
		return self::ok( self::suggested_flights_payload( (int) $request['trip_id'] ) );
	}

	/**
	 * Experiences (pangaea-experiences plugin, CPT pga_experience) — a
	 * separate catalogue from WTE Journeys/trips, bookable single-day/short
	 * activities. Wraps the same Pangaea_Experiences_Archive class the
	 * public /experiences/ archive and the pangaea_experience_cards
	 * Elementor widget already use, so filtering/sorting/pricing behavior is
	 * identical to the website.
	 */
	private static function experiences_available() {
		return class_exists( 'Pangaea_Experiences_Archive' ) && class_exists( 'Pangaea_Experiences' );
	}

	/**
	 * True only if $id is currently a real, published `trip` post. The smart
	 * search engine's ranked-ID cache (pangaea_smart_trip_search_ranked_ids())
	 * is only invalidated on save_post_trip / taxonomy edits — not on trash,
	 * permanent delete, or a post-type change — so a stale ID can outlive the
	 * trip it once pointed to for up to 6 hours. Every ID from that engine
	 * must pass this check before it's handed to Trip::make() (via
	 * pangaea_trip_data_trip()), which throws an uncaught
	 * InvalidArgumentException("Invalid post type") otherwise and fatals the
	 * whole request — confirmed live in production error logs.
	 */
	private static function trip_is_valid_published( $id ) {
		$id = (int) $id;
		if ( $id <= 0 ) {
			return false;
		}
		$post = get_post( $id );
		return $post instanceof WP_Post && 'trip' === $post->post_type && 'publish' === $post->post_status;
	}

	/** Same guard for Experiences (pga_experience), for the same reason. */
	private static function experience_is_valid_published( $id ) {
		$id = (int) $id;
		if ( $id <= 0 || ! self::experiences_available() ) {
			return false;
		}
		$post = get_post( $id );
		return $post instanceof WP_Post && Pangaea_Experiences::CPT === $post->post_type && 'publish' === $post->post_status;
	}

	/**
	 * Parallel to pangaea_smart_trip_search_trip_index() but for Experiences.
	 * Reuses the trip engine's generic text primitives (normalize/tokens/
	 * expand_query/score_segment all operate on plain strings, nothing
	 * trip-specific) — only the field extraction below is new, because
	 * Experience's real fields (pga_experience meta, experience_category /
	 * experience_destination taxonomies) don't map onto trip's hardcoded ones.
	 */
	private static function experience_search_index( $id ) {
		if ( ! function_exists( 'pangaea_smart_trip_search_normalize' ) || ! self::experiences_available() ) {
			return array();
		}

		$cache_key = 'pangaea_experience_search_index_1_' . (int) $id;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$post  = get_post( $id );
		$title = $post ? $post->post_title . ' ' . $post->post_name : '';

		$taxonomy_parts = array();
		foreach ( array( Pangaea_Experiences::TAX, Pangaea_Experiences::TAX_DEST ) as $taxonomy ) {
			$terms = get_the_terms( $id, $taxonomy );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$taxonomy_parts[] = $term->name;
					$taxonomy_parts[] = (string) get_term_meta( $term->term_id, 'name_ar', true );
				}
			}
		}

		$meta_parts = array();
		foreach ( array( 'tagline', 'meeting_point', 'location_label', 'important_info', 'weather_info' ) as $key ) {
			$meta_parts[] = (string) Pangaea_Experiences::g( $id, $key, '' );
		}
		foreach ( array( 'highlights', 'inclusions', 'not_included', 'requirements' ) as $key ) {
			$decoded = json_decode( (string) Pangaea_Experiences::g( $id, $key, '[]' ), true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $line ) {
					$meta_parts[] = is_scalar( $line ) ? (string) $line : '';
				}
			}
		}

		$index = array(
			'title'    => array( 'weight' => 6, 'text' => $title ),
			'taxonomy' => array( 'weight' => 5, 'text' => implode( ' ', $taxonomy_parts ) ),
			'meta'     => array( 'weight' => 3, 'text' => implode( ' ', $meta_parts ) ),
			'content'  => array( 'weight' => 2, 'text' => $post ? $post->post_content : '' ),
		);

		foreach ( $index as $key => $segment ) {
			$index[ $key ]['normalized'] = pangaea_smart_trip_search_normalize( $segment['text'] );
		}

		set_transient( $cache_key, $index, DAY_IN_SECONDS );

		return $index;
	}

	private static function experience_search_score( $query, $id ) {
		if ( ! function_exists( 'pangaea_smart_trip_search_normalize' ) ) {
			return 0;
		}
		$query   = pangaea_smart_trip_search_normalize( $query );
		$needles = pangaea_smart_trip_search_expand_query( $query );
		$index   = self::experience_search_index( $id );
		$score   = 0;
		// Same "don't fuzzy-match the whole body" exemption the trip engine
		// uses — content is the largest haystack and only gets full credit
		// for an exact substring (perf: fuzzy-matching every word of a long
		// description against every needle was hanging PHP-FPM workers in
		// the trip engine before that exemption was added).
		$no_fuzzy_segments = array( 'content' );
		foreach ( $index as $key => $segment ) {
			$allow_fuzzy   = ! in_array( $key, $no_fuzzy_segments, true );
			$segment_score = pangaea_smart_trip_search_score_segment( $query, $needles, $segment['normalized'] ?? '', $allow_fuzzy );
			$score        += $segment_score * (int) ( $segment['weight'] ?? 1 );
		}
		return $score;
	}

	/**
	 * Parallel to pangaea_smart_trip_search_ranked_ids() — same "query all
	 * published posts of the type, score each within a time budget, cache the
	 * result" shape, adapted to pga_experience. Returns an id => score map
	 * (trip's version returns bare ids; scores are kept here so the caller
	 * doesn't have to re-score every candidate a second time).
	 */
	private static function experience_search_ranked( $query ) {
		if ( ! self::experiences_available() || ! function_exists( 'pangaea_smart_trip_search_normalize' ) ) {
			return array();
		}

		$cache_key = 'pangaea_experience_search_1_' . md5( pangaea_smart_trip_search_normalize( $query ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$ids = get_posts(
			array(
				'post_type'              => Pangaea_Experiences::CPT,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'cache_results'          => false,
				'orderby'                => 'title',
				'order'                  => 'ASC',
			)
		);

		// Same 3-second hard time budget as pangaea_smart_trip_search_ranked_ids()
		// — this loop's cost scales with catalog size, uncached, per keystroke.
		$deadline  = microtime( true ) + 3.0;
		$ranked    = array();
		$timed_out = false;

		foreach ( $ids as $id ) {
			if ( microtime( true ) > $deadline ) {
				$timed_out = true;
				break;
			}
			$score = self::experience_search_score( $query, $id );
			if ( $score >= 56 ) {
				$ranked[ (int) $id ] = $score;
			}
		}

		arsort( $ranked, SORT_NUMERIC );
		set_transient( $cache_key, $ranked, $timed_out ? MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS );

		return $ranked;
	}

	public static function list_experiences( $request ) {
		if ( ! self::experiences_available() ) {
			return self::fail( 'experiences_unavailable', 'Experiences module is not active.', 503 );
		}
		self::lang( $request );

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 50, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 12 ) ) );

		$filters = array( 'sort' => sanitize_key( (string) ( $request->get_param( 'sort' ) ?: 'recommended' ) ) );

		// Multiple categories: category=tour,adventure — previously this only
		// ever looked up the whole raw string as one single slug/id, which
		// silently matched no term for a comma-joined value and fell through
		// to "no category filter at all" (returning every experience
		// unfiltered). query_cards()'s tax_query already OR-matches an array
		// of term ids correctly; this was purely a request-parsing gap.
		// An invalid/unknown slug for a filter the app explicitly asked for
		// must exclude everything, not silently return every experience —
		// each filter below tracks whether it was requested at all vs.
		// requested-but-matched-nothing, and the latter short-circuits to
		// an empty result.
		$no_match = false;

		$category_param = sanitize_text_field( (string) $request->get_param( 'category' ) );
		if ( '' !== $category_param ) {
			$category_ids = array();
			foreach ( array_filter( array_map( 'trim', explode( ',', $category_param ) ) ) as $slug_or_id ) {
				$term = get_term_by( is_numeric( $slug_or_id ) ? 'id' : 'slug', $slug_or_id, Pangaea_Experiences::TAX );
				if ( $term instanceof WP_Term ) {
					$category_ids[] = (int) $term->term_id;
				}
			}
			if ( $category_ids ) {
				$filters['categories'] = $category_ids;
			} else {
				$no_match = true;
			}
		}

		// Multiple destinations: destination=alula,riyadh — same comma-split
		// fix as category above (previously only a single slug/id was ever
		// looked up, so a comma-joined value matched no term and silently
		// fell through to "no destination filter at all").
		$destination_param = sanitize_text_field( (string) $request->get_param( 'destination' ) );
		if ( '' !== $destination_param ) {
			$destination_ids = array();
			foreach ( array_filter( array_map( 'trim', explode( ',', $destination_param ) ) ) as $slug_or_id ) {
				$term = get_term_by( is_numeric( $slug_or_id ) ? 'id' : 'slug', $slug_or_id, Pangaea_Experiences::TAX_DEST );
				if ( $term instanceof WP_Term ) {
					$destination_ids[] = (int) $term->term_id;
				}
			}
			if ( $destination_ids ) {
				$filters['destinations'] = $destination_ids;
			} else {
				$no_match = true;
			}
		}

		if ( $no_match ) {
			return self::ok(
				array(
					'items'      => array(),
					'pagination' => array( 'page' => $page, 'per_page' => $per_page, 'total' => 0, 'total_pages' => 0 ),
				)
			);
		}
		if ( '' !== (string) $request->get_param( 'price_min' ) ) {
			$filters['price_min'] = (float) $request->get_param( 'price_min' );
		}
		if ( '' !== (string) $request->get_param( 'price_max' ) ) {
			$filters['price_max'] = (float) $request->get_param( 'price_max' );
		}
		if ( '' !== (string) $request->get_param( 'rating_min' ) ) {
			$filters['rating_min'] = (float) $request->get_param( 'rating_min' );
		}
		// New: text search, matching the Trips convention (?search=).
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		if ( '' !== $search ) {
			$filters['search'] = $search;
		}

		$cards = Pangaea_Experiences_Archive::query_cards( $filters );
		$total = count( $cards );
		$items = array_slice( $cards, ( $page - 1 ) * $per_page, $per_page );

		return self::ok(
			array(
				'items'      => array_values( $items ),
				'pagination' => array(
					'page'        => $page,
					'per_page'    => $per_page,
					'total'       => $total,
					'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
				),
			)
		);
	}

	public static function experience_filters( $request ) {
		if ( ! self::experiences_available() ) {
			return self::fail( 'experiences_unavailable', 'Experiences module is not active.', 503 );
		}
		$is_rtl = 'ar' === self::lang( $request );

		// $term->count is WordPress's cached, language-UNAWARE taxonomy stat
		// — it counts every published experience tagged with the term
		// regardless of which language "current" is, so it reads roughly
		// double the real per-language figure (confirmed live: raw_count 64
		// for "Tour" vs. 33 real English / 31 real Arabic experiences). A
		// real WP_Query, run after self::lang() has already switched WPML's
		// active language, DOES correctly scope to that language — the same
		// mechanism list_experiences() already relies on — so counts are
		// recomputed that way instead of trusted from the term object.
		$real_count = static function ( string $taxonomy, int $term_id ): int {
			$q = new WP_Query(
				array(
					'post_type'      => Pangaea_Experiences::CPT,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => false,
					'tax_query'      => array( array( 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => array( $term_id ) ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				)
			);
			return (int) $q->found_posts;
		};

		$map = static function ( array $terms, string $taxonomy ) use ( $is_rtl, $real_count ) {
			$out = array();
			foreach ( $terms as $term ) {
				if ( ! $term instanceof WP_Term ) {
					continue;
				}
				$count = $real_count( $taxonomy, (int) $term->term_id );
				// Excludes terms with zero matching experiences IN THE
				// CURRENT LANGUAGE — this also happens to remove a stray
				// Arabic-named duplicate term (a second "National Day
				// Offers" term accidentally created with its Arabic name as
				// the primary name, sitting alongside the real English one)
				// without needing a separate language-detection heuristic.
				if ( 0 === $count ) {
					continue;
				}
				$out[] = array(
					'id'    => (int) $term->term_id,
					'slug'  => $term->slug,
					'name'  => Pangaea_Experiences::term_label( $term, $is_rtl ),
					'count' => $count,
				);
			}
			return $out;
		};

		// Destinations: the taxonomy is a shallow "country > city" hierarchy
		// (e.g. Saudi Arabia > Riyadh, AlUla, ...), and Experiences are only
		// ever tagged on the CITY (leaf), never the country. Returning just
		// the top-level parent (as this previously did) showed a single
		// "Saudi Arabia" option that — because WordPress's tax_query
		// implicitly includes descendants when you query a parent term —
		// matches every experience, giving the app no real way to narrow by
		// city at all. Leaf terms (no children of their own) are what's
		// actually usable as a filter, so those are returned instead,
		// regardless of taxonomy depth.
		$all_destinations  = get_terms( array( 'taxonomy' => Pangaea_Experiences::TAX_DEST, 'hide_empty' => false, 'orderby' => 'name' ) );
		$all_destinations  = is_wp_error( $all_destinations ) ? array() : $all_destinations;
		$parent_ids        = array_unique( array_filter( array_map( static function ( $t ) {
			return $t instanceof WP_Term ? (int) $t->parent : 0;
		}, $all_destinations ) ) );
		$leaf_destinations = array_values( array_filter( $all_destinations, static function ( $t ) use ( $parent_ids ) {
			return $t instanceof WP_Term && ! in_array( (int) $t->term_id, $parent_ids, true );
		} ) );

		// Price range, scoped to the current language — reuses the exact
		// same query_cards() the list endpoint uses (no filters but sort),
		// so the slider bounds can never disagree with what /experiences
		// actually returns for that language (previously computed from
		// get_posts() with no language scoping at all — e.g. Arabic showed
		// min 35 when the cheapest real Arabic experience is 45).
		$all_cards = Pangaea_Experiences_Archive::query_cards( array( 'sort' => 'recommended' ) );
		$price_min = null;
		$price_max = null;
		foreach ( $all_cards as $card ) {
			$p = $card['price'] ?? null;
			if ( null === $p ) {
				continue;
			}
			if ( null === $price_min || $p < $price_min ) {
				$price_min = $p;
			}
			if ( null === $price_max || $p > $price_max ) {
				$price_max = $p;
			}
		}

		return self::ok(
			array(
				'categories'   => $map( Pangaea_Experiences_Archive::categories(), Pangaea_Experiences::TAX ),
				'destinations' => $map( $leaf_destinations, Pangaea_Experiences::TAX_DEST ),
				'price_range'  => array(
					'min' => $price_min ?? 0,
					'max' => $price_max ?? 0,
				),
				'sort_options' => array(
					array( 'value' => 'recommended', 'label' => $is_rtl ? 'موصى به' : 'Recommended' ),
					array( 'value' => 'price_asc', 'label' => $is_rtl ? 'السعر: من الأقل للأعلى' : 'Price: Low to High' ),
					array( 'value' => 'price_desc', 'label' => $is_rtl ? 'السعر: من الأعلى للأقل' : 'Price: High to Low' ),
					array( 'value' => 'rating', 'label' => $is_rtl ? 'الأعلى تقييمًا' : 'Top Rated' ),
					array( 'value' => 'soonest', 'label' => $is_rtl ? 'أقرب موعد' : 'Soonest Available' ),
				),
			)
		);
	}

	public static function get_experience( $request ) {
		if ( ! self::experiences_available() ) {
			return self::fail( 'experiences_unavailable', 'Experiences module is not active.', 503 );
		}
		$id   = (int) $request['experience_id'];
		$post = get_post( $id );
		if ( ! $post || Pangaea_Experiences::CPT !== $post->post_type || 'publish' !== $post->post_status ) {
			return self::fail( 'experience_not_found', 'Experience not found.', 404 );
		}

		$lang  = self::lang( $request );
		$is_ar = 'ar' === $lang;
		$card  = Pangaea_Experiences_Archive::card_data( $id );

		$json = static function ( $raw ) {
			$decoded = json_decode( (string) $raw, true );
			return is_array( $decoded ) ? array_values( $decoded ) : array();
		};
		$g = static function ( string $key, $default = '' ) use ( $id ) {
			return Pangaea_Experiences::g( $id, $key, $default );
		};
		// Arabic content lives in ar_-prefixed meta on the SAME post (the
		// Experiences vendor portal's own AI/manual-translation system, not
		// WPML) — fall back to the English value when no Arabic one was set.
		$field = static function ( string $key ) use ( $g, $is_ar ) {
			if ( $is_ar ) {
				$ar = (string) $g( 'ar_' . $key, '' );
				if ( '' !== $ar ) {
					return $ar;
				}
			}
			return (string) $g( $key, '' );
		};
		$list_field = static function ( string $key ) use ( $g, $json, $is_ar ) {
			if ( $is_ar ) {
				$ar = $json( $g( 'ar_' . $key, '[]' ) );
				if ( ! empty( $ar ) ) {
					return array_map( 'strval', $ar );
				}
			}
			return array_map( 'strval', $json( $g( $key, '[]' ) ) );
		};

		$title   = $is_ar ? (string) $g( 'ar_title', '' ) : '';
		$title   = '' !== $title ? $title : get_the_title( $id );
		$content = $field( 'content' );
		$content = '' !== $content ? $content : (string) $post->post_content;

		$gallery = array();
		foreach ( array_filter( array_map( 'intval', explode( ',', (string) $g( 'gallery', '' ) ) ) ) as $attachment_id ) {
			$url = wp_get_attachment_image_url( $attachment_id, 'large' );
			if ( $url ) {
				$gallery[] = array( 'id' => $attachment_id, 'url' => $url );
			}
		}

		// The app only surfaces one hero video per Experience — first entry
		// of the video_urls JSON list, previously not returned at all.
		$video_urls = $json( $g( 'video_urls', '[]' ) );
		$video_url  = isset( $video_urls[0] ) ? (string) $video_urls[0] : '';

		// Pangaea-admin-only override (Experience edit screen > Booking Link
		// tab) — mirrors the exact gate in
		// Pangaea_Experiences_Booking_Widget::render(): when enabled AND a
		// link is actually set, the website skips its own quote/checkout
		// modal entirely and sends the visitor straight to this URL instead.
		// The app needs the same signal so it can skip its normal booking
		// flow for this Experience too, rather than rendering pricing/date
		// pickers the website itself never shows for it. Previously not
		// exposed at all — verified live and currently enabled on a real
		// Experience.
		$custom_booking_enabled = 1 === (int) $g( 'custom_booking_enable', 0 );
		$custom_booking_link    = trim( (string) $g( 'custom_booking_link', '' ) );
		if ( ! $custom_booking_enabled || '' === $custom_booking_link ) {
			$custom_booking_enabled = false;
			$custom_booking_link    = '';
		}

		// Pangaea_Experiences_Archive::card_data()'s vendor_name is
		// intentionally always the generic "PANGAEA" brand strap for the
		// public archive card grid — but the Experience detail payload should
		// surface the REAL vendor when one is actually assigned via
		// _pga_exp_vendor_id, falling back to that same generic brand only
		// when no real vendor is set (previously always hardcoded here too).
		$vendor_name = $card['vendor_name'];
		$vendor_logo = '';
		$vendor_id   = (int) $g( 'vendor_id', 0 );
		if ( $vendor_id > 0 && class_exists( 'Pangaea_Vendors' ) ) {
			$vendor_post = get_post( $vendor_id );
			if ( $vendor_post && Pangaea_Vendors::CPT === $vendor_post->post_type && 'publish' === $vendor_post->post_status ) {
				$real_vendor_name = get_the_title( $vendor_id );
				if ( '' !== $real_vendor_name ) {
					$vendor_name = $real_vendor_name;
					$logo_id     = (int) Pangaea_Vendors::g( $vendor_id, 'logo_id', 0 );
					if ( $logo_id > 0 ) {
						$logo_url = wp_get_attachment_url( $logo_id );
						if ( $logo_url ) {
							$vendor_logo = $logo_url;
						}
					}
				}
			}
		}

		// Single source of truth shared with the archive/search card
		// (Pangaea_Experiences_Archive::card_data()) — a card's CTA and this
		// detail page can never disagree about whether the experience is
		// actually bookable right now.
		if ( method_exists( 'Pangaea_Experiences', 'booking_state' ) ) {
			$state           = Pangaea_Experiences::booking_state( $id );
			$is_sold_out     = $state['is_sold_out'];
			$is_coming_soon  = $state['is_coming_soon'];
			$is_enquiry_only = $state['is_enquiry_only'];
		} else {
			$is_sold_out     = 1 === (int) $g( 'sold_out_override', 0 );
			$is_coming_soon  = 1 === (int) $g( 'coming_soon_override', 0 );
			$is_enquiry_only = 1 === (int) $g( 'enquiry_override', 0 );
		}

		$payload = array_merge(
			$card,
			array(
				'title'              => pangaea_trip_data_text_field( $title ),
				'description'        => pangaea_trip_data_plain_text( $content ),
				'tagline'            => pangaea_trip_data_text_field( $field( 'tagline' ) ),
				'meeting_point'      => pangaea_trip_data_text_field( $field( 'meeting_point' ) ),
				'highlights'         => $list_field( 'highlights' ),
				'inclusions'         => $list_field( 'inclusions' ),
				'not_included'       => $list_field( 'not_included' ),
				'requirements'       => $list_field( 'requirements' ),
				'accessibility'      => pangaea_trip_data_text_field( $field( 'accessibility' ) ),
				'languages'          => pangaea_trip_data_text_field( $field( 'languages' ) ),
				'important_info'     => pangaea_trip_data_plain_text( $field( 'important_info' ) ),
				'weather_info'       => pangaea_trip_data_plain_text( $field( 'weather_info' ) ),
				'itinerary'          => self::normalize_experience_itinerary( $json( $g( 'itinerary', '[]' ) ) ),
				'faqs'               => $json( $g( 'faqs', '[]' ) ),
				'pricing_categories' => self::normalize_experience_pricing_categories( $json( $g( 'pricing_categories', '[]' ) ) ),
				'cancellation_policy'    => pangaea_trip_data_plain_text( $field( 'cancellation_policy' ) ),
				'extra_services'         => $json( $g( 'extra_services', '[]' ) ),
				'duration_value'         => '' !== $g( 'duration_value', '' ) ? (float) $g( 'duration_value', '' ) : null,
				'duration_unit'          => (string) $g( 'duration_unit', '' ),
				'video_url'              => $video_url,
				'custom_booking_enabled' => $custom_booking_enabled,
				'custom_booking_link'    => $custom_booking_link,
				'vendor_name'            => $vendor_name,
				'vendor_logo'            => $vendor_logo,
				'location_label'         => pangaea_trip_data_text_field( (string) $g( 'location_label', '' ) ),
				'gallery'            => $gallery,
				'min_travellers'     => (int) $g( 'min_travellers', 1 ),
				'total_seats'        => (int) $g( 'total_seats', 0 ),
				'schedule_dates'     => self::normalize_experience_schedule_dates( $json( $g( 'schedule_dates', '[]' ) ) ),
				'is_sold_out'        => $is_sold_out,
				'is_coming_soon'     => $is_coming_soon,
				'is_enquiry_only'    => $is_enquiry_only,
				// One unambiguous field instead of three the app would
				// otherwise have to prioritise itself — same reasoning, same
				// precedence order (coming_soon > enquiry > sold_out >
				// book_now) as the equivalent trip fix earlier this session.
				'booking_type'       => $is_coming_soon
					? 'coming_soon'
					: ( $is_enquiry_only ? 'enquiry' : ( $is_sold_out ? 'sold_out' : 'book_now' ) ),
				// A physical meeting point's real coordinates don't change by
				// language — always read from the original (English)
				// experience via canonical_id(), same as the website template,
				// so an Arabic-locale request can never show a different pin.
				'lat'                => ( '' !== ( $lat_raw = Pangaea_Experiences::g( Pangaea_Experiences::canonical_id( $id ), 'location_lat', '' ) ) ) ? (float) $lat_raw : null,
				'lng'                => ( '' !== ( $lng_raw = Pangaea_Experiences::g( Pangaea_Experiences::canonical_id( $id ), 'location_lng', '' ) ) ) ? (float) $lng_raw : null,
				'language'           => $lang,
			)
		);

		return self::ok( $payload );
	}

	/**
	 * The Experiences vendor portal stores an unset numeric field as an
	 * empty string, not null/0 — the app crashed parsing sale_price/max_pax
	 * as numbers on 49/50 experiences. Coerced to null here (not 0): a real
	 * 0 would mean "free"/"no cap", which is a different, wrong meaning from
	 * "not set".
	 */
	private static function normalize_experience_pricing_categories( array $categories ) {
		foreach ( $categories as &$category ) {
			if ( ! is_array( $category ) ) {
				continue;
			}
			foreach ( array( 'sale_price', 'max_pax' ) as $key ) {
				if ( isset( $category[ $key ] ) && '' === $category[ $key ] ) {
					$category[ $key ] = null;
				}
			}
		}
		return $categories;
	}

	/**
	 * Each itinerary step already carries an explicit `format` field
	 * ("bullets" or "plain") set by whoever authored it — this isn't a
	 * detection problem. The inconsistency reported ("sometimes plain,
	 * sometimes bullet points") is that some "bullets"-format steps were
	 * typed with a literal `*` marker per line and others weren't, even
	 * though both are tagged the same way — so two steps meaning the same
	 * thing render differently. Normalizes every "bullets" step to the same
	 * single marker regardless of whether the original text had one,
	 * without inventing structure `format: "plain"` steps never had.
	 */
	private static function normalize_experience_itinerary( $itinerary ) {
		if ( ! is_array( $itinerary ) ) {
			return array();
		}
		foreach ( $itinerary as &$step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			// Steps saved before this admin form had a format selector at all
			// have no `format` key in storage whatsoever (not "plain", just
			// genuinely absent) — normalize it here too, the same way
			// description gets normalized, so the documented API contract
			// ("format is always 'bullets' or 'plain'") is actually true,
			// rather than relying on every client to independently default
			// a missing key the same way.
			$step['format'] = 'bullets' === ( $step['format'] ?? '' ) ? 'bullets' : 'plain';

			$description = str_replace( array( "\r\n", "\r" ), "\n", (string) ( $step['description'] ?? '' ) );
			$lines       = array_values( array_filter( array_map( 'trim', explode( "\n", $description ) ), 'strlen' ) );
			if ( 'bullets' === $step['format'] ) {
				$lines = array_map(
					static function ( $line ) {
						return '- ' . ltrim( $line, "*-•\t " );
					},
					$lines
				);
			}
			$step['description'] = implode( "\n", $lines );
		}
		unset( $step );
		return $itinerary;
	}

	/**
	 * Drops past schedule_dates entries (293/1250 were in the past, and
	 * quoting one 409s as invalid_slot) and coerces a blank `seats` to null
	 * rather than 0 — an empty seats value here means "not capacity-tracked
	 * for this slot", not "sold out"; turning it into 0 would make a
	 * bookable slot look full.
	 */
	private static function normalize_experience_schedule_dates( array $dates ) {
		$today = wp_date( 'Y-m-d' );
		$out   = array();
		foreach ( $dates as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$date = (string) ( $entry['date'] ?? '' );
			if ( '' !== $date && substr( $date, 0, 10 ) < $today ) {
				continue;
			}
			if ( isset( $entry['seats'] ) && '' === $entry['seats'] ) {
				$entry['seats'] = null;
			}
			$out[] = $entry;
		}
		return array_values( $out );
	}

	private static function trip_packages_payload( $trip_id ) {
		if ( function_exists( 'pangaea_trip_data_trip_packages' ) ) {
			$packages = pangaea_trip_data_trip_packages( $trip_id );
			if ( is_array( $packages ) ) {
				return $packages;
			}
		}
		$ids = get_post_meta( $trip_id, 'packages_ids', true );
		$ids = is_array( $ids ) ? $ids : array();
		$out = array();
		foreach ( $ids as $package_id ) {
			$out[] = array(
				'id'         => (int) $package_id,
				'name'       => get_the_title( $package_id ),
				'departures' => self::package_dates_payload( (int) $package_id ),
			);
		}
		return $out;
	}

	private static function package_dates_payload( $package_id ) {
		$dates = get_post_meta( $package_id, 'package-dates', true );
		return is_array( $dates ) ? $dates : array();
	}

	private static function departures_payload( $trip_id ) {
		$out = array();
		foreach ( self::trip_packages_payload( $trip_id ) as $package ) {
			if ( empty( $package['departures'] ) || ! is_array( $package['departures'] ) ) {
				continue;
			}
			foreach ( $package['departures'] as $departure ) {
				$out[] = array(
					'package_id' => (int) ( $package['id'] ?? 0 ),
					'package_name' => (string) ( $package['name'] ?? '' ),
					'departure' => $departure,
				);
			}
		}
		return $out;
	}

	private static function gallery_payload( $trip_id ) {
		$ids = get_post_meta( $trip_id, 'wpte_gallery_id', true );
		$ids = is_array( $ids ) ? $ids : array_filter( array_map( 'absint', explode( ',', (string) $ids ) ) );
		$out = array();
		foreach ( $ids as $id ) {
			$image = self::image_payload( (int) $id );
			if ( $image ) {
				$out[] = $image;
			}
		}
		$thumb = get_post_thumbnail_id( $trip_id );
		if ( $thumb ) {
			array_unshift( $out, self::image_payload( $thumb ) );
		}
		return array_values( array_filter( $out ) );
	}

	/**
	 * Strips a rich-text itinerary description down to plain text the same
	 * way pangaea_trip_data_plain_text() does everywhere else, EXCEPT that
	 * helper collapses all whitespace to single spaces after stripping tags
	 * — which silently destroys a bullet list ("<li>A</li><li>B</li>"
	 * becomes "A B", one run-on sentence, no indication there were ever two
	 * separate points). Each list item and paragraph break is converted to
	 * its own line, with a leading "- " marker for list items, before
	 * tags/comments are stripped, so the app can render each line as its
	 * own bullet/paragraph instead of losing the structure entirely.
	 */
	private static function format_itinerary_description( $html ) {
		$html = is_string( $html ) ? trim( $html ) : '';
		if ( '' === $html ) {
			return '';
		}
		$html = preg_replace( '/<!--\s*\/?wp:[^>]*-->/', '', $html );
		$html = preg_replace( '/<li[^>]*>/i', "\n- ", $html );
		$html = preg_replace( '/<\/(li|p|div|h[1-6])>/i', "\n", $html );
		$html = preg_replace( '/<br\s*\/?>/i', "\n", $html );
		$html = wp_strip_all_tags( $html, false );
		$html = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );
		// Collapse repeated blank lines/trailing spaces from the tag
		// stripping above, but never touch the newlines themselves — those
		// are the whole point.
		$lines = array_filter( array_map( 'trim', explode( "\n", $html ) ), 'strlen' );
		return implode( "\n", $lines );
	}

	private static function itinerary_payload( $trip_id ) {
		$advanced_raw = get_post_meta( $trip_id, 'wte_advanced_itinerary', true );
		$advanced     = is_array( $advanced_raw['advanced_itinerary'] ?? null ) ? $advanced_raw['advanced_itinerary'] : array();

		$settings   = get_post_meta( $trip_id, 'wp_travel_engine_setting', true );
		$settings   = is_array( $settings ) ? $settings : array();
		$basic      = is_array( $settings['itinerary'] ?? null ) ? $settings['itinerary'] : array();

		// Days can be keyed under either source (or both) — a trip's basic
		// title/label/description meta and its advanced per-day activities/
		// facilities meta are two separate WTE fields that were previously
		// read as one-or-the-other, never merged, silently dropping whichever
		// half wasn't picked. Every day key seen in either source gets a row.
		$day_keys = array_unique(
			array_merge(
				array_keys( $advanced['activity_steps'] ?? array() ),
				array_keys( $basic['itinerary_title'] ?? array() )
			)
		);
		// Both sources use the same string-keyed-by-number convention ("1",
		// "2", ...) — sort numerically so day order is correct regardless of
		// which source's keys happened to come first into the array above.
		usort( $day_keys, static function ( $a, $b ) { return (int) $a <=> (int) $b; } );

		$days = array();
		foreach ( $day_keys as $key ) {
			$facilities = array();
			foreach ( (array) ( $advanced['info'][ $key ] ?? array() ) as $facility ) {
				if ( is_array( $facility ) && isset( $facility['title'], $facility['value'] ) && '' !== trim( (string) $facility['value'] ) ) {
					$facilities[] = array(
						'title' => (string) $facility['title'],
						'value' => (string) $facility['value'],
					);
				}
			}

			$activities = array();
			foreach ( (array) ( $advanced['activity_steps'][ $key ] ?? array() ) as $step ) {
				if ( is_array( $step ) && '' !== trim( (string) ( $step['title'] ?? '' ) ) ) {
					$activities[] = array(
						'time'  => (string) ( $step['time'] ?? '' ),
						'title' => pangaea_trip_data_text_field( (string) ( $step['title'] ?? '' ) ),
					);
				}
			}

			$days[] = array(
				'day_number'      => (int) $key,
				'day_label'       => pangaea_trip_data_text_field( (string) ( $basic['itinerary_days_label'][ $key ] ?? '' ) ) ?: ( 'Day ' . str_pad( (string) (int) $key, 2, '0', STR_PAD_LEFT ) ),
				'title'           => pangaea_trip_data_text_field( (string) ( $basic['itinerary_title'][ $key ] ?? '' ) ),
				'description'     => self::format_itinerary_description( (string) ( $basic['itinerary_content'][ $key ] ?? '' ) ),
				'duration_value'  => (float) ( $advanced['itinerary_duration'][ $key ] ?? 0 ),
				'duration_type'   => (string) ( $advanced['itinerary_duration_type'][ $key ] ?? '' ),
				'sleep_mode'      => pangaea_trip_data_text_field( (string) ( $advanced['sleep_modes'][ $key ] ?? '' ) ),
				'overnight_at'    => pangaea_trip_data_text_field( (string) ( $advanced['overnight'][ $key ]['at'] ?? '' ) ),
				'meals_included'  => array_values( array_filter( (array) ( $advanced['meals_included'][ $key ] ?? array() ) ) ),
				'facilities'      => $facilities,
				'activities'      => $activities,
			);
		}

		return $days;
	}

	private static function suggested_flights_payload( $trip_id ) {
		$source_id = $trip_id;
		if ( function_exists( 'pangaea_suggested_flights_get_source_trip_id' ) ) {
			$maybe = (int) pangaea_suggested_flights_get_source_trip_id( $trip_id );
			if ( $maybe > 0 ) {
				$source_id = $maybe;
			}
		}
		$arrivals = function_exists( 'pangaea_suggested_flights_get_rows_for_display' )
			? pangaea_suggested_flights_get_rows_for_display( $trip_id, 'arrivals' )
			: get_post_meta( $source_id, '_pangaea_trip_arrivals', true );
		$departures = function_exists( 'pangaea_suggested_flights_get_rows_for_display' )
			? pangaea_suggested_flights_get_rows_for_display( $trip_id, 'departures' )
			: get_post_meta( $source_id, '_pangaea_trip_departures', true );
		return array(
			'arrivals'   => is_array( $arrivals ) ? array_values( $arrivals ) : array(),
			'departures' => is_array( $departures ) ? array_values( $departures ) : array(),
		);
	}

	/**
	 * "Travel Requirement" and its sibling tabs (Important Information,
	 * Weather, Currency & Payment, Entry Visa, Adaptation During Travel,
	 * Tips) are dynamic wp_editor tabs configured site-wide in WP Travel
	 * Engine's own trip-tabs settings (option `wp_travel_engine_settings`,
	 * `trip_tabs`) — identified only by `sanitize_title()` of whatever name
	 * an admin gave that tab, same convention pangaea-wte-trip-tabs.php
	 * already uses to relabel/reorder them for the website. A tab's actual
	 * per-trip CONTENT lives on the trip's own `wp_travel_engine_setting`
	 * meta, under `tab_content[$key . '_wpeditor']` — where `$key` is that
	 * tab's own array key in the global `trip_tabs['id']` list (confirmed
	 * live: every tab's key equals its id, e.g. "Travel Requirement" is
	 * id/key 15, so its content is `tab_content['15_wpeditor']`), NOT a
	 * small sequential per-trip counter. Replicates WTE's own
	 * wte_get_active_single_trip_tabs() lookup exactly, rather than
	 * guessing at the indexing. Only present in the response when that tab
	 * both exists in the site's tab configuration AND has real content on
	 * this specific trip — "if exists in a journey" per the request.
	 */
	private static function travel_requirements_payload( $trip_id ) {
		$settings  = get_option( 'wp_travel_engine_settings', array() );
		$trip_tabs = is_array( $settings['trip_tabs'] ?? null ) && is_array( $settings['trip_tabs']['id'] ?? null )
			? $settings['trip_tabs']
			: ( function_exists( 'wte_get_default_settings_tab' ) ? wte_get_default_settings_tab() : array() );

		if ( empty( $trip_tabs['id'] ) || ! is_array( $trip_tabs['id'] ) ) {
			return array();
		}

		$post_meta   = get_post_meta( $trip_id, 'wp_travel_engine_setting', true );
		$post_meta   = is_array( $post_meta ) ? $post_meta : array();
		$tab_content = is_array( $post_meta['tab_content'] ?? null ) ? $post_meta['tab_content'] : array();

		$wanted = array(
			'travel_requirement'       => 'travel-requirement',
			'important_information'    => 'important-information',
			'weather'                  => 'weather',
			'currency_and_payment'     => array( 'currency-payment', 'currency-and-payment' ),
			'entry_visa'               => 'entry-visa',
			'adaptation_during_travel' => 'adaptation-during-travel',
			'tips'                     => 'tips',
		);

		$out = array();
		foreach ( $trip_tabs['id'] as $key => $tab_id ) {
			if ( 'wp_editor' !== ( $trip_tabs['field'][ $tab_id ] ?? '' ) ) {
				continue;
			}
			$slug = sanitize_title( (string) ( $trip_tabs['name'][ $tab_id ] ?? '' ) );
			foreach ( $wanted as $out_key => $slugs ) {
				if ( isset( $out[ $out_key ] ) || ! in_array( $slug, (array) $slugs, true ) ) {
					continue;
				}
				$text = self::format_itinerary_description( (string) ( $tab_content[ $key . '_wpeditor' ] ?? '' ) );
				if ( '' !== $text ) {
					$out[ $out_key ] = $text;
				}
				break;
			}
		}

		return $out;
	}

	private static function trip_guides_payload( $trip_id, $lang = '' ) {
		$ids = get_post_meta( $trip_id, '_pgtm_trip_guides', true );
		$ids = is_array( $ids ) ? array_map( 'absint', $ids ) : array_filter( array_map( 'absint', explode( ',', (string) $ids ) ) );
		$out = array();
		foreach ( $ids as $id ) {
			$post = get_post( self::translated_id( $id, get_post_type( $id ), $lang ) );
			if ( $post ) {
				$out[] = self::team_member_payload( $post );
			}
		}
		return $out;
	}

	private static function team_member_payload( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		$image_id     = get_post_thumbnail_id( $post );
		$display_name = (string) get_post_meta( $post->ID, 'name', true );
		$social       = array();
		foreach ( array( 'email', 'facebook', 'instagram', 'tiktok', 'snapchat', 'x', 'youtube' ) as $network ) {
			$value = trim( (string) get_post_meta( $post->ID, 'social_links_' . $network, true ) );
			if ( '' !== $value ) {
				$social[ $network ] = $value;
			}
		}
		return array(
			'id'                 => (int) $post->ID,
			'title'              => get_the_title( $post ),
			'display_name'       => '' !== $display_name ? $display_name : get_the_title( $post ),
			'position'           => (string) get_post_meta( $post->ID, 'title', true ),
			'slug'               => $post->post_name,
			'bio'                => apply_filters( 'the_content', $post->post_content ),
			'excerpt'            => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'image'              => $image_id ? self::image_payload( $image_id ) : null,
			'priority'           => get_post_meta( $post->ID, 'priority', true ),
			'languages'          => (string) get_post_meta( $post->ID, 'languages', true ),
			'location'           => (string) get_post_meta( $post->ID, 'location', true ),
			'guiding_philosophy' => (string) get_post_meta( $post->ID, 'guiding_philosophy', true ),
			'years_experience'   => (string) get_post_meta( $post->ID, 'years_experience', true ),
			'qualifications'     => get_post_meta( $post->ID, 'qualifications_list', true ),
			'social_links'       => $social,
			'meta'               => array(
				'role'      => wp_get_post_terms( $post->ID, 'pgtm_role', array( 'fields' => 'names' ) ),
				'specialty' => wp_get_post_terms( $post->ID, 'pgtm_specialty', array( 'fields' => 'names' ) ),
			),
		);
	}

	public static function quote( $request ) {
		$data = self::request_data( $request );
		$trip_id = (int) ( $data['trip_id'] ?? 0 );
		if ( $trip_id <= 0 || 'trip' !== get_post_type( $trip_id ) ) {
			return self::fail( 'invalid_trip', 'Valid trip_id is required.' );
		}
		// is_bookable in the trip payload used to be computed purely from
		// departure/seat data, with no regard for the Coming-Soon flag or the
		// site's Enquiry-only override — so the app could show "Book Now" for
		// a trip the website itself never lets anyone book directly, and the
		// user would only find out here, mid-quote/checkout, with no clean
		// error (confirmed live on real Enquiry and Coming-Soon trips).
		// checkout_create_order() calls this same quote() internally, so this
		// one guard covers both endpoints.
		if ( function_exists( 'pangaea_trip_is_coming_soon' ) && pangaea_trip_is_coming_soon( $trip_id ) ) {
			return self::fail( 'trip_coming_soon', 'This trip is not yet open for booking.', 409 );
		}
		// trip_is_local_form() must be checked too, not just trip_is_enquiry_only()
		// — 'internal' trips now correctly return false from
		// trip_is_enquiry_only() (they're their own distinct state), so without
		// this they'd stop being blocked here and become bookable through the
		// real checkout again.
		if ( self::trip_is_enquiry_only( $trip_id ) || self::trip_is_local_form( $trip_id ) ) {
			return self::fail( 'trip_enquiry_only', 'This trip requires an enquiry, not a direct booking.', 409 );
		}
		$packages = self::trip_packages_payload( $trip_id );
		$package_id = (int) ( $data['package_id'] ?? 0 );
		$package = null;
		foreach ( $packages as $candidate ) {
			if ( $package_id > 0 && (int) ( $candidate['id'] ?? 0 ) === $package_id ) {
				$package = $candidate;
				break;
			}
		}
		if ( ! $package && ! empty( $packages ) ) {
			$package = reset( $packages );
			$package_id = (int) ( $package['id'] ?? 0 );
		}
		$travellers = isset( $data['travellers'] ) && is_array( $data['travellers'] ) ? $data['travellers'] : array();
		$base_price = self::trip_base_price( $trip_id );
		$lines = array();
		$subtotal = 0.0;
		if ( ! empty( $travellers ) ) {
			foreach ( $travellers as $row ) {
				$qty = max( 0, (int) ( $row['quantity'] ?? 1 ) );
				$label = sanitize_text_field( (string) ( $row['label'] ?? 'Adult' ) );
				$price = self::traveller_unit_price( $package, $row, $base_price );
				if ( $qty <= 0 ) {
					continue;
				}
				$total = $qty * $price;
				$subtotal += $total;
				$lines[] = array(
					'label'       => $label,
					'category_id' => isset( $row['category_id'] ) ? (int) $row['category_id'] : 0,
					'quantity'    => $qty,
					'unit_price'  => round( $price, 2 ),
					'total'       => round( $total, 2 ),
				);
			}
		}
		if ( empty( $lines ) ) {
			$qty = max( 1, (int) ( $data['pax'] ?? 1 ) );
			$subtotal = $qty * $base_price;
			$lines[] = array( 'label' => 'Adult', 'quantity' => $qty, 'unit_price' => $base_price, 'total' => $subtotal );
		}
		$addon_lines = self::quote_addon_lines( $trip_id, $data );
		foreach ( $addon_lines as $addon_line ) {
			$subtotal += $addon_line['total'];
		}
		$coupon = sanitize_text_field( (string) ( $data['coupon'] ?? '' ) );
		$discount = self::coupon_discount( $coupon, $subtotal );
		$total = max( 0, $subtotal - $discount['amount'] );
		// Deposit/partial payment is confirmed off — PANGAEA does not offer
		// it at all, and checkout would silently force full payment anyway
		// (pangaea-disable-wte-partial-when-inactive.php). Always quoting
		// the full total regardless of what payment_mode/deposit_amount the
		// client sends means the quote can never promise a "pay less now"
		// amount that the real order won't honor.
		$deposit = $total;
		return self::ok(
			array(
				'trip_id'        => $trip_id,
				'package_id'     => $package_id,
				'currency'       => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'SAR',
				'available'      => true,
				'lines'          => $lines,
				'addons'         => $addon_lines,
				'subtotal'       => round( $subtotal, 2 ),
				'discount'       => $discount,
				'total'          => round( $total, 2 ),
				'payment_mode'   => $deposit < $total ? 'deposit' : 'full',
				'payable_now'    => round( $deposit, 2 ),
				'remaining_due'  => round( max( $total - $deposit, 0 ), 2 ),
				// A soft, UI-only expiry for the "Price held for MM:SS"
				// countdown — nothing actually reserves this price or these
				// seats server-side; availability/total are simply
				// re-quoted fresh at order creation, same as today.
				'expires_at'     => gmdate( 'c', time() + 15 * MINUTE_IN_SECONDS ),
			)
		);
	}

	private static function traveller_unit_price( $package, $row, $fallback ) {
		$category_id = isset( $row['category_id'] ) ? (int) $row['category_id'] : 0;
		$label = strtolower( trim( (string) ( $row['label'] ?? '' ) ) );
		$categories = is_array( $package ) && isset( $package['traveler_categories'] ) && is_array( $package['traveler_categories'] ) ? $package['traveler_categories'] : array();
		foreach ( $categories as $category ) {
			if ( ! is_array( $category ) ) {
				continue;
			}
			$cat_id = (int) ( $category['id'] ?? 0 );
			$cat_label = strtolower( trim( (string) ( $category['label'] ?? $category['age_group'] ?? '' ) ) );
			if ( ( $category_id > 0 && $cat_id === $category_id ) || ( $label && $cat_label && $label === $cat_label ) ) {
				if ( isset( $category['sale_price'] ) && is_numeric( $category['sale_price'] ) && (float) $category['sale_price'] > 0 ) {
					return (float) $category['sale_price'];
				}
				if ( isset( $category['price'] ) && is_numeric( $category['price'] ) ) {
					return (float) $category['price'];
				}
			}
		}
		foreach ( array( 'adult_sale_price', 'adult_price', 'sale_price', 'price' ) as $key ) {
			if ( is_array( $package ) && isset( $package[ $key ] ) && is_numeric( $package[ $key ] ) && (float) $package[ $key ] > 0 ) {
				return (float) $package[ $key ];
			}
		}
		return (float) $fallback;
	}

	private static function trip_base_price( $trip_id ) {
		$direct = (float) get_post_meta( $trip_id, 'wp_travel_engine_setting_trip_price', true );
		if ( $direct > 0 ) {
			return $direct;
		}
		$settings = get_post_meta( $trip_id, 'wp_travel_engine_setting', true );
		$settings = is_array( $settings ) ? $settings : array();
		return (float) ( $settings['trip_price'] ?? 0 );
	}

	private static function coupon_discount( $coupon, $subtotal ) {
		$out = array( 'code' => $coupon, 'amount' => 0, 'valid' => false, 'message' => '' );
		if ( '' === $coupon || ! class_exists( 'WC_Coupon' ) ) {
			return $out;
		}
		$wc_coupon = new WC_Coupon( $coupon );
		if ( ! $wc_coupon->get_id() ) {
			$out['message'] = 'Coupon not found.';
			return $out;
		}
		$type = $wc_coupon->get_discount_type();
		$amount = (float) $wc_coupon->get_amount();
		if ( 'percent' === $type ) {
			$out['amount'] = round( $subtotal * ( $amount / 100 ), 2 );
		} else {
			$out['amount'] = min( $subtotal, $amount );
		}
		$out['valid'] = true;
		$out['type'] = $type;
		return $out;
	}

	public static function cart_get( $request ) {
		$uid = self::user_id_from_request( $request );
		$cart = get_user_meta( $uid, self::CART_META, true );
		return self::ok( is_array( $cart ) ? $cart : array( 'items' => array(), 'coupons' => array() ) );
	}

	public static function cart_add_item( $request ) {
		$uid = self::user_id_from_request( $request );
		$cart = get_user_meta( $uid, self::CART_META, true );
		$cart = is_array( $cart ) ? $cart : array( 'items' => array(), 'coupons' => array() );
		$item = self::sanitize_deep( self::request_data( $request ) );
		$item['id'] = wp_generate_uuid4();
		$cart['items'][] = $item;
		update_user_meta( $uid, self::CART_META, $cart );
		return self::ok( $cart, 201 );
	}

	public static function cart_apply_coupon( $request ) {
		$uid  = self::user_id_from_request( $request );
		$data = self::request_data( $request );
		$code = sanitize_text_field( (string) ( $data['code'] ?? '' ) );
		if ( '' === $code ) {
			return self::fail( 'coupon_required', 'A coupon code is required.' );
		}
		// Checked for real against WooCommerce here (not deferred silently
		// to /quote) so an invalid/expired code is rejected immediately
		// instead of being stored and only surfacing as a wrong total later.
		$check = self::coupon_discount( $code, 0 );
		if ( ! $check['valid'] ) {
			return self::fail( 'coupon_invalid', $check['message'] ?: 'This coupon code is not valid.' );
		}
		$cart = get_user_meta( $uid, self::CART_META, true );
		$cart = is_array( $cart ) ? $cart : array( 'items' => array(), 'coupons' => array() );
		if ( ! in_array( $code, (array) $cart['coupons'], true ) ) {
			$cart['coupons'][] = $code;
		}
		update_user_meta( $uid, self::CART_META, $cart );
		return self::ok( $cart );
	}

	public static function cart_remove_coupon( $request ) {
		$uid = self::user_id_from_request( $request );
		$code = (string) $request['code'];
		$cart = get_user_meta( $uid, self::CART_META, true );
		$cart = is_array( $cart ) ? $cart : array( 'items' => array(), 'coupons' => array() );
		$cart['coupons'] = array_values( array_diff( (array) $cart['coupons'], array( $code ) ) );
		update_user_meta( $uid, self::CART_META, $cart );
		return self::ok( $cart );
	}

	public static function checkout_schema( $request ) {
		self::lang( $request );
		// A payment gateway (Tamara) echoes its own gateway-description HTML
		// straight to output when its settings are touched outside a real
		// checkout page context, corrupting this response into invalid JSON
		// ("<div class=\"tamara-gateway-description\">…" prepended to the
		// body). Capturing and discarding it here is the one place every
		// gateway gets instantiated for this route; the actual (already
		// tag-stripped) description text still comes back normally below.
		ob_start();
		$payment_methods = self::payment_methods_payload();
		ob_end_clean();
		return self::ok(
			array(
				'billing' => array(
					array( 'key' => 'billing_first_name', 'label' => 'First name', 'type' => 'text', 'required' => true ),
					array( 'key' => 'billing_last_name', 'label' => 'Last name', 'type' => 'text', 'required' => true ),
					array( 'key' => 'billing_email', 'label' => 'Email', 'type' => 'email', 'required' => true ),
					array( 'key' => 'billing_phone', 'label' => 'Phone', 'type' => 'tel', 'required' => true ),
					// Was never in this schema at all, so the app had no way
					// to know it needed a selectable country field here (it
					// only ever showed one on the Profile screen, which
					// reads a different endpoint). Free-typing it let
					// mismatched, nonsense values through — e.g. a "Palau"
					// billing country paired with a US-formatted phone
					// number, which reads as a fraud signal to Tap's own
					// risk/3DS scoring and has caused genuine payment
					// declines. `options` is the same authoritative
					// ISO 3166-1 alpha-2 list update_billing() itself
					// validates against — build the dropdown from exactly
					// this, not a separately hardcoded list.
					array(
						'key'      => 'billing_country',
						'label'    => 'Country',
						'type'     => 'country_dropdown',
						'required' => true,
						'options'  => self::country_options(),
					),
				),
				'traveller_fields' => self::traveller_field_schema(),
				'payment_methods' => $payment_methods,
			)
		);
	}

	/**
	 * Required flags per the site owner's explicit product decision (overrides
	 * the website theme's own checkout field config in
	 * themes/tourm/functions.php, which marks nationality/emergency contact
	 * as required — the mobile app is deliberately less strict here): only
	 * Age and Sex are required among these "additional" fields; everything
	 * else (blood type, nationality, emergency contact, referral, medical
	 * notes) is optional. Passport document is kept in the schema (still
	 * accepted if sent) but the app has been asked to hide that field in the
	 * UI for now.
	 */
	private static function traveller_field_schema() {
		return array(
			array( 'key' => 'booking_age', 'meta_key' => '_booking_age', 'label' => 'Age', 'type' => 'number', 'required' => true ),
			array( 'key' => 'booking_sex', 'meta_key' => '_booking_sex', 'label' => 'Sex', 'type' => 'select', 'required' => true, 'options' => array( 'male', 'female' ) ),
			array( 'key' => 'booking_blood_type', 'meta_key' => '_booking_blood_type', 'label' => 'Blood type', 'type' => 'select', 'required' => false, 'options' => array( 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-' ) ),
			array( 'key' => 'booking_nationality', 'meta_key' => '_booking_nationality', 'label' => 'Nationality', 'type' => 'text', 'required' => false ),
			array( 'key' => 'booking_emergency_name', 'meta_key' => '_booking_emergency_name', 'label' => 'Emergency contact name', 'type' => 'text', 'required' => false ),
			array( 'key' => 'booking_emergency_phone', 'meta_key' => '_booking_emergency_phone', 'label' => 'Emergency contact phone', 'type' => 'tel', 'required' => false ),
			array( 'key' => 'booking_referral_staff_name', 'meta_key' => '_booking_referral_staff_name', 'label' => 'Referral staff name', 'type' => 'text', 'required' => false ),
			array( 'key' => 'booking_medical_notes', 'meta_key' => '_booking_medical_notes', 'label' => 'Medical notes', 'type' => 'textarea', 'required' => false ),
			array( 'key' => 'booking_passport_doc', 'meta_key' => '_booking_passport_doc_url', 'label' => 'Passport document', 'type' => 'file', 'required' => false ),
		);
	}

	/**
	 * Falls back to the customer's saved WC_Customer billing profile (the
	 * exact same source get_billing() reads) for any field the checkout
	 * request didn't explicitly include — matching the real website's own
	 * checkout, which pre-fills these for a logged-in customer instead of
	 * asking them to retype it on every booking.
	 */
	private static function billing_with_saved_defaults( array $billing, int $uid ): array {
		if ( ! $uid || ! class_exists( 'WC_Customer' ) ) {
			return $billing;
		}
		$customer = new WC_Customer( $uid );
		$user     = get_userdata( $uid );
		$defaults = array(
			'billing_first_name' => $customer->get_billing_first_name(),
			'billing_last_name'  => $customer->get_billing_last_name(),
			'billing_email'      => $customer->get_billing_email() ?: ( $user ? $user->user_email : '' ),
			'billing_phone'      => $customer->get_billing_phone(),
			'billing_country'    => $customer->get_billing_country(),
			'billing_city'       => $customer->get_billing_city(),
			'billing_address_1'  => $customer->get_billing_address_1(),
			'billing_postcode'   => $customer->get_billing_postcode(),
		);
		foreach ( $defaults as $key => $value ) {
			if ( '' !== (string) $value && ( ! isset( $billing[ $key ] ) || '' === trim( (string) $billing[ $key ] ) ) ) {
				$billing[ $key ] = $value;
			}
		}
		return $billing;
	}

	/**
	 * The same authoritative ISO 3166-1 alpha-2 list update_billing() (see
	 * self::update_billing()) validates a submitted country against — the
	 * checkout schema's billing_country dropdown is built from exactly
	 * this, so nothing the app can select there could ever fail that
	 * validation, and vice versa.
	 */
	private static function country_options() {
		$countries = ( function_exists( 'WC' ) && WC()->countries ) ? WC()->countries->get_countries() : array();
		$options   = array();
		foreach ( $countries as $code => $label ) {
			$options[] = array( 'code' => $code, 'label' => $label );
		}
		return $options;
	}

	/**
	 * Falls back to the customer's saved "Travel & Emergency" profile
	 * (`_pga_acct_*` — the same real, shared storage get_health() reads,
	 * see its own docblock) for any field a traveller entry doesn't
	 * explicitly include. Only ever called for the lead/first traveller —
	 * see the call site.
	 */
	private static function traveller_with_saved_defaults( array $traveller, int $uid ): array {
		if ( ! $uid ) {
			return $traveller;
		}
		$map = array(
			'booking_age'                 => '_pga_acct_age',
			'booking_sex'                 => '_pga_acct_sex',
			'booking_blood_type'          => '_pga_acct_blood_type',
			'booking_nationality'         => '_pga_acct_nationality',
			'booking_emergency_name'      => '_pga_acct_emergency_name',
			'booking_emergency_phone'     => '_pga_acct_emergency_phone',
			'booking_referral_staff_name' => '_pga_acct_referral_staff_name',
			'booking_medical_notes'       => '_pga_acct_medical_notes',
		);
		foreach ( $map as $key => $meta_key ) {
			if ( ! isset( $traveller[ $key ] ) || '' === trim( (string) $traveller[ $key ] ) ) {
				$value = get_user_meta( $uid, $meta_key, true );
				if ( '' !== (string) $value ) {
					$traveller[ $key ] = (string) $value;
				}
			}
		}
		if ( ! isset( $traveller['booking_passport_doc'] ) || '' === trim( (string) $traveller['booking_passport_doc'] ) ) {
			$passport_url = get_user_meta( $uid, '_pga_acct_passport_url', true );
			if ( $passport_url ) {
				$traveller['booking_passport_doc'] = (string) $passport_url;
			}
		}
		return $traveller;
	}

	/**
	 * Mirrors the website's own checkout exactly: pga_acct_save_billing_and_extra_fields()
	 * runs on every real web checkout (hooked on woocommerce_checkout_create_order)
	 * so any NEW info a customer types at checkout is saved back to their
	 * account, and next time it's already filled in. Mobile checkout builds
	 * its order directly via wc_create_order(), bypassing that hook entirely
	 * (same class of gap already fixed for the PANGAEA Balance discount) —
	 * without this, a first-time mobile customer's checkout details would
	 * never be remembered for their next booking, defeating the entire point
	 * of the saved-defaults fallback above.
	 */
	private static function save_billing_to_profile( array $billing, int $uid ): void {
		if ( ! $uid || ! class_exists( 'WC_Customer' ) ) {
			return;
		}
		$customer = new WC_Customer( $uid );
		$setters  = array(
			'billing_first_name' => 'set_billing_first_name',
			'billing_last_name'  => 'set_billing_last_name',
			'billing_phone'      => 'set_billing_phone',
			'billing_country'    => 'set_billing_country',
			'billing_city'       => 'set_billing_city',
			'billing_address_1'  => 'set_billing_address_1',
			'billing_postcode'   => 'set_billing_postcode',
		);
		$changed = false;
		foreach ( $setters as $key => $setter ) {
			if ( isset( $billing[ $key ] ) && '' !== trim( (string) $billing[ $key ] ) ) {
				$customer->$setter( sanitize_text_field( (string) $billing[ $key ] ) );
				$changed = true;
			}
		}
		if ( isset( $billing['billing_email'] ) && is_email( (string) $billing['billing_email'] ) ) {
			$customer->set_billing_email( sanitize_email( (string) $billing['billing_email'] ) );
			$changed = true;
		}
		if ( $changed ) {
			$customer->save();
		}
	}

	/** Same principle as save_billing_to_profile(), for the Travel & Emergency fields. */
	private static function save_traveller_to_profile( array $traveller, int $uid ): void {
		if ( ! $uid ) {
			return;
		}
		$map = array(
			'booking_age'                 => '_pga_acct_age',
			'booking_sex'                 => '_pga_acct_sex',
			'booking_blood_type'          => '_pga_acct_blood_type',
			'booking_nationality'         => '_pga_acct_nationality',
			'booking_emergency_name'      => '_pga_acct_emergency_name',
			'booking_emergency_phone'     => '_pga_acct_emergency_phone',
			'booking_referral_staff_name' => '_pga_acct_referral_staff_name',
			'booking_medical_notes'       => '_pga_acct_medical_notes',
		);
		foreach ( $map as $key => $meta_key ) {
			if ( ! isset( $traveller[ $key ] ) || '' === trim( (string) $traveller[ $key ] ) ) {
				continue;
			}
			$value = 'booking_age' === $key ? (int) $traveller[ $key ] : sanitize_text_field( (string) $traveller[ $key ] );
			update_user_meta( $uid, $meta_key, $value );
		}
	}

	private static function payment_methods_payload() {
		$out = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$gateways = WC()->payment_gateways()->get_available_payment_gateways();
			foreach ( $gateways as $id => $gateway ) {
				$out[] = array(
					'id'          => $id,
					'title'       => $gateway->get_title(),
					'description' => wp_strip_all_tags( $gateway->get_description() ),
					'enabled'     => 'yes' === $gateway->enabled,
				);
			}
		}
		if ( empty( $out ) ) {
			$out = array(
				array( 'id' => 'paytabs_creditcard', 'title' => 'PayTabs - Credit Card', 'enabled' => true ),
				array( 'id' => 'tamara', 'title' => 'Tamara', 'enabled' => true ),
			);
		}
		return $out;
	}

	public static function checkout_payment_modes( $request ) {
		$trip_id = (int) $request->get_param( 'trip_id' );
		$total = $trip_id > 0 ? self::trip_base_price( $trip_id ) : 0;
		// Deposit reported `available: true` unconditionally — regardless of
		// whether the partial-payment plugins are even active — so the app
		// could offer "pay 50% now" for a checkout that (per
		// pangaea-disable-wte-partial-when-inactive.php) always silently
		// forces full payment anyway. Confirmed: PANGAEA does not offer
		// partial payment at all, so this now says so honestly.
		return self::ok(
			array(
				'full'    => array( 'available' => true, 'payable_now' => $total ),
				'deposit' => array( 'available' => false, 'payable_now' => null, 'remaining_due' => null ),
				'coupons_allowed_for_deposit' => false,
			)
		);
	}

	public static function checkout_validate( $request ) {
		$data = self::request_data( $request );
		$errors = self::validate_billing_fields( (array) ( $data['billing'] ?? array() ) );
		return self::ok( array( 'valid' => empty( $errors ), 'errors' => $errors ) );
	}

	/**
	 * Shared with checkout_create_order()/checkout_create_experience_order(),
	 * which now call this BEFORE creating any order — previously only this
	 * advisory /checkout/validate endpoint checked billing_email format, and
	 * nothing forced a client to call it first. An invalid email reaching
	 * apply_order_billing() used to throw an uncaught WC_Data_Exception
	 * (WC_Order::set_billing_email() rejects anything failing is_email()),
	 * crashing the whole request as a raw PHP fatal after the order/line-item
	 * had already been created — leaving a permanent, wrongly-priced "ghost"
	 * order behind (confirmed in production, order #47092: a trailing comma
	 * in the email, e.g. "name@example.com,", crashed billing application
	 * after the SAR 2,200 line item was already saved, leaving the order
	 * stuck at total 0.00 with none of this API's own tracking meta ever
	 * written). Validating first stops the order from ever being created.
	 */
	private static function validate_billing_fields( array $billing ) {
		$errors = array();
		foreach ( array( 'billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone', 'billing_country' ) as $key ) {
			if ( empty( $billing[ $key ] ) ) {
				$errors[ $key ] = 'Required.';
			}
		}
		if ( ! empty( $billing['billing_email'] ) && ! is_email( (string) $billing['billing_email'] ) ) {
			$errors['billing_email'] = 'Invalid email.';
		}
		// Same check update_billing() already enforces for the profile —
		// required here too now, since an unvalidated free-typed country
		// (e.g. a stray "Palau" paired with a US-formatted phone number)
		// reads as a fraud signal to Tap's own risk/3DS scoring and has
		// caused genuine payment declines, independent of the card used.
		if ( ! empty( $billing['billing_country'] ) ) {
			$country = strtoupper( sanitize_text_field( (string) $billing['billing_country'] ) );
			$valid   = ( function_exists( 'WC' ) && WC()->countries ) ? WC()->countries->get_countries() : array();
			if ( ! isset( $valid[ $country ] ) ) {
				$errors['billing_country'] = 'Must be a valid ISO 3166-1 alpha-2 code.';
			}
		}
		return $errors;
	}

	public static function checkout_create_order( $request ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return self::fail( 'woocommerce_missing', 'WooCommerce is not available.', 503 );
		}
		$data = self::request_data( $request );
		$uid  = self::user_id_from_request( $request );

		// Validated AFTER merging the customer's saved billing defaults, not
		// on the raw request alone — otherwise a returning customer who
		// correctly omits already-saved fields (the whole point of
		// billing_with_saved_defaults()) would fail validation here despite
		// checkout being able to fill them in correctly a few lines below.
		$billing = isset( $data['billing'] ) && is_array( $data['billing'] ) ? $data['billing'] : array();
		$billing = self::billing_with_saved_defaults( $billing, $uid );
		$billing_errors = self::validate_billing_fields( $billing );
		if ( ! empty( $billing_errors ) ) {
			return self::fail( 'invalid_billing', 'Please check the billing details.', 422, array( 'errors' => $billing_errors ) );
		}

		// Same reasoning, computed and validated here too — before any order
		// exists — rather than after order/line-item creation as before,
		// which left a ghost order behind on a failed traveller-fields
		// validation (same class of problem as the billing ghost-order bug
		// fixed earlier this session, just via a clean 422 instead of a
		// crash). Saved-profile fallback (age/sex/blood type/nationality/
		// emergency contact/etc.) only applies to the FIRST/lead traveller —
		// that's the logged-in customer themselves; additional travellers
		// are other people, so there's no saved profile to fall back to for
		// them, exactly like the website's own checkout only pre-fills the
		// customer's own row.
		$travellers_in = array();
		if ( ! empty( $data['travellers'] ) && is_array( $data['travellers'] ) ) {
			foreach ( $data['travellers'] as $index => $traveller ) {
				if ( ! is_array( $traveller ) ) {
					continue;
				}
				if ( 0 === $index ) {
					$traveller = self::traveller_with_saved_defaults( $traveller, $uid );
				}
				$row = array();
				foreach ( self::traveller_field_schema() as $field ) {
					$key = $field['key'];
					if ( isset( $traveller[ $key ] ) ) {
						$row[ $key ] = sanitize_textarea_field( (string) $traveller[ $key ] );
					}
				}
				if ( $row ) {
					$travellers_in[] = $row;
				}
			}
		}
		$lead_traveller = $travellers_in ? $travellers_in[0] : self::traveller_with_saved_defaults(
			is_array( $data['traveller_fields'] ?? null ) ? $data['traveller_fields'] : array(),
			$uid
		);
		$traveller_errors = array();
		foreach ( self::traveller_field_schema() as $field ) {
			if ( ! empty( $field['required'] ) && '' === trim( (string) ( $lead_traveller[ $field['key'] ] ?? '' ) ) ) {
				$traveller_errors[ $field['key'] ] = 'Required.';
			}
		}
		if ( ! empty( $traveller_errors ) ) {
			return self::fail( 'invalid_traveller', 'Please check the traveller details.', 422, array( 'errors' => $traveller_errors ) );
		}

		$quote_request = new WP_REST_Request( 'POST', '/' . self::NS . '/quote' );
		foreach ( $data['quote'] ?? $data as $key => $value ) {
			$quote_request->set_param( $key, $value );
		}
		$quote_response = self::quote( $quote_request );
		if ( is_wp_error( $quote_response ) ) {
			return $quote_response;
		}
		$quote_data = $quote_response->get_data();
		$quote = $quote_data['data'];
		$order = wc_create_order( array( 'customer_id' => $uid ) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$product_id = self::booking_product_id();
		$product = wc_get_product( $product_id );
		if ( $product ) {
			$item_id = $order->add_product( $product, 1, array( 'subtotal' => $quote['payable_now'], 'total' => $quote['payable_now'] ) );
			$item = $item_id ? $order->get_item( $item_id ) : null;
			if ( $item ) {
				$item->add_meta_data( 'tripbooking', self::tripbooking_meta_from_quote( $quote, $data ), true );
				$item->save();
			}
		}
		// Auto-fill from the customer's saved profile for anything the
		// checkout request didn't explicitly include — matching the real
		// website's own checkout, which pre-fills billing + travel/emergency
		// fields from the same saved account data instead of asking a
		// returning customer to retype them on every booking.
		self::save_billing_to_profile( $billing, $uid );
		self::apply_order_billing( $order, $billing );
		$order->set_total( (float) $quote['payable_now'] );
		self::apply_pga_balance_discount( $order );
		if ( ! empty( $data['payment_method'] ) ) {
			$order->set_payment_method( sanitize_key( (string) $data['payment_method'] ) );
		}
		$order->update_meta_data( '_pangaea_mobile_quote', $quote );
		$order->update_meta_data( '_pangaea_mobile_checkout_payload', self::sanitize_deep( $data ) );
		// Multiple travellers: the client sends a `travellers` array (one
		// entry per selected traveller) alongside the legacy single-object
		// `traveller_fields` — previously only `traveller_fields` was ever
		// read, so a 2+ traveller booking silently captured just the first
		// person. Store the full array, and keep mirroring the first
		// traveller into the existing individual _booking_* keys so anything
		// already reading those (e.g. the Pangaea Bookings admin view) keeps
		// working unchanged. $travellers_in/$lead_traveller were already
		// computed and validated above, before the order was created.
		self::save_traveller_to_profile( $lead_traveller, $uid );
		if ( $travellers_in ) {
			// Stored as a raw array, not JSON — matches the existing
			// save_order_travellers() endpoint below, which already writes
			// this same meta key this way; keeping both consistent so
			// whichever one runs last doesn't silently blank the other's data.
			$order->update_meta_data( '_pangaea_mobile_travellers', $travellers_in );
			foreach ( self::traveller_field_schema() as $field ) {
				$key = $field['key'];
				if ( isset( $travellers_in[0][ $key ] ) ) {
					$order->update_meta_data( $field['meta_key'], $travellers_in[0][ $key ] );
				}
			}
		} else {
			foreach ( self::traveller_field_schema() as $field ) {
				$key = $field['key'];
				if ( isset( $lead_traveller[ $key ] ) ) {
					$order->update_meta_data( $field['meta_key'], sanitize_textarea_field( (string) $lead_traveller[ $key ] ) );
				}
			}
		}
		self::apply_partial_payment_meta( $order, $quote, $data );
		$order->save();
		do_action( 'pangaea_mobile_order_created', $order->get_id(), $data, $quote );
		return self::ok( self::order_payload( $order ), 201 );
	}

	private static function booking_product_id() {
		$product_id = (int) get_option( self::ORDER_PRODUCT_OPTION, 0 );
		if ( $product_id > 0 && get_post_type( $product_id ) === 'product' ) {
			return $product_id;
		}
		$product_id = wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'PANGAEA Mobile Booking',
			)
		);
		if ( $product_id > 0 ) {
			update_post_meta( $product_id, '_virtual', 'yes' );
			update_post_meta( $product_id, '_downloadable', 'no' );
			update_post_meta( $product_id, '_regular_price', '0' );
			update_post_meta( $product_id, '_price', '0' );
			update_post_meta( $product_id, '_sold_individually', 'yes' );
			update_option( self::ORDER_PRODUCT_OPTION, $product_id, false );
		}
		return (int) $product_id;
	}

	/**
	 * Experience booking — mirrors the Journey/Trip flow above (quote ->
	 * create order -> existing gateway-agnostic /payments/*\/session ->
	 * /payments/*\/verify), so the mobile app can use the same pattern for
	 * both. Deliberately does not touch class-pangaea-experiences-api.php or
	 * pangaea-bookings.php — the website's own Experience checkout (a
	 * separate, cart+redirect-based flow under the pangaea-experiences/v1
	 * namespace) is completely unaffected by this. Instead this reuses their
	 * existing, already-tested logic by calling their public quote() method
	 * directly and firing the same `woocommerce_checkout_order_processed`
	 * action a real checkout submission fires — which is what already
	 * handles seat reservation and the Pangaea Bookings CPT sync for every
	 * other Experience order, so neither needs to be reimplemented here.
	 */
	public static function experience_quote( $request ) {
		if ( ! self::experiences_available() ) {
			return self::fail( 'experiences_unavailable', 'Experiences module is not active.', 503 );
		}
		$data                   = self::request_data( $request );
		$data['experience_id']  = (int) $request['experience_id'];
		$quote_request          = new WP_REST_Request( 'POST', '/' . Pangaea_Experiences_API::NS . '/quote' );
		foreach ( $data as $key => $value ) {
			$quote_request->set_param( $key, $value );
		}
		return Pangaea_Experiences_API::quote( $quote_request );
	}

	public static function checkout_create_experience_order( $request ) {
		if ( ! self::experiences_available() ) {
			return self::fail( 'experiences_unavailable', 'Experiences module is not active.', 503 );
		}
		if ( ! function_exists( 'wc_create_order' ) ) {
			return self::fail( 'woocommerce_missing', 'WooCommerce is not available.', 503 );
		}

		$data          = self::request_data( $request );
		$uid           = self::user_id_from_request( $request );
		$billing_errors = self::validate_billing_fields( (array) ( $data['billing'] ?? array() ) );
		if ( ! empty( $billing_errors ) ) {
			return self::fail( 'invalid_billing', 'Please check the billing details.', 422, array( 'errors' => $billing_errors ) );
		}

		// Same traveller/health fields as a Journey booking (age, sex, blood
		// type, nationality, emergency contact, referral, medical notes) —
		// site owner's explicit decision: Experience bookings must match
		// Journeys here, not just in visual shape. Validated (and the
		// customer's saved profile is used to fill in anything the request
		// omitted) before the order is created, same as the trip flow, so a
		// failed traveller-fields check never leaves a ghost order behind.
		$travellers_in = array();
		if ( ! empty( $data['travellers'] ) && is_array( $data['travellers'] ) ) {
			foreach ( $data['travellers'] as $index => $traveller ) {
				if ( ! is_array( $traveller ) ) {
					continue;
				}
				if ( 0 === $index ) {
					$traveller = self::traveller_with_saved_defaults( $traveller, $uid );
				}
				$row = array();
				foreach ( self::traveller_field_schema() as $field ) {
					$key = $field['key'];
					if ( isset( $traveller[ $key ] ) ) {
						$row[ $key ] = sanitize_textarea_field( (string) $traveller[ $key ] );
					}
				}
				if ( $row ) {
					$travellers_in[] = $row;
				}
			}
		}
		$lead_traveller = $travellers_in ? $travellers_in[0] : self::traveller_with_saved_defaults(
			is_array( $data['traveller_fields'] ?? null ) ? $data['traveller_fields'] : array(),
			$uid
		);
		$traveller_errors = array();
		foreach ( self::traveller_field_schema() as $field ) {
			if ( ! empty( $field['required'] ) && '' === trim( (string) ( $lead_traveller[ $field['key'] ] ?? '' ) ) ) {
				$traveller_errors[ $field['key'] ] = 'Required.';
			}
		}
		if ( ! empty( $traveller_errors ) ) {
			return self::fail( 'invalid_traveller', 'Please check the traveller details.', 422, array( 'errors' => $traveller_errors ) );
		}

		$quote_request = new WP_REST_Request( 'POST', '/' . Pangaea_Experiences_API::NS . '/quote' );
		foreach ( (array) ( $data['quote'] ?? $data ) as $key => $value ) {
			$quote_request->set_param( $key, $value );
		}
		$quote_response = Pangaea_Experiences_API::quote( $quote_request );
		if ( is_wp_error( $quote_response ) ) {
			return $quote_response;
		}
		$quote         = $quote_response->get_data()['data'];
		$experience_id = (int) $quote['experience_id'];

		$order = wc_create_order( array( 'customer_id' => $uid ) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		// Same shared placeholder product every Experience booking uses
		// (website and mobile alike) — read directly via the plugin's own
		// public option constant rather than duplicating its private
		// booking_product_id() creation logic; the product already exists.
		$product_id = (int) get_option( Pangaea_Experiences_API::ORDER_PRODUCT_OPTION, 0 );
		$product    = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			return self::fail( 'product_missing', 'The Experience booking product is not available.', 500 );
		}

		$experience_title = get_the_title( $experience_id );
		$item_id          = $order->add_product(
			$product,
			1,
			array(
				'subtotal' => $quote['total'],
				'total'    => $quote['total'],
			)
		);
		$item = $item_id ? $order->get_item( $item_id ) : null;
		if ( $item ) {
			$item->add_meta_data(
				'experiencebooking',
				array(
					'experience_id'    => $experience_id,
					'experience_title' => $experience_title,
					'date'             => $quote['date'],
					'time'             => $quote['time'],
					'slot_key'         => $quote['slot_key'],
					'travellers'       => $quote['lines'],
					'extra_services'   => $quote['extra_services'],
					'total_travellers' => $quote['total_travellers'],
					'totals'           => array( 'total' => $quote['total'] ),
					'currency'         => $quote['currency'],
				),
				true
			);
			// Same fix as yesterday's naming bug (class-pangaea-experiences-api.php
			// persist_line_item_meta()) — set it here too, at the moment this
			// item is created, rather than depending on that hook firing (it
			// doesn't, for a directly-added line item like this one).
			if ( '' !== $experience_title ) {
				$item->set_name( $experience_title );
			}
			$item->save();
		}

		$billing = isset( $data['billing'] ) && is_array( $data['billing'] ) ? $data['billing'] : array();
		$billing = self::billing_with_saved_defaults( $billing, $uid );
		self::save_billing_to_profile( $billing, $uid );
		self::apply_order_billing( $order, $billing );
		$order->set_total( (float) $quote['total'] );
		self::apply_pga_balance_discount( $order );
		if ( ! empty( $data['payment_method'] ) ) {
			$order->set_payment_method( sanitize_key( (string) $data['payment_method'] ) );
		}
		$order->update_meta_data( '_pangaea_mobile_quote', $quote );
		$order->update_meta_data( '_pangaea_mobile_checkout_payload', self::sanitize_deep( $data ) );

		// Same traveller/health field storage + profile write-back as the
		// trip flow (see checkout_order()) — kept identical so the Pangaea
		// Bookings admin view, the thank-you page, and the profile
		// auto-fill all work the same regardless of Journey vs Experience.
		self::save_traveller_to_profile( $lead_traveller, $uid );
		if ( $travellers_in ) {
			$order->update_meta_data( '_pangaea_mobile_travellers', $travellers_in );
			foreach ( self::traveller_field_schema() as $field ) {
				$key = $field['key'];
				if ( isset( $travellers_in[0][ $key ] ) ) {
					$order->update_meta_data( $field['meta_key'], $travellers_in[0][ $key ] );
				}
			}
		} else {
			foreach ( self::traveller_field_schema() as $field ) {
				$key = $field['key'];
				if ( isset( $lead_traveller[ $key ] ) ) {
					$order->update_meta_data( $field['meta_key'], sanitize_textarea_field( (string) $lead_traveller[ $key ] ) );
				}
			}
		}

		$order->save();

		do_action( 'woocommerce_checkout_order_processed', $order->get_id(), array(), $order );

		// Re-fetch: on_order_processed() above may have changed the order's
		// status (e.g. cancelled it if seats sold out in the moment between
		// this endpoint's own quote check and now) via its own fresh
		// wc_get_order() call, which this local $order object won't reflect.
		$order = wc_get_order( $order->get_id() );

		return self::ok( self::order_payload( $order ), 201 );
	}

	private static function tripbooking_meta_from_quote( $quote, $data ) {
		return array(
			'trip_id'        => (int) $quote['trip_id'],
			'trip'           => (int) $quote['trip_id'],
			'trip_name'      => get_the_title( (int) $quote['trip_id'] ),
			'package_id'     => (int) $quote['package_id'],
			'trip_date'      => sanitize_text_field( (string) ( $data['departure_date'] ?? '' ) ),
			'travellers'     => $quote['lines'],
			// B13: kept as its own key, never merged into `travellers` — the
			// booking-detail traveller_count fix (B24) sums quantities out of
			// `travellers` specifically, and mixing addon quantities into that
			// array would silently re-break it.
			'addons'         => $quote['addons'] ?? array(),
			'totals'         => array(
				'total'         => (float) $quote['total'],
				'cart_total'    => (float) $quote['total'],
				'payable_now'   => (float) $quote['payable_now'],
				'partial_total' => (float) $quote['payable_now'],
				'due_total'     => (float) $quote['remaining_due'],
			),
			'currency'       => $quote['currency'],
		);
	}

	private static function apply_partial_payment_meta( $order, $quote, $data ) {
		$remaining = (float) ( $quote['remaining_due'] ?? 0 );
		$mode = $remaining > 0 ? 'partial' : 'full';
		$order->update_meta_data( '_wte_pp_mode', $mode );
		$order->update_meta_data( '_wte_pp_full_total', (float) ( $quote['total'] ?? 0 ) );
		$order->update_meta_data( '_wte_pp_deposit', (float) ( $quote['payable_now'] ?? 0 ) );
		$order->update_meta_data( '_wte_pp_remaining', $remaining );
		$order->update_meta_data( '_wte_pp_trip_id', (int) ( $quote['trip_id'] ?? 0 ) );
		$order->update_meta_data( '_wte_pp_trip_date', sanitize_text_field( (string) ( $data['departure_date'] ?? $data['start_date'] ?? '' ) ) );
		$order->update_meta_data( '_pangaea_mobile_payment_mode', (string) ( $quote['payment_mode'] ?? ( $remaining > 0 ? 'deposit' : 'full' ) ) );
	}

	private static function apply_order_billing( $order, $billing ) {
		$map = array(
			'billing_first_name' => 'set_billing_first_name',
			'billing_last_name'  => 'set_billing_last_name',
			'billing_email'      => 'set_billing_email',
			'billing_phone'      => 'set_billing_phone',
			'billing_country'    => 'set_billing_country',
			'billing_city'       => 'set_billing_city',
			'billing_address_1'  => 'set_billing_address_1',
			'billing_postcode'   => 'set_billing_postcode',
		);
		foreach ( $map as $key => $method ) {
			if ( ! isset( $billing[ $key ] ) || ! method_exists( $order, $method ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $billing[ $key ] );
			if ( 'billing_email' === $key ) {
				// Callers now validate email format via validate_billing_fields()
				// before an order is even created (see checkout_create_order()/
				// checkout_create_experience_order()) — this is a second,
				// belt-and-braces check so this method is never itself the thing
				// that lets a malformed value reach a WC setter that throws.
				$value = sanitize_email( $value );
				if ( '' === $value || ! is_email( $value ) ) {
					continue;
				}
			}
			try {
				$order->$method( $value );
			} catch ( WC_Data_Exception $e ) {
				// A single bad billing field (e.g. an invalid ISO country code)
				// must never crash order creation and leave a ghost order behind
				// — skip just that field rather than let the exception propagate.
				continue;
			}
		}
		self::ensure_order_billing_last_name( $order );
	}

	/**
	 * Mobile orders are built directly via wc_create_order()+add_product(),
	 * never touching WC()->cart — so the PANGAEA Balance mu-plugin's own
	 * 'woocommerce_cart_calculate_fees'/'woocommerce_checkout_order_created'
	 * hooks never fired for them, meaning a customer with real PANGAEA
	 * Balance was silently charged full price via the app, with no error and
	 * no record of it (confirmed: pangaea-balance.php's fee hook reads
	 * WC()->cart->get_subtotal(), which is empty/irrelevant here). Firing
	 * 'woocommerce_checkout_order_created' manually lets that plugin's own,
	 * already-correct attach_to_order() compute and record how much of the
	 * customer's balance to apply — it only needs the $order object
	 * (reads $order->get_subtotal()), not the cart — then this turns that
	 * into a real fee line + reduced total, so the customer is actually
	 * charged the discounted amount instead of just having it bookkept as
	 * if they were. No-op (and harmless) if the balance plugin isn't active
	 * or the customer has no balance.
	 */
	private static function apply_pga_balance_discount( $order ) {
		if ( ! function_exists( 'pga_balance_get_balance' ) || ! class_exists( 'WC_Order_Item_Fee' ) ) {
			return;
		}
		do_action( 'woocommerce_checkout_order_created', $order );
		$applied = (float) $order->get_meta( '_pga_balance_applied' );
		if ( $applied <= 0 ) {
			return;
		}
		$label = is_rtl()
			? sprintf( 'تم استخدام %s ر.س من رصيدك في بانجيا', number_format( $applied, 2 ) )
			: sprintf( '%s SAR applied from your PANGAEA Balance', number_format( $applied, 2 ) );
		$fee = new WC_Order_Item_Fee();
		$fee->set_name( $label );
		$fee->set_amount( -$applied );
		$fee->set_total( -$applied );
		$order->add_item( $fee );
		$order->set_total( max( 0, (float) $order->get_total() - $applied ) );
	}

	/**
	 * Tap's hosted payment page hard-rejects an empty customer.lastName
	 * ("is not allowed to be empty") with no card form shown at all — hit in
	 * practice by a one-word account name (WC_Customer billing carries no
	 * last name, and nothing upstream forces one). Rather than block
	 * checkout, derive a sane last name so existing one-word-name accounts
	 * can still pay: split a multi-word first name, else fall back to the
	 * WP user's own last_name meta, else reuse the first name itself as a
	 * last resort — never leave it empty going into the gateway.
	 */
	private static function ensure_order_billing_last_name( $order ) {
		if ( ! method_exists( $order, 'get_billing_last_name' ) || '' !== trim( (string) $order->get_billing_last_name() ) ) {
			return;
		}
		$first = trim( (string) $order->get_billing_first_name() );
		if ( '' !== $first && false !== strpos( $first, ' ' ) ) {
			$parts = preg_split( '/\s+/', $first, 2 );
			$order->set_billing_first_name( $parts[0] );
			$order->set_billing_last_name( $parts[1] );
			return;
		}
		$uid = (int) $order->get_customer_id();
		$meta_last = $uid > 0 ? trim( (string) get_user_meta( $uid, 'last_name', true ) ) : '';
		if ( '' !== $meta_last ) {
			$order->set_billing_last_name( $meta_last );
			return;
		}
		if ( '' !== $first ) {
			$order->set_billing_last_name( $first );
		}
	}

	/**
	 * The regular order-pay URL, flagged so the mobile-only minimal payment
	 * template (no site header/footer) renders instead of the full page —
	 * see mu-plugins/pangaea-mobile-payment-embed.php. Website checkout never
	 * sends this flag, so this only affects the app's own WebView.
	 */
	private static function mobile_payment_url( $order ) {
		// get_checkout_payment_url( true ) is WooCommerce's "on checkout"
		// variant — per its own docblock, it deliberately omits
		// `pay_for_order=true` and "doesn't offer gateway choices". Without
		// that query arg, WC_Shortcode_Checkout::order_pay() never enters
		// its real pay-for-order branch, so gateways that rely on the
		// normal payment-method form + submit button (PayTabs, Tamara)
		// never actually render anything — only Tap appeared to work,
		// because its own receipt-page hook fires regardless. Calling it
		// with no argument (the default, `false`) is the correct "give me
		// a real link to pay this order" URL.
		$url = add_query_arg( 'mobile_embed', '1', $order->get_checkout_payment_url() );

		// A registered customer's order-pay page requires being logged in
		// as that exact customer — the app's WebView has no WordPress
		// session cookie (only its own bearer token for API calls), so it
		// would always hit a login wall here. Wrap the URL in a one-time
		// auto-login link for that case; see mu-plugins/
		// pangaea-mobile-auto-login.php for the redemption side. A guest
		// order (customer_id 0) needs no login at all — the order key
		// alone already lets WooCommerce's own guest pay-for-order flow
		// through, so it's left untouched.
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id > 0 ) {
			$url = self::mobile_auto_login_url( $customer_id, $order->get_id() );
		}

		return $url;
	}

	/**
	 * Issues a single-use, short-lived token that logs the WebView in as
	 * $customer_id, then redirects straight to that order's real (already
	 * `mobile_embed`-flagged) pay URL — never to a client-supplied
	 * redirect, closing off any open-redirect risk. Redeemed and deleted
	 * on first use in mu-plugins/pangaea-mobile-auto-login.php; expires on
	 * its own after 5 minutes if never used.
	 */
	private static function mobile_auto_login_url( $customer_id, $order_id ) {
		$token = wp_generate_password( 48, false, false );
		set_transient(
			'pga_mobile_login_' . $token,
			array( 'user_id' => $customer_id, 'order_id' => $order_id ),
			5 * MINUTE_IN_SECONDS
		);
		return add_query_arg( 'pga_mobile_login', $token, home_url( '/' ) );
	}

	/**
	 * $payment_url: pass an already-computed mobile_payment_url() result
	 * when the caller (payment_session()) needs this SAME order's payload
	 * embedded alongside its own separately-returned redirect_url — a
	 * registered customer's URL is a one-time auto-login token, so calling
	 * mobile_payment_url() twice for one order handed out two different,
	 * mutually-exclusive single-use links in the same response (whichever
	 * one the app opened first invalidated the other). Every other caller
	 * leaves this null and gets a fresh one computed here, as before.
	 */
	private static function order_payload( $order, $payment_url = null ) {
		if ( is_numeric( $order ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $order );
		}
		if ( ! $order ) {
			return null;
		}
		$travellers = $order->get_meta( '_pangaea_mobile_travellers' );
		// The `pangaea_booking` CPT record (see booking_payload()/unified_booking_cpt_id_for_order())
		// is created by a `woocommerce_new_order` hook that already fires
		// before this ever runs, both for a fresh order and for
		// checkout_create_order()/checkout_create_experience_order()'s own
		// explicit `woocommerce_checkout_order_processed` call — so a real
		// booking_id is available here immediately, before payment even
		// completes. This lets the app go straight to "View My Journey" /
		// GET /me/trips/{booking_id}/ticket right after checkout, instead of
		// listing every trip and matching this order_id itself.
		$booking_id = self::unified_booking_cpt_id_for_order( $order->get_id() );
		$payload    = array(
			'id'             => (int) $order->get_id(),
			'number'         => $order->get_order_number(),
			'status'         => $order->get_status(),
			'total'          => (float) $order->get_total(),
			'currency'       => $order->get_currency(),
			'payment_method' => $order->get_payment_method(),
			'payment_url'    => $payment_url ?? self::mobile_payment_url( $order ),
			'created_at'     => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : '',
			'travellers'     => is_array( $travellers ) ? $travellers : array(),
			'booking_id'     => $booking_id ?: null,
		);

		// Everything trip/experience-specific (name, dates, traveller count,
		// paid/due) comes from the same unified pangaea_booking CPT that
		// booking_payload() reads, so this response and /me/trips/{id} never
		// disagree. Without this, the app had nothing here but the shared
		// checkout placeholder product's own name ("PANGAEA Mobile Booking",
		// identical for every booking) to show as "the trip", and no real
		// paid/due at all — `total` is the order's face amount, not whether
		// it's actually been paid yet.
		if ( $booking_id ) {
			$booking = self::booking_payload( $booking_id );
			$payload = array_merge(
				$payload,
				array(
					'trip_name'       => $booking['trip_name'],
					'package_name'    => $booking['package_name'],
					'booking_type'    => $booking['booking_type'],
					'duration'        => $booking['duration'],
					'departure_date'  => $booking['departure_date'],
					'end_date'        => $booking['end_date'],
					'traveller_count' => $booking['traveller_count'],
					'paid_amount'     => $booking['paid_amount'],
					'due_amount'      => $booking['due_amount'],
					'payment_status'  => $booking['payment_status'],
				)
			);
		}

		return $payload;
	}

	public static function checkout_order( $request ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return self::fail( 'woocommerce_missing', 'WooCommerce is not available.', 503 );
		}
		$order = wc_get_order( (int) $request['order_id'] );
		if ( ! $order || ! self::order_belongs_to_user( $order, self::user_id_from_request( $request ) ) ) {
			return self::fail( 'not_found', 'Order not found.', 404 );
		}
		return self::ok( self::order_payload( $order ) );
	}

	private static function order_belongs_to_user( $order, $uid ) {
		return (int) $order->get_customer_id() === (int) $uid || current_user_can( 'manage_woocommerce' );
	}

	public static function upload_order_passport( $request ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return self::fail( 'woocommerce_missing', 'WooCommerce is not available.', 503 );
		}
		$order = wc_get_order( (int) $request['order_id'] );
		if ( ! $order || ! self::order_belongs_to_user( $order, self::user_id_from_request( $request ) ) ) {
			return self::fail( 'not_found', 'Order not found.', 404 );
		}
		$upload = self::handle_upload( $request, 'passport' );
		if ( is_wp_error( $upload ) ) {
			return $upload;
		}
		$order->update_meta_data( '_booking_passport_doc_url', $upload['url'] );
		$order->update_meta_data( '_booking_passport_url', $upload['url'] );
		$order->update_meta_data( '_booking_passport_attachment_id', $upload['attachment_id'] );
		$order->update_meta_data( '_booking_passport_filename', $upload['filename'] );
		$order->save();
		return self::ok( $upload, 201 );
	}

	public static function save_order_travellers( $request ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return self::fail( 'woocommerce_missing', 'WooCommerce is not available.', 503 );
		}
		$order = wc_get_order( (int) $request['order_id'] );
		if ( ! $order || ! self::order_belongs_to_user( $order, self::user_id_from_request( $request ) ) ) {
			return self::fail( 'not_found', 'Order not found.', 404 );
		}
		$data = self::request_data( $request );
		$travellers = isset( $data['travellers'] ) && is_array( $data['travellers'] ) ? self::sanitize_deep( $data['travellers'] ) : array();
		$order->update_meta_data( '_pangaea_mobile_travellers', $travellers );
		$order->save();
		return self::ok( array( 'travellers' => $travellers ) );
	}

	private static function handle_upload( $request, $kind ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return self::fail( 'missing_file', 'Upload file is required.' );
		}
		$file = $files['file'];
		$allowed = array( 'image/jpeg', 'image/png', 'image/webp', 'application/pdf' );
		if ( ! empty( $file['type'] ) && ! in_array( $file['type'], $allowed, true ) ) {
			return self::fail( 'invalid_file_type', 'Only JPG, PNG, WEBP, or PDF files are allowed.' );
		}
		if ( ! empty( $file['size'] ) && (int) $file['size'] > 5 * 1024 * 1024 ) {
			return self::fail( 'file_too_large', 'File must be 5MB or smaller.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$upload = wp_handle_upload( $file, array( 'test_form' => false ) );
		if ( isset( $upload['error'] ) ) {
			return self::fail( 'upload_failed', $upload['error'] );
		}
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $upload['type'],
				'post_title'     => sanitize_file_name( basename( $upload['file'] ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
			$meta = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
			wp_update_attachment_metadata( $attachment_id, $meta );
		}
		return array(
			'kind'          => $kind,
			'attachment_id' => (int) $attachment_id,
			'url'           => esc_url_raw( $upload['url'] ),
			'filename'      => basename( $upload['file'] ),
			'mime_type'     => $upload['type'],
		);
	}

	/**
	 * Saved cards ("Visa ending 4242"). The PayTabs plugin already has full,
	 * PCI-compliant tokenization built in (WC_Payment_Token_PayTabs — raw card
	 * numbers are tokenized client-side by PayTabs' own hosted form and never
	 * reach this server; only the resulting transaction reference, last4, and
	 * expiry get stored). This just exposes that existing, standard
	 * WooCommerce token storage to the app — it does not implement card
	 * capture or charging itself, both of which stay entirely inside the
	 * already-existing PayTabs gateway/hosted-form flow.
	 */
	public static function list_saved_cards( $request ) {
		$uid    = self::user_id_from_request( $request );
		$tokens = class_exists( 'WC_Payment_Tokens' ) ? WC_Payment_Tokens::get_customer_tokens( $uid, '' ) : array();
		$items  = array();
		foreach ( $tokens as $token ) {
			if ( 'PayTabs' !== $token->get_type() ) {
				continue;
			}
			$items[] = array(
				'id'           => (int) $token->get_id(),
				'brand'        => method_exists( $token, 'get_card_type' ) ? (string) $token->get_card_type() : '',
				'last4'        => (string) $token->get_last4(),
				'expiry_month' => (string) $token->get_expiry_month(),
				'expiry_year'  => (string) $token->get_expiry_year(),
				'is_default'   => (bool) $token->is_default(),
			);
		}
		return self::ok( array( 'items' => $items ) );
	}

	public static function delete_saved_card( $request ) {
		$uid      = self::user_id_from_request( $request );
		$token_id = (int) $request['token_id'];
		$token    = class_exists( 'WC_Payment_Tokens' ) ? WC_Payment_Tokens::get( $token_id ) : null;
		if ( ! $token || (int) $token->get_user_id() !== (int) $uid ) {
			return self::fail( 'not_found', 'Saved card not found.', 404 );
		}
		$token->delete();
		return self::ok( array( 'deleted' => true ) );
	}

	/**
	 * Tap is this site's actual dominant live gateway (verified against real
	 * order data — nearly every recent order uses it), unlike what the
	 * existing /payments/paytabs/* naming below might suggest. Added as its
	 * own clearly-named route rather than leaving the mobile developer to
	 * discover that passing `gateway: "tap"` to the paytabs-named endpoint
	 * happened to already work.
	 */
	public static function payment_tap_session( $request ) {
		return self::payment_session( $request, 'tap' );
	}

	public static function payment_tap_verify( $request ) {
		return self::payment_verify( $request, 'tap' );
	}

	public static function payment_paytabs_session( $request ) {
		return self::payment_session( $request, 'paytabs_creditcard' );
	}

	public static function payment_tamara_session( $request ) {
		// 'tamara-gateway' is Tamara's actual registered WooCommerce gateway
		// ID (TamaraCheckout::TAMARA_GATEWAY_ID in
		// wp-content/plugins/tamara-checkout/src/TamaraCheckout.php) — not
		// the plain 'tamara' this previously defaulted to, which matched no
		// real WC_Payment_Gateway and always fell through to the generic
		// get_checkout_payment_url() page below.
		return self::payment_session( $request, 'tamara-gateway' );
	}

	/**
	 * Deliberately does NOT call the gateway's own process_payment() and
	 * return a native gateway session — the app instead loads
	 * get_checkout_payment_url() (WooCommerce's own order-pay page) in an
	 * in-app iframe/WebView, exactly like a normal web checkout would. That
	 * page's own gateway form + JS calls process_payment() itself on
	 * submit, so this is the SAME code path a real web checkout runs, which
	 * means everything already wired to that path (Pangaea Bookings sync,
	 * WTE Booking creation) fires unchanged — no separate native-gateway
	 * integration to build or keep in sync with the website's own checkout.
	 */
	private static function payment_session( $request, $default_gateway ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return self::fail( 'woocommerce_missing', 'WooCommerce is not available.', 503 );
		}
		$data = self::request_data( $request );
		$order_id = (int) ( $data['order_id'] ?? 0 );
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::order_belongs_to_user( $order, self::user_id_from_request( $request ) ) ) {
			return self::fail( 'not_found', 'Order not found.', 404 );
		}

		$requested = sanitize_key( (string) ( $data['gateway'] ?? $default_gateway ) );
		if ( ! $requested ) {
			return self::fail( 'invalid_gateway', 'No payment gateway specified.', 400 );
		}

		// Tamara's real registered WooCommerce gateway ID is 'tamara-gateway'
		// (TamaraCheckout::TAMARA_GATEWAY_ID) — a caller asking for e.g.
		// 'tamara-gateway-pay-in-3' (a specific Tamara product) still needs
		// the order's payment_method set to plain 'tamara-gateway' for the
		// order-pay page to recognise it.
		$gateway = ( 0 === strpos( $requested, 'tamara-gateway' ) ) ? 'tamara-gateway' : $requested;

		$order->set_payment_method( $gateway );
		// Flags this order as an app checkout so the gateway (e.g. Tap, whose
		// hosted checkout redirects back via a URL WE hand it up front, not
		// one the app controls) knows to send the customer back to the
		// chrome-free mobile_embed page too, not just the initial one — see
		// WC_Tap_Gateway::pga_mobile_return_url() in tap.php.
		$order->update_meta_data( '_pangaea_mobile_checkout', '1' );
		$order->save();

		// Computed once and reused for both fields below — see
		// order_payload()'s $payment_url param for why: two separate
		// calls here previously handed out two different one-time
		// auto-login tokens for the same order in the same response.
		$payment_url = self::mobile_payment_url( $order );

		return self::ok(
			array(
				'provider'     => $requested,
				'order'        => self::order_payload( $order, $payment_url ),
				// The app embeds this URL in an iframe/WebView; the
				// gateway's own payment form renders inside it exactly as
				// it does on the website's own checkout-pay page.
				'redirect_url' => $payment_url,
			)
		);
	}

	public static function payment_paytabs_verify( $request ) {
		return self::payment_verify( $request, 'paytabs' );
	}

	public static function payment_tamara_verify( $request ) {
		return self::payment_verify( $request, 'tamara' );
	}

	/**
	 * This intentionally just re-reads the order's own WooCommerce status
	 * rather than calling out to the gateway a second time, because all
	 * three real gateways installed on this site already update that status
	 * themselves, asynchronously, off their own server-to-server callback:
	 *  - Tap: WC_Tap_Gateway::webhook(), hooked on
	 *    `woocommerce_api_tap_webhook`, calls
	 *    $order->payment_complete()/update_status() directly.
	 *  - PayTabs: WC_Gateway_Paytabs::callback_response()/ipn_response(),
	 *    hooked on `woocommerce_api_wc_gateway_{$this->id}` and
	 *    `woocommerce_api_wc_gateway_paytabs`, call
	 *    $order->payment_complete()/update_status().
	 *  - Tamara: TamaraNotificationService::handleIpnRequest(), dispatched
	 *    from TamaraCheckout::handleTamaraApi(), calls
	 *    TamaraCheckout::updateOrderStatusAndAddOrderNote() ->
	 *    $order->update_status().
	 * So by the time the mobile app polls this endpoint, $order->get_status()
	 * already reflects whatever the gateway itself reported — this is a read
	 * of that real state, not an independent second check against the
	 * gateway. If a gateway's own callback hasn't reached this site yet (or
	 * is misconfigured), this will keep returning the order's last-known
	 * status and has no way to detect that on its own.
	 */
	private static function payment_verify( $request, $provider ) {
		$data = self::request_data( $request );
		$order_id = (int) ( $data['order_id'] ?? $request->get_param( 'order_id' ) );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order ) {
			return self::ok( array( 'provider' => $provider, 'order_id' => $order_id, 'status' => 'unknown', 'paid' => false ) );
		}

		if ( ! self::order_allowed( $order, $request, $data ) ) {
			return self::fail( 'not_found', 'Order not found.', 404 );
		}

		return self::ok(
			array(
				'provider' => $provider,
				'order_id' => $order_id,
				'status'   => $order->get_status(),
				'paid'     => $order->is_paid(),
			)
		);
	}

	/**
	 * Same ownership contract as guest_booking_lookup()/booking_allowed(): an
	 * authenticated user whose account email matches the order, OR a caller who
	 * proves ownership by supplying the order's own billing email/phone. Prevents
	 * an unauthenticated caller from learning any order's payment status by
	 * guessing/incrementing order_id.
	 */
	private static function order_allowed( $order, $request, array $data = array() ) {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		$uid = self::user_id_from_request( $request );
		if ( $uid ) {
			$user = get_userdata( $uid );
			if ( $user && strtolower( $user->user_email ) === strtolower( (string) $order->get_billing_email() ) ) {
				return true;
			}
		}

		$email = strtolower( sanitize_email( (string) ( $data['email'] ?? $request->get_param( 'email' ) ?? '' ) ) );
		$phone = preg_replace( '/\D+/', '', (string) ( $data['phone'] ?? $request->get_param( 'phone' ) ?? '' ) );
		$order_email = strtolower( (string) $order->get_billing_email() );
		$order_phone = preg_replace( '/\D+/', '', (string) $order->get_billing_phone() );

		if ( $email && $email === $order_email ) return true;
		if ( $phone && $order_phone && $phone === $order_phone ) return true;

		return false;
	}

	public static function webhook_paytabs( $request ) {
		if ( ! self::webhook_secret_valid( $request ) ) {
			return self::fail( 'unauthorized', 'Invalid webhook request.', 401 );
		}
		do_action( 'pangaea_mobile_paytabs_webhook', self::request_data( $request ), $request );
		return self::ok( array( 'accepted' => true ) );
	}

	/**
	 * This route (and webhook_tamara() below) had no signature verification at
	 * all — publicly reachable, and anyone could POST an arbitrary payload.
	 * In practice nothing in the codebase hooks `pangaea_mobile_paytabs_webhook`
	 * or `pangaea_mobile_tamara_webhook` (verified: no add_action anywhere), so
	 * this was inert rather than an active fraud path — real payment
	 * confirmation happens through PayTabs'/Tamara's own official WooCommerce
	 * gateway callback URLs, not through this mobile API.
	 *
	 * This is a fail-closed placeholder, not real PayTabs/Tamara signature
	 * verification — each provider has its own HMAC scheme keyed to this
	 * site's actual merchant secret, which isn't something to guess at here.
	 * PANGAEA_MOBILE_WEBHOOK_SECRET is not defined anywhere, so both routes
	 * reject every request by default. Before ever pointing a real PayTabs or
	 * Tamara IPN at these URLs, replace this with that provider's documented
	 * signature check.
	 */
	private static function webhook_secret_valid( $request ) {
		$secret = defined( 'PANGAEA_MOBILE_WEBHOOK_SECRET' ) ? (string) PANGAEA_MOBILE_WEBHOOK_SECRET : '';
		if ( '' === $secret ) {
			return false;
		}
		$provided = (string) $request->get_header( 'x-pangaea-webhook-secret' );
		return '' !== $provided && hash_equals( $secret, $provided );
	}

	public static function payment_tamara_options( $request ) {
		return self::ok(
			array(
				'amount'   => (float) $request->get_param( 'amount' ),
				'currency' => sanitize_text_field( (string) ( $request->get_param( 'currency' ) ?: 'SAR' ) ),
				'options'  => array(
					array( 'id' => 'tamara', 'title' => 'Tamara', 'available' => true ),
				),
			)
		);
	}

	public static function webhook_tamara( $request ) {
		if ( ! self::webhook_secret_valid( $request ) ) {
			return self::fail( 'unauthorized', 'Invalid webhook request.', 401 );
		}
		do_action( 'pangaea_mobile_tamara_webhook', self::request_data( $request ), $request );
		return self::ok( array( 'accepted' => true ) );
	}

	public static function get_booking( $request ) {
		$booking_id = self::resolve_live_booking_id( (int) $request['booking_id'] );
		if ( ! self::booking_allowed( $booking_id, self::user_id_from_request( $request ) ) ) {
			return self::fail( 'not_found', 'Booking not found.', 404 );
		}
		return self::ok( self::booking_payload( $booking_id ) );
	}

	/**
	 * `booking_id` throughout this whole "My Trips" section is a
	 * `pangaea_booking` CPT post id (mu-plugins/pangaea-bookings/pangaea-bookings.php)
	 * — NOT the WP Travel Engine `booking` post id. That CPT is kept in sync
	 * automatically (order created/paid/status-changed hooks) for BOTH a
	 * Journey (WTE) order and an Experience order alike, with one consistent
	 * set of `_pb_*` meta fields either way — an Experience order never
	 * creates a WTE `booking` post at all, so the old WTE-only
	 * pbt_build_payload()-based lookup here always returned nothing for
	 * Experience bookings (My Trips silently omitted every one of them).
	 * Switching to the unified CPT gives Journeys and Experiences the exact
	 * same My-Trips/ticket/QR flow, which is the whole point of it.
	 */
	private static function unified_booking_cpt_id_for_order( $order_id ) {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'pangaea_booking',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => '_pb_woo_order_id',
				'meta_value'     => $order_id,
			)
		);
		return ! empty( $ids[0] ) ? (int) $ids[0] : 0;
	}

	/**
	 * A journey order's `pangaea_booking` CPT record can legitimately get
	 * superseded moments after creation: it's first synced (fallback path)
	 * the instant the order exists, then re-synced from the fuller WTE
	 * `booking` post once WP Travel Engine creates one a moment later —
	 * `reconcile_duplicate_cpts_for_order()` correctly keeps the richer
	 * record and trashes the earlier one. But `order_payload()` hands the
	 * app a `booking_id` right at checkout, before that second sync/dedupe
	 * has necessarily happened — so the app can be left holding an id that
	 * gets trashed and superseded a few seconds later, forever pointing at
	 * a dead, incomplete snapshot (wrong/placeholder trip name, no dates,
	 * whatever totals existed at that first instant). Rather than require
	 * the app to somehow know to re-fetch a fresh id, every entry point
	 * that takes a `booking_id` transparently follows a trashed record to
	 * whichever live one now covers the same underlying order.
	 */
	private static function resolve_live_booking_id( $booking_id ) {
		$booking_id = (int) $booking_id;
		if ( $booking_id <= 0 || get_post_status( $booking_id ) !== 'trash' ) {
			return $booking_id;
		}
		$order_id = (int) get_post_meta( $booking_id, '_pb_woo_order_id', true );
		if ( ! $order_id ) {
			return $booking_id;
		}
		$live_id = self::unified_booking_cpt_id_for_order( $order_id );
		return $live_id ?: $booking_id;
	}

	private static function booking_allowed( $booking_id, $uid ) {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		$order_id = (int) get_post_meta( (int) $booking_id, '_pb_woo_order_id', true );
		$order    = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			return false;
		}
		if ( (int) $order->get_customer_id() === (int) $uid ) {
			return true;
		}
		$user  = get_userdata( $uid );
		$email = $user ? strtolower( $user->user_email ) : '';
		return $email && $email === strtolower( (string) $order->get_billing_email() );
	}

	/**
	 * Decodes the HTML entities WordPress's `wp_kses`/title sanitization
	 * leaves in a stored trip title (e.g. "AlUla Adventure &amp; Nature
	 * Experience") — the raw `_pb_trip_name` meta value is never meant to be
	 * echoed into HTML, so it's still entity-encoded; a JSON API response
	 * must hand the app the literal display text instead.
	 */
	private static function decode_stored_title( $value ) {
		return html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * The real trip/experience post id isn't one of the unified CPT's own
	 * `_pb_*` fields (it only stores the display name) — resolved directly
	 * from the order instead, the same way the QR ticket viewer and the
	 * thank-you page already tell a Journey from an Experience.
	 */
	private static function trip_id_for_booking_order( $order, array $snapshot_setting ) {
		if ( ! $order ) {
			return 0;
		}
		$experience_id = (int) $order->get_meta( '_pga_exp_id' );
		if ( $experience_id > 0 ) {
			return $experience_id;
		}
		$po = ( is_array( $snapshot_setting ) && ! empty( $snapshot_setting['place_order'] ) && is_array( $snapshot_setting['place_order'] ) )
			? $snapshot_setting['place_order']
			: array();
		return ! empty( $po['tid'] ) ? (int) $po['tid'] : 0;
	}

	private static function booking_payload( $booking_id ) {
		$booking_id = (int) $booking_id;
		$g          = function ( $key ) use ( $booking_id ) {
			return get_post_meta( $booking_id, '_pb_' . $key, true );
		};

		$order_id = (int) $g( 'woo_order_id' );
		$order    = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		$paid     = (float) $g( 'paid' );
		$due      = (float) $g( 'due' );
		$start_ts = (int) $g( 'start_ts' );
		$end_ts   = (int) $g( 'end_ts' );

		$snapshot_setting = json_decode( (string) $g( 'snapshot_booking_setting' ), true );
		$trip_id          = self::trip_id_for_booking_order( $order, is_array( $snapshot_setting ) ? $snapshot_setting : array() );

		// The traveller/health fields (age, sex, blood type, nationality,
		// emergency contact, medical notes, passport, plus the raw billing
		// address) — exactly what the wp-admin "Pangaea Bookings" edit
		// screen shows under Booking Fields — are captured on the CPT as one
		// JSON blob rather than individual `_pb_*` keys.
		$fields = json_decode( (string) $g( 'booking_fields' ), true );
		$fields = is_array( $fields ) ? $fields : array();
		$f      = function ( $key ) use ( $fields ) {
			return isset( $fields[ $key ] ) ? (string) $fields[ $key ] : '';
		};

		// The CPT's own `_pb_passport_url` is only ever populated by the
		// standalone passport-upload endpoint (upload_order_passport()) — a
		// traveller's passport submitted inline with checkout instead lands
		// under `_booking_passport_doc_url` in `booking_fields` (see
		// checkout_order()/checkout_create_experience_order()), which the
		// CPT sync in pangaea-bookings.php doesn't currently read at all.
		$passport_url = (string) $g( 'passport_url' );
		if ( '' === $passport_url ) {
			$passport_url = $f( '_booking_passport_doc_url' );
		}

		// The CPT can still be sitting on its first-pass sync (before WP
		// Travel Engine's own booking post appears and triggers the fuller
		// re-sync — see resolve_live_booking_id()) at the exact moment the
		// app fetches this right after payment confirms. In that window
		// `trip_name` is either blank or the shared placeholder product
		// name ("PANGAEA Mobile Booking"), even though the order itself
		// already has everything needed to know the real trip/experience —
		// `trip_id` above resolves from the order directly, independent of
		// CPT sync state, so reading the real post title sidesteps the race
		// entirely instead of asking the client to wait it out.
		$trip_name = self::decode_stored_title( $g( 'trip_name' ) );
		if ( $trip_id > 0 && ( '' === trim( $trip_name ) || 'PANGAEA Mobile Booking' === trim( $trip_name ) ) ) {
			$live_title = get_the_title( $trip_id );
			if ( '' !== trim( (string) $live_title ) ) {
				$trip_name = self::decode_stored_title( $live_title );
			}
		}

		return array(
			'id'              => $booking_id,
			'booking_id'      => $booking_id,
			'order_id'        => $order_id,
			'trip_id'         => $trip_id,
			'booking_type'    => (string) ( $g( 'booking_type' ) ?: 'journey' ),
			'status'          => (string) $g( 'status_key' ),
			'status_label'    => (string) $g( 'status_label' ),
			'payment_status'  => $due > 0.01 ? 'partial' : 'paid',
			'booking_date'    => get_the_date( 'Y-m-d', $booking_id ) ?: '',
			'trip_name'       => $trip_name,
			'package_name'    => $trip_name,
			'duration'        => (string) $g( 'duration' ),
			'travellers'      => (int) $g( 'travellers' ),
			'traveller_count' => (int) $g( 'travellers' ),
			'departure_date'  => $start_ts ? gmdate( 'Y-m-d', $start_ts ) : '',
			'end_date'        => $end_ts ? gmdate( 'Y-m-d', $end_ts ) : '',
			'currency'        => (string) ( $g( 'currency' ) ?: 'SAR' ),
			'full_total'      => (float) $g( 'trip_cost' ),
			'paid_amount'     => $paid,
			'due_amount'      => $due,
			'extra_services'  => (string) $g( 'extra_services' ),
			'coupon_codes'    => (string) $g( 'coupon_codes' ),
			'passport_url'    => $passport_url,
			'customer'        => array(
				'name'    => (string) $g( 'customer_name' ),
				'email'   => $order ? (string) $order->get_billing_email() : $f( '_billing_email' ),
				'phone'   => $order ? (string) $order->get_billing_phone() : $f( '_billing_phone' ),
				'country' => $f( '_billing_country' ),
			),
			'traveller_details' => array(
				'age'              => $f( '_booking_age' ),
				'sex'              => $f( '_booking_sex' ),
				'blood_type'       => $f( '_booking_blood_type' ),
				'nationality'      => $f( '_booking_nationality' ),
				'emergency_name'   => $f( '_booking_emergency_name' ),
				'emergency_phone'  => $f( '_booking_emergency_phone' ),
				'medical_notes'    => $f( '_booking_medical_notes' ),
				'referral_staff_name' => (string) ( $g( 'referral_staff_name' ) ?: $f( '_booking_referral_staff_name' ) ),
			),
		);
	}

	public static function get_booking_payments( $request ) {
		$booking_id = self::resolve_live_booking_id( (int) $request['booking_id'] );
		if ( ! self::booking_allowed( $booking_id, self::user_id_from_request( $request ) ) ) {
			return self::fail( 'not_found', 'Booking not found.', 404 );
		}
		$payments = get_post_meta( $booking_id, 'payments', true );
		return self::ok( array( 'items' => is_array( $payments ) ? $payments : array() ) );
	}

	public static function pay_booking_balance( $request ) {
		$booking_id = self::resolve_live_booking_id( (int) $request['booking_id'] );
		if ( ! self::booking_allowed( $booking_id, self::user_id_from_request( $request ) ) ) {
			return self::fail( 'not_found', 'Booking not found.', 404 );
		}
		$url = function_exists( 'pangaea_get_due_checkout_link' ) ? pangaea_get_due_checkout_link( $booking_id ) : '';
		return self::ok( array( 'booking_id' => $booking_id, 'redirect_url' => $url ) );
	}

	public static function booking_cancel_request( $request ) {
		$booking_id = self::resolve_live_booking_id( (int) $request['booking_id'] );
		if ( ! self::booking_allowed( $booking_id, self::user_id_from_request( $request ) ) ) {
			return self::fail( 'not_found', 'Booking not found.', 404 );
		}
		$data = self::sanitize_deep( self::request_data( $request ) );
		add_post_meta(
			$booking_id,
			'_pangaea_mobile_cancel_request',
			array(
				'user_id'    => self::user_id_from_request( $request ),
				'data'       => $data,
				'created_at' => current_time( 'mysql' ),
			)
		);
		wp_mail( get_option( 'admin_email' ), 'PANGAEA cancellation request #' . $booking_id, wp_json_encode( $data ) );
		return self::ok( array( 'submitted' => true ), 201 );
	}

	public static function my_trips( $request ) {
		$uid   = self::user_id_from_request( $request );
		$items = array();
		// `pangaea_booking` posts are transactional records, never translated
		// per-language — but WPML's own URL-based language switching (?lang=ar)
		// still auto-filters any WP_Query by the active language unless
		// explicitly suppressed, which would otherwise silently empty this
		// list on every Arabic-locale request (single-booking lookups are
		// unaffected since those fetch by ID via get_post(), not a query).
		$query = new WP_Query(
			array(
				'post_type'        => 'pangaea_booking',
				'post_status'      => 'any',
				'posts_per_page'   => 100,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);
		foreach ( $query->posts as $post ) {
			if ( ! self::booking_allowed( $post->ID, $uid ) ) {
				continue;
			}
			$items[] = self::booking_payload( $post->ID );
		}
		return self::ok( array( 'items' => $items ) );
	}

	public static function my_trip( $request ) {
		return self::get_booking( $request );
	}

	public static function my_trip_ticket( $request ) {
		$booking_id = self::resolve_live_booking_id( (int) $request['booking_id'] );
		if ( ! self::booking_allowed( $booking_id, self::user_id_from_request( $request ) ) ) {
			return self::fail( 'not_found', 'Booking not found.', 404 );
		}
		$payload  = self::booking_payload( $booking_id );
		$order_id = (int) ( $payload['order_id'] ?? 0 );
		$url      = '';
		$qr_url   = '';
		// ticket_url is the whole ticket: a token-secured link to a
		// server-rendered viewer page (with the QR code embedded) that the app
		// opens directly, not a JSON payload for the app to render itself.
		// qr_image_url is the same QR as a direct PNG (pangaea-booking-ticket.php's
		// ?pangaea_qr_img= endpoint) for the app to show the code natively —
		// e.g. on a "View My Journey" screen — without loading the whole page.
		if ( $order_id > 0 ) {
			if ( function_exists( 'pangaea_ticket_url' ) ) {
				$url = pangaea_ticket_url( $order_id );
			}
			if ( function_exists( 'pangaea_ticket_qr_img_url' ) ) {
				$qr_url = pangaea_ticket_qr_img_url( $order_id );
			}
		}
		return self::ok(
			array(
				'booking_id'     => $booking_id,
				'order_id'       => $order_id,
				'ticket_url'     => $url,
				'qr_image_url'   => $qr_url,
				'trip_name'      => $payload['trip_name'] ?? '',
				'status'         => $payload['status'] ?? '',
				'departure_date' => $payload['departure_date'] ?? '',
			)
		);
	}

	public static function guest_booking_lookup( $request ) {
		$data = self::request_data( $request );
		$booking_id = (int) ( $data['booking_id'] ?? 0 );
		$email = strtolower( sanitize_email( (string) ( $data['email'] ?? '' ) ) );
		$phone = preg_replace( '/\D+/', '', (string) ( $data['phone'] ?? '' ) );
		$payload = self::booking_payload( $booking_id );
		if ( empty( $payload['id'] ) ) {
			return self::fail( 'not_found', 'Booking not found.', 404 );
		}
		$booking_email = strtolower( (string) ( $payload['customer']['email'] ?? $payload['email'] ?? '' ) );
		$booking_phone = preg_replace( '/\D+/', '', (string) ( $payload['customer']['phone'] ?? $payload['phone'] ?? '' ) );
		if ( ( $email && $email === $booking_email ) || ( $phone && $booking_phone && $phone === $booking_phone ) ) {
			return self::ok( $payload );
		}
		return self::fail( 'lookup_failed', 'Booking details did not match.', 404 );
	}

	public static function custom_trip_destinations( $request ) {
		if ( class_exists( 'PCTAI_Rest' ) ) {
			return rest_ensure_response( PCTAI_Rest::get_destinations( $request ) );
		}
		return self::list_destinations( $request );
	}

	public static function custom_trip_addons( $request ) {
		if ( class_exists( 'PCTAI_Rest' ) ) {
			return rest_ensure_response( PCTAI_Rest::get_addons( $request ) );
		}
		return self::ok( array( 'items' => array() ) );
	}

	public static function custom_trip_estimate( $request ) {
		if ( class_exists( 'PCTAI_Rest' ) ) {
			return rest_ensure_response( PCTAI_Rest::post_estimate( $request ) );
		}
		return self::ok( array( 'estimated_total' => 0, 'currency' => 'SAR' ) );
	}

	public static function custom_trip_ai( $request ) {
		if ( class_exists( 'PCTAI_Rest' ) ) {
			return rest_ensure_response( PCTAI_Rest::post_ai( $request ) );
		}
		return self::ok( array( 'items' => array() ) );
	}

	public static function custom_trip_submit( $request ) {
		if ( class_exists( 'PCTAI_Rest' ) ) {
			return rest_ensure_response( PCTAI_Rest::post_submit( $request ) );
		}
		return self::fail( 'custom_trip_unavailable', 'Custom trip plugin is unavailable.', 503 );
	}

	public static function custom_trip_request( $request ) {
		$post = get_post( (int) $request['request_id'] );
		if ( ! $post || 'pctai_request' !== $post->post_type ) {
			return self::fail( 'not_found', 'Request not found.', 404 );
		}
		$uid = self::user_id_from_request( $request );
		$owner = (int) get_post_meta( $post->ID, 'pctai_user_id', true );
		if ( $owner && $owner !== $uid && ! current_user_can( 'edit_post', $post->ID ) ) {
			return self::fail( 'forbidden', 'You cannot view this request.', 403 );
		}
		return self::ok( self::post_meta_payload( $post->ID, 'pctai_' ) );
	}

	public static function pangawi_chat( $request ) {
		if ( function_exists( 'pangaea_pangawi_rest_chat' ) ) {
			return rest_ensure_response( pangaea_pangawi_rest_chat( $request ) );
		}
		return self::fail( 'pangawi_unavailable', 'Pangawi is unavailable.', 503 );
	}

	public static function pangawi_event( $request ) {
		if ( function_exists( 'pangaea_pangawi_rest_event' ) ) {
			return rest_ensure_response( pangaea_pangawi_rest_event( $request ) );
		}
		return self::ok( array( 'stored' => false ) );
	}

	public static function pangawi_feedback( $request ) {
		if ( function_exists( 'pangaea_pangawi_rest_feedback' ) ) {
			return rest_ensure_response( pangaea_pangawi_rest_feedback( $request ) );
		}
		return self::ok( array( 'stored' => false ) );
	}

	public static function pangawi_booking_link( $request ) {
		if ( function_exists( 'pangaea_pangawi_create_payment_link' ) ) {
			return rest_ensure_response( pangaea_pangawi_create_payment_link( $request ) );
		}
		return self::fail( 'pangawi_unavailable', 'Pangawi booking links are unavailable.', 503 );
	}

	private static function post_meta_payload( $post_id, $prefix = '' ) {
		$meta = get_post_meta( $post_id );
		$out = array( 'id' => (int) $post_id, 'title' => get_the_title( $post_id ), 'status' => get_post_status( $post_id ) );
		foreach ( $meta as $key => $values ) {
			if ( $prefix && 0 !== strpos( $key, $prefix ) ) {
				continue;
			}
			$out[ $key ] = maybe_unserialize( $values[0] ?? '' );
		}
		return $out;
	}

	public static function get_page( $request ) {
		$lang = self::lang( $request );
		$page = get_page_by_path( sanitize_title( $request['slug'] ), OBJECT, 'page' );
		return $page ? self::ok( self::public_post_payload( $page, $lang ) ) : self::fail( 'not_found', 'Page not found.', 404 );
	}

	public static function list_blogs( $request ) {
		$lang = self::lang( $request );
		return self::ok( self::posts_query_payload( 'post', $request, function ( $post ) use ( $lang ) { return self::public_post_payload( $post, $lang ); } ) );
	}

	public static function get_blog( $request ) {
		$lang = self::lang( $request );
		$value = $request['id_or_slug'];
		$post = is_numeric( $value ) ? get_post( (int) $value ) : get_page_by_path( sanitize_title( $value ), OBJECT, 'post' );
		return $post ? self::ok( self::public_post_payload( $post, $lang ) ) : self::fail( 'not_found', 'Post not found.', 404 );
	}

	public static function list_faqs( $request ) {
		$lang = self::lang( $request );
		$items = self::simple_post_list( 'faq', 50, $lang );
		return self::ok( array( 'items' => $items ) );
	}

	public static function list_team( $request ) {
		return self::team_query( $request, '' );
	}

	public static function list_guides( $request ) {
		return self::team_query( $request, 'guide' );
	}

	private static function team_query( $request, $role_slug = '' ) {
		self::lang( $request );
		// The real CPT registered by plugins/pangaea-team-members is `guide`
		// (85 published records) — 'pgtm_member'/'team' never existed, so
		// this always silently returned an empty list.
		$post_type = post_type_exists( 'guide' ) ? 'guide' : ( post_type_exists( 'pgtm_member' ) ? 'pgtm_member' : 'team' );
		$args = array( 'post_type' => $post_type, 'post_status' => 'publish', 'posts_per_page' => 100 );
		if ( $role_slug && taxonomy_exists( 'pgtm_role' ) ) {
			$args['tax_query'] = array( array( 'taxonomy' => 'pgtm_role', 'field' => 'slug', 'terms' => $role_slug ) );
		}
		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = self::team_member_payload( $post );
		}
		return self::ok( array( 'items' => array_values( array_filter( $items ) ) ) );
	}

	public static function stories_map( $request ) {
		$post_type = post_type_exists( 'pangaea_story' ) ? 'pangaea_story' : 'pangaea_stories_map';
		$query = new WP_Query( array( 'post_type' => $post_type, 'post_status' => 'publish', 'posts_per_page' => 100 ) );
		$items = array();
		foreach ( $query->posts as $post ) {
			$item = self::public_post_payload( $post );
			$item['meta'] = get_post_meta( $post->ID );
			$items[] = $item;
		}
		return self::ok( array( 'items' => $items ) );
	}

	public static function newsletter_subscribe( $request ) {
		$data = self::request_data( $request );
		$email = sanitize_email( (string) ( $data['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return self::fail( 'invalid_email', 'Valid email is required.' );
		}
		$subscribers = get_option( 'pgb4_newsletter_subscribers', array() );
		$subscribers = is_array( $subscribers ) ? $subscribers : array();
		if ( ! in_array( $email, $subscribers, true ) ) {
			$subscribers[] = $email;
			update_option( 'pgb4_newsletter_subscribers', $subscribers, false );
			wp_mail( get_option( 'admin_email' ), 'New PANGAEA newsletter subscriber', $email );
		}
		return self::ok( array( 'subscribed' => true ), 201 );
	}

	public static function newsletter_unsubscribe( $request ) {
		$data  = self::request_data( $request );
		$email = sanitize_email( (string) ( $data['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return self::fail( 'invalid_email', 'Valid email is required.' );
		}
		$subscribers = get_option( 'pgb4_newsletter_subscribers', array() );
		$subscribers = is_array( $subscribers ) ? $subscribers : array();
		$subscribers = array_values( array_diff( $subscribers, array( $email ) ) );
		update_option( 'pgb4_newsletter_subscribers', $subscribers, false );
		return self::ok( array( 'subscribed' => false ) );
	}

	public static function newsletter_status( $request ) {
		$email = sanitize_email( (string) ( $request->get_param( 'email' ) ?? '' ) );
		if ( ! is_email( $email ) ) {
			return self::fail( 'invalid_email', 'Valid email is required.' );
		}
		$subscribers = get_option( 'pgb4_newsletter_subscribers', array() );
		$subscribers = is_array( $subscribers ) ? $subscribers : array();
		return self::ok( array( 'subscribed' => in_array( $email, $subscribers, true ) ) );
	}

	public static function local_trip_form( $request ) {
		$data = self::sanitize_deep( self::request_data( $request ) );
		$post_id = wp_insert_post(
			array(
				'post_type'   => post_type_exists( 'pangaea_local_trip_form' ) ? 'pangaea_local_trip_form' : 'post',
				'post_status' => 'private',
				'post_title'  => 'Mobile local trip enquiry - ' . current_time( 'mysql' ),
			)
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		foreach ( $data as $key => $value ) {
			update_post_meta( $post_id, '_plf_' . sanitize_key( $key ), $value );
		}
		wp_mail( get_option( 'admin_email' ), 'New mobile local trip enquiry', wp_json_encode( $data ) );
		return self::ok( array( 'request_id' => (int) $post_id ), 201 );
	}

	public static function social_proof( $request ) {
		return self::ok( array( 'items' => array(), 'source' => 'mobile' ) );
	}

	public static function list_notifications( $request ) {
		$uid  = self::user_id_from_request( $request );
		$feed = get_user_meta( $uid, self::NOTIFICATIONS_FEED_META, true );
		$feed = is_array( $feed ) ? array_reverse( array_values( $feed ) ) : array(); // newest first

		$page      = max( 1, (int) $request->get_param( 'page' ) );
		$per_page  = min( 50, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$total     = count( $feed );
		$items     = array_slice( $feed, ( $page - 1 ) * $per_page, $per_page );
		$unread    = count( array_filter( $feed, static function ( $n ) { return empty( $n['read'] ); } ) );

		return self::ok(
			array(
				'items'         => $items,
				'unread_count'  => $unread,
				'pagination'    => array(
					'page'        => $page,
					'per_page'    => $per_page,
					'total'       => $total,
					'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
				),
			)
		);
	}

	public static function mark_notification_read( $request ) {
		$uid = self::user_id_from_request( $request );
		$id  = (string) $request['notification_id'];
		$feed = get_user_meta( $uid, self::NOTIFICATIONS_FEED_META, true );
		$feed = is_array( $feed ) ? $feed : array();
		$found = false;
		foreach ( $feed as &$n ) {
			if ( (string) ( $n['id'] ?? '' ) === $id ) {
				$n['read'] = true;
				$found = true;
				break;
			}
		}
		unset( $n );
		if ( ! $found ) {
			return self::fail( 'not_found', 'Notification not found.', 404 );
		}
		update_user_meta( $uid, self::NOTIFICATIONS_FEED_META, $feed );
		return self::ok( array( 'marked_read' => true ) );
	}

	public static function mark_all_notifications_read( $request ) {
		$uid  = self::user_id_from_request( $request );
		$feed = get_user_meta( $uid, self::NOTIFICATIONS_FEED_META, true );
		$feed = is_array( $feed ) ? $feed : array();
		foreach ( $feed as &$n ) {
			$n['read'] = true;
		}
		unset( $n );
		update_user_meta( $uid, self::NOTIFICATIONS_FEED_META, $feed );
		return self::ok( array( 'marked_read' => true ) );
	}

	/**
	 * Browse-only room listing for a Stays "location" — reuses the exact
	 * same cross-site call already proven live on the PANGAEA Hub destination
	 * pages (pangaea-hub-stays-shortcode.php calling stay.pangaeaclub.net's
	 * own public rooms API), transient-cached there already. There is no
	 * booking flow here, same as the website: a room links out to
	 * stay.pangaeaclub.net to actually book, since stays are a fully separate
	 * booking system on that other site, not something this API can create
	 * an order for.
	 */
	/**
	 * Full room data straight from stay.pangaeaclub.net's own comprehensive
	 * rooms-full API (a separate, richer endpoint from the one
	 * pangaea-hub-stays-shortcode.php uses for website cards) — description,
	 * full gallery, complete pricing, occupancy rules, and every assigned
	 * facility, not just the card-shaped subset. Cached here too, same
	 * reasoning as that shortcode: never block a page/request on a slow or
	 * failed cross-site call more than once per hour per query.
	 */
	private static function stay_rooms_full_request( string $path, array $query = array() ) {
		$cache_key = 'pangaea_mobile_stay_full_' . md5( $path . wp_json_encode( $query ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
		$url = 'https://stay.pangaeaclub.net/wp-json/pangaea/v1/rooms-full' . $path;
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}
		$response = wp_remote_get( $url, array( 'timeout' => 8 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return null;
		}
		set_transient( $cache_key, $body, HOUR_IN_SECONDS );
		return $body;
	}

	/**
	 * Stays has no free-text search of its own on stay.pangaeaclub.net (its
	 * rooms-full API is location-scoped only) — unlike trips/experiences,
	 * matching here is a plain local substring check across each room's own
	 * title/subtitle/description plus its location name, not the same
	 * AI/fuzzy scoring engine. Safe to fetch every location's full room list
	 * on every search (rather than a smarter/narrower query) because both
	 * calls are already the same hourly-cached request list_stays()/
	 * list_stay_locations() use, and the catalog itself is small (a handful
	 * of locations, a few rooms each) — nowhere near trip/experience scale.
	 */
	private static function stay_search_matches( $query, $cap ) {
		$needle = function_exists( 'pangaea_smart_trip_search_normalize' )
			? pangaea_smart_trip_search_normalize( $query )
			: strtolower( trim( (string) $query ) );
		if ( '' === $needle ) {
			return array();
		}

		$locations_body = self::stay_rooms_full_request( '/locations' );
		$locations      = is_array( $locations_body ) ? (array) ( $locations_body['data'] ?? array() ) : array();
		if ( empty( $locations ) ) {
			return array();
		}

		$matches = array();
		foreach ( $locations as $location ) {
			$slug = sanitize_title( (string) ( $location['slug'] ?? '' ) );
			if ( '' === $slug ) {
				continue;
			}
			$location_name = (string) ( $location['name'] ?? '' );
			$rooms_body    = self::stay_rooms_full_request( '', array( 'location' => $slug, 'per_page' => 50 ) );
			$rooms         = is_array( $rooms_body ) ? (array) ( $rooms_body['data'] ?? array() ) : array();

			foreach ( $rooms as $room ) {
				if ( ! is_array( $room ) ) {
					continue;
				}
				$title    = (string) ( $room['title'] ?? '' );
				$haystack = function_exists( 'pangaea_smart_trip_search_normalize' )
					? pangaea_smart_trip_search_normalize(
						$title . ' ' . (string) ( $room['subtitle'] ?? '' ) . ' ' . (string) ( $room['description'] ?? '' ) . ' ' . $location_name
					)
					: strtolower( $title . ' ' . (string) ( $room['subtitle'] ?? '' ) . ' ' . (string) ( $room['description'] ?? '' ) . ' ' . $location_name );

				if ( false === strpos( $haystack, $needle ) ) {
					continue;
				}
				// A hit in the title/location itself outranks one buried
				// only in the long description — same "where" and "how
				// strong" distinction pangaea_smart_trip_search_score_segment()
				// makes for trips, just without its fuzzy-matching machinery
				// (no catalog large enough here to need it).
				$title_haystack = function_exists( 'pangaea_smart_trip_search_normalize' )
					? pangaea_smart_trip_search_normalize( $title . ' ' . $location_name )
					: strtolower( $title . ' ' . $location_name );
				$score          = ( false !== strpos( $title_haystack, $needle ) ) ? 100.0 : 40.0;

				$gallery = is_array( $room['gallery'] ?? null ) ? $room['gallery'] : array();
				$image   = isset( $gallery[0]['url'] ) ? (string) $gallery[0]['url'] : '';

				$matches[] = array(
					'score'   => $score,
					'payload' => array(
						'id'            => (int) ( $room['id'] ?? 0 ),
						'type'          => 'stay',
						'title'         => $title,
						'subtitle'      => (string) ( $room['subtitle'] ?? '' ),
						'permalink'     => (string) ( $room['permalink'] ?? '' ),
						'image'         => $image,
						'price'         => (float) ( $room['pricing']['regular_price'] ?? 0 ),
						'currency'      => (string) ( $room['currency'] ?? 'SAR' ),
						'location'      => $location_name,
						'location_slug' => $slug,
					),
				);
			}
		}

		usort(
			$matches,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $matches, 0, $cap );
	}

	/**
	 * Every room across the given location slugs (or every known location
	 * when none are given), pulled from the same hourly-cached per-location
	 * request list_stays()/stay_search_matches() already use. Filtering
	 * happens here, not on stay.pangaeaclub.net, since that site's own API
	 * is location-scoped only and has no filter params of its own — the
	 * whole catalog is small enough (a handful of locations, a few rooms
	 * each) that fetching it all up front and filtering locally is cheap,
	 * and it means the app isn't blocked on that other team building
	 * filtering support on their end.
	 */
	private static function stays_all_rooms( array $location_slugs = array() ) {
		if ( empty( $location_slugs ) ) {
			$locations_body = self::stay_rooms_full_request( '/locations' );
			if ( null === $locations_body ) {
				return array( 'rooms' => array(), 'ok' => false );
			}
			foreach ( (array) ( $locations_body['data'] ?? array() ) as $loc ) {
				$slug = sanitize_title( (string) ( $loc['slug'] ?? '' ) );
				if ( '' !== $slug ) {
					$location_slugs[] = $slug;
				}
			}
		}
		$rooms   = array();
		$any_ok  = false;
		foreach ( array_unique( $location_slugs ) as $slug ) {
			$body = self::stay_rooms_full_request( '', array( 'location' => $slug, 'per_page' => 100 ) );
			if ( null === $body ) {
				continue;
			}
			$any_ok = true;
			foreach ( (array) ( $body['data'] ?? array() ) as $room ) {
				if ( is_array( $room ) ) {
					$rooms[] = $room;
				}
			}
		}
		return array( 'rooms' => $rooms, 'ok' => $any_ok || empty( $location_slugs ) );
	}

	/**
	 * True if a room satisfies every filter that was actually supplied —
	 * price range, minimum guest capacity (occupancy.max_people), and a
	 * required set of facility types (AND, not OR: a guest asking for
	 * "wifi,air-conditioning" wants both, not either).
	 */
	private static function stay_room_matches_filters( array $room, $price_min, $price_max, $guests_min, array $facility_types ) {
		if ( null !== $price_min || null !== $price_max ) {
			$price = (float) ( $room['pricing']['regular_price'] ?? 0 );
			if ( null !== $price_min && $price < (float) $price_min ) {
				return false;
			}
			if ( null !== $price_max && $price > (float) $price_max ) {
				return false;
			}
		}
		if ( $guests_min > 0 ) {
			$max_people = (int) ( $room['occupancy']['max_people'] ?? 0 );
			if ( $max_people < $guests_min ) {
				return false;
			}
		}
		if ( ! empty( $facility_types ) ) {
			$room_types = array();
			foreach ( (array) ( $room['facilities'] ?? array() ) as $facility ) {
				if ( is_array( $facility ) && ! empty( $facility['type'] ) ) {
					$room_types[] = sanitize_title( (string) $facility['type'] );
				}
			}
			foreach ( $facility_types as $needed ) {
				if ( ! in_array( $needed, $room_types, true ) ) {
					return false;
				}
			}
		}
		return true;
	}

	public static function list_stays( $request ) {
		$location_param = sanitize_text_field( (string) $request->get_param( 'location' ) );
		$location_slugs = array_values( array_filter( array_map( 'sanitize_title', explode( ',', $location_param ) ) ) );

		$price_min_raw = $request->get_param( 'price_min' );
		$price_max_raw = $request->get_param( 'price_max' );
		$price_min     = ( null === $price_min_raw || '' === $price_min_raw ) ? null : (float) $price_min_raw;
		$price_max     = ( null === $price_max_raw || '' === $price_max_raw ) ? null : (float) $price_max_raw;
		$guests_min    = max( 0, (int) $request->get_param( 'guests' ) );
		$facilities_param = sanitize_text_field( (string) $request->get_param( 'facilities' ) );
		$facility_types    = array_values( array_filter( array_map( 'sanitize_title', explode( ',', $facilities_param ) ) ) );
		$sort = sanitize_key( (string) $request->get_param( 'sort' ) );

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 50 ) ) );

		$result = self::stays_all_rooms( $location_slugs );
		if ( ! $result['ok'] ) {
			return self::fail( 'stays_unavailable', 'Stays module is temporarily unavailable.', 503 );
		}

		$rooms = array_values(
			array_filter(
				$result['rooms'],
				static function ( $room ) use ( $price_min, $price_max, $guests_min, $facility_types ) {
					return self::stay_room_matches_filters( $room, $price_min, $price_max, $guests_min, $facility_types );
				}
			)
		);

		if ( 'price_asc' === $sort || 'price_desc' === $sort ) {
			usort(
				$rooms,
				static function ( $a, $b ) use ( $sort ) {
					$pa = (float) ( $a['pricing']['regular_price'] ?? 0 );
					$pb = (float) ( $b['pricing']['regular_price'] ?? 0 );
					return 'price_asc' === $sort ? ( $pa <=> $pb ) : ( $pb <=> $pa );
				}
			);
		}

		$total       = count( $rooms );
		$total_pages = (int) ceil( $total / $per_page );
		$slice       = array_slice( $rooms, ( $page - 1 ) * $per_page, $per_page );

		return self::ok(
			array(
				'location'   => $location_param,
				'items'      => $slice,
				'pagination' => array(
					'page'        => $page,
					'per_page'    => $per_page,
					'total'       => $total,
					'total_pages' => $total_pages,
				),
			)
		);
	}

	public static function get_stay( $request ) {
		$room_id = (int) $request['room_id'];
		$body    = self::stay_rooms_full_request( '/' . $room_id );
		if ( null === $body || empty( $body['data'] ) ) {
			return self::fail( 'room_not_found', 'Room not found.', 404 );
		}
		return self::ok( $body['data'] );
	}

	public static function list_stay_locations( $request ) {
		$body = self::stay_rooms_full_request( '/locations' );
		return self::ok( array( 'items' => ( null === $body ) ? array() : ( $body['data'] ?? array() ) ) );
	}

	/**
	 * Real price/guest ranges and the live set of facility types, computed
	 * from the same cached room data list_stays() filters against — so the
	 * app's filter UI always matches what list_stays() can actually filter
	 * by, the same guarantee trip_filters()/experience_filters() give for
	 * Journeys and Experiences.
	 */
	public static function stays_filters( $request ) {
		$result = self::stays_all_rooms();
		if ( ! $result['ok'] ) {
			return self::fail( 'stays_unavailable', 'Stays module is temporarily unavailable.', 503 );
		}
		$rooms = $result['rooms'];

		$prices  = array();
		$guests  = array();
		$facility_types = array();
		foreach ( $rooms as $room ) {
			if ( isset( $room['pricing']['regular_price'] ) ) {
				$prices[] = (float) $room['pricing']['regular_price'];
			}
			if ( isset( $room['occupancy']['max_people'] ) ) {
				$guests[] = (int) $room['occupancy']['max_people'];
			}
			foreach ( (array) ( $room['facilities'] ?? array() ) as $facility ) {
				if ( ! is_array( $facility ) || empty( $facility['type'] ) ) {
					continue;
				}
				$type = sanitize_title( (string) $facility['type'] );
				if ( ! isset( $facility_types[ $type ] ) ) {
					$facility_types[ $type ] = array(
						'type' => $type,
						'name' => (string) ( $facility['name'] ?? $type ),
						'icon' => (string) ( $facility['icon'] ?? '' ),
					);
				}
			}
		}

		$locations_body = self::stay_rooms_full_request( '/locations' );
		$locations      = is_array( $locations_body ) ? (array) ( $locations_body['data'] ?? array() ) : array();

		return self::ok(
			array(
				'locations'      => array_values( $locations ),
				'price_range'    => array(
					'min' => $prices ? min( $prices ) : 0,
					'max' => $prices ? max( $prices ) : 0,
				),
				'guests_range'   => array(
					'min' => $guests ? min( $guests ) : 1,
					'max' => $guests ? max( $guests ) : 1,
				),
				'facility_types' => array_values( $facility_types ),
				'sort_options'   => array(
					array( 'key' => 'recommended', 'label' => 'Recommended', 'label_ar' => 'موصى به' ),
					array( 'key' => 'price_asc', 'label' => 'Price: low to high', 'label_ar' => 'السعر: من الأقل للأعلى' ),
					array( 'key' => 'price_desc', 'label' => 'Price: high to low', 'label_ar' => 'السعر: من الأعلى للأقل' ),
				),
			)
		);
	}

	public static function notifications_register_device( $request ) {
		$uid = self::user_id_from_request( $request );
		$data = self::sanitize_deep( self::request_data( $request ) );
		$devices = get_user_meta( $uid, self::DEVICES_META, true );
		$devices = is_array( $devices ) ? $devices : array();
		$id = sanitize_text_field( (string) ( $data['device_id'] ?? wp_generate_uuid4() ) );
		$data['device_id'] = $id;
		$data['updated_at'] = current_time( 'mysql' );
		$devices[ $id ] = $data;
		update_user_meta( $uid, self::DEVICES_META, $devices );
		return self::ok( $data, 201 );
	}

	public static function notifications_delete_device( $request ) {
		$uid = self::user_id_from_request( $request );
		$devices = get_user_meta( $uid, self::DEVICES_META, true );
		$devices = is_array( $devices ) ? $devices : array();
		unset( $devices[ (string) $request['device_id'] ] );
		update_user_meta( $uid, self::DEVICES_META, $devices );
		return self::ok( array( 'deleted' => true ) );
	}

	/**
	 * Key set matches the app (booking_updates/promotions/trip_reminders/
	 * pangawi_recommendations) — previously seeded booking_updates/marketing/
	 * pangawi_recommendations, a naming mismatch the app had no server-agreed
	 * 'trip_reminders' key at all.
	 */
	private static function default_notification_preferences() {
		return array(
			'booking_updates'         => true,
			'promotions'              => false,
			'trip_reminders'          => true,
			'pangawi_recommendations' => true,
		);
	}

	public static function notifications_get_preferences( $request ) {
		$prefs = get_user_meta( self::user_id_from_request( $request ), self::NOTIFICATION_META, true );
		$prefs = is_array( $prefs ) ? array_merge( self::default_notification_preferences(), $prefs ) : self::default_notification_preferences();
		return self::ok( $prefs );
	}

	public static function notifications_update_preferences( $request ) {
		$uid  = self::user_id_from_request( $request );
		$data = self::sanitize_deep( self::request_data( $request ) );
		// Merges onto the existing stored prefs (and the defaults, for a
		// first-ever save) instead of replacing the whole object — a PATCH
		// that only sends the one toggle the user changed no longer silently
		// resets every other preference to its default.
		$existing = get_user_meta( $uid, self::NOTIFICATION_META, true );
		$existing = is_array( $existing ) ? $existing : array();
		$prefs    = array_merge( self::default_notification_preferences(), $existing, $data );
		update_user_meta( $uid, self::NOTIFICATION_META, $prefs );
		return self::ok( $prefs );
	}

	public static function policies( $request ) {
		self::lang( $request );
		return self::ok(
			array(
				'privacy' => array( 'url' => get_privacy_policy_url(), 'version' => 'current' ),
				'terms'   => array( 'url' => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'terms' ) : '', 'version' => 'current' ),
			)
		);
	}

	public static function consents( $request ) {
		$uid = self::user_id_from_request( $request );
		$data = self::sanitize_deep( self::request_data( $request ) );
		$data['accepted_at'] = current_time( 'mysql' );
		update_user_meta( $uid, self::CONSENTS_META, $data );
		return self::ok( $data, 201 );
	}

	public static function analytics_event( $request ) {
		do_action( 'pangaea_mobile_analytics_event', self::sanitize_deep( self::request_data( $request ) ), $request );
		return self::ok( array( 'accepted' => true ) );
	}

	public static function client_log( $request ) {
		do_action( 'pangaea_mobile_client_log', self::sanitize_deep( self::request_data( $request ) ), $request );
		return self::ok( array( 'accepted' => true ) );
	}

	public static function health( $request ) {
		return self::ok(
			array(
				'namespace' => self::NS,
				'time'      => current_time( 'mysql' ),
				'wordpress' => get_bloginfo( 'version' ),
				'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'wte'       => defined( 'WP_TRAVEL_ENGINE_VERSION' ) ? WP_TRAVEL_ENGINE_VERSION : null,
			)
		);
	}
}
