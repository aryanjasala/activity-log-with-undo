<?php
/**
 * Clears out what version 1 logged that nobody can act on.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo;

use AryanX\ActivityLogWithUndo\Admin\LogPage;
use AryanX\ActivityLogWithUndo\Admin\Notices;
use AryanX\ActivityLogWithUndo\Recorders\OptionsRecorder;

defined( 'ABSPATH' ) || exit;

/**
 * Version 1 logged every option, so other plugins' caches and indexes filled
 * the table (one Block Doctor scan wrote 5,079 entries, 1.18 GB). After the
 * upgrade, administrators get a notice that counts those entries and offers
 * to delete them. Deleting runs in batches, in the request and then through
 * WP-Cron. `wp activity-log-with-undo cleanup` does the same from the shell.
 */
final class Cleanup implements Service {

	public const OPTION = 'activity_log_with_undo_cleanup';
	public const HOOK   = 'activity_log_with_undo_cleanup';
	public const ACTION = 'activity_log_with_undo_cleanup';

	/**
	 * Entries.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Knows which options are settings.
	 *
	 * @var OptionsRecorder
	 */
	private OptionsRecorder $options;

	/**
	 * Sets up the clean-up.
	 *
	 * @param Store           $store   Entries.
	 * @param OptionsRecorder $options Knows which options are settings.
	 */
	public function __construct( Store $store, OptionsRecorder $options ) {
		$this->store   = $store;
		$this->options = $options;
	}

	/**
	 * Adds the notice, its form handler and the background job.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Marks the log for a scan, after an upgrade from version 1.
	 */
	public static function flag(): void {
		update_option( self::OPTION, array( 'status' => 'pending' ) );
	}

	/**
	 * Records that a new install has nothing to clean up, so wp-admin does not look for it.
	 */
	public static function nothing_to_do(): void {
		add_option( self::OPTION, array( 'status' => 'done' ) );
	}

