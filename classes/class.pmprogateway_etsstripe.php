<?php
// For compatibility with old library (Namespace Alias)
use Stripe\Customer as Stripe_Customer;
use Stripe\Invoice as Stripe_Invoice;
use Stripe\Plan as Stripe_Plan;
use Stripe\Product as Stripe_Product;
use Stripe\Price as Stripe_Price;
use Stripe\Charge as Stripe_Charge;
use Stripe\PaymentIntent as Stripe_PaymentIntent;
use Stripe\SetupIntent as Stripe_SetupIntent;
use Stripe\PaymentMethod as Stripe_PaymentMethod;
use Stripe\Subscription as Stripe_Subscription;
use Stripe\ApplePayDomain as Stripe_ApplePayDomain;
use Stripe\WebhookEndpoint as Stripe_Webhook;
use Stripe\StripeClient as Stripe_Client; // Used for deleting webhook as of 2.4
use Stripe\Account as Stripe_Account;

define( "PMPRO_ETS_STRIPE_API_VERSION", "2020-03-02" );

//include pmprogateway
//require_once( dirname( __FILE__ ) . "/class.pmprogateway.php" );

//load classes init method
add_action( 'init', array( 'PMProGateway_etsStripe', 'init' ) );

// loading plugin activation actions
add_action( 'activate_paid-memberships-pro', array( 'PMProGateway_etsStripe', 'pmpro_activation' ) );
add_action( 'deactivate_paid-memberships-pro', array( 'PMProGateway_etsStripe', 'pmpro_deactivation' ) );

/**
 * PMProGateway_gatewayname Class
 *
 * Handles example integration.
 *
 */
class PMProGateway_etsStripe extends PMProGateway
{
	/**
	 * @var bool    Is the Stripe/PHP Library loaded
	 */
	private static $is_loaded = false;

	/**
	 * Stripe Class Constructor
	 *
	 * @since 1.4
	 */
	function __construct( $gateway = null ) {
		$this->gateway             = $gateway;
		$this->gateway_environment = pmpro_getOption( "gateway_environment" );

		if ( true === $this->dependencies() ) {
			$this->loadStripeLibrary();
			Stripe\Stripe::setApiKey( self::get_secretkey() );
			Stripe\Stripe::setAPIVersion( PMPRO_ETS_STRIPE_API_VERSION );
			Stripe\Stripe::setAppInfo(
				'WordPress Paid Memberships Pro',
				PMPRO_VERSION,
				'https://www.paidmembershipspro.com',
				'pp_partner_DKlIQ5DD7SFW3A'
			);
			self::$is_loaded = true;
		}

		return $this->gateway;
	}

	/****************************************
	 ************ STATIC METHODS ************
	 ****************************************/
	/**
	 * Load the Stripe API library.
	 *
	 * @since 1.8
	 * Moved into a method in version 1.8 so we only load it when needed.
	 */
	public static function loadStripeLibrary() {
		//load Stripe library if it hasn't been loaded already (usually by another plugin using Stripe)
		if ( ! class_exists( "Stripe\Stripe" ) ) {
			$tru = require_once( PMPRO_DIR . "/includes/lib/Stripe/init.php" );
		}
	}

	/**
	 * Run on WP init
	 *
	 * @since 1.8
	 */
	public static function init() {
		//make sure Stripe is a gateway option
		add_filter( 'pmpro_gateways', array( 'PMProGateway_etsStripe', 'pmpro_gateways' ) );

		//add fields to payment settings
		add_filter( 'pmpro_payment_options', array( 'PMProGateway_etsStripe', 'pmpro_payment_options' ) );
		add_filter( 'pmpro_payment_option_fields', array(
			'PMProGateway_etsStripe',
			'pmpro_payment_option_fields'
		), 10, 2 );

		//add some fields to edit user page (Updates)
		add_action( 'pmpro_after_membership_level_profile_fields', array(
			'PMProGateway_etsStripe',
			'user_profile_fields'
		) );
		add_action( 'profile_update', array( 'PMProGateway_etsStripe', 'user_profile_fields_save' ) );

		//old global RE showing billing address or not
		global $pmpro_stripe_lite;
		$pmpro_stripe_lite = apply_filters( "pmpro_stripe_lite", ! pmpro_getOption( "stripe_billingaddress" ) );    //default is oposite of the stripe_billingaddress setting

		$gateway = pmpro_getGateway();
		if($gateway == "etsstripe")
		{
			add_filter( 'pmpro_required_billing_fields', array( 'PMProGateway_etsStripe', 'pmpro_required_billing_fields' ) );
		}

		//updates cron
		add_action( 'pmpro_cron_stripe_subscription_updates', array(
			'PMProGateway_etsStripe',
			'pmpro_cron_stripe_subscription_updates'
		) );
		
		//AJAX services for creating/disabling webhooks
		add_action( 'wp_ajax_pmpro_stripe_create_webhook', array( 'PMProGateway_etsStripe', 'wp_ajax_pmpro_stripe_create_webhook' ) );
		add_action( 'wp_ajax_pmpro_stripe_delete_webhook', array( 'PMProGateway_etsStripe', 'wp_ajax_pmpro_stripe_delete_webhook' ) );
		add_action( 'wp_ajax_pmpro_stripe_rebuild_webhook', array( 'PMProGateway_etsStripe', 'wp_ajax_pmpro_stripe_rebuild_webhook' ) );

		/*
            Filter pmpro_next_payment to get actual value
            via the Stripe API. This is disabled by default
            for performance reasons, but you can enable it
            by copying this line into a custom plugin or
            your active theme's functions.php and uncommenting
            it there.
        */
		//add_filter('pmpro_next_payment', array('PMProGateway_etsStripe', 'pmpro_next_payment'), 10, 3);

		//code to add at checkout if Stripe is the current gateway
		$default_gateway = pmpro_getOption( 'gateway' );
		$current_gateway = pmpro_getGateway();

		// $_REQUEST['review'] here means the PayPal Express review pag
		if ( ( $default_gateway == "etsstripe" || $current_gateway == "etsstripe" ) && empty( $_REQUEST['review'] ) )
		{
			add_action( 'pmpro_after_checkout_preheader', array(
				'PMProGateway_etsStripe',
				'pmpro_checkout_after_preheader'
			) );
			
			add_action( 'pmpro_billing_preheader', array( 'PMProGateway_etsStripe', 'pmpro_checkout_after_preheader' ) );
			add_filter( 'pmpro_checkout_order', array( 'PMProGateway_etsStripe', 'pmpro_checkout_order' ) );
			add_filter( 'pmpro_billing_order', array( 'PMProGateway_etsStripe', 'pmpro_checkout_order' ) );
			add_filter( 'pmpro_include_billing_address_fields', array(
				'PMProGateway_etsStripe',
				'pmpro_include_billing_address_fields'
			) );
			add_filter( 'pmpro_include_cardtype_field', array(
				'PMProGateway_etsStripe',
				'pmpro_include_billing_address_fields'
			) );
			add_filter( 'pmpro_include_payment_information_fields', array(
				'PMProGateway_etsStripe',
				'pmpro_include_payment_information_fields'
			) );

			//make sure we clean up subs we will be cancelling after checkout before processing
			add_action( 'pmpro_checkout_before_processing', array(
				'PMProGateway_etsStripe',
				'pmpro_checkout_before_processing'
			) );
		}

		add_action( 'pmpro_payment_option_fields', array( 'PMProGateway_etsStripe', 'pmpro_set_up_apple_pay' ), 10, 2 );
		add_action( 'init', array( 'PMProGateway_etsStripe', 'clear_saved_subscriptions' ) );

		// Stripe Connect functions.
		add_action( 'admin_init', array( 'PMProGateway_etsStripe', 'stripe_connect_save_options' ) );
		add_action( 'admin_notices', array( 'PMProGateway_etsStripe', 'stripe_connect_show_errors' ) );
		add_action( 'admin_notices', array( 'PMProGateway_etsStripe', 'stripe_connect_deauthorize' ) );
	}

	/**
	 * Clear any saved (preserved) subscription IDs that should have been processed and are now timed out.
	 */
	public static function clear_saved_subscriptions() {

		if ( ! is_user_logged_in() ) {
			return;
		}

		global $current_user;
		$preserve = get_user_meta( $current_user->ID, 'pmpro_stripe_dont_cancel', true );

		// Clean up the subscription timeout values (if applicable)
		if ( ! empty( $preserve ) ) {

			foreach ( $preserve as $sub_id => $timestamp ) {

				// Make sure the ID has "timed out" (more than 3 days since it was last updated/added.
				if ( intval( $timestamp ) >= ( current_time( 'timestamp' ) + ( 3 * DAY_IN_SECONDS ) ) ) {
					unset( $preserve[ $sub_id ] );
				}
			}

			update_user_meta( $current_user->ID, 'pmpro_stripe_dont_cancel', $preserve );
		}
	}

	/**
	 * Make sure Stripe is in the gateways list
	 *
	 * @since 1.8
	 */
	public static function pmpro_gateways( $gateways ) {
		if ( empty( $gateways['etsstripe'] ) ) {
			$gateways['etsstripe'] = __( 'Ets Stripe', 'paid-memberships-pro' );
		}

		return $gateways;
	}

	/**
	 * Get a list of payment options that the Stripe gateway needs/supports.
	 *
	 * @since 1.8
	 */
	public static function getGatewayOptions() {
		$options = array(
			'sslseal',
			'nuclear_HTTPS',
			'gateway_environment',
			'stripe_secretkey',
			'stripe_publishablekey',
			'live_stripe_connect_user_id',
			'live_stripe_connect_secretkey',
			'live_stripe_connect_publishablekey',
			'sandbox_stripe_connect_user_id',
			'sandbox_stripe_connect_secretkey',
			'sandbox_stripe_connect_publishablekey',
			'stripe_webhook',
			'stripe_billingaddress',
			'currency',
			'use_ssl',
			'tax_state',
			'tax_rate',
			'accepted_credit_cards',
			'stripe_payment_request_button',
		);

		return $options;
	}

	/**
	 * Set payment options for payment settings page.
	 *
	 * @since 1.8
	 */
	public static function pmpro_payment_options( $options ) {
		//get stripe options
		$stripe_options = self::getGatewayOptions();

		//merge with others.
		$options = array_merge( $stripe_options, $options );

		return $options;
	}

	/**
	 * Display fields for Stripe options.
	 *
	 * @since 1.8
	 */
	public static function pmpro_payment_option_fields( $values, $gateway ) {
		$stripe = new PMProGateway_etsStripe();

		// Show connect fields.
		$stripe->show_connect_payment_option_fields( true, $values, $gateway ); // Show live connect fields.
		$stripe->show_connect_payment_option_fields( false, $values, $gateway ); // Show sandbox connect fields.

		if ( self::using_legacy_keys() ) {
			// Check if webhook is enabled or not.
			$webhook = self::does_webhook_exist();

			// Check to see if events are missing.
			if ( is_array( $webhook ) && isset( $webhook['enabled_events'] ) ) {
				$events = self::check_missing_webhook_events( $webhook['enabled_events'] );
				if ( $events ) {
					self::update_webhook_events();
				}
			}

			// Break the country cache in case new credentials were saved.
			delete_transient( 'pmpro_stripe_account_country' );
		}
		var_dump($gateway);
		?>
			<tr class="pmpro_settings_divider gateway gateway_etsstripe" <?php if ( $gateway != "etsstripe" ) { ?>style="display: none;"<?php } ?>>		
			<td colspan="2">
				<hr />
				<h2 class="pmpro_stripe_legacy_keys" <?php if( ! self::show_legacy_keys_settings() ) {?>style="display: none;"<?php }?>><?php esc_html_e( 'Stripe API Settings (Legacy)', 'paid-memberships-pro' ); ?></h2>
				<?php if( ! self::show_legacy_keys_settings() ) {?>
				<p>
					<?php esc_html_e( 'Having trouble connecting through the button above or otherwise need to use your own API keys?', 'paid-memberships-pro' );?>
					<a id="pmpro_stripe_legacy_keys_toggle" href="javascript:void(0);"><?php esc_html_e( 'Click here to use the legacy API settings.', 'paid-memberships-pro' );?></a>
				</p>
				<script>
					// Toggle to show the Stripe legacy keys settings.
					jQuery(document).ready(function(){
						jQuery('#pmpro_stripe_legacy_keys_toggle').click(function(e){
							var btn = jQuery('#pmpro_stripe_legacy_keys_toggle');
							var div = btn.closest('.pmpro_settings_divider');							
							btn.parent().remove();
							jQuery('.pmpro_stripe_legacy_keys').show();
							jQuery('.pmpro_stripe_legacy_keys').addClass('gateway_stripe');
							jQuery('#stripe_publishablekey').focus();
						});
					});
				</script>
				<?php } ?>
			</td>
		</tr>
		<tr class="gateway pmpro_stripe_legacy_keys <?php if ( self::show_legacy_keys_settings() ) { echo 'gateway_etsstripe'; } ?>" <?php if ( $gateway != "etsstripe" || ! self::show_legacy_keys_settings() ) { ?>style="display: none;"<?php } ?>>
			<th scope="row" valign="top">
				<label for="stripe_publishablekey"><?php _e( 'Publishable Key', 'paid-memberships-pro' ); ?>:</label>
			</th>
			<td>
				<input type="text" id="stripe_publishablekey" name="stripe_publishablekey" value="<?php echo esc_attr( $values['stripe_publishablekey'] ) ?>" class="regular-text code" />
				<?php
				$public_key_prefix = substr( $values['stripe_publishablekey'], 0, 3 );
				if ( ! empty( $values['stripe_publishablekey'] ) && $public_key_prefix != 'pk_' ) {
					?>
					<p class="pmpro_red"><strong><?php _e( 'Your Publishable Key appears incorrect.', 'paid-memberships-pro' ); ?></strong></p>
					<?php
				}
				?>
			</td>
		</tr>
		<tr class="gateway pmpro_stripe_legacy_keys <?php if ( self::show_legacy_keys_settings() ) { echo 'gateway_etsstripe'; } ?>" <?php if ( $gateway != "etsstripe" ||  ! self::show_legacy_keys_settings() ) { ?>style="display: none;"<?php } ?>>
			<th scope="row" valign="top">
				<label for="stripe_secretkey"><?php _e( 'Secret Key', 'paid-memberships-pro' ); ?>:</label>
			</th>
			<td>
				<input type="text" id="stripe_secretkey" name="stripe_secretkey" value="<?php echo esc_attr( $values['stripe_secretkey'] ) ?>" autocomplete="off" class="regular-text code pmpro-admin-secure-key" />
			</td>
		</tr>		
		<tr class="gateway pmpro_stripe_legacy_keys <?php if ( self::show_legacy_keys_settings() ) { echo 'gateway_etsstripe'; } ?>" <?php if ( $gateway != "etsstripe" || ! self::show_legacy_keys_settings() ) { ?>style="display: none;"<?php } ?>>
			<th scope="row" valign="top">
				<label><?php esc_html_e( 'Webhook', 'paid-memberships-pro' ); ?>:</label>
			</th>
			<td>
				<?php if ( ! empty( $webhook ) && is_array( $webhook ) && self::show_legacy_keys_settings()) { ?>
				<button type="button" id="pmpro_stripe_create_webhook" class="button button-secondary" style="display: none;"><span class="dashicons dashicons-update-alt"></span> <?php _e( 'Create Webhook' ,'paid-memberships-pro' ); ?></button>
					<?php 
						if ( 'disabled' === $webhook['status'] ) {
							// Check webhook status.
							?>
							<div class="notice error inline">
								<p id="pmpro_stripe_webhook_notice" class="pmpro_stripe_webhook_notice"><?php _e( 'A webhook is set up in Stripe, but it is disabled.', 'paid-memberships-pro' ); ?> <a id="pmpro_stripe_rebuild_webhook" href="#">Rebuild Webhook</a></p>
							</div>
							<?php
						} elseif ( $webhook['api_version'] < PMPRO_ETS_STRIPE_API_VERSION ) {
							// Check webhook API version.
							?>
							<div class="notice error inline">
								<p id="pmpro_stripe_webhook_notice" class="pmpro_stripe_webhook_notice"><?php _e( 'A webhook is set up in Stripe, but it is using an old API version.', 'paid-memberships-pro' ); ?> <a id="pmpro_stripe_rebuild_webhook" href="#"><?php _e( 'Rebuild Webhook', 'paid-memberships-pro' ); ?></a></p>
							</div>
							<?php
						} else {
							?>
							<div class="notice notice-success inline">
								<p id="pmpro_stripe_webhook_notice" class="pmpro_stripe_webhook_notice"><?php _e( 'Your webhook is enabled.', 'paid-memberships-pro' ); ?> <a id="pmpro_stripe_delete_webhook" href="#"><?php _e( 'Disable Webhook', 'paid-memberships-pro' ); ?></a></p>
							</div>
							<?php
						}
					} elseif ( self::show_legacy_keys_settings() ) { ?>
						<button type="button" id="pmpro_stripe_create_webhook" class="button button-secondary"><span class="dashicons dashicons-update-alt"></span> <?php _e( 'Create Webhook' ,'paid-memberships-pro' ); ?></button>
						<div class="notice error inline">
							<p id="pmpro_stripe_webhook_notice" class="pmpro_stripe_webhook_notice"><?php _e('A webhook in Stripe is required to process recurring payments, manage failed payments, and synchronize cancellations.', 'paid-memberships-pro' );?></p>
						</div>
						<?php
					}
				?>
				<p class="description"><?php esc_html_e( 'Webhook URL', 'paid-memberships-pro' ); ?>:
				<code><?php echo self::get_site_webhook_url(); ?></code></p>
			</td>
		</tr>
		<tr class="pmpro_settings_divider gateway gateway_etsstripe" <?php if ( $gateway != "etsstripe" ) { ?>style="display: none;"<?php } ?>>		
			<td colspan="2">
				<hr />
				<h2><?php esc_html_e( 'Other Stripe Settings', 'paid-memberships-pro' ); ?></h2>				
			</td>
		</tr>
		<tr class="gateway gateway_etsstripe" <?php if ( $gateway != "etsstripe" ) { ?>style="display: none;"<?php } ?>>
			<th><?php _e( 'Stripe API Version', 'paid-memberships-pro' ); ?>:</th>
			<td><code><?php echo PMPRO_ETS_STRIPE_API_VERSION; ?></code></td>
		</tr>
		<tr class="gateway gateway_stripe" <?php if ( $gateway != "etsstripe" ) { ?>style="display: none;"<?php } ?>>
			<th scope="row" valign="top">
				<label for="stripe_billingaddress"><?php _e( 'Show Billing Address Fields', 'paid-memberships-pro' ); ?>:</label>
			</th>
			<td>
				<select id="stripe_billingaddress" name="stripe_billingaddress">
					<option value="0"
							<?php if ( empty( $values['stripe_billingaddress'] ) ) { ?>selected="selected"<?php } ?>><?php _e( 'No', 'paid-memberships-pro' ); ?></option>
					<option value="1"
							<?php if ( ! empty( $values['stripe_billingaddress'] ) ) { ?>selected="selected"<?php } ?>><?php _e( 'Yes', 'paid-memberships-pro' ); ?></option>
				</select>
				<p class="description"><?php _e( "Stripe doesn't require billing address fields. Choose 'No' to hide them on the checkout page.<br /><strong>If No, make sure you disable address verification in the Stripe dashboard settings.</strong>", 'paid-memberships-pro' ); ?></p>
			</td>
		</tr>
		<tr class="gateway gateway_stripe" <?php if ( $gateway != "etsstripe" ) { ?>style="display: none;"<?php } ?>>
			<th scope="row" valign="top">
				<label for="stripe_payment_request_button"><?php _e( 'Enable Payment Request Button', 'paid-memberships-pro' ); ?>:</label>
			</th>
			<td>
				<select id="stripe_payment_request_button" name="stripe_payment_request_button">
					<option value="0"
							<?php if ( empty( $values['stripe_payment_request_button'] ) ) { ?>selected="selected"<?php } ?>><?php _e( 'No', 'paid-memberships-pro' ); ?></option>
					<option value="1"
							<?php if ( ! empty( $values['stripe_payment_request_button'] ) ) { ?>selected="selected"<?php } ?>><?php _e( 'Yes', 'paid-memberships-pro' ); ?></option>
				</select>
				<?php
					$allowed_stripe_payment_button_html = array (
						'a' => array (
							'href' => array(),
							'target' => array(),
							'title' => array(),
						),
					);
				?>
				<p class="description"><?php echo sprintf( wp_kses( __( 'Allow users to pay using Apple Pay, Google Pay, or Microsoft Pay depending on their browser. When enabled, your domain will automatically be registered with Apple and a domain association file will be hosted on your site. <a target="_blank" href="%s" title="More Information about the domain association file for Apple Pay">More Information &raquo;</a>', 'paid-memberships-pro' ), $allowed_stripe_payment_button_html ), 'https://stripe.com/docs/stripe-js/elements/payment-request-button#verifying-your-domain-with-apple-pay' ); ?></p>
					<?php
					if ( ! empty( $values['stripe_payment_request_button'] ) ) {
						// Are there any issues with how the payment request button is set up?
						$payment_request_error = null;
						$allowed_payment_request_error_html = array (
							'a' => array (
								'href' => array(),
								'target' => array(),
								'title' => array(),
							),
						);
						if ( empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === "off" ) {
							$payment_request_error = sprintf( wp_kses( __( 'This webpage is being served over HTTP, but the Stripe Payment Request Button will only work on pages being served over HTTPS. To resolve this, you must <a target="_blank" href="%s" title="Configuring WordPress to Always Use HTTPS/SSL">set up WordPress to always use HTTPS</a>.', 'paid-memberships-pro' ), $allowed_payment_request_error_html ), 'https://www.paidmembershipspro.com/configuring-wordpress-always-use-httpsssl/?utm_source=plugin&utm_medium=pmpro-paymentsettings&utm_campaign=blog&utm_content=configure-https' );
						} elseif ( self::using_legacy_keys() && substr( $values['stripe_publishablekey'], 0, 8 ) !== "pk_live_" && substr( $values['stripe_publishablekey'], 0, 8 ) !== "pk_test_" ) {
							$payment_request_error = sprintf( wp_kses( __( 'It looks like you are using an older Stripe publishable key. In order to use the Payment Request Button feature, you will need to update your API key, which will be prefixed with "pk_live_" or "pk_test_". <a target="_blank" href="%s" title="Stripe Dashboard API Key Settings">Log in to your Stripe Dashboard to roll your publishable key</a>.', 'paid-memberships-pro' ), $allowed_payment_request_error_html ), 'https://dashboard.stripe.com/account/apikeys' );
						} elseif ( self::using_legacy_keys() && substr( $values['stripe_secretkey'], 0, 8 ) !== "sk_live_" && substr( $values['stripe_secretkey'], 0, 8 ) !== "sk_test_" ) {
							$payment_request_error = sprintf( wp_kses( __( 'It looks like you are using an older Stripe secret key. In order to use the Payment Request Button feature, you will need to update your API key, which will be prefixed with "sk_live_" or "sk_test_". <a target="_blank" href="%s" title="Stripe Dashboard API Key Settings">Log in to your Stripe Dashboard to roll your secret key</a>.', 'paid-memberships-pro' ), $allowed_payment_request_error_html ), 'https://dashboard.stripe.com/account/apikeys' );
						} elseif ( ! $stripe->pmpro_does_apple_pay_domain_exist() ) {
							$payment_request_error = sprintf( wp_kses( __( 'Your domain could not be registered with Apple to enable Apple Pay. Please try <a target="_blank" href="%s" title="Apple Pay Settings Page in Stripe">registering your domain manually from the Apple Pay settings page in Stripe</a>.', 'paid-memberships-pro' ), $allowed_payment_request_error_html ), 'https://dashboard.stripe.com/settings/payments/apple_pay' );
						}
						if ( ! empty( $payment_request_error ) ) {
							?>
							<div class="notice error inline">
								<p id="pmpro_stripe_payment_request_button_notice"><?php echo( $payment_request_error ); ?></p>
							</div>
							<?php
						}
					}
					?>
			</td>
		</tr>
		<?php if ( ! function_exists( 'pmproappe_pmpro_valid_gateways' ) ) {
				$allowed_appe_html = array (
					'a' => array (
						'href' => array(),
						'target' => array(),
						'title' => array(),
					),
				);
				echo '<tr class="gateway gateway_stripe"';
				if ( $gateway != "etsstripe" ) { 
					echo ' style="display: none;"';
				}
				echo '><th>&nbsp;</th><td><p class="description">' . sprintf( wp_kses( __( 'Optional: Offer PayPal Express as an option at checkout using the <a target="_blank" href="%s" title="Paid Memberships Pro - Add PayPal Express Option at Checkout Add On">Add PayPal Express Add On</a>.', 'paid-memberships-pro' ), $allowed_appe_html ), 'https://www.paidmembershipspro.com/add-ons/pmpro-add-paypal-express-option-checkout/?utm_source=plugin&utm_medium=pmpro-paymentsettings&utm_campaign=add-ons&utm_content=pmpro-add-paypal-express-option-checkout' ) . '</p></td></tr>';
		} ?>
		<?php
	}

