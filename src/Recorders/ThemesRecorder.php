<?php
/**
 * Theme switches, installs, updates and deletion.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo\Recorders;

use AryanX\ActivityLogWithUndo\Entry;

defined( 'ABSPATH' ) || exit;

/**
 * A theme switch can be reversed while the old theme is still installed.
 * Installs, updates and deletions are logged only.
 */
final class ThemesRecorder extends BaseRecorder {

	/**
	 * Recorder ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'themes';
	}

	/**
	 * Name on the settings screen.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Themes', 'activity-log-with-undo' );
	}

	/**
	 * Events.
	 *
	 * @return array<string, string>
	 */
	public function events(): array {
		return array(
			'theme_switched'  => __( 'Theme switched', 'activity-log-with-undo' ),
			'theme_installed' => __( 'Theme installed', 'activity-log-with-undo' ),
			'theme_updated'   => __( 'Theme updated', 'activity-log-with-undo' ),
			'theme_deleted'   => __( 'Theme deleted', 'activity-log-with-undo' ),
		);
	}

	/**
	 * Hooks into theme changes.
	 */
	public function register(): void {
		add_action( 'switch_theme', array( $this, 'switched' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'upgraded' ), 10, 2 );
		add_action( 'deleted_theme', array( $this, 'deleted' ), 10, 2 );
	}

	/**
	 * The theme was switched.
	 *
	 * @param string $new_name  New theme name.
	 * @param mixed  $new_theme New theme (WP_Theme).
	 * @param mixed  $old_theme Old theme (WP_Theme).
	 */
	public function switched( $new_name, $new_theme, $old_theme ): void {
		$old = $old_theme instanceof \WP_Theme ? $old_theme->get_stylesheet() : '';
		$new = $new_theme instanceof \WP_Theme ? $new_theme->get_stylesheet() : get_stylesheet();

		$this->record( 'theme_switched', 'theme', $new, (string) $new_name, array( 'stylesheet' => $old ), array( 'stylesheet' => $new ), '' !== $old );
	}

	/**
	 * Themes were installed or updated.
	 *
	 * @param mixed $upgrader Upgrader.
	 * @param array $extra    type, action, themes.
	 */
	public function upgraded( $upgrader, $extra ): void {
		$extra = (array) $extra;
		if ( 'theme' !== ( $extra['type'] ?? '' ) ) {
			return;
		}

		if ( 'install' === ( $extra['action'] ?? '' ) ) {
			$theme = is_object( $upgrader ) && method_exists( $upgrader, 'theme_info' ) ? $upgrader->theme_info() : false;
			if ( $theme instanceof \WP_Theme ) {
				$this->record( 'theme_installed', 'theme', $theme->get_stylesheet(), (string) $theme->get( 'Name' ), null, array( 'version' => (string) $theme->get( 'Version' ) ), false );
			}
			return;
		}

		foreach ( isset( $extra['themes'] ) ? (array) $extra['themes'] : array() as $stylesheet ) {
			$theme = wp_get_theme( (string) $stylesheet );
			$this->record( 'theme_updated', 'theme', $stylesheet, (string) $theme->get( 'Name' ), null, array( 'version' => (string) $theme->get( 'Version' ) ), false );
		}
	}

	/**
	 * A theme was deleted.
	 *
	 * @param string $stylesheet Theme folder.
	 * @param bool   $deleted    Whether the files were removed.
	 */
	public function deleted( $stylesheet, $deleted ): void {
		if ( $deleted ) {
			$this->record( 'theme_deleted', 'theme', $stylesheet, (string) $stylesheet, null, null, false );
		}
	}

	/**
	 * Whether the old theme can come back.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	public function can_undo( Entry $entry ) {
		switch ( $entry->event ) {
			case 'theme_installed':
				return self::refuse( __( 'Installs are logged only. Delete the theme under Appearance > Themes if you do not want it.', 'activity-log-with-undo' ) );
			case 'theme_updated':
				return self::refuse( __( 'WordPress keeps no copy of the old version, so an update cannot be reversed here.', 'activity-log-with-undo' ) );
			case 'theme_deleted':
				return self::refuse( __( 'The theme files are gone. Install the theme again to get it back.', 'activity-log-with-undo' ) );
		}

		$old = (string) ( ( (array) $entry->before() )['stylesheet'] ?? '' );
		if ( '' === $old || ! wp_get_theme( $old )->exists() ) {
			return self::refuse( __( 'The previous theme is no longer installed.', 'activity-log-with-undo' ) );
		}

		return parent::can_undo( $entry );
	}

	/**
	 * The active theme.
	 *
	 * @param Entry $entry Entry.
	 * @return array
	 */
	public function current_state( Entry $entry ): array {
		return array( 'stylesheet' => get_stylesheet() );
	}

	/**
	 * Switches back to the old theme.
	 *
	 * @param Entry $entry Entry.
	 * @return true
	 */
	public function undo( Entry $entry ) {
		switch_theme( (string) ( (array) $entry->before() )['stylesheet'] );

		return true;
	}

	/**
	 * One line about the change.
	 *
	 * @param Entry $entry Entry.
	 * @return string
	 */
	public function describe( Entry $entry ): string {
		$name = '' !== $entry->object_label ? $entry->object_label : $entry->object_id;

		switch ( $entry->event ) {
			case 'theme_switched':
				$old = wp_get_theme( (string) ( ( (array) $entry->before() )['stylesheet'] ?? '' ) );
				/* translators: 1: new theme name, 2: old theme name. */
				return sprintf( __( 'Switched the theme to %1$s (was %2$s)', 'activity-log-with-undo' ), $name, $old->exists() ? (string) $old->get( 'Name' ) : (string) ( ( (array) $entry->before() )['stylesheet'] ?? '' ) );
			case 'theme_installed':
				/* translators: %s: theme name. */
				return sprintf( __( 'Installed the %s theme', 'activity-log-with-undo' ), $name );
			case 'theme_updated':
				/* translators: %s: theme name. */
				return sprintf( __( 'Updated the %s theme', 'activity-log-with-undo' ), $name );
			default:
				/* translators: %s: theme folder. */
				return sprintf( __( 'Deleted the %s theme', 'activity-log-with-undo' ), $name );
		}
	}
}
