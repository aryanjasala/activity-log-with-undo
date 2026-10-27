<?php
/**
 * `wp activity-log-with-undo` commands.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Cli;

use AryanX\Axle\ActivityLogWithUndo\Abilities;
use AryanX\Axle\ActivityLogWithUndo\Cleanup;
use AryanX\Axle\ActivityLogWithUndo\Recorders\Registry;
use AryanX\Axle\ActivityLogWithUndo\Retention;
use AryanX\Axle\ActivityLogWithUndo\Settings;
use AryanX\Axle\ActivityLogWithUndo\Store;
use AryanX\Axle\ActivityLogWithUndo\Undoer;

defined( 'ABSPATH' ) || exit;

/**
 * Lists, shows and undoes logged changes, deletes old entries and changes settings.
 */
final class Command {

	/**
	 * Entries.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Recorders.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Reverses changes.
	 *
	 * @var Undoer
	 */
	private Undoer $undoer;

	/**
	 * Deletes old entries.
	 *
	 * @var Retention
	 */
	private Retention $retention;

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Entry details, shared with the abilities.
	 *
	 * @var Abilities
	 */
	private Abilities $abilities;

	/**
	 * Deletes entries of options that are not logged any more.
	 *
	 * @var Cleanup|null
	 */
	private ?Cleanup $cleanup;

	/**
	 * Sets up the command.
	 *
	 * @param Store     $store     Entries.
	 * @param Registry  $registry  Recorders.
	 * @param Undoer    $undoer    Reverses changes.
	 * @param Retention $retention Deletes old entries.
	 * @param Settings  $settings  Saved settings.
	 * @param Abilities $abilities Entry details.
	 * @param Cleanup   $cleanup   Deletes entries of options that are not logged any more.
	 */
	public function __construct( Store $store, Registry $registry, Undoer $undoer, Retention $retention, Settings $settings, Abilities $abilities, ?Cleanup $cleanup = null ) {
		$this->store     = $store;
		$this->registry  = $registry;
		$this->undoer    = $undoer;
		$this->retention = $retention;
		$this->settings  = $settings;
		$this->abilities = $abilities;
		$this->cleanup   = $cleanup;
	}

