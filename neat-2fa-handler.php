<?php
/**
 * Plugin Name: Neat2FA Handler
 * Description: Adds secure email confirmation for registration and checkout, plus admin 2FA controls integrated with the Two Factor plugin.
 * Version: 0.2.1
 * Author: Jv Secate
 * Text Domain: neat-2fa-handler
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ASW_Account_Security {
	const VERSION                            = '0.2.1';
	const OPTION_KEY                         = 'asw_account_security_options';
	const REGISTRATION_CODE_NAME             = 'asw_registration_verification_code';
	const REGISTRATION_PASSWORD_CONFIRM_NAME = 'asw_registration_password_confirm';
	const CHECKOUT_CODE_NAME                 = 'asw_checkout_verification_code';
	const USER_EMAIL_CONFIRMED               = '_asw_email_confirmed_at';
	const USER_CHECKOUT_CONFIRMED            = '_asw_checkout_email_confirmed_at';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_hooks' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );

		add_filter( 'two_factor_providers', array( $this, 'filter_two_factor_providers' ), 20 );
		add_filter( 'two_factor_enabled_providers_for_user', array( $this, 'enforce_admin_two_factor_providers' ), 20, 2 );
		add_filter( 'two_factor_primary_provider_for_user', array( $this, 'prefer_admin_totp_provider' ), 20, 2 );
	}

	public function register_hooks() {
		$this->ensure_account_flow_options();

		if ( $this->enabled( 'registration_enabled' ) ) {
			add_action( 'woocommerce_register_form', array( $this, 'render_registration_password_confirmation' ), 6 );
			add_filter( 'woocommerce_registration_errors', array( $this, 'validate_registration_password_confirmation' ), 20, 3 );
			add_action( 'wp_loaded', array( $this, 'maybe_start_registration_verification' ), 5 );
			add_action( 'template_redirect', array( $this, 'maybe_render_registration_verification_page' ), 0 );
			add_action( 'user_register', array( $this, 'mark_registered_user_confirmed' ) );
		}

		add_action( 'wp_loaded', array( $this, 'maybe_block_account_password_change_submission' ), 4 );
		add_action( 'woocommerce_edit_account_form', array( $this, 'render_account_password_recovery_panel' ), 30 );
		add_action( 'woocommerce_save_account_details_errors', array( $this, 'block_account_password_changes' ), 5, 2 );

		add_action( 'woocommerce_checkout_after_customer_details', array( $this, 'render_checkout_fields' ) );
		add_action( 'woocommerce_checkout_before_order_review', array( $this, 'render_checkout_fields' ) );
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_checkout_fields' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_checkout_process' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout' ), 999, 2 );
		add_filter( 'rest_pre_dispatch', array( $this, 'validate_store_api_checkout' ), 10, 3 );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
	}

	private function ensure_account_flow_options() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$this->ensure_option( 'woocommerce_registration_generate_username', 'yes' );
		$this->ensure_option( 'woocommerce_registration_generate_password', 'no' );

		if ( '' === (string) get_option( 'woocommerce_myaccount_lost_password_endpoint', '' ) ) {
			$this->ensure_option( 'woocommerce_myaccount_lost_password_endpoint', 'lost-password' );
		}
	}

	private function ensure_option( $key, $value ) {
		if ( (string) get_option( $key, '' ) !== (string) $value ) {
			update_option( $key, $value );
		}
	}

	private function defaults() {
		return array(
			'registration_enabled'       => 1,
			'checkout_valid_hours'       => 12,
			'code_length'                => 8,
			'code_ttl_minutes'           => 15,
			'resend_cooldown_seconds'    => 60,
			'max_attempts'               => 5,
			'admin_2fa_enabled'          => 1,
			'admin_2fa_roles'            => array( 'administrator', 'shop_manager' ),
			'admin_2fa_providers'        => array( 'Two_Factor_Totp', 'Two_Factor_Email', 'Two_Factor_Backup_Codes' ),
			'admin_2fa_force_email'      => 1,
			'admin_2fa_prefer_totp'      => 1,
			'registration_email_subject' => '[{site_name}] Your verification code',
			'registration_email_body'    => $this->default_email_template( 'registration' ),
			'checkout_email_subject'     => '[{site_name}] Your checkout verification code',
			'checkout_email_body'        => $this->default_email_template( 'checkout' ),
		);
	}

	private function default_email_template( $context ) {
		$is_checkout = 'checkout' === $context;
		$headline    = $is_checkout ? 'Confirm your checkout email' : 'Confirm your email address';
		$intro       = $is_checkout ? 'Use this code to confirm your billing email and continue checkout.' : 'Use this code to finish creating your account.';
		$detail      = $is_checkout ? 'After confirmation, logged-in customers can reuse this confirmation for {checkout_valid_hours} hours. Guests need a fresh code for each checkout.' : 'If you did not request this account, you can safely ignore this email.';

		return '<div style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">'
			. '<div style="max-width:560px;margin:0 auto;padding:32px 16px;">'
			. '<div style="background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;">'
			. '<div style="padding:28px 28px 20px;border-bottom:1px solid #eef2f7;">'
			. '<p style="margin:0 0 8px;font-size:13px;line-height:1.5;color:#64748b;">{site_name}</p>'
			. '<h1 style="margin:0;font-size:24px;line-height:1.25;color:#111827;">' . esc_html( $headline ) . '</h1>'
			. '</div>'
			. '<div style="padding:28px;">'
			. '<p style="margin:0 0 20px;font-size:16px;line-height:1.6;color:#374151;">' . esc_html( $intro ) . '</p>'
			. '<div style="margin:0 0 22px;padding:20px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;text-align:center;">'
			. '<p style="margin:0 0 8px;font-size:12px;line-height:1.4;color:#64748b;text-transform:uppercase;">Verification code</p>'
			. '<p style="margin:0;font-size:34px;line-height:1.2;font-weight:700;letter-spacing:6px;color:#0f172a;">{code}</p>'
			. '</div>'
			. '<p style="margin:0 0 14px;font-size:14px;line-height:1.6;color:#475569;">This code expires in {ttl_minutes} minutes.</p>'
			. '<p style="margin:0;font-size:14px;line-height:1.6;color:#475569;">' . esc_html( $detail ) . '</p>'
			. '</div>'
			. '</div>'
			. '<p style="margin:16px 0 0;text-align:center;font-size:12px;line-height:1.5;color:#94a3b8;">Sent to {email}</p>'
			. '</div>'
			. '</div>';
	}

	private function options() {
		$options = get_option( self::OPTION_KEY, array() );
		$options = is_array( $options ) ? $options : array();
		$options = wp_parse_args( $options, $this->defaults() );

		return $this->upgrade_legacy_email_defaults( $options );
	}

	private function upgrade_legacy_email_defaults( $options ) {
		$upgraded = $options;

		if ( '[{site_name}] Confirm your email' === ( $upgraded['registration_email_subject'] ?? '' ) ) {
			$upgraded['registration_email_subject'] = '[{site_name}] Your verification code';
		}

		if ( '<p>Your confirmation code is:</p><p><strong>{code}</strong></p><p>This code expires in {ttl_minutes} minutes.</p>' === ( $upgraded['registration_email_body'] ?? '' ) ) {
			$upgraded['registration_email_body'] = $this->default_email_template( 'registration' );
		}

		if ( '[{site_name}] Checkout confirmation code' === ( $upgraded['checkout_email_subject'] ?? '' ) ) {
			$upgraded['checkout_email_subject'] = '[{site_name}] Your checkout verification code';
		}

		if ( '<p>Your checkout confirmation code is:</p><p><strong>{code}</strong></p><p>This code expires in {ttl_minutes} minutes. After confirmation, you will not be asked again for {checkout_valid_hours} hours.</p>' === ( $upgraded['checkout_email_body'] ?? '' ) ) {
			$upgraded['checkout_email_body'] = $this->default_email_template( 'checkout' );
		}

		if ( $upgraded !== $options ) {
			update_option( self::OPTION_KEY, $upgraded );
		}

		return $upgraded;
	}

	private function option( $key ) {
		$options = $this->options();

		return $options[ $key ] ?? null;
	}

	private function enabled( $key ) {
		return (bool) $this->option( $key );
	}

	public function register_settings() {
		register_setting(
			'asw_account_security',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => $this->defaults(),
			)
		);
	}

	public function sanitize_options( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = $this->defaults();
		$output   = $defaults;

		foreach ( array( 'registration_enabled', 'admin_2fa_enabled', 'admin_2fa_force_email', 'admin_2fa_prefer_totp' ) as $key ) {
			$output[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$output['checkout_valid_hours']    = max( 0, min( 168, absint( $input['checkout_valid_hours'] ?? $defaults['checkout_valid_hours'] ) ) );
		$output['code_length']             = max( 6, min( 10, absint( $input['code_length'] ?? $defaults['code_length'] ) ) );
		$output['code_ttl_minutes']        = max( 5, min( 60, absint( $input['code_ttl_minutes'] ?? $defaults['code_ttl_minutes'] ) ) );
		$output['resend_cooldown_seconds'] = max( 15, min( 300, absint( $input['resend_cooldown_seconds'] ?? $defaults['resend_cooldown_seconds'] ) ) );
		$output['max_attempts']            = max( 3, min( 10, absint( $input['max_attempts'] ?? $defaults['max_attempts'] ) ) );

		$posted_roles                = isset( $input['admin_2fa_roles'] ) && is_array( $input['admin_2fa_roles'] ) ? $input['admin_2fa_roles'] : array();
		$output['admin_2fa_roles']   = array_values( array_unique( array_filter( array_map( 'sanitize_key', $posted_roles ) ) ) );
		$posted_providers            = isset( $input['admin_2fa_providers'] ) && is_array( $input['admin_2fa_providers'] ) ? $input['admin_2fa_providers'] : array();
		$valid_providers             = array( 'Two_Factor_Totp', 'Two_Factor_Email', 'Two_Factor_Backup_Codes' );
		$posted_providers            = array_map( 'sanitize_text_field', wp_unslash( $posted_providers ) );
		$output['admin_2fa_providers'] = array_values( array_intersect( $valid_providers, $posted_providers ) );

		foreach ( array( 'registration_email_subject', 'checkout_email_subject' ) as $key ) {
			$output[ $key ] = sanitize_text_field( $input[ $key ] ?? $defaults[ $key ] );
		}

		foreach ( array( 'registration_email_body', 'checkout_email_body' ) as $key ) {
			$output[ $key ] = wp_kses_post( $input[ $key ] ?? $defaults[ $key ] );
		}

		return $output;
	}

	public function add_admin_menu() {
		$callback = array( $this, 'render_settings_page' );

		if ( class_exists( 'WooCommerce' ) ) {
			add_submenu_page(
				'woocommerce',
				__( 'Neat2FA Handler', 'neat-2fa-handler' ),
				__( 'Neat2FA Handler', 'neat-2fa-handler' ),
				'manage_options',
				'neat-2fa-handler',
				$callback
			);
			return;
		}

		add_options_page(
			__( 'Neat2FA Handler', 'neat-2fa-handler' ),
			__( 'Neat2FA Handler', 'neat-2fa-handler' ),
			'manage_options',
			'neat-2fa-handler',
			$callback
		);
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options   = $this->options();
		$roles     = wp_roles()->roles;
		$providers = $this->provider_labels();
		?>
		<div class="wrap asw-admin">
			<h1><?php esc_html_e( 'Neat2FA Handler', 'neat-2fa-handler' ); ?></h1>
			<p><?php esc_html_e( 'Configure registration email confirmation, timed checkout confirmation, and Two Factor enforcement for admin roles.', 'neat-2fa-handler' ); ?></p>

			<?php $this->render_two_factor_status_notice(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'asw_account_security' ); ?>

				<h2><?php esc_html_e( 'Email Confirmation', 'neat-2fa-handler' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Flows', 'neat-2fa-handler' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[registration_enabled]" value="1" <?php checked( $options['registration_enabled'] ); ?>> <?php esc_html_e( 'Require code before account registration', 'neat-2fa-handler' ); ?></label><br>
							<p class="description"><?php esc_html_e( 'Checkout confirmation is always enforced for billing email addresses.', 'neat-2fa-handler' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asw_checkout_valid_hours"><?php esc_html_e( 'Checkout confirmation window', 'neat-2fa-handler' ); ?></label></th>
						<td>
							<input id="asw_checkout_valid_hours" type="number" min="0" max="168" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[checkout_valid_hours]" value="<?php echo esc_attr( $options['checkout_valid_hours'] ); ?>"> <?php esc_html_e( 'hours', 'neat-2fa-handler' ); ?>
							<p class="description"><?php esc_html_e( 'Logged-in customers can reuse checkout confirmation within this window. Use 0 to request a fresh code for every checkout. Guests always need a fresh code.', 'neat-2fa-handler' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Code controls', 'neat-2fa-handler' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Digits', 'neat-2fa-handler' ); ?> <input type="number" min="6" max="10" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[code_length]" value="<?php echo esc_attr( $options['code_length'] ); ?>"></label>
							<label><?php esc_html_e( 'Expiry minutes', 'neat-2fa-handler' ); ?> <input type="number" min="5" max="60" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[code_ttl_minutes]" value="<?php echo esc_attr( $options['code_ttl_minutes'] ); ?>"></label>
							<label><?php esc_html_e( 'Resend cooldown seconds', 'neat-2fa-handler' ); ?> <input type="number" min="15" max="300" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[resend_cooldown_seconds]" value="<?php echo esc_attr( $options['resend_cooldown_seconds'] ); ?>"></label>
							<label><?php esc_html_e( 'Max attempts', 'neat-2fa-handler' ); ?> <input type="number" min="3" max="10" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[max_attempts]" value="<?php echo esc_attr( $options['max_attempts'] ); ?>"></label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Email Templates', 'neat-2fa-handler' ); ?></h2>
				<p class="description"><?php esc_html_e( 'HTML is supported. Placeholders: {site_name}, {email}, {user_login}, {code}, {ttl_minutes}, {checkout_valid_hours}.', 'neat-2fa-handler' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="asw_registration_email_subject"><?php esc_html_e( 'Registration subject', 'neat-2fa-handler' ); ?></label></th>
						<td><input id="asw_registration_email_subject" class="large-text" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[registration_email_subject]" value="<?php echo esc_attr( $options['registration_email_subject'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="asw_registration_email_body"><?php esc_html_e( 'Registration email', 'neat-2fa-handler' ); ?></label></th>
						<td>
							<textarea id="asw_registration_email_body" class="large-text code" rows="16" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[registration_email_body]"><?php echo esc_textarea( $options['registration_email_body'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Shown when a customer confirms their email before account registration.', 'neat-2fa-handler' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asw_checkout_email_subject"><?php esc_html_e( 'Checkout subject', 'neat-2fa-handler' ); ?></label></th>
						<td><input id="asw_checkout_email_subject" class="large-text" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[checkout_email_subject]" value="<?php echo esc_attr( $options['checkout_email_subject'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="asw_checkout_email_body"><?php esc_html_e( 'Checkout email', 'neat-2fa-handler' ); ?></label></th>
						<td>
							<textarea id="asw_checkout_email_body" class="large-text code" rows="16" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[checkout_email_body]"><?php echo esc_textarea( $options['checkout_email_body'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Shown when a customer confirms their billing email during checkout.', 'neat-2fa-handler' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Admin Two-Factor', 'neat-2fa-handler' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enforcement', 'neat-2fa-handler' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[admin_2fa_enabled]" value="1" <?php checked( $options['admin_2fa_enabled'] ); ?>> <?php esc_html_e( 'Require Two Factor for selected roles', 'neat-2fa-handler' ); ?></label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[admin_2fa_force_email]" value="1" <?php checked( $options['admin_2fa_force_email'] ); ?>> <?php esc_html_e( 'Use email code as a secure fallback for protected roles', 'neat-2fa-handler' ); ?></label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[admin_2fa_prefer_totp]" value="1" <?php checked( $options['admin_2fa_prefer_totp'] ); ?>> <?php esc_html_e( 'Prefer authenticator app when configured', 'neat-2fa-handler' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Protected roles', 'neat-2fa-handler' ); ?></th>
						<td>
							<?php foreach ( $roles as $role_key => $role ) : ?>
								<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[admin_2fa_roles][]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, $options['admin_2fa_roles'], true ) ); ?>> <?php echo esc_html( translate_user_role( $role['name'] ) ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Allowed Two Factor methods', 'neat-2fa-handler' ); ?></th>
						<td>
							<?php foreach ( $providers as $provider_key => $provider_label ) : ?>
								<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[admin_2fa_providers][]" value="<?php echo esc_attr( $provider_key ); ?>" <?php checked( in_array( $provider_key, $options['admin_2fa_providers'], true ) ); ?>> <?php echo esc_html( $provider_label ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
				</table>

				<?php $this->render_admin_2fa_status_table(); ?>

				<?php submit_button( __( 'Save security settings', 'neat-2fa-handler' ) ); ?>
			</form>
		</div>
		<?php
	}

	private function provider_labels() {
		$labels = array(
			'Two_Factor_Totp'         => __( 'Authenticator app (TOTP)', 'neat-2fa-handler' ),
			'Two_Factor_Email'        => __( 'Email code', 'neat-2fa-handler' ),
			'Two_Factor_Backup_Codes' => __( 'Backup codes', 'neat-2fa-handler' ),
		);

		if ( class_exists( 'Two_Factor_Core' ) && method_exists( 'Two_Factor_Core', 'get_providers' ) ) {
			foreach ( Two_Factor_Core::get_providers() as $key => $provider ) {
				if ( method_exists( $provider, 'get_label' ) ) {
					$labels[ $key ] = $provider->get_label();
				}
			}
		}

		unset( $labels['Two_Factor_Dummy'] );

		return $labels;
	}

	private function render_admin_2fa_status_table() {
		if ( ! class_exists( 'Two_Factor_Core' ) ) {
			return;
		}

		$roles = (array) $this->option( 'admin_2fa_roles' );
		if ( empty( $roles ) ) {
			return;
		}

		$users = get_users(
			array(
				'role__in' => $roles,
				'number'   => 50,
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);

		$labels = $this->provider_labels();
		?>
		<h3><?php esc_html_e( 'Protected Users', 'neat-2fa-handler' ); ?></h3>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'User', 'neat-2fa-handler' ); ?></th>
					<th><?php esc_html_e( 'Roles', 'neat-2fa-handler' ); ?></th>
					<th><?php esc_html_e( 'Enabled methods', 'neat-2fa-handler' ); ?></th>
					<th><?php esc_html_e( 'Login protection', 'neat-2fa-handler' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $users ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No users found for the selected roles.', 'neat-2fa-handler' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $users as $user ) : ?>
						<?php
						$enabled   = Two_Factor_Core::get_enabled_providers_for_user( $user->ID );
						$available = Two_Factor_Core::get_available_providers_for_user( $user->ID );
						$names     = array();
						foreach ( $enabled as $provider_key ) {
							$names[] = $labels[ $provider_key ] ?? $provider_key;
						}
						$is_protected = is_array( $available ) && ! empty( $available );
						?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php echo esc_html( $user->display_name ); ?></a><br><code><?php echo esc_html( $user->user_email ); ?></code></td>
							<td><?php echo esc_html( implode( ', ', $user->roles ) ); ?></td>
							<td><?php echo esc_html( $names ? implode( ', ', $names ) : __( 'None', 'neat-2fa-handler' ) ); ?></td>
							<td><?php echo esc_html( $is_protected ? __( 'Active', 'neat-2fa-handler' ) : __( 'Needs setup', 'neat-2fa-handler' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	public function plugin_action_links( $links ) {
		$url = admin_url( class_exists( 'WooCommerce' ) ? 'admin.php?page=neat-2fa-handler' : 'options-general.php?page=neat-2fa-handler' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'neat-2fa-handler' ) . '</a>' );

		return $links;
	}

	public function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! class_exists( 'Two_Factor_Core' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Neat2FA Handler is active, but the Two Factor plugin is not active. Registration and checkout email confirmation will still work; admin 2FA controls need Two Factor enabled.', 'neat-2fa-handler' ) . '</p></div>';
		}
	}

	private function render_two_factor_status_notice() {
		if ( class_exists( 'Two_Factor_Core' ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Two Factor integration found. Authenticator app and email-code login protection can be managed here without modifying the Two Factor plugin.', 'neat-2fa-handler' ) . '</p></div>';
			return;
		}

		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Two Factor integration was not found. Activate the Two Factor plugin to enable admin 2FA enforcement.', 'neat-2fa-handler' ) . '</p></div>';
	}

	public function enqueue_frontend_assets() {
		if ( ! $this->should_load_frontend_assets() ) {
			return;
		}

		wp_enqueue_style( 'neat-2fa-handler', plugins_url( 'assets/neat-2fa-handler.css', __FILE__ ), array(), self::VERSION );

		if ( $this->should_load_frontend_script() ) {
			wp_enqueue_script( 'neat-2fa-handler', plugins_url( 'assets/neat-2fa-handler.js', __FILE__ ), array(), self::VERSION, true );
			wp_localize_script(
				'neat-2fa-handler',
				'Neat2FAHandler',
				$this->frontend_script_data()
			);
		}
	}

	private function frontend_script_data() {
		return array_merge(
			$this->frontend_texts(),
			array(
				'checkoutHeader' => 'X-Neat-2FA-Code',
				'recoveryUrl'    => $this->lost_password_url(),
			)
		);
	}

	private function frontend_texts() {
		$defaults = array(
			'checkoutTitle'  => __( 'Confirm email', 'neat-2fa-handler' ),
			'checkoutHelp'   => __( 'Enter the email code.', 'neat-2fa-handler' ),
			'checkoutLabel'  => __( 'Code', 'neat-2fa-handler' ),
			'checkoutVerify' => __( 'Continue', 'neat-2fa-handler' ),
			'checkoutClose'  => __( 'Close', 'neat-2fa-handler' ),
			'recoveryTitle'  => __( 'Recover password', 'neat-2fa-handler' ),
			'recoveryHelp'   => '',
			'recoveryLabel'  => __( 'Recover password', 'neat-2fa-handler' ),
		);

		/**
		 * Lets themes replace the small default front-end strings.
		 *
		 * Expected keys: checkoutTitle, checkoutHelp, checkoutLabel,
		 * checkoutVerify, checkoutClose, recoveryTitle, recoveryHelp,
		 * recoveryLabel.
		 */
		$texts = apply_filters( 'neat_2fa_handler_frontend_texts', $defaults );

		return is_array( $texts ) ? wp_parse_args( $texts, $defaults ) : $defaults;
	}

	private function should_load_frontend_script() {
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		return function_exists( 'is_account_page' ) && is_account_page();
	}

	private function should_load_frontend_assets() {
		if ( $this->is_registration_verification_request() ) {
			return true;
		}

		if ( $this->enabled( 'registration_enabled' ) && function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		global $pagenow;
		return $this->enabled( 'registration_enabled' ) && 'wp-login.php' === $pagenow;
	}

	public function render_registration_password_confirmation() {
		if ( 'yes' === get_option( 'woocommerce_registration_generate_password' ) ) {
			return;
		}
		?>
		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide asw-password-confirm-row">
			<label for="asw-registration-password-confirm"><?php esc_html_e( 'Confirm password', 'neat-2fa-handler' ); ?>&nbsp;<span class="required">*</span></label>
			<input id="asw-registration-password-confirm" class="woocommerce-Input woocommerce-Input--text input-text" type="password" name="<?php echo esc_attr( self::REGISTRATION_PASSWORD_CONFIRM_NAME ); ?>" autocomplete="new-password" required>
		</p>
		<?php
	}

	public function validate_registration_password_confirmation( $errors, $username, $email ) {
		if ( 'yes' === get_option( 'woocommerce_registration_generate_password' ) ) {
			return $errors;
		}

		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		$confirm  = isset( $_POST[ self::REGISTRATION_PASSWORD_CONFIRM_NAME ] ) ? (string) wp_unslash( $_POST[ self::REGISTRATION_PASSWORD_CONFIRM_NAME ] ) : '';

		if ( '' === $password ) {
			return $errors;
		}

		if ( '' === $confirm ) {
			$errors->add( 'asw_password_confirmation_required', __( 'Please confirm your password.', 'neat-2fa-handler' ) );
			return $errors;
		}

		if ( ! hash_equals( $password, $confirm ) ) {
			$errors->add( 'asw_password_confirmation_mismatch', __( 'The password confirmation does not match.', 'neat-2fa-handler' ) );
		}

		return $errors;
	}

	public function render_account_password_recovery_panel() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( ! apply_filters( 'neat_2fa_handler_render_password_recovery_panel', true ) ) {
			return;
		}

		$texts = $this->frontend_texts();
		?>
		<div class="asw-password-recovery-panel">
			<?php if ( '' !== (string) $texts['recoveryTitle'] ) : ?>
				<h3><?php echo esc_html( $texts['recoveryTitle'] ); ?></h3>
			<?php endif; ?>
			<?php if ( '' !== (string) $texts['recoveryHelp'] ) : ?>
				<p><?php echo esc_html( $texts['recoveryHelp'] ); ?></p>
			<?php endif; ?>
			<p><a class="button" href="<?php echo esc_url( $this->lost_password_url() ); ?>"><?php echo esc_html( $texts['recoveryLabel'] ); ?></a></p>
		</div>
		<?php
	}

	public function maybe_block_account_password_change_submission() {
		if ( empty( $_POST['save_account_details'] ) || ! $this->account_password_change_was_submitted() ) {
			return;
		}

		// Block manual POST attempts before WooCommerce can save account password fields.
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $this->password_recovery_required_message(), 'error' );
		}

		wp_safe_redirect( $this->lost_password_url() );
		exit;
	}

	public function block_account_password_changes( $errors, $user ) {
		if ( $this->account_password_change_was_submitted() ) {
			$errors->add( 'asw_password_recovery_required', $this->password_recovery_required_message() );
		}
	}

	private function account_password_change_was_submitted() {
		foreach ( array( 'password_current', 'password_1', 'password_2' ) as $field ) {
			if ( ! empty( $_POST[ $field ] ) ) {
				return true;
			}
		}

		return false;
	}

	private function password_recovery_required_message() {
		return sprintf(
			/* translators: %s: password recovery URL */
			__( 'Use password recovery: %s', 'neat-2fa-handler' ),
			esc_url( $this->lost_password_url() )
		);
	}

	public function maybe_start_registration_verification() {
		if ( empty( $_POST['register'] ) || empty( $_POST['woocommerce-register-nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['woocommerce-register-nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'woocommerce-register' ) ) {
			return;
		}

		$email    = $this->sanitize_email_from_request( $_POST['email'] ?? '' );
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		$username = isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ), true ) : '';

		if ( ! $email || email_exists( $email ) ) {
			return;
		}

		if ( '' === $password ) {
			if ( 'yes' !== get_option( 'woocommerce_registration_generate_password' ) ) {
				return;
			}

			$password = wp_generate_password();
		}

		$errors = apply_filters( 'woocommerce_registration_errors', new WP_Error(), $username, $email );
		if ( is_wp_error( $errors ) && $errors->has_errors() ) {
			return;
		}

		$key = $this->create_pending_registration(
			array(
				'email'     => $email,
				'password'  => $password,
				'username'  => $username,
				'post_data' => $this->registration_post_data(),
			)
		);
		if ( is_wp_error( $key ) ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $key->get_error_message(), 'error' );
			}

			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : $this->account_url() );
			exit;
		}

		$result = $this->send_code( 'registration', $email );
		if ( is_wp_error( $result ) && 'asw_cooldown' !== $result->get_error_code() ) {
			$this->delete_pending_registration( $key );

			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $result->get_error_message(), 'error' );
			}

			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : $this->account_url() );
			exit;
		}

		wp_safe_redirect( $this->registration_verification_url( $key ) );
		exit;
	}

	public function maybe_render_registration_verification_page() {
		if ( ! $this->is_registration_verification_request() ) {
			return;
		}

		$key     = $this->sanitize_pending_key( $_GET['asw_key'] ?? '' );
		$pending = $this->get_pending_registration( $key );
		$error   = null;

		if ( 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$error = $this->process_registration_verification( $key );
			if ( ! is_wp_error( $error ) ) {
				wp_safe_redirect( $this->account_url() );
				exit;
			}
		}

		status_header( $pending ? 200 : 410 );
		get_header();
		?>
		<main class="content-grid">
			<section class="asw-verification-page" aria-labelledby="asw-registration-verification-title">
				<h1 id="asw-registration-verification-title"><?php esc_html_e( 'Confirm your email', 'neat-2fa-handler' ); ?></h1>
				<?php if ( ! $pending ) : ?>
					<p><?php esc_html_e( 'Verification expired.', 'neat-2fa-handler' ); ?></p>
					<p><a class="button" href="<?php echo esc_url( $this->account_url() ); ?>"><?php esc_html_e( 'Back to registration', 'neat-2fa-handler' ); ?></a></p>
				<?php else : ?>
					<p><?php echo esc_html( sprintf( __( 'Code sent to %s.', 'neat-2fa-handler' ), $pending['email'] ) ); ?></p>
					<?php if ( is_wp_error( $error ) ) : ?>
						<div class="woocommerce-error" role="alert"><?php echo esc_html( $error->get_error_message() ); ?></div>
					<?php endif; ?>
					<form method="post">
						<?php wp_nonce_field( 'asw_registration_verify_' . $key, 'asw_registration_verify_nonce' ); ?>
						<p class="form-row form-row-wide">
							<label for="asw-registration-code"><?php esc_html_e( 'Verification code', 'neat-2fa-handler' ); ?></label>
							<input id="asw-registration-code" class="input-text" type="text" inputmode="numeric" pattern="[0-9 ]*" autocomplete="one-time-code" name="<?php echo esc_attr( self::REGISTRATION_CODE_NAME ); ?>" value="" required>
						</p>
						<p><button type="submit" class="button" name="asw_registration_verify" value="1"><?php esc_html_e( 'Continue', 'neat-2fa-handler' ); ?></button></p>
					</form>
				<?php endif; ?>
			</section>
		</main>
		<?php
		get_footer();
		exit;
	}

	private function process_registration_verification( $key ) {
		if ( ! $key || empty( $_POST['asw_registration_verify_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['asw_registration_verify_nonce'] ) ), 'asw_registration_verify_' . $key ) ) {
			return new WP_Error( 'asw_bad_request', __( 'The verification request is no longer valid.', 'neat-2fa-handler' ) );
		}

		$pending = $this->get_pending_registration( $key );
		if ( ! $pending ) {
			return new WP_Error( 'asw_registration_expired', __( 'This verification session expired. Please start registration again.', 'neat-2fa-handler' ) );
		}

		$code   = $this->sanitize_code( $_POST[ self::REGISTRATION_CODE_NAME ] ?? '' );
		$result = $this->validate_code( 'registration', $pending['email'], $code );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$customer_id = $this->create_customer_from_pending_registration( $pending );
		if ( is_wp_error( $customer_id ) ) {
			return $customer_id;
		}

		update_user_meta( $customer_id, self::USER_EMAIL_CONFIRMED, time() );
		$this->delete_pending_registration( $key );

		if ( function_exists( 'wc_set_customer_auth_cookie' ) ) {
			wc_set_customer_auth_cookie( $customer_id );
		}

		return true;
	}

	private function create_customer_from_pending_registration( $pending ) {
		if ( ! function_exists( 'wc_create_new_customer' ) ) {
			return new WP_Error( 'asw_missing_woocommerce', __( 'WooCommerce registration is unavailable.', 'neat-2fa-handler' ) );
		}

		$previous_post = $_POST;
		$_POST = isset( $pending['post_data'] ) && is_array( $pending['post_data'] ) ? $pending['post_data'] : array();
		$_POST['email'] = $pending['email'];
		$_POST['password'] = $pending['password'] ?? '';

		try {
			$customer_id = wc_create_new_customer(
				$pending['email'],
				$pending['username'] ?? '',
				$pending['password'] ?? ''
			);
		} finally {
			$_POST = $previous_post;
		}

		return $customer_id;
	}

	private function registration_post_data() {
		$data = array();

		foreach ( $_POST as $key => $value ) {
			$key = preg_replace( '/[^A-Za-z0-9_\\-]/', '', (string) $key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$data[ $key ] = array_map( 'sanitize_text_field', wp_unslash( $value ) );
				continue;
			}

			$data[ $key ] = sanitize_text_field( wp_unslash( $value ) );
		}

		return $data;
	}

	private function create_pending_registration( $payload ) {
		// Pending registration includes the raw password briefly; never store it unencrypted.
		$encoded = $this->encode_pending_registration( $payload );
		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}

		$key = wp_generate_password( 32, false, false );
		set_transient( $this->pending_registration_key( $key ), $encoded, $this->code_ttl() );

		return $key;
	}

	private function get_pending_registration( $key ) {
		if ( ! $key ) {
			return false;
		}

		$payload = get_transient( $this->pending_registration_key( $key ) );

		return $this->decode_pending_registration( $payload );
	}

	private function delete_pending_registration( $key ) {
		if ( $key ) {
			delete_transient( $this->pending_registration_key( $key ) );
		}
	}

	private function pending_registration_key( $key ) {
		return 'asw_pending_registration_' . hash_hmac( 'sha256', $key, wp_salt( 'nonce' ) );
	}

	private function sanitize_pending_key( $key ) {
		return preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $key ) );
	}

	private function encode_pending_registration( $payload ) {
		$json = wp_json_encode( $payload );
		if ( ! is_string( $json ) || '' === $json ) {
			return new WP_Error( 'asw_registration_storage_failed', __( 'Registration could not be prepared securely. Please try again.', 'neat-2fa-handler' ) );
		}

		if ( function_exists( 'openssl_encrypt' ) && function_exists( 'random_bytes' ) ) {
			try {
				$iv = random_bytes( 16 );
			} catch ( Exception $error ) {
				return new WP_Error( 'asw_registration_storage_failed', __( 'Registration could not be prepared securely. Please try again.', 'neat-2fa-handler' ) );
			}

			$key    = hash( 'sha256', wp_salt( 'secure_auth' ), true );
			$cipher = openssl_encrypt( $json, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			if ( false !== $cipher ) {
				$data = base64_encode( $iv . $cipher );
				return array(
					'v'    => 1,
					'data' => $data,
					'mac'  => hash_hmac( 'sha256', $data, wp_salt( 'auth' ) ),
				);
			}
		}

		return new WP_Error( 'asw_registration_storage_failed', __( 'Registration could not be prepared securely. Please try again.', 'neat-2fa-handler' ) );
	}

	private function decode_pending_registration( $payload ) {
		if ( ! is_array( $payload ) || ! isset( $payload['v'], $payload['data'] ) ) {
			return false;
		}

		if ( 1 !== (int) $payload['v'] || empty( $payload['mac'] ) || ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}

		$data = (string) $payload['data'];
		$mac  = hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );
		if ( ! hash_equals( $mac, (string) $payload['mac'] ) ) {
			return false;
		}

		$raw = base64_decode( $data, true );
		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return false;
		}

		$iv     = substr( $raw, 0, 16 );
		$cipher = substr( $raw, 16 );
		$key    = hash( 'sha256', wp_salt( 'secure_auth' ), true );
		$json   = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		$decoded = json_decode( (string) $json, true );

		return is_array( $decoded ) ? $decoded : false;
	}

	private function is_registration_verification_request() {
		return $this->enabled( 'registration_enabled' ) && isset( $_GET['neat-2fa-register'], $_GET['asw_key'] );
	}

	private function registration_verification_url( $key ) {
		return add_query_arg(
			array(
				'neat-2fa-register' => '1',
				'asw_key'           => $key,
			),
			$this->account_url()
		);
	}

	private function account_url() {
		return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
	}

	private function lost_password_url() {
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			return wc_get_account_endpoint_url( 'lost-password' );
		}

		if ( function_exists( 'wc_lostpassword_url' ) ) {
			return wc_lostpassword_url();
		}

		return wp_lostpassword_url();
	}

	public function render_checkout_fields() {
		static $rendered = false;

		if ( $rendered ) {
			return;
		}

		$rendered = true;
		?>
		<?php // Classic checkout posts this hidden field after the modal collects the code. ?>
		<input id="asw-checkout-code" type="hidden" name="<?php echo esc_attr( self::CHECKOUT_CODE_NAME ); ?>" value="">
		<?php
	}

	private function send_code( $context, $email ) {
		$key          = $this->email_key( $context, $email );
		$cooldown_key = 'asw_code_cooldown_' . $key;

		if ( get_transient( $cooldown_key ) ) {
			return new WP_Error( 'asw_cooldown', __( 'Please wait before requesting another code.', 'neat-2fa-handler' ) );
		}

		$code = $this->generate_code();
		set_transient(
			'asw_email_code_' . $key,
			array(
				'hash'       => wp_hash( $code ),
				'created_at' => time(),
				'attempts'   => 0,
			),
			$this->code_ttl()
		);
		set_transient( $cooldown_key, 1, (int) $this->option( 'resend_cooldown_seconds' ) );

		$subject = $this->parse_template( $this->option( $context . '_email_subject' ), $context, $email, $code );
		$body    = $this->parse_template( $this->option( $context . '_email_body' ), $context, $email, $code );

		add_filter( 'wp_mail_content_type', array( $this, 'mail_content_type' ) );
		$sent = wp_mail( $email, wp_strip_all_tags( $subject ), $body );
		remove_filter( 'wp_mail_content_type', array( $this, 'mail_content_type' ) );

		if ( ! $sent ) {
			delete_transient( 'asw_email_code_' . $key );
			delete_transient( $cooldown_key );
			return new WP_Error( 'asw_mail_failed', __( 'The confirmation email could not be sent.', 'neat-2fa-handler' ) );
		}

		return true;
	}

	public function mail_content_type() {
		return 'text/html';
	}

	private function validate_code( $context, $email, $code ) {
		$key   = $this->email_key( $context, $email );
		$store = get_transient( 'asw_email_code_' . $key );

		if ( ! is_array( $store ) || empty( $store['hash'] ) ) {
			return new WP_Error( 'asw_code_expired', __( 'The code expired. Please request a new code.', 'neat-2fa-handler' ) );
		}

		$attempts = absint( $store['attempts'] ?? 0 ) + 1;
		if ( $attempts > (int) $this->option( 'max_attempts' ) ) {
			delete_transient( 'asw_email_code_' . $key );
			return new WP_Error( 'asw_too_many_attempts', __( 'Too many incorrect attempts. Please request a new code.', 'neat-2fa-handler' ) );
		}

		if ( ! hash_equals( $store['hash'], wp_hash( $code ) ) ) {
			$store['attempts'] = $attempts;
			set_transient( 'asw_email_code_' . $key, $store, max( 1, $this->code_ttl() - ( time() - absint( $store['created_at'] ?? time() ) ) ) );
			return new WP_Error( 'asw_invalid_code', __( 'The verification code could not be verified.', 'neat-2fa-handler' ) );
		}

		delete_transient( 'asw_email_code_' . $key );

		return true;
	}

	private function generate_code() {
		$length = (int) $this->option( 'code_length' );
		if ( class_exists( 'Two_Factor_Provider' ) && method_exists( 'Two_Factor_Provider', 'get_code' ) ) {
			return Two_Factor_Provider::get_code( $length );
		}

		$code = (string) wp_rand( 1, 9 );
		for ( $i = 1; $i < $length; $i++ ) {
			$code .= (string) wp_rand( 0, 9 );
		}

		return $code;
	}

	private function code_ttl() {
		return (int) $this->option( 'code_ttl_minutes' ) * MINUTE_IN_SECONDS;
	}

	private function parse_template( $template, $context, $email, $code ) {
		$user = get_user_by( 'email', $email );

		$replacements = array(
			'{site_name}'            => wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ),
			'{email}'                => $email,
			'{user_login}'           => $user ? $user->user_login : '',
			'{code}'                 => $code,
			'{ttl_minutes}'          => (string) $this->option( 'code_ttl_minutes' ),
			'{checkout_valid_hours}' => (string) $this->option( 'checkout_valid_hours' ),
			'{context}'              => $context,
		);

		return strtr( (string) $template, $replacements );
	}

	public function validate_checkout( $posted, $errors ) {
		$email = $this->sanitize_email_from_request( $posted['billing_email'] ?? '' );
		$result = $this->validate_confirmation_submission( 'checkout', $email );
		if ( is_wp_error( $result ) ) {
			$message = $result->get_error_message();
			if ( function_exists( 'wc_has_notice' ) && wc_has_notice( $message, 'error' ) ) {
				return;
			}

			$errors->add( $result->get_error_code(), $message );
		}
	}

	public function validate_checkout_process() {
		$email = $this->sanitize_email_from_request( $_POST['billing_email'] ?? '' );
		$result = $this->validate_confirmation_submission( 'checkout', $email );
		if ( ! is_wp_error( $result ) ) {
			return;
		}

		$message = $result->get_error_message();
		if ( function_exists( 'wc_has_notice' ) && wc_has_notice( $message, 'error' ) ) {
			return;
		}

		wc_add_notice( $message, 'error' );
	}

	public function validate_store_api_checkout( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		if ( 'POST' !== strtoupper( $request->get_method() ) || ! preg_match( '#^/wc/store/v1/checkout/?$#', $request->get_route() ) ) {
			return $result;
		}

		// WooCommerce Blocks checkout bypasses classic hooks, so block the Store API before order creation.
		$email = $this->store_api_checkout_email( $request );
		if ( ! $email ) {
			return $result;
		}

		$verification = $this->validate_confirmation_submission( 'checkout', $email, $this->store_api_checkout_code( $request ) );
		if ( is_wp_error( $verification ) ) {
			return new WP_Error(
				$verification->get_error_code(),
				$verification->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return $result;
	}

	private function store_api_checkout_email( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$billing = isset( $params['billing_address'] ) && is_array( $params['billing_address'] ) ? $params['billing_address'] : array();

		return $this->sanitize_email_from_request( $billing['email'] ?? ( $params['billing_email'] ?? ( $params['email'] ?? '' ) ) );
	}

	private function store_api_checkout_code( $request ) {
		$code = $request->get_header( 'x-neat-2fa-code' );
		if ( $code ) {
			return $code;
		}

		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		if ( ! empty( $params[ self::CHECKOUT_CODE_NAME ] ) ) {
			return $params[ self::CHECKOUT_CODE_NAME ];
		}

		$extensions = isset( $params['extensions'] ) && is_array( $params['extensions'] ) ? $params['extensions'] : array();
		$extension = isset( $extensions['neat_2fa_handler'] ) && is_array( $extensions['neat_2fa_handler'] ) ? $extensions['neat_2fa_handler'] : array();

		return $extension['checkout_code'] ?? '';
	}

	public function mark_registered_user_confirmed( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return;
		}

		if ( $this->has_recent_confirmation( 'registration', $user->user_email ) ) {
			update_user_meta( $user_id, self::USER_EMAIL_CONFIRMED, time() );
		}
	}

	private function validate_confirmation_submission( $context, $email, $submitted_code = null ) {
		static $results = array();

		if ( ! $context || ! $email ) {
			return true;
		}

		$code      = null === $submitted_code ? $this->code_from_request( $context ) : $this->sanitize_code( $submitted_code );
		$cache_key = $context . '|' . strtolower( $email ) . '|' . $code;
		if ( array_key_exists( $cache_key, $results ) ) {
			return $results[ $cache_key ];
		}

		if ( $this->can_use_recent_confirmation( $context ) && $this->has_recent_confirmation( $context, $email ) ) {
			$results[ $cache_key ] = true;
			return true;
		}

		if ( $code ) {
			$result = $this->validate_code( $context, $email, $code );
			if ( is_wp_error( $result ) ) {
				$results[ $cache_key ] = $result;
				return $result;
			}

			$this->store_recent_confirmation( $context, $email );
			$results[ $cache_key ] = true;
			return true;
		}

		if ( $this->has_pending_code( $context, $email ) ) {
			$results[ $cache_key ] = new WP_Error(
				'asw_confirmation_pending',
				$this->confirmation_pending_message( $context )
			);
			return $results[ $cache_key ];
		}

		$result = $this->send_code( $context, $email );
		if ( is_wp_error( $result ) && 'asw_cooldown' !== $result->get_error_code() ) {
			$results[ $cache_key ] = $result;
			return $result;
		}

		$results[ $cache_key ] = new WP_Error(
			'asw_confirmation_sent',
			$this->confirmation_sent_message( $context )
		);
		return $results[ $cache_key ];
	}

	private function can_use_recent_confirmation( $context ) {
		if ( 'checkout' !== $context ) {
			return true;
		}

		return is_user_logged_in() && (int) $this->option( 'checkout_valid_hours' ) > 0;
	}

	private function store_recent_confirmation( $context, $email ) {
		if ( $this->can_use_recent_confirmation( $context ) ) {
			$ttl = 'checkout' === $context ? (int) $this->option( 'checkout_valid_hours' ) * HOUR_IN_SECONDS : HOUR_IN_SECONDS;
			set_transient( 'asw_verified_' . $this->email_key( $context, $email ), time(), $ttl );
		}

		$user = get_user_by( 'email', $email );
		if ( $user ) {
			update_user_meta( $user->ID, 'checkout' === $context ? self::USER_CHECKOUT_CONFIRMED : self::USER_EMAIL_CONFIRMED, time() );
		}
	}

	private function has_recent_confirmation( $context, $email ) {
		if ( ! $context || ! $email ) {
			return false;
		}

		return (bool) get_transient( 'asw_verified_' . $this->email_key( $context, $email ) );
	}

	private function has_pending_code( $context, $email ) {
		return (bool) get_transient( 'asw_email_code_' . $this->email_key( $context, $email ) );
	}

	private function code_from_request( $context ) {
		$field = 'checkout' === $context ? self::CHECKOUT_CODE_NAME : self::REGISTRATION_CODE_NAME;
		return $this->sanitize_code( $_POST[ $field ] ?? '' );
	}

	private function confirmation_sent_message( $context ) {
		if ( 'checkout' === $context ) {
			return __( 'Email code sent.', 'neat-2fa-handler' );
		}

		return __( 'Email code sent.', 'neat-2fa-handler' );
	}

	private function confirmation_pending_message( $context ) {
		if ( 'checkout' === $context ) {
			return __( 'Enter the email code.', 'neat-2fa-handler' );
		}

		return __( 'Enter the email code.', 'neat-2fa-handler' );
	}

	private function email_key( $context, $email ) {
		return hash_hmac( 'sha256', $context . '|' . strtolower( $email ), wp_salt( 'auth' ) );
	}

	private function sanitize_email_from_request( $email ) {
		$email = sanitize_email( wp_unslash( $email ) );

		return is_email( $email ) ? $email : '';
	}

	private function sanitize_code( $code ) {
		return preg_replace( '/[^0-9]/', '', (string) wp_unslash( $code ) );
	}

	public function filter_two_factor_providers( $providers ) {
		unset( $providers['Two_Factor_Dummy'] );

		if ( ! $this->enabled( 'admin_2fa_enabled' ) ) {
			return $providers;
		}

		$allowed = (array) $this->option( 'admin_2fa_providers' );
		if ( empty( $allowed ) ) {
			return $providers;
		}

		return array_intersect_key( $providers, array_flip( $allowed ) );
	}

	public function enforce_admin_two_factor_providers( $enabled, $user_id ) {
		if ( ! $this->enabled( 'admin_2fa_enabled' ) || ! $this->enabled( 'admin_2fa_force_email' ) || ! $this->user_requires_admin_2fa( $user_id ) ) {
			return $enabled;
		}

		$allowed = (array) $this->option( 'admin_2fa_providers' );
		if ( in_array( 'Two_Factor_Email', $allowed, true ) && ! in_array( 'Two_Factor_Email', $enabled, true ) ) {
			$enabled[] = 'Two_Factor_Email';
		}

		return array_values( array_unique( $enabled ) );
	}

	public function prefer_admin_totp_provider( $provider, $user_id ) {
		if ( ! $this->enabled( 'admin_2fa_enabled' ) || ! $this->enabled( 'admin_2fa_prefer_totp' ) || ! $this->user_requires_admin_2fa( $user_id ) ) {
			return $provider;
		}

		if ( ! class_exists( 'Two_Factor_Core' ) ) {
			return $provider;
		}

		$available = Two_Factor_Core::get_available_providers_for_user( $user_id );
		if ( is_array( $available ) && isset( $available['Two_Factor_Totp'] ) ) {
			return 'Two_Factor_Totp';
		}

		return $provider;
	}

	private function user_requires_admin_2fa( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}

		return (bool) array_intersect( (array) $user->roles, (array) $this->option( 'admin_2fa_roles' ) );
	}
}

ASW_Account_Security::instance();
