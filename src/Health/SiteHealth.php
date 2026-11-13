<?php
/**
 * Site Health check.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Health;

use AryanX\Axle\ActivityLogWithUndo\Admin\LogPage;
use AryanX\Axle\ActivityLogWithUndo\Alerts\Rules;
use AryanX\Axle\ActivityLogWithUndo\Cleanup;
use AryanX\Axle\ActivityLogWithUndo\Logger;
use AryanX\Axle\ActivityLogWithUndo\Retention;
use AryanX\Axle\ActivityLogWithUndo\Rollback;
use AryanX\Axle\ActivityLogWithUndo\Schema;
use AryanX\Axle\ActivityLogWithUndo\Service;
use AryanX\Axle\ActivityLogWithUndo\Settings;
use AryanX\Axle\ActivityLogWithUndo\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an Activity Log with Undo test to Tools > Site Health: is the table
 * there, is it over its size limit, is the clean-up scheduled, does it hold
 * old entries of internal options, and are any recorders off.
 */
final class SiteHealth implements Service {

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Entries, for the size of the log.
	 *
	 * @var Store|null
	 */
	private ?Store $store;

	/**
	 * Roll backs, to spot one that never finished.
	 *
	 * @var Rollback|null
	 */
	private ?Rollback $rollback;

	/**
	 * Alert rules, to spot one whose messages do not get through.
	 *
	 * @var Rules|null
	 */
	private ?Rules $alert_rules;

	/**
	 * Sets up the check.
	 *
	 * @param Settings      $settings Saved settings.
	 * @param Store|null    $store    Entries, for the size of the log.
	 * @param Rollback|null $rollback Roll backs.
	 * @param Rules|null    $alert_rules Alert rules.
	 */
	public function __construct( Settings $settings, ?Store $store = null, ?Rollback $rollback = null, ?Rules $alert_rules = null ) {
		$this->settings    = $settings;
		$this->store       = $store;
		$this->rollback    = $rollback;
		$this->alert_rules = $alert_rules;
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

		$max  = Logger::max_log_size();
		$size = $this->store && $max > 0 ? $this->store->size() : 0;
		if ( $size > $max && $max > 0 ) {
			return $this->result(
				'critical',
				__( 'The activity log is full', 'activity-log-with-undo' ),
				/* translators: 1: size of the log, 2: its limit. */
				sprintf( __( 'The log takes %1$s, over its limit of %2$s. New changes are logged without their old values, so they cannot be undone. Delete old entries on the Settings tab, or keep fewer days.', 'activity-log-with-undo' ), size_format( $size, 1 ), size_format( $max ) ),
				$action
			);
		}

		$job = $this->rollback ? $this->rollback->job() : null;
		if ( $job && Rollback::is_stale( $job ) ) {
			return $this->result(
				'recommended',
				__( 'A roll back did not finish', 'activity-log-with-undo' ),
				__( 'A roll back started, but nothing has happened for over an hour. Some changes were undone and some were not. Open the Roll back tab to continue it or cancel it.', 'activity-log-with-undo' ),
				sprintf( '<p><a href="%s">%s</a></p>', esc_url( admin_url( 'tools.php?page=' . LogPage::SLUG . '&tab=rollback' ) ), esc_html__( 'Open the roll back', 'activity-log-with-undo' ) )
			);
		}

		if ( $this->alert_rules ) {
			$state  = $this->alert_rules->state();
			$failed = array();
			foreach ( $this->alert_rules->enabled() as $rule ) {
				if ( ! empty( $state['rules'][ $rule['id'] ]['error'] ) ) {
					$failed[] = $rule['name'];
				}
			}
			if ( $failed ) {
				return $this->result(
					'recommended',
					__( 'Some alerts are not getting through', 'activity-log-with-undo' ),
					/* translators: %s: rule names. */
					sprintf( __( 'The last message of these alert rules could not be sent: %s. Open the Alerts tab and send a test to see what went wrong.', 'activity-log-with-undo' ), implode( ', ', $failed ) ),
					sprintf( '<p><a href="%s">%s</a></p>', esc_url( admin_url( 'tools.php?page=' . LogPage::SLUG . '&tab=alerts' ) ), esc_html__( 'Open the alerts', 'activity-log-with-undo' ) )
				);
			}
		}

		$cleanup = Cleanup::state();
		if ( in_array( $cleanup['status'], array( 'pending', 'offered' ), true ) ) {
			return $this->result(
				'recommended',
				__( 'The activity log holds entries nobody can act on', 'activity-log-with-undo' ),
				__( 'An earlier version logged other plugins\' internal options, such as caches and indexes. Delete those entries from the notice in wp-admin, or with wp activity-log-with-undo cleanup.', 'activity-log-with-undo' ),
				$action
			);
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
