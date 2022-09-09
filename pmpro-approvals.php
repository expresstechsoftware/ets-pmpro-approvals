<?php
/*
Plugin Name: Paid Memberships Pro - Approvals Add On By Expresstech
Plugin URI: https://www.paidmembershipspro.com/add-ons/approval-process-membership/
Description: Grants administrators the ability to approve/deny memberships after signup.
Version: 1.4.3
Author: Stranger Studios
Author URI: https://www.paidmembershipspro.com
Text Domain: pmpro-approvals
Domain Path: /languages
*/

define( 'PMPRO_APP_DIR', dirname( __FILE__ ) );
// create plugin url constant.
define( 'PMPRO_APP_URL', plugin_dir_url( __FILE__ ) );
define('PMPRO_APPROVAL_VERSION', '1.0');

/**
 * Only load approvals after plugins have been loaded. Otherwise it may be loaded too early (e.g., before PMPro).
 */
function pmpro_approvals_plugins_loaded() {
	require PMPRO_APP_DIR . '/classes/class.approvalemails.php';
	require PMPRO_APP_DIR . '/classes/class.pmprogateway_etsstripe.php';
	require PMPRO_APP_DIR . '/classes/class.pmpro-admin-setting.php';

}
add_action( 'plugins_loaded', 'pmpro_approvals_plugins_loaded' );

class PMPro_Approvals {
	/*
		Attributes
	*/
	private static $instance = null;        // Refers to a single instance of this class.

	/**
	 * Constructor
	 * Initializes the plugin by setting localization, filters, and administration functions.
	 */
	private function __construct() {
		//activation/deactivation
		register_activation_hook( __FILE__, array( 'PMPro_Approvals', 'activation' ) );
		register_deactivation_hook( __FILE__, array( 'PMPro_Approvals', 'deactivation' ) );

		//initialize the plugin
		add_action( 'init', array( 'PMPro_Approvals', 'init' ) );
		add_action( 'plugins_loaded', array( 'PMPro_Approvals', 'text_domain' ) );

		//add support for PMPro Email Templates Add-on
		add_filter( 'pmproet_templates', array( 'PMPro_Approvals', 'pmproet_templates' ) );
		add_filter( 'pmpro_email_filter', array( 'PMPro_Approvals', 'pmpro_email_filter' ) );
	}

	/**
	 * Creates or returns an instance of this class.
	 *
	 * @return  PMPro_Approvals A single instance of this class.
	 */
	public static function get_instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Run code on init.
	 */
	public static function init() {
		//check that PMPro is active
		if ( ! defined( 'PMPRO_VERSION' ) ) {
			return;
		}

		//add admin menu items to 'Memberships' in WP dashboard and admin bar
		add_action( 'admin_menu', array( 'PMPro_Approvals', 'admin_menu' ) );
		add_action( 'admin_bar_menu', array( 'PMPro_Approvals', 'admin_bar_menu' ), 1000 );
		add_action( 'admin_init', array( 'PMPro_Approvals', 'admin_init' ) );

		// Add script for front end.
		add_action( 'wp_enqueue_scripts', array( 'PMPro_Approvals', 'pmpro_approvals_add_script' ) );

		add_action( 'admin_enqueue_scripts', array( 'PMPro_Approvals', 'pmpro_approvals_add_admin_script' ) );


		//add user actions to the approvals page
		add_filter( 'pmpro_approvals_user_row_actions', array( 'PMPro_Approvals', 'pmpro_approvals_user_row_actions' ), 10, 2 );

		//add approval section to edit user page
		$membership_level_capability = apply_filters( 'pmpro_edit_member_capability', 'manage_options' );
		if ( current_user_can( $membership_level_capability ) ) {
			//current user can change membership levels
			add_action( 'pmpro_after_membership_level_profile_fields', array( 'PMPro_Approvals', 'show_user_profile_status' ), 5 );
		} else {
			//current user can't change membership level; use different hooks
			add_action( 'edit_user_profile', array( 'PMPro_Approvals', 'show_user_profile_status' ) );
			add_action( 'show_user_profile', array( 'PMPro_Approvals', 'show_user_profile_status' ) );
		}

		//check approval status at checkout
		add_action( 'pmpro_checkout_preheader', array( 'PMPro_Approvals', 'pmpro_checkout_preheader' ) );

		//add approval status to members list
		add_action( 'pmpro_members_list_user', array( 'PMPro_Approvals', 'pmpro_members_list_user' ) );

		//filter to add the approval membership level template
		add_filter( 'pmpro_membershiplevels_template_level', array( 'PMPro_Approvals', 'pmpro_membershiplevels_template_level' ), 10, 2 );

		//filter membership and content access
		add_filter( 'pmpro_has_membership_level', array( 'PMPro_Approvals', 'pmpro_has_membership_level' ), 10, 3 );
		add_filter( 'pmpro_has_membership_access_filter', array( 'PMPro_Approvals', 'pmpro_has_membership_access_filter' ), 10, 4 );
		add_filter( 'pmpro_member_shortcode_access', array( 'PMPro_Approvals', 'pmpro_member_shortcode_access' ), 10, 4 );

		//load checkbox in membership level edit page for users to select.
		if ( defined( 'PMPRO_VERSION' ) && PMPRO_VERSION >= '2.9' ) {
			add_action( 'pmpro_membership_level_before_billing_information', array( 'PMPro_Approvals', 'pmpro_membership_level_settings' ) );
		} else {
			add_action( 'pmpro_membership_level_after_other_settings', array( 'PMPro_Approvals', 'pmpro_membership_level_settings' ) );
		}
		add_action( 'pmpro_save_membership_level', array( 'PMPro_Approvals', 'pmpro_save_membership_level' ) );

		//Add code for filtering checkouts, confirmation, and content filters
		add_filter( 'pmpro_non_member_text_filter', array( 'PMPro_Approvals', 'pmpro_non_member_text_filter' ) );
		add_action( 'pmpro_account_bullets_top', array( 'PMPro_Approvals', 'pmpro_account_bullets_top' ) );
		add_filter( 'pmpro_confirmation_message', array( 'PMPro_Approvals', 'pmpro_confirmation_message' ), 10, 2 );
		add_action( 'pmpro_before_change_membership_level', array( 'PMPro_Approvals', 'pmpro_before_change_membership_level' ), 10, 4 );
		add_action( 'pmpro_after_change_membership_level', array( 'PMPro_Approvals', 'pmpro_after_change_membership_level' ), 10, 2 );

		//Integrate with Member Directory.
		add_filter( 'pmpro_member_directory_sql_parts', array( 'PMPro_Approvals', 'pmpro_member_directory_sql_parts'), 10, 9 );
		add_filter( 'gettext', array( 'PMPro_Approvals', 'change_your_level_text' ), 10, 3 );

		//Integrate with Pay By Check Add On
		add_action( 'pmpro_approvals_after_approve_member', array( 'PMPro_Approvals', 'pmpro_pay_by_check_approve' ), 10, 2 );

		//plugin row meta
		add_filter( 'plugin_row_meta', array( 'PMPro_Approvals', 'plugin_row_meta' ), 10, 2 );
	}

	/**
	* Added style on frontend
	*/
	public static function pmpro_approvals_add_script(){
		wp_register_style(
			'pmpro-approval-style',
			PMPRO_APP_URL . 'assets/css/ets-pmpro-approval-frontend.css',
			false,
			PMPRO_APPROVAL_VERSION
		);
		wp_enqueue_style('pmpro-approval-style');
	}

	/**
	* Added style on frontend
	*/
	public static function pmpro_approvals_add_admin_script(){
		wp_register_style(
			'pmpro-approval-admin-style',
			PMPRO_APP_URL . 'assets/css/admin/pmpro-approval-admin.css',
			false,
			PMPRO_APPROVAL_VERSION
		);
		wp_enqueue_style('pmpro-approval-admin-style');
		wp_register_style(
			'pmpro_approvals_datatable_css',
			PMPRO_APP_URL . 'assets/css/jquery.dataTables.min.css',
			array(),
			PMPRO_APPROVAL_VERSION
		);
		wp_register_script(
			'pmpro_approvals_datatable_js',
			PMPRO_APP_URL . 'assets/js/jquery.dataTables.min.js',
			array(),
			PMPRO_APPROVAL_VERSION
		);
		if ( isset( $_GET['page'] ) && $_GET['page'] == 'pmpro-approvals' ) {
			wp_enqueue_style('pmpro_approvals_datatable_css');
			wp_enqueue_script('pmpro_approvals_datatable_js');
		}
	}

	/**
	* Run code on admin init
	*/
	public static function admin_init() {
		//get role of administrator
		$role = get_role( 'administrator' );
		//add custom capability to administrator
		$role->add_cap( 'pmpro_approvals' );

		//make sure the current user has the updated cap
		global $current_user;
		setup_userdata( $current_user->ID );
	}

	/**
	* Run code on activation
	*/
	public static function activation() {
		//add Membership Approver role
		remove_role( 'pmpro_approver' );  //in case we updated the caps below
		add_role(
			'pmpro_approver', 'Membership Approver', array(
				'read'                   => true,
				'pmpro_memberships_menu' => true,
				'pmpro_memberslist'      => true,
				'pmpro_approvals'        => true,
			)
		);
	}

