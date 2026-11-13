<?php
/**
 * `wp activity-log-with-undo alerts` commands.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Cli;

use AryanX\Axle\ActivityLogWithUndo\Alerts\Alerts;
use AryanX\Axle\ActivityLogWithUndo\Alerts\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Manages alert rules. A webhook URL is read from the command line when a rule is made or changed,
 * and no command prints one back.
 */
final class AlertsCommand {

	/**
	 * Rules.
	 *
	 * @var Rules
	 */
	private Rules $rules;

	/**
	 * Sends alerts.
	 *
	 * @var Alerts
	 */
	private Alerts $alerts;

	/**
	 * Sets up the command.
	 *
	 * @param Rules  $rules    Rules.
	 * @param Alerts $alerts   Sends alerts.
	 */
	public function __construct( Rules $rules, Alerts $alerts ) {
		$this->rules  = $rules;
		$this->alerts = $alerts;
	}

	/**
	 * Lists alert rules.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml, count or ids.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp activity-log-with-undo alerts list
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function list_rules( array $args, array $assoc_args ): void {
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$rows   = array();
		foreach ( $this->rules->all() as $rule ) {
			$rows[] = array(
				'id'      => $rule['id'],
				'name'    => $rule['name'],
				'enabled' => $rule['enabled'] ? 'yes' : 'no',
				'events'  => $rule['events'] ? implode( ',', $rule['events'] ) : 'any',
				'mode'    => $rule['mode'],
				'email'   => $rule['email'] ? 'yes' : 'no',
				'webhook' => $rule['webhook'] && $this->rules->has_webhook( $rule['id'] ) ? $this->rules->webhook_host( $rule['id'] ) : 'no',
			);
		}

		if ( 'count' === $format ) {
			\WP_CLI::line( (string) count( $rows ) );
			return;
		}
		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', wp_list_pluck( $rows, 'id' ) ) );
			return;
		}
		\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'name', 'enabled', 'events', 'mode', 'email', 'webhook' ) );
	}

	/**
	 * Adds an alert rule, off unless you pass --enable.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Name of the rule.
	 *
	 * [--starter=<id>]
	 * : Start from a starter rule, such as new-administrator.
	 *
	 * [--events=<events>]
	 * : Comma-separated events, such as plugin_deactivated,plugin_deleted. Empty means any.
	 *
	 * [--areas=<areas>]
	 * : Comma-separated areas (recorder IDs), such as plugins,users.
	 *
	 * [--objects=<objects>]
	 * : Comma-separated object IDs, such as siteurl,home.
	 *
	 * [--users=<ids>]
	 * : Comma-separated user IDs whose changes fire the rule.
	 *
	 * [--roles=<roles>]
	 * : Comma-separated roles whose changes fire the rule.
	 *
	 * [--outside-hours]
	 * : Only fire outside working hours.
	 *
	 * [--emails=<emails>]
	 * : Comma-separated addresses. Empty means the site admin email.
	 *
	 * [--skip-email]
	 * : Do not send email. Needs --webhook.
	 *
	 * [--webhook=<url>]
	 * : A webhook URL. It is saved as a secret and never shown again.
	 *
	 * [--format=<format>]
	 * : Webhook format, json or slack.
	 * ---
	 * default: json
	 * ---
	 *
	 * [--mode=<mode>]
	 * : instant or daily.
	 * ---
	 * default: instant
	 * ---
	 *
	 * [--enable]
	 * : Turn the rule on.
	 *
	 * [--porcelain]
	 * : Print only the new rule's ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp activity-log-with-undo alerts add "Plugin removed" --events=plugin_deactivated,plugin_deleted --enable
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function add( array $args, array $assoc_args ): void {
		$fields   = array( 'name' => (string) $args[0] );
		$starters = Rules::starters();
		if ( isset( $assoc_args['starter'] ) ) {
			$starter = (string) $assoc_args['starter'];
			if ( ! isset( $starters[ $starter ] ) ) {
				\WP_CLI::error( 'Unknown starter rule. Use one of: ' . implode( ', ', array_keys( $starters ) ) . '.' );
			}
			$fields = $fields + array_diff_key( $starters[ $starter ], array( 'id' => 1 ) );
		}
		$map = array(
			'events'  => 'events',
			'areas'   => 'areas',
			'objects' => 'objects',
			'users'   => 'users',
			'roles'   => 'roles',
			'emails'  => 'emails',
			'format'  => 'format',
			'mode'    => 'mode',
		);
		foreach ( $map as $flag => $field ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$fields[ $field ] = (string) $assoc_args[ $flag ];
			}
		}
		$webhook                 = isset( $assoc_args['webhook'] ) ? (string) $assoc_args['webhook'] : null;
		$fields['webhook']       = null !== $webhook;
		$fields['email']         = ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-email', false );
		$fields['outside_hours'] = \WP_CLI\Utils\get_flag_value( $assoc_args, 'outside-hours', false ) || ! empty( $fields['outside_hours'] );
		$fields['enabled']       = \WP_CLI\Utils\get_flag_value( $assoc_args, 'enable', false );
		$fields['owner']         = get_current_user_id();

		$saved = $this->rules->save( $fields, $webhook );
		if ( is_wp_error( $saved ) ) {
			\WP_CLI::error( $saved->get_error_message() );
		}
		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
			\WP_CLI::line( $saved['id'] );
			return;
		}
		\WP_CLI::success( sprintf( 'Added the rule "%s" (%s), %s.', $saved['name'], $saved['id'], $saved['enabled'] ? 'on' : 'off' ) );
	}

	/**
	 * Turns a rule on.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Rule ID.
	 *
	 * @param array $args Positional arguments.
	 */
	public function enable( array $args ): void {
		$this->toggle( (string) $args[0], true );
	}

