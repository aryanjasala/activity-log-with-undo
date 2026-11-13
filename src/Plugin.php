<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\Axle\ActivityLogWithUndo
 */

namespace AryanX\Axle\ActivityLogWithUndo;

use AryanX\Axle\ActivityLogWithUndo\Admin\AdminBar;
use AryanX\Axle\ActivityLogWithUndo\Admin\AlertsScreen;
use AryanX\Axle\ActivityLogWithUndo\Alerts\AlertPrivacy;
use AryanX\Axle\ActivityLogWithUndo\Alerts\Alerts;
use AryanX\Axle\ActivityLogWithUndo\Alerts\Delivery;
use AryanX\Axle\ActivityLogWithUndo\Alerts\Matcher;
use AryanX\Axle\ActivityLogWithUndo\Alerts\Message;
use AryanX\Axle\ActivityLogWithUndo\Alerts\Rules;
use AryanX\Axle\ActivityLogWithUndo\Admin\LogPage;
use AryanX\Axle\ActivityLogWithUndo\Admin\Notices;
use AryanX\Axle\ActivityLogWithUndo\Cli\AlertsCommand;
use AryanX\Axle\ActivityLogWithUndo\Cli\Command;
use AryanX\Axle\ActivityLogWithUndo\Health\SiteHealth;
use AryanX\Axle\ActivityLogWithUndo\Integrations\CategoryTagTrash;
use AryanX\Axle\ActivityLogWithUndo\Recorders\MenusRecorder;
use AryanX\Axle\ActivityLogWithUndo\Recorders\NetworkRecorder;
use AryanX\Axle\ActivityLogWithUndo\Recorders\OptionsRecorder;
use AryanX\Axle\ActivityLogWithUndo\Recorders\PluginsRecorder;
use AryanX\Axle\ActivityLogWithUndo\Recorders\PostsRecorder;
use AryanX\Axle\ActivityLogWithUndo\Recorders\Registry;
use AryanX\Axle\ActivityLogWithUndo\Recorders\RollbackRecorder;
use AryanX\Axle\ActivityLogWithUndo\Recorders\SiteEditorRecorder;
use AryanX\Axle\ActivityLogWithUndo\Recorders\TermsRecorder;
use AryanX\Axle\ActivityLogWithUndo\Recorders\ThemesRecorder;
use AryanX\Axle\ActivityLogWithUndo\Recorders\UsersRecorder;
use AryanX\Axle\ActivityLogWithUndo\Rest\UndoController;

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
	 * Rolls the site back to a moment.
	 *
	 * @var Rollback
	 */
	private Rollback $rollback;

	/**
	 * Deletes old entries.
	 *
	 * @var Retention
	 */
	private Retention $retention;

	/**
	 * Deletes entries of options that are not logged any more.
	 *
	 * @var Cleanup
	 */
	private Cleanup $cleanup;

	/**
	 * Alert rules.
	 *
	 * @var Rules
	 */
	private Rules $alert_rules;

	/**
	 * Sends alerts and digests.
	 *
	 * @var Alerts
	 */
	private Alerts $alerts;

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
		$options        = new OptionsRecorder( $this->logger );
		$recorders      = array(
			$options,
			new PluginsRecorder( $this->logger ),
			new ThemesRecorder( $this->logger ),
			new UsersRecorder( $this->logger ),
			new PostsRecorder( $this->logger ),
			new TermsRecorder( $this->logger, new CategoryTagTrash() ),
			new MenusRecorder( $this->logger ),
			new SiteEditorRecorder( $this->logger ),
			new RollbackRecorder( $this->logger ),
		);
		if ( is_multisite() ) {
			$recorders[] = new NetworkRecorder( $this->logger );
		}
		$this->registry  = new Registry( $recorders, $this->settings );
		$this->undoer    = new Undoer( $this->store, $this->logger, $this->registry );
		$this->rollback  = new Rollback( $this->store, $this->logger, $this->undoer, $this->registry );
		$this->retention = new Retention( $this->store, $this->settings, $this->rollback );
		$this->cleanup   = new Cleanup( $this->store, $options );

		$this->alert_rules = new Rules();
		$this->alerts      = new Alerts( $this->store, $this->settings, $this->alert_rules, new Matcher( $this->settings ), new Message( $this->registry ), new Delivery() );
	}

	/**
	 * Deactivation: stops the background jobs.
	 */
	public static function deactivate(): void {
		Retention::unschedule();
		Alerts::unschedule();
		delete_option( Rollback::JOB_OPTION );
		delete_option( Rollback::LOCK_OPTION );
		wp_clear_scheduled_hook( Cleanup::HOOK );
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
	 * Roll backs, for other plugins.
	 *
	 * @return Rollback
	 */
	public function rollback(): Rollback {
		return $this->rollback;
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
	 * Alerts, for other plugins.
	 *
	 * @return Alerts
	 */
	public function alerts(): Alerts {
		return $this->alerts;
	}

	/**
	 * Alert rules, for other plugins.
	 *
	 * @return Rules
	 */
	public function alert_rules(): Rules {
		return $this->alert_rules;
	}

	/**
	 * Registers the services.
	 */
	private function register(): void {
		Schema::maybe_install();

		$abilities = new Abilities( $this->store, $this->registry, $this->undoer, $this->rollback, $this->alert_rules, $this->alerts );
		$screen    = new AlertsScreen( $this->alert_rules, $this->alerts, $this->registry, $this->settings );
		$services  = array(
			$this->context,
			$this->registry,
			$this->retention,
			$this->cleanup,
			new Privacy( $this->store, $this->registry ),
			new LogPage( $this->store, $this->registry, $this->undoer, $this->settings, $this->retention, $this->rollback, $screen ),
			$screen,
			$this->alerts,
			new AlertPrivacy( $this->alert_rules ),
			new Notices(),
			new AdminBar( $this->store, $this->registry, $this->settings ),
			new UndoController( $this->undoer, $this->rollback ),
			new SiteHealth( $this->settings, $this->store, $this->rollback, $this->alert_rules ),
			$abilities,
		);

		foreach ( $services as $service ) {
			$service->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'activity-log-with-undo', new Command( $this->store, $this->registry, $this->undoer, $this->retention, $this->settings, $abilities, $this->cleanup, $this->rollback ) );
			\WP_CLI::add_command( 'activity-log-with-undo alerts', new AlertsCommand( $this->alert_rules, $this->alerts ) );
		}
	}
}
