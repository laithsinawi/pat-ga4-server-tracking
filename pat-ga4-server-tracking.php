<?php
/**
 * Plugin Name: PAT GA4 Server-Side Purchase Tracking
 * Description: Sends the GA4 "purchase" event via the Measurement Protocol directly from the server when an order completes, so ecommerce tracking no longer depends on the custom Oxygen/Breakdance checkout's thank-you page JavaScript executing. This is the sole purchase tracker (the official "Google Analytics for WooCommerce" plugin's "Purchase Transactions" setting is disabled to avoid double-counting) - it still handles page views, add-to-cart, add_shipping_info/add_payment_info, etc., all of which already work correctly on this site's classic checkout shortcode.
 * Version: 1.2.2
 * Author: Price Action Tools
 * License: GPL-2.0-or-later
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PAT_GA4_Server_Tracking {

	const VERSION = '1.2.2';

	/**
	 * Hosts allowed to send to the live /mp/collect endpoint. Anywhere else (Local,
	 * staging, any clone of prod) is forced onto the validation endpoint, which
	 * records nothing - so a cloned DB carrying the real secret can't pollute GA4.
	 */
	const PRODUCTION_HOSTS = array( 'priceactiontools.com', 'www.priceactiontools.com' );

	/** Order meta key: timestamp the purchase event was successfully sent (live). Used for de-duplication. */
	const SENT_META_KEY = '_ga4_purchase_sent';

	/** Order meta key: why automatic sending was skipped for this order (internal/test order, nothing to report). */
	const SKIPPED_META_KEY = '_ga4_purchase_skipped';

	/** Order meta key: set to "yes" to mark an order as a test order that must never be reported. */
	const TEST_ORDER_META_KEY = '_pat_ga4_test_order';

	/** Line item meta set by pat-free-bonus on its auto-added $0 bonus lines. */
	const FREE_BONUS_ITEM_META = '_pat_free_bonus_item';

	/** Roles whose own orders and browsing count as internal traffic. */
	const INTERNAL_ROLES = array( 'administrator', 'shop_manager' );

	/** Order meta key: the GA4 client_id attributed to this order. */
	const CLIENT_ID_META_KEY = '_ga4_client_id';

	/** Order meta key: whether the client_id came from the visitor's _ga cookie or was generated. */
	const CLIENT_ID_SOURCE_META_KEY = '_ga4_client_id_source';

	/** Order meta key: the GA4 session_id attributed to this order (empty string if unavailable at capture time). */
	const SESSION_ID_META_KEY = '_ga4_session_id';

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

		// Browser side: stop the official "Google Analytics for WooCommerce" plugin's gtag
		// from reporting non-production hosts and internal users into the live property.
		// (That plugin already skips users with manage_options; this adds shop managers,
		// internal emails, and every non-production host.)
		add_filter( 'woocommerce_ga_disable_tracking', array( __CLASS__, 'filter_disable_browser_tracking' ) );

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

		if ( $order->get_meta( self::SENT_META_KEY ) || $order->get_meta( self::SKIPPED_META_KEY ) ) {
			return; // Already sent (or deliberately skipped); never double-count.
		}

		$internal_reason = self::get_internal_order_reason( $order );

		if ( '' !== $internal_reason ) {
			$order->update_meta_data( self::SKIPPED_META_KEY, $internal_reason );
			$order->save();
			self::log( 'info', sprintf( 'Order #%d: not sent to GA4 - internal/test order (%s).', $order->get_id(), $internal_reason ) );
			return;
		}

		self::send_purchase_event( $order, self::is_debug_mode(), false );
	}

	/**
	 * Explains why an order counts as internal/test traffic, or '' if it's a real customer order.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	public static function get_internal_order_reason( WC_Order $order ) {
		if ( 'yes' === $order->get_meta( self::TEST_ORDER_META_KEY ) ) {
			return 'flagged as test order';
		}

		if ( ! self::is_internal_exclusion_enabled() ) {
			return '';
		}

		$user = $order->get_user();

		if ( $user ) {
			$roles = array_intersect( self::INTERNAL_ROLES, (array) $user->roles );

			if ( $roles ) {
				return 'customer has role ' . implode( ', ', $roles );
			}
		}

		$email = strtolower( (string) $order->get_billing_email() );

		if ( self::is_internal_email( $email ) ) {
			return 'billing email is on the internal list';
		}

		return '';
	}

	/**
	 * Browser gtag kill-switch for non-production hosts and internal users.
	 *
	 * @param bool $disabled
	 * @return bool
	 */
	public static function filter_disable_browser_tracking( $disabled ) {
		if ( $disabled || ! self::is_production_site() ) {
			return true;
		}

		if ( ! self::is_internal_exclusion_enabled() || ! is_user_logged_in() ) {
			return false;
		}

		$user = wp_get_current_user();

		return (bool) array_intersect( self::INTERNAL_ROLES, (array) $user->roles )
			|| self::is_internal_email( $user->user_email );
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

		// Hard guard: only the production host may ever reach the live endpoint.
		$forced_validate = ! $validate && ! self::is_production_site();

		if ( $forced_validate ) {
			$validate = true;
		}

		$events = self::build_events( $order, self::get_session_id( $order ), $tag_debug_view );

		if ( empty( $events ) ) {
			// Nothing reportable (e.g. only $0 free-bonus lines). Remember that so the
			// status-change backstop doesn't re-evaluate it on every transition.
			if ( ! $validate ) {
				$order->update_meta_data( self::SKIPPED_META_KEY, 'no reportable items' );
				$order->save();
			}
			self::log( 'info', sprintf( 'Order #%d: no reportable items; nothing sent to GA4.', $order->get_id() ) );
			return new WP_Error( 'ga4_nothing_to_send', 'Order has no paid or trial items to report.' );
		}

		$client_id = self::get_or_create_client_id( $order );
		$payload   = array(
			'client_id' => $client_id,
			'events'    => $events,
		);

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
				sprintf(
					'Order #%d: %svalidation response (HTTP %d): %s',
					$order->get_id(),
					$forced_validate ? 'non-production host, so sent to the validation endpoint only (nothing recorded in GA4) - ' : '',
					$code,
					$body
				),
				array( 'payload' => $payload )
			);
			return array( 'code' => $code, 'body' => $body, 'payload' => $payload );
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
				'Order #%d: sent %s (client_id %s, session_id %s, order total %s %s).',
				$order->get_id(),
				implode( ', ', wp_list_pluck( $events, 'name' ) ),
				$client_id,
				self::get_session_id( $order ) ? self::get_session_id( $order ) : '(none)',
				$order->get_total(),
				$order->get_currency()
			),
			array( 'payload' => $payload )
		);

		return true;
	}

	/**
	 * Builds the Measurement Protocol events for an order:
	 *  - purchase:        paid line items only (trial and $0 free-bonus lines excluded), value = order total.
	 *  - start_trial:     "- Free Trial" line items, value 0. Sent instead of purchase for trial-only orders
	 *                     so $0 trials stop inflating purchase counts/conversion rate.
	 *  - trial_converted: one per paid item whose product this customer previously trialled.
	 *
	 * item_id follows the official GA for WooCommerce plugin's "ga_product_identifier" setting
	 * (product ID on this site) so browser-side view_item/add_to_cart and these server-side
	 * events join on the same item in GA4 reports.
	 *
	 * @param WC_Order $order
	 * @param string   $session_id
	 * @param bool     $tag_debug_view
	 * @return array[] Empty if there is nothing reportable.
	 */
	private static function build_events( WC_Order $order, $session_id, $tag_debug_view = false ) {
		$paid   = array();
		$trials = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product || self::is_free_bonus_item( $item ) ) {
				continue;
			}

			if ( self::is_trial_item( $item ) ) {
				// Customers sometimes re-checkout a trial as a guest instead of logging in to
				// re-download it; only their first trial of a product counts as a start_trial.
				if ( ! self::is_repeat_trial( $order, $item ) ) {
					$trials[] = $item;
				}
			} else {
				$paid[] = $item;
			}
		}

		$common = array( 'currency' => $order->get_currency() );

		if ( $session_id ) {
			// Ties this Measurement Protocol hit to the visitor's actual browser session.
			// Without session_id, GA4 has no session to attach source/medium to and the
			// purchase revenue lands under "Unassigned" / "(not set)" landing page even
			// though client_id is valid - this was the root cause of that symptom.
			$common['session_id']           = $session_id;
			$common['engagement_time_msec'] = 1;
		}

		if ( $tag_debug_view ) {
			$common['debug_mode'] = true;
		}

		$events = array();

		if ( $paid ) {
			$events[] = array(
				'name'   => 'purchase',
				'params' => array_merge(
					$common,
					array(
						'transaction_id' => (string) $order->get_id(),
						'value'          => (float) $order->get_total(),
						'items'          => array_map( array( __CLASS__, 'build_item' ), $paid ),
					)
				),
			);
		}

		if ( $trials ) {
			$events[] = array(
				'name'   => 'start_trial',
				'params' => array_merge(
					$common,
					array(
						'transaction_id' => (string) $order->get_id(),
						'value'          => 0,
						'trial_product'  => implode( ', ', array_map( array( __CLASS__, 'get_trial_base_name' ), $trials ) ),
						'items'          => array_map( array( __CLASS__, 'build_item' ), $trials ),
					)
				),
			);
		}

		foreach ( $paid as $item ) {
			$trial = self::find_prior_trial( $order, $item );

			if ( ! $trial ) {
				continue;
			}

			$built    = self::build_item( $item );
			$events[] = array(
				'name'   => 'trial_converted',
				'params' => array_merge(
					$common,
					array(
						'transaction_id'  => (string) $order->get_id(),
						'value'           => $built['price'] * $built['quantity'],
						'trial_product'   => $trial['base_name'],
						'trial_order_id'  => (string) $trial['order_id'],
						'days_to_convert' => $trial['days'],
						'items'           => array( $built ),
					)
				),
			);
		}

		return $events;
	}

	/**
	 * @param WC_Order_Item_Product $item
	 * @return array GA4 item.
	 */
	private static function build_item( WC_Order_Item_Product $item ) {
		$product    = $item->get_product();
		$product_id = (int) $item->get_product_id(); // Parent ID for variations, matching the browser plugin.
		$item_id    = (string) $product_id;
		$settings   = get_option( 'woocommerce_google_analytics_settings', array() );

		if ( $product && isset( $settings['ga_product_identifier'] ) && 'product_sku' === $settings['ga_product_identifier'] ) {
			$item_id = $product->get_sku() ? $product->get_sku() : '#' . $product_id;
		}

		$qty = max( 1, (int) $item->get_quantity() );

		return array(
			'item_id'   => $item_id,
			'item_name' => $item->get_name(),
			'price'     => round( (float) $item->get_total() / $qty, 2 ),
			'quantity'  => $qty,
		);
	}

	/**
	 * @param WC_Order_Item_Product $item
	 * @return bool
	 */
	private static function is_free_bonus_item( WC_Order_Item_Product $item ) {
		return 'yes' === $item->get_meta( self::FREE_BONUS_ITEM_META )
			|| ( 0.0 === (float) $item->get_total() && preg_match( '/\(Free bonus\)\s*$/i', $item->get_name() ) );
	}

	/**
	 * Trial products are separate $0 products named "<Product> - Free Trial".
	 *
	 * @param WC_Order_Item_Product $item
	 * @return bool
	 */
	private static function is_trial_item( WC_Order_Item_Product $item ) {
		return 0.0 === (float) $item->get_total() && (bool) preg_match( '/\s-\s*Free Trial\s*$/i', $item->get_name() );
	}

	/**
	 * "Advanced Second Entry - Free Trial" => "Advanced Second Entry".
	 *
	 * @param WC_Order_Item_Product $item
	 * @return string
	 */
	private static function get_trial_base_name( WC_Order_Item_Product $item ) {
		return trim( preg_replace( '/\s-\s*Free Trial\s*$/i', '', $item->get_name() ) );
	}

	/**
	 * Whether this customer (account or billing email) already had an earlier
	 * trial order for the same product.
	 *
	 * @param WC_Order              $order
	 * @param WC_Order_Item_Product $trial_item
	 * @return bool
	 */
	private static function is_repeat_trial( WC_Order $order, WC_Order_Item_Product $trial_item ) {
		$base = strtolower( self::get_trial_base_name( $trial_item ) );

		foreach ( self::get_prior_trial_items( $order ) as $trial ) {
			if ( strtolower( $trial['base_name'] ) === $base ) {
				self::log(
					'info',
					sprintf( 'Order #%d: repeat trial of %s (first trial order #%d); start_trial not sent.', $order->get_id(), $trial['base_name'], $trial['order_id'] )
				);
				return true;
			}
		}

		return false;
	}

	/**
	 * Finds the most recent earlier paid-for (processing/completed) trial order by the same
	 * customer (account or billing email) for the product in $paid_item.
	 *
	 * Trial and paid products are matched by name prefix because they're separate
	 * products with no stored link, and names don't always match exactly
	 * ("Advanced Second Entry - Free Trial" covers "Advanced Second Entry Strategy"
	 * and "Advanced Second Entry Indicator").
	 *
	 * @param WC_Order              $order
	 * @param WC_Order_Item_Product $paid_item
	 * @return array{order_id: int, base_name: string, days: int}|null
	 */
	private static function find_prior_trial( WC_Order $order, WC_Order_Item_Product $paid_item ) {
		$paid_name = strtolower( $paid_item->get_name() );

		foreach ( self::get_prior_trial_items( $order ) as $trial ) {
			$base = strtolower( $trial['base_name'] );

			$matches = '' !== $base && 0 === strpos( $paid_name, $base );

			/**
			 * Filters whether a previously trialled product counts as converted by a paid line item.
			 *
			 * @param bool                  $matches
			 * @param array                 $trial     order_id, base_name, days.
			 * @param WC_Order_Item_Product $paid_item
			 */
			if ( apply_filters( 'pat_ga4_trial_matches_paid_item', $matches, $trial, $paid_item ) ) {
				return $trial;
			}
		}

		return null;
	}

	/**
	 * @param WC_Order $order
	 * @return array[] Newest first.
	 */
	private static function get_prior_trial_items( WC_Order $order ) {
		static $cache = array();

		if ( isset( $cache[ $order->get_id() ] ) ) {
			return $cache[ $order->get_id() ];
		}

		$created = $order->get_date_created();
		$base    = array(
			'limit'        => 50,
			'status'       => array( 'wc-processing', 'wc-completed' ),
			'exclude'      => array( $order->get_id() ),
			'orderby'      => 'date',
			'order'        => 'DESC',
			'date_created' => '<' . ( $created ? $created->getTimestamp() : time() ),
		);
		$orders  = array();

		if ( $order->get_customer_id() ) {
			$orders = wc_get_orders( array_merge( $base, array( 'customer_id' => $order->get_customer_id() ) ) );
		}

		if ( $order->get_billing_email() ) {
			$orders = array_merge( $orders, wc_get_orders( array_merge( $base, array( 'billing_email' => $order->get_billing_email() ) ) ) );
		}

		usort(
			$orders,
			function ( $a, $b ) {
				return $b->get_date_created()->getTimestamp() - $a->get_date_created()->getTimestamp();
			}
		);

		$trials = array();
		$seen   = array();
		$now    = $created ? $created->getTimestamp() : time();

		foreach ( $orders as $prior ) {
			if ( isset( $seen[ $prior->get_id() ] ) ) {
				continue;
			}
			$seen[ $prior->get_id() ] = true;

			foreach ( $prior->get_items() as $item ) {
				if ( $item instanceof WC_Order_Item_Product && self::is_trial_item( $item ) ) {
					$trials[] = array(
						'order_id'  => $prior->get_id(),
						'base_name' => self::get_trial_base_name( $item ),
						'days'      => (int) floor( ( $now - $prior->get_date_created()->getTimestamp() ) / DAY_IN_SECONDS ),
					);
				}
			}
		}

		$cache[ $order->get_id() ] = $trials;

		return $trials;
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
		$session_id                 = self::resolve_session_id();

		$order->update_meta_data( self::CLIENT_ID_META_KEY, $client_id );
		$order->update_meta_data( self::CLIENT_ID_SOURCE_META_KEY, $source );
		$order->update_meta_data( self::SESSION_ID_META_KEY, $session_id );
		$order->save();

		if ( '' === $session_id ) {
			self::log(
				'warning',
				sprintf(
					'Order #%d: no _ga_<container-id> session cookie found at checkout; purchase event will be sent without session_id, so revenue may still land as Unassigned/(not set) even though client_id was captured.',
					$order->get_id()
				)
			);
		}
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
	 * Returns the order's stored session_id, if checkout capture found one.
	 *
	 * Unlike client_id, no fallback is generated here: a made-up session_id
	 * wouldn't attach to any real session and would just as effectively read
	 * as "no session" to GA4, but without the honesty of an empty value.
	 *
	 * @param WC_Order $order
	 * @return string Empty string if never captured.
	 */
	private static function get_session_id( WC_Order $order ) {
		return (string) $order->get_meta( self::SESSION_ID_META_KEY );
	}

	/**
	 * Reads session_id from the GA4 session cookie, which gtag.js sets as
	 * _ga_<container-id> (the container ID is assigned per data stream and
	 * isn't necessarily the measurement ID suffix, so the cookie name is
	 * discovered by pattern rather than assumed).
	 *
	 * The third dot-separated segment of the cookie value holds the session
	 * id, but its own format has changed across gtag.js versions:
	 *  - GS2.x (current): "$"-delimited, letter-prefixed fields, e.g.
	 *    "s1787168690$o4$g1$t1787168757$j54$l0$h0" - session_id is the "s" field.
	 *  - GS1.x (older): a bare number, e.g. "1700000000".
	 * Confirmed against a real captured cookie during live testing on
	 * 2026-08-19 (order #4846/#4847 both had a GS2.x cookie present that the
	 * original GS1-only parsing missed entirely, despite client_id capturing
	 * fine from the same request).
	 *
	 * @return string Empty string if no matching cookie is present.
	 */
	private static function resolve_session_id() {
		foreach ( $_COOKIE as $name => $value ) {
			if ( ! preg_match( '/^_ga_[A-Za-z0-9]+$/', $name ) ) {
				continue;
			}

			$parts = explode( '.', sanitize_text_field( wp_unslash( $value ) ) );

			if ( ! isset( $parts[2] ) ) {
				continue;
			}

			if ( preg_match( '/(?:^|\$)s(\d+)/', $parts[2], $matches ) ) {
				return $matches[1];
			}

			if ( ctype_digit( $parts[2] ) ) {
				return $parts[2];
			}
		}

		return '';
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

	/**
	 * True only on the real production host. Everything else (Local, staging, clones) can
	 * only ever hit the validation endpoint, whatever secret/debug settings it carries.
	 *
	 * @return bool
	 */
	public static function is_production_site() {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		return (bool) apply_filters( 'pat_ga4_is_production_site', in_array( $host, self::PRODUCTION_HOSTS, true ), $host );
	}

	/**
	 * On by default: internal users' orders/browsing are not reported.
	 *
	 * @return bool
	 */
	public static function is_internal_exclusion_enabled() {
		$opts = get_option( self::OPTION_KEY, array() );

		return ! isset( $opts['exclude_internal'] ) || ! empty( $opts['exclude_internal'] );
	}

	/**
	 * @return string[] Lowercased emails and "@domain" entries.
	 */
	public static function get_internal_emails() {
		$opts = get_option( self::OPTION_KEY, array() );

		return empty( $opts['internal_emails'] ) ? array() : array_filter( array_map( 'strtolower', explode( "\n", $opts['internal_emails'] ) ) );
	}

	/**
	 * Whether an email is on the internal list, either exactly or via an "@domain" entry.
	 *
	 * @param string $email
	 * @return bool
	 */
	public static function is_internal_email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		$at    = strrpos( $email, '@' );

		if ( '' === $email || false === $at ) {
			return false;
		}

		$list = self::get_internal_emails();

		return in_array( $email, $list, true ) || in_array( substr( $email, $at ), $list, true );
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
			'measurement_id'   => isset( $input['measurement_id'] ) ? sanitize_text_field( $input['measurement_id'] ) : '',
			'api_secret'       => isset( $input['api_secret'] ) ? sanitize_text_field( $input['api_secret'] ) : '',
			'debug_mode'       => ! empty( $input['debug_mode'] ),
			'exclude_internal' => ! empty( $input['exclude_internal'] ),
			'internal_emails'  => isset( $input['internal_emails'] ) ? self::sanitize_email_list( $input['internal_emails'] ) : '',
		);
	}

	/**
	 * @param string $raw Comma/newline/space-separated emails and/or domains.
	 * @return string One lowercased entry per line: a valid email, or a domain stored as "@domain".
	 */
	private static function sanitize_email_list( $raw ) {
		$entries = array();

		foreach ( preg_split( '/[\s,;]+/', strtolower( (string) $raw ) ) as $entry ) {
			$entry = trim( $entry );

			if ( '' === $entry ) {
				continue;
			}

			if ( false !== strpos( ltrim( $entry, '@' ), '@' ) ) {
				$email = sanitize_email( $entry );

				if ( is_email( $email ) ) {
					$entries[] = $email;
				}
			} else {
				$domain = ltrim( $entry, '@' );

				if ( preg_match( '/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $domain ) ) {
					$entries[] = '@' . $domain;
				}
			}
		}

		return implode( "\n", array_unique( $entries ) );
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
								<?php if ( ! empty( $opts['api_secret'] ) ) : ?>
									<br /><span style="color:#b32d2e;"><?php esc_html_e( 'An old copy is still stored in the database - click "Save Changes" below to remove it.', 'pat-ga4' ); ?></span>
								<?php endif; ?>
							<?php elseif ( self::get_api_secret() ) : ?>
								<?php esc_html_e( 'Configured via the option field below.', 'pat-ga4' ); ?>
							<?php else : ?>
								<span style="color:#b32d2e;"><?php esc_html_e( 'Not configured yet - purchase events cannot be sent until this is set.', 'pat-ga4' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Environment', 'pat-ga4' ); ?></strong></td>
						<td>
							<?php if ( self::is_production_site() ) : ?>
								<?php esc_html_e( 'Production host - automatic sends go to live GA4 (unless debug mode is on).', 'pat-ga4' ); ?>
							<?php else : ?>
								<span style="color:#b32d2e;"><?php esc_html_e( 'Non-production host - all sends are forced to the validation endpoint and browser gtag is disabled. Nothing reaches GA4 reports from this site.', 'pat-ga4' ); ?></span>
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
						<?php // The constant wins, so saving here drops any leftover DB copy of the secret (keeps DB clones secret-free). ?>
						<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_secret]" value="" />
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
					<tr>
						<th scope="row"><?php esc_html_e( 'Exclude internal traffic', 'pat-ga4' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[exclude_internal]" value="1" <?php checked( self::is_internal_exclusion_enabled() ); ?> />
								<?php esc_html_e( 'Don\'t report orders placed by administrators/shop managers or by the emails below, and disable browser gtag for them while logged in.', 'pat-ga4' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pat_ga4_internal_emails"><?php esc_html_e( 'Internal emails', 'pat-ga4' ); ?></label></th>
						<td>
							<textarea id="pat_ga4_internal_emails" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[internal_emails]" rows="4" class="large-text code"><?php echo esc_textarea( $opts['internal_emails'] ?? '' ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line: a full email, or a whole domain (e.g. @example.com). Orders whose billing email matches (e.g. your own test-customer accounts) are never sent to GA4.', 'pat-ga4' ); ?></p>
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
		$skipped = $order->get_meta( self::SKIPPED_META_KEY );

		echo '<p class="form-field form-field-wide">';
		echo '<strong>' . esc_html__( 'GA4 purchase event:', 'pat-ga4' ) . '</strong> ';

		if ( $sent_at ) {
			echo esc_html( sprintf( __( 'Sent %s', 'pat-ga4' ), $sent_at ) );
		} elseif ( $skipped ) {
			echo esc_html( sprintf( __( 'Not sent - %s.', 'pat-ga4' ), $skipped ) );
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
		 * (Re)sends the GA4 purchase/start_trial/trial_converted events for a WooCommerce order.
		 *
		 * Bypasses the internal-order exclusion (it's a deliberate send), but on any
		 * non-production host it is always forced to the validation endpoint.
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

			if ( is_array( $result ) ) {
				WP_CLI::log( wp_json_encode( $result['payload'], JSON_PRETTY_PRINT ) );
				WP_CLI::log( 'Response: ' . $result['body'] );
				WP_CLI::success( "Validation response received for order #{$order_id} (nothing recorded in GA4)." );
			} else {
				WP_CLI::success( "Purchase event sent for order #{$order_id}." );
			}
		}
	}

	WP_CLI::add_command( 'pat-ga4 resend', array( 'PAT_GA4_Server_Tracking_CLI', 'resend' ) );
}
