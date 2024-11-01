<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Gateway_USAePay class.
 *
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_USAePay extends WC_Payment_Gateway_CC {

	public $testmode;
	public $capture;
	public $source_key;
	public $pin;
	public $logging;
	public $debugging;
	public $line_items;
	public $allowed_card_types;
	public $customer_receipt;
	public $statement_descriptor;

	private $currencies = array( 'USD' );

    const LIVE_URL    = 'https://usaepay.com/api/v2/transactions';
    const SANDBOX_URL = 'https://sandbox.usaepay.com/api/v2/transactions';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->id                  	= 'usaepay';
		$this->method_title         = __( 'USAePay', 'wc-usaepay' );
		$this->method_description	= sprintf( esc_html__( 'Live merchant accounts cannot be used in a sandbox environment, so to test the plugin, please make sure you are using a separate sandbox account. If you do not have a sandbox account, you can sign up for one from %shere%s.', 'wc-usaepay' ), '<a href="https://developer.usaepay.com/_developer/app/register" target="_blank">', '</a>' );
		$this->has_fields           = true;
		$this->supports             = array( 'products', 'refunds' );

		// Load the form fields
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Get setting values.
		$this->title       		  	= $this->get_option( 'title' );
		$this->description 		  	= $this->get_option( 'description' );
		$this->enabled     		  	= $this->get_option( 'enabled' );
		$this->testmode    		  	= $this->get_option( 'testmode' ) === 'yes';
		$this->capture     		  	= $this->get_option( 'capture', 'yes' ) === 'yes';
		$this->statement_descriptor = $this->get_option( 'statement_descriptor', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$this->source_key	   		= $this->get_option( 'source_key' );
		$this->pin	  				= $this->get_option( 'pin' );
		$this->logging     		  	= $this->get_option( 'logging' ) === 'yes';
		$this->debugging   		  	= $this->get_option( 'debugging' ) === 'yes';
		$this->line_items           = $this->get_option( 'line_items' ) === 'yes';
		$this->allowed_card_types 	= $this->get_option( 'allowed_card_types', array() );
		$this->customer_receipt   	= $this->get_option( 'customer_receipt' ) === 'yes';

		if ( $this->testmode ) {
			$this->description .= ' ' . sprintf( __( '<br /><br /><strong>TEST MODE ENABLED</strong><br /> In test mode, you can use the card number 4111111111111111 with any CVC and a valid expiration date or check the documentation "<a href="%s">%s API</a>" for more card numbers.', 'wc-usaepay' ), 'https://help.usaepay.info/developer/reference/testcards/', $this->method_title );
			$this->description  = trim( $this->description );
		}

		// Hooks
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

	}

	/**
	 * get_icon function.
	 *
	 * @access public
	 * @return string
	 */
	public function get_icon() {
		$icon = '';
        if ( in_array( 'visa', $this->allowed_card_types ) ) {
            $icon .= '<img style="margin-left: 0.3em" src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/visa.svg' ) . '" alt="Visa" width="32" />';
        }
        if ( in_array( 'mastercard', $this->allowed_card_types ) ) {
            $icon .= '<img style="margin-left: 0.3em" src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/mastercard.svg' ) . '" alt="Mastercard" width="32" />';
        }
        if ( in_array( 'amex', $this->allowed_card_types ) ) {
            $icon .= '<img style="margin-left: 0.3em" src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/amex.svg' ) . '" alt="Amex" width="32" />';
        }
        if ( in_array( 'discover', $this->allowed_card_types ) ) {
            $icon .= '<img style="margin-left: 0.3em" src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/discover.svg' ) . '" alt="Discover" width="32" />';
        }
        if ( in_array( 'jcb', $this->allowed_card_types ) ) {
            $icon .= '<img style="margin-left: 0.3em" src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/jcb.svg' ) . '" alt="JCB" width="32" />';
        }
        if ( in_array( 'diners-club', $this->allowed_card_types ) ) {
            $icon .= '<img style="margin-left: 0.3em" src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/diners.svg' ) . '" alt="Diners Club" width="32" />';
        }
        return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	/**
	 * Check if SSL is enabled and notify the user
	 */
	public function admin_notices() {
		if ( $this->enabled == 'no' ) {
            return;
        }

		// Check required fields
        if ( ! $this->source_key ) {
            echo  '<div class="error"><p>' . sprintf( __( 'USAePay error: Please enter your Source Key <a href="%s">here</a>', 'wc-usaepay' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=usaepay' ) ) . '</p></div>';
            return;
        } elseif ( ! $this->pin ) {
            echo  '<div class="error"><p>' . sprintf( __( 'USAePay error: Please enter your Pin <a href="%s">here</a>', 'wc-usaepay' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=usaepay' ) ) . '</p></div>';
            return;
        }

		// Simple check for duplicate keys
		if ( $this->source_key == $this->pin ) {
			echo '<div class="error"><p>' . sprintf( __( 'USAePay error: Your Source Key and Pin match. Please check and re-enter.', 'wc-usaepay' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=usaepay' ) ) . '</p></div>';
			return;
		}

		if ( ! $this->currency_is_accepted() ) {
			echo '<div class="error"><p>' . __( 'USAePay supports only USD currency.', 'wc-usaepay' ) . '</p></div>';
			return;
		}

        // Show message if enabled and FORCE SSL is disabled and WordpressHTTPS plugin is not detected
        if ( ! wc_checkout_is_https() ) {
            echo  '<div class="notice notice-warning"><p>' . sprintf( __( 'USAePay is enabled, but a SSL certificate is not detected. Your checkout may not be secure! Please ensure your server has a valid <a href="%1$s" target="_blank">SSL certificate</a>', 'wc-usaepay' ), 'https://en.wikipedia.org/wiki/Transport_Layer_Security' ) . '</p></div>';
        }
	}

	/**
	 * Check if this gateway is enabled
	 */
	public function is_available() {
		if ( $this->enabled == "yes" ) {
			if ( is_add_payment_method_page() ) {
				return false;
			}
            // Required fields check
            if ( ! $this->source_key || ! $this->pin ) {
                return false;
            }
			if ( ! $this->currency_is_accepted() ) {
				return false;
			}
            return true;
        }

        return parent::is_available();
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields() {
		$this->form_fields = apply_filters( 'wc_usaepay_settings', array(
			'enabled' => array(
				'title'		  => __( 'Enable/Disable', 'wc-usaepay' ),
				'label'       => __( 'Enable USAePay', 'wc-usaepay' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'title'	=> array(
				'title'       => __( 'Title', 'wc-usaepay' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'wc-usaepay' ),
				'default'     => __( 'Credit card (USAePay)', 'wc-usaepay' ),
			),
			'description' => array(
				'title'       => __( 'Description', 'wc-usaepay' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'wc-usaepay' ),
				'default'     => __( 'Pay with your credit card via USAePay.', 'wc-usaepay' ),
			),
			'testmode' => array(
				'title'       => __( 'Sandbox mode', 'wc-usaepay' ),
				'label'       => __( 'Enable Sandbox Mode', 'wc-usaepay' ),
				'type'        => 'checkbox',
				'description' => sprintf( esc_html__( 'Check the USAePay testing guide %shere%s. This will display "sandbox mode" warning on checkout.', 'wc-usaepay' ), '<a href="https://help.usaepay.info/developer/reference/testcards/" target="_blank">', '</a>' ),
				'default'     => 'yes',
			),
			'source_key' => array(
				'title'       => __( 'Source Key', 'wc-usaepay' ),
				'type'        => 'text',
				'description' => esc_html__( 'Create one from Settings -> Source Keys in your USAePay account.', 'wc-usaepay' ),
				'default'     => '',
			),
			'pin' => array(
				'title'       => __( 'Pin', 'wc-usaepay' ),
				'type'        => 'password',
				'description' => esc_html__( 'Enter your Pin associated with the source key.', 'wc-usaepay' ),
				'default'     => '',
			),
			'statement_descriptor' => array(
				'title'       => __( 'Statement Descriptor', 'wc-usaepay' ),
				'type'        => 'text',
				'description' => __( 'Extra information about a charge. This will appear in your order description. Defaults to site name.', 'wc-usaepay' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'capture' => array(
				'title'       => __( 'Capture', 'wc-usaepay' ),
				'label'       => __( 'Capture charge immediately', 'wc-usaepay' ),
				'type'        => 'checkbox',
				'description' => __( 'Whether or not to immediately capture the charge. When unchecked, the charge issues an authorization and will need to be captured later.', 'wc-usaepay' ),
				'default'     => 'yes',
			),
			'logging' => array(
				'title'       => __( 'Logging', 'wc-usaepay' ),
				'label'       => __( 'Log debug messages', 'wc-usaepay' ),
				'type'        => 'checkbox',
				'description' => sprintf( __( 'Save debug messages to the WooCommerce System Status log file <code>%s</code>.', 'wc-usaepay' ), WC_Log_Handler_File::get_log_file_path( 'woocommerce-gateway-usaepay' ) ),
				'default'     => 'no',
			),
			'debugging' => array(
				'title'       => __( 'Gateway Debug', 'wc-usaepay' ),
				'label'       => __( 'Log gateway requests and response to the WooCommerce System Status log.', 'wc-usaepay' ),
				'type'        => 'checkbox',
				'description' => __( '<strong>CAUTION! Enabling this option will write gateway requests possibly including card numbers and CVV to the logs.</strong> Do not turn this on unless you have a problem processing credit cards. You must only ever enable it temporarily for troubleshooting or to send requested information to the plugin author. It must be disabled straight away after the issues are resolved and the plugin logs should be deleted.', 'wc-usaepay' ) . ' ' . sprintf( __( '<a href="%s">Click here</a> to check and delete the full log file.', 'wc-usaepay' ), admin_url( 'admin.php?page=wc-status&tab=logs&log_file=' . WC_Log_Handler_File::get_log_file_name( 'woocommerce-gateway-usaepay' ) ) ),
				'default'     => 'no',
			),
			'line_items' => array(
				'title'       => __( 'Line Items', 'wc-usaepay' ),
				'label'       => __( 'Enable Line Items', 'wc-usaepay' ),
				'type'        => 'checkbox',
				'description' => __( 'Add line item data to description sent to the gateway (eg. Item x qty).', 'wc-usaepay' ),
				'default'     => 'no'
			),
			'allowed_card_types' => array(
				'title'       => __( 'Allowed Card types', 'wc-usaepay' ),
				'class'       => 'wc-enhanced-select',
				'type'        => 'multiselect',
				'description' => __( 'Select the card types you want to allow payments from.', 'wc-usaepay' ),
				'default'     => array(
					'visa',
					'mastercard',
					'discover',
					'amex'
				),
				'options'     => array(
					'visa'        => __( 'Visa', 'wc-usaepay' ),
					'mastercard'  => __( 'MasterCard', 'wc-usaepay' ),
					'discover'    => __( 'Discover', 'wc-usaepay' ),
					'amex'        => __( 'American Express', 'wc-usaepay' ),
					'jcb'         => __( 'JCB', 'wc-usaepay' ),
					'diners-club' => __( 'Diners Club', 'wc-usaepay' ),
				),
			),
			'customer_receipt' => array(
				'title'       => __( 'Receipt', 'wc-usaepay' ),
				'label'       => __( 'Send Gateway Receipt', 'wc-usaepay' ),
				'type'        => 'checkbox',
				'description' => __( 'If enabled, the customer will be sent an email receipt from USAePay.', 'wc-usaepay' ),
				'default'     => 'no',
			),
        ) );
	}

	/**
	 * Payment form on checkout page
	 */
	public function payment_fields() {
		echo '<div class="usaepay_new_card" id="usaepay-payment-data">';

		if ( $this->description ) {
			echo apply_filters( 'wc_usaepay_description', wpautop( wp_kses_post( $this->description ) ) );
		}

		$this->form();

		echo '</div>';
	}

	public function javascript_params() {
		return array(
			'allowed_card_types'    => $this->allowed_card_types,
			'no_card_number_error'  => __( 'Enter a card number.', 'wc-usaepay' ),
			'no_card_expiry_error'  => __( 'Enter an expiry date.', 'wc-usaepay' ),
			'no_cvv_error'          => __( 'CVC code is required.', 'wc-usaepay' ),
			'card_number_error' 	=> __( 'Invalid card number.', 'wc-usaepay' ),
			'card_expiry_error' 	=> __( 'Invalid card expiry date.', 'wc-usaepay' ),
			'card_cvc_error' 		=> __( 'Invalid card CVC.', 'wc-usaepay' ),
			'placeholder_cvc'	 	=> __( 'CVC', 'woocommerce' ),
			'placeholder_expiry' 	=> __( 'MM / YY', 'woocommerce' ),
			'card_disallowed_error' => __( 'Card Type Not Accepted.', 'wc-usaepay' ),
		);
	}

	/**
	 * Process the payment
	 */
	public function process_payment( $order_id, $retry = true ) {

		$order = wc_get_order( $order_id );

		$this->log( "Info: Begin processing payment for order {$order_id} for the amount of {$order->get_total()}" );

		$response = false;

		// Use USAePay CURL API for payment
		try {

			// Check for CC details filled or not
			if ( empty( $_POST['usaepay-card-number'] ) || empty( $_POST['usaepay-card-expiry'] ) || empty( $_POST['usaepay-card-cvc'] ) ) {
				throw new Exception( __( 'Credit card details cannot be left incomplete.', 'wc-usaepay' ) );
			}

			// Check for card type supported or not
			if ( ! in_array( $this->get_card_type( wc_clean( $_POST['usaepay-card-number'] ), 'pattern', 'name' ), $this->allowed_card_types ) ) {
				$this->log( sprintf( __( 'Card type being used is not one of supported types in plugin settings: %s', 'wc-usaepay' ), $this->get_card_type( wc_clean( $_POST['usaepay-card-number'] ) ) ) );
				throw new Exception( __( 'Card Type Not Accepted', 'wc-usaepay' ) );
			}

			$expiry = explode( ' / ', wc_clean( $_POST['usaepay-card-expiry'] ) );
			$expiry[1] = substr( $expiry[1], -2 );

			$description = trim( sprintf( __( '%1$s - Order %2$s', 'wc-usaepay' ), $this->statement_descriptor, $order->get_order_number() ) );

			if ( $this->line_items ) {
				$description .= ' (' . $this->get_line_items( $order ) . ')';
			}

			$payment_args = array(
				'command'	 	=> $this->capture ? 'cc:sale' : 'cc:authonly',
				'invoice'		=> $order->get_order_number(),
				'orderid'		=> $order_id,
				'description'	=> $description,
				'amount'		=> $order->get_total(),
				'email'			=> $order->get_billing_email(),
				'send_receipt'  => (int) $this->customer_receipt,
				'clientip'		=> WC_Geolocation::get_ip_address(),
				'save_card'		=> true,
				'creditcard'	=> array(
					'cardholder' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
					'number' 	 => str_replace( ' ', '', wc_clean( $_POST['usaepay-card-number'] ) ),
					'expiration' => $expiry[0] . $expiry[1],
					'cvc' 		 => wc_clean( $_POST['usaepay-card-cvc'] ),
				),
				'billing_address' => array(
					'firstname'	=> $order->get_billing_first_name(),
					'lastname'	=> $order->get_billing_last_name(),
					'street'	=> $order->get_billing_address_1(),
					'street2'	=> $order->get_billing_address_2(),
					'city'		=> $order->get_billing_city(),
					'state'		=> $order->get_billing_state(),
					'postalcode' => $order->get_billing_postcode(),
					'country' 	=> $order->get_billing_country(),
					'phone' 	=> $order->get_billing_phone(),
					'company' 	=> $order->get_billing_company(),
				),
				'ignore_duplicate' => 0,
			);
			if( ! $this->customer_receipt ) {
				$payment_args['receipt-custemail'] = 'none';
			}
			$payment_args = apply_filters( 'wc_usaepay_process_payment_request_args', $payment_args, $order );

			$response = $this->usaepay_request( $payment_args );

			if ( is_wp_error( $response ) ) {
				throw new Exception( $response->get_error_message() );
			}

			// Store charge ID
			$order->update_meta_data( '_usaepay_charge_id', $response['refnum'] );
			$order->update_meta_data( '_usaepay_authorization_code', $response['authcode'] );
			$order->update_meta_data( '_usaepay_cc_last4', substr( wc_clean( $_POST['usaepay-card-number'] ), -4 ) );
			$order->update_meta_data( '_usaepay_cc_type', $this->get_card_type( wc_clean( $_POST['usaepay-card-number'] ) ) );

			$order->set_transaction_id( $response['refnum'] );

            if ( $payment_args['command'] == 'cc:sale' ) {

                // Store captured value
                $order->update_meta_data( '_usaepay_charge_captured', 'yes' );
                $order->update_meta_data( 'USAePay Payment ID', $response['refnum'] );

                // Payment complete
                $order->payment_complete( $response['refnum'] );

                // Add order note
				$complete_message = trim( sprintf( __( "USAePay charge complete (Charge ID: %s) %s %s", 'wc-usaepay' ), $response['refnum'], self::get_avs_message( $response ), self::get_cvv_message( $response ) ) );
                $order->add_order_note( $complete_message );
                $this->log( "Success: $complete_message" );

            } else {

                // Store captured value
                $order->update_meta_data( '_usaepay_charge_captured', 'no' );

                if ( $order->has_status( array( 'pending', 'failed' ) ) ) {
                    wc_reduce_stock_levels( $order_id );
                }

                // Mark as on-hold
				$authorized_message = trim( sprintf( __( "USAePay charge authorized (Charge ID: %s). Process order to take payment, or cancel to remove the pre-authorization. %s %s", 'wc-usaepay' ), $response['refnum'], self::get_avs_message( $response ), self::get_cvv_message( $response ) ) );
                $order->update_status( 'on-hold', $authorized_message . "\n" );
                $this->log( "Success: $authorized_message" );

            }

			$order->save();

			// Remove cart
			WC()->cart->empty_cart();

			do_action( 'wc_gateway_usaepay_process_payment', $response, $order );

			// Return thank you page redirect
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order )
			);

		} catch ( Exception $e ) {

			wc_add_notice( sprintf( __( 'Gateway Error: %s', 'wc-usaepay' ), $e->getMessage() ), 'error' );
            $this->log( sprintf( __( 'Gateway Error: %s', 'wc-usaepay' ), $e->getMessage() ) );

			if ( is_wp_error( $response ) && $response = $response->get_error_data() ) {
				$order->add_order_note( trim( sprintf( __( "USAePay failure reason: %s %s %s", 'wc-usaepay' ), $response['error'], self::get_avs_message( $response ), self::get_cvv_message( $response ) ) ) );
            }

			do_action( 'wc_gateway_usaepay_process_payment_error', $e, $order );

			/* translators: error message */
			$order->update_status( 'failed' );

			return array(
				'result'   => 'fail',
				'redirect' => ''
			);
		}
	}

	function usaepay_request( $payment_args ) {

		$gateway_debug = ( $this->logging && $this->debugging );

		$request_url = $this->testmode ? self::SANDBOX_URL : self::LIVE_URL;
		$request_url = apply_filters( 'wc_usaepay_request_url', $request_url );

        // Setting custom timeout for the HTTP request
		add_filter( 'http_request_timeout', array( $this, 'http_request_timeout' ), 9999 );

		$args = array(
			'method'	=> 'POST',
			'body' 		=> json_encode( $payment_args ),
			'headers' 	=> array(
				'Content-Type' 	=> 'application/json',
				'Authorization' => 'Basic ' . $this->get_auth_key(),
			),
		);

		$response = wp_remote_request( $request_url, $args );

		$result = is_wp_error( $response ) ? $response : json_decode( wp_remote_retrieve_body( $response ), true );

        // Saving to Log here
		if ( $gateway_debug ) {
			$message = sprintf( "\nPosting to: \n%s\nRequest: \n%s\nResponse: \n%s", $request_url, print_r( $payment_args, 1 ), print_r( $result, 1 ) );
			WC_USAePay_Logger::log( $message );
		}

		remove_filter( 'http_request_timeout', array( $this, 'http_request_timeout' ), 9999 );

        if ( is_wp_error( $result ) ) {
			return $result;
		} elseif ( empty( $result ) ) {
			$error_message = __( 'There was an error with the gateway response.', 'wc-usaepay' );
			return new WP_Error( 'invalid_response', apply_filters( 'woocommerce_usaepay_error_message', $error_message, $result ) );
		}

        if ( isset( $result['result'] ) && in_array( $result['result'], array( 'Error', 'Declined' ) ) ) {
			$error_message = '<!-- Error: ' . $result['error_code'] . ' --> ' . $result['error'];
            return new WP_Error( 'card_declined', apply_filters( 'woocommerce_usaepay_error_message', $error_message, $result ), $result );
		} elseif ( isset( $result['error'] ) ) {
			$error_message = '<!-- Error: ' . $result['errorcode'] . ' --> ' . $result['error'];
			return new WP_Error( 'card_error', apply_filters( 'woocommerce_usaepay_error_message', $error_message, $result ), $result );
		}

		return $result;
	}

	/**
	 * Refund a charge
	 * @param  int $order_id
	 * @param  float $amount
	 * @return bool
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || ! $order->get_transaction_id() || $amount <= 0 ) {
			return false;
		}

		$this->log( "Info: Beginning refund for order $order_id for the amount of {$amount}" );

		$args = array(
			'command'	=> 'refund',
			'amount'  	=> $amount,
			'refnum'	=> $order->get_transaction_id(),
			'email' 	=> $order->get_billing_email(),
			//'order_description' => $reason,
		);

		$args = apply_filters( 'wc_usaepay_refund_request_args', $args, $order );

		$response = $this->usaepay_request( $args );

		if ( is_wp_error( $response ) ) {
			$this->log( "Gateway Error: " . $response->get_error_message() );
			return $response;
		} elseif ( ! empty( $response['refnum'] ) ) {
			$refund_message = sprintf( __( 'Refunded %s - Refund ID: %s - Reason: %s', 'wc-usaepay' ), $amount, $response['refnum'], $reason );
			$order->add_order_note( $refund_message );
			$order->save();
			$this->log( "Success: " . html_entity_decode( strip_tags( $refund_message ) ) );
			return true;
		}
	}

    public function http_request_timeout( $timeout_value ) {
		return 45; // 45 seconds. Too much for production, only for testing.
	}

	function get_card_type( $value, $field = 'pattern', $return = 'label' ) {
		$card_types = array(
			array(
				'label' => 'American Express',
				'name' => 'amex',
				'pattern' => '/^3[47]/',
				'valid_length' => '[15]'
			),
			array(
				'label' => 'JCB',
				'name' => 'jcb',
				'pattern' => '/^35(2[89]|[3-8][0-9])/',
				'valid_length' => '[16]'
			),
			array(
				'label' => 'Discover',
				'name' => 'discover',
				'pattern' => '/^(6011|622(12[6-9]|1[3-9][0-9]|[2-8][0-9]{2}|9[0-1][0-9]|92[0-5]|64[4-9])|65)/',
				'valid_length' => '[16]'
			),
			array(
				'label' => 'MasterCard',
				'name' => 'mastercard',
				'pattern' => '/^5[1-5]/',
				'valid_length' => '[16]'
			),
			array(
				'label' => 'Visa',
				'name' => 'visa',
				'pattern' => '/^4/',
				'valid_length' => '[16]'
			),
			array(
				'label' => 'Maestro',
				'name' => 'maestro',
				'pattern' => '/^(5018|5020|5038|6304|6759|676[1-3])/',
				'valid_length' => '[12, 13, 14, 15, 16, 17, 18, 19]'
			),
			array(
				'label' => 'Diners Club',
				'name' => 'diners-club',
				'pattern' => '/^3[0689]/',
				'valid_length' => '[14]'
			),
		);

		foreach( $card_types as $type ) {
			$compare = $type[$field];
			if ( ( $field == 'pattern' && preg_match( $compare, $value, $match ) ) || $compare == $value ) {
				return $type[$return];
			}
		}

		return false;

	}

	/**
	 * Get payment currency, either from current order or WC settings
	 *
	 * @since 4.1.0
	 * @return string three-letter currency code
	 */
	function get_payment_currency( $order_id = false ) {
 		$currency = get_woocommerce_currency();
		$order_id = ! $order_id ? $this->get_checkout_pay_page_order_id() : $order_id;

 		// Gets currency for the current order, that is about to be paid for
 		if ( $order_id ) {
 			$order    = wc_get_order( $order_id );
 			$currency = $order->get_currency();
 		}
 		return $currency;
 	}

	/**
	 * Returns true if $currency is accepted by this gateway
	 *
	 * @since 2.1.0
	 * @param string $currency optional three-letter currency code, defaults to
	 *        order currency (if available) or currently configured WooCommerce
	 *        currency
	 * @return boolean true if $currency is accepted, false otherwise
	 */
	public function currency_is_accepted( $currency = null ) {
		// accept all currencies
		if ( ! $this->currencies ) {
			return true;
		}
		// default to order/WC currency
		if ( is_null( $currency ) ) {
			$currency = $this->get_payment_currency();
		}
		return in_array( $currency, $this->currencies );
	}

	/**
	 * Returns the order_id if on the checkout pay page
	 *
	 * @since 3.3
	 * @return int order identifier
	 */
	public function get_checkout_pay_page_order_id() {
		global $wp;
		return isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
	}

	/**
	 * get_avs_message function.
	 *
	 * @access public
	 *
	 * @param array $response
	 *
	 * @return string
	 */
	public function get_avs_message( $response ) {
		$avs_code    = $response['avs']['result_code'];
		$avs_message = $response['avs']['result'];

		if ( $avs_code && $avs_message ) {
			return "\n" . sprintf( 'AVS Response: %s', $avs_code . ' - ' . $avs_message );
		} else {
			return '';
		}
	}

	/**
	 * get_cvv_message function.
	 *
	 * @access public
	 *
	 * @param array $response
	 *
	 * @return string
	 */
	public function get_cvv_message( $response ) {
		$cvv_code    = $response['cvc']['result_code'];
		$cvv_message = $response['cvc']['result'];

		if ( $cvv_message ) {
			return "\n" . sprintf( 'CVV2 Response: %s', ( $cvv_code ? $cvv_code . ' - ' : '' ) . $cvv_message );
		} else {
			return '';
		}
	}

	public function get_line_items( $order ) {
		$line_items = array();
		// order line items
		foreach ( $order->get_items() as $item ) {
			$line_items[] = $item->get_name() . ' x ' . $item->get_quantity();
		}

		return implode( ', ', $line_items );
	}

	/**
	 * Send the request to USAePay's API
	 *
	 * @since 2.6.10
	 *
	 * @param string $message
	 */
	public function log( $message ) {
		if ( $this->logging ) {
			WC_USAePay_Logger::log( $message );
		}
	}

	private function get_auth_key() {

		$api_key  = $this->source_key;
		$pin 	  = $this->pin;

		$seed	  = substr( sha1( time() ), 0, 16 );

		$prehash  = $api_key . $seed . $pin;
		$api_hash = 's2/' . $seed . '/' . hash( 'sha256', $prehash );

		return base64_encode( $api_key . ':' . $api_hash );
	}

}
