<?php
/*
Plugin Name: Payment Gateway for USAePay on WooCommerce
Plugin URI: https://pledgedplugins.com/products/usaepay-payment-gateway-woocommerce/
Description: A payment gateway for USAePay. An USAePay account and a server with cURL, SSL support, and a valid SSL certificate is required (for security reasons) for this gateway to function. Requires WC 3.3+
Version: 4.2.0
Author: Pledged Plugins
Author URI: https://pledgedplugins.com
Text Domain: wc-usaepay
Domain Path: /languages
WC requires at least: 3.3
WC tested up to: 9.2
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Requires Plugins: woocommerce

	Copyright: © Pledged Plugins.
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WC_USAEPAY_VERSION', '4.2.0' );
define( 'WC_USAEPAY_MIN_PHP_VER', '5.6.0' );
define( 'WC_USAEPAY_MIN_WC_VER', '3.3' );
define( 'WC_USAEPAY_PLUGIN_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'WC_USAEPAY_PLUGIN_URL', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) );
define( 'WC_USAEPAY_MAIN_FILE', __FILE__ );

/**
 * Main USAePay class which sets the gateway up for us
 */
class WC_USAePay {

	/**
     * @var Singleton The reference the *Singleton* instance of this class
     */
    private static $instance;

    /**
     * Returns the *Singleton* instance of this class.
     *
     * @return Singleton The *Singleton* instance.
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

	/**
     * Notices (array)
     * @var array
     */
    public $notices = array();

