const { test, expect, wp, wpEval, logInAs } = require( './utils' );
const { PASSWORD } = require( './utils/config' );

const PAGE = '/wp-admin/tools.php?page=activity-log-with-undo';
const ALERTS = `${ PAGE }&tab=alerts`;
const FIXTURE = 'alu-e2e-fixture.php';
const HOOK = 'https://hooks.slack.com/services/T000/B000/SECRETSECRET';

const reset = () => {
	for ( const name of [ 'activity_log_with_undo_alert_rules', 'activity_log_with_undo_alert_secrets', 'activity_log_with_undo_alert_state', 'activity_log_with_undo_settings', 'alu_e2e_mails', 'alu_e2e_posts', 'alu_e2e_webhook_code', 'alu_e2e_capture' ] ) {
		try {
			wp( 'option', 'delete', name );
		} catch ( error ) {
			// Not set.
		}
	}
	wpEval( 'AryanX\\Axle\\ActivityLogWithUndo\\Plugin::instance()->store()->truncate();' );
	wp( 'transient', 'delete', '--all' );
};
const rules = () => JSON.parse( wp( 'activity-log-with-undo', 'alerts', 'list', '--format=json' ) );
const mails = () => JSON.parse( wp( 'option', 'get', 'alu_e2e_mails', '--format=json' ) );
const posts = () => JSON.parse( wp( 'option', 'get', 'alu_e2e_posts', '--format=json' ) );
const capture = () => wp( 'option', 'update', 'alu_e2e_capture', '1' );
const addRule = ( name, ...extra ) => wp( 'activity-log-with-undo', 'alerts', 'add', name, '--events=option_updated', '--objects=blogname', '--porcelain', ...extra );

