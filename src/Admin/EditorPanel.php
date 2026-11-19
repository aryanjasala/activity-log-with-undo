<?php
/**
 * "Activity on this post" in the block editor sidebar.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo\Admin;

use AryanX\Axle\ActivityLogWithUndo\Recorders\PostsRecorder;
use AryanX\Axle\ActivityLogWithUndo\Recorders\Registry;
use AryanX\Axle\ActivityLogWithUndo\Rest\PostActivityController;
use AryanX\Axle\ActivityLogWithUndo\Rest\UndoController;
use AryanX\Axle\ActivityLogWithUndo\Service;
use const AryanX\Axle\ActivityLogWithUndo\PLUGIN_FILE;
use const AryanX\Axle\ActivityLogWithUndo\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the panel script in the post editor. The panel lists the changes
 * revisions do not keep, with who, when and Undo.
 */
final class EditorPanel implements Service {

	/**
	 * Recorders, to know which post types are logged.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Sets up the panel.
	 *
	 * @param Registry $registry Recorders.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Adds the hook.
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Loads the script on post editor screens for logged post types.
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$posts  = $this->registry->get( 'posts' );
		if ( ! $screen || 'post' !== $screen->base || ! $posts instanceof PostsRecorder || ! $posts->covers( (string) $screen->post_type ) ) {
			return;
		}

		/**
		 * Filters whether the editor shows the "Activity on this post" panel.
		 *
		 * @param bool   $show      Default true for logged post types.
		 * @param string $post_type Post type being edited.
		 */
		if ( ! apply_filters( 'activity_log_with_undo_show_post_panel', true, (string) $screen->post_type ) ) {
			return;
		}

		wp_enqueue_script(
			'activity-log-with-undo-editor',
			plugins_url( 'assets/editor.js', PLUGIN_FILE ),
			array( 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-core-data', 'wp-api-fetch', 'wp-i18n', 'wp-a11y', 'wp-hooks', 'wp-date' ),
			VERSION,
			true
		);
		wp_set_script_translations( 'activity-log-with-undo-editor', 'activity-log-with-undo' );
		wp_localize_script(
			'activity-log-with-undo-editor',
			'activityLogWithUndoPanel',
			array(
				'namespace' => UndoController::NAMESPACE,
				'limit'     => PostActivityController::limit(),
				'logUrl'    => current_user_can( LogPage::capability() ) ? LogPage::url() : '',
			)
		);
	}
}
