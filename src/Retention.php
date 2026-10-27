<?php
/**
 * Deletes old entries.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo;

defined( 'ABSPATH' ) || exit;

/**
 * A daily WP-Cron job deletes entries older than the retention setting
 * (90 days by default), in batches. When a batch run does not finish, it
 * schedules itself again a minute later.
 */
final class Retention implements Service {

	public const HOOK = 'activity_log_with_undo_purge';

	/**
	 * Entries.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the clean-up.
	 *
	 * @param Store    $store    Entries.
	 * @param Settings $settings Saved settings.
	 */
	public function __construct( Store $store, Settings $settings ) {
		$this->store    = $store;
		$this->settings = $settings;
	}

	/**
	 * Hooks the job, and schedules it if it is missing.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'admin_init', array( self::class, 'schedule' ) );
	}

	/**
	 * Schedules the daily job.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Removes the job. Runs on deactivation.
	 */
	public static function unschedule(): void {
		wp_unschedule_hook( self::HOOK );
	}

	/**
	 * The cron job: deletes old entries, and comes back if there are more.
	 */
	public function run(): void {
		$result = $this->purge( $this->settings->retention_days() );
		if ( ! $result['done'] && ! wp_next_scheduled( self::HOOK, array( 'more' ) ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK, array( 'more' ) );
		}
	}

	/**
	 * Deletes entries older than a number of days.
	 *
	 * @param int $days     Keep this many days. 0 deletes everything.
	 * @param int $batches  Most batches to run now.
	 * @return array{deleted: int, done: bool, cutoff: string}
	 */
	public function purge( int $days, int $batches = 20 ): array {
		/**
		 * Filters how many entries are deleted per query.
		 *
		 * @param int $size Default 1000.
		 */
		$size    = max( 1, min( 10000, (int) apply_filters( 'activity_log_with_undo_purge_batch_size', 1000 ) ) );
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - max( 0, $days ) * DAY_IN_SECONDS + ( 0 === $days ? 1 : 0 ) );
		$deleted = 0;
		$done    = false;

		$batches = max( 1, $batches );
		for ( $i = 0; $i < $batches; $i++ ) {
			$count    = $this->store->purge_before( $cutoff, $size );
			$deleted += $count;
			if ( $count < $size ) {
				$done = true;
				break;
			}
		}

		/**
		 * Fires after old entries are deleted.
		 *
		 * @param int    $deleted Entries deleted.
		 * @param string $cutoff  Entries older than this were deleted, UTC `Y-m-d H:i:s`.
		 */
		do_action( 'activity_log_with_undo_purged', $deleted, $cutoff );

		return array(
			'deleted' => $deleted,
			'done'    => $done,
			'cutoff'  => $cutoff,
		);
	}
}