	/**
	* Run code on deactivation
	*/
	public static function deactivation() {
		//remove Membership Approver role
		remove_role( 'pmpro_approver' );
	}

	/**
	 * Create the submenu item 'Approvals' under the 'Memberships' link in WP dashboard.
	 * Fires during the "admin_menu" action.
	 */
	public static function admin_menu() {
		global $menu, $submenu;
		
		if ( ! defined( 'PMPRO_VERSION' ) ) {
			return;
		}

		$approval_menu_text = __( 'Approvals', 'pmpro-approvals' );
		
		$user_count = self::getApprovalCount();

		if ( $user_count > 0 ) {
			$approval_menu_text .= ' <span class="wp-core-ui wp-ui-notification pmpro-issue-counter" style="display: inline; padding: 1px 7px 1px 6px!important; border-radius: 50%; color: #fff; ">' . $user_count . '</span>';

			foreach ( $menu as $key => $value ) {
				if ( $menu[ $key ][1] === 'pmpro_memberships_menu' ) {
					$menu[ $key ][0] .= ' <span class="update-plugins"><span class="update-count"> ' . $user_count . '</span></span>';
				}
			}
		}

		if ( ! defined( 'PMPRO_VERSION' ) ) {
			return;
		}

		if ( version_compare( PMPRO_VERSION, '2.0' ) >= 0 ) {
			add_submenu_page( 'pmpro-dashboard', __( 'Approvals', 'pmpro-approvals' ), $approval_menu_text, 'pmpro_approvals', 'pmpro-approvals', array( 'PMPro_Approvals', 'admin_page_approvals' ) );
		} else {
			add_submenu_page( 'pmpro-membershiplevels', __( 'Approvals', 'pmpro-approvals' ), $approval_menu_text, 'pmpro_approvals', 'pmpro-approvals', array( 'PMPro_Approvals', 'admin_page_approvals' ) );
		}
	}

	/**
	 * Create 'Approvals' link under the admin bar link 'Memberships'.
	 * Fires during the "admin_bar_menu" action.
	 */
	public static function admin_bar_menu() {
		global $wp_admin_bar;

		//check capabilities (TODO: Define a new capability (pmpro_approvals) for managing approvals.)
		if ( ! is_super_admin() && ! current_user_can( 'pmpro_approvals' ) || ! is_admin_bar_showing() ) {
			return;
		}
		//default title for admin bar menu
		$title = __( 'Approvals', 'pmpro-approvals' );

		$user_count = self::getApprovalCount();

		//if returned data contains pending users, adjust the title of the admin bar menu.
		if ( $user_count > 0 ) {
			$title .= ' <span class="wp-core-ui wp-ui-notification pmpro-issue-counter" style="display: inline; padding: 1px 7px 1px 6px!important; border-radius: 50%; color: #fff; background:red; background:#CA4A1E;">' . $user_count . '</span>';
		}

		//add the admin link
		$wp_admin_bar->add_menu(
			array(
				'id'     => 'pmpro-approvals',
				'title'  => $title,
				'href'   => get_admin_url( null, '/admin.php?page=pmpro-approvals' ),
				'parent' => 'paid-memberships-pro',
			)
		);
	}

	/**
	 * Load the Approvals admin page.
	 */
	public static function admin_page_approvals() {
		if ( ! empty( $_REQUEST['user_id'] ) ) {
			require_once dirname( __FILE__ ) . '/adminpages/userinfo.php';
		} else {
			require_once dirname( __FILE__ ) . '/adminpages/approvals.php';
		}
	}

	/**
	 * Get options for level.
	 */
	public static function getOptions( $level_id = null ) {
		$options = get_option( 'pmproapp_options', array() );

		if ( ! empty( $level_id ) ) {
			if ( ! empty( $options[ $level_id ] ) ) {
				$r = $options[ $level_id ];
			} else {
				$r = array(
					'requires_approval' => 0,
					'restrict_checkout' => 0,
				);
			}
		} else {
			$r = $options;

			//clean up extra values that were accidentally stored in here in old versions
			if ( isset( $r['requires_approval'] ) ) {
				unset( $r['requires_approval'] );
			}
			if ( isset( $r['restrict_checkout'] ) ) {
				unset( $r['restrict_checkout'] );
			}
		}

		return $r;
	}

	/**
	 * Save options for level.
	 */
	public static function saveOptions( $options ) {
		update_option( 'pmproapp_options', $options, 'no' );
	}

	/**
	 * Check if a level requires approval
	 */
	public static function requiresApproval( $level_id = null ) {
		//no level?
		if ( empty( $level_id ) ) {
			return false;
		}

		$options = self::getOptions( $level_id );
		
		$requires_approval = apply_filters( 'pmpro_approvals_level_requires_approval', $options['requires_approval'], $level_id);
		
		return $requires_approval;
	}

    /**
     * Check if level has a restriction level at checkout
     */
    public static function restrictCheckout( $level_id = null ) {
        //no level?
        if ( empty( $level_id ) ) {
            return false;
        }
        
        $options = self::getOptions( $level_id );

        $restrict_checkout = apply_filters( 'pmpro_approvals_level_restrict_checkout', $options['restrict_checkout'], $level_id);

        return $restrict_checkout;
    }

	/**
	* Load check box to make level require membership.
	*/
	public static function pmpro_membership_level_settings() {
		$level_id = $_REQUEST['edit'];

		// Get the template if passed in the URL.
		if ( isset( $_REQUEST['template'] ) ) {
			$template = sanitize_text_field( $_REQUEST['template'] );
		} else {
			$template = false;
		}

		// Get approval settings or set defaults if this is a new approvals level.
		if ( $level_id > 0 ) {
			$options = self::getOptions( $level_id );
		} elseif ( $template === 'approvals' ) {
			$options = array(
				'requires_approval' => true,
				'restrict_checkout' => true,
			);
		} else {
			$options = array(
				'requires_approval' => false,
				'restrict_checkout' => false,
			);
		}

		//figure out approval_setting from the actual options
		if ( ! $options['requires_approval'] && ! $options['restrict_checkout'] ) {
			$approval_setting = 0;
		} elseif ( $options['requires_approval'] && ! $options['restrict_checkout'] ) {
			$approval_setting = 1;
		} elseif ( ! $options['requires_approval'] && $options['restrict_checkout'] ) {
			$approval_setting = 2;
		} else {
			$approval_setting = 3;
		}

		//get all levels for which level option
		$levels = pmpro_getAllLevels( true, true );
		if ( isset( $levels[ $level_id ] ) ) {
			unset( $levels[ $level_id ] );   //remove this level

		}

		// Hide or show this section based on settings
		if ( $template === 'approvals' || $approval_setting > 0 ) {
			$section_visibility = 'shown';
			$section_activated = 'true';
		} else {
			$section_visibility = 'hidden';
			$section_activated = 'false';
		}
		?>
		<div id="approval-settings" class="pmpro_section" data-visibility="<?php echo esc_attr( $section_visibility ); ?>" data-activated="<?php echo esc_attr( $section_activated ); ?>">
			<div class="pmpro_section_toggle">
				<button class="pmpro_section-toggle-button" type="button" aria-expanded="<?php echo $section_visibility === 'hidden' ? 'false' : 'true'; ?>">
					<span class="dashicons dashicons-arrow-<?php echo $section_visibility === 'hidden' ? 'down' : 'up'; ?>-alt2"></span>
					<?php esc_html_e( 'Approval Settings', 'pmpro-approvals' ); ?>
				</button>
			</div>
			<div class="pmpro_section_inside" <?php echo $section_visibility === 'hidden' ? 'style="display: none"' : ''; ?>>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row" valign="top"><label for="approval_setting"><?php esc_html_e( 'Requires Approval?', 'pmpro-approvals' ); ?></label></th>
							<td>
								<select id="approval_setting" name="approval_setting">
									<option value="0" <?php selected( $approval_setting, 0 ); ?>><?php esc_html_e( 'No.', 'pmpro-approvals' ); ?></option>
									<option value="1" <?php selected( $approval_setting, 1 ); ?>><?php esc_html_e( 'Yes. Admin must approve new members for this level.', 'pmpro-approvals' ); ?></option>
									<?php if ( ! empty( $levels ) ) { ?>
										<option value="2" <?php selected( $approval_setting, 2 ); ?>><?php esc_html_e( 'Yes. User must have an approved membership for a different level.', 'pmpro-approvals' ); ?></option>
										<option value="3" <?php selected( $approval_setting, 3 ); ?>><?php esc_html_e( 'Yes. User must have an approved membership for a different level AND admin must approve new members for this level.', 'pmpro-approvals' ); ?></option>
									<?php } ?>
								</select>
							</td>
						</tr>
						<?php if ( ! empty( $levels ) ) { ?>
						<tr 
						<?php
						if ( $approval_setting < 2 ) {
				?>
			 style="display: none;"<?php } ?>>
							<th scope="row" valign="top"><label for="approval_restrict_level"><?php esc_html_e( 'Which Level?', 'pmpro-approvals' ); ?></label></th>
							<td>
								<select id="approval_restrict_level" name="approval_restrict_level">					
								<?php
								foreach ( $levels as $level ) {
									?>
									<option value="<?php echo $level->id; ?>" <?php selected( $options['restrict_checkout'], $level->id ); ?>><?php echo $level->name; ?></option>
										<?php
								}
								?>
							</td>
						</tr>
						<?php } ?>
					</tbody>
				</table>
				<?php if ( ! empty( $levels ) ) { ?>
					<script>
						jQuery(document).ready(function() {
							function pmproap_toggleWhichLevel() {
								if(jQuery('#approval_setting').val() > 1)
									jQuery('#approval_restrict_level').closest('tr').show();
								else
									jQuery('#approval_restrict_level').closest('tr').hide();
							}
							
							//bind to approval setting change
							jQuery('#approval_setting').change(function() { pmproap_toggleWhichLevel(); });
							
							//run on load
							pmproap_toggleWhichLevel();
						});
					</script>
				<?php } ?>
			</div> <!-- end pmpro_section_inside -->
		</div> <!-- end pmpro_section -->
		<?php
	}