	/**
	 * Turns a rule off. Nothing is deleted.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Rule ID.
	 *
	 * @param array $args Positional arguments.
	 */
	public function disable( array $args ): void {
		$this->toggle( (string) $args[0], false );
	}

	/**
	 * Turns a rule on or off.
	 *
	 * @param string $id Rule ID.
	 * @param bool   $on On or off.
	 */
	private function toggle( string $id, bool $on ): void {
		$rule = $this->rules->set_enabled( sanitize_key( $id ), $on );
		if ( is_wp_error( $rule ) ) {
			\WP_CLI::error( $rule->get_error_message() );
		}
		\WP_CLI::success( sprintf( 'The rule "%s" is %s.', $rule['name'], $on ? 'on' : 'off' ) );
	}

	/**
	 * Deletes a rule and its saved webhook URL. It cannot be undone.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Rule ID.
	 *
	 * [--yes]
	 * : Do not ask.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function delete( array $args, array $assoc_args ): void {
		$id = sanitize_key( (string) $args[0] );
		if ( ! $this->rules->get( $id ) ) {
			\WP_CLI::error( 'That alert rule does not exist.' );
		}
		\WP_CLI::confirm( 'Delete this rule and its saved webhook URL? This cannot be undone.', $assoc_args );
		$this->rules->delete( $id );
		\WP_CLI::success( 'Deleted the rule.' );
	}

	/**
	 * Sends a test message through every channel of a rule.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Rule ID.
	 *
	 * @param array $args Positional arguments.
	 */
	public function test( array $args ): void {
		$results = $this->alerts->test( sanitize_key( (string) $args[0] ) );
		if ( is_wp_error( $results ) ) {
			\WP_CLI::error( $results->get_error_message() );
		}
		$failed = 0;
		foreach ( $results as $result ) {
			if ( $result['ok'] ) {
				\WP_CLI::log( sprintf( 'Sent to %s (%s).', $result['target'], $result['channel'] ) );
			} else {
				++$failed;
				\WP_CLI::warning( sprintf( '%s (%s): %s', $result['target'], $result['channel'], $result['error'] ) );
			}
		}
		if ( $failed ) {
			\WP_CLI::error( 'The test did not go through everywhere.' );
		}
		\WP_CLI::success( 'The test went through.' );
	}

	/**
	 * Sends the daily digests now.
	 */
	public function digest(): void {
		\WP_CLI::success( sprintf( 'Sent %d digest(s).', $this->alerts->send_digests() ) );
	}
}
