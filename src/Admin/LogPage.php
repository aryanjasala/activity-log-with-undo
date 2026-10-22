<?php
/**
 * Tools > Activity Log.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo\Admin;

use AryanX\ActivityLogWithUndo\Entry;
use AryanX\ActivityLogWithUndo\Integrations\CategoryTagTrash;
use AryanX\ActivityLogWithUndo\Payload;
use AryanX\ActivityLogWithUndo\Recorders\Registry;
use AryanX\ActivityLogWithUndo\Retention;
use AryanX\ActivityLogWithUndo\Service;
use AryanX\ActivityLogWithUndo\Settings;
use AryanX\ActivityLogWithUndo\Store;
use AryanX\ActivityLogWithUndo\Undoer;
use const AryanX\ActivityLogWithUndo\PLUGIN_FILE;
use const AryanX\ActivityLogWithUndo\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * The log, one entry with its differences, and the settings.
 */
final class LogPage implements Service {

	public const SLUG          = 'activity-log-with-undo';
	public const UNDO_ACTION   = 'activity_log_with_undo_undo';
	public const SETTINGS_SAVE = 'activity_log_with_undo_settings';

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
	 * Sets up the screen.
	 *
	 * @param Store     $store     Entries.
	 * @param Registry  $registry  Recorders.
	 * @param Undoer    $undoer    Reverses changes.
	 * @param Settings  $settings  Saved settings.
	 * @param Retention $retention Deletes old entries.
	 */
	public function __construct( Store $store, Registry $registry, Undoer $undoer, Settings $settings, Retention $retention ) {
		$this->store     = $store;
		$this->registry  = $registry;
		$this->undoer    = $undoer;
		$this->settings  = $settings;
		$this->retention = $retention;
	}