	/**
	 * Lists logged changes, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--event=<event>]
	 * : Only this event, e.g. option_updated, plugin_deactivated, post_trashed.
	 *
	 * [--user=<user>]
	 * : Only changes by this user: ID, login or email.
	 *
	 * [--via=<via>]
	 * : Only changes made through admin, rest, app-password, cli, cron, xmlrpc or ability.
	 *
	 * [--object-type=<type>]
	 * : Only this kind of object, e.g. option, plugin, user, post, page, category, menu.
	 *
	 * [--object=<object>]
	 * : Object ID or name, or part of the name.
	 *
	 * [--since=<date>]
	 * : Only changes on or after this date, e.g. 2026-10-01 or "2 days ago".
	 *
	 * [--until=<date>]
	 * : Only changes on or before this date.
	 *
	 * [--search=<text>]
	 * : Search names and events.
	 *
	 * [--undoable]
	 * : Only changes that can still be undone.
	 *
	 * [--per-page=<number>]
	 * : How many. Default 50, at most 500.
	 *
	 * [--page=<number>]
	 * : Page. Default 1.
	 *
	 * [--fields=<fields>]
	 * : Fields to show. Default id,created,user,via,description,undoable,undone_by.
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml, count or ids.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp activity-log-with-undo list --since="1 day ago" --undoable
	 *     wp activity-log-with-undo list --event=option_updated --format=json
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function list_entries( array $args, array $assoc_args ): void {
		$user = '';
		if ( isset( $assoc_args['user'] ) ) {
			$found = is_numeric( $assoc_args['user'] ) ? get_userdata( (int) $assoc_args['user'] ) : ( get_user_by( 'login', (string) $assoc_args['user'] ) ? get_user_by( 'login', (string) $assoc_args['user'] ) : get_user_by( 'email', (string) $assoc_args['user'] ) );
			if ( ! $found ) {
				\WP_CLI::error( 'No such user.' );
			}
			$user = $found->ID;
		}

		$found  = $this->store->find(
			array(
				'event'       => (string) ( $assoc_args['event'] ?? '' ),
				'via'         => (string) ( $assoc_args['via'] ?? '' ),
				'object_type' => (string) ( $assoc_args['object-type'] ?? '' ),
				'object'      => (string) ( $assoc_args['object'] ?? '' ),
				'user'        => $user,
				'since'       => Abilities::date( (string) ( $assoc_args['since'] ?? '' ) ),
				'until'       => Abilities::date( (string) ( $assoc_args['until'] ?? '' ), true ),
				'search'      => (string) ( $assoc_args['search'] ?? '' ),
				'undoable'    => \WP_CLI\Utils\get_flag_value( $assoc_args, 'undoable', false ),
				'per_page'    => (int) ( $assoc_args['per-page'] ?? 50 ),
				'page'        => (int) ( $assoc_args['page'] ?? 1 ),
			)
		);
		$format = (string) ( $assoc_args['format'] ?? 'table' );

		if ( 'count' === $format ) {
			\WP_CLI::line( (string) $found['total'] );
			return;
		}
		if ( 'ids' === $format ) {
			\WP_CLI::line(
				implode(
					' ',
					array_map(
						static function ( $entry ) {
							return $entry->id;
						},
						$found['entries']
					)
				)
			);
			return;
		}

		$rows   = array_map( array( $this->abilities, 'summary' ), $found['entries'] );
		$fields = isset( $assoc_args['fields'] ) ? explode( ',', (string) $assoc_args['fields'] ) : array( 'id', 'created', 'user', 'via', 'description', 'undoable', 'undone_by' );
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Shows one change with its before and after state.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Entry ID.
	 *
	 * [--format=<format>]
	 * : table, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function show( array $args, array $assoc_args ): void {
		$entry = $this->store->get( (int) $args[0] );
		if ( ! $entry ) {
			\WP_CLI::error( 'That log entry does not exist.' );
		}

		$data   = $this->abilities->details( $entry );
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'table' !== $format ) {
			\WP_CLI\Utils\format_items( $format, array( $data ), array_keys( $data ) );
			return;
		}

		$rows = array();
		foreach ( $data as $key => $value ) {
			$rows[] = array(
				'field' => $key,
				'value' => is_scalar( $value ) || null === $value ? (string) wp_json_encode( $value ) : (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
	}

	/**
	 * Undoes changes, each with the rest of its change set.
	 *
	 * Lists the other changes saved together with an entry and asks before
	 * undoing them all. Stops when something changed since the entry was
	 * logged, unless --force.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Entry IDs.
	 *
	 * [--force]
	 * : Undo even when things changed since. That overwrites the later change.
	 *
	 * [--yes]
	 * : Do not ask before undoing the other changes saved together, or deleting a user that an undo would delete.
	 *
	 * ## EXAMPLES
	 *
	 *     wp activity-log-with-undo undo 42
	 *     wp activity-log-with-undo undo 42 --force
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function undo( array $args, array $assoc_args ): void {
		$force  = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$errors = 0;

		foreach ( array_map( 'intval', $args ) as $id ) {
			$check = $this->undoer->check( $id );
			if ( ! $check instanceof \WP_Error ) {
				$others = array_values(
					array_filter(
						$check['entries'],
						static function ( $entry ) use ( $id ) {
							return $entry->id !== $id;
						}
					)
				);
				if ( $others ) {
					\WP_CLI::line( sprintf( '#%d was saved together with %d other change(s), which are undone with it:', $id, count( $others ) ) );
					foreach ( $others as $other ) {
						\WP_CLI::line( sprintf( '  #%d %s', $other->id, $this->registry->describe( $other ) ) );
					}
					\WP_CLI::confirm( 'Undo all of them?', $assoc_args );
				}
				foreach ( $check['entries'] as $entry ) {
					if ( 'user_created' === $entry->event ) {
						\WP_CLI::confirm( sprintf( 'Undoing #%d deletes the user %s. Continue?', $id, $entry->object_label ), $assoc_args );
					}
				}
			}

			$result = $this->undoer->undo( $id, $force );
			if ( $result instanceof \WP_Error ) {
				++$errors;
				\WP_CLI::warning( sprintf( '#%d: %s', $id, $result->get_error_message() ) );
				foreach ( (array) ( $result->get_error_data()['conflicts'] ?? array() ) as $conflict ) {
					\WP_CLI::line( sprintf( '  #%d expected %s, now %s', (int) $conflict['id'], (string) wp_json_encode( $conflict['expected'] ), (string) wp_json_encode( $conflict['current'] ) ) );
				}
				continue;
			}

			foreach ( $result['failed'] as $failed_id => $message ) {
				\WP_CLI::warning( sprintf( '#%d was not undone: %s', $failed_id, $message ) );
			}
			\WP_CLI::success( sprintf( 'Undid #%s. Logged as #%s.', implode( ', #', $result['undone'] ), implode( ', #', $result['entries'] ) ) );
		}

		if ( $errors ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Deletes old entries.
	 *
	 * ## OPTIONS
	 *
	 * [--older-than=<days>]
	 * : Delete entries older than this many days. 0 deletes every entry. Default: the retention setting.
	 *
	 * ## EXAMPLES
	 *
	 *     wp activity-log-with-undo purge --older-than=30
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function purge( array $args, array $assoc_args ): void {
		$days   = isset( $assoc_args['older-than'] ) ? max( 0, (int) $assoc_args['older-than'] ) : $this->settings->retention_days();
		$result = $this->retention->purge( $days, PHP_INT_MAX );

		\WP_CLI::success( sprintf( 'Deleted %d entries older than %s UTC.', $result['deleted'], $result['cutoff'] ) );
	}

	/**
	 * Deletes old entries of options that are not settings.
	 *
	 * Version 1 logged every option, so other plugins' caches, indexes and
	 * counters may fill the log. This lists those options with their number of
	 * entries and deletes the entries, in batches. Settings registered only in
	 * wp-admin are not known to WP-CLI, so check the list before you confirm.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List what would be deleted, and stop.
	 *
	 * [--yes]
	 * : Do not ask first.
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml for the list.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp activity-log-with-undo cleanup --dry-run
	 *     wp activity-log-with-undo cleanup --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function cleanup( array $args, array $assoc_args ): void {
		if ( ! $this->cleanup ) {
			\WP_CLI::error( 'The clean-up is not available.' );
		}

		$found = $this->cleanup->scan();
		if ( ! $found ) {
			Cleanup::finish( 0 );
			\WP_CLI::success( 'Nothing to clean up: every option in the log is a setting.' );
			return;
		}

		$rows = array();
		foreach ( $found as $name => $count ) {
			$rows[] = array(
				'option'  => $name,
				'entries' => $count,
			);
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'option', 'entries' ) );

		$total = array_sum( $found );
		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ) ) {
			\WP_CLI::line( sprintf( '%d entries of %d options would be deleted.', $total, count( $found ) ) );
			return;
		}

		\WP_CLI::confirm( sprintf( 'Delete %d entries of %d options?', $total, count( $found ) ), $assoc_args );
		$result = $this->cleanup->purge( array_keys( $found ), PHP_INT_MAX );
		Cleanup::finish( $result['deleted'] );

		\WP_CLI::success( sprintf( 'Deleted %d entries.', $result['deleted'] ) );
	}

	/**
	 * Shows or changes the settings.
	 *
	 * ## OPTIONS
	 *
	 * [--retention-days=<days>]
	 * : Keep entries this many days.
	 *
	 * [--ip=<mode>]
	 * : IP addresses: full, masked or off.
	 *
	 * [--payload-kb=<kb>]
	 * : Largest before or after state kept, in KB. Bigger changes are logged but cannot be undone.
	 *
	 * [--admin-bar=<on|off>]
	 * : "Undo last change" in the admin bar.
	 *
	 * [--enable=<recorders>]
	 * : Comma-separated recorders to turn on.
	 *
	 * [--disable=<recorders>]
	 * : Comma-separated recorders to turn off.
	 *
	 * [--format=<format>]
	 * : table, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp activity-log-with-undo settings --retention-days=30 --ip=off
	 *     wp activity-log-with-undo settings --disable=menus,site_editor
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function settings( array $args, array $assoc_args ): void {
		$new = array();
		if ( isset( $assoc_args['retention-days'] ) ) {
			$new['retention_days'] = (int) $assoc_args['retention-days'];
		}
		if ( isset( $assoc_args['ip'] ) ) {
			if ( ! in_array( $assoc_args['ip'], Settings::IP_MODES, true ) ) {
				\WP_CLI::error( '--ip must be full, masked or off.' );
			}
			$new['ip'] = (string) $assoc_args['ip'];
		}
		if ( isset( $assoc_args['payload-kb'] ) ) {
			$new['payload_kb'] = (int) $assoc_args['payload-kb'];
		}
		if ( isset( $assoc_args['admin-bar'] ) ) {
			$new['admin_bar'] = in_array( strtolower( (string) $assoc_args['admin-bar'] ), array( 'on', '1', 'yes', 'true' ), true );
		}
		if ( isset( $assoc_args['enable'] ) || isset( $assoc_args['disable'] ) ) {
			$known    = array_keys( $this->registry->all() );
			$enable   = array_filter( array_map( 'trim', explode( ',', (string) ( $assoc_args['enable'] ?? '' ) ) ) );
			$disable  = array_filter( array_map( 'trim', explode( ',', (string) ( $assoc_args['disable'] ?? '' ) ) ) );
			$unknown  = array_diff( array_merge( $enable, $disable ), $known );
			$disabled = array_diff( array_merge( (array) $this->settings->get( 'disabled' ), $disable ), $enable );
			if ( $unknown ) {
				\WP_CLI::error( sprintf( 'Unknown recorder: %s. Recorders: %s.', implode( ', ', $unknown ), implode( ', ', $known ) ) );
			}
			$new['disabled'] = array_values( array_unique( $disabled ) );
		}

		$settings = $new ? $this->settings->save( $new ) : $this->settings->all();
		if ( $new ) {
			\WP_CLI::success( 'Settings saved.' );
		}

		$row = array(
			'recorders_on'   => implode( ',', array_diff( array_keys( $this->registry->all() ), (array) $settings['disabled'] ) ),
			'recorders_off'  => implode( ',', (array) $settings['disabled'] ),
			'retention_days' => $settings['retention_days'],
			'ip'             => $settings['ip'],
			'payload_kb'     => $settings['payload_kb'],
			'admin_bar'      => $settings['admin_bar'] ? 'on' : 'off',
		);
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), array( $row ), array_keys( $row ) );
	}
}
