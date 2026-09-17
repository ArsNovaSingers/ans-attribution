<?php
/**
 * Plugin Name: Ars Nova Attribution
 * Plugin URI:  https://github.com/ArsNovaSingers/ans-attribution
 * Description: Campaign attribution for print mailers and on-air radio. Captures a campaign ref off the landing URL, auto-applies that campaign's coupon, refuses to stack it on a Flex Pass / Season Package, and stamps every resulting order so the mailer's return is answerable years later without depending on GA4.
 * Version:     1.4.1
 * Author:      Ars Nova (Jonathan Raabe) + Claude
 * Requires PHP: 7.4
 * Text Domain: ans-attribution
 *
 * @package ans-attribution
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ANS_ATTR_VERSION', '1.4.1' );
define( 'ANS_ATTR_OVERRIDES_OPTION', 'ans_attr_overrides' );
define( 'ANS_ATTR_SCANS_OPTION', 'ans_attr_scans' );
define( 'ANS_ATTR_COOKIE', 'ans_attr' );
define( 'ANS_ATTR_TTL', 60 * DAY_IN_SECONDS );

/**
 * The campaign registry.
 *
 * One entry per printed piece. `coupon` is the code printed on the card AND the
 * value of the `ansref` query parameter the short link carries. `short_path` is
 * the Redirection plugin source whose hit counter is the scan count.
 *
 * @return array<string,array<string,mixed>>
 */
function ans_attr_campaigns() {
	$campaigns = array(
		'rivers-streams-mailer-2026' => array(
			'label'       => 'Rivers & Streams postcard mailer (Oct 2026)',
			'coupon'      => 'RIVERS10',
			'short_path'  => '/go/rs',
			// The printed QR carries ?m=1. Kinsta's edge serves a bare /go/rs
			// from cache and strips Set-Cookie; any unrecognised query param
			// forces BYPASS. Measured 3/3 both ways on LIVE, 2026-09-09.
			'qr_query'    => 'm=1',
			'destination' => '/this-season/rivers-and-streams/',
			'utm_content' => 'rivers-streams-mailer-2026',
			'utm_campaign' => 'confluence-2627',
			'pieces'      => 650,
			'mail_house'  => 'Allegra',
		),
		// CPR Classical (KVOD 88.1) on-air sponsorship, 2026-27 season. 180
		// 15-second spots, 2026-09-28 to 2027-05-23. The announcer SAYS the
		// URL, so it cannot carry a cache-busting query param. The spoken
		// aliases below are plain 302s to short_path + qr_query; a cached
		// alias is harmless because it records nothing. The counted hop is
		// the second one, which always carries ?r=1 and so always BYPASSes
		// Kinsta's edge cache (see ans_attr_handle_short_link).
		//
		// `coupon` here is a REF ONLY. There is deliberately no WooCommerce
		// coupon behind it: noncommercial underwriting rules keep discounts
		// off the air. auto-apply finds no coupon and does nothing; the
		// order stamp still records the campaign.
		//
		// `destination` and `utm_content` move to the next concert each
		// flight. Change them with POST attribution/campaign/cpr-kvod-2627,
		// not by editing this file.
		'cpr-kvod-2627' => array(
			'label'        => 'CPR Classical (KVOD) on-air sponsorship 2026-27',
			'coupon'       => 'CPRKVOD',
			'short_path'   => '/go/cpr',
			'qr_query'     => 'r=1',
			'aliases'      => array( '/cpr', '/npr', '/kvod' ),
			'destination'  => '/this-season/rivers-and-streams/',
			'utm_source'   => 'cpr',
			'utm_medium'   => 'radio',
			'utm_campaign' => 'kvod-2627',
			'utm_content'  => 'rivers-and-streams',
			'spots'        => 180,
			'channel'      => 'radio',
		),
	);

	$campaigns = (array) apply_filters( 'ans_attr_campaigns', $campaigns );

	return ans_attr_apply_overrides( $campaigns );
}

/**
 * Fields that may be changed at runtime, without a plugin release.
 *
 * Only where a campaign POINTS, never what it counts or which code it carries —
 * changing `coupon` or `short_path` at runtime would orphan the counters.
 *
 * @return string[]
 */