test.describe( 'Alerts and the daily digest', () => {
	let adminEmail = 'admin@example.com';

	test.beforeAll( () => {
		adminEmail = wp( 'user', 'get', 'admin', '--field=user_email' );
		wpEval( `wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( WP_PLUGIN_DIR . '/activity-log-with-undo/tests/e2e/fixtures/${ FIXTURE }', WPMU_PLUGIN_DIR . '/${ FIXTURE }' );` );
	} );

	test.beforeEach( () => {
		wpEval( `update_option( 'blogname', 'E2E' ); update_option( 'admin_email', 'admin@example.test' ); wp_update_user( array( 'ID' => 1, 'user_email' => 'admin@example.test' ) );` );
		reset();
	} );

	test.afterAll( () => {
		reset();
		wpEval( `wp_update_user( array( 'ID' => 1, 'user_email' => '${ adminEmail }' ) );` );
		wpEval( `@unlink( WPMU_PLUGIN_DIR . '/${ FIXTURE }' );` );
	} );

	test( 'the empty tab offers the starter rules, added turned off', async ( { page } ) => {
		await page.goto( ALERTS );
		await expect( page.locator( '.axle-empty' ) ).toContainText( 'no alert rules yet' );
		await expect( page.locator( '.alu-starters li' ) ).toHaveCount( 6 );

		await page.locator( '.alu-starters li', { hasText: 'A plugin is deactivated or deleted' } ).getByRole( 'button', { name: 'Add this rule' } ).click();
		await expect( page.locator( '.alu-notice' ).first() ).toContainText( 'Added 1 starter rule. It is off until you turn it on.' );

		const [ rule ] = rules();
		expect( rule ).toMatchObject( { id: 'plugin-removed', enabled: 'no', events: 'plugin_deactivated,plugin_deleted' } );
		await expect( page.locator( '.alu-starters li' ) ).toHaveCount( 5 );
	} );

	test( 'a bulk change is one message, with no undo link and no secret', async () => {
		capture();
		const id = addRule( 'Site title', '--emails=admin@example.test', '--webhook=' + HOOK, '--format=slack', '--enable' );
		expect( rules()[ 0 ].webhook ).toBe( 'hooks.slack.com' );

		wp( 'eval', "update_option( 'blogname', 'One' ); update_option( 'blogname', 'Two' );" );

		expect( mails() ).toHaveLength( 1 );
		expect( mails()[ 0 ].subject ).toContain( 'Alert: Site title' );
		expect( mails()[ 0 ].message ).toContain( 'Open it in the log' );
		expect( mails()[ 0 ].message ).toContain( 'no undo link' );
		expect( mails()[ 0 ].message ).not.toContain( 'SECRETSECRET' );
		expect( posts() ).toHaveLength( 1 );
		expect( posts()[ 0 ].url ).toBe( HOOK );
		expect( posts()[ 0 ].body ).not.toContain( 'SECRETSECRET' );
		expect( JSON.parse( posts()[ 0 ].body ).text ).toContain( 'Site title' );
		expect( wp( 'activity-log-with-undo', 'alerts', 'list', '--format=json' ) ).not.toContain( 'SECRETSECRET' );
		expect( id ).toBeTruthy();
	} );

	test( 'a person who may not edit the object reads a masked message', async () => {
		capture();
		addRule( 'Site title', '--emails=outsider@example.net,admin@example.test', '--enable' );

		wp( 'option', 'update', 'blogname', 'Changed' );

		const sent = mails();
		expect( sent ).toHaveLength( 2 );
		const outsider = sent.find( ( mail ) => 'outsider@example.net' === mail.to );
		const admin = sent.find( ( mail ) => 'admin@example.test' === mail.to );
		expect( outsider.message ).toContain( 'not allowed to see' );
		expect( outsider.message ).not.toContain( 'Changed' );
		expect( outsider.message ).not.toContain( 'Open it in the log' );
		expect( admin.message ).toContain( 'Open it in the log' );
		expect( admin.message ).toContain( 'After: Changed' );
	} );

	test( 'a rule that just sent holds the next change, and a daily rule waits for the digest', async () => {
		capture();
		addRule( 'Instant', '--emails=admin@example.test', '--enable' );
		addRule( 'Daily', '--emails=admin@example.test', '--mode=daily', '--enable' );

		wp( 'option', 'update', 'blogname', 'First' );
		wp( 'option', 'update', 'blogname', 'Second' );

		// One instant message for the first change. The second is held, and the daily rule queued both.
		expect( mails().map( ( mail ) => mail.subject ) ).toHaveLength( 1 );
		const state = JSON.parse( wp( 'option', 'get', 'activity_log_with_undo_alert_state', '--format=json' ) );
		const queued = Object.values( state.queue ).map( ( queue ) => Object.keys( queue ).join() ).sort();
		expect( queued ).toEqual( [ 'daily', 'held' ] );

		wp( 'activity-log-with-undo', 'alerts', 'digest' );
		const subjects = mails().map( ( mail ) => mail.subject );
		expect( subjects ).toHaveLength( 2 );
		expect( subjects[ 1 ] ).toContain( 'Daily digest, Daily: 2 changes' );
	} );

	test( 'only outside working hours: a change on a working day is not sent', async () => {
		capture();
		addRule( 'After hours', '--emails=admin@example.test', '--outside-hours', '--enable' );
		wpEval( `update_option( 'activity_log_with_undo_settings', array( 'work_days' => array( 1, 2, 3, 4, 5, 6, 7 ), 'work_start' => '00:00', 'work_end' => '23:59' ) );` );

		wp( 'option', 'update', 'blogname', 'Busy time' );

		expect( wp( 'eval', "echo count( (array) get_option( 'alu_e2e_mails', array() ) );" ) ).toBe( '0' );
	} );

	test( 'the screen sends a test, turns a rule off, and never prints the webhook URL', async ( { page } ) => {
		capture();
		const id = addRule( 'Site title', '--emails=admin@example.test', '--webhook=' + HOOK, '--enable' );

		await page.goto( ALERTS );
		const row = page.locator( 'table.alu-alert-rules tbody tr' ).first();
		await expect( row ).toContainText( 'Webhook (JSON) to hooks.slack.com' );
		await expect( row ).toContainText( 'Never' );
		expect( await page.content() ).not.toContain( 'SECRETSECRET' );

		await row.getByRole( 'button', { name: 'Send a test' } ).click();
		await expect( page.locator( '.alu-notice' ).first() ).toContainText( 'Sent a test to admin@example.test, hooks.slack.com.' );
		expect( mails()[ 0 ].subject ).toContain( 'Test of the alert "Site title"' );
		expect( JSON.parse( posts()[ 0 ].body ).kind ).toBe( 'test' );

		await page.locator( 'table.alu-alert-rules tbody tr' ).first().getByRole( 'button', { name: 'Turn off' } ).click();
		await expect( page.locator( '.alu-notice' ).first() ).toContainText( 'Turned off "Site title"' );
		expect( rules().find( ( rule ) => id === rule.id ).enabled ).toBe( 'no' );

		await page.goto( `${ ALERTS }&rule=${ id }` );
		await expect( page.locator( '#alu-rule-webhook' ) ).toHaveValue( '' );
		await expect( page.locator( '#alu-webhook-note' ) ).toContainText( 'hooks.slack.com' );
		expect( await page.content() ).not.toContain( 'SECRETSECRET' );
	} );

	test( 'a webhook that fails says so, and Site Health reports it', async ( { page } ) => {
		capture();
		wp( 'option', 'update', 'alu_e2e_webhook_code', '404' );
		const id = addRule( 'Hook only', '--skip-email', '--webhook=' + HOOK, '--enable' );

		await page.goto( ALERTS );
		await page.locator( 'table.alu-alert-rules tbody tr' ).first().getByRole( 'button', { name: 'Send a test' } ).click();
		await expect( page.locator( '.alu-notice-error, .notice-error' ).first() ).toContainText( 'The webhook answered with HTTP 404' );
		await expect( page.locator( '.alu-notice' ).first() ).not.toContainText( 'SECRETSECRET' );
		await page.goto( ALERTS );
		await expect( page.locator( 'table.alu-alert-rules' ) ).toContainText( 'Failed' );
		expect( id ).toBeTruthy();

		const health = wp( 'eval', 'echo json_encode( ( new AryanX\\Axle\\ActivityLogWithUndo\\Health\\SiteHealth( new AryanX\\Axle\\ActivityLogWithUndo\\Settings(), null, null, AryanX\\Axle\\ActivityLogWithUndo\\Plugin::instance()->alert_rules() ) )->run() );' );
		expect( JSON.parse( health ).label ).toBe( 'Some alerts are not getting through' );
	} );

	test( 'creates a rule in the form and deletes it with a confirm', async ( { page } ) => {
		await page.goto( `${ ALERTS }&rule=new` );
		await page.getByLabel( 'Name', { exact: true } ).fill( 'Plugin changes' );
		await page.getByLabel( 'Plugin deactivated' ).check();
		await page.getByLabel( 'Email addresses' ).fill( 'ops@example.test' );
		await page.getByLabel( 'Send alerts for this rule' ).check();
		await page.getByRole( 'button', { name: 'Save rule' } ).click();
		await expect( page.locator( '.alu-notice' ).first() ).toContainText( 'Saved the alert rule "Plugin changes".' );

		const [ rule ] = rules();
		expect( rule ).toMatchObject( { name: 'Plugin changes', enabled: 'yes', events: 'plugin_deactivated', email: 'yes' } );

		await page.goto( `${ ALERTS }&rule=${ rule.id }` );
		await page.getByRole( 'button', { name: 'Delete this rule', exact: true } ).click();
		await expect( page.locator( '#alu-alert-delete-confirm' ) ).toContainText( 'cannot be undone' );
		expect( rules() ).toHaveLength( 1 );
		await page.getByRole( 'button', { name: 'Delete the rule' } ).click();
		await expect( page.locator( '.alu-notice' ).first() ).toContainText( 'Deleted the alert rule "Plugin changes"' );
		expect( rules() ).toHaveLength( 0 );
	} );

	test( 'a webhook that is not https is refused', async ( { page } ) => {
		await page.goto( `${ ALERTS }&rule=new` );
		await page.getByLabel( 'Name', { exact: true } ).fill( 'Bad hook' );
		await page.getByLabel( 'Send to a webhook' ).check();
		await page.locator( '#alu-rule-webhook' ).fill( 'http://hooks.slack.com/x' );
		await page.getByRole( 'button', { name: 'Save rule' } ).click();
		await expect( page.locator( '.alu-notice' ).first() ).toContainText( 'The webhook URL is not valid' );
		expect( rules() ).toHaveLength( 0 );
	} );

	test( 'someone without the log capability cannot reach the alert handlers', async ( { browser } ) => {
		wpEval( `if ( ! username_exists( 'alu_editor' ) ) { wp_insert_user( array( 'user_login' => 'alu_editor', 'user_pass' => '${ PASSWORD }', 'user_email' => 'alu_editor@example.test', 'role' => 'editor' ) ); }` );
		const { context, page } = await logInAs( browser, 'alu_editor', PASSWORD );
		const response = await page.goto( ALERTS );
		expect( response.status() ).toBe( 403 );
		await context.close();
	} );

	test( 'the abilities list rules without any URL', async () => {
		addRule( 'Hooked', '--emails=admin@example.test', '--webhook=' + HOOK, '--enable' );
		const out = wp( 'eval', 'wp_set_current_user( 1 ); echo wp_json_encode( wp_get_ability( "activity-log-with-undo/list-alert-rules" )->execute( array() ) );' );
		expect( out ).not.toContain( 'SECRETSECRET' );
		expect( out ).not.toContain( 'admin@example.test' );
		expect( JSON.parse( out )[ 0 ] ).toMatchObject( { name: 'Hooked', webhook: true } );
	} );
} );
