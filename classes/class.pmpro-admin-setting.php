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

	/**
	 * Add custom recurring date field
	 * for a subscription should be charged.
	 *
	 * @param Level $level.
	 * 
	 * @return None.
	 */
	public function pmpro_recurring_custom_field( $level ){
		$level_id = ( !empty($_GET['edit'])) ? intval($_GET['edit']) : 0;
		$recurring_stripe_custom_date = get_pmpro_membership_level_meta( $level_id, '_pmpro_recurring_stripe_custom_date', true );

		$recurring_stripe_custom_month = get_pmpro_membership_level_meta( $level_id, '_pmpro_recurring_stripe_custom_month', true );
		?>
		<table class="form-table">
			<tbody>
				<tr class="recurring_info" <?php if(!pmpro_isLevelRecurring($level)) {?>style="display: none;"<?php } ?>>
					<th scope="row" valign="top">
						<label for="billing_recurring_date"><?php esc_html_e('Recurring Date/Month', 'paid-memberships-pro' );?>:</label></th>
					<td>
						<input name="billing_recurring_date" type="text" value="<?php if(isset($recurring_stripe_custom_date) && $recurring_stripe_custom_date) echo  $recurring_stripe_custom_date; ?>" class="medium-text" placeholder="Date like 01" />
						<select name="billing_recurring_date_month" class="small-text" />
							<option value=0><?php echo __('Select Month');?></option>
							<option value="01" <?php if(isset($recurring_stripe_custom_month) && $recurring_stripe_custom_month == '01') echo "selected";?>>01</option>
							<option value="02" <?php if(isset($recurring_stripe_custom_month) && $recurring_stripe_custom_month == '02') echo "selected";?>>02</option>
							<option value="03" <?php if(isset($recurring_stripe_custom_month) && $recurring_stripe_custom_month == '03') echo "selected";?>>03</option>
							<option value="04" <?php if(isset($recurring_stripe_custom_month) && $recurring_stripe_custom_month == '04') echo "selected";?>>04</option>
							<option value="05" <?php if(isset($recurring_stripe_custom_month) && $recurring_stripe_custom_month == '05') echo "selected";?>>05</option>
							<option value="06" <?php if(isset($recurring_stripe_custom_month) && $recurring_stripe_custom_month == '06') echo "selected";?>>06</option>
							<option value="07" <?php if(isset($recurring_stripe_custom_month) && $recurring_stripe_custom_month == '07') echo "selected";?>>07</option>
							<option value="08" <?php if(isset($recurring_stripe_custom_month) && $recurring_stripe_custom_month == '08') echo "selected";?>>08</option>
							<option value="09" <?php if(isset($recurring_stripe_custom_month) && $recurring_stripe_custom_month == '09') echo "selected";?>>09</option>
							<option value="10" <?php if(isset($recurring_stripe_custom_month) && $recurring_stripe_custom_month == '10') echo "selected";?>>10</option>
							<option value="11" <?php if(isset($recurring_stripe_custom_month) && $recurring_stripe_custom_month == '11') echo "selected";?>>11</option>
							<option value="12" <?php if(isset($recurring_stripe_custom_month) && $recurring_stripe_custom_month == '12') echo "selected";?>>12</option>

						</select>
						<p class="description"><?php esc_html_e(' The date/month to be set next cycle payment.', 'paid-memberships-pro' );?></p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Save custom recurring date
	 * @param Memberlevel $level_id.
	 * @return save date.
	 */
	public function pmpro_recurring_save_cutom_date_save($level_id){
		if (isset( $_POST['billing_recurring_date'] ) && $_POST['billing_recurring_date'] && isset( $_POST['billing_recurring_date_month'] ) && $_POST['billing_recurring_date_month']) {
			$recurring_date = $_POST['billing_recurring_date'];
			$recurring_month = $_POST['billing_recurring_date_month'];
			$recurring_year = date('Y', strtotime('+1 year'));
			$full_recurring_date = $recurring_year.'-'.$recurring_month.'-'.$recurring_date;
			
	    	$full_recurring_date = date_format( date_create($full_recurring_date), 'Y-m-d' );
			update_pmpro_membership_level_meta( $level_id, '_pmpro_recurring_stripe_custom_date', $recurring_date );
			update_pmpro_membership_level_meta( $level_id, '_pmpro_recurring_stripe_custom_month', $recurring_month );
			update_pmpro_membership_level_meta( $level_id, '_pmpro_recurring_stripe_custom_full_date', $full_recurring_date );
		}
	}

	/**
	 * Allow gateway to refunds	 *
	 * @param $allowed_gateways.
	 * @return array $allowed_gateways.
	 */
	public function pmpro_allowed_refunds_gateways($allowed_gateways)
	{
		array_push($allowed_gateways,'etsstripe');
		return $allowed_gateways;
	}
}
PMPro_Admin_recurring_setting::register();