	/**
	 * AJAX callback to create webhooks.
	 */
	public static function wp_ajax_pmpro_stripe_create_webhook( $silent = false ) {
		$secretkey = sanitize_text_field( $_REQUEST['secretkey'] );
		
		$stripe = new PMProGateway_etsStripe();
		Stripe\Stripe::setApiKey( $secretkey );
		
		$update_webhook_response = $stripe::update_webhook_events();

		if ( empty( $update_webhook_response ) || is_wp_error( $update_webhook_response ) ) {
			$message = empty( $update_webhook_response ) ? __( 'Webhook creation failed. You might already have a webhook set up.', 'paid-memberships-pro' ) : $update_webhook_response->get_error_message();
			$r = array(
				'success' => false,
				'notice' => 'error',
				'message' => $message,
				'response' => $update_webhook_response
			);
		} else {
			$r = array(
				'success' => true,
				'notice' => 'notice-success',
				'message' => __( 'Your webhook is enabled.', 'paid-memberships-pro' ),
				'response' => $update_webhook_response
			);
		}
		
		if ( $silent ) {
			return $r;
		} else {
			echo json_encode( $r );
			exit;
		}
	}
	
	/**
	 * AJAX callback to disable webhooks.
	 */
	public static function wp_ajax_pmpro_stripe_delete_webhook( $silent = false ) {
		$secretkey = sanitize_text_field( $_REQUEST['secretkey'] );
		
		$stripe = new PMProGateway_etsStripe();
		Stripe\Stripe::setApiKey( $secretkey );
		
		$webhook = self::does_webhook_exist();

		$r = array(
			'success' => true,
			'notice' => 'error',
			'message' => __( 'A webhook in Stripe is required to process recurring payments, manage failed payments, and synchronize cancellations.', 'paid-memberships-pro' )
		);
		if ( ! empty( $webhook ) ) {
			$delete_webhook_response = $stripe::delete_webhook( $webhook, $secretkey );

			if ( is_wp_error( $delete_webhook_response ) || empty( $delete_webhook_response['deleted'] ) || $delete_webhook_response['deleted'] != true ) {
				$message = is_wp_error( $delete_webhook_response ) ? $delete_webhook_response->get_error_message() : __( 'There was an error deleting the webhook.', 'paid-memberships-pro' );
				$r = array(
					'success' => false,
					'notice' => 'error',
					'message' => $message,
				);
			}
			$r['response'] = $delete_webhook_response;
		}

		if ( $silent ) {
			return $r;
		} else {
			echo json_encode( $r );
			exit;
		}
	}

	/**
	 * AJAX callback to rebuild webhook.
	 */
	public static function wp_ajax_pmpro_stripe_rebuild_webhook() {
		// First try to delete the webhook.
		$r = self::wp_ajax_pmpro_stripe_delete_webhook( true ) ;
		if ( $r['success'] ) {
			// Webhook was successfully deleted. Now make a new one.
			$r = self::wp_ajax_pmpro_stripe_create_webhook( true );
			if ( ! $r['success'] ) {
				$r['message'] = __( 'Webhook creation failed. Please refresh and try again.', 'paid-memberships-pro' );
			}
		}

		echo json_encode( $r );
		exit;
	}

	/**
	 * Code added to checkout preheader.
	 *
	 * @since 1.8
	 */
	public static function pmpro_checkout_after_preheader( $order ) {
		global $gateway, $pmpro_level, $current_user, $pmpro_requirebilling, $pmpro_pages, $pmpro_currency;

		$default_gateway = pmpro_getOption( "gateway" );

		if ( $gateway == "etsstripe" || $default_gateway == "etsstripe" ) {
			//stripe js library
			wp_enqueue_script( "etsstripe", "https://js.stripe.com/v3/", array(), null );

			if ( ! function_exists( 'pmpro_stripe_javascript' ) ) {
				$localize_vars = array(
					'publishableKey' => self::get_publishablekey(),
					'user_id'        => self::get_connect_user_id(),
					'verifyAddress'  => apply_filters( 'pmpro_stripe_verify_address', pmpro_getOption( 'stripe_billingaddress' ) ),
					'ajaxUrl'        => admin_url( "admin-ajax.php" ),
					'msgAuthenticationValidated' => __( 'Verification steps confirmed. Your payment is processing.', 'paid-memberships-pro' ),
					'pmpro_require_billing' => $pmpro_requirebilling,
					'restUrl' => get_rest_url(),
					'siteName' => get_bloginfo( 'name' ),
					'updatePaymentRequestButton' => apply_filters( 'pmpro_stripe_update_payment_request_button', true ),
					'currency' => strtolower( $pmpro_currency ),
					'accountCountry' => self::get_account_country(),
				);

				if ( ! empty( $order ) ) {
					if ( ! empty( $order->stripe_payment_intent ) ) {
						$localize_vars['paymentIntent'] = $order->stripe_payment_intent;
					}
					if ( ! empty( $order->stripe_setup_intent ) ) {
						$localize_vars['setupIntent']  = $order->stripe_setup_intent;
					}
				}

				wp_register_script( 'pmpro_stripe',
					plugins_url( 'js/pmpro-stripe.js', PMPRO_BASE_FILE ),
					array( 'jquery' ),
					PMPRO_VERSION );
				wp_localize_script( 'pmpro_stripe', 'pmproStripe', $localize_vars );
				wp_enqueue_script( 'pmpro_stripe' );
			}
		}
	}

	/**
	 * Don't require the CVV.
	 * Don't require address fields if they are set to hide.
	 */
	public static function pmpro_required_billing_fields( $fields ) {
		global $pmpro_stripe_lite, $current_user, $bemail, $bconfirmemail;

		//CVV is not required if set that way at Stripe. The Stripe JS will require it if it is required.
		unset( $fields['CVV'] );

		//if using stripe lite, remove some fields from the required array
		if ( $pmpro_stripe_lite ) {
			//some fields to remove
			$remove = array(
				'bfirstname',
				'blastname',
				'baddress1',
				'bcity',
				'bstate',
				'bzipcode',
				'bphone',
				'bcountry',
				'CardType'
			);
			//if a user is logged in, don't require bemail either
			if ( ! empty( $current_user->user_email ) ) {
				$remove[]      = 'bemail';
				$bemail        = $current_user->user_email;
				$bconfirmemail = $bemail;
			}
			//remove the fields
			foreach ( $remove as $field ) {
				unset( $fields[ $field ] );
			}
		}

		return $fields;
	}

	/**
	 * Filtering orders at checkout.
	 *
	 * @since 1.8
	 */
	public static function pmpro_checkout_order( $morder ) {

		// Create a code for the order.
		if ( empty( $morder->code ) ) {
			$morder->code = $morder->getRandomCode();
		}

		// Add the PaymentIntent ID to the order.
		if ( ! empty ( $_REQUEST['payment_intent_id'] ) ) {
			$morder->payment_intent_id = sanitize_text_field( $_REQUEST['payment_intent_id'] );
		}

		// Add the SetupIntent ID to the order.
		if ( ! empty ( $_REQUEST['setup_intent_id'] ) ) {
			$morder->setup_intent_id = sanitize_text_field( $_REQUEST['setup_intent_id'] );
		}

		// Add the PaymentMethod ID to the order.
		if ( ! empty ( $_REQUEST['payment_method_id'] ) ) {
			$morder->payment_method_id = sanitize_text_field( $_REQUEST['payment_method_id'] );
		}

		//stripe lite code to get name from other sources if available
		global $pmpro_stripe_lite, $current_user;
		if ( ! empty( $pmpro_stripe_lite ) && empty( $morder->FirstName ) && empty( $morder->LastName ) ) {
			if ( ! empty( $current_user->ID ) ) {
				$morder->FirstName = get_user_meta( $current_user->ID, "first_name", true );
				$morder->LastName  = get_user_meta( $current_user->ID, "last_name", true );
			} elseif ( ! empty( $_REQUEST['first_name'] ) && ! empty( $_REQUEST['last_name'] ) ) {
				$morder->FirstName = sanitize_text_field( $_REQUEST['first_name'] );
				$morder->LastName  = sanitize_text_field( $_REQUEST['last_name'] );
			}
		}

		return $morder;
	}

	/**
	 * Code to run after checkout
	 *
	 * @since 1.8
	 */
	public static function pmpro_after_checkout( $user_id, $morder ) {
		global $gateway;

		if ( $gateway == "etsstripe" ) {
			if ( self::$is_loaded && ! empty( $morder ) && ! empty( $morder->Gateway ) && ! empty( $morder->Gateway->customer ) && ! empty( $morder->Gateway->customer->id ) ) {
				update_user_meta( $user_id, "pmpro_stripe_customerid", $morder->Gateway->customer->id );
			}
		}
	}

	/**
	 * Check settings if billing address should be shown.
	 * @since 1.8
	 */
	public static function pmpro_include_billing_address_fields( $include ) {
		//check settings RE showing billing address
		if ( ! pmpro_getOption( "stripe_billingaddress" ) ) {
			$include = false;
		}

		return $include;
	}

	/**
	 * Use our own payment fields at checkout. (Remove the name attributes.)
	 * @since 1.8
	 */
	public static function pmpro_include_payment_information_fields( $include ) {
		//global vars
		global $pmpro_requirebilling, $pmpro_show_discount_code, $discount_code, $CardType, $AccountNumber, $ExpirationMonth, $ExpirationYear;

		//get accepted credit cards
		$pmpro_accepted_credit_cards        = pmpro_getOption( "accepted_credit_cards" );
		$pmpro_accepted_credit_cards        = explode( ",", $pmpro_accepted_credit_cards );
		$pmpro_accepted_credit_cards_string = pmpro_implodeToEnglish( $pmpro_accepted_credit_cards );

		//include ours
		?>
        <div id="pmpro_payment_information_fields" class="<?php echo pmpro_get_element_class( 'pmpro_checkout', 'pmpro_payment_information_fields' ); ?>"
		     <?php if ( ! $pmpro_requirebilling || apply_filters( "pmpro_hide_payment_information_fields", false ) ) { ?>style="display: none;"<?php } ?>>
            <h3>
                <span class="<?php echo pmpro_get_element_class( 'pmpro_checkout-h3-name' ); ?>"><?php _e( 'Payment Information', 'paid-memberships-pro' ); ?></span>
                <span class="<?php echo pmpro_get_element_class( 'pmpro_checkout-h3-msg' ); ?>"><?php printf( __( 'We Accept %s', 'paid-memberships-pro' ), $pmpro_accepted_credit_cards_string ); ?></span>
            </h3>
			<?php $sslseal = pmpro_getOption( "sslseal" ); ?>
			<?php if ( ! empty( $sslseal ) ) { ?>
            <div class="<?php echo pmpro_get_element_class( 'pmpro_checkout-fields-display-seal' ); ?>">
				<?php } ?>
		<?php
			if ( pmpro_getOption( 'stripe_payment_request_button' ) ) { ?>
				<div class="<?php echo pmpro_get_element_class( 'pmpro_checkout-field pmpro_checkout-field-payment-request-button', 'pmpro_checkout-field-payment-request-button' ); ?>">
					<div id="payment-request-button"><!-- Alternate payment method will be inserted here. --></div>
					<h4 class="<?php echo pmpro_get_element_class( 'pmpro_checkout-field pmpro_payment-credit-card', 'pmpro_payment-credit-card' ); ?>"><?php esc_html_e( 'Pay with Credit Card', 'paid-memberships-pro' ); ?></h4>
				</div>
				<?php
			}
		?>
                <div class="pmpro_checkout-fields<?php if ( ! empty( $sslseal ) ) { ?> pmpro_checkout-fields-leftcol<?php } ?>">
					<?php
					$pmpro_include_cardtype_field = apply_filters( 'pmpro_include_cardtype_field', false );
					if ( $pmpro_include_cardtype_field ) { ?>
                        <div class="<?php echo pmpro_get_element_class( 'pmpro_checkout-field pmpro_payment-card-type', 'pmpro_payment-card-type' ); ?>">
                            <label for="CardType"><?php _e( 'Card Type', 'paid-memberships-pro' ); ?></label>
                            <select id="CardType" class="<?php echo pmpro_get_element_class( 'CardType' ); ?>">
								<?php foreach ( $pmpro_accepted_credit_cards as $cc ) { ?>
                                    <option value="<?php echo $cc ?>"
									        <?php if ( $CardType == $cc ) { ?>selected="selected"<?php } ?>><?php echo $cc ?></option>
								<?php } ?>
                            </select>
                        </div>
					<?php } else { ?>
                        <input type="hidden" id="CardType" name="CardType"
                               value="<?php echo esc_attr( $CardType ); ?>"/>
					<?php } ?>
                    <div class="<?php echo pmpro_get_element_class( 'pmpro_checkout-field pmpro_payment-account-number', 'pmpro_payment-account-number' ); ?>">
                        <label for="AccountNumber"><?php _e( 'Card Number', 'paid-memberships-pro' ); ?></label>
                        <div id="AccountNumber"></div>
                    </div>
                    <div class="<?php echo pmpro_get_element_class( 'pmpro_checkout-field pmpro_payment-expiration', 'pmpro_payment-expiration' ); ?>">
                        <label for="Expiry"><?php _e( 'Expiration Date', 'paid-memberships-pro' ); ?></label>
                        <div id="Expiry"></div>
                    </div>
					<?php
					$pmpro_show_cvv = apply_filters( "pmpro_show_cvv", true );
					if ( $pmpro_show_cvv ) { ?>
                        <div class="<?php echo pmpro_get_element_class( 'pmpro_checkout-field pmpro_payment-cvv', 'pmpro_payment-cvv' ); ?>">
                            <label for="CVV"><?php _e( 'CVC', 'paid-memberships-pro' ); ?></label>
                            <div id="CVV"></div>
                        </div>
					<?php } ?>
					<?php if ( $pmpro_show_discount_code ) { ?>
                        <div class="<?php echo pmpro_get_element_class( 'pmpro_checkout-field pmpro_payment-discount-code', 'pmpro_payment-discount-code' ); ?>">
                            <label for="discount_code"><?php _e( 'Discount Code', 'paid-memberships-pro' ); ?></label>
                            <input class="<?php echo pmpro_get_element_class( 'input pmpro_alter_price', 'discount_code' ); ?>"
                                   id="discount_code" name="discount_code" type="text" size="10"
                                   value="<?php echo esc_attr( $discount_code ) ?>"/>
                            <input type="button" id="discount_code_button" name="discount_code_button"
                                   value="<?php _e( 'Apply', 'paid-memberships-pro' ); ?>"/>
                            <p id="discount_code_message" class="<?php echo pmpro_get_element_class( 'pmpro_message' ); ?>" style="display: none;"></p>
                        </div>
					<?php } ?>
                </div> <!-- end pmpro_checkout-fields -->
				<?php if ( ! empty( $sslseal ) ) { ?>
                <div class="<?php echo pmpro_get_element_class( 'pmpro_checkout-fields-rightcol pmpro_sslseal', 'pmpro_sslseal' ); ?>"><?php echo stripslashes( $sslseal ); ?></div>
            </div> <!-- end pmpro_checkout-fields-display-seal -->
		<?php } ?>
        </div> <!-- end pmpro_payment_information_fields -->
		<?php

		//don't include the default
		return false;
	}

	/**
	 * Fields shown on edit user page
	 *
	 * @since 1.8
	 */
	public static function user_profile_fields( $user ) {
		global $wpdb, $current_user, $pmpro_currency_symbol;

		//make sure the current user has privileges
		$membership_level_capability = apply_filters( "pmpro_edit_member_capability", "manage_options" );
		if ( ! current_user_can( $membership_level_capability ) ) {
			return false;
		}

		//more privelges they should have
		$show_membership_level = apply_filters( "pmpro_profile_show_membership_level", true, $user );
		if ( ! $show_membership_level ) {
			return false;
		}

		// Get the user's Stripe Customer if they have one.
		$stripe = new PMProGateway_etsStripe();
		$customer = $stripe->get_customer_for_user( $user->ID );

		// Check whether we have a Stripe Customer.
		if ( ! empty( $customer ) ) {
			// Get the link to edit the customer.
			echo '<hr>';
			echo '<a target="_blank" href="' . esc_url( 'https://dashboard.stripe.com/' . ( pmpro_getOption( 'gateway_environment' ) == 'sandbox' ? 'test/' : '' ) . 'customers/' . $customer->id ) . '">' . esc_html__( 'Edit customer in Stripe', 'paid-memberships-pro' ) . '</a>';
			if ( ! empty( $user->pmpro_stripe_updates ) && is_array( $user->pmpro_stripe_updates ) ) {
				$stripe->user_profile_fields_subscription_updates( $user, $customer );
			}
		}
	}