function ans_attr_overridable_fields() {
	return array( 'destination', 'utm_content' );
}

/**
 * Layer stored runtime overrides onto the code registry.
 *
 * @param array<string,array<string,mixed>> $campaigns Registry.
 * @return array<string,array<string,mixed>>
 */
function ans_attr_apply_overrides( $campaigns ) {
	$overrides = get_option( ANS_ATTR_OVERRIDES_OPTION, array() );

	if ( ! is_array( $overrides ) ) {
		return $campaigns;
	}

	foreach ( $overrides as $key => $fields ) {
		if ( ! isset( $campaigns[ $key ] ) || ! is_array( $fields ) ) {
			continue;
		}

		foreach ( ans_attr_overridable_fields() as $field ) {
			if ( isset( $fields[ $field ] ) && '' !== $fields[ $field ] ) {
				$campaigns[ $key ][ $field ] = $fields[ $field ];
			}
		}
	}

	return $campaigns;
}

/**
 * REST: re-point a campaign (destination / utm_content).
 *
 * Every change is appended to a history list on the override itself, so the
 * report can later say which concert a given week's traffic was sent to.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function ans_attr_update_campaign( $request ) {
	nocache_headers();

	$key       = sanitize_key( (string) $request['key'] );
	$campaigns = ans_attr_campaigns();

	if ( ! isset( $campaigns[ $key ] ) ) {
		return new WP_Error( 'ans_attr_unknown_campaign', 'No such campaign.', array( 'status' => 404 ) );
	}

	$overrides = get_option( ANS_ATTR_OVERRIDES_OPTION, array() );

	if ( ! is_array( $overrides ) ) {
		$overrides = array();
	}

	$current = isset( $overrides[ $key ] ) && is_array( $overrides[ $key ] ) ? $overrides[ $key ] : array();
	$changed = array();

	$destination = $request->get_param( 'destination' );

	if ( null !== $destination ) {
		$destination = '/' . ltrim( (string) wp_parse_url( esc_url_raw( home_url( (string) $destination ) ), PHP_URL_PATH ), '/' );

		if ( '/' === $destination ) {
			return new WP_Error( 'ans_attr_bad_destination', 'destination must be a site path such as /this-season/darkness-and-light/.', array( 'status' => 400 ) );
		}

		$current['destination'] = trailingslashit( $destination );
		$changed['destination'] = $current['destination'];
	}

	$content = $request->get_param( 'utm_content' );

	if ( null !== $content ) {
		$content = sanitize_title( (string) $content );

		if ( '' === $content ) {
			return new WP_Error( 'ans_attr_bad_content', 'utm_content must not be empty.', array( 'status' => 400 ) );
		}

		$current['utm_content'] = $content;
		$changed['utm_content'] = $content;
	}

	if ( empty( $changed ) ) {
		return new WP_Error( 'ans_attr_nothing_to_change', 'Pass destination and/or utm_content.', array( 'status' => 400 ) );
	}

	$history   = isset( $current['history'] ) && is_array( $current['history'] ) ? $current['history'] : array();
	$history[] = array_merge( array( 'at' => gmdate( 'c' ) ), $changed );

	$current['history'] = array_slice( $history, -50 );
	$overrides[ $key ]  = $current;

	update_option( ANS_ATTR_OVERRIDES_OPTION, $overrides, false );

	$campaigns = ans_attr_campaigns();

	return rest_ensure_response(
		array(
			'campaign'    => $key,
			'destination' => $campaigns[ $key ]['destination'],
			'utm_content' => $campaigns[ $key ]['utm_content'],
			'history'     => $current['history'],
		)
	);
}

/**
 * Find a campaign by the ref printed on the piece (its coupon code).
 *
 * @param string $ref Raw ref from the URL.
 * @return array<string,mixed>|null
 */
function ans_attr_campaign_by_ref( $ref ) {
	$ref = strtoupper( trim( (string) $ref ) );

	if ( '' === $ref ) {
		return null;
	}

	foreach ( ans_attr_campaigns() as $key => $campaign ) {
		if ( strtoupper( $campaign['coupon'] ) === $ref ) {
			$campaign['key'] = $key;
			return $campaign;
		}
	}

	return null;
}