	/**
	 * Save settings when editing the membership level
	 * Fires on pmpro_save_membership_level
	 */
	public static function pmpro_save_membership_level( $level_id ) {
		global $msg, $msgt, $saveid, $edit;

		//get value
		if ( ! empty( $_REQUEST['approval_setting'] ) ) {
			$approval_setting = intval( $_REQUEST['approval_setting'] );
		} else {
			$approval_setting = 0;
		}

		if ( ! empty( $_REQUEST['approval_restrict_level'] ) ) {
			$restrict_checkout = intval( $_REQUEST['approval_restrict_level'] );
		} else {
			$restrict_checkout = 0;
		}

		//figure out requires_approval and restrict_checkout value from setting
		if ( $approval_setting == 1 ) {
			$requires_approval = 1;
			$restrict_checkout = 0;
		} elseif ( $approval_setting == 2 ) {
			$requires_approval = 0;
			//restrict_checkout set correctly above from input, but check that a level was chosen
		} elseif ( $approval_setting == 3 ) {
			$requires_approval = 1;
			//restrict_checkout set correctly above from input, but check that a level was chosen
		} else {
			//assume 0, all off
			$requires_approval = 0;
			$restrict_checkout = 0;
		}

		//get options
		$options = self::getOptions();

		//create array if we don't have options for this level already
		if ( empty( $options[ $level_id ] ) ) {
			$options[ $level_id ] = array();
		}

		//update options
		$options[ $level_id ]['requires_approval'] = $requires_approval;
		$options[ $level_id ]['restrict_checkout'] = $restrict_checkout;

		//save it
		self::saveOptions( $options );
	}

	/**
	 * Deny access to member content if user is not approved
	 * Fires on pmpro_has_membership_access_filter
	 */
	public static function pmpro_has_membership_access_filter( $access, $post, $user, $levels ) {

		//if we don't have access now, we still won't
		if ( ! $access ) {
			return $access;
		}

		//no user, this must be open to everyone
		if ( empty( $user ) || empty( $user->ID ) ) {
			return $access;
		}

		//no levels, must be open
		if ( empty( $levels ) ) {
			return $access;
		}

		// If the current user doesn't have a level, bail.
		if ( ! pmpro_hasMembershipLevel() ) {
			return $access;
		}

		//now we need to check if the user is approved for ANY of the $levels
		$access = false;    //assume no access
		foreach ( $levels as $level ) {
			if ( self::isApproved( $user->ID, $level->id ) ) {
				$access = true;
				break;
			}
		}

		return $access;
	}

	/**
	 * Deny access for shortcode specific content to pending members.
	 * @since 1.4
	 */
	public static function pmpro_member_shortcode_access( $access, $content, $levels, $delay ) {
		global $current_user;
		
		// Bail if they are not logged-in, default behavior.
		if ( ! is_user_logged_in()  ) {
			return $access;
		}

		// Bail if the user is logged in but doesn't have a membership level.
		if ( ! pmpro_hasMembershipLevel() ) {
			return $access;
		}

		// If no levels are defined but they aren't approved. Let's set this to false.
		if ( empty( $levels ) && ! self::isApproved( $current_user->ID ) ) {
			return false;
		}

		if ( self::isApproved( $current_user->ID ) ) {
			return $access;
		}
		
		return $access;
	}

	/**
	 * Filter hasMembershipLevel to return false
	 * if a user is not approved.
	 * Fires on pmpro_has_membership_level filter
	 */
	public static function pmpro_has_membership_level( $haslevel, $user_id, $levels ) {
		global $pmpro_pages;

		// Let members access PMPro pages, PMPro can handle the cases here.
		if ( is_page( $pmpro_pages ) ) {
			return $haslevel;
		}

		//if already false, skip
		if ( ! $haslevel ) {
			return $haslevel;
		}

		// Only check this inside admin of WordPress.
		if ( is_admin() && function_exists( 'get_current_screen' ) ) {

			// Ignore if on the edit user screen. This will allow admins/users to update custom fields.
			$current_screen = get_current_screen();
			
			if ( !empty( $current_screen ) && ( $current_screen->base == 'user-edit' || $current_screen->base == 'profile' ) ) {
				return $haslevel;
			}

		}

		//no user, skip
		if ( empty( $user_id ) ) {
			return $haslevel;
		}

		//no levels, skip
		if ( empty( $levels ) ) {
			return $haslevel;
		}

		// If the current user doesn't have a level, bail.
		if ( ! pmpro_hasMembershipLevel() ) {
			return $haslevel;
		}

		//now we need to check if the user is approved for ANY of the $levels
		$haslevel = false;
		foreach ( $levels as $level ) {
			if ( self::isApproved( $user_id, $level ) ) {
				$haslevel = true;
				break;
			}
		}

		return $haslevel;
	}

	/**
	 * Show potential errors on the checkout page.
	 * Note that the precense of these errors will halt checkout as well.
	 */
	public static function pmpro_checkout_preheader() {
		global $pmpro_level, $current_user;

		//are they denied for this level?
		if ( self::isDenied( null, $pmpro_level->id ) ) {
			pmpro_setMessage( __( 'Your previous application for this level has been denied. You will not be allowed to check out.', 'pmpro-approvals' ), 'pmpro_error' );
		}

		//does this level require approval of another level?
		$restrict_checkout = self::restrictCheckout( $pmpro_level->id );
		if ( $restrict_checkout ) {
			$other_level = pmpro_getLevel( $restrict_checkout );

			//check that they are approved and not denied for that other level
			if ( self::isDenied( null, $restrict_checkout ) ) {
				pmpro_setMessage( sprintf( __( 'Since your application to the %s level has been denied, you may not check out for this level.', 'pmpro-approvals' ), $other_level->name ), 'pmpro_error' );
			} elseif ( self::isPending( null, $restrict_checkout ) ) {
				//note we use pmpro_getMembershipLevelForUser instead of pmpro_hasMembershipLevel because the latter is filtered
				$user_level = pmpro_getMembershipLevelForUser( $current_user->ID );
				if ( ! empty( $user_level ) && $user_level->id == $other_level->id ) {
					//already applied but still pending
					pmpro_setMessage( sprintf( __( 'Your application to %s is still pending.', 'pmpro-approvals' ), $other_level->name ), 'pmpro_error' );
				} else {
					//haven't applied yet, check if the level is hidden
					if ( isset( $other_level->hidden ) && true == $other_level->hidden ) {
						pmpro_setMessage( sprintf( __( 'You must be approved for %s before checking out here.', 'pmpro-approvals' ), $other_level->name ), 'pmpro_error' );
					} else {
						pmpro_setMessage( sprintf( __( 'You must register and be approved for <a href="%1$s">%2$s</a> before checking out here.', 'pmpro-approvals' ), pmpro_url( 'checkout', '?level=' . $other_level->id ), $other_level->name ), 'pmpro_error' );
					}
				}
			}
		}
	}

	/**
	 * Get User Approval Meta
	 */
	public static function getUserApproval( $user_id = null, $level_id = null ) {
		//default to false
		$user_approval = false;     //false will function as a kind of N/A at times

		//default to the current user
		if ( empty( $user_id ) ) {
			global $current_user;
			$user_id = $current_user->ID;
		}

		//get approval status for this level from user meta
		if ( ! empty( $user_id ) ) {
			//default to the user's current level
			if ( empty( $level_id ) ) {
				$level = pmpro_getMembershipLevelForUser( $user_id );
				if ( ! empty( $level ) ) {
					$level_id = $level->id;
				}
			}

			//if we have a level, check if it requires approval and if so check user meta
			if ( ! empty( $level_id ) && self::hasMembershipLevelSansApproval( $level_id, $user_id ) ) {
				//if the level doesn't require approval, then the user is approved
				if ( ! self::requiresApproval( $level_id ) ) {
					//approval not required, so return status approved
					$user_approval = array( 'status' => 'approved' );
				} else {
					//approval required, check user meta
					$user_approval = get_user_meta( $user_id, 'pmpro_approval_' . $level_id, true );
				}
			}
		}

		return $user_approval;
	}