	/**
	 * Adds the menu entry and the action handlers.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::UNDO_ACTION, array( $this, 'handle_undo' ) );
		add_action( 'admin_post_' . self::SETTINGS_SAVE, array( $this, 'handle_settings' ) );
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
	 * @param Entry $entry Entry.
	 * @param int   $count Changes in its change set.
	 * @param bool  $force Undo even when things changed since.
	 * @return string HTML.
	 */
	public static function undo_link( Entry $entry, int $count, bool $force ): string {
		$redo = null !== $entry->undo_of;
		if ( $force ) {
			$label   = __( 'Undo anyway', 'activity-log-with-undo' );
			$confirm = __( 'Undo anyway? This also overwrites what changed since.', 'activity-log-with-undo' );
		} elseif ( $redo ) {
			$label   = __( 'Redo', 'activity-log-with-undo' );
			$confirm = __( 'Redo the change this undo reversed?', 'activity-log-with-undo' );
		} else {
			$label = __( 'Undo', 'activity-log-with-undo' );
			/* translators: %d: number of changes. */
			$confirm = $count > 1 ? sprintf( __( 'Undo these %d changes, saved together?', 'activity-log-with-undo' ), $count ) : __( 'Undo this change?', 'activity-log-with-undo' );
		}

		$deletes_user = 'user_created' === $entry->event;
		if ( $deletes_user ) {
			/* translators: %s: user login. */
			$confirm = sprintf( __( 'This deletes the user %s. Their content goes to the person who added them, or to you. Undo?', 'activity-log-with-undo' ), $entry->object_label );
		}

		return sprintf(
			'<a href="%1$s" class="button %2$s alu-undo" data-alu-entry="%3$d" data-alu-force="%4$d" data-alu-count="%5$d" data-alu-redo="%6$d" data-alu-deletes-user="%7$d" data-alu-confirm="%8$s">%9$s</a>',
			esc_url( self::undo_url( $entry->id, $force ) ),
			$force ? 'button-primary alu-undo-anyway' : ( $redo ? 'alu-redo' : 'button-secondary' ),
			$entry->id,
			$force ? 1 : 0,
			$count,
			$redo ? 1 : 0,
			$deletes_user ? 1 : 0,
			esc_attr( $confirm ),
			esc_html( $label )
		);
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
			add_action( "load-{$hook}", array( $this, 'handle_bulk' ) );
		}
	}

	/**
	 * Styles and the undo script.
	 */
	public function enqueue_assets(): void {
		wp_enqueue_style( 'activity-log-with-undo-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array(), VERSION );
		wp_enqueue_script( 'activity-log-with-undo-admin', plugins_url( 'assets/admin.js', PLUGIN_FILE ), array( 'wp-hooks', 'wp-api-fetch' ), VERSION, true );
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
		Notices::add( 'success', $message );

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
				'disabled'       => array_values( array_diff( array_keys( $this->registry->all() ), $enabled ) ),
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
		?>
		<div class="wrap alu-page">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Activity Log', 'activity-log-with-undo' ); ?></h1>
			<hr class="wp-header-end">
			<p class="alu-intro"><?php esc_html_e( 'Every change to settings, plugins, themes, users, content, terms, menus and the Site Editor, with what it was before. Undo puts it back.', 'activity-log-with-undo' ); ?></p>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( self::url() ); ?>" class="nav-tab <?php echo 'settings' !== $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Log', 'activity-log-with-undo' ); ?></a>
				<a href="<?php echo esc_url( self::url( array( 'tab' => 'settings' ) ) ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'activity-log-with-undo' ); ?></a>
			</nav>

			<?php
			Notices::render();

			if ( $entry_id ) {
				$this->render_entry( $entry_id );
			} elseif ( 'settings' === $tab ) {
				$this->render_settings();
			} else {
				$this->render_log();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Prints the list.
	 */
	private function render_log(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		$table = new EntriesTable( $this->store, $this->registry );
		$table->prepare_items();
		$set = EntriesTable::filter( 'alu_set' );
		?>
		<?php if ( '' !== $set ) : ?>
			<p class="alu-set-note">
				<?php esc_html_e( 'Showing the changes saved together in one request. Undo on any of them undoes them all.', 'activity-log-with-undo' ); ?>
				<a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Show everything', 'activity-log-with-undo' ); ?></a>
			</p>
		<?php endif; ?>
		<form method="get" class="alu-log">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<?php if ( '' !== $set ) : ?>
				<input type="hidden" name="alu_set" value="<?php echo esc_attr( $set ); ?>">
			<?php endif; ?>
			<?php
			$table->search_box( __( 'Search the log', 'activity-log-with-undo' ), 'activity-log-with-undo' );
			$table->display();
			?>
		</form>
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

		$check  = $this->undoer->check( $entry->id );
		$set    = $this->store->change_set( $entry->change_set );
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$events = $this->registry->events();
		?>
		<div class="alu-entry" data-alu-entry="<?php echo esc_attr( (string) $entry->id ); ?>">
			<h2><?php echo esc_html( $this->registry->describe( $entry ) ); ?></h2>
			<table class="form-table alu-facts" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'When', 'activity-log-with-undo' ); ?></th><td><?php echo esc_html( wp_date( $format, $entry->timestamp() ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Who', 'activity-log-with-undo' ); ?></th><td><?php echo esc_html( $entry->actor_name() ); ?> <span class="alu-via alu-via-<?php echo esc_attr( $entry->actor_via ); ?>"><?php echo esc_html( self::via_label( $entry->actor_via ) ); ?></span></td></tr>
				<?php if ( '' !== $entry->ip ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'IP address', 'activity-log-with-undo' ); ?></th><td><code><?php echo esc_html( $entry->ip ); ?></code></td></tr>
				<?php endif; ?>
				<tr><th scope="row"><?php esc_html_e( 'Event', 'activity-log-with-undo' ); ?></th><td><?php echo esc_html( $events[ $entry->event ] ?? $entry->event ); ?> <code><?php echo esc_html( $entry->event ); ?></code></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Object', 'activity-log-with-undo' ); ?></th><td><code><?php echo esc_html( $entry->object_type . ' ' . $entry->object_id ); ?></code> <?php echo esc_html( $entry->object_label ); ?></td></tr>
				<?php if ( count( $set ) > 1 ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Saved with', 'activity-log-with-undo' ); ?></th>
						<td>
							<ul class="alu-set">
								<?php foreach ( $set as $other ) : ?>
									<?php if ( $other->id !== $entry->id ) : ?>
										<li><a href="<?php echo esc_url( self::url( array( 'entry' => $other->id ) ) ); ?>">#<?php echo esc_html( (string) $other->id ); ?></a> <?php echo esc_html( $this->registry->describe( $other ) ); ?></li>
									<?php endif; ?>
								<?php endforeach; ?>
							</ul>
							<p class="description"><?php esc_html_e( 'These were saved in the same request, so Undo puts all of them back together.', 'activity-log-with-undo' ); ?></p>
						</td>
					</tr>
				<?php endif; ?>
				<?php if ( $entry->undo_of ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'Undo of', 'activity-log-with-undo' ); ?></th><td><a href="<?php echo esc_url( self::url( array( 'entry' => $entry->undo_of ) ) ); ?>">#<?php echo esc_html( (string) $entry->undo_of ); ?></a></td></tr>
				<?php endif; ?>
				<?php if ( $entry->is_undone() ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'Undone by', 'activity-log-with-undo' ); ?></th><td><a href="<?php echo esc_url( self::url( array( 'entry' => (int) $entry->undone_by ) ) ); ?>">#<?php echo esc_html( (string) $entry->undone_by ); ?></a></td></tr>
				<?php endif; ?>
				<?php if ( 'term_deleted' === $entry->event && ! empty( ( (array) $entry->before() )['ctt_item'] ) ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'Kept in', 'activity-log-with-undo' ); ?></th><td><a href="<?php echo esc_url( CategoryTagTrash::url() ); ?>"><?php esc_html_e( 'Category & Tag Trash', 'activity-log-with-undo' ); ?></a></td></tr>
				<?php endif; ?>
			</table>

			<h3><?php esc_html_e( 'Before and after', 'activity-log-with-undo' ); ?></h3>
			<?php $this->render_states( $entry ); ?>

			<?php $this->render_undo( $entry, $check ); ?>
		</div>
		<?php
	}

	/**
	 * Prints the before and after states.
	 *
	 * @param Entry $entry Entry.
	 */
	private function render_states( Entry $entry ): void {
		$left_out = max( Payload::left_out( $entry->before ), Payload::left_out( $entry->after ) );
		if ( $left_out ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html(
				sprintf(
					/* translators: %s: size, e.g. "300 KB". */
					__( 'This change was too big to keep (%s), so it is logged without its values and cannot be undone. The limit is in the settings.', 'activity-log-with-undo' ),
					size_format( $left_out )
				)
			) . '</p></div>';
			return;
		}

		echo wp_kses_post( DiffView::compare( $entry->before(), $entry->after(), __( 'Before', 'activity-log-with-undo' ), __( 'After', 'activity-log-with-undo' ) ) );
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

		$count   = count( $check['entries'] );
		$blocked = $check['blocked'][ $entry->id ] ?? null;
		if ( $blocked ) {
			printf( '<div class="notice notice-info inline alu-not-undoable"><p>%s</p></div>', esc_html( $blocked->get_error_message() ) );
			return;
		}

		if ( ! $check['conflicts'] ) {
			echo '<p class="alu-actions">' . self::undo_link( $entry, $count, false ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in undo_link().
			return;
		}

		echo '<div class="notice notice-warning inline alu-conflict"><p><strong>' . esc_html__( 'Changed since', 'activity-log-with-undo' ) . '</strong> ' . esc_html__( 'This was changed again after this entry. Undo would overwrite these later changes:', 'activity-log-with-undo' ) . '</p></div>';
		foreach ( $check['conflicts'] as $conflict ) {
			$other = (int) $conflict['id'] === $entry->id ? $entry : $this->store->get( (int) $conflict['id'] );
			if ( $other && $other->id !== $entry->id ) {
				echo '<h4>' . esc_html( $this->registry->describe( $other ) ) . '</h4>';
			}
			echo wp_kses_post( DiffView::compare( $conflict['expected'], $conflict['current'], __( 'Right after this change', 'activity-log-with-undo' ), __( 'Now', 'activity-log-with-undo' ) ) );
		}
		echo '<p class="alu-actions">' . self::undo_link( $entry, $count, true ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in undo_link().
	}

	/**
	 * Prints the settings form.
	 */
	private function render_settings(): void {
		$settings = $this->settings->all();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="alu-settings">
			<?php wp_nonce_field( self::SETTINGS_SAVE ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SETTINGS_SAVE ); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Log changes to', 'activity-log-with-undo' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Log changes to', 'activity-log-with-undo' ); ?></legend>
							<?php foreach ( $this->registry->all() as $id => $recorder ) : ?>
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

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="alu-purge">
			<?php wp_nonce_field( self::SETTINGS_SAVE ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SETTINGS_SAVE ); ?>">
			<input type="hidden" name="task" value="purge">
			<h2><?php esc_html_e( 'Clean up now', 'activity-log-with-undo' ); ?></h2>
			<p>
				<?php
				/* translators: 1: number of entries, 2: number of days. */
				echo esc_html( sprintf( __( 'The log holds %1$s entries. Entries older than %2$d days are deleted once a day; this does it now.', 'activity-log-with-undo' ), number_format_i18n( $this->store->count() ), $this->settings->retention_days() ) );
				?>
			</p>
			<?php submit_button( __( 'Delete old entries now', 'activity-log-with-undo' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}
}
