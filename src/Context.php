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
 * WP-CLI, cron, XML-RPC or an ability), whether a person started it, the IP
 * address to store, and which AI agent and approver are behind it when
 * Agent Governance runs a request someone approved.
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
	 * The Agent Governance request being run after its approval, if any.
	 *
	 * @var array{agent: string, request: int, approved_by: int}|null
	 */
	private ?array $agent = null;

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

		// Agent Governance runs a held agent call right after someone approves it.
		add_action( 'agent_governance_request_approved', array( $this, 'agent_request_approved' ), 10, 2 );
		add_action( 'agent_governance_request_executed', array( $this, 'agent_request_done' ) );
	}

	/**
	 * Agent Governance approved a held agent request and is about to run it.
	 *
	 * @param mixed $request     The request row: id, agent, identity, via, user_id.
	 * @param mixed $approver_id Who approved it.
	 */
	public function agent_request_approved( $request, $approver_id = 0 ): void {
		$request = is_array( $request ) ? $request : array();
		$label   = (string) ( $request['agent'] ?? '' );
		$agent   = array(
			'agent'       => '' !== $label ? $label : (string) ( $request['identity'] ?? '' ),
			'request'     => (int) ( $request['id'] ?? 0 ),
			'approved_by' => (int) $approver_id,
		);

		/**
		 * Filters what an entry records about the AI agent behind an approved change.
		 *
		 * Return an empty array to record nothing.
		 *
		 * @param array $agent       agent (its name), request (Agent Governance request ID), approved_by (user ID).
		 * @param array $request     The Agent Governance request row.
		 * @param int   $approver_id Who approved it.
		 */
		$agent = (array) apply_filters( 'activity_log_with_undo_agent_context', $agent, $request, (int) $approver_id );

		$this->agent = $agent ? array(
			'agent'       => wp_html_excerpt( sanitize_text_field( (string) ( $agent['agent'] ?? '' ) ), 190, '' ),
			'request'     => max( 0, (int) ( $agent['request'] ?? 0 ) ),
			'approved_by' => max( 0, (int) ( $agent['approved_by'] ?? 0 ) ),
		) : null;
	}

	/**
	 * The approved request finished running.
	 */
	public function agent_request_done(): void {
		$this->agent = null;
	}

	/**
	 * The AI agent and approver behind the change being logged, when Agent Governance runs an approved request.
	 *
	 * @return array{agent: string, request: int, approved_by: int}|null
	 */
	public function agent(): ?array {
		return $this->agent;
	}

	/**
	 * Whether a person started this request: WP-CLI, or a signed-in user in
	 * wp-admin, the REST API, admin-ajax or XML-RPC. Cron jobs and visitors on
	 * the front end are not.
	 *
	 * Settings changed outside such a request are not logged, unless they are
	 * on the always-logged list (`activity_log_with_undo_logged_options`).
	 *
	 * @return bool
	 */
	public function user_initiated(): bool {
		$via = $this->via();
		if ( 'cron' === $via ) {
			$user = false;
		} elseif ( 'cli' === $via ) {
			$user = true;
		} else {
			$api  = ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || wp_doing_ajax() || is_admin();
			$user = $api && get_current_user_id() > 0;
		}

		/**
		 * Filters whether a person started the current request.
		 *
		 * Settings changed in a request nobody started (cron, a visitor on the
		 * front end) are not logged unless they are always logged.
		 *
		 * @param bool   $user Whether a person started it.
		 * @param string $via  How the change is made: admin, rest, app-password, cli, cron, xmlrpc or ability.
		 */
		return (bool) apply_filters( 'activity_log_with_undo_is_user_request', $user, $via );
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