	/**
	 * Returns status of a given or current user. Returns 'approved', 'denied' or 'pending'.
	 * If the users level does not require approval it will not return anything.
	 */
	public static function getUserApprovalStatus( $user_id = null, $level_id = null, $short = true ) {

		global $current_user;

		//check if user ID is blank, set to current user ID.
		if ( empty( $user_id ) ) {
			$user_id = $current_user->ID;
		}

		//get the PMPro level for the user
		if ( empty( $level_id ) ) {
			$level    = pmpro_getMembershipLevelForUser( $user_id );
			
			if ( ! empty( $level ) ) {
				$level_id = $level->ID;
			}
			
		} else {
			$level = pmpro_getLevel( $level_id );
		}

		//make sure we have a user and level by this point
		if ( empty( $user_id ) || empty( $level_id ) ) {
			return false;
		}

		//check if level requires approval.
		if ( ! self::requiresApproval( $level_id ) ) {
			return;
		}

		//Get the user approval status. If it's not Approved/Denied it's set to Pending.
		if ( ! self::isPending( $user_id, $level_id ) ) {

			$approval_data = self::getUserApproval( $user_id, $level_id );

			if ( $short ) {
				if ( ! empty( $approval_data ) ) {
					$status = $approval_data['status'];
				} else {
					$status = __( 'approved', 'pmpro-approvals' );
				}
			} else {
				if ( ! empty( $approval_data ) ) {
					$approver = get_userdata( $approval_data['who'] );
					if ( current_user_can( 'edit_users' ) ) {
						$approver_text = '<a href="' . get_edit_user_link( $approver->ID ) . '">' . esc_attr( $approver->display_name ) . '</a>';
					} elseif ( current_user_can( 'pmpro_approvals' ) ) {
						$approver_text = $approver->display_name;
					} else {
						$approver_text = '';
					}

					if ( $approver_text ) {
						$status = sprintf( __( '%1$s on %2$s by %3$s', 'pmpro-approvals' ), ucwords( $approval_data['status'] ), date_i18n( get_option( 'date_format' ), $approval_data['timestamp'] ), $approver_text );
					} else {
						$status = sprintf( __( '%1$s on %2$s', 'pmpro-approvals' ), ucwords( $approval_data['status'] ), date_i18n( get_option( 'date_format' ), $approval_data['timestamp'] ) );
					}
				} else {
					$status = __( 'Approved', 'pmpro-approvals' );
				}
			}
		} else {

			if ( $short ) {
				$status = __( 'pending', 'pmpro-approvals' );
			} else {
				$status = sprintf( __( 'Pending Approval for %s', 'pmpro-approvals' ), $level->name );
			}
		}

		$status = apply_filters( 'pmpro_approvals_status_filter', $status, $user_id, $level_id );

		return $status;
	}

	/**
	 * Get user approval statuses for all levels that require approval
	 * Level IDs are used for the index the array
	 */
	public static function getUserApprovalStatuses( $user_id = null, $short = false ) {
		//default to current user
		if ( empty( $user_id ) ) {
			global $current_user;
			$user_id = $current_user->ID;
		}

		$approval_levels = self::getApprovalLevels();
		$r               = array();
		foreach ( $approval_levels as $level_id ) {
			$r[ $level_id ] = self::getUserApprovalStatus( $user_id, $level_id, $short );
		}

		return $r;
	}

	/**
	 * Check if a user is approved.
	 */
	public static function isApproved( $user_id = null, $level_id = null ) {
		//default to the current user
		if ( empty( $user_id ) ) {
			global $current_user;
			$user_id = $current_user->ID;
		}

		//get approval for this user/level
		$user_approval = self::getUserApproval( $user_id, $level_id );

		//if no array, check if they already have the level
		if ( empty( $user_approval ) || ! is_array( $user_approval ) ) {
			$level = pmpro_getMembershipLevelForUser( $user_id );

			if ( empty( $level ) || ( ! empty( $level_id ) && $level->id != $level_id ) ) {
				$user_approval = array( 'status' => 'pending' );
			} else {
				$user_approval = array( 'status' => 'approved' );
			}
		}

		/**
		 * @filter pmproap_user_is_approved - Filter to override whether the user ID is approved for access to for the level ID
		 *
		 * @param bool      $is_approved - Whether the $user_id is approved for the specified $level_id
		 * @param int       $user_id - The ID of the User being tested for approval
		 * @param int       $level_id - The ID of the Membership Level the $user_id is being thested for approval
		 * @param array     $user_approval - The approval status information for the user_id/level_id
		 *
		 * @return bool
		 */
		return apply_filters( 'pmproap_user_is_approved', ( 'approved' == $user_approval['status'] ? true : false ), $user_id, $level_id, $user_approval );
	}

	/**
	 * Check if a user is approved.
	 */
	public static function isDenied( $user_id = null, $level_id = null ) {
		//get approval for this user/level
		$user_approval = self::getUserApproval( $user_id, $level_id );

		//if no array, return false
		if ( empty( $user_approval ) || ! is_array( $user_approval ) ) {
			return false;
		}

		/**
		 * @filter pmproap_user_is_denied - Filter to override whether the user ID is denied for access to the level ID
		 *
		 * @param bool      $is_denied - Whether the $user_id is denied for the specified $level_id
		 * @param int       $user_id - The ID of the User being tested for approval
		 * @param int       $level_id - The ID of the Membership Level the $user_id is being thested for approval
		 * @param array     $user_approval - The approval status information for the user_id/level_id
		 *
		 * @return bool
		 */
		return apply_filters( 'pmproap_user_is_denied', ( 'denied' == $user_approval['status'] ? true : false ), $user_id, $level_id, $user_approval );
	}

	/**
	 * Check if a user is pending
	 */
	public static function isPending( $user_id = null, $level_id = null ) {
		//default to the current user
		if ( empty( $user_id ) ) {
			global $current_user;
			$user_id = $current_user->ID;
		}

		//get approval for this user/level
		$user_approval = self::getUserApproval( $user_id, $level_id );

		//if no array, check if they already had the level
		if ( empty( $user_approval ) || ! is_array( $user_approval ) ) {
			$level = pmpro_getMembershipLevelForUser( $user_id );

			if ( empty( $level ) || ( ! empty( $level_id ) && $level->id != $level_id ) ) {
				return true;
			} else {
				return false;
			}
		}

		/**
		 * @filter pmproap_user_is_pending - Filter to override whether the user ID is pending access to the level ID
		 *
		 * @param bool      $is_pending - Whether the $user_id is pending for the specified $level_id
		 * @param int       $user_id - The ID of the User being tested for approval
		 * @param int       $level_id - The ID of the Membership Level the $user_id is being thested for approval
		 * @param array     $user_approval - The approval status information for the user_id/level_id
		 *
		 * @return bool
		 */
		return apply_filters( 'pmproap_user_is_pending', ( 'pending' == $user_approval['status'] ? true : false ), $user_id, $level_id, $user_approval );
	}

	/**
	 * Get levels that require approval
	 */
	public static function getApprovalLevels() {
		$options = self::getOptions();

		$r = array();

		foreach ( $options as $level_id => $level_options ) {
			if ( $level_options['requires_approval'] ) {
				$r[] = $level_id;
			}
		}
		return $r;
	}

	/**
	 * Get list of approvals
	 */
	public static function getApprovals( $l = false, $s = '', $status = 'pending', $sortby = 'user_registered', $sortorder = 'ASC', $pn = 1, $limit = 15 ) {
		global $wpdb;

		$end   = $pn * $limit;
		$start = $end - $limit;

		$sql_parts = array();
		$sql_parts['SELECT'] = "SELECT SQL_CALC_FOUND_ROWS u.ID, u.user_login, u.user_email, UNIX_TIMESTAMP(u.user_registered) as joindate, mu.membership_id, mu.initial_payment, mu.billing_amount, mu.cycle_period, mu.cycle_number, mu.billing_limit, mu.trial_amount, mu.trial_limit, UNIX_TIMESTAMP(mu.startdate) as startdate, UNIX_TIMESTAMP(mu.enddate) as enddate, m.name as membership FROM $wpdb->users u ";
		$sql_parts['JOIN'] = "LEFT JOIN $wpdb->pmpro_memberships_users mu ON u.ID = mu.user_id LEFT JOIN $wpdb->pmpro_membership_levels m ON mu.membership_id = m.id ";
		$sql_parts['WHERE'] = "WHERE mu.status = 'active' AND mu.membership_id > 0 ";
		$sql_parts['GROUP'] = "";
		$sql_parts['LIMIT'] = "LIMIT $start, $limit";

		if ( ! empty( $status ) && $status != 'all' ) {
			$sql_parts['JOIN'] .= "LEFT JOIN $wpdb->usermeta um ON um.user_id = u.ID AND um.meta_key LIKE CONCAT('pmpro_approval_', mu.membership_id) ";
		}

		if ( ! empty( $s ) ) {
			$sql_parts['WHERE'] .= "AND (u.user_login LIKE '%" . esc_sql( $s ) . "%' OR u.user_email LIKE '%" . esc_sql( $s ) . "%' OR u.display_name LIKE '%" . esc_sql( $s ) . "%') ";
		}

		if ( $l ) {
			$sql_parts['WHERE'] .= "AND mu.membership_id = '" . esc_sql( $l ) . "' ";
		} else {
			$sql_parts['WHERE'] .= "AND mu.membership_id IN(" . implode( ',', self::getApprovalLevels() ) . ") ";
		}

		if ( ! empty( $status ) && $status != 'all' ) {
			$sql_parts['WHERE'] .= "AND um.meta_value LIKE '%\"" . esc_sql( $status ) . "\"%' ";
		}

		if ( $sortby == 'pmpro_approval' ) {
			$sql_parts['ORDER'] = "ORDER BY (um2.meta_value IS NULL) $sortorder ";
		} else {
			$sql_parts['ORDER'] = "ORDER BY $sortby $sortorder ";
		}

		/**
		 * Filters SQL parts for the query to fetch all users pending approval.
		 *
		 * @since
		 *
		 * @param array  $sql_parts The current SQL query parts
		 * @param int    $l         Level ID
		 * @param string $s         Search string
		 * @param string $status    Approval status
		 * @param string $sortby    Sort by
		 * @param string $sortby    Sort order
		 * @param int    $pn        Results page number
		 * @param int    $limit     Number of results per page limit
		 *
		 */
		$sql_parts = apply_filters(
			'pmpro_approvals_pending_approvals_sql_parts',
			$sql_parts,
			$l,
			$s,
			$status,
			$sortby,
			$sortorder ,
			$pn,
			$limit
		);

		$sqlQuery = $sql_parts['SELECT'] . $sql_parts['JOIN'] . $sql_parts['WHERE'] . $sql_parts['GROUP'] . $sql_parts['ORDER'] . $sql_parts['LIMIT'];

		/**
		 * Filters final SQL string for the query to fetch all users pending approval.
		 *
		 * @since
		 *
		 * @param array  $sqlQuery  The current SQL query
		 * @param int    $l         Level ID
		 * @param string $s         Search string
		 * @param string $status    Approval status
		 * @param string $sortby    Sort by
		 * @param string $sortby    Sort order
		 * @param int    $pn        Results page number
		 * @param int    $limit     Number of results per page limit
		 *
		 */
		$sqlQuery = apply_filters(
			'pmpro_approvals_pending_approvals_sql',
			$sqlQuery,
			$l,
			$s,
			$status,
			$sortby,
			$sortorder ,
			$pn,
			$limit
		);

		$theusers = $wpdb->get_results( $sqlQuery );

		return $theusers;
	}

