<?php
/**
 * Plugin Name: PAT GA4 Server-Side Purchase Tracking
 * Description: Sends the GA4 "purchase" event via the Measurement Protocol directly from the server when an order completes, so ecommerce tracking no longer depends on the custom Oxygen/Breakdance checkout's thank-you page JavaScript executing. Supplements (does not replace) the official "Google Analytics for WooCommerce" plugin, which keeps handling page views, add-to-cart, etc.
 * Version: 1.0.0
 * Author: Price Action Tools
 * License: GPL-2.0-or-later
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PAT_GA4_Server_Tracking {

	/** Order meta key: timestamp the purchase event was successfully sent (live). Used for de-duplication. */
	const SENT_META_KEY = '_ga4_purchase_sent';

	/** Order meta key: the GA4 client_id attributed to this order. */
	const CLIENT_ID_META_KEY = '_ga4_client_id';

	/** Order meta key: whether the client_id came from the visitor's _ga cookie or was generated. */
	const CLIENT_ID_SOURCE_META_KEY = '_ga4_client_id_source';

	/** WooCommerce logger source name (see WooCommerce > Status > Logs). */
	const LOG_SOURCE = 'ga4-server-tracking';

	/** Options row used when GA4_MP_API_SECRET isn't defined in wp-config.php. */
	const OPTION_KEY = 'pat_ga4_mp_settings';

	/** Falls back to the property the user gave us; overridable via constant or settings field. */
	const DEFAULT_MEASUREMENT_ID = 'G-R99SS3D8E6';

	/**
	 * Wires up all hooks. Called on plugins_loaded.
	 */
	public static function init() {
		// Capture the visitor's GA4 client_id while we still have their browser request
		// (checkout submission). By the time payment_complete fires it may be a server-to-server
		// gateway webhook (e.g. Stripe) with no cookies at all, so we can't read _ga there.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'capture_client_id_on_checkout' ), 10, 3 );

		// Primary triggers for sending the purchase event. Both are wired; whichever fires
		// first wins and the de-dup meta stops the other from sending it twice.
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'handle_payment_complete' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'handle_status_changed' ), 10, 4 );

		// Settings screen (Settings > GA4 Server Tracking).
		add_action( 'admin_menu', array( __CLASS__, 'register_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );

		// Manual resend: adds an entry to the "Order actions" dropdown on the order edit screen.
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'add_order_action' ) );
		add_action( 'woocommerce_order_action_pat_ga4_resend', array( __CLASS__, 'handle_order_action_resend' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'render_order_status_line' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notices' ) );
	}

	/**
	 * Activation guard: this plugin is useless without WooCommerce.
	 */
	public static function activate() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'PAT GA4 Server-Side Purchase Tracking requires WooCommerce to be installed and active.', 'pat-ga4' ) );
		}
	}

	/* -----------------------------------------------------------------------
	 * Order-completion hooks
	 * ------------------------------------------------------------------- */

	/**
	 * Fires reliably whenever a payment gateway (Stripe, PayPal, etc.) marks
	 * an order as paid, regardless of which checkout template rendered it.
	 *
	 * @param int $order_id
	 */
	public static function handle_payment_complete( $order_id ) {
		self::maybe_send_for_order( $order_id );
	}

	/**
	 * Backstop for orders that reach "processing"/"completed" without ever
	 * firing woocommerce_payment_complete (e.g. manual order status changes,
	 * offline payment methods marked paid by an admin).
	 *
	 * @param int      $order_id
	 * @param string   $status_from
	 * @param string   $status_to
	 * @param WC_Order $order
	 */
	public static function handle_status_changed( $order_id, $status_from, $status_to, $order ) {
		if ( in_array( $status_to, array( 'processing', 'completed' ), true ) ) {
			self::maybe_send_for_order( $order_id );
		}
	}

	/**
	 * De-duplication gate shared by both hooks above.
	 *
	 * @param int $order_id
	 */
	private static function maybe_send_for_order( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( $order->get_meta( self::SENT_META_KEY ) ) {
			return; // Already sent successfully; never double-count.
		}

		self::send_purchase_event( $order, self::is_debug_mode(), false );
	}

	/* -----------------------------------------------------------------------
	 * Sending the event
	 * ------------------------------------------------------------------- */

	/**
	 * Builds and sends the GA4 Measurement Protocol "purchase" event for an order.
	 *
	 * @param WC_Order $order          The order to report.
	 * @param bool     $validate       If true, POST to the /debug/mp/collect validation
	 *                                 endpoint instead of the live endpoint. Validation
	 *                                 responses describe payload problems but are never
	 *                                 recorded in GA4 reports, so the de-dup meta is left
	 *                                 untouched — safe to run repeatedly while testing.
	 * @param bool     $tag_debug_view If true, adds "debug_mode": true to the event so it
	 *                                 also surfaces in GA4 Admin > DebugView. Still a real,
	 *                                 counted send unless $validate is also true.
	 * @return true|array|WP_Error True on a successful live send, an array with the raw
	 *                             response for validation calls, or WP_Error on failure.
	 */
	public static function send_purchase_event( WC_Order $order, $validate = false, $tag_debug_view = false ) {
		$api_secret = self::get_api_secret();

		if ( empty( $api_secret ) ) {
			$message = sprintf(
				'Order #%d: no GA4 Measurement Protocol API secret configured. Add GA4_MP_API_SECRET to wp-config.php or set it on Settings > GA4 Server Tracking.',
				$order->get_id()
			);
			self::log( 'error', $message );
			return new WP_Error( 'ga4_missing_secret', $message );
		}

		$client_id = self::get_or_create_client_id( $order );
		$payload   = self::build_payload( $order, $client_id, $tag_debug_view );

		$endpoint = $validate
			? 'https://www.google-analytics.com/debug/mp/collect'
			: 'https://www.google-analytics.com/mp/collect';

		$url = add_query_arg(
			array(
				'measurement_id' => self::get_measurement_id(),
				'api_secret'     => $api_secret,
			),
			$endpoint
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::log(
				'error',
				sprintf( 'Order #%d: HTTP request to GA4 failed - %s', $order->get_id(), $response->get_error_message() ),
				array( 'payload' => $payload )
			);
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $validate ) {
			// The validation endpoint always returns HTTP 200 with a JSON body listing
			// validationMessages (an empty array means the payload is well-formed).
			self::log(
				'info',
				sprintf( 'Order #%d: validation response (HTTP %d): %s', $order->get_id(), $code, $body ),
				array( 'payload' => $payload )
			);
			return array( 'code' => $code, 'body' => $body );
		}

		if ( $code < 200 || $code >= 300 ) {
			self::log(
				'error',
				sprintf( 'Order #%d: GA4 returned HTTP %d - %s', $order->get_id(), $code, $body ),
				array( 'payload' => $payload )
			);
			return new WP_Error( 'ga4_bad_response', sprintf( 'GA4 returned HTTP %d', $code ) );
		}

		// The live /mp/collect endpoint always returns 204/200 with no body, even for a
		// malformed payload, so a 2xx here only confirms Google accepted the request -
		// use $validate=true against /debug/mp/collect if you need to confirm the payload
		// itself is well-formed.
		$order->update_meta_data( self::SENT_META_KEY, current_time( 'mysql' ) );
		$order->save();

		self::log(
			'info',
			sprintf(
				'Order #%d: purchase event sent (client_id %s, value %s %s).',
				$order->get_id(),
				$client_id,
				$order->get_total(),
				$order->get_currency()
			)
		);

		return true;
	}

	/**
	 * Builds the Measurement Protocol JSON payload for an order.
	 *
	 * @param WC_Order $order
	 * @param string   $client_id
	 * @param bool     $tag_debug_view
	 * @return array
	 */
	private static function build_payload( WC_Order $order, $client_id, $tag_debug_view = false ) {
		$items = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			$sku     = $product ? $product->get_sku() : '';
			$qty     = max( 1, (int) $item->get_quantity() );

			$items[] = array(
				'item_id'   => $sku ? $sku : (string) $item->get_product_id(),
				'item_name' => $item->get_name(),
				'price'     => round( (float) $item->get_total() / $qty, 2 ),
				'quantity'  => $qty,
			);
		}

		$params = array(
			'transaction_id' => (string) $order->get_id(),
			'value'          => (float) $order->get_total(),
			'currency'       => $order->get_currency(),
			'items'          => $items,
		);

		if ( $tag_debug_view ) {
			$params['debug_mode'] = true;
		}

		return array(
			'client_id' => $client_id,
			'events'    => array(
				array(
					'name'   => 'purchase',
					'params' => $params,
				),
			),
		);
	}

	/* -----------------------------------------------------------------------
	 * client_id handling
	 * ------------------------------------------------------------------- */

	/**
	 * Captures the visitor's _ga cookie onto the order at the moment checkout
	 * is submitted, i.e. while we still have their actual browser request.
	 *
	 * @param int      $order_id
	 * @param array    $posted_data
	 * @param WC_Order $order
	 */
	public static function capture_client_id_on_checkout( $order_id, $posted_data, $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order instanceof WC_Order || $order->get_meta( self::CLIENT_ID_META_KEY ) ) {
			return;
		}

		list( $client_id, $source ) = self::resolve_client_id();

		$order->update_meta_data( self::CLIENT_ID_META_KEY, $client_id );
		$order->update_meta_data( self::CLIENT_ID_SOURCE_META_KEY, $source );
		$order->save();
	}

	/**
	 * Returns the order's stored client_id, generating and persisting a
	 * fallback if checkout capture never ran (e.g. order created via admin/API).
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	private static function get_or_create_client_id( WC_Order $order ) {
		$client_id = $order->get_meta( self::CLIENT_ID_META_KEY );

		if ( $client_id ) {
			return $client_id;
		}

		list( $client_id, $source ) = self::resolve_client_id();

		$order->update_meta_data( self::CLIENT_ID_META_KEY, $client_id );
		$order->update_meta_data( self::CLIENT_ID_SOURCE_META_KEY, $source );
		$order->save();

		if ( 'fallback' === $source ) {
			self::log(
				'warning',
				sprintf(
					'Order #%d: no _ga cookie available; generated a fallback client_id. Revenue will still record but won\'t attribute to the original session/channel.',
					$order->get_id()
				)
			);
		}

		return $client_id;
	}

	/**
	 * Reads client_id from the _ga cookie (format GA1.2.XXXXXXXXXX.YYYYYYYYYY -
	 * client_id is the last two dot-separated segments), or generates a random
	 * fallback if the cookie isn't present.
	 *
	 * @return array{0: string, 1: string} [client_id, source] where source is 'cookie'|'fallback'.
	 */
	private static function resolve_client_id() {
		if ( ! empty( $_COOKIE['_ga'] ) ) {
			$cookie = sanitize_text_field( wp_unslash( $_COOKIE['_ga'] ) );
			$parts  = explode( '.', $cookie );

			if ( count( $parts ) >= 4 ) {
				return array( $parts[ count( $parts ) - 2 ] . '.' . $parts[ count( $parts ) - 1 ], 'cookie' );
			}
		}

		return array( self::generate_fallback_client_id(), 'fallback' );
	}

	/**
	 * @return string A syntactically valid but made-up GA4 client_id.
	 */
	private static function generate_fallback_client_id() {
		return sprintf( '%d.%d', wp_rand( 1000000000, 2147483647 ), time() );
	}

	/* -----------------------------------------------------------------------
	 * Configuration
	 * ------------------------------------------------------------------- */

	/**
	 * @return string
	 */
	public static function get_measurement_id() {
		if ( defined( 'GA4_MP_MEASUREMENT_ID' ) && GA4_MP_MEASUREMENT_ID ) {
			return GA4_MP_MEASUREMENT_ID;
		}

		$opts = get_option( self::OPTION_KEY, array() );

		return ! empty( $opts['measurement_id'] ) ? $opts['measurement_id'] : self::DEFAULT_MEASUREMENT_ID;
	}

	/**
	 * @return string Empty string if not configured anywhere.
	 */
	public static function get_api_secret() {
		if ( defined( 'GA4_MP_API_SECRET' ) && GA4_MP_API_SECRET ) {
			return GA4_MP_API_SECRET;
		}

		$opts = get_option( self::OPTION_KEY, array() );

		return ! empty( $opts['api_secret'] ) ? $opts['api_secret'] : '';
	}

	/**
	 * Global safety switch: when on, automatic (hook-triggered) sends go to the
	 * validation endpoint instead of live GA4. Intended for staging sites.
	 *
	 * @return bool
	 */
	public static function is_debug_mode() {
		if ( defined( 'GA4_MP_DEBUG_MODE' ) ) {
			return (bool) GA4_MP_DEBUG_MODE;
		}

		$opts = get_option( self::OPTION_KEY, array() );

		return ! empty( $opts['debug_mode'] );
	}

	/* -----------------------------------------------------------------------
	 * Settings page (Settings > GA4 Server Tracking)
	 * ------------------------------------------------------------------- */

	public static function register_settings_page() {
		add_options_page(
			__( 'GA4 Server Tracking', 'pat-ga4' ),
			__( 'GA4 Server Tracking', 'pat-ga4' ),
			'manage_woocommerce',
			'pat-ga4-server-tracking',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		register_setting( 'pat_ga4_mp_settings_group', self::OPTION_KEY, array( __CLASS__, 'sanitize_settings' ) );
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public static function sanitize_settings( $input ) {
		return array(
			'measurement_id' => isset( $input['measurement_id'] ) ? sanitize_text_field( $input['measurement_id'] ) : '',
			'api_secret'     => isset( $input['api_secret'] ) ? sanitize_text_field( $input['api_secret'] ) : '',
			'debug_mode'     => ! empty( $input['debug_mode'] ),
		);
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$opts             = get_option( self::OPTION_KEY, array() );
		$secret_is_constant = defined( 'GA4_MP_API_SECRET' ) && GA4_MP_API_SECRET;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GA4 Server Tracking', 'pat-ga4' ); ?></h1>
			<p><?php esc_html_e( 'Sends the GA4 "purchase" event via the Measurement Protocol directly from the server, so tracking no longer depends on the thank-you page\'s JavaScript executing.', 'pat-ga4' ); ?></p>

			<h2><?php esc_html_e( 'Current status', 'pat-ga4' ); ?></h2>
			<table class="widefat" style="max-width:720px;">
				<tbody>
					<tr>
						<td style="width:220px;"><strong><?php esc_html_e( 'Measurement ID', 'pat-ga4' ); ?></strong></td>
						<td><code><?php echo esc_html( self::get_measurement_id() ); ?></code></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'API secret', 'pat-ga4' ); ?></strong></td>
						<td>
							<?php if ( $secret_is_constant ) : ?>
								<?php esc_html_e( 'Configured via the GA4_MP_API_SECRET constant in wp-config.php.', 'pat-ga4' ); ?>
							<?php elseif ( self::get_api_secret() ) : ?>
								<?php esc_html_e( 'Configured via the option field below.', 'pat-ga4' ); ?>
							<?php else : ?>
								<span style="color:#b32d2e;"><?php esc_html_e( 'Not configured yet - purchase events cannot be sent until this is set.', 'pat-ga4' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Debug mode', 'pat-ga4' ); ?></strong></td>
						<td>
							<?php if ( self::is_debug_mode() ) : ?>
								<?php esc_html_e( 'ON - automatic sends go to /debug/mp/collect and are NOT recorded in GA4.', 'pat-ga4' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'OFF - automatic sends go to the live endpoint.', 'pat-ga4' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<form method="post" action="options.php">
				<?php settings_fields( 'pat_ga4_mp_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pat_ga4_measurement_id"><?php esc_html_e( 'Measurement ID override', 'pat-ga4' ); ?></label></th>
						<td>
							<input type="text" id="pat_ga4_measurement_id" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[measurement_id]" value="<?php echo esc_attr( $opts['measurement_id'] ?? '' ); ?>" class="regular-text" placeholder="<?php echo esc_attr( self::DEFAULT_MEASUREMENT_ID ); ?>" />
							<p class="description"><?php echo esc_html( sprintf( 'Leave blank to use the default (%s).', self::DEFAULT_MEASUREMENT_ID ) ); ?></p>
						</td>
					</tr>
					<?php if ( ! $secret_is_constant ) : ?>
					<tr>
						<th scope="row"><label for="pat_ga4_api_secret"><?php esc_html_e( 'API secret', 'pat-ga4' ); ?></label></th>
						<td>
							<input type="password" id="pat_ga4_api_secret" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_secret]" value="<?php echo esc_attr( $opts['api_secret'] ?? '' ); ?>" class="regular-text" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'From GA4 Admin > Data Streams > your stream > Measurement Protocol API secrets. Prefer defining GA4_MP_API_SECRET in wp-config.php instead of storing it here.', 'pat-ga4' ); ?></p>
						</td>
					</tr>
					<?php else : ?>
						<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_secret]" value="<?php echo esc_attr( $opts['api_secret'] ?? '' ); ?>" />
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Debug mode', 'pat-ga4' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[debug_mode]" value="1" <?php checked( ! empty( $opts['debug_mode'] ) ); ?> />
								<?php esc_html_e( 'Send automatic purchase events to the /debug/mp/collect validation endpoint instead of live GA4 (nothing is recorded; use only on staging).', 'pat-ga4' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* -----------------------------------------------------------------------
	 * Manual resend (order edit screen)
	 * ------------------------------------------------------------------- */

	/**
	 * @param array $actions
	 * @return array
	 */
	public static function add_order_action( $actions ) {
		$actions['pat_ga4_resend'] = __( 'Resend GA4 purchase event (Measurement Protocol)', 'pat-ga4' );
		return $actions;
	}

	/**
	 * Handles the custom "Order actions" dropdown entry. Always sends a real,
	 * counted event (tagged for GA4 DebugView) - this is a deliberate resend of
	 * a real order, not a dry run. Use WP-CLI's --validate flag for a dry run.
	 *
	 * @param WC_Order $order
	 */
	public static function handle_order_action_resend( $order ) {
		$result  = self::send_purchase_event( $order, false, true );
		$user_id = get_current_user_id();

		if ( is_wp_error( $result ) ) {
			set_transient(
				'pat_ga4_notice_' . $user_id,
				array(
					'type'    => 'error',
					'message' => sprintf( 'GA4 purchase event for order #%d failed: %s', $order->get_id(), $result->get_error_message() ),
				),
				60
			);
		} else {
			set_transient(
				'pat_ga4_notice_' . $user_id,
				array(
					'type'    => 'success',
					'message' => sprintf( 'GA4 purchase event sent for order #%d. Check GA4 Admin > DebugView (filter to this device) within the next few minutes.', $order->get_id() ),
				),
				60
			);
		}
	}

	public static function render_admin_notices() {
		$user_id = get_current_user_id();
		$notice  = get_transient( 'pat_ga4_notice_' . $user_id );

		if ( ! $notice ) {
			return;
		}

		delete_transient( 'pat_ga4_notice_' . $user_id );

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			'error' === $notice['type'] ? 'error' : 'success',
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Shows current send status in the order edit screen sidebar.
	 *
	 * @param WC_Order $order
	 */
	public static function render_order_status_line( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$sent_at = $order->get_meta( self::SENT_META_KEY );

		echo '<p class="form-field form-field-wide">';
		echo '<strong>' . esc_html__( 'GA4 purchase event:', 'pat-ga4' ) . '</strong> ';

		if ( $sent_at ) {
			echo esc_html( sprintf( __( 'Sent %s', 'pat-ga4' ), $sent_at ) );
		} else {
			esc_html_e( 'Not sent yet. Use "Resend GA4 purchase event" under Order actions to send it now.', 'pat-ga4' );
		}

		echo '</p>';
	}

	/* -----------------------------------------------------------------------
	 * Logging
	 * ------------------------------------------------------------------- */

	/**
	 * @param string $level   emergency|alert|critical|error|warning|notice|info|debug
	 * @param string $message
	 * @param array  $context
	 */
	private static function log( $level, $message, $context = array() ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array_merge( array( 'source' => self::LOG_SOURCE ), $context ) );
		}
	}
}

register_activation_hook( __FILE__, array( 'PAT_GA4_Server_Tracking', 'activate' ) );
add_action( 'plugins_loaded', array( 'PAT_GA4_Server_Tracking', 'init' ) );

// Declare High-Performance Order Storage (HPOS) compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/* -----------------------------------------------------------------------
 * WP-CLI: wp pat-ga4 resend <order_id> [--validate] [--debug-view]
 * ------------------------------------------------------------------- */

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Manages PAT GA4 server-side purchase tracking.
	 */
	class PAT_GA4_Server_Tracking_CLI {

		/**
		 * (Re)sends the GA4 purchase event for a WooCommerce order.
		 *
		 * ## OPTIONS
		 *
		 * <order_id>
		 * : The WooCommerce order ID.
		 *
		 * [--validate]
		 * : Send to the /debug/mp/collect validation endpoint instead of live GA4.
		 * Returns payload validation messages; does not affect GA4 reports or the
		 * de-dup flag, so it's safe to run repeatedly.
		 *
		 * [--debug-view]
		 * : Tag the live event with debug_mode so it also appears in GA4 DebugView.
		 * Ignored when --validate is used.
		 *
		 * ## EXAMPLES
		 *
		 *     wp pat-ga4 resend 1234
		 *     wp pat-ga4 resend 1234 --validate
		 *     wp pat-ga4 resend 1234 --debug-view
		 *
		 * @when after_wp_load
		 *
		 * @param array $args
		 * @param array $assoc_args
		 */
		public function resend( $args, $assoc_args ) {
			list( $order_id ) = $args;
			$order = wc_get_order( (int) $order_id );

			if ( ! $order ) {
				WP_CLI::error( "Order #{$order_id} not found." );
			}

			$validate   = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'validate', false );
			$debug_view = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'debug-view', false );

			$result = PAT_GA4_Server_Tracking::send_purchase_event( $order, $validate, $debug_view );

			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			if ( $validate ) {
				WP_CLI::success( "Validation response received for order #{$order_id}. See the ga4-server-tracking log (WooCommerce > Status > Logs) for validationMessages." );
			} else {
				WP_CLI::success( "Purchase event sent for order #{$order_id}." );
			}
		}
	}

	WP_CLI::add_command( 'pat-ga4 resend', array( 'PAT_GA4_Server_Tracking_CLI', 'resend' ) );
}
