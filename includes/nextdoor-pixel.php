<?php
/**
 * Nextdoor Pixel (Universal Pixel) for Ars Nova ads.
 *
 * Three events, all standard Nextdoor pixel event names:
 *   PAGE_VIEW   - every front-end page, from the base code in <head>.
 *   ADD_TO_CART - on the first page rendered after a ticket is added to the
 *                 cart (classic form post, AJAX or Store API alike).
 *   PURCHASE    - on the WooCommerce order-received page, once per order.
 *
 * Privacy: no advanced matching. Nothing about the buyer (name, email, phone,
 * address) is ever passed to ndp(). PURCHASE carries only the order number,
 * its total and its currency.
 *
 * Logged-in users, administrators included, get the pixel too, on purpose:
 * Nextdoor's Pixel Helper / testing tool can only verify the install from a
 * real browser session, and admin traffic is negligible. Filter
 * `ans_attr_nextdoor_enabled` to change that.
 *
 * The pixel ID is a filterable default, so changing Data Source never needs a
 * release: add_filter( 'ans_attr_nextdoor_pixel_id', ... ). Returning an empty
 * string disables every bit of output.
 *
 * @package ans-attribution
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ANS_ATTR_NEXTDOOR_PIXEL_ID', '6fb37353-eeee-40bd-a72a-2a283aef6719' );
define( 'ANS_ATTR_ND_SENT_META', '_ans_nd_purchase_sent' );
define( 'ANS_ATTR_ND_ATC_SESSION', 'ans_nd_add_to_cart' );

/**
 * The active Nextdoor Data Source (pixel) ID, or '' when disabled.
 *
 * @return string
 */
function ans_attr_nextdoor_pixel_id() {
	$id = (string) apply_filters( 'ans_attr_nextdoor_pixel_id', ANS_ATTR_NEXTDOOR_PIXEL_ID );
	$id = strtolower( trim( $id ) );

	// A Nextdoor Data Source ID is a UUID. Anything else is refused rather than
	// echoed into a script tag.
	if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id ) ) {
		return '';
	}

	return $id;
}

/**
 * Should the pixel print on this request at all?
 *
 * @return bool
 */
function ans_attr_nextdoor_should_output() {
	if ( '' === ans_attr_nextdoor_pixel_id() ) {
		return false;
	}

	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_embed() ) {
		return false;
	}

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return false;
	}

	if ( is_customize_preview() ) {
		return false;
	}

	return (bool) apply_filters( 'ans_attr_nextdoor_enabled', true );
}

/**
 * Base code: Nextdoor's official snippet, in <head>, firing PAGE_VIEW.
 *
 * Kept byte-for-byte equivalent to the Ads Manager snippet apart from the ID
 * being injected, so it can be diffed against Nextdoor's copy.
 *
 * @return void
 */
function ans_attr_nextdoor_base_code() {
	if ( ! ans_attr_nextdoor_should_output() ) {
		return;
	}

	$id = ans_attr_nextdoor_pixel_id();
	?>
<!-- Nextdoor Pixel Code (ans-attribution) -->
<script type='text/javascript'>
!function(e,n){var t,p;e.ndp||((t=e.ndp=function(){
t.handleRequest?t.handleRequest.apply(t,arguments):t.queue.push(arguments)
}).queue=[],t.v=1,(p=n.createElement(e="script")).async=!0,
p.src="https://ads.nextdoor.com/public/pixel/ndp.js?id=<?php echo esc_js( $id ); ?>",
(n=n.getElementsByTagName(e)[0]).parentNode.insertBefore(p,n))
}(window,document);
ndp('init','<?php echo esc_js( $id ); ?>', {});
ndp('track','PAGE_VIEW');
</script>
<noscript>
<img height="1" width="1" style="display:none" alt="" src="<?php echo esc_url( 'https://flask.nextdoor.com/pixel?pid=' . $id . '&ev=PAGE_VIEW&noscript=1' ); ?>"/>
</noscript>
<!-- End Nextdoor Pixel Code -->
	<?php
}
add_action( 'wp_head', 'ans_attr_nextdoor_base_code', 5 );

/**
 * Remember that something was added to the cart this session.
 *
 * Fires for a classic add-to-cart form post, WooCommerce's AJAX add and the
 * Store API alike, so one mechanism covers every path the Tickera ticket table
 * can use. The event itself is printed on the next full page render, which is
 * never edge-cached: Kinsta bypasses its cache once the cart cookie exists.
 *
 * @return void
 */
function ans_attr_nextdoor_flag_add_to_cart() {
	if ( '' === ans_attr_nextdoor_pixel_id() ) {
		return;
	}

	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}

	WC()->session->set( ANS_ATTR_ND_ATC_SESSION, 1 );
}
add_action( 'woocommerce_add_to_cart', 'ans_attr_nextdoor_flag_add_to_cart', 10, 0 );

/**
 * The order on this order-received request, if the key in the URL matches it.
 *
 * The key check is what stops /checkout/order-received/123/ from reporting
 * somebody else's order as a conversion.
 *
 * @return WC_Order|null
 */
function ans_attr_nextdoor_received_order() {
	if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-received' ) ) {
		return null;
	}

	$order_id = absint( get_query_var( 'order-received' ) );
	$key      = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( $order_id <= 0 || '' === $key ) {
		return null;
	}

	$order = wc_get_order( $order_id );

	if ( ! $order instanceof WC_Order || ! hash_equals( (string) $order->get_order_key(), (string) $key ) ) {
		return null;
	}

	return $order;
}

/**
 * Print the conversion events at the foot of the page.
 *
 * Runs after the base code in <head> has defined ndp(), so the calls queue
 * normally even before ndp.js has loaded.
 *
 * @return void
 */
function ans_attr_nextdoor_events() {
	if ( ! ans_attr_nextdoor_should_output() ) {
		return;
	}

	$calls = array();

	// PURCHASE, once per order. The meta flag is what keeps a reload, a
	// bookmark or a back-button visit from counting the same sale twice;
	// event_id lets Nextdoor de-duplicate if the browser fires it again anyway.
	$order = ans_attr_nextdoor_received_order();

	if ( $order && ! $order->has_status( array( 'failed', 'cancelled', 'refunded' ) ) && ! $order->get_meta( ANS_ATTR_ND_SENT_META ) ) {
		$data = array(
			'order_id'    => (string) $order->get_order_number(),
			'order_value' => wc_format_decimal( $order->get_total(), 2 ),
			'currency'    => $order->get_currency() ? $order->get_currency() : 'USD',
			'event_id'    => 'ans-order-' . $order->get_id(),
		);

		$calls[] = "ndp('track','PURCHASE'," . wp_json_encode( $data ) . ');';

		$order->update_meta_data( ANS_ATTR_ND_SENT_META, gmdate( 'c' ) );
		$order->save_meta_data();

		// A purchase supersedes any pending add-to-cart from this session.
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( ANS_ATTR_ND_ATC_SESSION, null );
		}
	} elseif ( function_exists( 'WC' ) && WC()->session && WC()->session->get( ANS_ATTR_ND_ATC_SESSION ) ) {
		$calls[] = "ndp('track','ADD_TO_CART');";
		WC()->session->set( ANS_ATTR_ND_ATC_SESSION, null );
	}

	if ( empty( $calls ) ) {
		return;
	}

	echo "<script id=\"ans-nd-events\">\ntry { if (window.ndp) { " . implode( ' ', $calls ) . " } } catch (e) {}\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from wp_json_encode() of sanitized scalars.
}
add_action( 'wp_footer', 'ans_attr_nextdoor_events', 20 );