	/**
	 * The stored state: status (pending, offered, running, done or kept), options, entries, deleted.
	 *
	 * @return array{status: string, options: string[], entries: int, deleted: int}
	 */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		return array(
			'status'  => in_array( $state['status'] ?? '', array( 'pending', 'offered', 'running', 'done', 'kept' ), true ) ? (string) $state['status'] : 'done',
			'options' => array_values( array_map( 'strval', (array) ( $state['options'] ?? array() ) ) ),
			'entries' => max( 0, (int) ( $state['entries'] ?? 0 ) ),
			'deleted' => max( 0, (int) ( $state['deleted'] ?? 0 ) ),
		);
	}

	/**
	 * Marks the clean-up as finished, after WP-CLI ran it.
	 *
	 * @param int $deleted Entries deleted.
	 */
	public static function finish( int $deleted ): void {
		self::save(
			array(
				'status'  => 'done',
				'deleted' => self::state()['deleted'] + max( 0, $deleted ),
			)
		);
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Saves the state.
	 *
	 * @param array $state New values; missing keys keep theirs.
	 */
	private static function save( array $state ): void {
		update_option( self::OPTION, $state + self::state() );
	}

	/**
	 * Counts the entries of options that are not logged any more.
	 *
	 * @return array<string, int> Option name => entries, biggest first. Network options are `network:<name>`.
	 */
	public function scan(): array {
		$found = array();
		foreach ( $this->store->option_entry_counts() as $name => $count ) {
			$network = 0 === strpos( (string) $name, 'network:' );
			$option  = $network ? substr( (string) $name, 8 ) : (string) $name;
			if ( ! $this->options->is_tracked( $option, $network ) ) {
				$found[ (string) $name ] = (int) $count;
			}
		}

		/**
		 * Filters the options whose entries the clean-up offers to delete.
		 *
		 * Remove an option to keep its entries.
		 *
		 * @param array<string, int> $found Option name => number of entries. Network options are `network:<name>`.
		 */
		$found = (array) apply_filters( 'activity_log_with_undo_cleanup_options', $found );

		$out = array();
		foreach ( $found as $name => $count ) {
			if ( '' !== (string) $name && (int) $count > 0 ) {
				$out[ (string) $name ] = (int) $count;
			}
		}
		arsort( $out );

		return $out;
	}

	/**
	 * Scans and stores the result, the first time an administrator sees wp-admin after the upgrade.
	 *
	 * @return array{status: string, options: string[], entries: int, deleted: int}
	 */
	public function offer(): array {
		$found = $this->scan();
		self::save(
			array(
				'status'  => $found ? 'offered' : 'done',
				'options' => array_keys( $found ),
				'entries' => array_sum( $found ),
				'deleted' => 0,
			)
		);

		return self::state();
	}

	/**
	 * Deletes up to $batches batches of the entries found.
	 *
	 * @param string[] $options Option names, `network:<name>` for network options.
	 * @param int      $batches Most batches to run.
	 * @return array{deleted: int, done: bool}
	 */
	public function purge( array $options, int $batches ): array {
		/**
		 * Filters how many entries the clean-up deletes per query.
		 *
		 * @param int $size Default 500.
		 */
		$size    = max( 1, min( 10000, (int) apply_filters( 'activity_log_with_undo_cleanup_batch_size', 500 ) ) );
		$deleted = 0;
		$done    = ! $options;

		$batches = max( 1, $batches );
		for ( $i = 0; ! $done && $i < $batches; $i++ ) {
			$count    = $this->store->delete_option_entries( $options, $size );
			$deleted += $count;
			$done     = $count < $size;
		}

		if ( $done ) {
			$this->store->forget_size();

			/**
			 * Fires when the clean-up deleted every entry it found.
			 *
			 * @param int      $deleted Entries deleted by this last run.
			 * @param string[] $options The options whose entries were deleted.
			 */
			do_action( 'activity_log_with_undo_cleaned_up', $deleted, $options );
		}

		return array(
			'deleted' => $deleted,
			'done'    => $done,
		);
	}

	/**
	 * Deletes a few batches, and schedules the rest.
	 *
	 * @param int $batches Most batches to run now.
	 * @return array{deleted: int, done: bool}
	 */
	public function step( int $batches ): array {
		$state  = self::state();
		$result = $this->purge( $state['options'], $batches );

		self::save(
			array(
				'status'  => $result['done'] ? 'done' : 'running',
				'deleted' => $state['deleted'] + $result['deleted'],
			)
		);
		if ( ! $result['done'] && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}

		return $result;
	}

	/**
	 * The background job: keeps deleting until done.
	 */
	public function run(): void {
		if ( 'running' === self::state()['status'] ) {
			$this->step( 20 );
		}
	}

	/**
	 * The notice that offers the clean-up, or says it is running.
	 */
	public function notice(): void {
		if ( ! current_user_can( LogPage::capability() ) ) {
			return;
		}

		$state = self::state();
		if ( 'pending' === $state['status'] ) {
			$state = $this->offer();
		}

		if ( 'running' === $state['status'] ) {
			printf(
				'<div class="notice notice-info alu-cleanup"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: entries deleted so far, 2: entries found. */
						__( 'Activity Log with Undo is deleting old entries of internal options in the background: %1$s of %2$s done.', 'activity-log-with-undo' ),
						number_format_i18n( min( $state['deleted'], $state['entries'] ) ),
						number_format_i18n( $state['entries'] )
					)
				)
			);
			return;
		}

		if ( 'offered' !== $state['status'] ) {
			return;
		}

		$names = array_map(
			static function ( string $name ): string {
				return 0 === strpos( $name, 'network:' ) ? substr( $name, 8 ) : $name;
			},
			array_slice( $state['options'], 0, 3 )
		);
		$size  = $this->store->size();

		$message = sprintf(
			/* translators: 1: number of entries, 2: option names. */
			_n(
				'Activity Log with Undo now logs only settings people change on purpose. The log still holds %1$s entry of an option that is not a setting (%2$s). Nobody can act on it, and undoing it could put another plugin out of step.',
				'Activity Log with Undo now logs only settings people change on purpose. The log still holds %1$s entries of options that are not settings (%2$s). Nobody can act on them, and undoing them could put other plugins out of step.',
				$state['entries'],
				'activity-log-with-undo'
			),
			number_format_i18n( $state['entries'] ),
			implode( ', ', $names ) . ( count( $state['options'] ) > 3 ? ', …' : '' )
		);
		if ( $size > 0 ) {
			/* translators: %s: size, e.g. "1.1 GB". */
			$message .= ' ' . sprintf( __( 'The log takes %s.', 'activity-log-with-undo' ), size_format( $size, 1 ) );
		}

		/**
		 * Filters the notice that offers to delete the entries of internal options.
		 *
		 * @param string $message Message.
		 * @param array  $state   status, options, entries, deleted.
		 */
		$message = (string) apply_filters( 'activity_log_with_undo_cleanup_message', $message, $state );
		?>
		<div class="notice notice-warning alu-cleanup">
			<p><?php echo esc_html( $message ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="alu-cleanup-actions">
				<?php wp_nonce_field( self::ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<button type="submit" name="task" value="delete" class="button button-primary"><?php esc_html_e( 'Delete these entries', 'activity-log-with-undo' ); ?></button>
				<button type="submit" name="task" value="keep" class="button"><?php esc_html_e( 'Keep them', 'activity-log-with-undo' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Delete or keep, from the notice.
	 */
	public function handle(): void {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( LogPage::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change the activity log.', 'activity-log-with-undo' ), 403 );
		}

		$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
		if ( 'keep' === $task ) {
			self::save( array( 'status' => 'kept' ) );
			Notices::add( 'info', __( 'The old entries stay. Run wp activity-log-with-undo cleanup to delete them later.', 'activity-log-with-undo' ) );
		} elseif ( 'delete' === $task && 'offered' === self::state()['status'] ) {
			$result = $this->step( 10 );
			$state  = self::state();
			Notices::add(
				'success',
				$result['done']
					/* translators: %s: number of entries. */
					? sprintf( __( 'Deleted %s old entries of internal options.', 'activity-log-with-undo' ), number_format_i18n( $state['deleted'] ) )
					/* translators: %s: number of entries. */
					: sprintf( __( 'Deleted %s old entries so far. The rest are deleted in the background.', 'activity-log-with-undo' ), number_format_i18n( $state['deleted'] ) )
			);
		}

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : LogPage::url() );
		exit;
	}
}
