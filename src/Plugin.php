<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\ActivityLogWithUndo
 */

namespace AryanX\ActivityLogWithUndo;

use AryanX\ActivityLogWithUndo\Admin\AdminBar;
use AryanX\ActivityLogWithUndo\Admin\LogPage;
use AryanX\ActivityLogWithUndo\Admin\Notices;
use AryanX\ActivityLogWithUndo\Cli\Command;
use AryanX\ActivityLogWithUndo\Health\SiteHealth;
use AryanX\ActivityLogWithUndo\Integrations\CategoryTagTrash;
use AryanX\ActivityLogWithUndo\Recorders\MenusRecorder;
use AryanX\ActivityLogWithUndo\Recorders\NetworkRecorder;
use AryanX\ActivityLogWithUndo\Recorders\OptionsRecorder;
use AryanX\ActivityLogWithUndo\Recorders\PluginsRecorder;
use AryanX\ActivityLogWithUndo\Recorders\PostsRecorder;
use AryanX\ActivityLogWithUndo\Recorders\Registry;
use AryanX\ActivityLogWithUndo\Recorders\SiteEditorRecorder;
use AryanX\ActivityLogWithUndo\Recorders\TermsRecorder;
use AryanX\ActivityLogWithUndo\Recorders\ThemesRecorder;
use AryanX\ActivityLogWithUndo\Recorders\UsersRecorder;
use AryanX\ActivityLogWithUndo\Rest\UndoController;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the shared objects once and registers their hooks.
 */
final class Plugin {

	/**
	 * The running instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Entries.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Where changes come from.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * Writes entries.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Recorders.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Reverses changes.
	 *
	 * @var Undoer
	 */
	private Undoer $undoer;

	/**
	 * Deletes old entries.
	 *
	 * @var Retention
	 */
	private Retention $retention;

	/**
	 * Starts the plugin. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}
	}

	/**
	 * The running instance.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Builds the shared objects.
	 */
	private function __construct() {
		$this->settings = new Settings();
		$this->store    = new Store();
		$this->context  = new Context( $this->settings );
		$this->logger   = new Logger( $this->store, $this->settings, $this->context );
		$recorders      = array(
			new OptionsRecorder( $this->logger ),
			new PluginsRecorder( $this->logger ),
			new ThemesRecorder( $this->logger ),
			new UsersRecorder( $this->logger ),
			new PostsRecorder( $this->logger ),
			new TermsRecorder( $this->logger, new CategoryTagTrash() ),
			new MenusRecorder( $this->logger ),
			new SiteEditorRecorder( $this->logger ),
		);
		if ( is_multisite() ) {
			$recorders[] = new NetworkRecorder( $this->logger );
		}
		$this->registry  = new Registry( $recorders, $this->settings );
		$this->undoer    = new Undoer( $this->store, $this->logger, $this->registry );
		$this->retention = new Retention( $this->store, $this->settings );
	}

	/**
	 * Entries, for other plugins.
	 *
	 * @return Store
	 */
	public function store(): Store {
		return $this->store;
	}

	/**
	 * Writes entries, for custom recorders.
	 *
	 * @return Logger
	 */
	public function logger(): Logger {
		return $this->logger;
	}

	/**
	 * Undo, for other plugins.
	 *
	 * @return Undoer
	 */
	public function undoer(): Undoer {
		return $this->undoer;
	}

	/**
	 * Recorders.
	 *
	 * @return Registry
	 */
	public function recorders(): Registry {
		return $this->registry;
	}

	/**
	 * Registers the services.
	 */
	private function register(): void {
		Schema::maybe_install();

		$abilities = new Abilities( $this->store, $this->registry, $this->undoer );
		$services  = array(
			$this->context,
			$this->registry,
			$this->retention,
			new Privacy( $this->store, $this->registry ),
			new LogPage( $this->store, $this->registry, $this->undoer, $this->settings, $this->retention ),
			new Notices(),
			new AdminBar( $this->store, $this->registry, $this->settings ),
			new UndoController( $this->undoer ),
			new SiteHealth( $this->settings ),
			$abilities,
		);

		foreach ( $services as $service ) {
			$service->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'activity-log-with-undo', new Command( $this->store, $this->registry, $this->undoer, $this->retention, $this->settings, $abilities ) );
		}
	}
}