    /**
     * Constructor
     */
    public function __construct() {

		add_action( 'before_woocommerce_init', function() {
			// Declaring HPOS feature compatibility
			if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			}
			// Declaring cart and checkout blocks compatibility
			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
			}
		} );

        // Actions
        add_action( 'admin_init', array( $this, 'check_environment' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ), 15 );
        add_action( 'plugins_loaded', array( $this, 'init' ) );
    }

    public function settings_url() {
        return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=usaepay' );
    }

    /**
	 * Add relevant links to plugins page
	 *
	 * @param array $links
	 *
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$plugin_links = array(
			'<a href="' . esc_url( $this->settings_url() ) . '">' . __( 'Settings', 'wc-usaepay' ) . '</a>',
			'<a href="https://pledgedplugins.com/support/" target="_blank">' . __( 'Support', 'wc-usaepay' ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}

    /**
     * Init localisations and files
     */
    public function init() {

		// Don't hook anything else in the plugin if we're in an incompatible environment
        if ( self::get_environment_warning() ) {
            return;
        }

        // Init the gateway itself
        $this->init_gateways();

        // required files
        require_once dirname( __FILE__ ) . '/includes/class-wc-gateway-usaepay-logger.php';

        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ), 11 );

        add_action( 'woocommerce_order_status_processing', array( $this, 'capture_payment' ) );
        add_action( 'woocommerce_order_status_completed', array( $this, 'capture_payment' ) );
        add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancel_payment' ) );
        add_action( 'woocommerce_order_status_refunded', array( $this, 'cancel_payment' ) );
    }

	/**
     * Allow this class and other classes to add slug keyed notices (to avoid duplication)
     */
    public function add_admin_notice( $slug, $class, $message, $data_dismissible = '' ) {
	    $this->notices[ $slug ] = array(
		    'class'            => $data_dismissible ? "$class is-dismissible" : $class,
		    'message'          => $message,
		    'data_dismissible' => $data_dismissible,
	    );
    }

    /**
     * The backup sanity check, in case the plugin is activated in a weird way,
     * or the environment changes after activation. Also handles upgrade routines.
     */
    public function check_environment() {

        if ( !defined( 'IFRAME_REQUEST' ) && WC_USAEPAY_VERSION !== get_option( 'wc_usaepay_version', '3.5' ) ) {
            $this->install();
            do_action( 'woocommerce_usaepay_updated' );
        }

        $environment_warning = self::get_environment_warning();

        if ( $environment_warning && is_plugin_active( plugin_basename( __FILE__ ) ) ) {
            $this->add_admin_notice( 'bad_environment', 'error', $environment_warning );
        }

		if ( ! class_exists( 'WC_Gateway_USAePay' ) ) {
            return;
		}

	    require_once dirname( __FILE__ ) . '/includes/persist-admin-notices-dismissal/persist-admin-notices-dismissal.php';
	    PAnD::init();

        // Check if secret key present. Otherwise prompt, via notice, to go to setting.
		$options = get_option( 'woocommerce_usaepay_settings' );
		$secret = isset( $options['pin'] ) ? $options['pin'] : '';

	    if ( PAnD::is_admin_notice_active( 'wp-gateways-merchant-account-forever' ) ) {
		    $external_link = 'https://wpgateways.com/support/merchant-solutions/';
		    $this->add_admin_notice( 'merchant_account', 'notice notice-info', sprintf( __( 'Need a new merchant account, or looking to lower your processing fees? <a href="%s" target="_blank">Discuss your requirements with us.</a>', 'wc-usaepay' ), $external_link ), 'wp-gateways-merchant-account-forever' );
	    }

        if ( empty( $secret ) && !( isset( $_GET['page'], $_GET['section'] ) && 'wc-settings' === $_GET['page'] && 'usaepay' === $_GET['section'] ) ) {
            $setting_link = esc_url( $this->settings_url() );
            $this->add_admin_notice( 'prompt_connect', 'notice notice-warning', sprintf( __( 'USAePay is almost ready. To get started, <a href="%s">set your USAePay account keys</a>.', 'wc-usaepay' ), $setting_link ) );
        }

    }

    /**
     * Updates the plugin version in db
     *
     * @since 3.1.0
     * @version 3.1.0
     * @return bool
     */
    private static function _update_plugin_version() {
        delete_option( 'wc_usaepay_version' );
        update_option( 'wc_usaepay_version', WC_USAEPAY_VERSION );
        return true;
    }

    /**
     * Handles upgrade routines.
     *
     * @since 3.1.0
     * @version 3.1.0
     */
    public function install() {
        if ( !defined( 'WC_USAEPAY_INSTALLING' ) ) {
            define( 'WC_USAEPAY_INSTALLING', true );
        }
        $this->_update_plugin_version();
    }

    /**
     * Checks the environment for compatibility problems.  Returns a string with the first incompatibility
     * found or false if the environment has no problems.
     */
    static function get_environment_warning() {

        if ( version_compare( phpversion(), WC_USAEPAY_MIN_PHP_VER, '<' ) ) {
            $message = __( 'WooCommerce USAePay - The minimum PHP version required for this plugin is %1$s. You are running %2$s.', 'wc-usaepay' );
            return sprintf( $message, WC_USAEPAY_MIN_PHP_VER, phpversion() );
        }

        if ( !defined( 'WC_VERSION' ) ) {
            return __( 'WooCommerce USAePay requires WooCommerce to be activated to work.', 'wc-usaepay' );
        }

        if ( version_compare( WC_VERSION, WC_USAEPAY_MIN_WC_VER, '<' ) ) {
            $message = __( 'WooCommerce USAePay - The minimum WooCommerce version required for this plugin is %1$s. You are running %2$s.', 'wc-usaepay' );
            return sprintf( $message, WC_USAEPAY_MIN_WC_VER, WC_VERSION );
        }

        if ( !function_exists( 'curl_init' ) ) {
            return __( 'WooCommerce USAePay - cURL is not installed.', 'wc-usaepay' );
        }
        return false;
    }

    /**
     * Display any notices we've collected thus far (e.g. for connection, disconnection)
     */
    public function admin_notices() {

        foreach ( $this->notices as $notice ) {
			echo "<div class='" . esc_attr( $notice['class'] ) . "' data-dismissible='" . esc_attr( $notice['data_dismissible'] ) . "'><p>";
			echo wp_kses( $notice['message'], array( 'a' => array( 'href' => array(), 'target' => array() ) ) );
			echo '</p></div>';
		}
    }

    /**
     * Initialize the gateway. Called very early - in the context of the plugins_loaded action
     *
     * @since 1.0.0
     */
    public function init_gateways() {
        if ( !class_exists( 'WC_Payment_Gateway' ) ) {
            return;
        }

        // Includes
        if ( is_admin() ) {
            require_once dirname( __FILE__ ) . '/includes/class-wc-usaepay-privacy.php';
        }

        include_once dirname( __FILE__ ) . '/includes/class-wc-gateway-usaepay.php';

        load_plugin_textdomain( 'wc-usaepay', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );
        add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateways' ) );
    }

    /**
     * Add the gateways to WooCommerce
     *
     * @since 1.0.0
     */
    public function add_gateways( $methods ) {
        $methods[] = 'WC_Gateway_USAePay';
        return $methods;
    }

	/**
	 * Capture payment when the order is changed from on-hold to complete or processing
	 *
	 * @param int $order_id
	 *
	 * @throws WC_Data_Exception
	 */
	public function capture_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		$gateway = new WC_Gateway_USAePay();

		$gateway->log( "Info: Beginning capture payment for order $order_id for the amount of {$order->get_total()}" );

		if ( $order->get_payment_method() == 'usaepay' ) {
			$charge   = $order->get_meta( '_usaepay_charge_id' );
			$captured = $order->get_meta( '_usaepay_charge_captured' );

			if ( $charge && $captured == 'no' ) {

				$order_total = $order->get_total();

				if ( 0 < $order->get_total_refunded() ) {
					$order_total = $order_total - $order->get_total_refunded();
				}

				$args = array(
					'command'	=> 'cc:capture',
					'amount'  	=> $order_total,
					'refnum'	=> $order->get_transaction_id(),
				);
				$args = apply_filters( 'wc_usaepay_capture_payment_request_args', $args, $order );

				$response = $gateway->usaepay_request( $args );

				if ( is_wp_error( $response ) ) {
					$order->add_order_note( __( 'Unable to capture charge!', 'wc-usaepay' ) . ' ' . $response->get_error_message() );
				} else {
					$complete_message = sprintf( __( 'USAePay charge complete (Charge ID: %s)', 'wc-usaepay' ), $response['refnum'] );
 					$order->add_order_note( $complete_message );
					$gateway->log( "Success: $complete_message" );

					$order->update_meta_data( '_usaepay_charge_captured', 'yes' );
					$order->update_meta_data( 'USAePay Payment ID', $response['refnum'] );

					$order->set_transaction_id( $response['refnum'] );
					$order->save();
				}
			}
		}
	}

	/**
	 * Cancel pre-auth on refund/cancellation
	 *
	 * @param  int $order_id
	 */
	public function cancel_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		$gateway = new WC_Gateway_USAePay();

		$gateway->log( "Info: Beginning cancel payment for order $order_id for the amount of {$order->get_total()}" );

		if ( $order->get_payment_method() == 'usaepay' ) {
			$charge = $order->get_meta( '_usaepay_charge_id' );
			$captured = $order->get_meta( '_usaepay_charge_captured' );

			if ( $charge && $captured == 'no' ) {
				$args = array(
					'command'	=> 'void',
					'refnum'	=> $order->get_transaction_id(),
				);
				$args = apply_filters( 'wc_usaepay_cancel_payment_request_args', $args, $order );

				$response = $gateway->usaepay_request( $args );

				if ( is_wp_error( $response ) ) {
					$order->add_order_note( __( 'Unable to refund charge!', 'wc-usaepay' ) . ' ' . $response->get_error_message() );
				} else {
					$cancel_message = sprintf( __( 'USAePay charge refunded (Charge ID: %s)', 'wc-usaepay' ), $response['refnum'] );
 					$order->add_order_note( $cancel_message );
					$gateway->log( "Success: $cancel_message" );

					$order->delete_meta_data( '_usaepay_charge_captured' );
					$order->delete_meta_data( '_usaepay_charge_id' );
					$order->save();
				}
			}
		}
	}

}
$GLOBALS['wc_usaepay'] = WC_USAePay::get_instance();

// Hook in Blocks integration. This action is called in a callback on plugins loaded, so current usaepay plugin class
// implementation is too late.
add_action( 'woocommerce_blocks_loaded', 'woocommerce_gateway_usaepay_woocommerce_block_support' );

function woocommerce_gateway_usaepay_woocommerce_block_support() {
	if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		require_once dirname( __FILE__ ) . '/includes/class-wc-usaepay-blocks-support.php';
		// priority is important here because this ensures this integration is
		// registered before the WooCommerce Blocks built-in usaepay registration.
		// Blocks code has a check in place to only register if 'usaepay' is not
		// already registered.
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
				$container = Automattic\WooCommerce\Blocks\Package::container();
				// registers as shared instance.
				$container->register(
					WC_USAePay_Blocks_Support::class,
					function() {
						return new WC_USAePay_Blocks_Support();
					}
				);
				$payment_method_registry->register(
					$container->get( WC_USAePay_Blocks_Support::class )
				);
			},
			5
		);
	}
}