	/**
	 * Temporary function to allow users to delete subscription updates.
	 * Will be removed once subscription updates are completely deprecated.
	 *
	 * @since 2.7.0.
	 */
	static function user_profile_fields_save( $user_id ) {
		global $wpdb;
		//check capabilities
		$membership_level_capability = apply_filters( "pmpro_edit_member_capability", "manage_options" );
		if ( ! current_user_can( $membership_level_capability ) ) {
			return false;
		}

		//make sure subscription updates were shown.
		if ( ! isset( $_POST['pmpro_subscription_updates_visible'] ) ) {
			return;
		}

		// Check whether all updates were deleted.
		if ( ! isset( $_POST['updates_when'] ) || ! is_array( $_POST['updates_when'] ) ) {
			delete_user_meta( $user_id, 'pmpro_stripe_updates' );
			delete_user_meta( $user_id, 'pmpro_stripe_next_on_date_update' );
			return;
		}

		//vars
		$updates             = array();
		$next_on_date_update = "";

		//build array of updates
		for ( $i = 0; $i < count( $_POST['updates_when'] ); $i ++ ) {
			$update = array();

			//all updates have these values
			$update['when']           = pmpro_sanitize_with_safelist( $_POST['updates_when'][ $i ], array(
				'now',
				'payment',
				'date'
			) );
			$update['billing_amount'] = sanitize_text_field( $_POST['updates_billing_amount'][ $i ] );
			$update['cycle_number']   = intval( $_POST['updates_cycle_number'][ $i ] );
			$update['cycle_period']   = sanitize_text_field( $_POST['updates_cycle_period'][ $i ] );

			//these values only for on date updates
			if ( $_POST['updates_when'][ $i ] == "date" ) {
				$update['date_month'] = str_pad( intval( $_POST['updates_date_month'][ $i ] ), 2, "0", STR_PAD_LEFT );
				$update['date_day']   = str_pad( intval( $_POST['updates_date_day'][ $i ] ), 2, "0", STR_PAD_LEFT );
				$update['date_year']  = intval( $_POST['updates_date_year'][ $i ] );
			}

			//make sure the update is valid
			if ( empty( $update['cycle_number'] ) ) {
				continue;
			}

			//if when is now, update the subscription
			if ( $update['when'] == "now" ) {
				self::updateSubscription( $update, $user_id );

				continue;
			} elseif ( $update['when'] == 'date' ) {
				if ( ! empty( $next_on_date_update ) ) {
					$next_on_date_update = min( $next_on_date_update, $update['date_year'] . "-" . $update['date_month'] . "-" . $update['date_day'] );
				} else {
					$next_on_date_update = $update['date_year'] . "-" . $update['date_month'] . "-" . $update['date_day'];
				}
			}

			//add to array
			$updates[] = $update;
		}

		//save in user meta
		update_user_meta( $user_id, "pmpro_stripe_updates", $updates );

		//save date of next on-date update to make it easier to query for these in cron job
		update_user_meta( $user_id, "pmpro_stripe_next_on_date_update", $next_on_date_update );
	}

	/**
	 * Cron activation for subscription updates.
	 *
	 * The subscription updates menu is no longer accessible as of v2.6.
	 * This function is staying to process subscription updates that were already queued.
	 *
	 * @since 1.8
	 */
	public static function pmpro_activation() {
		pmpro_maybe_schedule_event( time(), 'daily', 'pmpro_cron_stripe_subscription_updates' );
	}

	/**
	 * Cron deactivation for subscription updates.
	 *
	 * The subscription updates menu is no longer accessible as of v2.6.
	 * This function is staying to process subscription updates that were already queued.
	 *
	 * @since 1.8
	 */
	public static function pmpro_deactivation() {
		wp_clear_scheduled_hook( 'pmpro_cron_stripe_subscription_updates' );
	}

	/**
	 * Cron job for subscription updates.
	 *
	 * The subscription updates menu is no longer accessible as of v2.6.
	 * This function is staying to process subscription updates that were already queued.
	 *
	 * @since 1.8
	 */
	public static function pmpro_cron_stripe_subscription_updates() {
		global $wpdb;

		//get all updates for today (or before today)
		$sqlQuery = "SELECT *
					 FROM $wpdb->usermeta
					 WHERE meta_key = 'pmpro_stripe_next_on_date_update'
						AND meta_value IS NOT NULL
						AND meta_value <> ''
						AND meta_value < '" . date_i18n( "Y-m-d", strtotime( "+1 day", current_time( 'timestamp' ) ) ) . "'";
		$updates  = $wpdb->get_results( $sqlQuery );

		if ( ! empty( $updates ) ) {
			//loop through
			foreach ( $updates as $update ) {
				//pull values from update
				$user_id = $update->user_id;

				$user = get_userdata( $user_id );

				//if user is missing, delete the update info and continue
				if ( empty( $user ) || empty( $user->ID ) ) {
					delete_user_meta( $user_id, "pmpro_stripe_updates" );
					delete_user_meta( $user_id, "pmpro_stripe_next_on_date_update" );

					continue;
				}

				$user_updates        = $user->pmpro_stripe_updates;
				$next_on_date_update = "";

				//loop through updates looking for updates happening today or earlier
				if ( ! empty( $user_updates ) ) {
					foreach ( $user_updates as $key => $ud ) {
						if ( $ud['when'] == 'date' &&
						     $ud['date_year'] . "-" . $ud['date_month'] . "-" . $ud['date_day'] <= date_i18n( "Y-m-d", current_time( 'timestamp' ) )
						) {
							self::updateSubscription( $ud, $user_id );

							//remove update from list
							unset( $user_updates[ $key ] );
						} elseif ( $ud['when'] == 'date' ) {
							//this is an on date update for the future, update the next on date update
							if ( ! empty( $next_on_date_update ) ) {
								$next_on_date_update = min( $next_on_date_update, $ud['date_year'] . "-" . $ud['date_month'] . "-" . $ud['date_day'] );
							} else {
								$next_on_date_update = $ud['date_year'] . "-" . $ud['date_month'] . "-" . $ud['date_day'];
							}
						}
					}
				}

				//save updates in case we removed some
				update_user_meta( $user_id, "pmpro_stripe_updates", $user_updates );

				//save date of next on-date update to make it easier to query for these in cron job
				update_user_meta( $user_id, "pmpro_stripe_next_on_date_update", $next_on_date_update );
			}
		}
	}