/**
 * Is this code one of ours?
 *
 * @param string $code Coupon code.
 * @return bool
 */
function ans_attr_is_ours( $code ) {
	$code = strtoupper( trim( (string) $code ) );

	foreach ( ans_attr_campaigns() as $campaign ) {
		if ( strtoupper( $campaign['coupon'] ) === $code ) {
			return true;
		}
	}

	return false;
}

/**
 * Capture the campaign ref off the landing URL into a 60-day cookie.
 *
 * The window matters: Ars Nova patrons routinely scan in October and buy in
 * November. A session-scoped capture would lose most of the mailer's effect.
 *
 * @return void
 */
function ans_attr_capture() {
	if ( is_admin() || wp_doing_cron() ) {
		return;
	}

	if ( empty( $_GET['ansref'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$campaign = ans_attr_campaign_by_ref( sanitize_text_field( wp_unslash( $_GET['ansref'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( ! $campaign ) {
		return;
	}

	$content = isset( $_GET['utm_content'] ) ? sanitize_title( wp_unslash( $_GET['utm_content'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	ans_attr_set_cookie( $campaign['key'], $campaign['coupon'], $content );
}
add_action( 'init', 'ans_attr_capture', 5 );

/**
 * Write the attribution cookie.
 *
 * @param string $campaign_key Campaign key.
 * @param string $coupon       Coupon code.
 * @param string $content      utm_content the visitor was sent to, if known.
 * @return void
 */
function ans_attr_set_cookie( $campaign_key, $coupon, $content = '' ) {
	$data = array(
		'campaign' => $campaign_key,
		'coupon'   => $coupon,
		'ts'       => time(),
	);

	if ( '' !== (string) $content ) {
		$data['content'] = sanitize_title( (string) $content );
	}

	$payload = wp_json_encode( $data );

	if ( ! headers_sent() ) {
		setcookie(
			ANS_ATTR_COOKIE,
			$payload,
			time() + ANS_ATTR_TTL,
			COOKIEPATH ? COOKIEPATH : '/',
			COOKIE_DOMAIN,
			is_ssl(),
			false
		);
	}

	$_COOKIE[ ANS_ATTR_COOKIE ] = $payload;
}

/**
 * The stored capture, if any.
 *
 * @return array<string,mixed>|null
 */
function ans_attr_stored() {
	if ( empty( $_COOKIE[ ANS_ATTR_COOKIE ] ) ) {
		return null;
	}

	$data = json_decode( wp_unslash( $_COOKIE[ ANS_ATTR_COOKIE ] ), true );

	if ( ! is_array( $data ) || empty( $data['coupon'] ) || empty( $data['campaign'] ) ) {
		return null;
	}

	if ( ! ans_attr_is_ours( $data['coupon'] ) ) {
		return null;
	}

	return $data;
}

/**
 * Does this cart qualify for a Flex Pass / Season Package?
 *
 * Kimberly Brody, 2026-09-09, on the Rivers & Streams mailer: the code "must
 * work ONLY for these concerts and not on a package." Restricting the coupon to
 * the concert's ten products does NOT satisfy that — a cart holding Rivers &
 * Streams plus four other concerts earns the 20% package AND would still take
 * 10% off the Rivers lines on top. This is the guard for that case.
 *
 * It deliberately calls ans-season-packages' OWN functions rather than
 * reimplementing the tier thresholds. A second copy of that logic would drift
 * silently the first time the tiers change, and the failure would be invisible:
 * a discount quietly stacking on a package for months.
 *
 * Fails open when the packages plugin is absent — with no package discount in
 * play there is nothing to stack on.
 *
 * @param WC_Cart|null $cart Cart, or null for the session cart.
 * @return bool
 */
function ans_attr_cart_has_package( $cart = null ) {
	if ( ! function_exists( 'ans_spd_concert_terms' )
		|| ! function_exists( 'ans_spd_item_concert_term' )
		|| ! function_exists( 'ans_spd_tiers' ) ) {
		return false;
	}

	if ( null === $cart ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		$cart = WC()->cart;
	}

	if ( ! $cart instanceof WC_Cart ) {
		return false;
	}

	$terms = array();

	foreach ( $cart->get_cart() as $item ) {
		// ans-season-packages stamps this on every line it discounted.
		if ( ! empty( $item['ans_spd'] ) ) {
			return true;
		}

		$product_id = ! empty( $item['product_id'] ) ? (int) $item['product_id'] : 0;

		if ( $product_id <= 0 ) {
			continue;
		}

		$term = (int) ans_spd_item_concert_term( $product_id );

		if ( $term > 0 ) {
			$terms[ $term ] = true;
		}
	}

	$distinct = count( $terms );
	$lowest   = PHP_INT_MAX;

	foreach ( ans_spd_tiers() as $tier ) {
		if ( isset( $tier['min'] ) ) {
			$lowest = min( $lowest, (int) $tier['min'] );
		}
	}

	if ( PHP_INT_MAX === $lowest ) {
		return false;
	}

	return $distinct >= $lowest;
}

/**
 * Refuse the mailer coupon on a package cart.
 *
 * This runs on validation, so it covers a code typed by hand at checkout as
 * well as one applied automatically. Throwing is how WooCommerce surfaces the
 * reason to the buyer instead of failing silently.
 *
 * @param bool        $valid  Current validity.
 * @param WC_Coupon   $coupon The coupon.
 * @param WC_Discounts $discounts Discounts object.
 * @return bool
 * @throws Exception When the cart already earns a package discount.
 */
function ans_attr_block_on_package( $valid, $coupon, $discounts ) {
	if ( ! $valid || ! $coupon instanceof WC_Coupon ) {
		return $valid;
	}

	if ( ! ans_attr_is_ours( $coupon->get_code() ) ) {
		return $valid;
	}

	if ( ans_attr_cart_has_package() ) {
		throw new Exception(
			esc_html__(
				'The mailer discount cannot be combined with a Flex Pass or Season Package. Your package saving is the larger of the two and has been kept.',
				'ans-attribution'
			)
		);
	}

	return $valid;
}
add_filter( 'woocommerce_coupon_is_valid', 'ans_attr_block_on_package', 10, 3 );

/**
 * Apply the captured coupon automatically, and pull it back off if the cart
 * grows into a package.
 *
 * Auto-apply is the whole point: a patron who scans a postcard should not have
 * to find a coupon box and retype a code correctly for the mailer to be
 * measurable. Priority 30 so ans-season-packages (priority 20) has already
 * stamped its lines this request.
 *
 * @param WC_Cart $cart Cart being calculated.
 * @return void
 */
function ans_attr_auto_apply( $cart ) {
	static $running = false;

	if ( $running ) {
		return; // apply_coupon() recalculates totals; do not recurse.
	}

	if ( is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	if ( ! $cart instanceof WC_Cart || ! $cart->get_cart() ) {
		return;
	}

	$stored = ans_attr_stored();

	if ( ! $stored ) {
		return;
	}

	$code       = $stored['coupon'];
	$is_package = ans_attr_cart_has_package( $cart );

	if ( $cart->has_discount( $code ) ) {
		if ( $is_package ) {
			$running = true;
			$cart->remove_coupon( $code );
			$running = false;
		}
		return;
	}

	if ( $is_package ) {
		return;
	}

	$coupon = new WC_Coupon( $code );

	if ( ! $coupon->get_id() ) {
		return; // Code printed on a card that was never created. Do nothing.
	}

	$restricted = array_map( 'intval', (array) $coupon->get_product_ids() );

	if ( ! empty( $restricted ) ) {
		$match = false;

		foreach ( $cart->get_cart() as $item ) {
			if ( in_array( (int) $item['product_id'], $restricted, true ) ) {
				$match = true;
				break;
			}
		}

		if ( ! $match ) {
			return; // Nothing in the cart this coupon covers.
		}
	}

	$running = true;
	$cart->apply_coupon( $code );
	$running = false;
}
add_action( 'woocommerce_before_calculate_totals', 'ans_attr_auto_apply', 30 );

/**
 * Stamp the order with the campaign.
 *
 * This is the counter that outlives everything else. GA4 samples and expires;
 * a coupon's usage count dies if the code is ever reused. Order meta is exact,
 * permanent, and queryable long after the season is over.
 *
 * @param WC_Order $order Order being created.
 * @return void
 */
function ans_attr_stamp_order( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	$stored = ans_attr_stored();

	if ( ! $stored ) {
		return;
	}

	$order->update_meta_data( '_ans_campaign', sanitize_text_field( $stored['campaign'] ) );
	$order->update_meta_data( '_ans_ref', sanitize_text_field( $stored['coupon'] ) );
	$order->update_meta_data( '_ans_landed', gmdate( 'c', (int) $stored['ts'] ) );

	// Which concert the visitor was sent to. For a season-long campaign whose
	// destination moves each flight, this is the only per-concert breakdown
	// that survives GA4 retention.
	if ( ! empty( $stored['content'] ) ) {
		$order->update_meta_data( '_ans_content', sanitize_title( $stored['content'] ) );
	}
}
add_action( 'woocommerce_checkout_create_order', 'ans_attr_stamp_order', 20, 1 );
add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'ans_attr_stamp_order', 20, 1 );

/**
 * Scan count for a campaign, read off the Redirection plugin's own hit counter.
 *
 * Server-side, so it counts scans that ad-blockers and privacy browsers hide
 * from GA4.
 *
 * @param string $short_path Redirection source path.
 * @return int|null Null when Redirection is not installed.
 */
function ans_attr_scan_count( $short_path, $campaign_key = '' ) {
	global $wpdb;

	// Our own counter is authoritative from v1.1.0 on — we serve the hop.
	if ( '' !== $campaign_key ) {
		$counts = get_option( ANS_ATTR_SCANS_OPTION, array() );

		if ( is_array( $counts ) && isset( $counts[ $campaign_key ] ) ) {
			return (int) $counts[ $campaign_key ];
		}
	}

	// Fallback: a Redirection rule left over from v1.0.0.
	$table = $wpdb->prefix . 'redirection_items';

	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

	if ( $exists !== $table ) {
		return null;
	}

	$hits = $wpdb->get_var(
		$wpdb->prepare( "SELECT hits FROM {$table} WHERE url = %s ORDER BY id DESC LIMIT 1", $short_path ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery

	return null === $hits ? null : (int) $hits;
}

/**
 * Per-campaign results.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function ans_attr_report( $request ) {
	// Measured on LIVE 2026-09-09: this response was being served from cache,
	// so the report returned scans=1 while the database held 9. The counting
	// was correct; the READOUT lied. A stale attribution report is worse than
	// none — it is the number someone decides next year's print budget on.
	nocache_headers();

	$wanted    = sanitize_text_field( (string) $request->get_param( 'campaign' ) );
	$campaigns = ans_attr_campaigns();

	if ( '' !== $wanted && isset( $campaigns[ $wanted ] ) ) {
		$campaigns = array( $wanted => $campaigns[ $wanted ] );
	}

	$out = array();

	foreach ( $campaigns as $key => $campaign ) {
		$redemptions = 0;
		$coupon_id   = function_exists( 'wc_get_coupon_id_by_code' )
			? wc_get_coupon_id_by_code( $campaign['coupon'] )
			: 0;

		if ( $coupon_id ) {
			$coupon      = new WC_Coupon( $coupon_id );
			$redemptions = (int) $coupon->get_usage_count();
		}

		$orders     = array();
		$revenue    = 0.0;
		$by_content = array();

		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'limit'      => -1,
					'status'     => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
					'meta_key'   => '_ans_campaign', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value' => $key,            // phpcs:ignore WordPress.DB.SlowDBQuery
					'return'     => 'objects',
				)
			);

			foreach ( $orders as $order ) {
				$revenue += (float) $order->get_total();

				$content = (string) $order->get_meta( '_ans_content' );
				$content = '' === $content ? '(unknown)' : $content;

				if ( ! isset( $by_content[ $content ] ) ) {
					$by_content[ $content ] = array( 'orders' => 0, 'revenue' => 0.0 );
				}

				$by_content[ $content ]['orders']++;
				$by_content[ $content ]['revenue'] = round( $by_content[ $content ]['revenue'] + (float) $order->get_total(), 2 );
			}
		}

		$pieces = isset( $campaign['pieces'] ) ? (int) $campaign['pieces'] : 0;
		$scans  = ans_attr_scan_count( $campaign['short_path'], $key );

		$row = array(
			'label'              => $campaign['label'],
			'coupon'             => $campaign['coupon'],
			'short_path'         => $campaign['short_path'],
			'qr_url'             => home_url( $campaign['short_path'] ) . ( empty( $campaign['qr_query'] ) ? '' : '?' . $campaign['qr_query'] ),
			'pieces_mailed'      => $pieces,
			'scans'              => $scans,
			'scan_rate_pct'      => ( $pieces > 0 && null !== $scans ) ? round( $scans / $pieces * 100, 2 ) : null,
			'coupon_redemptions' => $redemptions,
			'attributed_orders'  => count( $orders ),
			'attributed_revenue' => round( $revenue, 2 ),
			'orders_by_content'  => $by_content,
			'note'               => 'scans = server-side redirect hits. attributed_* = orders carrying _ans_campaign, independent of GA4.',
		);

		if ( ! empty( $campaign['aliases'] ) ) {
			$row['spoken_urls'] = array_map(
				static function ( $alias ) {
					return home_url( $alias );
				},
				(array) $campaign['aliases']
			);
		}

		if ( isset( $campaign['spots'] ) ) {
			$row['spots']       = (int) $campaign['spots'];
			$row['destination'] = $campaign['destination'];
			$row['utm_content'] = $campaign['utm_content'];
		}

		$out[ $key ] = $row;
	}

	return rest_ensure_response( $out );
}

/**
 * Register the REST routes into the connector's existing namespace.
 *
 * ans-ops/v1 is already reachable by the Ars Nova WordPress MCP connector's
 * ans_rest_call, so these are usable the moment the plugin activates without
 * any connector rebuild.
 *
 * @return void
 */
function ans_attr_rest_routes() {
	$can = static function () {
		return current_user_can( 'manage_woocommerce' );
	};

	register_rest_route(
		'ans-ops/v1',
		'/attribution/report',
		array(
			'methods'             => 'GET',
			'callback'            => 'ans_attr_report',
			'permission_callback' => $can,
			'args'                => array(
				'campaign' => array( 'type' => 'string' ),
			),
		)
	);

	register_rest_route(
		'ans-ops/v1',
		'/attribution/campaigns',
		array(
			'methods'             => 'GET',
			'callback'            => static function () {
				nocache_headers();
				return rest_ensure_response( ans_attr_campaigns() );
			},
			'permission_callback' => $can,
		)
	);

	register_rest_route(
		'ans-ops/v1',
		'/attribution/scans/(?P<key>[a-z0-9_-]+)',
		array(
			'methods'             => 'POST',
			'callback'            => 'ans_attr_set_scans',
			'permission_callback' => $can,
			'args'                => array(
				'set'    => array( 'type' => 'integer' ),
				'adjust' => array( 'type' => 'integer' ),
				'reason' => array( 'type' => 'string' ),
			),
		)
	);

	register_rest_route(
		'ans-ops/v1',
		'/attribution/campaign/(?P<key>[a-z0-9_-]+)',
		array(
			'methods'             => 'POST',
			'callback'            => 'ans_attr_update_campaign',
			'permission_callback' => $can,
			'args'                => array(
				'destination' => array( 'type' => 'string' ),
				'utm_content' => array( 'type' => 'string' ),
			),
		)
	);
}
add_action( 'rest_api_init', 'ans_attr_rest_routes' );

/**
 * Serve the short link ourselves, and set the cookie HERE.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS. Measured on LIVE 2026-09-09, and it would have been a silent
 * total failure in production.
 *
 * v1.0.0 let the Redirection plugin 302 to the concert page and set the cookie
 * when that page loaded. But Kinsta's edge cache serves the concert page as a
 * HIT (`x-kinsta-cache: HIT`) — utm_* parameters do not bust it. On a cache hit
 * PHP never runs, so `setcookie()` never fires. Every patron scanning the
 * postcard would have landed on a perfectly normal-looking page with no cookie,
 * no auto-applied coupon and no order stamp, and nothing anywhere would have
 * reported an error. The bug only showed up because a test request happened to
 * carry a cache-busting parameter.
 *
 * A redirect is uncacheable and always executes PHP, so setting the cookie at
 * the hop instead of at the destination removes the failure mode rather than
 * working around it. The landing page stays cached and fast.
 *
 * Priority 1 so this runs before the Redirection plugin can claim the URL.
 * ---------------------------------------------------------------------------
 *
 * @return void
 */
function ans_attr_handle_short_link() {
	if ( is_admin() || wp_doing_cron() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
		return;
	}

	$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

	if ( '' === $uri ) {
		return;
	}

	// Case-insensitive: the announcer spells the path out ("slash C-P-R"),
	// so listeners type /CPR as often as /cpr.
	$path = strtolower( '/' . trim( rawurldecode( (string) strtok( $uri, '?' ) ), '/' ) );

	foreach ( ans_attr_campaigns() as $key => $campaign ) {
		if ( empty( $campaign['short_path'] ) || empty( $campaign['destination'] ) ) {
			continue;
		}

		$short = strtolower( '/' . trim( $campaign['short_path'], '/' ) );

		// A spoken alias (/cpr) cannot carry a cache-busting param, so it is
		// a plain hop onto the counted short path WITH one. It records
		// nothing itself, so an edge-cached copy of this 302 loses nothing.
		if ( $path !== $short && ! empty( $campaign['aliases'] ) ) {
			foreach ( (array) $campaign['aliases'] as $alias ) {
				if ( $path === strtolower( '/' . trim( (string) $alias, '/' ) ) ) {
					$target = home_url( $short ) . ( empty( $campaign['qr_query'] ) ? '' : '?' . $campaign['qr_query'] );
					wp_safe_redirect( $target, 302 );
					exit;
				}
			}
		}

		if ( $path !== $short ) {
			continue;
		}

		ans_attr_record_scan( $key );
		ans_attr_set_cookie( $key, $campaign['coupon'], $campaign['utm_content'] );

		$destination = add_query_arg(
			array(
				'utm_source'   => isset( $campaign['utm_source'] ) ? $campaign['utm_source'] : 'qr',
				'utm_medium'   => isset( $campaign['utm_medium'] ) ? $campaign['utm_medium'] : 'print',
				'utm_campaign' => isset( $campaign['utm_campaign'] ) ? $campaign['utm_campaign'] : 'confluence-2627',
				'utm_content'  => $campaign['utm_content'],
				'ansref'       => $campaign['coupon'],
			),
			home_url( $campaign['destination'] )
		);

		nocache_headers();
		wp_safe_redirect( $destination, 302 );
		exit;
	}
}
add_action( 'init', 'ans_attr_handle_short_link', 1 );

/**
 * REST: correct a campaign's scan counter.
 *
 * Testing a live short link counts as a scan. Before v1.4.1 the only way to
 * take a test hit back out was SSH into the database, so the counter a mailer
 * or radio budget is judged on quietly carried the tester's clicks. `set`
 * writes an exact value (e.g. 0 before a drop); `adjust` adds or subtracts
 * (e.g. -1 for one test request). Every correction is logged with its reason.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function ans_attr_set_scans( $request ) {
	nocache_headers();

	$key = sanitize_key( (string) $request['key'] );

	if ( ! isset( ans_attr_campaigns()[ $key ] ) ) {
		return new WP_Error( 'ans_attr_unknown_campaign', 'No such campaign.', array( 'status' => 404 ) );
	}

	$set    = $request->get_param( 'set' );
	$adjust = $request->get_param( 'adjust' );

	if ( null === $set && null === $adjust ) {
		return new WP_Error( 'ans_attr_nothing_to_change', 'Pass set or adjust.', array( 'status' => 400 ) );
	}

	$counts = get_option( ANS_ATTR_SCANS_OPTION, array() );

	if ( ! is_array( $counts ) ) {
		$counts = array();
	}

	$before = isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;
	$after  = null !== $set ? (int) $set : $before + (int) $adjust;
	$after  = max( 0, $after );

	$counts[ $key ] = $after;
	update_option( ANS_ATTR_SCANS_OPTION, $counts, false );

	$log   = get_option( 'ans_attr_scan_corrections', array() );
	$log   = is_array( $log ) ? $log : array();
	$log[] = array(
		'at'       => gmdate( 'c' ),
		'campaign' => $key,
		'before'   => $before,
		'after'    => $after,
		'reason'   => sanitize_text_field( (string) $request->get_param( 'reason' ) ),
		'user'     => get_current_user_id(),
	);
	update_option( 'ans_attr_scan_corrections', array_slice( $log, -100 ), false );

	return rest_ensure_response( array( 'campaign' => $key, 'before' => $before, 'after' => $after ) );
}

/**
 * Increment the scan counter for a campaign.
 *
 * @param string $campaign_key Campaign key.
 * @return void
 */
function ans_attr_record_scan( $campaign_key ) {
	$counts = get_option( ANS_ATTR_SCANS_OPTION, array() );

	if ( ! is_array( $counts ) ) {
		$counts = array();
	}

	$counts[ $campaign_key ] = isset( $counts[ $campaign_key ] ) ? (int) $counts[ $campaign_key ] + 1 : 1;

	update_option( ANS_ATTR_SCANS_OPTION, $counts, false );
}

/**
 * Client-side belt-and-braces for the cookie.
 *
 * ---------------------------------------------------------------------------
 * The PHP hop (above) is the primary mechanism and it works — but only on a
 * cache MISS. Measured on LIVE 2026-09-09: when Kinsta's edge serves /go/rs
 * from cache it STRIPS the Set-Cookie header entirely. A bare /go/rs was HIT
 * 3/3; /go/rs with any unrecognised query parameter was BYPASS 3/3, cookie
 * present every time. That is why the printed QR carries `?m=1`.
 *
 * Relying on that alone would make attribution depend on an undocumented host
 * caching rule that could change without notice, and the failure would be
 * silent — which is exactly how v1.0.0 failed. So: the landing URL always
 * carries `ansref`, and this reads it from the URL in the browser. The page
 * HTML can be cached and identical for everyone; `location.search` is not.
 *
 * Belt and braces, deliberately. Either layer alone is sufficient.
 * ---------------------------------------------------------------------------
 *
 * @return void
 */
function ans_attr_fallback_script() {
	if ( is_admin() ) {
		return;
	}

	$map = array();

	foreach ( ans_attr_campaigns() as $key => $campaign ) {
		$map[ strtoupper( $campaign['coupon'] ) ] = $key;
	}

	if ( empty( $map ) ) {
		return;
	}

	$json = wp_json_encode( $map );
	$name = ANS_ATTR_COOKIE;
	$ttl  = (int) ANS_ATTR_TTL;

	?>
<script id="ans-attr-fallback">
(function () {
	try {
		var map = <?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
		var params = new URLSearchParams(window.location.search);
		var ref = (params.get('ansref') || '').toUpperCase();
		if (!ref || !map[ref]) { return; }
		if (document.cookie.indexOf('<?php echo esc_js( $name ); ?>=') !== -1) { return; }
		var data = {
			campaign: map[ref],
			coupon: ref,
			ts: Math.floor(Date.now() / 1000)
		};
		var content = params.get('utm_content');
		if (content) { data.content = content.toLowerCase().replace(/[^a-z0-9_-]/g, ''); }
		var payload = JSON.stringify(data);
		var expires = new Date(Date.now() + <?php echo (int) $ttl; ?> * 1000).toUTCString();
		document.cookie = '<?php echo esc_js( $name ); ?>=' + encodeURIComponent(payload) +
			';expires=' + expires + ';path=/;SameSite=Lax' +
			(window.location.protocol === 'https:' ? ';Secure' : '');
	} catch (e) { /* attribution is never worth breaking a page over */ }
})();
</script>
	<?php
}
add_action( 'wp_footer', 'ans_attr_fallback_script', 99 );
