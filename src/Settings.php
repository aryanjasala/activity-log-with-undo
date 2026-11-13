<?php
/**
 * Saved settings.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo;

defined( 'ABSPATH' ) || exit;

/**
 * Which recorders run, how long entries are kept, IP logging, the payload
 * size cap and the admin bar item.
 */
final class Settings {

	public const OPTION = 'activity_log_with_undo_settings';

	public const IP_MODES = array( 'full', 'masked', 'off' );

	/**
	 * Settings with defaults applied, cached for the request.
	 *
	 * @var array|null
	 */
	private ?array $settings = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		$defaults = array(
			// Empty means every recorder; otherwise the IDs that are off.
			'disabled'       => array(),
			'retention_days' => 90,
			'ip'             => 'masked',
			'payload_kb'     => 256,
			'admin_bar'      => false,
			// Working hours, for alert rules that only fire outside them. Days are ISO (1 is Monday).
			'work_days'      => array( 1, 2, 3, 4, 5 ),
			'work_start'     => '09:00',
			'work_end'       => '18:00',
			// Minutes between two instant alert messages from one rule. Changes inside the window wait for one combined message.
			'alert_throttle' => 10,
		);

		/**
		 * Filters the default settings, used until someone saves the settings screen.
		 *
		 * @param array $defaults disabled (recorder IDs), retention_days, ip (full, masked or off), payload_kb, admin_bar, work_days (1 to 7), work_start and work_end (HH:MM, site time), alert_throttle (minutes).
		 */
		$filtered = (array) apply_filters( 'activity_log_with_undo_default_settings', $defaults );

		return self::sanitize( $filtered + $defaults );
	}

	/**
	 * Cleans a settings array.
	 *
	 * @param array $settings Raw settings.
	 * @return array
	 */
	public static function sanitize( array $settings ): array {
		$ip    = (string) ( $settings['ip'] ?? 'masked' );
		$days  = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', (array) ( $settings['work_days'] ?? array( 1, 2, 3, 4, 5 ) ) ),
					static function ( $day ) {
						return $day >= 1 && $day <= 7;
					} 
				) 
			) 
		);
		$start = self::time_of_day( $settings['work_start'] ?? '09:00', '09:00' );
		$end   = self::time_of_day( $settings['work_end'] ?? '18:00', '18:00' );
		if ( $end <= $start ) {
			$start = '09:00';
			$end   = '18:00';
		}
		sort( $days );

		return array(
			'disabled'       => array_values( array_unique( array_map( 'sanitize_key', array_map( 'strval', (array) ( $settings['disabled'] ?? array() ) ) ) ) ),
			'retention_days' => max( 1, min( 3650, (int) ( $settings['retention_days'] ?? 90 ) ) ),
			'ip'             => in_array( $ip, self::IP_MODES, true ) ? $ip : 'masked',
			'payload_kb'     => max( 1, min( 16384, (int) ( $settings['payload_kb'] ?? 256 ) ) ),
			'admin_bar'      => ! empty( $settings['admin_bar'] ),
			'work_days'      => $days,
			'work_start'     => $start,
			'work_end'       => $end,
			'alert_throttle' => max( 0, min( 1440, (int) ( $settings['alert_throttle'] ?? 10 ) ) ),
		);
	}

	/**
	 * A time of day as HH:MM, or a fallback.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Used when the value is not a time.
	 * @return string
	 */
	public static function time_of_day( $value, string $fallback ): string {
		if ( is_string( $value ) && preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', trim( $value ), $m ) ) {
			return sprintf( '%02d:%s', (int) $m[1], $m[2] );
		}

		return $fallback;
	}

	/**
	 * Every setting.
	 *
	 * @return array
	 */
	public function all(): array {
		if ( null === $this->settings ) {
			$saved          = get_option( self::OPTION, array() );
			$this->settings = is_array( $saved ) && $saved ? self::sanitize( $saved + self::defaults() ) : self::defaults();
		}

		return $this->settings;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public function get( string $key ) {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Saves settings.
	 *
	 * @param array $settings New values; missing keys keep their current value.
	 * @return array The saved settings.
	 */
	public function save( array $settings ): array {
		$old            = $this->all();
		$this->settings = self::sanitize( $settings + $old );
		update_option( self::OPTION, $this->settings );

		/**
		 * Fires after the settings are saved.
		 *
		 * @param array $settings New settings.
		 * @param array $old      Previous settings.
		 */
		do_action( 'activity_log_with_undo_settings_saved', $this->settings, $old );

		return $this->settings;
	}

	/**
	 * Whether a recorder is on.
	 *
	 * @param string $id Recorder ID.
	 * @return bool
	 */
	public function recorder_enabled( string $id ): bool {
		return ! in_array( $id, (array) $this->get( 'disabled' ), true );
	}

	/**
	 * Days entries are kept.
	 *
	 * @return int
	 */
	public function retention_days(): int {
		/**
		 * Filters how many days log entries are kept.
		 *
		 * @param int $days Default 90, or the saved setting.
		 */
		return max( 1, (int) apply_filters( 'activity_log_with_undo_retention_days', (int) $this->get( 'retention_days' ) ) );
	}

	/**
	 * Seconds between two instant alert messages from one rule.
	 *
	 * @return int
	 */
	public function alert_throttle(): int {
		/**
		 * Filters how many seconds one alert rule waits between instant messages.
		 *
		 * Changes inside the window are held and sent as one combined message when it ends.
		 *
		 * @param int $seconds Default 600, or the saved setting in minutes.
		 */
		return max( 0, (int) apply_filters( 'activity_log_with_undo_alert_throttle', (int) $this->get( 'alert_throttle' ) * MINUTE_IN_SECONDS ) );
	}

	/**
	 * Largest before or after state stored, in bytes. Bigger states are logged without them and cannot be undone.
	 *
	 * @return int
	 */
	public function payload_limit(): int {
		/**
		 * Filters the largest before or after state stored with an entry, in bytes.
		 *
		 * A change whose state is bigger is still logged, but cannot be undone.
		 *
		 * @param int $bytes Default 262144 (256 KB), or the saved setting.
		 */
		return max( 1024, (int) apply_filters( 'activity_log_with_undo_payload_limit', (int) $this->get( 'payload_kb' ) * 1024 ) );
	}
}
