<?php
/**
 * Tools > Activity Log.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Admin;

use AryanX\Axle\ActivityLogWithUndo\Access;
use AryanX\Axle\ActivityLogWithUndo\Entry;
use AryanX\Axle\ActivityLogWithUndo\Integrations\CategoryTagTrash;
use AryanX\Axle\ActivityLogWithUndo\Logger;
use AryanX\Axle\ActivityLogWithUndo\Payload;
use AryanX\Axle\ActivityLogWithUndo\Recorders\OptionsRecorder;
use AryanX\Axle\ActivityLogWithUndo\Recorders\Registry;
use AryanX\Axle\ActivityLogWithUndo\Retention;
use AryanX\Axle\ActivityLogWithUndo\Rollback;
use AryanX\Axle\ActivityLogWithUndo\Service;
use AryanX\Axle\ActivityLogWithUndo\Settings;
use AryanX\Axle\ActivityLogWithUndo\Store;
use AryanX\Axle\ActivityLogWithUndo\Undoer;
use const AryanX\Axle\ActivityLogWithUndo\PLUGIN_FILE;
use const AryanX\Axle\ActivityLogWithUndo\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * The log, one entry with its differences, and the settings.
 */
final class LogPage implements Service {

	public const SLUG          = 'activity-log-with-undo';
	public const UNDO_ACTION   = 'activity_log_with_undo_undo';
	public const SETTINGS_SAVE = 'activity_log_with_undo_settings';

	public const ROLLBACK_ACTION = 'activity_log_with_undo_rollback';
	public const ROLLBACK_STEP   = 'activity_log_with_undo_rollback_step';
	public const ROLLBACK_CANCEL = 'activity_log_with_undo_rollback_cancel';
	public const DISMISS_STEPS   = 'activity_log_with_undo_dismiss_steps';
	public const STEPS_META      = 'activity_log_with_undo_steps_dismissed';

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
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Deletes old entries.
	 *
	 * @var Retention
	 */
	private Retention $retention;

	/**
	 * Rolls the site back to a moment.
	 *
	 * @var Rollback
	 */
	private Rollback $rollback;

	/**
	 * The Alerts tab.
	 *
	 * @var AlertsScreen|null
	 */
	private ?AlertsScreen $alerts;

	/**
	 * Sets up the screen.
	 *
	 * @param Store             $store     Entries.
	 * @param Registry          $registry  Recorders.
	 * @param Undoer            $undoer    Reverses changes.
	 * @param Settings          $settings  Saved settings.
	 * @param Retention         $retention Deletes old entries.
	 * @param Rollback          $rollback  Rolls the site back to a moment.
	 * @param AlertsScreen|null $alerts The Alerts tab.
	 */
	public function __construct( Store $store, Registry $registry, Undoer $undoer, Settings $settings, Retention $retention, Rollback $rollback, ?AlertsScreen $alerts = null ) {
		$this->store     = $store;
		$this->registry  = $registry;
		$this->undoer    = $undoer;
		$this->settings  = $settings;
		$this->retention = $retention;
		$this->rollback  = $rollback;
		$this->alerts    = $alerts;
	}