	/**
	 * Before processing a checkout, check for pending invoices we want to clean up.
	 * This prevents double billing issues in cases where Stripe has pending invoices
	 * because of an expired credit card/etc and a user checks out to renew their subscription
	 * instead of updating their billing information via the billing info page.
	 */
	public static function pmpro_checkout_before_processing() {
		global $wpdb, $current_user;

		// we're only worried about cases where the user is logged in
		if ( ! is_user_logged_in() ) {
			return;
		}

		// make sure we're checking out with Stripe
		$current_gateway = pmpro_getGateway();
		if ( $current_gateway != 'etsstripe' ) {
			return;
		}

		//check the $pmpro_cancel_previous_subscriptions filter
		//this is used in add ons like Gift Memberships to stop PMPro from cancelling old memberships
		$pmpro_cancel_previous_subscriptions = true;
		$pmpro_cancel_previous_subscriptions = apply_filters( 'pmpro_cancel_previous_subscriptions', $pmpro_cancel_previous_subscriptions );
		if ( ! $pmpro_cancel_previous_subscriptions ) {
			return;
		}

		//get user and membership level
		$membership_level = pmpro_getMembershipLevelForUser( $current_user->ID );

		//no level, then probably no subscription at Stripe anymore
		if ( empty( $membership_level ) ) {
			return;
		}

		/**
		 * Filter which levels to cancel at the gateway.
		 * MMPU will set this to all levels that are going to be cancelled during this checkout.
		 * Others may want to display this by add_filter('pmpro_stripe_levels_to_cancel_before_checkout', __return_false);
		 */
		$levels_to_cancel = apply_filters( 'pmpro_stripe_levels_to_cancel_before_checkout', array( $membership_level->id ), $current_user );

		foreach ( $levels_to_cancel as $level_to_cancel ) {
			//get the last order for this user/level
			$last_order = new MemberOrder();
			$last_order->getLastMemberOrder( $current_user->ID, 'success', $level_to_cancel, 'etsstripe' );

			//so let's cancel the user's susbcription
			if ( ! empty( $last_order ) && ! empty( $last_order->subscription_transaction_id ) ) {
				$subscription = $last_order->Gateway->get_subscription( $last_order->subscription_transaction_id );
				if ( ! empty( $subscription ) ) {
					$last_order->Gateway->cancelSubscriptionAtGateway( $subscription, true );

					//Stripe was probably going to cancel this subscription 7 days past the payment failure (maybe just one hour, use a filter for sure)
					$memberships_users_row = $wpdb->get_row( "SELECT * FROM $wpdb->pmpro_memberships_users WHERE user_id = '" . $current_user->ID . "' AND membership_id = '" . $level_to_cancel . "' AND status = 'active' LIMIT 1" );

					if ( ! empty( $memberships_users_row ) && ( empty( $memberships_users_row->enddate ) || $memberships_users_row->enddate == '0000-00-00 00:00:00' ) ) {
						/**
						 * Filter graced period days when canceling existing subscriptions at checkout.
						 *
						 * @param int $days Grace period defaults to 3 days
						 * @param object $membership Membership row from pmpro_memberships_users including membership_id, user_id, and enddate
						 *
						 * @since 1.9.4
						 *
						 */
						$days_grace  = apply_filters( 'pmpro_stripe_days_grace_when_canceling_existing_subscriptions_at_checkout', 3, $memberships_users_row );
						$new_enddate = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + 3600 * 24 * $days_grace );
						$wpdb->update( $wpdb->pmpro_memberships_users, array( 'enddate' => $new_enddate ), array(
							'user_id'       => $current_user->ID,
							'membership_id' => $level_to_cancel,
							'status'        => 'active'
						), array( '%s' ), array( '%d', '%d', '%s' ) );
					}
				}
			}
		}
	}

	/**
	 * Filter pmpro_next_payment to get date via API if possible
	 *
	 * @since 1.8.6
	 */
	public static function pmpro_next_payment( $timestamp, $user_id, $order_status ) {
		//find the last order for this user
		if ( ! empty( $user_id ) ) {
			//get last order
			$order = new MemberOrder();
			$order->getLastMemberOrder( $user_id, $order_status );

			//check if this is a Stripe order with a subscription transaction id
			if ( ! empty( $order->id ) && ! empty( $order->subscription_transaction_id ) && $order->gateway == "etsstripe" ) {
				//get the subscription and return the current_period end or false
				$subscription = $order->Gateway->get_subscription( $order->subscription_transaction_id );

				if ( ! empty( $subscription ) ) {
					$customer = $order->Gateway->get_customer_for_user( $user_id );
					if ( ! $customer->delinquent && ! empty ( $subscription->current_period_end ) ) {
						$offset = get_option( 'gmt_offset' );						
						$timestamp = $subscription->current_period_end + ( $offset * 3600 );
					} elseif ( $customer->delinquent && ! empty( $subscription->current_period_start ) ) {
						$offset = get_option( 'gmt_offset' );						
						$timestamp = $subscription->current_period_start + ( $offset * 3600 );
					} else {
						$timestamp = null;  // shouldn't really get here
					}
				}
			}
		}

		return $timestamp;
	}

	public static function pmpro_set_up_apple_pay( $payment_option_values, $gateway  ) {
		// Check that we just saved Stripe settings.
		if ( $gateway != 'etsstripe' || empty( $_REQUEST['savesettings'] ) ) {
			return;
		}

		// Check that payment request button is enabled.
		if ( empty( $payment_option_values['stripe_payment_request_button'] ) ) {
			// We don't want to unregister domain or remove file in case
			// other plugins are using it.
			return;	
		}
	
		// Make sure that Apple Pay is set up.
		// TODO: Apple Pay API functions don't seem to work with
		//       test API keys. Need to figure this out.
		$stripe = new PMProGateway_etsStripe();
		if ( ! $stripe->pmpro_does_apple_pay_domain_exist() ) {
			// 1. Make sure domain association file available.
			flush_rewrite_rules();
			// 2. Register Domain with Apple.
			$stripe->pmpro_create_apple_pay_domain();
		}
   }

   /**
	 * This function is used to save the parameters returned after successfull connection of Stripe account.
	 *
	 * @return void
	 */
	public static function stripe_connect_save_options() {
		// Is user have permission to edit give setting.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Be sure only to connect when param present.
		if ( ! isset( $_REQUEST['pmpro_stripe_connected'] ) || ! isset( $_REQUEST['pmpro_stripe_connected_environment'] ) ) {
			return false;
		}
		
		// Change current gateway to Stripe
		pmpro_setOption( 'gateway', 'etsstripe' );
		pmpro_setOption( 'gateway_environment', $_REQUEST['pmpro_stripe_connected_environment'] );

		$error = '';
		if (
			'false' === $_REQUEST['pmpro_stripe_connected']
			&& isset( $_REQUEST['error_message'] )
		) {
			$error = $_REQUEST['error_message'];
		} elseif (
			'false' === $_REQUEST['pmpro_stripe_connected']
			|| ! isset( $_REQUEST['pmpro_stripe_publishable_key'] )
			|| ! isset( $_REQUEST['pmpro_stripe_user_id'] )
			|| ! isset( $_REQUEST['pmpro_stripe_access_token'] )
		) {
			$error = __( 'Invalid response from the Stripe Connect server.', 'paid-memberships-pro' );
		} else {
			// Update keys.
			if ( $_REQUEST['pmpro_stripe_connected_environment'] === 'live' ) {
				// Update live keys.
				pmpro_setOption( 'live_stripe_connect_user_id', $_REQUEST['pmpro_stripe_user_id'] );
				pmpro_setOption( 'live_stripe_connect_secretkey', $_REQUEST['pmpro_stripe_access_token'] );
				pmpro_setOption( 'live_stripe_connect_publishablekey', $_REQUEST['pmpro_stripe_publishable_key'] );
			} else {
				// Update sandbox keys.
				pmpro_setOption( 'sandbox_stripe_connect_user_id', $_REQUEST['pmpro_stripe_user_id'] );
				pmpro_setOption( 'sandbox_stripe_connect_secretkey', $_REQUEST['pmpro_stripe_access_token'] );
				pmpro_setOption( 'sandbox_stripe_connect_publishablekey', $_REQUEST['pmpro_stripe_publishable_key'] );
			}
			

			// Delete option for user API key.
			delete_option( 'pmpro_stripe_secretkey' );
			delete_option( 'pmpro_stripe_publishablekey' );

			wp_redirect( admin_url( 'admin.php?page=pmpro-paymentsettings' ) );
			exit;
		}

		if ( ! empty( $error ) ) {
			global $pmpro_stripe_error;
			$pmpro_stripe_error = sprintf(
				/* translators: %s Error Message */
				__( '<strong>Error:</strong> PMPro could not connect to the Stripe API. Reason: %s', 'paid-memberships-pro' ),
				esc_html( $error )
			);
		}
	}

	public static function stripe_connect_show_errors() {
		global $pmpro_stripe_error;
		if ( ! empty( $pmpro_stripe_error ) ) {
			$class   = 'notice notice-error pmpro-stripe-connect-message';
			printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), $pmpro_stripe_error );
		}
	}

	/**
	 * Disconnects user from the Stripe Connected App.
	 */
	public static function stripe_connect_deauthorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Be sure only to deauthorize when param present.
		if ( ! isset( $_REQUEST['pmpro_stripe_disconnected'] ) || ! isset( $_REQUEST['pmpro_stripe_disconnected_environment'] ) ) {
			return false;
		}

		// Show message if NOT disconnected.
		if (
			'false' === $_REQUEST['pmpro_stripe_disconnected']
			&& isset( $_REQUEST['error_code'] )
		) {

			$class   = 'notice notice-warning pmpro-stripe-disconnect-message';
			$message = sprintf(
				/* translators: %s Error Message */
				__( '<strong>Error:</strong> PMPro could not disconnect from the Stripe API. Reason: %s', 'paid-memberships-pro' ),
				esc_html( $_REQUEST['error_message'] )
			);

			printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), $message );

		}

		if ( $_REQUEST['pmpro_stripe_disconnected_environment'] === 'live' ) {
			// Delete live keys.
			delete_option( 'pmpro_live_stripe_connect_user_id' );
			delete_option( 'pmpro_live_stripe_connect_secretkey' );
			delete_option( 'pmpro_live_stripe_connect_publishablekey' );
		} else {
			// Delete sandbox keys.
			delete_option( 'pmpro_sandbox_stripe_connect_user_id' );
			delete_option( 'pmpro_sandbox_stripe_connect_secretkey' );
			delete_option( 'pmpro_sandbox_stripe_connect_publishablekey' );
		}
	}

	/**
	 * Determine whether the site is using legacy Stripe keys.
	 *
	 * @return bool Whether the site is using legacy Stripe keys.
	 */
	public static function using_legacy_keys() {
		$r = ! empty( pmpro_getOption( 'stripe_secretkey' ) ) && ! empty( pmpro_getOption( 'stripe_publishablekey' ) );		
		return $r;
	}

	/**
	 * Determine whether the site has Stripe Connect credentials set based on gateway environment.
	 *
	 * @param null|string $gateway_environment The gateway environment to use, default uses the current saved setting.
	 *
	 * @return bool Whether the site has Stripe Connect credentials set.
	 */
	public static function has_connect_credentials( $gateway_environment = null ) {
		if ( empty( $gateway_environment ) ) {
			$gateway_engvironemnt = pmpro_getOption( 'pmpro_gateway_environment' );
		}

		if ( $gateway_environment === 'live' ) {
			// Return whether Stripe is connected for live gateway environment.
			return ( 
				pmpro_getOption( 'live_stripe_connect_user_id' ) &&
				pmpro_getOption( 'live_stripe_connect_secretkey' ) &&
				pmpro_getOption( 'live_stripe_connect_publishablekey' )
			);
		} else {
			// Return whether Stripe is connected for sandbox gateway environment.
			return ( 
				pmpro_getOption( 'sandbox_stripe_connect_user_id' ) &&
				pmpro_getOption( 'sandbox_stripe_connect_secretkey' ) &&
				pmpro_getOption( 'sandbox_stripe_connect_publishablekey' )
			);
		}
	}

	/**
	 * Warn if required extensions aren't loaded.
	 *
	 * @return bool
	 * @since 1.8.6.8.1
	 * @since 1.8.13.6 - Add json dependency
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 */
	public static function dependencies() {
		global $msg, $msgt, $pmpro_stripe_error;

		if ( version_compare( PHP_VERSION, '5.3.29', '<' ) ) {

			$pmpro_stripe_error = true;
			$msg                = - 1;
			$msgt               = sprintf( __( "The Stripe Gateway requires PHP 5.3.29 or greater. We recommend upgrading to PHP %s or greater. Ask your host to upgrade.", "paid-memberships-pro" ), PMPRO_PHP_MIN_VERSION );

			if ( ! is_admin() ) {
				pmpro_setMessage( $msgt, "pmpro_error" );
			}

			return false;
		}

		$modules = array( 'curl', 'mbstring', 'json' );

		foreach ( $modules as $module ) {
			if ( ! extension_loaded( $module ) ) {
				$pmpro_stripe_error = true;
				$msg                = - 1;
				$msgt               = sprintf( __( "The %s gateway depends on the %s PHP extension. Please enable it, or ask your hosting provider to enable it.", 'paid-memberships-pro' ), 'Stripe', $module );

				//throw error on checkout page
				if ( ! is_admin() ) {
					pmpro_setMessage( $msgt, 'pmpro_error' );
				}

				return false;
			}
		}

		self::$is_loaded = true;

		return true;
	}

	/****************************************
	 ************ PUBLIC METHODS ************
	 ****************************************/
	/**
	 * Process checkout and decide if a charge and or subscribe is needed
	 * Updated in v2.1 to work with Stripe v3 payment intents.
	 * @since 1.4
	 */
	public function process( &$order ) {
		$payment_transaction_id = '';
		$subscription_transaction_id = '';
		if ( ! empty( $order->setup_intent_id ) ){
			// The user tried to confirm a setup intent. This means that there was no initial
			// payment needed for this chekout (otherwise there would be a payment intent instead),
			// and that the subscription needed authorization before being created.
			$setup_intent = $this->process_setup_intent( $order->setup_intent_id );
			if ( is_string( $setup_intent ) ) {
				$order->error      = __( 'Error processing setup intent.', 'paid-memberships-pro' ) . ' ' . $setup_intent;
				$order->shorterror = $order->error;
				return false;
			}
			// Subscription should now be active. Let's get its ID to save to the MemberOrder.
			$subscription_transaction_id = $setup_intent->metadata->subscription_id;
		} else {
			// User has either just submitted the checkout form or tried to confirm their
			// payment intent.
			$customer = null; // This will be used to create the subscription later.
			if ( ! empty( $order->payment_intent_id ) ) {
				// User has just tried to confirm their payment intent. We need to make sure that it was
				// confirmed successfully, and then try to create their subscription if needed.
				$payment_intent = $this->process_payment_intent( $order->payment_intent_id );
				if ( is_string( $payment_intent ) ) {
					$order->error      = __( 'Error processing payment intent.', 'paid-memberships-pro' ) . ' ' . $payment_intent;
					$order->shorterror = $order->error;
					return false;
				}
				// Payment should now be processed.
				$payment_transaction_id = $payment_intent->charges->data[0]->id;

				// Note the customer so that we can create a subscription if needed..
				$customer = $payment_intent->customer;
			} else {
				// We have not yet tried to process this checkout.
				// Make sure we have a customer with a payment method.
				$customer = $this->update_customer_at_checkout( $order );
				if ( empty( $customer ) ) {
					// There was an issue creating/updating the Stripe customer.
					// $order will have an error message, so we don't need to add one.
					return false;
				}

				$payment_method = $this->get_payment_method( $order );
				if ( empty( $payment_method ) ) {
					// There was an issue getting the payment method.
					$order->error      = __( 'Error retrieving payment method.', 'paid-memberships-pro' );
					$order->shorterror = $order->error;
					return false;
				}

				$customer = $this->set_default_payment_method_for_customer( $customer, $payment_method );
				if ( is_string( $customer ) ) {
					// There was an issue updating the default payment method.
					$order->error      = __( 'Error updating default payment method.', 'paid-memberships-pro' ) . ' ' . $customer;
					$order->shorterror = $order->error;
					return false;
				}

				// Save customer in $order for create_payment_intent().
				// This will likely be removed as we rework payment processing.
				$order->stripe_customer = $customer;

				// Process the charges.
				$charges_processed = $this->process_charges( $order );
				if ( ! empty( $order->error ) ) {
					// There was an error processing charges.
					// $order has an error message, so we don't need to add one.
					return false;
				}

				// If we needed to charge an initial payment, it was successful.
				if ( ! empty( $order->stripe_payment_intent->charges->data[0]->id ) ) {
					$payment_transaction_id = $order->stripe_payment_intent->charges->data[0]->id;
				}
			}

			// Create a subscription if we need to.
			/*if ( pmpro_isLevelRecurring( $order->membership_level ) ) {
				$subscription = $this->create_subscription_for_customer_from_order( $customer->id, $order );
				if ( empty( $subscription ) ) {
					// There was an issue creating the subscription.
					$order->error      = __( 'Error creating subscription for customer.', 'paid-memberships-pro' );
					$order->shorterror = $order->error;
					return false;
				}
				$order->stripe_subscription = $subscription;
				$setup_intent = $subscription->pending_setup_intent;
	
				if ( ! empty( $setup_intent->status ) && 'requires_action' === $setup_intent->status ) {
					// We will need to reload the page to authenticate, so save the subscription ID in the setup intent
					// so that we don't lose it.
					$setup_intent = $this->add_subscription_id_to_setup_intent( $setup_intent, $subscription->id );
					if ( is_string( $setup_intent ) ) {
						$order->error      = $setup_intent;
						$order->shorterror = $order->error;
						return false;
					}
					$order->stripe_setup_intent = $setup_intent;
					$order->errorcode = true;
					$order->error     = __( 'Customer authentication is required to finish setting up your subscription. Please complete the verification steps issued by your payment provider.', 'paid-memberships-pro' );
		
					return false;
				}

				// Successfully created a subscription.
				$subscription_transaction_id = $subscription->id;
			}*/
		}
		// All charges have been processed and all subscriptions have been created.
		$order->payment_transaction_id = $payment_transaction_id;
		$order->subscription_transaction_id = $subscription_transaction_id;
		$order->status = 'success';
		$order->notes = $order->payment_intent_id;
		//update_pmpro_membership_order_meta($order->id, '_ets_stripe_payment_intent_id', $payment_intent_id);
		$order->saveOrder();
		return true;
	}

	/**
	 * Retrieve a Stripe_Customer for a given user.
	 *
	 * @since 2.7.0
	 *
	 * @param int $user_id to get Stripe_Customer for.
	 * @return Stripe_Customer|null
	 */
	public function get_customer_for_user( $user_id ) {
		// Pull Stripe customer ID from user meta.
		$customer_id = get_user_meta( $user_id, 'pmpro_stripe_customerid', true );

		if ( empty( $customer_id ) ) {
			// Try to figure out the cuseromer ID from their last order.
			if ( empty ( $order ) ) {
				$order = new MemberOrder();
				$order->getLastMemberOrder(
					$user_id,
					array(
						'success',
						'cancelled'
					),
					null,
					'etsstripe',
					$order->Gateway->gateway_environment
				);
			}

			// If we don't have a customer ID yet, get the Customer ID from their subscription.
			if ( empty( $customer_id ) && ! empty( $order->subscription_transaction_id ) && strpos( $order->subscription_transaction_id, "sub_" ) !== false ) {
				try {
					$subscription = Stripe_Subscription::retrieve( $order->subscription_transaction_id );
				} catch ( \Throwable $e ) {
					// Assume no customer found.
				} catch ( \Exception $e ) {
					// Assume no customer found.
				}
				if ( ! empty( $subscription ) && ! empty( $subscription->customer ) ) {
					$customer_id = $subscription->customer;
				}
			}

			// If we don't have a customer ID yet, get the Customer ID from their charge.
			if ( empty( $customer_id ) && ! empty( $order->payment_transaction_id ) && strpos( $order->payment_transaction_id, "ch_" ) !== false ) {
				try {
					$charge = Stripe_Charge::retrieve( $order->payment_transaction_id );
				} catch ( \Throwable $e ) {
					// Assume no customer found.
				} catch ( \Exception $e ) {
					// Assume no customer found.
				}
				if ( ! empty( $charge ) && ! empty( $charge->customer ) ) {
					$customer_id = $charge->customer;
				}
			}

			// If we don't have a customer ID yet, get the Customer ID from their invoice.
			if ( empty( $customer_id ) && ! empty( $order->payment_transaction_id ) && strpos( $order->payment_transaction_id, "in_" ) !== false ) {
				try {
					$invoice = Stripe_Invoice::retrieve( $order->payment_transaction_id );
				} catch ( \Throwable $e ) {
					// Assume no customer found.
				} catch ( \Exception $e ) {
					// Assume no customer found.
				}
				if ( ! empty( $invoice ) && ! empty( $invoice->customer ) ) {
					$customer_id = $invoice->customer;
				}
			}

			if ( ! empty( $customer_id ) ) {
				update_user_meta( $user_id, "pmpro_stripe_customerid", $customer_id );
			}
		}

		return empty( $customer_id ) ? null : $this->get_customer( $customer_id );
	}

	/**
	 * Create/Update Stripe customer for a user.
	 *
	 * @since 2.7.0
	 *
	 * @param int $user_id to create/update Stripe customer for.
	 * @return Stripe_Customer|false
	 */
	public function update_customer_from_user( $user_id ) {
		$user = get_userdata( $user_id );

		if ( empty( $user->ID ) ) {
			// User does not exist.
			return false;
		}

		// Get the existing customer from Stripe.
		$customer = $this->get_customer_for_user( $user_id );

		// Get the name for the customer.
		$name = trim( $user->first_name . " " . $user->last_name );
		if ( empty( $name ) ) {
			// In case first and last names aren't set.
			$name = $user->user_login;
		}

		// Get data to update customer with.
		$customer_args = array(
			'email'       => $user->user_email,
			'description' => $name . ' (' . $email . ')',
		);

		// Maybe update billing address for customer.
		if (
			! $this->customer_has_billing_address( $customer ) &&
			! empty( $user->pmpro_baddress1 ) &&
			! empty( $user->pmpro_bcity ) &&
			! empty( $user->pmpro_bstate ) &&
			! empty( $user->pmpro_bzipcode ) &&
			! empty( $user->pmpro_bcountry ) 
		) {
			// We have an address in user meta and there is
			// no address in Stripe. May as well send it.
			$customer_args['address'] = array(
				'city'        => $user->pmpro_bcity,
				'country'     => $user->pmpro_bcountry,
				'line1'       => $user->pmpro_baddress1,
				'line2'       => $user->pmpro_baddress2,
				'postal_code' => $user->pmpro_bzipcode,
				'state'       => $user->pmpro_bstate,
			);
		}

		/**
		 * Change the information that is sent when updating/creating
		 * a Stripe_Customer from a user.
		 *
		 * @since 2.7.0
		 *
		 * @param array       $customer_args to be sent.
		 * @param WP_User     $user being used to create/update customer.
		 */
		$customer_args = apply_filters( 'pmpro_stripe_update_customer_from_user', $customer_args, $user );

		// Update the customer.
		if ( empty( $customer ) ) {
			// We need to build a new customer.
			$customer = $this->create_customer( $customer_args );
		} else {
			// Update the existing customer.
			$customer = $this->update_customer( $customer->ID, $customer_args );
		}
		return is_string( $customer ) ? false : $customer;
	}

	/**
	 * Get subscription status from the Gateway.
	 *
	 * @since 2.3
	 */
	public function getSubscriptionStatus( &$order ) {
		$subscription = $this->get_subscription( $order->subscription_transaction_id );
		
		if ( ! empty( $subscription ) ) {
			return $subscription->status;
		} else {
			return false;
		}
	}

	/**
	 * Helper method to update the customer info via update_customer_at_checkout
	 *
	 * @since 1.4
	 */
	public function update( &$order ) {
		$customer = $this->update_customer_at_checkout( $order );
		if ( empty( $customer ) ) {
			// There was an issue creating/updating the Stripe customer.
			// $order will have an error message, so we don't need to add one.
			return false;
		}

		$payment_method = $this->get_payment_method( $order );
		if ( empty( $payment_method ) ) {
			// There was an issue getting the payment method.
			$order->error      = __( "Error retrieving payment method.", 'paid-memberships-pro' );
			$order->shorterror = $order->error;
			return false;
		}

		$customer = $this->set_default_payment_method_for_customer( $customer, $payment_method );
		if ( is_string( $customer ) ) {
			// There was an issue updating the default payment method.
			$order->error      = __( "Error updating default payment method.", 'paid-memberships-pro' ) . " " . $customer;
			$order->shorterror = $order->error;
			return false;
		}

		if ( ! $this->update_payment_method_for_subscriptions( $order ) ) {
			$order->error      = __( "Error updating payment method for subscription.", 'paid-memberships-pro' );
			$order->shorterror = $order->error;
			return false;
		}

		return true;
	}

	/**
	 * Cancel a subscription at Stripe
	 *
	 * @since 1.4
	 */
	public function cancel( &$order, $update_status = true ) {
		global $pmpro_stripe_event;

		//no matter what happens below, we're going to cancel the order in our system
		if ( $update_status ) {
			$order->updateStatus( "cancelled" );
		}

		//require a subscription id
		if ( empty( $order->subscription_transaction_id ) ) {
			return false;
		}

		//find the customer
		$result = $this->update_customer_at_checkout( $order );

		if ( ! empty( $result ) ) {
			//find subscription with this order code
			$subscription = $this->get_subscription( $order->subscription_transaction_id );

			if ( ! empty( $subscription )
			     && ( empty( $pmpro_stripe_event ) || empty( $pmpro_stripe_event->type ) || $pmpro_stripe_event->type != 'customer.subscription.deleted' ) ) {
				if ( $this->cancelSubscriptionAtGateway( $subscription ) ) {
					//we're okay, going to return true later
				} else {
					$order->error      = __( "Could not cancel old subscription.", 'paid-memberships-pro' );
					$order->shorterror = $order->error;

					return false;
				}
			}

			/*
                Clear updates for this user. (But not if checking out, we would have already done that.)
            */
			if ( empty( $_REQUEST['submit-checkout'] ) ) {
				update_user_meta( $order->user_id, "pmpro_stripe_updates", array() );
			}

			return true;
		} else {
			$order->error      = __( "Could not find the customer.", 'paid-memberships-pro' );
			$order->shorterror = $order->error;

			return false;    //no customer found
		}
	}


	/****************************************
	 *********** PRIVATE METHODS ************
	 ****************************************/
	/**
	 * Shows settings for connecting to Stripe.
	 *
	 * @since 2.7.0.
	 *
	 * @param bool $livemode True if live credentials, false if sandbox.
	 * @param array $values Current settings.
	 * @param string $gateway currently being shown.
	 */
	private function show_connect_payment_option_fields( $livemode = true, $values, $gateway ) {
		$gateway_environment = $this->gateway_environment;

		$stripe_legacy_key      = $values['stripe_publishablekey'];
		$stripe_legacy_secret   = $values['stripe_secretkey'];
		$stripe_is_legacy_setup = ( self::using_legacy_keys() && ! empty( $stripe_legacy_key ) && ! empty( $stripe_legacy_secret ) );

		$environment = $livemode ? 'live' : 'sandbox';
		$environment2 = $livemode ? 'live' : 'test'; // For when 'test' is used instead of 'sandbox'.

		// Determine if the gateway is connected in live mode and set var.
		if ( self::has_connect_credentials( $environment ) || $stripe_is_legacy_setup ) {
			$connection_selector = 'pmpro_gateway-mode-connected';
		} else {
			$connection_selector = 'pmpro_gateway-mode-not-connected';
		}

		?>
		<tr class="pmpro_settings_divider gateway gateway_etsstripe_<?php echo $environment; ?>"
		    <?php if ( $gateway != "etsstripe" || $gateway_environment != $environment ) { ?>style="display: none;"<?php } ?>>
            <td colspan="2">
				<hr />
				<h2>
					<?php esc_html_e( 'Stripe Connect Settings', 'paid-memberships-pro' ); ?>
					<span class="pmpro_gateway-mode pmpro_gateway-mode-<?php echo $environment2; ?> <?php esc_attr_e( $connection_selector ); ?>">
						<?php  
							echo ( $livemode ? esc_html__( 'Live Mode:', 'paid-memberships-pro' ) : esc_html__( 'Test Mode:', 'paid-memberships-pro' ) ) . ' ';
							if ( self::has_connect_credentials( $environment ) ) {
								esc_html_e( 'Connected', 'paid-memberships-pro' );
							} elseif( $stripe_is_legacy_setup ) {
								esc_html_e( 'Connected with Legacy Keys', 'paid-memberships-pro' );
							} else {
								esc_html_e( 'Not Connected', 'paid-memberships-pro' );
							}
						?>
					</span>
				</h2>
				<?php if ( self::using_legacy_keys() ) { ?>
					<div class="notice notice-large notice-warning inline">
						<p class="pmpro_stripe_webhook_notice">
							<strong><?php esc_html_e( 'Your site is using legacy API keys to authenticate with Stripe.', 'paid-memberships-pro' ); ?></strong><br />
							<?php esc_html_e( 'You can continue to use the legacy API keys or choose to upgrade to our new Stripe Connect solution below.', 'paid-memberships-pro' ); ?><br />
							<?php
							if ( $livemode ) {
								esc_html_e( 'Use the "Connect with Stripe" button below to securely authenticate with your Stripe account using Stripe Connect. Log in with the current Stripe account used for this site so that existing subscriptions are not affected by the update.', 'paid-memberships-pro' );
							} else {
								esc_html_e( 'Use the "Connect with Stripe" button below to securely authenticate with your Stripe account using Stripe Connect in test mode.', 'paid-memberships-pro' );
							}
							?>
							<a href="https://www.paidmembershipspro.com/gateway/stripe/switch-legacy-to-connect/?utm_source=plugin&utm_medium=pmpro-paymentsettings&utm_campaign=documentation&utm_content=switch-to-connect" target="_blank"><?php esc_html_e( 'Read the documentation on switching to Stripe Connect &raquo;', 'paid-memberships-pro' ); ?></a>
						</p>
					</div>
				<?php } ?>
            </td>
        </tr>
		<tr class="gateway gateway_etsstripe_<?php echo $environment; ?>" <?php if ( $gateway != "etsstripe" || $gateway_environment != $environment ) { ?>style="display: none;"<?php } ?>>
            <th scope="row" valign="top">
                <label><?php esc_html_e( 'Stripe Connection:', 'paid-memberships-pro' ); ?></label>
            </th>
			<td>
				<?php
				$connect_url_base = apply_filters( 'pmpro_stripe_connect_url', 'https://connect.paidmembershipspro.com' );
				if ( self::has_connect_credentials( $environment ) ) {
					$connect_url = add_query_arg(
						array(
							'action' => 'disconnect',
							'gateway_environment' => $environment2,
							'stripe_user_id' => $values[ $environment . '_stripe_connect_user_id'],
							'return_url' => rawurlencode( admin_url( 'admin.php?page=pmpro-paymentsettings' ) ),
						),
						$connect_url_base
					);
					?>
					<a href="<?php echo esc_url_raw( $connect_url ); ?>" class="pmpro-stripe-connect"><span><?php esc_html_e( 'Disconnect From Stripe', 'paid-memberships-pro' ); ?></span></a>
					<p class="description">
						<?php
						if ( $livemode ) {
							esc_html_e( 'This will disconnect your site from Stripe. Users will not be able to complete membership checkout or update their billing information. Existing subscriptions will not be affected at the gateway, but new recurring orders will not be created in this site.', 'paid-memberships-pro' );
						} else {
							esc_html_e( 'This will disconnect your site from Stripe in test mode only.', 'paid-memberships-pro' );
						}
						?>
					</p>
					<?php
				} else {
					$connect_url = add_query_arg(
						array(
							'action' => 'authorize',
							'gateway_environment' => $environment2,
							'return_url' => rawurlencode( admin_url( 'admin.php?page=pmpro-paymentsettings' ) ),
						),
						$connect_url_base
					);
					?>
					<a href="<?php echo esc_url_raw( $connect_url ); ?>" class="pmpro-stripe-connect"><span><?php esc_html_e( 'Connect with Stripe', 'paid-memberships-pro' ); ?></span></a>
					<?php
				}
				?>
				<p class="description">
					<?php
						if ( pmpro_license_isValid( null, 'plus' ) ) {
							esc_html_e( 'Note: You have a valid license and are not charged additional platform fees for payment processing.', 'paid-memberships-pro');
						} else {
							esc_html_e( 'Note: You are using the free Stripe payment gateway integration. This includes an additional 1% fee for payment processing. This fee is removed by activating a Plus license.', 'paid-memberships-pro');
						}
						echo ' <a href="https://www.paidmembershipspro.com/gateway/stripe/?utm_source=plugin&utm_medium=pmpro-paymentsettings&utm_campaign=gateways&utm_content=stripe-fees#tab-fees" target="_blank">' . esc_html( 'Learn More &raquo;', 'paid-memberships-pro' ) . '</a>';
					?>
				</p>
				<input type='hidden' name='<?php echo $environment; ?>_stripe_connect_user_id' id='<?php echo $environment; ?>_stripe_connect_user_id' value='<?php echo esc_attr( $values[ $environment . '_stripe_connect_user_id'] ) ?>'/>
				<input type='hidden' name='<?php echo $environment; ?>_stripe_connect_secretkey' id='<?php echo $environment; ?>_stripe_connect_secretkey' value='<?php echo esc_attr( $values[ $environment . '_stripe_connect_secretkey'] ) ?>'/>
				<input type='hidden' name='<?php echo $environment; ?>_stripe_connect_publishablekey' id='<?php echo $environment; ?>_stripe_connect_publishablekey' value='<?php echo esc_attr( $values[ $environment . '_stripe_connect_publishablekey'] ) ?>'/>
			</td>
        </tr>
		<tr class="gateway gateway_etsstripe_<?php echo $environment; ?>" <?php if ( $gateway != "etsstripe" || $gateway_environment != $environment ) { ?>style="display: none;"<?php } ?>>
            <th scope="row" valign="top">
                <label><?php esc_html_e( 'Webhook', 'paid-memberships-pro' ); ?>:</label>
            </th>
            <td>
				<?php self::get_last_webhook_date( $environment ); ?>
				<p class="description"><?php esc_html_e( 'Webhook URL', 'paid-memberships-pro' ); ?>:
				<code><?php echo esc_html( self::get_site_webhook_url() ); ?></code></p>
            </td>
        </tr>
		<?php
	}

	/**
	 * Retrieve a Stripe_Customer.
	 *
	 * @since 2.7.0
	 *
	 * @param string $customer_id to retrieve.
	 * @return Stripe_Customer|null
	 */
	private function get_customer( $customer_id ) {
		try {
			$customer = Stripe_Customer::retrieve( $customer_id );
			return $customer;
		} catch ( \Throwable $e ) {
			// Assume no customer found.
		} catch ( \Exception $e ) {
			// Assume no customer found.
		}
	}

	/**
	 * Check whether a given Stripe customer has a billing address set.
	 *
	 * @since 2.7.0
	 *
	 * @param Stripe_Customer $customer to check.
	 * @return bool
	 */
	private function customer_has_billing_address( $customer ) {
		return (
			! empty( $customer ) &&
			! empty( $customer->address->line1 ) &&
			! empty( $customer->address->city ) &&
			! empty( $customer->address->state ) &&
			! empty( $customer->address->postal_code ) &&
			! empty( $customer->address->country )
		);
	}

	/**
	 * Update a customer in Stripe.
	 *
	 * @since 2.7.0
	 *
	 * @param string $customer_id to update.
	 * @param array  $args to update with.
	 * @return Stripe_Customer|string error message.
	 */
	private function update_customer( $customer_id, $args ) {
		try {
			$customer = Stripe_Customer::update( $customer_id, $args );
		} catch ( \Stripe\Error $e ) {
			return $e->getMessage();
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		} catch ( \Exception $e ) {
			return $e->getMessage();
		}
		return $customer;
	}

	/**
	 * Create a new customer in Stripe.
	 *
	 * @since 2.7.0
	 *
	 * @param array  $args to update with.
	 * @return Stripe_Customer|string error message.
	 */
	private function create_customer( $args ) {
		try {
			$customer = Stripe_Customer::create( $args );
		} catch ( \Stripe\Error $e ) {
			return $e->getMessage();
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		} catch ( \Exception $e ) {
			return $e->getMessage();
		}
		return $customer;
	}

	/**
	 * Create/Update Stripe customer from MemberOrder.
	 *
	 * Falls back on information in User object if insufficient
	 * information in MemberOrder.
	 *
	 * Should only be called when checkout is being proceesed. Otherwise,
	 * use update_customer_from_user() method.
	 *
	 * @since 2.7.0
	 *
	 * @param MemberOrder $order to create/update Stripe customer for.
	 * @return Stripe_Customer|false
	 */
	private function update_customer_at_checkout( $order ) {
		global $current_user;

		// Get user's ID.
		if ( ! empty( $order->user_id ) ) {
			$user_id = $order->user_id;
		}
		if ( empty( $user_id ) && ! empty( $current_user->ID ) ) {
			$user_id = $current_user->ID;
		}
		$user = empty( $user_id ) ? null : get_userdata( $user_id );
		$customer = empty( $user_id ) ? null : $this->get_customer_for_user( $user_id );

		// Get customer name.
		if ( ! empty( $order->FirstName ) && ! empty( $order->LastName ) ) {
			$name = trim( $order->FirstName . " " . $order->LastName );
		} elseif ( ! empty( $order->FirstName ) ) {
			$name = $order->FirstName;
		} elseif ( ! empty( $order->LastName ) ) {
			$name = $order->LastName;
		} elseif ( ! empty( $user->ID ) ) {
			$name = trim( $user->first_name . " " . $user->last_name );
			if ( empty( $name ) ) {
				// In case first and last names aren't set.
				$name = $user->user_login;
			}
		} else {
			$name = 'No Name';
		}

		// Get user's email.
		if ( ! empty( $order->Email ) ) {
			$email = $order->Email;
		} elseif ( ! empty( $user->user_email ) ) {
			$email = $user->user_email;
		} else {
			$email = "No Email";
		}

		// Build data to update customer with.
		$customer_args = array(
			'email'       => $email,
			'description' => $name . ' (' . $email . ')',
		);

		// Maybe update billing address for customer.
		if (
			! empty( $order->billing->street ) &&
			! empty( $order->billing->city ) &&
			! empty( $order->billing->state ) &&
			! empty( $order->billing->postal_code ) &&
			! empty( $order->billing->country ) 
		) {
			// We collected a billing address at checkout.
			// Send it to Stripe.
			$customer_args['address'] = array(
				'city'        => $order->billing->city,
				'country'     => $order->billing->country,
				'line1'       => $order->billing->street,
				'line2'       => '',
				'postal_code' => $order->billing->zip,
				'state'       => $order->billing->state,
			);
		} elseif (
			! $this->customer_has_billing_address( $customer ) &&
			! empty( $user->pmpro_baddress1 ) &&
			! empty( $user->pmpro_bcity ) &&
			! empty( $user->pmpro_bstate ) &&
			! empty( $user->pmpro_bzipcode ) &&
			! empty( $user->pmpro_bcountry ) 
		) {
			// We have an address in user meta and there is
			// no address in Stripe. May as well send it.
			$customer_args['address'] = array(
				'city'        => $user->pmpro_bcity,
				'country'     => $user->pmpro_bcountry,
				'line1'       => $user->pmpro_baddress1,
				'line2'       => $user->pmpro_baddress2,
				'postal_code' => $user->pmpro_bzipcode,
				'state'       => $user->pmpro_bstate,
			);
		}

		/**
		 * Change the information that is sent when updating/creating
		 * a Stripe_Customer from a MemberOrder.
		 *
		 * @since 2.7.0
		 *
		 * @param array       $customer_args to be sent.
		 * @param MemberOrder $order being used to create/update customer.
		 */
		$customer_args = apply_filters( 'pmpro_stripe_update_customer_at_checkout', $customer_args, $order );

		// Check if we have an existing user.
		if ( ! empty( $customer ) ) {
			// User is already a customer in Stripe. Update.
			$customer = $this->update_customer( $customer->id, $customer_args );
			if ( is_string( $customer ) ) {
				// We were not able to create a new user in Stripe.
				$order->error      = __( "Error updating customer record with Stripe.", 'paid-memberships-pro' ) . " " . $customer;
				$order->shorterror = $order->error;
				return false;
			}
			return $customer;
		}

		// No customer yet. Need to create one.
		$customer = $this->create_customer( $customer_args );
		if ( is_string( $customer ) ) {
			// We were not able to create a new user in Stripe.
			$order->error      = __( "Error creating customer record with Stripe.", 'paid-memberships-pro' ) . " " . $customer;
			$order->shorterror = $order->error;
			return false;
		}

		// If we don't have a user yet, we need to update their user meta after registration.
		if ( empty( $user_id ) ) {
			global $pmpro_stripe_customer_id;
			$pmpro_stripe_customer_id = $customer->id;
			if ( ! function_exists( 'pmpro_user_register_stripe_customerid' ) ) {
				function pmpro_user_register_stripe_customerid( $user_id ) {
					global $pmpro_stripe_customer_id;
					update_user_meta( $user_id, "pmpro_stripe_customerid", $pmpro_stripe_customer_id );
				}
				add_action( "user_register", "pmpro_user_register_stripe_customerid" );
			}
		}

		/**
		 * Fires after a Stripe_Customer is created at checkout.
		 *
		 * @since Unknown
		 * @deprecated 2.7.0. Use pmpro_stripe_update_customer_from_user or pmpro_stripe_update_customer_at_checkout.
		 *
		 * @param array       $customer_args to be sent.
		 * @param MemberOrder $order being used to create/update customer.
		 */
		do_action( 'pmpro_stripe_create_customer', $customer );
		return $customer;
	}

	/**
	 * Sets the default Payment Method for a Customer in Stripe.
	 *
	 * @param Stripe_Customer $customer to update default payment method for.
	 * @param Stripe_PaymentMethod $payment_method to set as default.
	 * @return Stripe_Customer|string error message.
	 */
	private function set_default_payment_method_for_customer( $customer, $payment_method ) {
		if ( ! empty( $customer->invoice_settings->default_payment_method ) && $customer->invoice_settings->default_payment_method === $payment_method->id ) {
			// Payment method already correct, no need to update.
			return $customer;
		}

		try {
			$payment_method->attach( [ 'customer' => $customer->id ] );
			$customer->invoice_settings->default_payment_method = $payment_method->id;
			$customer->save();
		} catch ( Stripe\Error\Base $e ) {
			$order->error = $e->getMessage();
			return $e->getMessage();
		} catch ( \Throwable $e ) {
			$order->error = $e->getMessage();
			return $e->getMessage();
		} catch ( \Exception $e ) {
			$order->error = $e->getMessage();
			return $e->getMessage();
		}

		return $customer;
	}

		/**
	 * Convert a price to a positive integer in cents (or 0 for a free price)
	 * representing how much to charge. This is how Stripe wants us to send price amounts.
	 *
	 * @param float $price to be converted into cents.
	 * @return integer
	 */
	private function convert_price_to_unit_amount( $price ) {
		global $pmpro_currencies, $pmpro_currency;
		$currency_unit_multiplier = 100; // ie 100 cents per USD.

		// Account for zero-decimal currencies like the Japanese Yen.
		if (
			is_array( $pmpro_currencies[ $pmpro_currency ] ) &&
			isset( $pmpro_currencies[ $pmpro_currency ]['decimals'] ) &&
			$pmpro_currencies[ $pmpro_currency ]['decimals'] == 0 
		) {
			$currency_unit_multiplier = 1;
		}

		return intval( $price * $currency_unit_multiplier );
	}

	/**
	 * Retrieve a Stripe_Subscription.
	 *
	 * @since 2.7.0
	 *
	 * @param string $subscription_id to retrieve.
	 * @return Stripe_Subscription|null
	 */
	private function get_subscription( $subscription_id ) {
		try {
			$customer = Stripe_Subscription::retrieve( $subscription_id );
			return $customer;
		} catch ( \Throwable $e ) {
			// Assume no subscription found.
		} catch ( \Exception $e ) {
			// Assume no subscription found.
		}
	}

	/**
	 * Get the Stripe product ID for a given membership level.
	 *
	 * @since 2.7.0
	 *
	 * @param PMPro_Membership_Leve|int $level to get product ID for.
	 * @return string|null
	 */
	private function get_product_id_for_level( $level ) {
		if ( ! is_a( $level, 'PMPro_Membership_Level' ) ) {
			if ( is_numeric( $level ) ) {
				$level = new PMPro_Membership_Level( $level );
			}
		}

		if ( empty( $level->ID ) ) {
			// We do not have a valid level.
			return;
		}

		$gateway_environment = pmpro_getOption( 'gateway_environment' );
		if ( $gateway_environment === 'sandbox' ) {
			$stripe_product_id = $level->stripe_product_id_sandbox;
		} else {
			$stripe_product_id = $level->stripe_product_id;
		}
		if ( empty( $stripe_product_id ) ) {
			$stripe_product_id = $this->create_product_for_level( $level, $gateway_environment );
		}

		if ( ! empty( $stripe_product_id ) ) {
			return $stripe_product_id;
		}
	}

	/**
	 * Create a new Stripe product for a given membership level.
	 *
	 * WARNING: Will overwrite old Stripe product set for level if
	 * there is already one set.
	 *
	 * @since 2.7.0
   *
	 * @param PMPro_Membership_Level|int $level to create product ID for.
	 * @param string $gateway_environment to create product for.
	 * @return string|null ID of new product
	 */
	private function create_product_for_level( $level, $gateway_environment ) {
		if ( ! is_a( $level, 'PMPro_Membership_Level' ) ) {
			if ( is_numeric( $level ) ) {
				$level = new PMPro_Membership_Level( $level );
			}
		}

		if ( empty( $level->ID ) ) {
			// We do not have a valid level.
			return;
		}

		$product_args = array(
			'name' => $level->name,
		);
		/**
		 * Filter the data sent to Stripe when creating a new product for a membership level.
		 *
		 * @since 2.7.0
		 *
		 * @param array $product_args being sent to Stripe.
		 * @param PMPro_Membership_Level $level that product is being created for.
		 * @param string $gateway_environment being used.
		 */
		$product_args = apply_filters( 'pmpro_stripe_create_product_for_level', $product_args, $level, $gateway_environment );

		try {
			$product = Stripe_Product::create( $product_args );
			if ( ! empty( $product->id ) ) {
				$meta_name = 'sandbox' === $gateway_environment ? 'stripe_product_id_sandbox' : 'stripe_product_id';
				update_pmpro_membership_level_meta( $level->ID, $meta_name, $product->id );
				return $product->id;
			}
		} catch (\Throwable $th) {
			// Could not create product.
		} catch (\Exception $e) {
			// Could not create product.
		}
	}

	/**
	 * Get a Price for a given product, or create one if it doesn't exist.
	 *
	 * TODO: Add pagination.
	 *
	 * @since 2.7.0
	 *
	 * @param string $product_id to get Price for.
	 * @param float $amount that the Price will charge.
	 * @param string|null $cycle_period for subscription payments.
	 * @param string|null $cycle_number of cycle periods between each subscription payment.
	 *
	 * @return string|null Price ID.
	 */
	private function get_price_for_product( $product_id, $amount, $cycle_period = null, $cycle_number = null ) {
		global $pmpro_currency;
		$currency = pmpro_get_currency();

		$is_recurring = ! empty( $cycle_period ) && ! empty( $cycle_number );
		$unit_amount  = intval( $amount * pow( 10, intval( $currency['decimals'] ) ) );
		$cycle_period = strtolower( $cycle_period );

		$price_search_args = array(
			'product'  => $product_id,
			'type'     => $is_recurring ? 'recurring' : 'one_time',
			'currency' => strtolower( $pmpro_currency ),
		);
		if ( $is_recurring ) {
			$price_search_args['recurring'] = array( 'interval' => $cycle_period );
		}

		try {
			$prices = Stripe_Price::all( $price_search_args );

			foreach ( $prices as $price ) {
				// Check whether price is the same. If not, continue.
				if ( intval( $price->unit_amount ) !== intval( $unit_amount ) ) {
					continue;
				}
				// Check if recurring structure is the same. If not, continue.
				if ( $is_recurring && ( empty( $price->recurring->interval_count ) || intval( $price->recurring->interval_count ) !== intval( $cycle_number ) ) ) {
					continue;
				}
				return $price->id;
			}
		} catch (\Throwable $th) {
			// There was an error listing prices.
			return;
		} catch (\Exception $e) {
			// There was an error listing prices.
			return;
		}

		// Create a new Price.
		$price_args = array(
			'product'     => $product_id,
			'currency'    => strtolower( $pmpro_currency ),
			'unit_amount' => $unit_amount
		);
		if ( $is_recurring ) {
			$price_args['recurring'] = array(
				'interval'       => $cycle_period,
				'interval_count' => $cycle_number
			);
		}

		try {
			$price = Stripe_Price::create( $price_args );
			if ( ! empty( $price->id ) ) {
				return $price->id;
			}
		} catch (\Throwable $th) {
			// Could not create product.
		} catch (\Exception $e) {
			// Could not create product.
		}
	}

	/**
	 * Calculate the number of days until the first recurring payment
	 * for a subscription should be charged.
	 *
	 * @since 2.7.0.
	 *
	 * @param MemberOrder $order to calculate trial period days for.
	 * @return int trial period days.
	 */
	public function calculate_trial_period_days( $order ) {
		// Use a trial period to set the first recurring payment date.
		if ( $order->BillingPeriod == "Year" ) {
			$days_in_billing_period = $order->BillingFrequency * 365;    //annual
		} elseif ( $order->BillingPeriod == "Day" ) {
			$days_in_billing_period = $order->BillingFrequency * 1;        //daily
		} elseif ( $order->BillingPeriod == "Week" ) {
			$days_in_billing_period = $order->BillingFrequency * 7;        //weekly
		} else {
			$days_in_billing_period = $order->BillingFrequency * 30;    //assume monthly
		}
		$trial_period_days = $order->BillingFrequency * $days_in_billing_period;

		$level_id = $order->membership_id;
		$recurring_stripe_custom_date = get_pmpro_membership_level_meta( $level_id, '_pmpro_recurring_stripe_custom_date', true );

		if ( !empty( $recurring_stripe_custom_date ) ) {
			$trial_period_days = ceil( abs( strtotime( date_i18n( "Y-m-d\TH:i:s" ), current_time( "timestamp" ) ) - strtotime($recurring_stripe_custom_date, current_time( "timestamp" ) ) )/86400);
		}
		//var_dump($order,$days_in_billing_period,$trial_period_days,$order->BillingFrequency);

		// For free trials, multiply the trial period for each additional free period.
		if ( ! empty( $order->TrialBillingCycles ) && $order->TrialAmount == 0 ) {
			$trialOccurrences = (int) $order->TrialBillingCycles;
			$trial_period_days = $trial_period_days * ( $order->TrialBillingCycles + 1 );
		}

		//convert to a profile start date
		$order->ProfileStartDate = date_i18n( "Y-m-d\TH:i:s", strtotime( "+ " . $trial_period_days . " Day", current_time( "timestamp" ) ) );

		//filter the start date
		$order->ProfileStartDate = apply_filters( "pmpro_profile_start_date", $order->ProfileStartDate, $order );

		//convert back to days
		$trial_period_days = ceil( abs( strtotime( date_i18n( "Y-m-d\TH:i:s" ), current_time( "timestamp" ) ) - strtotime( $order->ProfileStartDate, current_time( "timestamp" ) ) ) / 86400 );
		return $trial_period_days;
	}

	/**
	 * Create a subscription for a customer from an order using a Stripe Price.
	 *
	 * @since 2.7.0.
	 *
	 * @param string $customer_id to create subscription for.
	 * @param MemberOrder $order to pull subscription details from.
	 * @return Stripe_Subscription|bool false if error.
	 */
	public function create_subscription_for_customer_from_order( $customer_id, $order ) {
		$subtotal = $order->PaymentAmount;
		$tax      = $order->getTaxForPrice( $subtotal );
		$amount   = pmpro_round_price( (float) $subtotal + (float) $tax );
		// Set up the subscription.
		$product_id = $this->get_product_id_for_level( $order->membership_id );
		if ( empty( $product_id ) ) {
			$order->error = esc_html__( 'Cannot find product for membership level.', 'paid-memberships-pro' );
			return false;
		}

		$price = $this->get_price_for_product( $product_id, $amount, $order->BillingPeriod, $order->BillingFrequency );
		
		if ( empty( $price ) ) {
			$order->error = esc_html__( 'Cannot get price.', 'paid-memberships-pro' );
			return false;
		}

		$trial_period_days = $this->calculate_trial_period_days( $order );

		try {
			$subscription_params = array(
				'customer'          => $customer_id,
				'items'             => array(
					array( 'price' => $price ),
				),
				'trial_period_days' => $trial_period_days,
				'expand'                 => array(
					'pending_setup_intent.payment_method',
				),
			);
			if ( ! self::using_legacy_keys() ) {
				$params['application_fee_percent'] = self::get_application_fee_percentage();
			}
			$subscription_params = apply_filters( 'pmpro_stripe_create_subscription_array', $subscription_params );
			$subscription = Stripe_Subscription::create( $subscription_params );
		} catch ( Stripe\Error\Base $e ) {
			$order->error = $e->getMessage();
			return false;
		} catch ( \Throwable $e ) {
			$order->error = $e->getMessage();
			return false;
		} catch ( \Exception $e ) {
			$order->error = $e->getMessage();
			return false;
		}
		return $subscription;
	}

	/**
	 * Retrieve a payment intent.
	 *
	 * @since 2.7.0.
	 *
	 * @param string $payment_intent_id to retrieve.
	 * @return Stripe_PaymentIntent|string error.
	 */
	public function retrieve_payment_intent( $payment_intent_id ) {
		try {
			$payment_intent = Stripe_PaymentIntent::retrieve( $payment_intent_id );
		} catch ( Stripe\Error\Base $e ) {
			return $e->getMessage();
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		} catch ( \Exception $e ) {
			return $e->getMessage();
		}
		return $payment_intent;
	}

	/**
	 * Retrieve a setup intent.
	 *
	 * @since 2.7.0.
	 *
	 * @param string $setup_intent_id to retrieve.
	 * @return Stripe_SetupIntent|string error.
	 */
	private function retrieve_setup_intent( $setup_intent_id ) {
		try {
			$setup_intent_args = array(
				'id'     => $setup_intent_id,
				'expand' => array(
					'latest_attempt',
				),
			);
			$setup_intent = Stripe_SetupIntent::retrieve( $setup_intent_args );
		} catch ( Stripe\Error\Base $e ) {
			return $e->getMessage();
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		} catch ( \Exception $e ) {
			return $e->getMessage();
		}
		return $setup_intent;
	}

	/**
	 * Confirm the payment intent after authentication.
	 *
	 * @since 2.7.0.
	 *
	 * @param string $payment_intent_id to confirm.
	 * @return Stripe_PaymentIntent|string error.
	 */
	private function process_payment_intent( $payment_intent_id ) {
		// Get the payment intent.
		$payment_intent = $this->retrieve_payment_intent( $payment_intent_id );
		if ( is_string( $payment_intent ) ) {
			// There was an issue retrieving the payment intent.
			return $payment_intent;
		}

		// Confirm the payment.
		try {
			$params = array(
				'expand' => array(
					'payment_method',
					'customer'
				),
			);
			$payment_intent->confirm( $params );
		} catch ( Stripe\Error\Base $e ) {
			return $e->getMessage();
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		} catch ( \Exception $e ) {
			return $e->getMessage();
		}

		// Check that the confirmation was successful.
		if ( 'requires_action' == $payment_intent->status ) {
			return __( 'Customer authentication is required to finish setting up your subscription. Please complete the verification steps issued by your payment provider.', 'paid-memberships-pro' );
		}

		return $payment_intent;
	}

	/**
	 * Confirm the setup intent after authentication.
	 *
	 * @since 2.7.0.
	 *
	 * @param string $setup_intent_id to confirm.
	 * @return Stripe_SetupIntent|string error.
	 */
	private function process_setup_intent( $setup_intent_id ) {
		// Get the setup intent.
		$setup_intent = $this->retrieve_setup_intent( $setup_intent_id );
		if ( is_string( $setup_intent ) ) {
			return $setup_intent;
		}

		// Make sure that the setup intent was recently confirmed.
		/**
		 * The time in seconds that a setup intent must be confirmed within.
		 *
		 * @since 2.7.0
		 *
		 * @param int $seconds_to_confirm_setup_intent The time in seconds that a setup intent must be confirmed within.
		 */
		$setup_intent_timeout = apply_filters( 'pmpro_stripe_setup_intent_timeout', 60 * 10 );
		$last_setup_attempt_created = $setup_intent->latest_attempt->created;
		if (  $last_setup_attempt_created < time() - $setup_intent_timeout ) {
			return __( 'Cannot reuse an old setup intent.', 'paid-memberships-pro' );
		}

		// Make sure that the confirmation was successful.
		if ( 'requires_action' === $setup_intent->status ) {
			return __( 'Customer authentication is required to finish setting up your subscription. Please complete the verification steps issued by your payment provider.', 'paid-memberships-pro' );
		}
		return $setup_intent;
	}

	/**
	 * Add a subscription ID to the metadata of a setup intent.
	 *
	 * @since 2.7.0.
	 *
	 * @param Stripe_SetupIntent $setup_intent    The setup intent to add metadata to.
	 * @param string             $subscription_id The subscription ID that created this setup intent.
	 *
	 * @return Stripe_SetupIntent|string The setup intent object or an error message string.
	 */
	private function add_subscription_id_to_setup_intent( $setup_intent, $subscription_id ) {
		try {
			$setup_intent = Stripe_SetupIntent::update(
				$setup_intent->id,
				array(
					'metadata' => array(
						'subscription_id' => $subscription_id,
					),
					'expand' => array(
						'payment_method',
					),
				)
			);
		} catch ( \Throwable $e ) {
			return __( "Error adding metadata to setup intent.", 'paid-memberships-pro' );
		} catch ( \Exception $e ) {
			return __( "Error adding metadata to setup intent.", 'paid-memberships-pro' );
		}
		return $setup_intent;
	}

	/**
	 * Temporary function to allow users to view and delete subscription updates.
	 * Will be removed once subscription updates are completely deprecated.
	 *
	 * @since 2.7.0.
	 *
	 * @param WP_User $user whose profile is being shown.
	 * @param Stripe_Customer $customer associated with that user.
	 */
	private function user_profile_fields_subscription_updates( $user, $customer ) {
		global $pmpro_currency_symbol;

		$subscriptions = $customer->subscriptions->all();
		if ( empty( $subscriptions ) ) {
			// User does not have any subscriptions to udpate. Delete all updates.
			delete_user_meta( $user->ID, 'pmpro_stripe_updates' );
			return;
		}

		$cycles = array(
			__( 'Day(s)', 'paid-memberships-pro' )   => 'Day',
			__( 'Week(s)', 'paid-memberships-pro' )  => 'Week',
			__( 'Month(s)', 'paid-memberships-pro' ) => 'Month',
			__( 'Year(s)', 'paid-memberships-pro' )  => 'Year',
		);

		$current_year  = date_i18n( 'Y' );
		$current_month = date_i18n( 'm' );
		?>
            <h3><?php esc_html_e( 'Subscription Updates', 'paid-memberships-pro' ); ?></h3>
			<p><?php esc_html_e( 'Subscription updates will be deprecated in a future version of PMPro, though your existing subscription updates will still trigger as expected. We now instead reccomend updating the subscription directly in Stripe.', 'paid-memberships-pro' ); ?></p>
            <table class="form-table">
				<input type='hidden' name='pmpro_subscription_updates_visible' value='1' />
                <tr>
                    <th><label><?php esc_html_e( 'Update', 'paid-memberships-pro' ); ?></label></th>
                    <td id="updates_td">
						<?php
						$updates = $user->pmpro_stripe_updates;

						foreach ( $updates as $update ) {
							?>
                            <div class="updates_update">
                                <select class="updates_when" name="updates_when[]" disabled>
                                    <option value="now" <?php selected( $update['when'], 'now' ); ?>>Now</option>
                                    <option value="payment" <?php selected( $update['when'], 'payment' ); ?>>After
                                        Next Payment
                                    </option>
                                    <option value="date" <?php selected( $update['when'], 'date' ); ?>>On Date
                                    </option>
                                </select>
                                <span class="updates_date"
								      <?php if ( $update['when'] != 'date' ) { ?>style="display: none;"<?php } ?>>
								<select name="updates_date_month[]" disabled>
									<?php
									for ( $i = 1; $i < 13; $i ++ ) {
										?>
                                        <option value="<?php echo str_pad( $i, 2, '0', STR_PAD_LEFT ); ?>"
										        <?php if ( ! empty( $update['date_month'] ) && $update['date_month'] == $i ) { ?>selected="selected"<?php } ?>>
											<?php echo date_i18n( 'M', strtotime( $i . '/15/' . $current_year ) ); ?>
										</option>
										<?php
									}
									?>
								</select>
								<input name="updates_date_day[]" type="text" size="2"
                                       value="<?php if ( ! empty( $update['date_day'] ) ) {
									       echo esc_attr( $update['date_day'] );
								       } ?>" readonly/>
								<input name="updates_date_year[]" type="text" size="4"
                                       value="<?php if ( ! empty( $update['date_year'] ) ) {
									       echo esc_attr( $update['date_year'] );
								       } ?>" readonly/>
							</span>
                                <span class="updates_billing"
								      <?php if ( $update['when'] == "now" ) { ?>style="display: none;"<?php } ?>>
								<?php echo $pmpro_currency_symbol; ?><input name="updates_billing_amount[]" type="text"
                                                                           size="10"
                                                                           value="<?php echo esc_attr( $update['billing_amount'] ); ?>"
																		   readonly/>
								<small><?php esc_html_e( 'per', 'paid-memberships-pro' ); ?></small>
								<input name="updates_cycle_number[]" type="text" size="5"
                                       value="<?php echo esc_attr( $update['cycle_number'] ); ?>" readonly/>
								<select name="updates_cycle_period[]" disabled>
								  <?php
								  foreach ( $cycles as $name => $value ) {
									  echo "<option value='" . esc_attr( $value ) . "'";
									  if ( ! empty( $update['cycle_period'] ) && $update['cycle_period'] == $value ) {
										  echo " selected='selected'";
									  }
									  echo ">" . esc_html( $name ) . "</option>";
								  }
								  ?>
								</select>
							</span>
                                <span>
								<a class="updates_remove" href="javascript:void(0);"><?php esc_html_e( 'Remove', 'paid-memberships-pro' ); ?></a>
							</span>
                            </div>
							<script>
							jQuery(function () {
								//remove updates when clicking
								jQuery('.updates_remove').on('click', function () {
									jQuery(this).parent().parent().remove();
								});
								jQuery('form').on('submit', function () {
									// Makes sure that disabled select fields are still submitted.
									jQuery(this).find(':input').prop('disabled', false);
								});
							});
							</script>
							<?php
						}
						?>
                    </td>
                </tr>
            </table>
			<?php
	}

	

	/****************************************
	 ******* METHODS BECOMING PRIVATE *******
	 ****************************************/
	/**
	 * Get available webhooks
	 * 
	 * @since 2.4
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 */
	public static function get_webhooks( $limit = 10 ) {
		// Show deprecation warning if called publically.
		pmpro_method_should_be_private( '2.7.0' );

		if ( ! class_exists( 'Stripe\WebhookEndpoint' ) ) {
			// Load Stripe library.
			new PMProGateway_etsStripe();
			if ( ! class_exists( 'Stripe\WebhookEndpoint' ) ) {
				// Couldn't load library.
				return false;
			}
		}

		try {
			$webhooks = Stripe_Webhook::all( [ 'limit' => apply_filters( 'pmpro_stripe_webhook_retrieve_limit', $limit ) ] );
		} catch (\Throwable $th) {
			$webhooks = $th->getMessage();
		} catch (\Exception $e) {
			$webhooks = $e->getMessage();
		}
		
		return $webhooks;
	}

	/**
	 * Get current webhook URL for website to compare.
	 * 
	 * @since 2.4
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 */
	public static function get_site_webhook_url() {
		// Show deprecation warning if called publically.
		pmpro_method_should_be_private( '2.7.0' );
		return admin_url( 'admin-ajax.php' ) . '?action=stripe_webhook';
	}

	/**
	 * List of current enabled events required for PMPro to work.
	 * 
	 * @since 2.4
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 */
	public static function webhook_events() {
		// Show deprecation warning if called publically.
		pmpro_method_should_be_private( '2.7.0' );
		return apply_filters( 'pmpro_stripe_webhook_events', array(
			'invoice.payment_succeeded',
			'invoice.payment_action_required',
			'customer.subscription.deleted',
			'charge.failed'
		) );
	}

	/**
	 * Create webhook with relevant events
	 * 
	 * @since 2.4
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 */
	public static function create_webhook() {
		// Show deprecation warning if called publically.
		pmpro_method_should_be_private( '2.7.0' );

		try {
			$create = Stripe_Webhook::create([
				'url' => self::get_site_webhook_url(),
				'enabled_events' => self::webhook_events(),
				'api_version' => PMPRO_ETS_STRIPE_API_VERSION,
			]);

			if ( $create ) {
				return $create->id;
			}
		} catch (\Throwable $th) {
			//throw $th;
			return new WP_Error( 'error', $th->getMessage() );
		} catch (\Exception $e) {
			//throw $th;
			return new WP_Error( 'error', $e->getMessage() );
		}
		
	}

	/**
	 * See if a webhook is registered with Stripe.
	 * 
	 * @since 2.4
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 */
	public static function does_webhook_exist( $force = false ) {
		// Show deprecation warning if called publically.
		pmpro_method_should_be_private( '2.7.0' );

		static $cached_webhook = null;
		if ( ! empty( $cached_webhook ) && ! $force ) {
			return $cached_webhook;
		}

		$webhooks = self::get_webhooks();
		
		$webhook_id = false;
		if ( ! empty( $webhooks ) && ! empty( $webhooks['data'] ) ) {

			$pmpro_webhook_url = self::get_site_webhook_url();

			foreach( $webhooks as $webhook ) {
				if ( $webhook->url == $pmpro_webhook_url ) {
					$webhook_id = $webhook->id;
					$webhook_events = $webhook->enabled_events;
					$webhook_api_version = $webhook->api_version;
					$webhook_status = $webhook->status;
					continue;
				}
			}
		} else {
			$webhook_id = false; // make sure it's false if none are found.
		}

		if ( $webhook_id ) {
			$webhook_data = array();
			$webhook_data['webhook_id'] = $webhook_id;
			$webhook_data['enabled_events'] = $webhook_events;
			$webhook_data['api_version'] = $webhook_api_version;
			$webhook_data['status'] = $webhook_status;
			$cached_webhook = $webhook_data;
		} else {
			$cached_webhook = false;
		}
		return $cached_webhook;
	}

	/**
	 * Get a list of events that are missing between the created existing webhook and required webhook events for Paid Memberships Pro.
	 * 
	 * @since 2.4
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 */
	public static function check_missing_webhook_events( $webhook_events ) {
		// Show deprecation warning if called publically.
		pmpro_method_should_be_private( '2.7.0' );

		// Get required events
		$pmpro_webhook_events = self::webhook_events();

		// No missing events if webhook event is "All Events" selected.
		if ( is_array( $webhook_events ) && $webhook_events[0] === '*' ) {
			return false;
		} 

		if ( count( array_diff( $pmpro_webhook_events, $webhook_events ) ) ) {
			$events = array_unique( array_merge( $pmpro_webhook_events, $webhook_events ) );
			// Force reset of indexes for Stripe.
			$events = array_values( $events );
		} else {
			$events = false;
		}

		return $events;
	}

	/**
	 * Update required webhook enabled events.
	 * 
	 * @since 2.4
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 */
	public static function update_webhook_events() {
		// Show deprecation warning if called publically.
		pmpro_method_should_be_private( '2.7.0' );

		// Also checks database to see if it's been saved.
		$webhook = self::does_webhook_exist();

		if ( empty( $webhook ) ) {
			$create = self::create_webhook();
			return $create;
		}

		// Bail if no enabled events for a webhook are passed through.
		if ( ! isset( $webhook['enabled_events'] ) ) {
			return;
		}
		
		$events = self::check_missing_webhook_events( $webhook['enabled_events'] );
		
		if ( $events ) {

			try {
				$update = Stripe_Webhook::update( 
					$webhook['webhook_id'],
					['enabled_events' => $events ]
				);
	
				if ( $update ) {
					return $update;
				}
			} catch (\Throwable $th) {
				//throw $th;
				return new WP_Error( 'error', $th->getMessage() );
			} catch (\Exception $e) {
				//throw $th;
				return new WP_Error( 'error', $e->getMessage() );
			}
				
		}
		
	}

	/**
	 * Delete an existing webhook.
	 * 
	 * @since 2.4
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 */
	public static function delete_webhook( $webhook_id, $secretkey = false ) {
		// Show deprecation warning if called publically.
		pmpro_method_should_be_private( '2.7.0' );

		if ( empty( $secretkey ) ) {
			$secretkey = self::get_secretkey();
		}
		if ( is_array( $webhook_id ) ) {
			$webhook_id = $webhook_id['webhook_id'];
		}
		
		try {
			$stripe = new Stripe_Client( $secretkey );
			$delete = $stripe->webhookEndpoints->delete( $webhook_id, [] );
		} catch (\Throwable $th) {
			return new WP_Error( 'error', $th->getMessage() );
		} catch (\Exception $e) {
			return new WP_Error( 'error', $e->getMessage() );
		}

		return $delete;
	}

	/**
	 * Helper method to save the subscription ID to make sure the membership doesn't get cancelled by the webhook
	 *
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 */
	public static function ignoreCancelWebhookForThisSubscription( $subscription_id, $user_id = null ) {
		pmpro_method_should_be_private( '2.7.0' );

		if ( empty( $user_id ) ) {
			global $current_user;
			$user_id = $current_user->ID;
		}

		$preserve = get_user_meta( $user_id, 'pmpro_stripe_dont_cancel', true );

		// No previous values found, init the array
		if ( empty( $preserve ) ) {
			$preserve = array();
		}

		// Store or update the subscription ID timestamp (for cleanup)
		$preserve[ $subscription_id ] = current_time( 'timestamp' );

		update_user_meta( $user_id, 'pmpro_stripe_dont_cancel', $preserve );
	}

	/**
	 * Helper method to process a Stripe subscription update.
	 *
	 * Only called during subscription updates. Should be completely deprecated once that functionality is removed.
	 *
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 */
	static function updateSubscription( $update, $user_id ) {
		pmpro_method_should_be_private( '2.7.0' );
		global $wpdb;

		//get level for user
		$user_level = pmpro_getMembershipLevelForUser( $user_id );

		//get current plan at Stripe to get payment date
		$last_order = new MemberOrder();
		$last_order->getLastMemberOrder( $user_id );
		$last_order->setGateway( 'etsstripe' );
		$last_order->Gateway->update_customer_at_checkout( $last_order );

		$subscription = $last_order->Gateway->get_subscription( $last_order->subscription_transaction_id );

		if ( ! empty( $subscription ) ) {
			$end_timestamp = $subscription->current_period_end;

			//cancel the old subscription
			if ( ! $last_order->Gateway->cancelSubscriptionAtGateway( $subscription, true ) ) {
				//throw error and halt save
				if ( ! function_exists( 'pmpro_stripe_user_profile_fields_save_error' ) ) {
					//throw error and halt save
					function pmpro_stripe_user_profile_fields_save_error( $errors, $update, $user ) {
						$errors->add( 'pmpro_stripe_updates', __( 'Could not cancel the old subscription. Updates have not been processed.', 'paid-memberships-pro' ) );
					}

					add_filter( 'user_profile_update_errors', 'pmpro_stripe_user_profile_fields_save_error', 10, 3 );
				}

				//stop processing updates
				return;
			}
		}

		//if we didn't get an end date, let's set one one cycle out
		if ( empty( $end_timestamp ) ) {
			$end_timestamp = strtotime( "+" . $update['cycle_number'] . " " . $update['cycle_period'], current_time( 'timestamp' ) );
		}

		//build order object
		$update_order = new MemberOrder();
		$update_order->setGateway( 'etsstripe' );
		$update_order->code             = $update_order->getRandomCode();
		$update_order->user_id          = $user_id;
		$update_order->membership_id    = $user_level->id;
		$update_order->membership_name  = $user_level->name;
		$update_order->InitialPayment   = 0;
		$update_order->PaymentAmount    = $update['billing_amount'];
		$update_order->ProfileStartDate = date_i18n( "Y-m-d", $end_timestamp );
		$update_order->BillingPeriod    = $update['cycle_period'];
		$update_order->BillingFrequency = $update['cycle_number'];
		$update_order->getMembershipLevel();

		//need filter to reset ProfileStartDate
		$profile_start_date = $update_order->ProfileStartDate;
		add_filter( 'pmpro_profile_start_date', function ( $startdate, $order ) use ( $profile_start_date ) {
			return "{$profile_start_date}T0:0:0";
		}, 10, 2 );

		//update subscription
		$customer = $update_order->Gateway->update_customer_at_checkout( $update_order, true );
		$order->stripe_customer = $customer;
		$update_order->Gateway->process_subscriptions( $update_order );

		//update membership
		$sqlQuery = "UPDATE $wpdb->pmpro_memberships_users
						SET billing_amount = '" . esc_sql( $update['billing_amount'] ) . "',
							cycle_number = '" . esc_sql( $update['cycle_number'] ) . "',
							cycle_period = '" . esc_sql( $update['cycle_period'] ) . "',
							trial_amount = '',
							trial_limit = ''
						WHERE user_id = '" . esc_sql( $user_id ) . "'
							AND membership_id = '" . esc_sql( $last_order->membership_id ) . "'
							AND status = 'active'
						LIMIT 1";

		$wpdb->query( $sqlQuery );

		//save order so we know which plan to look for at stripe (order code = plan id)
		$update_order->Gateway->clean_up( $update_order );
		$update_order->status = "success";
		$update_order->saveOrder();
	}

	/**
	 * Update the payment method for a subscription.
	 *
	 * Only called on update billing page. Should be completely deprecated if we switch to using Stripe Customer Portal.
	 *
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 *
	 * @param MemberOrder $order The MemberOrder object.
	 */
	public function update_payment_method_for_subscriptions( &$order ) {
		pmpro_method_should_be_private( '2.7.0' );

		// get customer
		$customer = $this->update_customer_at_checkout( $order );
		
		if ( empty( $customer ) ) {
			return false;
		}
		
		// get all subscriptions
		if ( ! empty( $customer->subscriptions ) ) {
			$subscriptions = $customer->subscriptions->all();
		}
		
		foreach( $subscriptions as $subscription ) {
			// check if cancelled or expired
			if ( in_array( $subscription->status, array( 'canceled', 'incomplete', 'incomplete_expired' ) ) ) {
				continue;
			}
			
			// check if we have a related order for it
			$one_order = new MemberOrder();
			$one_order->getLastMemberOrderBySubscriptionTransactionID( $subscription->id );
			if ( empty( $one_order ) || empty( $one_order->id ) ) {
				continue;
			}
			
			// update the payment method
			$subscription->default_payment_method = $customer->invoice_settings->default_payment_method;
			$subscription->save();
		}
		return true;
	}

	/**
	 * Helper method to cancel a subscription at Stripe and also clear up any upaid invoices.
	 *
	 * @since 1.8
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 */
	public function cancelSubscriptionAtGateway( $subscription, $preserve_local_membership = false ) {
		pmpro_method_should_be_private( '2.7.0' );

		// Check if a valid sub.
		if ( empty( $subscription ) || empty( $subscription->id ) ) {
			return false;
		}

		// If this is already cancelled, return true.
		if ( ! empty( $subscription->canceled_at ) ) {
			return true;
		}

		// Make sure we get the customer for this subscription.
		$order = new MemberOrder();
		$order->getLastMemberOrderBySubscriptionTransactionID( $subscription->id );

		// No order?
		if ( empty( $order ) ) {
			//lets cancel anyway, but this is suspicious
			$r = $subscription->cancel();

			return true;
		}

		// Okay have an order, so get customer so we can cancel invoices too
		$customer = $this->update_customer_at_checkout( $order );

		// Get open invoices.
		$invoices = Stripe_Invoice::all(['customer' => $customer->id, 'status' => 'open']);

		// Found it, cancel it.
		try {
			// Find any open invoices for this subscription and forgive them.
			if ( ! empty( $invoices ) ) {
				foreach ( $invoices->data as $invoice ) {
					if ( 'open' == $invoice->status && $invoice->subscription == $subscription->id ) {
						$invoice->voidInvoice();
					}
				}
			}

			// Sometimes we don't want to cancel the local membership when Stripe sends its webhook.
			if ( $preserve_local_membership ) {
				self::ignoreCancelWebhookForThisSubscription( $subscription->id, $order->user_id );
			}

			// Cancel
			$r = $subscription->cancel();

			return true;
		} catch ( \Throwable $e ) {
			return false;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 */
	public function get_payment_method( &$order ) {
		pmpro_method_should_be_private( '2.7.0' );
		if ( ! empty( $order->payment_method_id ) ) {
			try {
				$payment_method = Stripe_PaymentMethod::retrieve( $order->payment_method_id );
			} catch ( Stripe\Error\Base $e ) {
				$order->error = $e->getMessage();
				return false;
			} catch ( \Throwable $e ) {
				$order->error = $e->getMessage();
				return false;
			} catch ( \Exception $e ) {
				$order->error = $e->getMessage();
				return false;
			}
		}

		if ( empty( $payment_method ) ) {
			return false;
		}

		return $payment_method;
	}

	/**
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private in a future version.
	 */
	public function process_charges( &$order ) {
		pmpro_method_should_be_private( '2.7.0' );
		if ( 0 == floatval( $order->InitialPayment ) ) {
			return true;
		}

		$payment_intent = $this->get_payment_intent( $order );
		update_option('ets_check_payment_intent',$payment_intent);
		if (!empty($payment_intent)) {
			update_option('ets_check_payment_intent_id',$payment_intent->id);
			$order->payment_intent_id = $payment_intent->id;
			update_pmpro_membership_order_meta($order->id, '_ets_stripe_payment_intent_id', $payment_intent->id);
		}
			
		if ( empty( $payment_intent) ) {
			// There was an error, and the message should already
			// be saved on the order.
			return false;
		}
		// Save payment intent to order so that we can use it in confirm_payment_intent().
		$order->stripe_payment_intent = $payment_intent;

		//$this->confirm_payment_intent( $order );

		if ( ! empty( $order->error ) ) {
			$order->error = $order->error;

			return false;
		}

		return true;
	}

	/**
	 * Only called during subscription updates. Should be completely deprecated once that functionality is removed.
	 *
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 *
	 * @param MemberOrder $order The MemberOrder object.
	 */
	public function get_setup_intent( &$order ) {
		pmpro_method_should_be_private( '2.7.0' );
		if ( ! empty( $order->setup_intent_id ) ) {
			try {
				$setup_intent = Stripe_SetupIntent::retrieve( $order->setup_intent_id );
			} catch ( Stripe\Error\Base $e ) {
				$order->error = $e->getMessage();
				return false;
			} catch ( \Throwable $e ) {
				$order->error = $e->getMessage();
				return false;
			} catch ( \Exception $e ) {
				$order->error = $e->getMessage();
				return false;
			}
		}

		if ( empty( $setup_intent ) ) {
			$setup_intent = $this->create_setup_intent( $order );
		}

		if ( empty( $setup_intent ) ) {
			return false;
		}

		return $setup_intent;
	}

	/**
	 * @deprecated 2.7.0. Use get_setup_intent() instead.
	 */
	public function set_setup_intent( &$order, $force = false ) {
		_deprecated_function( __FUNCTION__, '2.7.0', 'get_setup_intent()' );
		if ( ! empty( $this->setup_intent ) && ! $force ) {
			return true;
		}

		$setup_intent = $this->get_setup_intent( $order );

		if ( empty( $setup_intent ) ) {
			return false;
		}

		$this->setup_intent = $setup_intent;

		return true;
	}

	/**
	 * Only called during subscription updates. Should be completely deprecated once that functionality is removed.
	 *
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 *
	 * @param MemberOrder $order The MemberOrder object.
	 */
	public function create_setup_intent( &$order ) {
		pmpro_method_should_be_private( '2.7.0' );
		$this->create_plan( $order );
		$order->stripe_subscription = $this->create_subscription( $order );
		$this->delete_plan( $order );

		if ( ! empty( $order->error ) || empty( $order->stripe_subscription->pending_setup_intent ) ) {
			return false;
		}

		return $order->stripe_subscription->pending_setup_intent;
	}

	/**
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 */
	public function confirm_payment_intent( &$order ) {
		pmpro_method_should_be_private( '2.7.0' );
		try {
			$params = array(
				'expand' => array(
					'payment_method',
				),
			);
			$order->stripe_payment_intent->confirm( $params );
		} catch ( Stripe\Error\Base $e ) {
			$order->error = $e->getMessage();
			return false;
		} catch ( \Throwable $e ) {
			$order->error = $e->getMessage();
			return false;
		} catch ( \Exception $e ) {
			$order->error = $e->getMessage();
			return false;
		}

		if ( 'requires_action' == $order->stripe_payment_intent->status ) {
			$order->errorcode = true;
			$order->error = __( 'Customer authentication is required to complete this transaction. Please complete the verification steps issued by your payment provider.', 'paid-memberships-pro' );
			$order->error_type = 'pmpro_alert';

			return false;
		}

		return true;
	}

	/**
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 */
	public function confirm_setup_intent( &$order ) {
    pmpro_method_should_be_private( '2.7.0' );
		if ( empty( $order->stripe_setup_intent ) ) {
			return true;
		}

		if ( 'requires_action' === $order->stripe_setup_intent->status ) {
			$order->errorcode = true;
			$order->error     = __( 'Customer authentication is required to finish setting up your subscription. Please complete the verification steps issued by your payment provider.', 'paid-memberships-pro' );

			return false;
		}

	}

	/**
 	 * Get available Apple Pay domains.
	 *
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
 	 */
	public function pmpro_get_apple_pay_domains( $limit = 10 ) {
		pmpro_method_should_be_private( '2.7.0' );
		try {
			$apple_pay_domains = Stripe_ApplePayDomain::all( [ 'limit' => apply_filters( 'pmpro_stripe_apple_pay_domain_retrieve_limit', $limit ) ] );
		} catch (\Throwable $th) {
			$apple_pay_domains = array();
	   	}

		return $apple_pay_domains;
	}

	/**
 	 * Register domain with Apple Pay.
 	 * 
 	 * @since 2.4
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
 	 */
	public function pmpro_create_apple_pay_domain() {
		pmpro_method_should_be_private( '2.7.0' );
		try {
			$create = Stripe_ApplePayDomain::create([
				'domain_name' => $_SERVER['HTTP_HOST'],
			]);
		} catch (\Throwable $th) {
			//throw $th;
			return false;
		}
		return $create;
	}

	/**
 	 * See if domain is registered with Apple Pay.
 	 * 
 	 * @since 2.4
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
 	 */
	public function pmpro_does_apple_pay_domain_exist() {
		pmpro_method_should_be_private( '2.7.0' );
		$apple_pay_domains = $this->pmpro_get_apple_pay_domains();

		if ( empty( $apple_pay_domains ) ) {
			return false;
		}

		foreach( $apple_pay_domains as $apple_pay_domain ) {
			if ( $apple_pay_domain->domain_name === $_SERVER['HTTP_HOST'] ) {
				return true;
			}
		}
		return false;
   }

   /**
	* @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
    */
	public function get_account() {
		pmpro_method_should_be_private( '2.7.0' );
		try {
			$account = Stripe_Account::retrieve();
		} catch ( Stripe\Error\Base $e ) {
			return false;
		} catch ( \Throwable $e ) {
			return false;
		} catch ( \Exception $e ) {
			return false;
		}

		if ( empty( $account ) ) {
			return false;
		}

		return $account;
	}

	/**
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 */
	public static function get_account_country() {
		pmpro_method_should_be_private( '2.7.0' );
		$account_country = get_transient( 'pmpro_stripe_account_country' );
		if ( empty( $account_country ) ) {
			$stripe = new PMProGateway_etsStripe();
			$account = $stripe->get_account();
			if ( ! empty( $account ) && ! empty( $account->country ) ) {
				$account_country = $account->country;
				set_transient( 'pmpro_stripe_account_country', $account_country );
			}
		}
		return $account_country ?: 'US';
	}

	/**
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 */
	public function clean_up( &$order ) {
    pmpro_method_should_be_private( '2.7.0' );
		if ( ! empty( $order->stripe_payment_intent ) && 'succeeded' == $order->stripe_payment_intent->status ) {
			$order->payment_transaction_id = $order->stripe_payment_intent->charges->data[0]->id;
		}

		if ( empty( $order->subscription_transaction_id ) && ! empty( $order->stripe_subscription ) ) {
			$order->subscription_transaction_id = $order->stripe_subscription->id;
		}
	}

	/**
	 * Get percentage of Stripe payment to charge as application fee.
	 *
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 *
	 * @return int percentage to charge for application fee.
	 */
	public static function get_application_fee_percentage() {
		pmpro_method_should_be_private( '2.7.0' );
		$application_fee_percentage = pmpro_license_isValid( null, 'plus' ) ? 0 : 1;
		$application_fee_percentage = apply_filters( 'pmpro_set_application_fee_percentage', $application_fee_percentage );
		return round( floatval( $application_fee_percentage ), 2 );
	}

	/**
	 * Add application fee to params to be sent to Stripe.
	 *
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 *
	 * @param  array $params to be sent to Stripe.
	 * @param  bool  $add_percent true if percentage should be added, false if actual amount.
	 * @return array params with application fee if applicable.
	 */
	public static function add_application_fee_amount( $params ) {
		pmpro_method_should_be_private( '2.7.0' );
		if ( empty( $params['amount'] ) || self::using_legacy_keys() ) {
			return $params;
		}
		$amount = $params['amount'];
		$application_fee = $amount * ( self::get_application_fee_percentage() / 100 );
		$application_fee = floor( $application_fee );
		if ( ! empty( $application_fee ) ) {
			$params['application_fee_amount'] = intval( $application_fee );
		}
		return $params;
	}

	/**
	 * Should we show the legacy key fields on the payment settings page.
	 * We should if the site is using legacy keys already or
	 * if a filter has been set.
	 * @since 2.6
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 */
	public static function show_legacy_keys_settings() {
		pmpro_method_should_be_private( '2.7.0' );
		$r = self::using_legacy_keys();
		$r = apply_filters( 'pmpro_stripe_show_legacy_keys_settings', $r );
		return $r;
	}

	/**
	 * Get the Stripe secret key based on gateway environment.
	 *
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 *
	 * @return The Stripe secret key.
	 */
	public static function get_secretkey() {
		pmpro_method_should_be_private( '2.7.0' );
		$secretkey = '';
		if ( self::using_legacy_keys() ) {
			$secretkey = pmpro_getOption( 'stripe_secretkey' ); 
		} else {
			$secretkey = pmpro_getOption( 'gateway_environment' ) === 'live'
				? pmpro_getOption( 'live_stripe_connect_secretkey' )
				: pmpro_getOption( 'sandbox_stripe_connect_secretkey' );
		}
		return $secretkey;
	}

	/**
	 * Get the Stripe publishable key based on gateway environment.
	 *
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 *
	 * @return The Stripe publishable key.
	 */
	public static function get_publishablekey() {
		pmpro_method_should_be_private( '2.7.0' );
		$publishablekey = '';
		if ( self::using_legacy_keys() ) {
			$publishablekey = pmpro_getOption( 'stripe_publishablekey' ); 
		} else {
			$publishablekey = pmpro_getOption( 'gateway_environment' ) === 'live'
				? pmpro_getOption( 'live_stripe_connect_publishablekey' )
				: pmpro_getOption( 'sandbox_stripe_connect_publishablekey' );
		}
		return $publishablekey;
	}

	/**
	 * Get the Stripe Connect User ID based on gateway environment.
	 *
	 * @since 2.6.0
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 *
	 * @return string The Stripe Connect User ID.
	 */
	public static function get_connect_user_id() {
		pmpro_method_should_be_private( '2.7.0' );
		return pmpro_getOption( 'gateway_environment' ) === 'live'
			? pmpro_getOption( 'live_stripe_connect_user_id' )
			: pmpro_getOption( 'sandbox_stripe_connect_user_id' );
	}

	/**
	 * Determine whether the webhook is working by checking for Stripe orders with invalid transaction IDs.
	 *
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 *
	 * @param string|null $gateway_environment to check webhooks for. Defaults to set gateway environment.
	 * @return bool Whether the webhook is working.
	 */
	public static function webhook_is_working( $gateway_environment = null ) {
		pmpro_method_should_be_private( '2.7.0' );
		global $wpdb;

		if ( empty( $gateway_environment ) ) {
			$gateway_engvironemnt = pmpro_getOption( 'pmpro_gateway_environment' );
		}

		$last_webhook = get_option( 'pmpro_stripe_last_webhook_received_' . $gateway_environment );

		if ( empty( $last_webhook ) ) {
			// Probably never got a webhook event.
			$last_webhook_safe = date( 'Y-m-d H:i:s', strtotime( '-5 years' ) );
		} else {
			// In case recurring order made after webhook event received.
			$last_webhook_safe  = date( 'Y-m-d H:i:s', strtotime( $last_webhook . ' +5 minutes' ) );
		}

		$hour_before_now = date( 'Y-m-d H:i:s', strtotime( '-1 hour' ) );

		$num_problem_orders = $wpdb->get_var(
			$wpdb->prepare(
				"
					SELECT COUNT(*)
					FROM `{$wpdb->pmpro_membership_orders}`
					WHERE
						`gateway` = 'etsstripe'
						AND `gateway_environment` = %s
						AND `subscription_transaction_id` <> '' 
						AND `subscription_transaction_id` IS NOT NULL
						AND `timestamp` > %s
						AND `timestamp` < %s
				",
				$gateway_environment,
				$last_webhook_safe,
				$hour_before_now
			)
		);

		return ( empty( $num_problem_orders ) );
	}
	
	/**
	 * Get the date the last webhook was processed.
	 * @param environment The gateway environment (live or sandbox) to check for.
	 * @returns HTML with the date of the last webhook or an error message.
	 * @since 2.6
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 */
	public static function get_last_webhook_date( $environment = 'live' ) {
		pmpro_method_should_be_private( '2.7.0' );
		$last_webhook = get_option( 'pmpro_stripe_last_webhook_received_' . $environment );
		if ( ! empty( $last_webhook ) ) {
			echo '<p>' . esc_html__( 'Last webhook received at', 'paid-memberships-pro' ) . ': ' . esc_html( $last_webhook ) . ' GMT.</p>';
		} else {
			echo '<p>' . esc_html__( 'No webhooks have been received.', 'paid-memberships-pro' ) . '</p>';
		}
		if ( ! self::webhook_is_working( $environment ) ) {
			echo '<div class="notice error inline"><p>';
			echo esc_html__( 'Your webhook may not be working correctly.', 'paid-memberships-pro' );
			echo ' <a target="_blank" href="https://www.paidmembershipspro.com/gateway/stripe/?utm_source=plugin&utm_medium=pmpro-paymentsettings&utm_campaign=gateways&utm_content=stripe-webhook#tab-gateway-setup">';
			echo esc_html__( 'Click here for info on setting up your webhook with Stripe.', 'paid-memberships-pro' );
			echo '</a>';
			echo '</p></div>';
		}
	}

	/**
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private in a future version.
	 */
	public function get_payment_intent( &$order ) {
		pmpro_method_should_be_private( '2.7.0' );
		if ( ! empty( $order->payment_intent_id ) ) {
			try {
				$payment_intent = Stripe_PaymentIntent::retrieve( $order->payment_intent_id );
			} catch ( Stripe\Error\Base $e ) {
				$order->error = $e->getMessage();
				return false;
			} catch ( \Throwable $e ) {
				$order->error = $e->getMessage();
				return false;
			} catch ( \Exception $e ) {
				$order->error = $e->getMessage();
				return false;
			}
		}

		if ( empty( $payment_intent ) ) {
			$payment_intent = $this->create_payment_intent( $order );

		}

		if ( empty( $payment_intent ) ) {
			return false;
		}

		return $payment_intent;
	}

	/**
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private in a future version.
	 */
	public function create_payment_intent( &$order ) {
		pmpro_method_should_be_private( '2.7.0' );
		global $pmpro_currency;

		$amount          = $order->InitialPayment;
		$order->subtotal = $amount;
		$tax             = $order->getTax( true );

		$amount = pmpro_round_price( (float) $order->subtotal + (float) $tax );

		$params = array(
			'customer'               => $order->stripe_customer->id,
			'payment_method'         => $order->payment_method_id,
			'amount'                 => $this->convert_price_to_unit_amount( $amount ),
			'currency'               => $pmpro_currency,
			'confirmation_method'    => 'manual',
			'description'            => apply_filters( 'pmpro_stripe_order_description', "Order #" . $order->code . ", " . trim( $order->FirstName . " " . $order->LastName ) . " (" . $order->Email . ")", $order ),
			'setup_future_usage'     => 'off_session',
		);
		$params = self::add_application_fee_amount( $params );

		/**
		 * Filter params used to create the payment intent.
		 *
		 * @since 2.4.1
		 *
	 	 * @param array  $params 	Array of params sent to Stripe.
		 * @param object $order		Order object for this checkout.
		 */
		$params = apply_filters( 'pmpro_stripe_payment_intent_params', $params, $order );

		try {
			$payment_intent = Stripe_PaymentIntent::create( $params );
		} catch ( Stripe\Error\Base $e ) {
			$order->error = $e->getMessage();
			return false;
		} catch ( \Throwable $e ) {
			$order->error = $e->getMessage();
			return false;
		} catch ( \Exception $e ) {
			$order->error = $e->getMessage();
			return false;
		}

		return $payment_intent;
	}

	/**
	 * Only called during subscription updates. Should be completely deprecated once that functionality is removed.
	 *
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private in a future version.
	 *
	 * @param MemberOrder $order The MemberOrder object.
	 */
	public function process_subscriptions( &$order ) {
		pmpro_method_should_be_private( '2.7.0' );
		if ( ! pmpro_isLevelRecurring( $order->membership_level ) ) {
			return true;
		}

		//before subscribing, let's clear out the updates so we don't trigger any during sub
		if ( ! empty( $user_id ) ) {
			$old_user_updates = get_user_meta( $user_id, "pmpro_stripe_updates", true );
			update_user_meta( $user_id, "pmpro_stripe_updates", array() );
		}

		$setup_intent = $this->get_setup_intent( $order );
		if ( empty( $setup_intent ) ) {
			// There was an error, and the message should already
			// be saved on the order.
			return false;
		}
		// Save setup intent to order so that we can use it in confirm_setup_intent().
		$order->stripe_setup_intent = $setup_intent;

		$this->confirm_setup_intent( $order );

		if ( ! empty( $order->error ) ) {
			$order->error = $order->error;

			//give the user any old updates back
			if ( ! empty( $user_id ) ) {
				update_user_meta( $user_id, "pmpro_stripe_updates", $old_user_updates );
			}

			return false;
		}

		//save new updates if this is at checkout
		//empty out updates unless set above
		if ( empty( $new_user_updates ) ) {
			$new_user_updates = array();
		}

		//update user meta
		if ( ! empty( $user_id ) ) {
			update_user_meta( $user_id, "pmpro_stripe_updates", $new_user_updates );
		} else {
			//need to remember the user updates to save later
			global $pmpro_stripe_updates;
			$pmpro_stripe_updates = $new_user_updates;
			
			if( ! function_exists( 'pmpro_user_register_stripe_updates' ) ) {
				function pmpro_user_register_stripe_updates( $user_id ) {
					global $pmpro_stripe_updates;
					update_user_meta( $user_id, 'pmpro_stripe_updates', $pmpro_stripe_updates );
				}
				add_action( 'user_register', 'pmpro_user_register_stripe_updates' );
			}
		}

		return true;
	}

	/**
	 * Only called during subscription updates. Should be completely deprecated once that functionality is removed.
	 *
	 * @deprecated 2.7.0. Will only be deprecated once we create a function with better params.
	 *
	 * @param MemberOrder $order The MemberOrder object.
	 */
	public function create_subscription( &$order ) {
		// _deprecated_function( __FUNCTION__, '2.7.0' );
		//subscribe to the plan
		try {
			$params              = array(
				'customer'               => $order->stripe_customer->id,
				'items'                  => array(
					array( 'plan' => $order->code ),
				),
				'trial_period_days'      => $order->TrialPeriodDays,
				'expand'                 => array(
					'pending_setup_intent.payment_method',
				),
			);
			if ( ! self::using_legacy_keys() ) {
				$params['application_fee_percent'] = self::get_application_fee_percentage();
			}
			$order->subscription = Stripe_Subscription::create( apply_filters( 'pmpro_stripe_create_subscription_array', $params ) );
		} catch ( Stripe\Error\Base $e ) {
			$order->error = $e->getMessage();
			return false;
		} catch ( \Throwable $e ) {
			$order->error = $e->getMessage();
			return false;
		} catch ( \Exception $e ) {
			$order->error = $e->getMessage();
			return false;
		}

		return $order->subscription;

	}

	/**
	 * Only called during subscription updates. Should be completely deprecated once that functionality is removed.
	 *
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 *
	 * @param MemberOrder $order The MemberOrder object.
	 */
	public function delete_plan( &$order ) {
		// _deprecated_function( __FUNCTION__, '2.7.0' );
		try {
			// Delete the product first while we have a reference to it...
			if ( ( ! empty( $order->plan->product ) ) && ( ! $this->archive_product( $order ) ) ) {
				return false;
			}
			// Then delete the plan.
			$order->plan->delete();
		} catch ( Stripe\Error\Base $e ) {
			$order->error = $e->getMessage();

			return false;
		} catch ( \Throwable $e ) {
			$order->error = $e->getMessage();

			return false;
		} catch ( \Exception $e ) {
			$order->error = $e->getMessage();

			return false;
		}

		return true;
	}

	/**
	 * Only called during subscription updates. Should be completely deprecated once that functionality is removed.
	 *
	 * @deprecated 2.7.0. Only deprecated for public use, will be changed to private non-static in a future version.
	 *
	 * @param MemberOrder $order The MemberOrder object.
	 */
	public function archive_product( &$order ) {
		// _deprecated_function( __FUNCTION__, '2.7.0' );
		try {
			$product = Stripe_Product::update( $order->plan->product, array( 'active' => false ) );
		} catch ( Stripe\Error\Base $e ) {
			$order->error = $e->getMessage();
			return false;
		} catch ( \Throwable $e ) {
			$order->error = $e->getMessage();
			return false;
		} catch ( \Exception $e ) {
			$order->error = $e->getMessage();
			return false;
		}

		return true;
	}

	/****************************************
	 ********** DEPRECATED METHODS **********
	 ****************************************/
	/**
	 * Make a one-time charge with Stripe
	 *
	 * @since 1.4
	 * @deprecated 2.7.0. Use process_charges() instead.
	 */
	public function charge( &$order ) {
		_deprecated_function( __FUNCTION__, '2.7.0' );
		global $pmpro_currency;

		//create a code for the order
		if ( empty( $order->code ) ) {
			$order->code = $order->getRandomCode();
		}

		//what amount to charge?
		$amount = $order->InitialPayment;

		//tax
		$order->subtotal = $amount;
		$tax             = $order->getTax( true );
		$amount          = pmpro_round_price( (float) $order->subtotal + (float) $tax );

		//create a customer
		$customer = $this->update_customer_at_checkout( $order );
		if ( empty( $customer ) ) {
			//failed to create customer
			return false;
		}

		//charge
		try {
			$params = array(
					"amount"      => $this->convert_price_to_unit_amount( $amount ), # amount in cents, again
					"currency"    => strtolower( $pmpro_currency ),
					"customer"    => $customer->id,
					"description" => apply_filters( 'pmpro_stripe_order_description', "Order #" . $order->code . ", " . trim( $order->FirstName . " " . $order->LastName ) . " (" . $order->Email . ")", $order )
				);
			$params   = self::add_application_fee_amount( $params  );
			/**
			 * Filter params used to create the Stripe charge.
			 *
			 * @since 2.4.4
			 *
		 	 * @param array  $params 	Array of params sent to Stripe.
			 * @param object $order		Order object for this checkout.
			 */
			$params = apply_filters( 'pmpro_stripe_charge_params', $params, $order );
			$response = Stripe_Charge::create( $params );
		} catch ( \Throwable $e ) {
			//$order->status = "error";
			$order->errorcode  = true;
			$order->error      = "Error: " . $e->getMessage();
			$order->shorterror = $order->error;

			return false;
		} catch ( \Exception $e ) {
			//$order->status = "error";
			$order->errorcode  = true;
			$order->error      = "Error: " . $e->getMessage();
			$order->shorterror = $order->error;

			return false;
		}

		if ( empty( $response["failure_message"] ) ) {
			//successful charge
			$order->payment_transaction_id = $response["id"];
			$order->updateStatus( "success" );
			$order->saveOrder();

			return true;
		} else {
			//$order->status = "error";
			$order->errorcode  = true;
			$order->error      = $response['failure_message'];
			$order->shorterror = $response['failure_message'];

			return false;
		}
	}

	/**
	 * Get a Stripe Customer object and update it.
	 *
	 * @since 1.4
	 * @deprecated 2.7.0. Use get_customer_for_user() or update_customer_from_user().
	 *
	 * @return Stripe_Customer|false
	 */
	public function getCustomer( &$order = false, $force = false ) {
		_deprecated_function( __FUNCTION__, '2.7.0', 'update_customer_from_user()' );
		return $this->update_customer_at_checkout( $order );
	}

	/**
	 * Get a Stripe subscription from a PMPro order
	 *
	 * @since 1.8
	 * @deprecated 2.7.0. Need to write replacement methods for this.
	 */
	public function getSubscription( &$order ) {
		_deprecated_function( __FUNCTION__, '2.7.0' );
		global $wpdb;

		//no order?
		if ( empty( $order ) || empty( $order->code ) ) {
			return false;
		}

		$customer = $this->update_customer_at_checkout( $order, true );    //force so we don't get a cached sub for someone else

		//no customer?
		if ( empty( $customer ) ) {
			return false;
		}

		//no subscriptions?
		if ( empty( $customer->subscriptions ) ) {
			return false;
		}

		//is there a subscription transaction id pointing to a sub?
		if ( ! empty( $order->subscription_transaction_id ) && strpos( $order->subscription_transaction_id, "sub_" ) !== false ) {
			try {
				$sub = $customer->subscriptions->retrieve( $order->subscription_transaction_id );
			} catch ( \Throwable $e ) {
				$order->error      = __( "Error getting subscription with Stripe:", 'paid-memberships-pro' ) . $e->getMessage();
				$order->shorterror = $order->error;

				return false;
			} catch ( \Exception $e ) {
				$order->error      = __( "Error getting subscription with Stripe:", 'paid-memberships-pro' ) . $e->getMessage();
				$order->shorterror = $order->error;

				return false;
			}

			return $sub;
		}

		//find subscription based on customer id and order/plan id
		$subscriptions = $customer->subscriptions->all();

		//no subscriptions
		if ( empty( $subscriptions ) || empty( $subscriptions->data ) ) {
			return false;
		}

		//we really want to test against the order codes of all orders with the same subscription_transaction_id (customer id)
		$codes = $wpdb->get_col( "SELECT code FROM $wpdb->pmpro_membership_orders WHERE user_id = '" . esc_sql( $order->user_id ) . "' AND subscription_transaction_id = '" . esc_sql( $order->subscription_transaction_id ) . "' AND status NOT IN('refunded', 'review', 'token', 'error')" );

		//find the one for this order
		foreach ( $subscriptions->data as $sub ) {
			if ( in_array( $sub->plan->id, $codes ) ) {
				return $sub;
			}
		}

		//didn't find anything yet
		return false;
	}

	/**
	 * Create a new subscription with Stripe.
	 *
	 * This function is not run as a part of the PMPro Checkout Process.
	 * See method create_setup_intent().
	 *
	 * @since 1.4
	 * @deprecated 2.7.0. Use process_subscriptions() instead.
	 */
	public function subscribe( &$order, $checkout = true ) {
		//_deprecated_function( __FUNCTION__, '2.7.0' );
		global $pmpro_currency;

		//create a code for the order
		if ( empty( $order->code ) ) {
			$order->code = $order->getRandomCode();
		}

		//filter order before subscription. use with care.
		$order = apply_filters( "pmpro_subscribe_order", $order, $this );

		//figure out the user
		if ( ! empty( $order->user_id ) ) {
			$user_id = $order->user_id;
		} else {
			global $current_user;
			$user_id = $current_user->ID;
		}

		//set up customer

		$result = $this->update_customer_at_checkout( $order );
		if ( empty( $result ) ) {
			return false;    //error retrieving customer
		}
		$order->stripe_customer = $result;

		// set subscription id to custom id

		$order->subscription_transaction_id = $order->stripe_customer['id'];    //transaction id is the customer id, we save it in user meta later too

		//figure out the amounts
		$amount     = $order->PaymentAmount;
		$amount_tax = $order->getTaxForPrice( $amount );
		$amount     = pmpro_round_price( (float) $amount + (float) $amount_tax );

		$trial_period_days = $this->calculate_trial_period_days( $order );

		// Save $trial_period_days to order for now too.
		$order->TrialPeriodDays = $trial_period_days;

		//create a plan
		try {
			$plan = array(
				"amount"                 => $this->convert_price_to_unit_amount( $amount ),
				"interval_count"         => $order->BillingFrequency,
				"interval"               => strtolower( $order->BillingPeriod ),
				"trial_period_days"      => $trial_period_days,
				'product'                => array( 'name' => $order->membership_name . " for order " . $order->code ),
				"currency"               => strtolower( $pmpro_currency ),
				"id"                     => $order->code
			);
			$plan = self::add_application_fee_amount( $plan );
			$plan = Stripe_Plan::create( apply_filters( 'pmpro_stripe_create_plan_array', $plan ) );
		} catch ( \Throwable $e ) {
			$order->error      = __( "Error creating plan with Stripe:", 'paid-memberships-pro' ) . $e->getMessage();
			$order->shorterror = $order->error;

			return false;
		} catch ( \Exception $e ) {
			$order->error      = __( "Error creating plan with Stripe:", 'paid-memberships-pro' ) . $e->getMessage();
			$order->shorterror = $order->error;

			return false;
		}

		// before subscribing, let's clear out the updates so we don't trigger any during sub
		if ( ! empty( $user_id ) ) {
			$old_user_updates = get_user_meta( $user_id, "pmpro_stripe_updates", true );
			update_user_meta( $user_id, "pmpro_stripe_updates", array() );
		}


		if ( empty( $order->subscription_transaction_id ) && ! empty( $order->stripe_customer['id'] ) ) {
			$order->subscription_transaction_id = $order->stripe_customer['id'];
		}

		// subscribe to the plan
		try {
			$subscription = array( "plan" => $order->code );
			$result       = $this->create_subscription( $order );
		} catch ( \Throwable $e ) {
			//try to delete the plan
			$plan->delete();

			//give the user any old updates back
			if ( ! empty( $user_id ) ) {
				update_user_meta( $user_id, "pmpro_stripe_updates", $old_user_updates );
			}

			//return error
			$order->error      = __( "Error subscribing customer to plan with Stripe:", 'paid-memberships-pro' ) . $e->getMessage();
			$order->shorterror = $order->error;

			return false;
		} catch ( \Exception $e ) {
			//try to delete the plan
			$plan->delete();

			//give the user any old updates back
			if ( ! empty( $user_id ) ) {
				update_user_meta( $user_id, "pmpro_stripe_updates", $old_user_updates );
			}

			//return error
			$order->error      = __( "Error subscribing customer to plan with Stripe:", 'paid-memberships-pro' ) . $e->getMessage();
			$order->shorterror = $order->error;

			return false;
		}

		// delete the plan
		$plan = Stripe_Plan::retrieve( $order->code );
		$plan->delete();

		//if we got this far, we're all good
		$order->status                      = "success";
		$order->subscription_transaction_id = $result['id'];

		//save new updates if this is at checkout
		if ( $checkout ) {
			//empty out updates unless set above
			if ( empty( $new_user_updates ) ) {
				$new_user_updates = array();
			}

			//update user meta
			if ( ! empty( $user_id ) ) {
				update_user_meta( $user_id, "pmpro_stripe_updates", $new_user_updates );
			} else {
				//need to remember the user updates to save later
				global $pmpro_stripe_updates;
				$pmpro_stripe_updates = $new_user_updates;
				function pmpro_user_register_stripe_updates( $user_id ) {
					global $pmpro_stripe_updates;
					update_user_meta( $user_id, "pmpro_stripe_updates", $pmpro_stripe_updates );
				}

				add_action( "user_register", "pmpro_user_register_stripe_updates" );
			}
		} else {
			//give them their old updates back
			update_user_meta( $user_id, "pmpro_stripe_updates", $old_user_updates );
		}

		return true;
	}

	/**
	 * Refund a payment or invoice
	 *
	 * @deprecated 2.7.0.
	 *
	 * @param object &$order Related PMPro order object.
	 * @param string $transaction_id Payment or Invoice id to void.
	 *
	 * @return bool                     True or false if the void worked
	 */
	public function void( &$order, $transaction_id = null ) {
		_deprecated_function( __FUNCTION__, '2.7.0' );
		//stripe doesn't differentiate between voids and refunds, so let's just pass on to the refund function
		return $this->refund( $order, $transaction_id );
	}

	/**
	 * Refund a payment or invoice
	 *
	 * @deprecated 2.7.0.
	 *
	 * @param object &$order Related PMPro order object.
	 * @param string $transaction_id Payment or invoice id to void.
	 *
	 * @return bool                   True or false if the refund worked.
	 */
	public function refund( &$order, $transaction_id = null ) {
		_deprecated_function( __FUNCTION__, '2.7.0' );
		//default to using the payment id from the order
		if ( empty( $transaction_id ) && ! empty( $order->payment_transaction_id ) ) {
			$transaction_id = $order->payment_transaction_id;
		}

		//need a transaction id
		if ( empty( $transaction_id ) ) {
			return false;
		}

		//if an invoice ID is passed, get the charge/payment id
		if ( strpos( $transaction_id, "in_" ) !== false ) {
			$invoice = Stripe_Invoice::retrieve( $transaction_id );

			if ( ! empty( $invoice ) && ! empty( $invoice->charge ) ) {
				$transaction_id = $invoice->charge;
			}
		}

		//get the charge
		try {
			$charge = Stripe_Charge::retrieve( $transaction_id );
		} catch ( \Throwable $e ) {
			$charge = false;
		} catch ( \Exception $e ) {
			$charge = false;
		}

		//can't find the charge?
		if ( empty( $charge ) ) {
			$order->status     = "error";
			$order->errorcode  = "";
			$order->error      = "";
			$order->shorterror = "";

			return false;
		}

		//attempt refund
		try {
			$refund = $charge->refund();
		} catch ( \Throwable $e ) {
			$order->errorcode  = true;
			$order->error      = __( "Error: ", 'paid-memberships-pro' ) . $e->getMessage();
			$order->shorterror = $order->error;

			return false;
		} catch ( \Exception $e ) {
			$order->errorcode  = true;
			$order->error      = __( "Error: ", 'paid-memberships-pro' ) . $e->getMessage();
			$order->shorterror = $order->error;

			return false;
		}

		if ( $refund->status == "succeeded" ) {
			$order->status = "refunded";
			$order->saveOrder();

			return true;
		} else {
			$order->status     = "error";
			$order->errorcode  = true;
			$order->error      = sprintf( __( "Error: Unkown error while refunding charge #%s", 'paid-memberships-pro' ), $transaction_id );
			$order->shorterror = $order->error;

			return false;
		}
	}

	/**
	 * @deprecated 2.7.0. Use get_payment_method() instead.
	 */
	public function set_payment_method( &$order, $force = false ) {
		_deprecated_function( __FUNCTION__, '2.7.0', 'get_payment_method' );
		if ( ! empty( $this->payment_method ) && ! $force ) {
			return true;
		}

		$payment_method = $this->get_payment_method( $order );

		if ( empty( $payment_method ) ) {
			return false;
		}

		$this->payment_method = $payment_method;

		return true;
	}

	/**
	 * @deprecated 2.7.0. Use get_customer_for_user() or update_customer_from_user().
	 */
	public function set_customer( &$order, $force = false ) {
		_deprecated_function( __FUNCTION__, '2.7.0', 'get_customer_for_user()' );
		if ( ! empty( $this->customer ) && ! $force ) {
			return true;
		}
		// Temporarily setting this, will be removed when this function is deprecated.
		$this->customer = $this->update_customer_at_checkout( $order );
		return $this->customer;
	}

	/**
	 * @deprecated 2.7.0. Use set_default_payment_method_for_customer().
	 */
	public function attach_payment_method_to_customer( &$order ) {
		_deprecated_function( __FUNCTION__, '2.7.0', 'set_default_payment_method_for_customer()' );
		$customer = $this->update_customer_at_checkout( $order );

		if ( ! empty( $customer->invoice_settings->default_payment_method ) &&
             $customer->invoice_settings->default_payment_method === $this->payment_method->id ) {
			return true;
		}

		try {
			$this->payment_method->attach( [ 'customer' => $customer->id ] );
			$customer->invoice_settings->default_payment_method = $this->payment_method->id;
			$customer->save();
		} catch ( Stripe\Error\Base $e ) {
			$order->error = $e->getMessage();
			return false;
		} catch ( \Throwable $e ) {
			$order->error = $e->getMessage();
			return false;
		} catch ( \Exception $e ) {
			$order->error = $e->getMessage();
			return false;
		}

		return true;
	}

	/**
	 * @deprecated 2.7.0. Use get_payment_intent() instead.
	 */
	public function set_payment_intent( &$order, $force = false ) {
		_deprecated_function( __FUNCTION__, '2.7.0', 'get_payment_intent()' );
		if ( ! empty( $order->stripe_payment_intent ) && ! $force ) {
			return true;
		}

		$payment_intent = $this->get_payment_intent( $order );

		if ( empty( $payment_intent ) ) {
			return false;
		}

		$this->payment_intent = $payment_intent;

		return true;
	}

	/**
	 * Only called during subscription updates. Should be completely deprecated once that functionality is removed.
	 *
	 * @deprecated 2.7.0. Will only be deprecated once we are using Prices.
	 */
	public function create_plan( &$order ) {
		// _deprecated_function( __FUNCTION__, '2.7.0' );
		global $pmpro_currency;

		//figure out the amounts
		$amount     = $order->PaymentAmount;
		$amount_tax = $order->getTaxForPrice( $amount );
		$amount     = pmpro_round_price( (float) $amount + (float) $amount_tax );

		
		$trial_period_days = $this->calculate_trial_period_days( $order );
		// Save $trial_period_days to order for now too.
		$order->TrialPeriodDays = $trial_period_days;

		//create a plan
		try {
			$plan = array(
				"amount"                 => $this->convert_price_to_unit_amount( $amount ),
				"interval_count"         => $order->BillingFrequency,
				"interval"               => strtolower( $order->BillingPeriod ),
				"trial_period_days"      => $trial_period_days,
				'product'                => array( 'name' => $order->membership_name . " for order " . $order->code ),
				"currency"               => strtolower( $pmpro_currency ),
				"id"                     => $order->code
			);
			$order->plan = Stripe_Plan::create( apply_filters( 'pmpro_stripe_create_plan_array', $plan ) );
		} catch ( Stripe\Error\Base $e ) {
			$order->error = $e->getMessage();

			return false;
		} catch ( \Throwable $e ) {
			$order->error = $e->getMessage();

			return false;
		} catch ( \Exception $e ) {
			$order->error = $e->getMessage();

			return false;
		}

		return $order->plan;
	}

	
}
