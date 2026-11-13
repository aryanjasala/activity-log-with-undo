<?php
/**
 * Decides which entries a rule fires for.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Alerts;

use AryanX\Axle\ActivityLogWithUndo\Entry;
use AryanX\Axle\ActivityLogWithUndo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A rule fires when every filled-in part matches: event, area, object, user or role
 * (either one), a condition, and working hours.
 */
final class Matcher {

	/**
	 * Saved settings, for the working hours.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the matcher.
	 *
	 * @param Settings $settings Saved settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Named conditions a rule can add, ID => label.
	 *
	 * @return array<string, string>
	 */
	public static function conditions(): array {
		$conditions = array(
			'becomes_administrator' => __( 'Someone becomes an administrator', 'activity-log-with-undo' ),
			'turned_on'             => __( 'The setting is turned on', 'activity-log-with-undo' ),
		);

		/**
		 * Filters the conditions an alert rule can use.
		 *
		 * Add one here and test it in `activity_log_with_undo_alert_condition_met`.
		 *
		 * @param array<string, string> $conditions Condition ID => label.
		 */
		$filtered = apply_filters( 'activity_log_with_undo_alert_conditions', $conditions );

		return array_map( 'strval', (array) $filtered );
	}

	/**
	 * Whether a rule fires for an entry.
	 *
	 * @param array $rule  Rule.
	 * @param Entry $entry Entry.
	 * @return bool
	 */
	public function matches( array $rule, Entry $entry ): bool {
		$match = $this->check( $rule, $entry );

		/**
		 * Filters whether an alert rule fires for an entry.
		 *
		 * @param bool  $match Whether the rule's own tests passed.
		 * @param array $rule  The rule. It holds no webhook URL.
		 * @param Entry $entry The entry.
		 */
		return (bool) apply_filters( 'activity_log_with_undo_alert_matches', $match, $rule, $entry );
	}

	/**
	 * The rule's own tests.
	 *
	 * @param array $rule  Rule.
	 * @param Entry $entry Entry.
	 * @return bool
	 */
	private function check( array $rule, Entry $entry ): bool {
		if ( $rule['events'] && ! in_array( $entry->event, $rule['events'], true ) ) {
			return false;
		}
		if ( $rule['areas'] && ! in_array( $entry->recorder, $rule['areas'], true ) ) {
			return false;
		}
		if ( $rule['objects'] && ! in_array( $entry->object_id, $rule['objects'], true ) ) {
			return false;
		}
		if ( ( $rule['users'] || $rule['roles'] ) && ! $this->actor_matches( $rule, $entry ) ) {
			return false;
		}
		if ( '' !== $rule['condition'] && ! $this->condition_met( $rule['condition'], $entry ) ) {
			return false;
		}
		if ( $rule['outside_hours'] && ! $this->outside_hours( $entry->timestamp() ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether the person who made the change is one the rule names, by ID or by role.
	 *
	 * @param array $rule  Rule.
	 * @param Entry $entry Entry.
	 * @return bool
	 */
	private function actor_matches( array $rule, Entry $entry ): bool {
		if ( $entry->actor_user_id > 0 && in_array( $entry->actor_user_id, $rule['users'], true ) ) {
			return true;
		}
		if ( $rule['roles'] && $entry->actor_user_id > 0 ) {
			$user = get_userdata( $entry->actor_user_id );
			if ( $user && array_intersect( $rule['roles'], (array) $user->roles ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tests a named condition.
	 *
	 * @param string $condition Condition ID.
	 * @param Entry  $entry     Entry.
	 * @return bool
	 */
	private function condition_met( string $condition, Entry $entry ): bool {
		$met = false;
		if ( 'becomes_administrator' === $condition ) {
			$before = $entry->before();
			$after  = $entry->after();
			if ( 'user_created' === $entry->event ) {
				$met = is_array( $after ) && in_array( 'administrator', (array) ( $after['roles'] ?? array() ), true );
			} elseif ( 'user_role_changed' === $entry->event ) {
				$met = is_array( $after ) && in_array( 'administrator', $after, true ) && ! ( is_array( $before ) && in_array( 'administrator', $before, true ) );
			}
		} elseif ( 'turned_on' === $condition ) {
			$after  = $entry->after();
			$before = $entry->before();
			$on     = static function ( $value ): bool {
				return is_scalar( $value ) && ! in_array( strtolower( (string) $value ), array( '', '0', 'false', 'off', 'no' ), true );
			};
			$met    = $on( $after ) && ! $on( $before );
		}

		/**
		 * Filters whether a named alert condition is met. Return true for a condition you added.
		 *
		 * @param bool   $met       Result for the built-in conditions, false for others.
		 * @param string $condition Condition ID.
		 * @param Entry  $entry     The entry.
		 */
		return (bool) apply_filters( 'activity_log_with_undo_alert_condition_met', $met, $condition, $entry );
	}

	/**
	 * Whether a moment is outside the working hours, in site time.
	 *
	 * @param int $timestamp Unix time.
	 * @return bool
	 */
	public function outside_hours( int $timestamp ): bool {
		$days  = (array) $this->settings->get( 'work_days' );
		$day   = (int) wp_date( 'N', $timestamp );
		$clock = (string) wp_date( 'H:i', $timestamp );

		$outside = ! in_array( $day, $days, true ) || $clock < (string) $this->settings->get( 'work_start' ) || $clock >= (string) $this->settings->get( 'work_end' );

		/**
		 * Filters whether a moment counts as outside working hours.
		 *
		 * @param bool $outside   Result from the saved working days and hours.
		 * @param int  $timestamp Unix time.
		 */
		return (bool) apply_filters( 'activity_log_with_undo_alert_outside_hours', $outside, $timestamp );
	}
}
