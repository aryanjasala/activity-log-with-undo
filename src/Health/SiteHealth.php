<?php
/**
 * Site Health check.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo\Health;

use AryanX\ActivityLogWithUndo\Admin\LogPage;
use AryanX\ActivityLogWithUndo\Retention;
use AryanX\ActivityLogWithUndo\Schema;
use AryanX\ActivityLogWithUndo\Service;
use AryanX\ActivityLogWithUndo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an Activity Log with Undo test to Tools > Site Health: is the table
 * there, is the clean-up scheduled, and are any recorders off.
 */
final class SiteHealth implements Service {

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the check.
	 *
	 * @param Settings $settings Saved settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers the test.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
	}

	/**
	 * Adds the test to the direct tests.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public function add_test( $tests ): array {
		$tests                                     = (array) $tests;
		$tests['direct']['activity_log_with_undo'] = array(
			'label' => __( 'Activity Log with Undo', 'activity-log-with-undo' ),
			'test'  => array( $this, 'run' ),
		);

		return $tests;
	}

	/**
	 * Runs the test.
	 *
	 * @return array
	 */
	public function run(): array {
		$action = sprintf( '<p><a href="%s">%s</a></p>', esc_url( admin_url( 'tools.php?page=' . LogPage::SLUG ) ), esc_html__( 'Open the activity log', 'activity-log-with-undo' ) );

		if ( ! Schema::exists() ) {
			return $this->result( 'critical', __( 'The activity log table is missing', 'activity-log-with-undo' ), __( 'Changes are not being logged and cannot be undone. Deactivate and activate Activity Log with Undo to create the table again.', 'activity-log-with-undo' ), '' );
		}

		if ( ! wp_next_scheduled( Retention::HOOK ) ) {
			return $this->result( 'recommended', __( 'Old activity log entries are not being deleted', 'activity-log-with-undo' ), __( 'The daily clean-up is not scheduled, so the log keeps growing. It schedules itself again the next time an administrator opens wp-admin. If this stays, check that WP-Cron runs on this site.', 'activity-log-with-undo' ), $action );
		}

		if ( $this->settings->get( 'disabled' ) ) {
			return $this->result( 'recommended', __( 'Some changes are not logged', 'activity-log-with-undo' ), __( 'Some recorders are off in the activity log settings, so those changes are not logged and cannot be undone.', 'activity-log-with-undo' ), $action );
		}

		return $this->result(
			'good',
			__( 'Changes are logged and can be undone', 'activity-log-with-undo' ),
			/* translators: %d: number of days. */
			sprintf( __( 'Entries are kept for %d days.', 'activity-log-with-undo' ), $this->settings->retention_days() ),
			$action
		);
	}

	/**
	 * Builds a Site Health result.
	 *
	 * @param string $status      good, recommended or critical.
	 * @param string $label       Heading.
	 * @param string $description Explanation.
	 * @param string $actions     Action links HTML.
	 * @return array
	 */
	private function result( string $status, string $label, string $description, string $actions ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Security', 'activity-log-with-undo' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => $actions,
			'test'        => 'activity_log_with_undo',
		);
	}
}
