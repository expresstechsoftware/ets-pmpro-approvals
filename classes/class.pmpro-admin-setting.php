<?php
/**
 * 
 */
class PMPro_Admin_recurring_setting
{
	public static $instance;

	public function __construct()
	{
		
	}

	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new PMPro_Admin_recurring_setting();
		}

		return self::$instance;
	}

	public static function register(){
		$pmpro_recurring = self::get_instance();

		add_action('pmpro_membership_level_after_billing_details_settings', array( $pmpro_recurring, 'pmpro_recurring_custom_field' ) );

		add_action( 'pmpro_save_membership_level', array( $pmpro_recurring, 'pmpro_recurring_save_cutom_date_save' ) );

		add_filter( 'pmpro_allowed_refunds_gateways', array( $pmpro_recurring, 'pmpro_allowed_refunds_gateways' ), 10 );

	}

	public function pmpro_recurring_custom_field( $level ){
		$level_id = ( !empty($_GET['edit'])) ? intval($_GET['edit']) : 0;
		$recurring_stripe_custom_date = get_pmpro_membership_level_meta( $level_id, '_pmpro_recurring_stripe_custom_date', true );
		?>
		<table class="form-table">
			<tbody>
				<tr class="recurring_info" <?php if(!pmpro_isLevelRecurring($level)) {?>style="display: none;"<?php } ?>>
					<th scope="row" valign="top"><label for="billing_recurring_date"><?php esc_html_e('Recurring Date', 'paid-memberships-pro' );?>:</label></th>
					<td>
						<input name="billing_recurring_date" type="date" value="<?php if(isset($recurring_stripe_custom_date) && $recurring_stripe_custom_date) echo  $recurring_stripe_custom_date; ?>" class="regular-text" />	
						<p class="description"><?php esc_html_e('Recurring on set date.', 'paid-memberships-pro' );?></p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	public function pmpro_recurring_save_cutom_date_save($level_id){
		$billing_recurring_date = isset( $_POST['billing_recurring_date'] ) ? $_POST['billing_recurring_date'] : '';
		update_pmpro_membership_level_meta( $level_id, '_pmpro_recurring_stripe_custom_date', $billing_recurring_date );
	}

	public function pmpro_allowed_refunds_gateways($allowed_gateways)
	{
		//$allowed_gateways = array('etsstripe');

		array_push($allowed_gateways,'etsstripe');
		
		return $allowed_gateways;
	}
}
PMPro_Admin_recurring_setting::register();
