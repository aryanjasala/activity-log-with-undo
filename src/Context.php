<?php
/**
 * Where a change came from.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo;

defined( 'ABSPATH' ) || exit;

/**
 * Works out how a change was made (admin, REST, application password,
 * WP-CLI, cron, XML-RPC or an ability) and the IP address to store.
 */
final class Context implements Service {

	public const VIAS = array( 'admin', 'rest', 'app-password', 'cli', 'cron', 'xmlrpc', 'ability' );

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Abilities running right now (nested calls count).
	 *
	 * @var int
	 */
	private int $abilities = 0;

	/**
	 * Whether this request logged in with an application password.
	 *
	 * @var bool
	 */
	private bool $app_password = false;

	/**
	 * Sets up the context.
	 *
	 * @param Settings $settings Saved settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Watches abilities and application password logins.
	 */
	public function register(): void {
		add_action( 'wp_before_execute_ability', array( $this, 'ability_started' ) );
		add_action( 'wp_after_execute_ability', array( $this, 'ability_ended' ) );
		add_action( 'application_password_did_authenticate', array( $this, 'app_password_used' ) );
	}

	/**
	 * An ability started running.
	 */
	public function ability_started(): void {
		++$this->abilities;
	}

	/**
	 * An ability finished.
	 */
	public function ability_ended(): void {
		$this->abilities = max( 0, $this->abilities - 1 );
	}

	/**
	 * The request authenticated with an application password.
	 */
	public function app_password_used(): void {
		$this->app_password = true;
	}

	/**
	 * How the current change is being made.
	 *
	 * @return string One of VIAS.
	 */
	public function via(): string {
		if ( $this->abilities > 0 ) {
			return 'ability';
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( wp_doing_cron() ) {
			return 'cron';
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return 'xmlrpc';
		}
		if ( $this->app_password ) {
			return 'app-password';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}

		return 'admin';
	}

	/**
	 * The IP address to store with an entry: full, masked or empty, per the setting.
	 *
	 * @return string
	 */
	public function ip(): string {
		$via = $this->via();
		$ip  = 'cli' === $via || 'cron' === $via ? '' : $this->remote_addr();
		$ip  = false === filter_var( $ip, FILTER_VALIDATE_IP ) ? '' : $ip;

		$mode   = (string) $this->settings->get( 'ip' );
		$stored = '';
		if ( '' !== $ip && 'full' === $mode ) {
			$stored = $ip;
		} elseif ( '' !== $ip && 'masked' === $mode ) {
			$stored = wp_privacy_anonymize_ip( $ip );
		}

		/**
		 * Filters the IP address stored with an entry.
		 *
		 * Return '' to store none. Anything that is not an IP address is dropped.
		 *
		 * @param string $stored What would be stored: the full address, the masked one or ''.
		 * @param string $ip     The full address of the request, or '' for WP-CLI and cron.
		 * @param string $mode   The IP logging setting: full, masked or off.
		 */
		$stored = (string) apply_filters( 'activity_log_with_undo_mask_ip', $stored, $ip, $mode );

		return '' !== $stored && false !== filter_var( $stored, FILTER_VALIDATE_IP ) ? $stored : '';
	}

	/**
	 * The address the request came from.
	 *
	 * @return string
	 */
	private function remote_addr(): string {
		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- Only stored, never trusted for access decisions; validated as an IP address by the caller.
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}
}