	/**
	 * Adds the menu entry and the action handlers.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::UNDO_ACTION, array( $this, 'handle_undo' ) );
		add_action( 'admin_post_' . self::SETTINGS_SAVE, array( $this, 'handle_settings' ) );
		add_action( 'admin_post_' . self::ROLLBACK_ACTION, array( $this, 'handle_rollback_start' ) );
		add_action( 'admin_post_' . self::ROLLBACK_STEP, array( $this, 'handle_rollback_step' ) );
		add_action( 'admin_post_' . self::ROLLBACK_CANCEL, array( $this, 'handle_rollback_cancel' ) );
		add_action( 'admin_post_' . self::DISMISS_STEPS, array( $this, 'handle_dismiss_steps' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds the link to the log in the plugin's row on the Plugins screen.
	 *
	 * @param mixed $links Links.
	 * @return array
	 */
	public function action_links( $links ): array {
		$links = (array) $links;
		if ( current_user_can( self::capability() ) ) {
			array_unshift( $links, sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::url() ), esc_html__( 'Activity log', 'activity-log-with-undo' ) ) );
		}

		return $links;
	}

	/**
	 * Capability needed to see the log and undo changes.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to see the activity log and undo changes.
		 *
		 * @param string $capability Default manage_options.
		 */
		$capability = (string) apply_filters( 'activity_log_with_undo_capability', 'manage_options' );

		return '' !== $capability ? $capability : 'manage_options';
	}

	/**
	 * URL of the screen.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'tools.php?page=' . self::SLUG ) );
	}

	/**
	 * URL that undoes an entry, for links and the admin bar.
	 *
	 * @param int  $id    Entry ID.
	 * @param bool $force Undo even when things changed since.
	 * @param bool $back  Go back to the page the link was on.
	 * @return string
	 */
	public static function undo_url( int $id, bool $force = false, bool $back = false ): string {
		return wp_nonce_url(
			add_query_arg(
				array_filter(
					array(
						'action' => self::UNDO_ACTION,
						'entry'  => $id,
						'force'  => $force ? 1 : 0,
						'back'   => $back ? 1 : 0,
					)
				),
				admin_url( 'admin-post.php' )
			),
			self::UNDO_ACTION . '_' . $id
		);
	}

	/**
	 * An Undo, Redo or Undo anyway button.
	 *
	 * @param Entry    $entry Entry.
	 * @param string[] $also  Descriptions of the other changes undone with it.
	 * @param bool     $force   Undo even when things changed since.
	 * @param bool     $primary Whether this is the one main action of the screen.
	 * @return string HTML.
	 */
	public static function undo_link( Entry $entry, array $also, bool $force, bool $primary = false ): string {
		$rolled = 'rollback' === $entry->event;
		$redo   = null !== $entry->undo_of || $rolled;
		$also   = array_values( array_map( 'strval', $also ) );
		$count  = 1 + count( $also );
		if ( $force ) {
			$label   = __( 'Undo anyway', 'activity-log-with-undo' );
			$verb    = __( 'Undo anyway', 'activity-log-with-undo' );
			$confirm = __( 'This overwrites what changed since, and puts the old version back.', 'activity-log-with-undo' );
			$note    = __( 'The undo is logged, so you can redo it from the log.', 'activity-log-with-undo' );
		} elseif ( $rolled ) {
			$label   = __( 'Redo', 'activity-log-with-undo' );
			$verb    = __( 'Redo the roll back', 'activity-log-with-undo' );
			$confirm = __( 'This puts back every change the roll back undid.', 'activity-log-with-undo' );
			$note    = __( 'It is logged, so you can undo it again from the log.', 'activity-log-with-undo' );
		} elseif ( $redo ) {
			$label   = __( 'Redo', 'activity-log-with-undo' );
			$verb    = __( 'Redo this change', 'activity-log-with-undo' );
			$confirm = __( 'This puts back the change the undo reversed.', 'activity-log-with-undo' );
			$note    = __( 'It is logged, so you can undo it again from the log.', 'activity-log-with-undo' );
		} else {
			$label   = __( 'Undo', 'activity-log-with-undo' );
			$verb    = __( 'Undo this change', 'activity-log-with-undo' );
			$confirm = __( 'This puts the old value back.', 'activity-log-with-undo' );
			$note    = __( 'The undo is logged, so you can redo it from the log.', 'activity-log-with-undo' );
		}

		if ( $also ) {
			$shown = array_slice( $also, 0, 5 );
			$list  = implode( '; ', $shown );
			if ( count( $also ) > count( $shown ) ) {
				/* translators: %d: number of further changes. */
				$list .= '; ' . sprintf( _n( 'and %d more', 'and %d more', count( $also ) - count( $shown ), 'activity-log-with-undo' ), count( $also ) - count( $shown ) );
			}
			/* translators: 1: the question, e.g. "Undo this change?", 2: number of other changes, 3: their descriptions. */
			$confirm = sprintf( _n( '%1$s It also undoes %2$d other change saved with it: %3$s.', '%1$s It also undoes %2$d other changes saved with it: %3$s.', count( $also ), 'activity-log-with-undo' ), $confirm, count( $also ), $list );
		}

		$deletes_user = 'user_created' === $entry->event;
		if ( $deletes_user ) {
			/* translators: %s: user login. */
			$confirm = sprintf( __( 'This deletes the user %s. Their content goes to the person who added them, or to you.', 'activity-log-with-undo' ), $entry->object_label );
			$note    = __( 'A deleted user cannot be brought back with Redo.', 'activity-log-with-undo' );
			$verb    = __( 'Delete this user', 'activity-log-with-undo' );
		}

		/**
		 * Filters the question asked before an undo, in the log and on the entry screen.
		 *
		 * The `activityLogWithUndo.confirmMessage` JavaScript filter sees the result.
		 *
		 * @param string   $confirm Question.
		 * @param Entry    $entry   The entry.
		 * @param string[] $also    Descriptions of the other changes undone with it.
		 * @param bool     $force   Whether this is Undo anyway.
		 */
		$confirm = (string) apply_filters( 'activity_log_with_undo_confirm_message', $confirm, $entry, $also, $force );

		return sprintf(
			'<a href="%1$s" class="button %2$s alu-undo" data-alu-entry="%3$d" data-alu-force="%4$d" data-alu-count="%5$d" data-alu-redo="%6$d" data-alu-deletes-user="%7$d" data-alu-also="%8$s" data-alu-confirm="%9$s" data-alu-note="%10$s" data-alu-verb="%11$s">%12$s</a>',
			esc_url( self::undo_url( $entry->id, $force ) ),
			$force ? 'axle-button--danger alu-undo-anyway' : ( $primary ? 'button-primary' : '' ) . ( $redo ? ' alu-redo' : '' ),
			$entry->id,
			$force ? 1 : 0,
			$count,
			$redo ? 1 : 0,
			$deletes_user ? 1 : 0,
			esc_attr( (string) wp_json_encode( $also ) ),
			esc_attr( $confirm ),
			esc_attr( $note ),
			esc_attr( $verb ),
			esc_html( $label )
		);
	}

	/**
	 * Name of a kind of object for people: "Page", "Category", "Setting".
	 *
	 * @param string $type Object type stored with the entry.
	 * @return string
	 */
	public static function object_type_label( string $type ): string {
		$labels = array(
			'option'         => __( 'Setting', 'activity-log-with-undo' ),
			'network_option' => __( 'Network setting', 'activity-log-with-undo' ),
			'plugin'         => __( 'Plugin', 'activity-log-with-undo' ),
			'theme'          => __( 'Theme', 'activity-log-with-undo' ),
			'user'           => __( 'User', 'activity-log-with-undo' ),
			'menu'           => __( 'Menu', 'activity-log-with-undo' ),
			'site'           => __( 'Site', 'activity-log-with-undo' ),
			'rollback'       => __( 'Roll back', 'activity-log-with-undo' ),
		);

		$label = $labels[ $type ] ?? '';
		if ( '' === $label ) {
			$post_type = get_post_type_object( $type );
			$taxonomy  = $post_type ? null : get_taxonomy( $type );
			if ( $post_type ) {
				$label = (string) $post_type->labels->singular_name;
			} elseif ( $taxonomy ) {
				$label = (string) $taxonomy->labels->singular_name;
			}
		}

		/**
		 * Filters the name of a kind of object on the entry screen.
		 *
		 * @param string $label Name, or '' when unknown.
		 * @param string $type  Object type stored with the entry: a post type, taxonomy, option, plugin, theme, user, menu or site.
		 */
		$label = (string) apply_filters( 'activity_log_with_undo_object_type_label', $label, $type );

		return '' !== $label ? $label : $type;
	}

	/**
	 * Name of the thing that changed, for people.
	 *
	 * @param Entry $entry Entry.
	 * @return string
	 */
	public static function object_name( Entry $entry ): string {
		if ( ! Access::can_view( $entry ) ) {
			return Access::hidden_text();
		}
		if ( in_array( $entry->object_type, array( 'option', 'network_option' ), true ) ) {
			return OptionsRecorder::label_for( $entry->object_id );
		}

		return '' !== $entry->object_label ? $entry->object_label : '#' . $entry->object_id;
	}

	/**
	 * Which agent made a change, and who approved it, for an approved Agent Governance request.
	 *
	 * @param Entry $entry Entry.
	 * @return string Plain text, or '' when no agent was involved.
	 */
	public static function agent_line( Entry $entry ): string {
		if ( ! $entry->by_agent() ) {
			return '';
		}

		$agent    = '' !== $entry->agent ? $entry->agent : __( 'An AI agent', 'activity-log-with-undo' );
		$approver = $entry->approver_name();
		if ( $entry->agent_request && '' !== $approver ) {
			/* translators: 1: agent name, 2: request number, 3: user who approved it. */
			return sprintf( __( '%1$s, request #%2$d, approved by %3$s', 'activity-log-with-undo' ), $agent, $entry->agent_request, $approver );
		}
		if ( '' !== $approver ) {
			/* translators: 1: agent name, 2: user who approved it. */
			return sprintf( __( '%1$s, approved by %2$s', 'activity-log-with-undo' ), $agent, $approver );
		}

		return $agent;
	}

	/**
	 * Badge tone for a source: neutral for people at a screen, info for the rest.
	 *
	 * @param string $via Source.
	 * @return string
	 */
	public static function via_tone( string $via ): string {
		return in_array( $via, array( 'admin', 'xmlrpc' ), true ) ? 'neutral' : 'info';
	}

	/**
	 * Name of a source for people.
	 *
	 * @param string $via Source.
	 * @return string
	 */
	public static function via_label( string $via ): string {
		$labels = array(
			'admin'        => __( 'Dashboard', 'activity-log-with-undo' ),
			'rest'         => __( 'REST API / editor', 'activity-log-with-undo' ),
			'app-password' => __( 'Application password', 'activity-log-with-undo' ),
			'cli'          => __( 'WP-CLI', 'activity-log-with-undo' ),
			'cron'         => __( 'Scheduled task', 'activity-log-with-undo' ),
			'xmlrpc'       => __( 'XML-RPC', 'activity-log-with-undo' ),
			'ability'      => __( 'Ability / AI agent', 'activity-log-with-undo' ),
		);

		return $labels[ $via ] ?? $via;
	}

	/**
	 * Registers Tools > Activity Log.
	 */
	public function add_page(): void {
		$hook = add_management_page(
			__( 'Activity Log', 'activity-log-with-undo' ),
			__( 'Activity Log', 'activity-log-with-undo' ),
			self::capability(),
			self::SLUG,
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( "admin_print_styles-{$hook}", array( $this, 'enqueue_assets' ) );
			add_action( "load-{$hook}", array( $this, 'help' ) );
			add_action( "load-{$hook}", array( $this, 'handle_bulk' ) );
		}
	}

	/**
	 * Styles and the undo script.
	 */
	public function enqueue_assets(): void {
		AxleUi::enqueue();
		wp_enqueue_style( 'activity-log-with-undo-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array( AxleUi::HANDLE ), VERSION );
		wp_enqueue_script( 'activity-log-with-undo-admin', plugins_url( 'assets/admin.js', PLUGIN_FILE ), array( 'wp-hooks', 'wp-api-fetch', 'wp-a11y' ), VERSION, true );
		wp_localize_script(
			'activity-log-with-undo-admin',
			'activityLogWithUndo',
			array(
				'notUndone'     => __( 'The change was not undone. Reload the log to see where things stand, then try again.', 'activity-log-with-undo' ),
				'working'       => __( 'Working', 'activity-log-with-undo' ),
				'keep'          => __( 'Keep things as they are', 'activity-log-with-undo' ),
				'bulkVerb'      => __( 'Undo the selected changes', 'activity-log-with-undo' ),
				/* translators: %d: number of selected changes. */
				'bulkMessage'   => __( 'This undoes %d selected changes, and the other changes saved together with them. The undo is logged, so you can redo it from the log.', 'activity-log-with-undo' ),
				'bulkNone'      => __( 'Select at least one change to undo.', 'activity-log-with-undo' ),
				/* translators: 1: change sets done, 2: change sets in all. */
				'rollbackStep'  => __( 'Undid %1$d of %2$d groups of changes.', 'activity-log-with-undo' ),
				'rollbackError' => __( 'The roll back stopped because the server did not answer. Nothing is lost: press Continue to pick it up where it stopped.', 'activity-log-with-undo' ),
				'rollbackDone'  => __( 'The roll back is finished. Loading the result.', 'activity-log-with-undo' ),
			)
		);
	}

	/**
	 * Help tabs for the current tab of the screen.
	 */
	public function help(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'log';
		$tabs = array(
			array(
				'id'      => 'overview',
				'title'   => __( 'Overview', 'activity-log-with-undo' ),
				'content' => '<p>' . esc_html__( 'This screen logs changes to settings, plugins, themes, users, content, terms, menus and the Site Editor, together with what each one was before. Changes that can be reversed have an Undo button.', 'activity-log-with-undo' ) . '</p>',
			),
		);

		if ( 'rollback' === $tab ) {
			$tabs[] = array(
				'id'      => 'rollback-preview',
				'title'   => __( 'Preview a roll back', 'activity-log-with-undo' ),
				'content' => '<p>' . esc_html__( 'Pick a time. The preview lists every change since then, newest first. "Will be undone" changes are put back. "Cannot be undone" changes stay as they are, with the reason. "Clash" means something not in the list changed the same thing again, so the roll back skips it and says so.', 'activity-log-with-undo' ) . '</p>',
			);
			$tabs[] = array(
				'id'      => 'rollback-run',
				'title'   => __( 'Run and redo a roll back', 'activity-log-with-undo' ),
				'content' => '<p>' . esc_html__( 'A roll back undoes the changes in small groups, newest first, and shows its progress. It is logged as one entry. Use Redo on that entry to put everything back.', 'activity-log-with-undo' ) . '</p>',
			);
		} elseif ( 'alerts' === $tab ) {
			$tabs = array_merge( $tabs, AlertsScreen::help() );
		} elseif ( 'settings' === $tab ) {
			$tabs[] = array(
				'id'      => 'settings',
				'title'   => __( 'Settings', 'activity-log-with-undo' ),
				'content' => '<p>' . esc_html__( 'Choose what is logged, how many days entries are kept, whether IP addresses are stored, and how big a change may be before it is logged without its old values.', 'activity-log-with-undo' ) . '</p>',
			);
			$tabs[] = array(
				'id'      => 'cleanup',
				'title'   => __( 'Delete old entries', 'activity-log-with-undo' ),
				'content' => '<p>' . esc_html__( 'Entries older than the retention setting are deleted once a day. The settings screen says how many that would be before you delete them now. Deleted entries cannot be brought back, and their changes can no longer be undone.', 'activity-log-with-undo' ) . '</p>',
			);
		} else {
			$tabs[] = array(
				'id'      => 'undo',
				'title'   => __( 'Undo a change', 'activity-log-with-undo' ),
				'content' => '<p>' . esc_html__( 'Press Undo on a row. The screen says what will change first. Changes saved in the same request are undone together. If the thing changed again since, you see the differences and choose Undo anyway. Every undo is logged, so you can redo it.', 'activity-log-with-undo' ) . '</p>',
			);
			$tabs[] = array(
				'id'      => 'filter',
				'title'   => __( 'Find a change', 'activity-log-with-undo' ),
				'content' => '<p>' . esc_html__( 'Search by name or filter by event, user, source, dates or object. To undo several changes at once, tick them and use the Undo bulk action.', 'activity-log-with-undo' ) . '</p>',
			);
		}

		AxleUi::help_tabs( $tabs );
	}

	/**
	 * Bulk undo from the list.
	 */
	public function handle_bulk(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Checked below before anything changes.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( 'undo' !== $action ) {
			$action = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
		}
		if ( 'undo' !== $action || empty( $_REQUEST['entry'] ) || ! is_array( $_REQUEST['entry'] ) ) {
			return;
		}
		$ids = array_map( 'absint', wp_unslash( $_REQUEST['entry'] ) );
		// phpcs:enable

		check_admin_referer( 'bulk-' . EntriesTable::PLURAL );
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to undo changes.', 'activity-log-with-undo' ), 403 );
		}

		$sets      = array();
		$undone    = 0;
		$problems  = array();
		$conflicts = array();
		foreach ( $ids as $id ) {
			$entry = $this->store->get( $id );
			if ( ! $entry || isset( $sets[ $entry->change_set ] ) ) {
				continue;
			}
			$sets[ $entry->change_set ] = true;

			$result = $this->undoer->undo( $id );
			if ( $result instanceof \WP_Error ) {
				if ( 'activity_log_with_undo_conflict' === $result->get_error_code() ) {
					$conflicts[] = '#' . $id;
				} else {
					$problems[] = '#' . $id . ': ' . $result->get_error_message();
				}
				continue;
			}
			$undone += count( $result['undone'] );
			foreach ( $result['failed'] as $failed_id => $message ) {
				$problems[] = '#' . $failed_id . ': ' . $message;
			}
		}

		if ( $undone ) {
			/* translators: %d: number of changes. */
			Notices::add( 'success', sprintf( _n( 'Undid %d change.', 'Undid %d changes.', $undone, 'activity-log-with-undo' ), $undone ) );
		}
		if ( $conflicts ) {
			/* translators: %s: entry numbers. */
			Notices::add( 'warning', sprintf( __( 'Changed again since, so not undone: %s. Open each one to compare and use Undo anyway.', 'activity-log-with-undo' ), implode( ', ', $conflicts ) ) );
		}
		if ( $problems ) {
			Notices::add( 'error', implode( ' ', $problems ) );
		}

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Undo from a link or the admin bar.
	 */
	public function handle_undo(): void {
		$id = isset( $_REQUEST['entry'] ) ? absint( $_REQUEST['entry'] ) : 0;
		check_admin_referer( self::UNDO_ACTION . '_' . $id );
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to undo changes.', 'activity-log-with-undo' ), 403 );
		}

		$force  = ! empty( $_REQUEST['force'] );
		$result = $this->undoer->undo( $id, $force );
		$back   = ! empty( $_REQUEST['back'] ) ? wp_get_referer() : false;

		if ( $result instanceof \WP_Error && 'activity_log_with_undo_conflict' === $result->get_error_code() ) {
			wp_safe_redirect( self::url( array( 'entry' => $id ) ) );
			exit;
		}

		self::notice_for( $result );
		wp_safe_redirect( $back ? $back : self::url() );
		exit;
	}

	/**
	 * Adds the notice that reports an undo.
	 *
	 * @param array|\WP_Error $result Undoer::undo() result.
	 */
	public static function notice_for( $result ): void {
		if ( $result instanceof \WP_Error ) {
			Notices::add( 'error', $result->get_error_message() );
			return;
		}

		$count   = count( $result['undone'] );
		$message = $result['forced']
			/* translators: %d: number of changes. */
			? sprintf( _n( 'Undid %d change, overwriting what changed since.', 'Undid %d changes, overwriting what changed since.', $count, 'activity-log-with-undo' ), $count )
			/* translators: %d: number of changes. */
			: sprintf( _n( 'Undid %d change.', 'Undid %d changes.', $count, 'activity-log-with-undo' ), $count );
		$redo = $result['entries'] ? array(
			'label' => __( 'Redo', 'activity-log-with-undo' ),
			'url'   => self::undo_url( (int) $result['entries'][0] ),
		) : null;
		Notices::add( 'success', $message, $redo );

		if ( $result['failed'] ) {
			$lines = array();
			foreach ( $result['failed'] as $id => $reason ) {
				$lines[] = '#' . $id . ': ' . $reason;
			}
			/* translators: %s: entries and reasons. */
			Notices::add( 'warning', sprintf( __( 'Not undone: %s', 'activity-log-with-undo' ), implode( ' ', $lines ) ) );
		}
	}

	/**
	 * Adds the notices that report how a roll back ended.
	 *
	 * @param array $state Rollback::step() result for a roll back that is over.
	 */
	public static function notice_for_rollback( array $state ): void {
		$undone = (int) $state['undone'];
		$link   = $undone && $state['summary'] ? array(
			'label' => __( 'Redo', 'activity-log-with-undo' ),
			'url'   => self::undo_url( (int) $state['summary'] ),
		) : null;
		Notices::add( $undone ? 'success' : 'warning', Rollback::message( $state ), $link );

		if ( $state['skipped'] ) {
			$lines = array();
			foreach ( array_slice( $state['skipped'], 0, 5 ) as $item ) {
				$lines[] = '#' . $item['id'] . ': ' . $item['reason'];
			}
			$more = count( $state['skipped'] ) - count( $lines );
			if ( $more > 0 ) {
				/* translators: %d: number of further changes. */
				$lines[] = sprintf( _n( 'and %d more.', 'and %d more.', $more, 'activity-log-with-undo' ), $more );
			}
			/* translators: 1: number of changes, 2: their numbers and reasons. */
			Notices::add( 'warning', sprintf( _n( '%1$d change was left as it is. %2$s', '%1$d changes were left as they are. %2$s', count( $state['skipped'] ), 'activity-log-with-undo' ), count( $state['skipped'] ), implode( ' ', $lines ) ) );
		}
		if ( $state['deferred'] ) {
			/* translators: %d: number of changes. */
			Notices::add( 'info', sprintf( _n( '%d more change since then was left for the next run. Roll back again to undo it.', '%d more changes since then were left for the next run. Roll back again to undo them.', (int) $state['deferred'], 'activity-log-with-undo' ), (int) $state['deferred'] ) );
		}
	}

	/**
	 * Reads what to roll back to from a request.
	 *
	 * @param array $source $_GET or $_POST, already checked by the caller.
	 * @return array{to: string, user: int|string, area: string, choice: string, time: string, entry: int}
	 */
	private function rollback_request( array $source ): array {
		$presets = $this->rollback->presets();
		$choice  = isset( $source['rb_choice'] ) ? sanitize_key( wp_unslash( $source['rb_choice'] ) ) : '';
		$time    = isset( $source['rb_time'] ) ? sanitize_text_field( wp_unslash( $source['rb_time'] ) ) : '';
		$entry   = isset( $source['rb_entry'] ) ? absint( $source['rb_entry'] ) : 0;
		$user    = isset( $source['rb_user'] ) && '' !== $source['rb_user'] ? absint( $source['rb_user'] ) : '';
		$area    = isset( $source['rb_area'] ) ? sanitize_key( wp_unslash( $source['rb_area'] ) ) : '';

		$to = '';
		if ( isset( $presets[ $choice ] ) ) {
			$to = $choice;
		} elseif ( 'time' === $choice ) {
			$to = $time;
		} elseif ( 'entry' === $choice && $entry ) {
			$to = (string) $entry;
		}

		return array(
			'to'     => $to,
			'user'   => $user,
			'area'   => $area,
			'choice' => $choice,
			'time'   => $time,
			'entry'  => $entry,
		);
	}

	/**
	 * URL of the Roll back tab with the choices kept.
	 *
	 * @param array $request Result of rollback_request().
	 * @param array $extra   More query arguments.
	 * @return string
	 */
	private static function rollback_url( array $request, array $extra = array() ): string {
		return self::url(
			array_filter(
				array(
					'tab'       => 'rollback',
					'rb_choice' => $request['choice'] ?? '',
					'rb_time'   => $request['time'] ?? '',
					'rb_entry'  => $request['entry'] ?? 0,
					'rb_user'   => $request['user'] ?? '',
					'rb_area'   => $request['area'] ?? '',
					'rb_go'     => 1,
				) + $extra,
				static function ( $value ) {
					return '' !== $value && 0 !== $value && null !== $value;
				}
			)
		);
	}

	/**
	 * Starts a roll back from the preview.
	 */
	public function handle_rollback_start(): void {
		check_admin_referer( self::ROLLBACK_ACTION );
		if ( ! Rollback::allowed() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to roll the site back.', 'activity-log-with-undo' ), 403 );
		}

		$request = $this->rollback_request( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
		$plan    = $this->rollback->plan( $request );
		$job     = $plan instanceof \WP_Error ? $plan : $this->rollback->start( $plan, ! empty( $_POST['stop'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.

		if ( $job instanceof \WP_Error ) {
			Notices::add( 'error', $job->get_error_message() );
			wp_safe_redirect( self::rollback_url( $request ) );
			exit;
		}

		wp_safe_redirect( self::url( array( 'tab' => 'rollback' ) ) );
		exit;
	}

	/**
	 * Does the next batch of a roll back, for browsers without JavaScript.
	 */
	public function handle_rollback_step(): void {
		check_admin_referer( self::ROLLBACK_STEP );
		if ( ! Rollback::allowed() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to roll the site back.', 'activity-log-with-undo' ), 403 );
		}

		$state = $this->rollback->step( isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '' );
		if ( $state instanceof \WP_Error ) {
			Notices::add( 'error', $state->get_error_message() );
		} elseif ( 'running' !== $state['state'] ) {
			self::notice_for_rollback( $state );
		}

		wp_safe_redirect( self::url( array( 'tab' => 'rollback' ) ) );
		exit;
	}

	/**
	 * Stops a roll back.
	 */
	public function handle_rollback_cancel(): void {
		check_admin_referer( self::ROLLBACK_CANCEL );
		if ( ! Rollback::allowed() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to roll the site back.', 'activity-log-with-undo' ), 403 );
		}

		$state = $this->rollback->cancel( isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '' );
		if ( $state instanceof \WP_Error ) {
			Notices::add( 'error', $state->get_error_message() );
		} else {
			self::notice_for_rollback( $state );
		}

		wp_safe_redirect( self::url( array( 'tab' => 'rollback' ) ) );
		exit;
	}

	/**
	 * Hides the "Get started" card for the current user.
	 */
	public function handle_dismiss_steps(): void {
		check_admin_referer( self::DISMISS_STEPS );
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'activity-log-with-undo' ), 403 );
		}

		update_user_meta( get_current_user_id(), self::STEPS_META, 1 );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Saves the settings, or deletes old entries now.
	 */
	public function handle_settings(): void {
		check_admin_referer( self::SETTINGS_SAVE );
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change these settings.', 'activity-log-with-undo' ), 403 );
		}

		$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : 'save';
		if ( 'purge' === $task ) {
			$result = $this->retention->purge( $this->settings->retention_days() );
			/* translators: %d: number of entries. */
			Notices::add( 'success', sprintf( _n( 'Deleted %d old entry.', 'Deleted %d old entries.', $result['deleted'], 'activity-log-with-undo' ), $result['deleted'] ) );
			wp_safe_redirect( self::url( array( 'tab' => 'settings' ) ) );
			exit;
		}

		$enabled = isset( $_POST['recorders'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['recorders'] ) ) : array();
		$this->settings->save(
			array(
				'disabled'       => array_values( array_diff( array_keys( $this->registry->configurable() ), $enabled ) ),
				'retention_days' => isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : 90,
				'ip'             => isset( $_POST['ip'] ) ? sanitize_key( wp_unslash( $_POST['ip'] ) ) : 'masked',
				'payload_kb'     => isset( $_POST['payload_kb'] ) ? absint( $_POST['payload_kb'] ) : 256,
				'admin_bar'      => ! empty( $_POST['admin_bar'] ),
			)
		);

		Notices::add( 'success', __( 'Settings saved.', 'activity-log-with-undo' ) );
		wp_safe_redirect( self::url( array( 'tab' => 'settings' ) ) );
		exit;
	}

	/**
	 * Prints the screen.
	 */
	public function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view switches.
		$entry_id = isset( $_GET['entry'] ) && ! is_array( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0;
		$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'log';
		// phpcs:enable
		$tab = in_array( $tab, array( 'rollback', 'alerts', 'settings' ), true ) ? $tab : 'log';
		?>
		<div class="wrap axle-ui alu-page">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Activity Log', 'activity-log-with-undo' ); ?></h1>
			<?php if ( 'log' === $tab && ! $entry_id && Rollback::allowed() ) : ?>
				<a href="<?php echo esc_url( self::url( array( 'tab' => 'rollback' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Roll back to a time', 'activity-log-with-undo' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Activity log sections', 'activity-log-with-undo' ); ?>">
				<a href="<?php echo esc_url( self::url() ); ?>" class="nav-tab <?php echo 'log' === $tab ? 'nav-tab-active' : ''; ?>"<?php echo 'log' === $tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Log', 'activity-log-with-undo' ); ?></a>
				<a href="<?php echo esc_url( self::url( array( 'tab' => 'rollback' ) ) ); ?>" class="nav-tab <?php echo 'rollback' === $tab ? 'nav-tab-active' : ''; ?>"<?php echo 'rollback' === $tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Roll back', 'activity-log-with-undo' ); ?></a>
				<a href="<?php echo esc_url( self::url( array( 'tab' => 'alerts' ) ) ); ?>" class="nav-tab <?php echo 'alerts' === $tab ? 'nav-tab-active' : ''; ?>"<?php echo 'alerts' === $tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Alerts', 'activity-log-with-undo' ); ?></a>
				<a href="<?php echo esc_url( self::url( array( 'tab' => 'settings' ) ) ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"<?php echo 'settings' === $tab ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Settings', 'activity-log-with-undo' ); ?></a>
			</nav>
			<p class="axle-intro"><?php esc_html_e( 'Every change to settings, plugins, themes, users, content, terms, menus and the Site Editor, with what it was before. Undo puts it back, and a roll back puts the whole site back to an earlier time.', 'activity-log-with-undo' ); ?></p>

			<?php
			Notices::render();

			if ( $entry_id ) {
				$this->render_entry( $entry_id );
			} elseif ( 'settings' === $tab ) {
				$this->render_settings();
			} elseif ( 'rollback' === $tab ) {
				$this->render_rollback();
			} elseif ( 'alerts' === $tab && $this->alerts ) {
				$this->alerts->render();
			} else {
				$this->render_log();
			}
			AxleUi::footer();
			?>
		</div>
		<?php
	}

	/**
	 * Prints the list, with the "Get started" card until the plugin has been used.
	 */
	private function render_log(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		$table = new EntriesTable( $this->store, $this->registry, $this->undoer );
		$table->prepare_items();
		$set = EntriesTable::filter( 'alu_set' );

		$job = $this->rollback->job();
		if ( $job ) {
			printf(
				'<div class="notice notice-info inline alu-job-note"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'A roll back is in progress.', 'activity-log-with-undo' ),
				esc_url( self::url( array( 'tab' => 'rollback' ) ) ),
				esc_html__( 'Open it to continue or cancel', 'activity-log-with-undo' )
			);
		}

		$this->render_steps();
		?>
		<?php if ( '' !== $set ) : ?>
			<div class="notice notice-info inline alu-set-note"><p>
				<?php esc_html_e( 'Showing the changes saved together in one request. Undo on any of them undoes them all.', 'activity-log-with-undo' ); ?>
				<a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Show everything', 'activity-log-with-undo' ); ?></a>
			</p></div>
		<?php endif; ?>
		<form method="get" class="alu-log">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<?php if ( '' !== $set ) : ?>
				<input type="hidden" name="alu_set" value="<?php echo esc_attr( $set ); ?>">
			<?php endif; ?>
			<?php $table->toolbar(); ?>
			<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Logged changes', 'activity-log-with-undo' ); ?>">
				<?php $table->display(); ?>
			</div>
		</form>
		<?php
	}

	/**
	 * The "Get started" card: three steps, each done once the log shows it, dismissible per user.
	 */
	private function render_steps(): void {
		if ( get_user_meta( get_current_user_id(), self::STEPS_META, true ) ) {
			return;
		}

		$steps = array(
			array(
				'title' => __( 'Change something', 'activity-log-with-undo' ),
				'text'  => __( 'Edit a setting, a post, a menu or a plugin. It shows up in the list below with what it was before.', 'activity-log-with-undo' ),
				'done'  => $this->store->count() > 0,
				'url'   => admin_url( 'options-general.php' ),
				'label' => __( 'Open the general settings', 'activity-log-with-undo' ),
			),
			array(
				'title' => __( 'Undo it', 'activity-log-with-undo' ),
				'text'  => __( 'Press Undo on a row. The old value comes back and the undo is logged, so you can redo it.', 'activity-log-with-undo' ),
				'done'  => $this->store->find(
					array(
						'event'    => 'undo',
						'per_page' => 1,
					)
				)['total'] > 0,
				'url'   => '#the-list',
				'label' => __( 'Go to the list', 'activity-log-with-undo' ),
			),
			array(
				'title' => __( 'Roll back to a time', 'activity-log-with-undo' ),
				'text'  => __( 'Pick a time, see everything that changed since, and put it all back in one go.', 'activity-log-with-undo' ),
				'done'  => $this->store->find(
					array(
						'event'    => 'rollback',
						'per_page' => 1,
					)
				)['total'] > 0,
				'url'   => self::url( array( 'tab' => 'rollback' ) ),
				'label' => __( 'Preview a roll back', 'activity-log-with-undo' ),
			),
		);

		$open = array_filter(
			$steps,
			static function ( $step ) {
				return ! $step['done'];
			}
		);
		if ( ! $open ) {
			return;
		}
		?>
		<section class="axle-card alu-steps" aria-labelledby="alu-steps-title">
			<div class="axle-card__head">
				<h2 class="axle-card__title" id="alu-steps-title"><?php esc_html_e( 'Get started', 'activity-log-with-undo' ); ?></h2>
				<a class="button-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_STEPS ), self::DISMISS_STEPS ) ); ?>"><?php esc_html_e( 'Hide this', 'activity-log-with-undo' ); ?></a>
			</div>
			<ol class="axle-steps">
				<?php foreach ( $steps as $step ) : ?>
					<li class="axle-step">
						<div class="axle-step__body">
							<strong class="axle-step__title"><?php echo esc_html( $step['title'] ); ?></strong>
							<p><?php echo esc_html( $step['text'] ); ?></p>
						</div>
						<?php if ( $step['done'] ) : ?>
							<span class="axle-badge axle-badge--good"><?php esc_html_e( 'Done', 'activity-log-with-undo' ); ?></span>
						<?php else : ?>
							<a class="button" href="<?php echo esc_url( $step['url'] ); ?>"><?php echo esc_html( $step['label'] ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>
		<?php
	}

	/**
	 * Prints the Roll back tab: the choice of a moment, the preview, and the run.
	 */
	private function render_rollback(): void {
		if ( ! Rollback::allowed() ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'You are not allowed to roll the site back.', 'activity-log-with-undo' ) . '</p></div>';
			return;
		}

		$job = $this->rollback->job();
		if ( $job ) {
			$this->render_rollback_job( $job );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preview, nothing changes.
		$request = $this->rollback_request( $_GET );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preview.
		$go      = ! empty( $_GET['rb_go'] ) && '' !== $request['to'];
		$presets = $this->rollback->presets();
		$choice  = '' !== $request['choice'] ? $request['choice'] : ( $presets ? (string) array_key_first( $presets ) : 'time' );
		?>
		<section class="axle-card">
			<h2 class="axle-card__title"><?php esc_html_e( 'Go back to', 'activity-log-with-undo' ); ?></h2>
			<form method="get" class="alu-rollback-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="hidden" name="tab" value="rollback">
				<input type="hidden" name="rb_go" value="1">
				<fieldset class="alu-choices">
					<legend class="screen-reader-text"><?php esc_html_e( 'Moment to go back to', 'activity-log-with-undo' ); ?></legend>
					<?php foreach ( $presets as $key => $preset ) : ?>
						<label><input type="radio" name="rb_choice" value="<?php echo esc_attr( $key ); ?>" <?php checked( $choice, $key ); ?>> <?php echo esc_html( $preset['label'] ); ?> <span class="axle-muted">(<?php echo AxleUi::relative_time( $preset['time'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in relative_time(). ?>)</span></label><br>
					<?php endforeach; ?>
					<label><input type="radio" name="rb_choice" value="time" <?php checked( $choice, 'time' ); ?>> <?php esc_html_e( 'A time I choose', 'activity-log-with-undo' ); ?></label>
					<label class="screen-reader-text" for="alu-rb-time"><?php esc_html_e( 'Date and time, in the site time zone', 'activity-log-with-undo' ); ?></label>
					<input type="datetime-local" id="alu-rb-time" name="rb_time" value="<?php echo esc_attr( $request['time'] ); ?>"><br>
					<label><input type="radio" name="rb_choice" value="entry" <?php checked( $choice, 'entry' ); ?>> <?php esc_html_e( 'Just before change number', 'activity-log-with-undo' ); ?></label>
					<label class="screen-reader-text" for="alu-rb-entry"><?php esc_html_e( 'Log entry number', 'activity-log-with-undo' ); ?></label>
					<input type="number" min="1" class="small-text" id="alu-rb-entry" name="rb_entry" value="<?php echo $request['entry'] ? esc_attr( (string) $request['entry'] ) : ''; ?>">
				</fieldset>
				<p class="description"><?php esc_html_e( 'Times are in the site time zone. To start from a change, open it in the log and use "Roll back to just before this".', 'activity-log-with-undo' ); ?></p>
				<div class="axle-toolbar">
					<label for="alu-rb-user"><?php esc_html_e( 'Only changes by', 'activity-log-with-undo' ); ?></label>
					<select name="rb_user" id="alu-rb-user">
						<option value=""><?php esc_html_e( 'Everyone', 'activity-log-with-undo' ); ?></option>
						<?php foreach ( $this->store->actors() as $user_id ) : ?>
							<?php $user = get_userdata( $user_id ); ?>
							<option value="<?php echo esc_attr( (string) $user_id ); ?>" <?php selected( (string) $request['user'], (string) $user_id ); ?>><?php echo esc_html( $user ? $user->user_login : '#' . $user_id ); ?></option>
						<?php endforeach; ?>
					</select>
					<label for="alu-rb-area"><?php esc_html_e( 'Only this area', 'activity-log-with-undo' ); ?></label>
					<select name="rb_area" id="alu-rb-area">
						<option value=""><?php esc_html_e( 'Every area', 'activity-log-with-undo' ); ?></option>
						<?php foreach ( $this->registry->configurable() as $id => $recorder ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $request['area'], $id ); ?>><?php echo esc_html( $recorder->label() ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="axle-toolbar__end"><?php submit_button( __( 'Preview the roll back', 'activity-log-with-undo' ), $go ? 'secondary' : 'primary', '', false ); ?></span>
				</div>
			</form>
		</section>
		<?php
		if ( $go ) {
			$this->render_rollback_preview( $request );
		} else {
			?>
			<div class="axle-empty">
				<p><?php esc_html_e( 'Nothing has been undone yet. Pick a time and preview the roll back: you see every change since then, and which of them can be undone, before anything happens.', 'activity-log-with-undo' ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Prints what a roll back would do.
	 *
	 * @param array $request Result of rollback_request().
	 */
	private function render_rollback_preview( array $request ): void {
		$plan = $this->rollback->plan( $request );
		if ( $plan instanceof \WP_Error ) {
			printf( '<div class="notice notice-error inline" role="alert"><p>%s</p></div>', esc_html( $plan->get_error_message() ) );
			return;
		}

		$counts = $plan['counts'];
		if ( ! $plan['items'] ) {
			?>
			<div class="axle-empty">
				<p><?php esc_html_e( 'Nothing changed since then, so there is nothing to roll back. Pick an earlier time, or remove the user and area filters.', 'activity-log-with-undo' ); ?></p>
			</div>
			<?php
			return;
		}
		?>
		<div class="axle-stats alu-stats">
			<div class="axle-stat axle-stat--good"><span class="axle-stat__value"><?php echo esc_html( number_format_i18n( $counts['will'] ) ); ?></span><span class="axle-stat__label"><?php esc_html_e( 'will be undone', 'activity-log-with-undo' ); ?></span></div>
			<div class="axle-stat axle-stat--neutral"><span class="axle-stat__value"><?php echo esc_html( number_format_i18n( $counts['blocked'] ) ); ?></span><span class="axle-stat__label"><?php esc_html_e( 'cannot be undone', 'activity-log-with-undo' ); ?></span></div>
			<div class="axle-stat axle-stat--warning"><span class="axle-stat__value"><?php echo esc_html( number_format_i18n( $counts['conflict'] ) ); ?></span><span class="axle-stat__label"><?php esc_html_e( 'clash with a later change', 'activity-log-with-undo' ); ?></span></div>
		</div>
		<?php if ( $plan['truncated'] ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'There are more changes since then than this preview reads. It shows the newest ones. Pick a later time to see the rest.', 'activity-log-with-undo' ); ?></p></div>
		<?php endif; ?>
		<?php if ( $plan['deferred'] ) : ?>
			<div class="notice notice-info inline"><p>
				<?php
				/* translators: %d: number of changes. */
				echo esc_html( sprintf( _n( 'One run undoes the newest changes first and stops at a safe size. %d change is left for the next run.', 'One run undoes the newest changes first and stops at a safe size. %d changes are left for the next run.', (int) $plan['deferred'], 'activity-log-with-undo' ), (int) $plan['deferred'] ) );
				?>
			</p></div>
		<?php endif; ?>
		<?php
		$groups = array();
		foreach ( $plan['items'] as $item ) {
			$groups[ $item['entry']->recorder ][] = $item;
		}
		foreach ( $groups as $recorder_id => $items ) {
			$recorder = $this->registry->get( $recorder_id );
			?>
			<section class="axle-card alu-area">
				<h2 class="axle-card__title">
					<?php
					/* translators: 1: area name, 2: number of changes. */
					echo esc_html( sprintf( _n( '%1$s: %2$d change', '%1$s: %2$d changes', count( $items ), 'activity-log-with-undo' ), $recorder ? $recorder->label() : $recorder_id, count( $items ) ) );
					?>
				</h2>
				<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php echo esc_attr( $recorder ? $recorder->label() : $recorder_id ); ?>">
					<table class="widefat striped alu-plan">
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Change', 'activity-log-with-undo' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Who', 'activity-log-with-undo' ); ?></th>
							<th scope="col"><?php esc_html_e( 'When', 'activity-log-with-undo' ); ?></th>
							<th scope="col"><?php esc_html_e( 'What a roll back does', 'activity-log-with-undo' ); ?></th>
						</tr></thead>
						<tbody>
							<?php foreach ( $items as $item ) : ?>
								<?php
								$entry = $item['entry'];
								$tones = array(
									'will'     => array( 'good', __( 'Will be undone', 'activity-log-with-undo' ) ),
									'blocked'  => array( 'neutral', __( 'Cannot be undone', 'activity-log-with-undo' ) ),
									'conflict' => array( 'warning', __( 'Clash', 'activity-log-with-undo' ) ),
								);
								?>
								<tr class="alu-plan-<?php echo esc_attr( $item['status'] ); ?>">
									<td><a href="<?php echo esc_url( self::url( array( 'entry' => $entry->id ) ) ); ?>"><?php echo esc_html( $this->registry->describe( $entry ) ); ?></a></td>
									<td><?php echo esc_html( $entry->actor_name() ); ?></td>
									<td><?php echo AxleUi::relative_time( $entry->timestamp() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in relative_time(). ?></td>
									<td>
										<span class="axle-badge axle-badge--<?php echo esc_attr( $tones[ $item['status'] ][0] ); ?>"><?php echo esc_html( $tones[ $item['status'] ][1] ); ?></span>
										<?php if ( '' !== $item['reason'] ) : ?>
											<div class="alu-meta"><?php echo esc_html( $item['reason'] ); ?></div>
										<?php elseif ( ! empty( $item['deferred'] ) ) : ?>
											<div class="alu-meta"><?php esc_html_e( 'Left for the next run.', 'activity-log-with-undo' ); ?></div>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</section>
			<?php
		}

		$attempt = $counts['will'] + $counts['conflict'];
		if ( ! $plan['runs'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'None of these changes can be undone, so there is nothing to run.', 'activity-log-with-undo' ) . '</p></div>';
			return;
		}
		?>
		<section class="axle-card alu-run">
			<h2 class="axle-card__title"><?php esc_html_e( 'Run the roll back', 'activity-log-with-undo' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="alu-rollback-run">
				<?php wp_nonce_field( self::ROLLBACK_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ROLLBACK_ACTION ); ?>">
				<input type="hidden" name="rb_choice" value="<?php echo esc_attr( $request['choice'] ); ?>">
				<input type="hidden" name="rb_time" value="<?php echo esc_attr( $request['time'] ); ?>">
				<input type="hidden" name="rb_entry" value="<?php echo esc_attr( (string) $request['entry'] ); ?>">
				<input type="hidden" name="rb_user" value="<?php echo esc_attr( (string) $request['user'] ); ?>">
				<input type="hidden" name="rb_area" value="<?php echo esc_attr( $request['area'] ); ?>">
				<p><label><input type="checkbox" name="stop" value="1"> <?php esc_html_e( 'Stop at the first change that clashes with a later one', 'activity-log-with-undo' ); ?></label></p>
				<p class="description"><?php esc_html_e( 'Without this, clashing changes are skipped and listed when the roll back ends.', 'activity-log-with-undo' ); ?></p>
				<div class="axle-confirm" role="group" aria-labelledby="alu-run-confirm" data-alu-panel>
					<p id="alu-run-confirm"><strong>
						<?php
						/* translators: %d: number of changes. */
						echo esc_html( sprintf( _n( 'This undoes %d change, newest first.', 'This undoes %d changes, newest first.', $attempt, 'activity-log-with-undo' ), $attempt ) );
						?>
					</strong></p>
					<p><?php esc_html_e( 'Changes that cannot be undone stay as they are. The roll back is logged as one entry, and Redo on that entry puts everything back.', 'activity-log-with-undo' ); ?></p>
					<p class="axle-actions">
						<?php
						/* translators: %d: number of changes. */
						submit_button( sprintf( _n( 'Roll back %d change', 'Roll back %d changes', $attempt, 'activity-log-with-undo' ), $attempt ), 'primary', 'submit', false );
						?>
					</p>
				</div>
			</form>
		</section>
		<?php
	}

	/**
	 * Prints a roll back that is running: progress, Continue and Cancel.
	 *
	 * @param array $job The job.
	 */
	private function render_rollback_job( array $job ): void {
		$total   = count( $job['runs'] );
		$done    = (int) $job['cursor'];
		$mine    = get_current_user_id() === (int) $job['user'];
		$stale   = Rollback::is_stale( $job );
		$owner   = get_userdata( (int) $job['user'] );
		$percent = $total ? (int) floor( $done / $total * 100 ) : 0;
		?>
		<section class="axle-card alu-job" data-alu-job="<?php echo esc_attr( (string) $job['id'] ); ?>" data-alu-auto="<?php echo $mine ? '1' : '0'; ?>">
			<h2 class="axle-card__title"><?php esc_html_e( 'Roll back in progress', 'activity-log-with-undo' ); ?></h2>
			<p>
				<?php
				/* translators: 1: user login, 2: time the roll back started. */
				printf( esc_html__( 'Started by %1$s %2$s.', 'activity-log-with-undo' ), esc_html( $owner ? $owner->user_login : '#' . $job['user'] ), AxleUi::relative_time( (int) $job['created'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in relative_time().
				?>
			</p>
			<div class="axle-progress" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $percent ); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Roll back progress', 'activity-log-with-undo' ); ?>"><span class="axle-progress__bar" style="width:<?php echo esc_attr( (string) $percent ); ?>%"></span></div>
			<p class="alu-job-text">
				<?php
				/* translators: 1: groups of changes done, 2: groups in all. */
				echo esc_html( sprintf( __( 'Undid %1$d of %2$d groups of changes.', 'activity-log-with-undo' ), $done, $total ) );
				?>
				<span class="spinner alu-spinner" aria-hidden="true"></span>
			</p>
			<div class="alu-job-error" role="alert" hidden></div>
			<?php if ( $mine ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="alu-job-continue">
					<?php wp_nonce_field( self::ROLLBACK_STEP ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ROLLBACK_STEP ); ?>">
					<input type="hidden" name="job" value="<?php echo esc_attr( (string) $job['id'] ); ?>">
					<?php submit_button( __( 'Continue', 'activity-log-with-undo' ), 'primary', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Only the person who started it can continue it. You can cancel it.', 'activity-log-with-undo' ); ?></p>
			<?php endif; ?>
			<?php if ( $stale ) : ?>
				<p class="description"><?php esc_html_e( 'Nothing has happened for a while. Continue it, or cancel it.', 'activity-log-with-undo' ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="alu-job-cancel">
				<?php wp_nonce_field( self::ROLLBACK_CANCEL ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ROLLBACK_CANCEL ); ?>">
				<input type="hidden" name="job" value="<?php echo esc_attr( (string) $job['id'] ); ?>">
				<?php submit_button( __( 'Cancel the roll back', 'activity-log-with-undo' ), 'secondary', 'submit', false ); ?>
				<span class="description"><?php esc_html_e( 'What it already undid stays undone. You can redo it from the log.', 'activity-log-with-undo' ); ?></span>
			</form>
		</section>
		<?php
	}

	/**
	 * Prints one entry: details, differences, and Undo.
	 *
	 * @param int $id Entry ID.
	 */
	private function render_entry( int $id ): void {
		$entry = $this->store->get( $id );
		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Back to the log', 'activity-log-with-undo' ) . '</a></p>';
		if ( ! $entry ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'That log entry does not exist. It may have been deleted after the retention period.', 'activity-log-with-undo' ) . '</p></div>';
			return;
		}

		if ( ! Access::can_view( $entry ) ) {
			echo '<div class="notice notice-warning inline alu-hidden"><p>' . esc_html__( 'This change is about something you are not allowed to see or edit, so its details are hidden from you.', 'activity-log-with-undo' ) . '</p></div>';
			return;
		}

		$check   = $this->undoer->check( $entry->id );
		$set     = $this->store->change_set( $entry->change_set );
		$members = $check instanceof \WP_Error ? $this->undoer->members_of( $entry, $set ) : $check['entries'];
		$others  = array_values(
			array_filter(
				$members,
				static function ( Entry $other ) use ( $entry ) {
					return $other->id !== $entry->id;
				}
			)
		);
		$left    = count( $set ) - count( $others ) - 1;
		$events  = $this->registry->events();
		$agent   = self::agent_line( $entry );
		$type    = self::object_type_label( $entry->object_type );
		?>
		<div class="alu-entry" data-alu-entry="<?php echo esc_attr( (string) $entry->id ); ?>">
			<section class="axle-card">
			<h2 class="axle-card__title"><?php echo esc_html( $this->registry->describe( $entry ) ); ?></h2>
			<dl class="axle-facts alu-facts">
				<dt><?php esc_html_e( 'When', 'activity-log-with-undo' ); ?></dt><dd><?php echo AxleUi::relative_time( $entry->timestamp() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in relative_time(). ?></dd>
				<dt><?php esc_html_e( 'Who', 'activity-log-with-undo' ); ?></dt><dd><?php echo esc_html( $entry->actor_name() ); ?> <span class="axle-badge axle-badge--<?php echo esc_attr( self::via_tone( $entry->actor_via ) ); ?> alu-via"><?php echo esc_html( self::via_label( $entry->actor_via ) ); ?></span></dd>
				<?php if ( '' !== $agent ) : ?>
					<dt><?php esc_html_e( 'AI agent', 'activity-log-with-undo' ); ?></dt><dd class="alu-agent"><?php echo esc_html( $agent ); ?></dd>
				<?php endif; ?>
				<?php if ( '' !== $entry->ip ) : ?>
					<dt><?php esc_html_e( 'IP address', 'activity-log-with-undo' ); ?></dt><dd><span dir="ltr"><?php echo esc_html( $entry->ip ); ?></span></dd>
				<?php endif; ?>
				<dt><?php esc_html_e( 'What happened', 'activity-log-with-undo' ); ?></dt><dd><?php echo esc_html( $events[ $entry->event ] ?? $entry->event ); ?></dd>
				<dt><?php esc_html_e( 'What changed', 'activity-log-with-undo' ); ?></dt><dd><?php echo esc_html( $type . ': ' . self::object_name( $entry ) ); ?></dd>
				<?php if ( 'rollback' === $entry->event ) : ?>
					<?php $summary = (array) $entry->after(); ?>
					<dt><?php esc_html_e( 'Rolled back to', 'activity-log-with-undo' ); ?></dt><dd><?php echo AxleUi::relative_time( (int) strtotime( (string) ( $summary['since'] ?? '' ) . ' UTC' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in relative_time(). ?></dd>
				<?php endif; ?>
				<?php if ( $others ) : ?>
					<dt><?php esc_html_e( 'Undone together with', 'activity-log-with-undo' ); ?></dt>
						<dd>
							<ul class="alu-set">
								<?php foreach ( $others as $other ) : ?>
									<li><a href="<?php echo esc_url( self::url( array( 'entry' => $other->id ) ) ); ?>">#<?php echo esc_html( (string) $other->id ); ?></a> <?php echo esc_html( $this->registry->describe( $other ) ); ?></li>
								<?php endforeach; ?>
							</ul>
							<p class="description"><?php esc_html_e( 'These were saved in the same request, so Undo puts all of them back together.', 'activity-log-with-undo' ); ?></p>
						</dd>
				<?php endif; ?>
				<?php if ( $left > 0 ) : ?>
					<dt><?php esc_html_e( 'Saved with', 'activity-log-with-undo' ); ?></dt>
						<dd>
							<a href="<?php echo esc_url( add_query_arg( 'alu_set', $entry->change_set, self::url() ) ); ?>">
								<?php
								/* translators: %d: number of changes. */
								echo esc_html( sprintf( _n( '%d other change from the same request', '%d other changes from the same request', $left, 'activity-log-with-undo' ), $left ) );
								?>
							</a>
							<p class="description"><?php esc_html_e( 'Undo leaves these alone: they cannot be undone, were undone already, or are not settings.', 'activity-log-with-undo' ); ?></p>
						</dd>
				<?php endif; ?>
				<?php if ( $entry->undo_of ) : ?>
					<dt><?php esc_html_e( 'Undo of', 'activity-log-with-undo' ); ?></dt><dd><a href="<?php echo esc_url( self::url( array( 'entry' => $entry->undo_of ) ) ); ?>">#<?php echo esc_html( (string) $entry->undo_of ); ?></a></dd>
				<?php endif; ?>
				<?php if ( $entry->is_undone() ) : ?>
					<dt><?php esc_html_e( 'Undone by', 'activity-log-with-undo' ); ?></dt><dd><a href="<?php echo esc_url( self::url( array( 'entry' => (int) $entry->undone_by ) ) ); ?>">#<?php echo esc_html( (string) $entry->undone_by ); ?></a></dd>
				<?php endif; ?>
				<?php if ( 'term_deleted' === $entry->event && ! empty( ( (array) $entry->before() )['ctt_item'] ) ) : ?>
					<dt><?php esc_html_e( 'Kept in', 'activity-log-with-undo' ); ?></dt><dd><a href="<?php echo esc_url( CategoryTagTrash::url() ); ?>"><?php esc_html_e( 'Category & Tag Trash', 'activity-log-with-undo' ); ?></a></dd>
				<?php endif; ?>
			</dl>
			</section>

			<section class="axle-card">
				<h2 class="axle-card__title"><?php esc_html_e( 'Before and after', 'activity-log-with-undo' ); ?></h2>
				<?php $this->render_states( $entry ); ?>
			</section>
			<?php $this->render_undo( $entry, $check ); ?>

			<section class="axle-card"><details class="alu-tech">
				<summary><?php esc_html_e( 'Technical details', 'activity-log-with-undo' ); ?></summary>
				<table class="widefat striped alu-tech-table">
					<tbody>
						<tr><th scope="row"><?php esc_html_e( 'Entry', 'activity-log-with-undo' ); ?></th><td><code dir="ltr"><?php echo esc_html( (string) $entry->id ); ?></code></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Event', 'activity-log-with-undo' ); ?></th><td><code dir="ltr"><?php echo esc_html( $entry->event ); ?></code></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Recorder', 'activity-log-with-undo' ); ?></th><td><code dir="ltr"><?php echo esc_html( $entry->recorder ); ?></code></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Object type and ID', 'activity-log-with-undo' ); ?></th><td><code dir="ltr"><?php echo esc_html( $entry->object_type . ' ' . $entry->object_id ); ?></code></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Source', 'activity-log-with-undo' ); ?></th><td><code dir="ltr"><?php echo esc_html( $entry->actor_via ); ?></code></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Change set', 'activity-log-with-undo' ); ?></th><td><code dir="ltr"><?php echo esc_html( $entry->change_set ); ?></code></td></tr>
						<?php if ( $entry->agent_request ) : ?>
							<tr><th scope="row"><?php esc_html_e( 'Agent Governance request', 'activity-log-with-undo' ); ?></th><td><code dir="ltr"><?php echo esc_html( (string) $entry->agent_request ); ?></code></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</details></section>
		</div>
		<?php
	}

	/**
	 * Prints a comparison inside a scrollable region, so a wide table does not push the page sideways.
	 *
	 * @param string $html Markup from DiffView::compare().
	 */
	private static function print_diff( string $html ): void {
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Differences', 'activity-log-with-undo' ) . '">' . wp_kses_post( $html ) . '</div>';
	}

	/**
	 * Prints the before and after states.
	 *
	 * @param Entry $entry Entry.
	 */
	private function render_states( Entry $entry ): void {
		$left_out = max( Payload::left_out( $entry->before ), Payload::left_out( $entry->after ) );
		if ( $left_out ) {
			$full    = in_array( Payload::LOG_FULL, array( Payload::left_out_reason( $entry->before ), Payload::left_out_reason( $entry->after ) ), true );
			$message = $full
				/* translators: %s: size limit of the log, e.g. "512 MB". */
				? sprintf( __( 'The log was over its size limit (%s) when this change was made, so it is logged without its values and cannot be undone. Delete old entries on the Settings tab.', 'activity-log-with-undo' ), size_format( Logger::max_log_size() ) )
				/* translators: %s: size, e.g. "300 KB". */
				: sprintf( __( 'This change was too big to keep (%s), so it is logged without its values and cannot be undone. The limit is in the settings.', 'activity-log-with-undo' ), size_format( $left_out ) );
			echo '<div class="notice notice-info inline alu-left-out"><p>' . esc_html( $message ) . '</p></div>';
			return;
		}

		self::print_diff( DiffView::compare( $entry->before(), $entry->after(), __( 'Before', 'activity-log-with-undo' ), __( 'After', 'activity-log-with-undo' ) ) );
	}

	/**
	 * Prints the Undo button, the reason it cannot be undone, or what changed since.
	 *
	 * @param Entry           $entry Entry.
	 * @param array|\WP_Error $check Undoer::check() result.
	 */
	private function render_undo( Entry $entry, $check ): void {
		if ( $check instanceof \WP_Error ) {
			if ( ! $entry->is_undone() ) {
				printf( '<div class="notice notice-info inline alu-not-undoable"><p>%s</p></div>', esc_html( $check->get_error_message() ) );
			}
			return;
		}

		$blocked = $check['blocked'][ $entry->id ] ?? null;
		if ( $blocked ) {
			printf( '<div class="notice notice-info inline alu-not-undoable"><p>%s</p></div>', esc_html( $blocked->get_error_message() ) );
			return;
		}

		$also = array();
		foreach ( $check['entries'] as $other ) {
			if ( $other->id !== $entry->id ) {
				$also[] = $this->registry->describe( $other );
			}
		}

		if ( ! $check['conflicts'] ) {
			echo '<p class="alu-actions">' . self::undo_link( $entry, $also, false, true ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in undo_link().
			return;
		}

		echo '<section class="axle-card axle-card--danger"><h3 class="axle-card__title">' . esc_html__( 'Undo would overwrite later changes', 'activity-log-with-undo' ) . '</h3>';
		echo '<div class="notice notice-warning inline alu-conflict"><p><strong>' . esc_html__( 'Changed since', 'activity-log-with-undo' ) . '</strong> ' . esc_html__( 'This was changed again after this entry. Undo would overwrite these later changes:', 'activity-log-with-undo' ) . '</p></div>';
		foreach ( $check['conflicts'] as $conflict ) {
			$other = (int) $conflict['id'] === $entry->id ? $entry : $this->store->get( (int) $conflict['id'] );
			if ( $other && $other->id !== $entry->id ) {
				echo '<h4>' . esc_html( $this->registry->describe( $other ) ) . '</h4>';
			}
			self::print_diff( DiffView::compare( $conflict['expected'], $conflict['current'], __( 'Right after this change', 'activity-log-with-undo' ), __( 'Now', 'activity-log-with-undo' ) ) );
		}
		echo '<p class="alu-actions">' . self::undo_link( $entry, $also, true ) . '</p></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in undo_link().
	}

	/**
	 * Prints the settings form.
	 */
	private function render_settings(): void {
		$settings = $this->settings->all();
		?>
		<section class="axle-card">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="alu-settings">
			<?php wp_nonce_field( self::SETTINGS_SAVE ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SETTINGS_SAVE ); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Log changes to', 'activity-log-with-undo' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Log changes to', 'activity-log-with-undo' ); ?></legend>
							<?php foreach ( $this->registry->configurable() as $id => $recorder ) : ?>
								<label><input type="checkbox" name="recorders[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( $this->settings->recorder_enabled( $id ) ); ?>> <?php echo esc_html( $recorder->label() ); ?></label><br>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Turning one off stops new entries. Old entries can still be undone.', 'activity-log-with-undo' ); ?></p>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="alu-retention"><?php esc_html_e( 'Keep entries for', 'activity-log-with-undo' ); ?></label></th>
					<td>
						<input type="number" min="1" max="3650" class="small-text" id="alu-retention" name="retention_days" value="<?php echo esc_attr( (string) $settings['retention_days'] ); ?>"> <?php esc_html_e( 'days', 'activity-log-with-undo' ); ?>
						<p class="description"><?php esc_html_e( 'Older entries are deleted once a day.', 'activity-log-with-undo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'IP addresses', 'activity-log-with-undo' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'IP addresses', 'activity-log-with-undo' ); ?></legend>
							<label><input type="radio" name="ip" value="full" <?php checked( $settings['ip'], 'full' ); ?>> <?php esc_html_e( 'Keep the full address', 'activity-log-with-undo' ); ?></label><br>
							<label><input type="radio" name="ip" value="masked" <?php checked( $settings['ip'], 'masked' ); ?>> <?php esc_html_e( 'Keep a shortened address (192.168.1.0)', 'activity-log-with-undo' ); ?></label><br>
							<label><input type="radio" name="ip" value="off" <?php checked( $settings['ip'], 'off' ); ?>> <?php esc_html_e( 'Do not keep IP addresses', 'activity-log-with-undo' ); ?></label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="alu-payload"><?php esc_html_e( 'Largest change kept', 'activity-log-with-undo' ); ?></label></th>
					<td>
						<input type="number" min="1" max="16384" class="small-text" id="alu-payload" name="payload_kb" value="<?php echo esc_attr( (string) $settings['payload_kb'] ); ?>"> <?php esc_html_e( 'KB', 'activity-log-with-undo' ); ?>
						<p class="description"><?php esc_html_e( 'Bigger before or after values are not stored. The change is still logged, but cannot be undone.', 'activity-log-with-undo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Admin bar', 'activity-log-with-undo' ); ?></th>
					<td><label><input type="checkbox" name="admin_bar" value="1" <?php checked( $settings['admin_bar'] ); ?>> <?php esc_html_e( 'Show "Undo last change" for my most recent change', 'activity-log-with-undo' ); ?></label></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'activity-log-with-undo' ) ); ?>
		</form>
		</section>

		<?php
		$days = $this->settings->retention_days();
		$old  = $this->store->count_before( gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) );
		$size = $this->store->size();
		$max  = Logger::max_log_size();
		?>
		<section class="axle-card axle-card--danger">
			<h2 class="axle-card__title"><?php esc_html_e( 'Delete old entries', 'activity-log-with-undo' ); ?></h2>
			<p>
				<?php
				/* translators: 1: number of entries in the log, 2: number of days. */
				echo esc_html( sprintf( __( 'The log holds %1$s entries. Entries older than %2$d days are deleted once a day.', 'activity-log-with-undo' ), number_format_i18n( $this->store->count() ), $days ) );
				if ( $size > 0 ) {
					echo ' ' . esc_html(
						$max > 0
							/* translators: 1: size of the log, 2: its limit. */
							? sprintf( __( 'It takes %1$s of the %2$s it may use.', 'activity-log-with-undo' ), size_format( $size, 1 ), size_format( $max ) )
							/* translators: %s: size of the log. */
							: sprintf( __( 'It takes %s.', 'activity-log-with-undo' ), size_format( $size, 1 ) )
					);
				}
				?>
			</p>
			<?php if ( ! $old ) : ?>
				<p class="description alu-purge-none"><?php esc_html_e( 'No entry is older than that yet, so there is nothing to delete.', 'activity-log-with-undo' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="alu-purge">
					<?php wp_nonce_field( self::SETTINGS_SAVE ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SETTINGS_SAVE ); ?>">
					<input type="hidden" name="task" value="purge">
					<?php
					/* translators: %s: number of entries. */
					$label = sprintf( _n( 'Delete %s old entry', 'Delete %s old entries', $old, 'activity-log-with-undo' ), number_format_i18n( $old ) );
					?>
					<button type="button" class="button axle-button--danger alu-reveal" data-alu-reveal="alu-purge-confirm" aria-expanded="false" aria-controls="alu-purge-confirm" hidden><?php echo esc_html( $label ); ?></button>
					<div class="axle-confirm axle-confirm--danger" id="alu-purge-confirm" role="group" aria-labelledby="alu-purge-title" data-alu-panel data-alu-collapsible>
						<p id="alu-purge-title"><strong>
							<?php
							/* translators: 1: number of entries, 2: number of days. */
							echo esc_html( sprintf( _n( 'This deletes %1$s entry older than %2$d days.', 'This deletes %1$s entries older than %2$d days.', $old, 'activity-log-with-undo' ), number_format_i18n( $old ), $days ) );
							?>
						</strong></p>
						<p><?php esc_html_e( 'It cannot be undone. The changes these entries hold can no longer be undone either.', 'activity-log-with-undo' ); ?></p>
						<p class="axle-actions">
							<?php submit_button( $label, 'secondary axle-button--danger', 'submit', false ); ?>
							<button type="button" class="button alu-cancel" data-alu-cancel hidden><?php esc_html_e( 'Keep them', 'activity-log-with-undo' ); ?></button>
						</p>
					</div>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}
}
