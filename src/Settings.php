<?php
/**
 * Saved settings.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo;

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
		);

		/**
		 * Filters the default settings, used until someone saves the settings screen.
		 *
		 * @param array $defaults disabled (recorder IDs), retention_days, ip (full, masked or off), payload_kb, admin_bar.
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
		$ip = (string) ( $settings['ip'] ?? 'masked' );

		return array(
			'disabled'       => array_values( array_unique( array_map( 'sanitize_key', array_map( 'strval', (array) ( $settings['disabled'] ?? array() ) ) ) ) ),
			'retention_days' => max( 1, min( 3650, (int) ( $settings['retention_days'] ?? 90 ) ) ),
			'ip'             => in_array( $ip, self::IP_MODES, true ) ? $ip : 'masked',
			'payload_kb'     => max( 1, min( 16384, (int) ( $settings['payload_kb'] ?? 256 ) ) ),
			'admin_bar'      => ! empty( $settings['admin_bar'] ),
		);
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