	/**
	 * Approve a member.
	 *
	 * @param int  $user_id  The user ID.
	 * @param int  $level_id The Level ID.
	 * @param bool $force    Whether to force the appproval.
	 */
	public static function approveMember( $user_id, $level_id = null, $force = false ) {
		global $current_user, $msg, $msgt;

		//make sure they have permission
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'pmpro_approvals' ) && ! $force ) {
			$msg  = -1;
			$msgt = __( 'You do not have permission to perform approvals.', 'pmpro-approvals' );

			return false;
		}

		// get user's current level if none given.
		if ( empty( $level_id ) ) {
			$user_level = pmpro_getMembershipLevelForUser( $user_id );
			$level_id   = $user_level->id;
		}

		do_action( 'pmpro_approvals_before_approve_member', $user_id, $level_id );

		//complete intent payment
		$last_order = new MemberOrder();
		$order_status = 'success';
		$last_order->getLastMemberOrder( $user_id, $order_status );
		if($last_order->gateway == 'etsstripe'){
			//$order = $last_order->getMemberOrderByID($last_order_id);
			$payment_intent_id = $last_order->notes;
			$payment_intent = $last_order->Gateway->retrieve_payment_intent($payment_intent_id);
		
			if (! $last_order->payment_transaction_id && $payment_intent_id && $payment_intent  ) {
				$params = array(
					'expand' => array(
						'payment_method',
						'customer'
					),
				);
				if($confirm_payment->charges->data[0]){
					$last_order->payment_transaction_id = $confirm_payment->charges->data[0]->id;
				} else {					
					try{
						$confirm_payment = $payment_intent->confirm( $params );

					} catch ( Stripe\Error\Base $e ) {
						$msgt = $e->getMessage();
						//return false;
					} catch ( \Throwable $e ) {
						$msgt = $e->getMessage();
						//return false;
					} catch ( \Exception $e ) {
						$msgt = $e->getMessage();
						//return false;
					}
					if ( is_string( $confirm_payment ) ) {
						$order->error      = __( 'Error processing payment intent.', 'paid-memberships-pro' ) . ' ' . $confirm_payment;
						$order->shorterror = $order->error;
						return false;
					}
					// Payment should now be processed.
					$payment_transaction_id = $confirm_payment->charges->data[0]->id;
					$last_order->payment_transaction_id = $payment_transaction_id;
				}
			}
			if ( $payment_intent && $payment_intent_id ) {
				//$customer = $payment_intent_id->customer;
				$customer_id = $payment_intent->customer;
			}
			//Create subscription if level is recurring.
			if ( pmpro_isLevelRecurring( $user_level ) && ! $last_order->subscription_transaction_id && $customer_id ) {
				$last_order->PaymentAmount = $user_level->billing_amount;
				$last_order->BillingPeriod    = $user_level->cycle_period;
				$last_order->BillingFrequency = $user_level->cycle_number;
				try{
					$subscription = $last_order->Gateway->create_subscription_for_customer_from_order($customer_id, $last_order );
				}
				catch ( Stripe\Error\Base $e ) {
					$msgt = $e->getMessage();
				} catch ( \Throwable $e ) {
					$msgt = $e->getMessage();
				} catch ( \Exception $e ) {
					$msgt = $e->getMessage();
				}
				if ( empty( $subscription ) ) {
					// There was an issue creating the subscription.
					$last_order->error      = __( 'Error creating subscription for customer.', 'paid-memberships-pro' );
					$last_order->shorterror = $order->error;
					return false;
				}
				$setup_intent = $subscription->pending_setup_intent;
				if ( ! empty( $setup_intent->status ) && 'requires_action' === $setup_intent->status ) {
					// We will need to reload the page to authenticate, so save the subscription ID in the setup intent
					// so that we don't lose it.
					$setup_intent = $last_order->Gateway->add_subscription_id_to_setup_intent( $setup_intent, $subscription->id );
					if ( is_string( $setup_intent ) ) {
						$last_order->error      = $setup_intent;
						$last_order->shorterror = $last_order->error;
						return false;
					}
					$last_order->stripe_setup_intent = $setup_intent;
					$last_order->errorcode = true;
					$last_order->error     = __( 'Customer authentication is required to finish setting up your subscription. Please complete the verification steps issued by your payment provider.', 'paid-memberships-pro' );
		
					return false;
				}

				// Successfully created a subscription.
				$subscription_transaction_id = $subscription->id;
				$last_order->subscription_transaction_id = $subscription_transaction_id;
			}
		}
		$last_order->saveOrder();

		// update user meta to save timestamp and user who approved.
		update_user_meta(
			$user_id, 'pmpro_approval_' . $level_id, array(
				'status'    => 'approved',
				'timestamp' => current_time( 'timestamp' ),
				'who'       => $current_user->ID,
				'approver'  => $current_user->user_login,
			)
		);

		// delete the approval count cache.
		delete_transient( 'pmpro_approvals_approval_count' );

		// update statuses/etc.
		$msg  = 1;
		$msgt = __( 'Member was approved.', 'pmpro-approvals' );

		/**
		 * Potentially skip emails sent to admin/member.
		 *
		 * Skip sending if value is false.
		 *
		 * @since 1.3.5
		 *
		 * @param boolean true to skip email, false to to not (default false)
		 * @param int     $user_id  The user ID to approve.
		 * @param int     $level_id The level ID to approve.
		 * @param boolean $force Whether the approval was forced.
		 */
		$send_emails = apply_filters( 'pmpro_approvals_after_approve_member_send_emails', true, $user_id, $level_id, $force );

		if ( $send_emails ) {
			// send email to user and admin.
			$approval_email = new PMPro_Approvals_Email();
			$approval_email->sendMemberApproved( $user_id );
			$approval_email->sendAdminApproval( $user_id );
		}
		
		self::updateUserLog( $user_id, $level_id );

		do_action( 'pmpro_approvals_after_approve_member', $user_id, $level_id );

		return true;
	}

	/**
	 * Deny a member
	 */
	public static function denyMember( $user_id, $level_id ) {
		global $current_user, $msg, $msgt;

		//make sure they have permission
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'pmpro_approvals' ) ) {
			$msg  = -1;
			$msgt = __( 'You do not have permission to perform approvals.', 'pmpro-approvals' );

			return false;
		}

		//get user's current level if none given
		if ( empty( $level_id ) ) {
			$user_level = pmpro_getMembershipLevelForUser( $user_id );
			$level_id   = $user_level->id;
		}

		do_action( 'pmpro_approvals_before_deny_member', $user_id, $level_id );

		//update user meta to save timestamp and user who approved
		update_user_meta(
			$user_id, 'pmpro_approval_' . $level_id, array(
				'status'    => 'denied',
				'timestamp' => time(),
				'who'       => $current_user->ID,
				'approver'  => $current_user->user_login,
			)
		);

		//delete the approval count cache
		delete_transient( 'pmpro_approvals_approval_count' );

		//update statuses/etc
		$msg  = 1;
		$msgt = __( 'Member was denied.', 'pmpro-approvals' );

		// Send email to member and admin.
		$denied_email = new PMPro_Approvals_Email();
		$denied_email->sendMemberDenied( $user_id );
		$denied_email->sendAdminDenied( $user_id );

		self::updateUserLog( $user_id, $level_id );

		do_action( 'pmpro_approvals_after_deny_member', $user_id, $level_id );

		return true;

	}

	/**
	 * Reset a member to pending approval status
	 */
	public static function resetMember( $user_id, $level_id ) {
		global $current_user, $msg, $msgt;

		//make sure they have permission
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'pmpro_approvals' ) ) {
			$msg  = -1;
			$msgt = __( 'You do not have permission to perform approvals.', 'pmpro-approvals' );

			return false;
		}

		//get user's current level if none given
		if ( empty( $level_id ) ) {
			$user_level = pmpro_getMembershipLevelForUser( $user_id );
			$level_id   = $user_level->id;
		}

		do_action( 'pmpro_approvals_before_reset_member', $user_id, $level_id );

		update_user_meta(
			$user_id, 'pmpro_approval_' . $level_id, array(
				'status'    => 'pending',
				'timestamp' => current_time( 'timestamp' ),
				'who'       => $current_user->ID,
				'approver'  => $current_user->user_login,
			)
		);
		
		//delete the approval count cache
		delete_transient( 'pmpro_approvals_approval_count' );

		$msg  = 1;
		$msgt = __( 'Approval reset.', 'pmpro-approvals' );

		self::updateUserLog( $user_id, $level_id );

		do_action( 'pmpro_approvals_after_reset_member', $user_id, $level_id );

		return true;

	}

	/**
	 * Set approval status to pending for new members
	 */
	public static function pmpro_before_change_membership_level( $level_id, $user_id, $old_levels, $cancel_level ) {

		// First see if the user is cancelling. If so, try to clean up approval data if they are pending.
		if ( $level_id == 0 ) {
			if ( self::isPending( $user_id, $old_levels[0]->ID ) ) {
				self::clearApprovalData( $user_id, $old_levels[0]->ID, apply_filters( 'pmpro_approvals_delete_log_on_cancel', false ) );
			}
		}

		//check if level requires approval, if not stop executing this function and don't send email.
		if ( ! self::requiresApproval( $level_id ) ) {
			return;
		}

		//if they are already approved, keep them approved
		if ( self::isApproved( $user_id, $level_id ) ) {
			return;
		}

		//if they are denied, keep them denied (we're blocking checkouts elsewhere, so this is an admin change/etc)
		if ( self::isDenied( $user_id, $level_id ) ) {
			return;
		}

		//if this is their current level, assume they were grandfathered in and leave it alone
		if ( pmpro_hasMembershipLevel( $level_id, $user_id ) ) {
			return;
		}

		//else, we need to set their status to pending
		update_user_meta(
			$user_id, 'pmpro_approval_' . $level_id, array(
				'status'    => 'pending',
				'timestamp' => current_time( 'timestamp' ),
				'who'       => '',
				'approver'  => '',
			)
		);
		
		//delete the approval count cache
		delete_transient( 'pmpro_approvals_approval_count' );
	}

	/**
	 * Send an email to an admin when a user has signed up for a membership level that requires approval.
	 */
	public static function pmpro_after_change_membership_level( $level_id, $user_id ) {

		//check if level requires approval, if not stop executing this function and don't send email.
		if ( ! self::requiresApproval( $level_id ) ) {
			return;
		}
		
		//if they are already approved, keep them approved
		if ( self::isApproved( $user_id, $level_id ) ) {
			return;
		}

		//send email to admin that a new member requires approval.
		$email = new PMPro_Approvals_Email();
		$email->sendAdminPending( $user_id );
	}

	/**
	 * Show a different message for users that have their membership awaiting approval.
	 */
	public static function pmpro_non_member_text_filter( $text ) {

		global $current_user, $has_access;

		//if a user does not have a membership level, return default text.
		if ( ! pmpro_hasMembershipLevel() ) {
			return $text;
		} else {
			//get current user's level ID
			$users_level = pmpro_getMembershipLevelForUser( $current_user->ID );
			$level_id    = $users_level->ID;
			if ( self::requiresApproval( $level_id ) && self::isPending() ) {
				$text = __( 'Your membership requires approval before you are able to view this content.', 'pmpro-approvals' );
			} elseif ( self::requiresApproval( $level_id ) && self::isDenied() ) {
				$text = __( 'Your membership application has been denied. Contact the site owners if you believe this is an error.', 'pmpro-approvals' );
			}
		}

		return $text;
	}

	/**
	 * Set user action links for approvals page
	 */
	public static function pmpro_approvals_user_row_actions( $actions, $user ) {
		$cap = apply_filters( 'pmpro_approvals_cap', 'pmpro_approvals' );

		if ( current_user_can( 'edit_users' ) && ! empty( $user->ID ) ) {
			$actions[] = '<a href="' . admin_url( 'user-edit.php?user_id=' . $user->ID ) . '">Edit</a>';
		}

		if ( current_user_can( $cap ) && ! empty( $user->ID ) ) {
			$actions[] = '<a href="' . admin_url( 'admin.php?page=pmpro-approvals&user_id=' . $user->ID ) . '">View</a>';
		}

		return $actions;
	}

	/**
	 * Add Approvals status to Account Page.
	 */
	public static function pmpro_account_bullets_top() {
			$approval_status = ucfirst( self::getUserApprovalStatus() );

			$user_level = pmpro_getMembershipLevelForUser();
			if ( empty( $user_level ) ) {
				return;
			}
			
			$level_approval = self::requiresApproval( $user_level->ID );

			// Only show this if the user has an approval status.
			if ( $level_approval ) {
			  printf( '<li><strong>' . __( 'Status', 'pmpro-approvals' ) . ':' . '</strong> %s</li>', $approval_status );
			}
	}

	/**
	 * Add approval status to the members list in the dashboard
	 */
	public static function pmpro_members_list_user( $user ) {

	// Hide ('pending') link from the following statuses.
	$status_in = apply_filters( 'pmpro_approvals_members_list_status', array( 'oldmembers', 'cancelled', 'expired' ) );
	$level_type = isset( $_REQUEST['l'] ) ? $_REQUEST['l'] : '';

	// Bail if this is the dashboard page.
	if ( isset( $_REQUEST['page'] ) && $_REQUEST['page'] === 'pmpro-dashboard' ) {
		return $user;
	}

	// Show user status if the user is pending.
	if ( current_user_can( 'pmpro_approvals' ) && self::isPending( $user->ID, $user->membership_id ) && ! in_array( $level_type, $status_in ) ) {
		$user->membership .= ' (<a href="' . admin_url( 'admin.php?page=pmpro-approvals&s=' . urlencode( $user->user_email ) ) . '">' . __( 'Pending', 'pmpro-approvals' ) . '</a>)';
	}

	return $user;
}

	/**
	 * Add the approval membership level template
	 */
	public static function pmpro_membershiplevels_template_level( $level, $template ) {
		if ( $template === 'approvals' ) {
			$level->billing_amount = NULL;
			$level->trial_amount = NULL;
			$level->initial_payment = NULL;
			$level->billing_limit = NULL;
			$level->trial_limit = NULL;
			$level->expiration_number = NULL;
			$level->expiration_period = NULL;
			$level->cycle_number = 1;
			$level->cycle_period = 'Month';
		}
		return $level;
	}

	/**
	 * Custom confirmation message for levels that requires approval.
	 */
	public static function pmpro_confirmation_message( $confirmation_message, $pmpro_invoice ) {

		global $current_user;

		$approval_status = self::getUserApprovalStatus();

		$users_level = pmpro_getMembershipLevelForUser( $current_user->ID );
		$level_id    = $users_level->ID;

		//if current level does not require approval keep confirmation message the same.
		if ( ! self::requiresApproval( $level_id ) ) {
			return $confirmation_message;
		}

		$email_confirmation = self::getEmailConfirmation( $current_user->ID );

		if ( ! $email_confirmation ) {
			$approval_status = __( 'pending', 'pmpro-approvals' );
		}

		$confirmation_message = '<p>' . sprintf( __( 'Thank you for your membership to %1$s. Your %2$s membership status is: <b>%3$s</b>.', 'pmpro-approvals' ), get_bloginfo( 'name' ), $current_user->membership_level->name, $approval_status ) . '</p>';

		// Check instructions
		if ( ! empty( $pmpro_invoice ) && $pmpro_invoice->gateway == "check" && ! pmpro_isLevelFree( $pmpro_invoice->membership_level ) ) {
			$confirmation_message .= '<div class="pmpro_payment_instructions">' . wpautop( wp_unslash( pmpro_getOption("instructions") ) ) . '</div>';
		}


		$confirmation_message .= '<p>' . sprintf( __( 'Below are details about your membership account and a receipt for your initial membership invoice. A welcome email with a copy of your initial membership invoice has been sent to %s.', 'pmpro-approvals' ), $current_user->user_email ) . '</p>';

		return $confirmation_message;
	}

	/**
	 * Add email templates support for PMPro Edit Email Templates Add-on.
	 */
	public static function pmproet_templates( $pmproet_email_defaults ) {

		//Add admin emails to the PMPro Edit Email Templates Add-on list.
		$pmproet_email_defaults['admin_approved'] = array(
			'subject'     => __( 'A user has been approved for !!membership_level_name!!', 'pmpro-approvals' ),
			'description' => __( 'Approvals - Approved Email (admin)', 'pmpro-approvals' ),
			'body'        => file_get_contents( PMPRO_APP_DIR . '/email/admin_approved.html' ),
		);

		$pmproet_email_defaults['admin_denied'] = array(
			'subject'     => __( 'A user has been denied for !!membership_level_name!!', 'pmpro-approvals' ),
			'description' => __( 'Approvals - Denied Email (admin)', 'pmpro-approvals' ),
			'body'        => file_get_contents( PMPRO_APP_DIR . '/email/admin_denied.html' ),
		);

		$pmproet_email_defaults['admin_notification_approval'] = array(
			'subject'     => __( 'A user requires approval', 'pmpro-approvals' ),
			'description' => __( 'Approvals - Requires Approval (admin)', 'pmpro-approvals' ),
			'body'        => file_get_contents( PMPRO_APP_DIR . '/email/admin_notification.html' ),
		);


		//Add user emails to the PMPro Edit Email Templates Add-on list.
		$pmproet_email_defaults['application_approved'] = array(
			'subject'     => __( 'Your membership to !!sitename!! has been approved.', 'pmpro-approvals' ),
			'description' => __( 'Approvals - Approved Email', 'pmpro-approvals' ),
			'body'        => file_get_contents( PMPRO_APP_DIR . '/email/application_approved.html' ),
		);

		$pmproet_email_defaults['application_denied'] = array(
			'subject'     => __( 'Your membership to !!sitename!! has been denied.', 'pmpro-approvals' ),
			'description' => __( 'Approvals - Denied Email', 'pmpro-approvals' ),
			'body'        => file_get_contents( PMPRO_APP_DIR . '/email/application_denied.html' ),
		);

		return $pmproet_email_defaults;
	}

	/**
	 * Adjust default emails to show that the user is pending.
	 */
	public static function pmpro_email_filter( $email ) {

		//build an array to hold the email templates to adjust if a level is pending. (User templates only.)
		$email_templates = array( 'checkout_free', 'checkout_check', 'checkout_express', 'checkout_freetrial', 'checkout_paid', 'checkout_trial' );

		//if the level requires approval and is in the above array.
		if ( in_array( $email->template, $email_templates ) && self::requiresApproval( $email->data['membership_id'] ) ) {

			//Change the body text to show pending by default.
			$email->body = str_replace( 'Your membership account is now active.', __( 'Your membership account is now pending. You will be notified once your account has been approved/denied.', 'pmpro-approvals' ), $email->body );

		}

		return $email;

	}

	//Approve members from edit profile in WordPress.
	public static function show_user_profile_status( $user ) {

		//get some info about the user's level
		if ( isset( $_REQUEST['membership_level'] ) ) {
			$level_id = intval( $_REQUEST['membership_level'] );
			$level    = pmpro_getLevel( $level_id );
		} else {
			$level = pmpro_getMembershipLevelForUser( $user->ID );
			if ( ! empty( $level ) ) {
				$level_id = $level->id;
			} else {
				$level_id = null;
			}
		}

		//process any approve/deny/reset click
		if ( current_user_can( 'pmpro_approvals' ) ) {
			if ( ! empty( $_REQUEST['approve'] ) ) {
				self::approveMember( intval( $_REQUEST['approve'] ), $level_id );
			} elseif ( ! empty( $_REQUEST['deny'] ) ) {
				self::denyMember( intval( $_REQUEST['deny'] ), $level_id );
			} elseif ( ! empty( $_REQUEST['unapprove'] ) ) {
				self::resetMember( intval( $_REQUEST['unapprove'] ), $level_id );
			}
		}

		//show info
		?>
		<table id="pmpro_approvals_status_table" class="form-table">
			<tr>
				<th><?php esc_html_e( 'Approval Status', 'pmpro-approvals' ); ?></th>

				<td>
					<span id="pmpro_approvals_status_text">
						<?php echo self::getUserApprovalStatus( $user->ID, null, false ); ?>
					</span>
					<?php if ( current_user_can( 'pmpro_approvals' ) ) { ?>
					<span id="pmpro_approvals_reset_link" 
					<?php
					if ( self::isPending( $user->ID, $level_id ) ) {
?>
style="display: none;"<?php } ?>>
						<!--[<a href="javascript:askfirst('Are you sure you want to reset approval for <?php echo $user->user_login; ?>?', '?&user_id=<?php echo $user->ID; ?>&unapprove=<?php echo $user->ID; ?>');">X</a>]-->
					</span>
					<span id="pmpro_approvals_approve_deny_links" 
					<?php
					if ( ! self::isPending( $user->ID, $level_id ) ) {
?>
style="display: none;"<?php } ?>>
						<a href="?user_id=<?php echo $user->ID; ?>&approve=<?php echo $user->ID; ?>">Approve</a> |
						<a href="?user_id=<?php echo $user->ID; ?>&deny=<?php echo $user->ID; ?>">Deny</a>
					</span>
					<?php } ?>
				</td>
			</tr>
			<?php
			//only show user approval log if user can edit or has pmpro_approvals.
			if ( current_user_can( 'edit_users' ) || current_user_can( 'pmpro_approvals' ) ) {
			?>
			<tr>
				<th><?php esc_html_e( 'User Approval Log', 'pmpro-approvals' ); ?></th>
					<td>
					<?php
					echo self::showUserLog( $user->ID );
					?>
					</td>
			</tr>

			<?php } ?>
		</table>
		<script>
			var pmpro_approval_levels = <?php echo json_encode( self::getApprovalLevels() ); ?>;
			var pmpro_approval_user_status_per_level = <?php echo json_encode( self::getUserApprovalStatuses( $user->ID, true ) ); ?>;
			var pmpro_approval_user_status_full_per_level = <?php echo json_encode( self::getUserApprovalStatuses( $user->ID ) ); ?>;
			
			function pmpro_approval_updateApprovalStatus() {
				//get the level from the dropdown
				var olevel = <?php echo json_encode( $level_id ); ?>;
				var level = jQuery('[name=membership_level]').val();
				
				//no level field, default to the user's level id
				if(typeof(level) === 'undefined')
					level = olevel;
				
				//if no level, hide it
				if(level == '') {
					//no level, so hide everything
					jQuery('#pmpro_approvals_status_table').hide();
				} else if(pmpro_approval_levels.indexOf(parseInt(level)) < 0) {
					//show the field, but hide the actions
					jQuery('#pmpro_approvals_reset_link').hide();
					jQuery('#pmpro_approvals_approve_deny_links').hide();
					
					jQuery('#pmpro_approvals_status_text').html(<?php echo json_encode( __( 'The chosen level does not require approval.', 'pmpro-approvals' ) ); ?>);
					
					jQuery('#pmpro_approvals_status_table').show();
				} else {
					//show the status and action links
					jQuery('#pmpro_approvals_status_text').html(pmpro_approval_user_status_full_per_level[level]);
					jQuery('#pmpro_approvals_status_table').show();
					
					if(level == olevel) {
						if(pmpro_approval_user_status_per_level[level] == 'pending') {
							jQuery('#pmpro_approvals_reset_link').hide();
							jQuery('#pmpro_approvals_approve_deny_links').show();
						} else {
							jQuery('#pmpro_approvals_reset_link').show();
							jQuery('#pmpro_approvals_approve_deny_links').hide();
						}
					} else {
						jQuery('#pmpro_approvals_reset_link').hide();
						jQuery('#pmpro_approvals_approve_deny_links').hide();
					}
				}				
			}
			
			//update approval status when the membership level select changes
			jQuery('[name=membership_level]').change(function(){pmpro_approval_updateApprovalStatus();});
			
			//call this once on load just in case
			pmpro_approval_updateApprovalStatus();
		</script>
		<?php
	}

	/**
	 * Clear database data if the user changes their level while pending.
	 * @since 1.4
	 */
	public static function clearApprovalData( $user_id, $level_id = NULL, $force = NULL, $status = 'pending' ) {

		// try to get the current user level.
		if ( empty( $level_id ) ) {
			$user_level = pmpro_getMembershipLevelForUser( $user_id );
			$level_id   = $user_level->id;
		}

		do_action( 'pmpro_approvals_before_cleaned_approval_meta', $user_id, $level_id );
		
		// If force set to true, we can delete all approval data for the user when they cancel their level.
		if ( $force && $level_id == 0 ) {
			delete_user_meta( $user_id, 'pmpro_approval_' . $level_id );
			delete_user_meta( $user_id, 'pmpro_approval_log' );
		} else {
			// Get user meta and only remove the approval where status is pending.
			$approval_status = get_user_meta( $user_id, 'pmpro_approval_' . $level_id );
			foreach( $approval_status as $key => $data ) {
				// Remove this from the approvals array.
				if ( $data['status'] === $status ) {
					unset( $approval_status[$key] );
				}
			}
			
			// Let's clean up the user meta table a bit more smartly if they have no data pending/approved etc.
			if ( is_array( $approval_status ) && empty( $approval_status[0] ) ) {
				delete_user_meta( $user_id, 'pmpro_approval_' . $level_id );
			} else {
				update_user_meta( $user_id, 'pmpro_approval_' . $level_id, $approval_status );
			}

		}

		delete_transient( 'pmpro_approvals_approval_count' );

		do_action( 'pmpro_approvals_after_cleaned_approval_meta', $user_id, $level_id );
		// If we made it here, let's assume it worked okay
		return true;

	}

	/**
	 * Code generates user log for all users that require approval.
	 * @since 1.0.2
	 */
	public static function updateUserLog( $user_id, $level_id ) {

		//get user's approval status
		$users_approval_information = get_user_meta( $user_id, 'pmpro_approval_' . $level_id, true );

		$data = get_user_meta( $user_id, 'pmpro_approval_log', true );

		if ( ! array( $data ) || empty( $data ) ) {
			$data = array();
		}

		$data[] = $users_approval_information['status'] . ' by ' . $users_approval_information['approver'] . ' on ' . date_i18n( get_option( 'date_format' ), $users_approval_information['timestamp'] );

		update_user_meta( $user_id, 'pmpro_approval_log', $data );

		return true;

	}

	/**
	 * Show the user's approval log in <ul> form
	 * @since 1.0.2
	 */
	public static function showUserLog( $user_id = null ) {

		//If no user ID is available revert back to current user ID.
		if ( empty( $user_id ) ) {
			global $current_user;
			$user_id = $current_user->ID;
		}

		//create a variable to generate the unordered list and populate according to meta.
		$generated_list = '<ul id="pmpro-approvals-log">';

		//Get the approval log array meta.
		$approval_log_meta = get_user_meta( $user_id, 'pmpro_approval_log', true );

		if ( ! empty( $approval_log_meta ) ) {

			$approval_log = array_reverse( $approval_log_meta );

			foreach ( $approval_log as $key => $value ) {
				$generated_list .= '<li><pre>' . $value . '</pre></li>';
			}

			$generated_list .= '</ul>';

		} else {
			$generated_list = __( 'No approval history found.', 'pmpro-approvals' );
		}

		return $generated_list;
	}

	/**
	 * Calculate how many members are currently pending, approved or denied.
	 * @return (int) Numeric value of members.
	 * @since 1.0.2
	 */
	public static function getApprovalCount( $approval_status = null ) {

		global $wpdb, $menu, $submenu;

		// Default to pending status.
		if ( empty( $approval_status ) ) {
			$approval_status = 'pending';
		}

		// Check for a cached value in the transient.
		$number_of_users = get_transient( 'pmpro_approvals_approval_count' );	
		
		// Store results in an array to support different statuses.
		if ( ! isset( $number_of_users ) ) {
			$number_of_users = array();
		}
		
		// If we don't have this value yet, get all users with 'pending' status.
		if ( ! isset( $number_of_users[$approval_status] ) ) {
			$sql_parts = array();
			$sql_parts['SELECT'] = "SELECT COUNT(mu.user_id) as count FROM $wpdb->pmpro_memberships_users mu ";
			$sql_parts['JOIN'] = "LEFT JOIN $wpdb->usermeta um ON um.user_id = mu.user_id AND um.meta_key LIKE CONCAT('pmpro_approval_', mu.membership_id) ";
			$sql_parts['WHERE'] = "WHERE mu.status = 'active' AND mu.membership_id > 0 AND um.meta_value LIKE '%" . esc_sql( $approval_status ) . "%' ";
			$sql_parts['GROUP'] = "";
			$sql_parts['ORDER'] = "";
			$sql_parts['LIMIT'] = "";

			/**
			 * Filters SQL parts for the query to get pending approvals count.
			 *
			 * @since
			 *
			 * @param array  $sql_parts       The current SQL query parts
			 * @param string $approval_status Approval status
			 */
			$sql_parts = apply_filters(
				'pmpro_approvals_approval_count_sql_parts',
				$sql_parts,
				$approval_status
			);

			$sqlQuery = $sql_parts['SELECT'] . $sql_parts['JOIN'] . $sql_parts['WHERE'] . $sql_parts['GROUP'] . $sql_parts['ORDER'] . $sql_parts['LIMIT'];

			/**
			 * Filters final SQL string for the query to get pending approvals count.
			 *
			 * @since
			 *
			 * @param array  $sql_parts       The current SQL query parts
			 * @param string $approval_status Approval status
			*
			*/
			$sqlQuery = apply_filters(
				'pmpro_approvals_approval_count_sql',
				$sqlQuery,
				$approval_status
			);

			$results         = $wpdb->get_results( $sqlQuery );
			$number_of_users[$approval_status] = (int) $results[0]->count;
			
			set_transient( 'pmpro_approvals_approval_count', $number_of_users, 3600*24 );
		}

		return $number_of_users[$approval_status];
	}

	/**
	 * Call pmpro_hasMembershipLevel without our filters enabled
	 */
	public static function hasMembershipLevelSansApproval( $level_id = null, $user_id = null ) {
		//unhook our stuff
		remove_filter( 'pmpro_has_membership_level', array( 'PMPro_Approvals', 'pmpro_has_membership_level' ), 10, 3 );

		//ask PMPro
		$r = pmpro_hasMembershipLevel( $level_id, $user_id );

		//hook our stuff back up
		add_filter( 'pmpro_has_membership_level', array( 'PMPro_Approvals', 'pmpro_has_membership_level' ), 10, 3 );

		return $r;
	}

	/**
	 * Integration with Email Confirmation Add On.
	 * call this function to see if the user's email has been confirmed.
	 * @return boolean
	 */
	public static function getEmailConfirmation( $user_id ) {

		if ( ! function_exists( 'pmproec_load_plugin_text_domain' ) ) {
			return true;
		}

		$status             = array( 'validated', '' );
		$email_confirmation = get_user_meta( $user_id, 'pmpro_email_confirmation_key', true );

		if ( in_array( $email_confirmation, $status ) ) {
			$r = true;
		} else {
			$r = false;
		}

		$r = apply_filters( 'pmpro_approvals_email_confirmation_status', $r );

		return $r;
	}


	/**
	 * Integrate with Membership Directory Add On.
	 * @since 1.3
	 */
	public static function pmpro_member_directory_sql_parts( $sql_parts, $levels, $s, $pn, $limit, $start, $end, $order_by, $order ) {
		global $wpdb;
		$sql_parts['JOIN'] .= "LEFT JOIN {$wpdb->usermeta} umm
		ON umm.meta_key = CONCAT('pmpro_approval_', mu.membership_id)
		  AND umm.meta_key != 'pmpro_approval_log'
		  AND u.ID = umm.user_id ";

		$sql_parts['WHERE'] .= "AND ( umm.meta_value LIKE '%approved%' OR umm.meta_value IS NULL ) ";

		return $sql_parts;
	}

		/**
	 * Helper function to change the order status to 'success' for Pay By Check Add On when user is approved.
	 * @since 1.4
	 */
	public static function pmpro_pay_by_check_approve( $user_id, $level_id, $order_id = NULL ) {

		//If Pay By Check Add On not set, just bail.
		if ( ! defined( 'PMPROPBC_VER' ) ) {
			return;
		}

		// User's have to physically set this as a filter for now.
		if ( ! apply_filters( 'pmpro_approvals_pbc_success_on_approval', false ) ) {
			return;
		}

		//Check to see if the user's level that was approved had pay by check.
		$requires_check = pmpropbc_getOptions( $level_id );

		if ( $requires_check ) {
			$order = new MemberOrder();
			$order->getLastMemberOrder( $user_id, 'pending', $level_id );

			if ( isset( $order->gateway ) && $order->gateway == 'check' ) {
				$order->status = 'success';
				$order->saveOrder();
			}
		}
	}


	/**
	 * Add links to the plugin row meta
	 */
	public static function plugin_row_meta( $links, $file ) {
		if ( strpos( $file, 'pmpro-approvals' ) !== false ) {
			$new_links = array(
				'<a href="' . esc_url( 'https://www.paidmembershipspro.com/add-ons/approval-process-membership/' ) . '" title="' . esc_attr( __( 'View Documentation', 'pmpro-approvals' ) ) . '">' . __( 'Docs', 'pmpro-approvals' ) . '</a>',
				'<a href="' . esc_url( 'https://paidmembershipspro.com/support/' ) . '" title="' . esc_attr( __( 'Visit Customer Support Forum', 'pmpro-approvals' ) ) . '">' . __( 'Support', 'pmpro-approvals' ) . '</a>',
			);
			$links     = array_merge( $links, $new_links );
		}
		return $links;
	}

	/**
	 * Load the languages folder for i18n.
	 * Translations can be found within 'languages' folder.
	 * @since 1.0.5
	 */
	public static function text_domain() {

		load_plugin_textdomain( 'pmpro-approvals', false, basename( dirname( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Change "Your Level" to "Awaiting Approval" in these instances for pending users. 
	 * @since 1.3
	 */
	public static function change_your_level_text( $translated_text, $text, $domain ) {
		global $current_user;

		$approved = self::isApproved( $current_user->ID );

		// Bail if the user is approved.
		if ( $approved ) {
			return $translated_text;
		}

		$approval = self::getUserApproval( $current_user->ID );

		if ( $domain == 'paid-memberships-pro' ) {
			if ( $translated_text == 'Your&nbsp;Level' ) {
				if ( $approval['status'] == 'pending' ){
					$translated_text = __( 'Pending Approval', 'pmpro-approvals' );
				} else {
					$translated_text = __( 'Membership Denied', 'pmpro-approvals' );
				}
			}
		}
		return $translated_text;
	}


} // end class

PMPro_Approvals::get_instance();
