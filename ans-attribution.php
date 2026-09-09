<?php
/**
 * Plugin Name: Ars Nova Attribution
 * Plugin URI:  https://github.com/ArsNovaSingers/ans-attribution
 * Description: Campaign attribution for print mailers. Captures a campaign ref off the landing URL, auto-applies that campaign's coupon, refuses to stack it on a Flex Pass / Season Package, and stamps every resulting order so the mailer's return is answerable years later without depending on GA4.
 * Version:     1.0.0
 * Author:      Ars Nova (Jonathan Raabe) + Claude
 * Requires PHP: 7.4
 * Text Domain: ans-attribution
 *
 * @package ans-attribution
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ANS_ATTR_VERSION', '1.0.0' );
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
			'utm_content' => 'rivers-streams-mailer-2026',
			'pieces'      => 650,
			'mail_house'  => 'Allegra',
		),
	);

	return (array) apply_filters( 'ans_attr_campaigns', $campaigns );
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

	$payload = wp_json_encode(
		array(
			'campaign' => $campaign['key'],
			'coupon'   => $campaign['coupon'],
			'ts'       => time(),
		)
	);

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
add_action( 'init', 'ans_attr_capture', 5 );

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
function ans_attr_scan_count( $short_path ) {
	global $wpdb;

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

		$orders  = array();
		$revenue = 0.0;

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
			}
		}

		$pieces = isset( $campaign['pieces'] ) ? (int) $campaign['pieces'] : 0;
		$scans  = ans_attr_scan_count( $campaign['short_path'] );

		$out[ $key ] = array(
			'label'              => $campaign['label'],
			'coupon'             => $campaign['coupon'],
			'short_path'         => $campaign['short_path'],
			'pieces_mailed'      => $pieces,
			'scans'              => $scans,
			'scan_rate_pct'      => ( $pieces > 0 && null !== $scans ) ? round( $scans / $pieces * 100, 2 ) : null,
			'coupon_redemptions' => $redemptions,
			'attributed_orders'  => count( $orders ),
			'attributed_revenue' => round( $revenue, 2 ),
			'note'               => 'scans = server-side redirect hits. attributed_* = orders carrying _ans_campaign, independent of GA4.',
		);
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
				return rest_ensure_response( ans_attr_campaigns() );
			},
			'permission_callback' => $can,
		)
	);
}
add_action( 'rest_api_init', 'ans_attr_rest_routes' );
