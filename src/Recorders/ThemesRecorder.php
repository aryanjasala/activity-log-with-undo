<?php
/**
 * Theme switches, installs, updates and deletion.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Recorders;

use AryanX\Axle\ActivityLogWithUndo\Entry;
use AryanX\Axle\ActivityLogWithUndo\Integrations\Siblings;

defined( 'ABSPATH' ) || exit;

/**
 * A theme switch can be reversed while the old theme is still installed. So
 * can an update, when Axle Smoke Test saved the old version first. Installs
 * and deletions are logged only.
 */
final class ThemesRecorder extends BaseRecorder {

	/**
	 * Versions before an update, keyed by theme folder.
	 *
	 * @var array<string, string>
	 */
	private array $versions = array();

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
		add_filter( 'upgrader_pre_install', array( $this, 'before_install' ), 5, 2 );
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
	 * Remembers the version a theme has before an update replaces it.
	 *
	 * @param mixed $response   Result so far; returned untouched.
	 * @param mixed $hook_extra What the upgrader is working on.
	 * @return mixed
	 */
	public function before_install( $response, $hook_extra = array() ) {
		$theme = is_array( $hook_extra ) && ! empty( $hook_extra['theme'] ) ? (string) $hook_extra['theme'] : '';
		if ( '' !== $theme && ! isset( $this->versions[ $theme ] ) ) {
			$this->versions[ $theme ] = (string) wp_get_theme( $theme )->get( 'Version' );
		}

		return $response;
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

		$themes = isset( $extra['themes'] ) ? (array) $extra['themes'] : ( isset( $extra['theme'] ) ? array( $extra['theme'] ) : array() );
		foreach ( $themes as $stylesheet ) {
			$stylesheet = (string) $stylesheet;
			$theme      = wp_get_theme( $stylesheet );
			$old        = $this->versions[ $stylesheet ] ?? '';
			unset( $this->versions[ $stylesheet ] );
			$new = (string) $theme->get( 'Version' );
			// Undoable only when the files of the old version were saved before the update.
			$this->record( 'theme_updated', 'theme', $stylesheet, (string) $theme->get( 'Name' ), '' !== $old ? array( 'version' => $old ) : null, array( 'version' => $new ), '' !== $old && $old !== $new && null !== Siblings::smoke_backup( 'theme', $stylesheet, $old ) );
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
				return $this->can_undo_update( $entry );
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
		if ( 'theme_updated' === $entry->event ) {
			return array( 'version' => (string) wp_get_theme( $entry->object_id )->get( 'Version' ) );
		}

		return array( 'stylesheet' => get_stylesheet() );
	}

	/**
	 * Switches back to the old theme.
	 *
	 * @param Entry $entry Entry.
	 * @return true
	 */
	public function undo( Entry $entry ) {
		if ( 'theme_updated' === $entry->event ) {
			return $this->undo_update( $entry );
		}
		switch_theme( (string) ( (array) $entry->before() )['stylesheet'] );

		return true;
	}

	/**
	 * Whether an update can be reversed: the user may update themes, and Smoke Test still has the zip.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	private function can_undo_update( Entry $entry ) {
		$old = (string) ( ( (array) $entry->before() )['version'] ?? '' );
		if ( '' === $old ) {
			return self::refuse( __( 'The version before this update was not recorded, so it cannot be reversed here.', 'activity-log-with-undo' ) );
		}
		if ( ! current_user_can( 'update_themes' ) && ! ( defined( 'WP_CLI' ) && WP_CLI && ! is_user_logged_in() ) ) {
			return self::refuse( __( 'You are not allowed to change themes.', 'activity-log-with-undo' ) );
		}
		if ( ! Siblings::smoke_rollback() ) {
			return self::refuse( __( 'WordPress keeps no copy of the old version. Axle Smoke Test saves one before each update; activate it to reverse updates.', 'activity-log-with-undo' ) );
		}
		if ( null === Siblings::smoke_backup( 'theme', $entry->object_id, $old ) ) {
			/* translators: %s: version number. */
			return self::refuse( sprintf( __( 'No copy of version %s is saved any more, so the update cannot be reversed here.', 'activity-log-with-undo' ), $old ) );
		}

		return parent::can_undo( $entry );
	}

	/**
	 * Puts the files of the old version back, through Axle Smoke Test.
	 *
	 * @param Entry $entry Entry.
	 * @return true|\WP_Error
	 */
	private function undo_update( Entry $entry ) {
		$old      = (string) ( ( (array) $entry->before() )['version'] ?? '' );
		$backup   = Siblings::smoke_backup( 'theme', $entry->object_id, $old );
		$rollback = Siblings::smoke_rollback();
		if ( ! $backup || ! $rollback || ! method_exists( $rollback, 'restore' ) ) {
			return $this->can_undo_update( $entry );
		}

		$result = $rollback->restore(
			array(
				'type'    => 'theme',
				'id'      => $entry->object_id,
				'slug'    => $entry->object_id,
				'name'    => $entry->object_label,
				'current' => (string) wp_get_theme( $entry->object_id )->get( 'Version' ),
				'version' => $old,
				'backup'  => $backup,
			)
		);
		if ( function_exists( 'wp_clean_themes_cache' ) ) {
			wp_clean_themes_cache( true );
		}

		return $result instanceof \WP_Error ? $result : true;
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
				$text = sprintf( __( 'Updated the %s theme', 'activity-log-with-undo' ), $name );
				$old  = (string) ( ( (array) $entry->before() )['version'] ?? '' );
				$new  = (string) ( ( (array) $entry->after() )['version'] ?? '' );
				if ( '' !== $old && '' !== $new ) {
					/* translators: 1: theme description, 2: old version, 3: new version. */
					$text = sprintf( __( '%1$s from %2$s to %3$s', 'activity-log-with-undo' ), $text, $old, $new );
				}

				return $text;
			default:
				/* translators: %s: theme folder. */
				return sprintf( __( 'Deleted the %s theme', 'activity-log-with-undo' ), $name );
		}
	}
}
