const { test, expect, wp, wpEval, logInAs } = require( './utils' );
const { PASSWORD } = require( './utils/config' );

const PAGE = '/wp-admin/tools.php?page=activity-log-with-undo';
const ROLLBACK = `${ PAGE }&tab=rollback`;
const FIXTURE = 'alu-e2e-fixture.php';

const reset = () => {
	for ( const name of [ 'activity_log_with_undo_settings', 'activity_log_with_undo_rollback_job', 'activity_log_with_undo_rollback_lock', 'alu_e2e_log_cap' ] ) {
		try {
			wp( 'option', 'delete', name );
		} catch ( error ) {
			// Not set.
		}
	}
	wpEval( 'AryanX\\Axle\\ActivityLogWithUndo\\Plugin::instance()->store()->truncate();' );
	wp( 'transient', 'delete', '--all' );
};
const entries = () => JSON.parse( wp( 'activity-log-with-undo', 'list', '--format=json', '--fields=id,event,object_id,user,via,undoable,undone_by,undo_of,change_set,description' ) );
const option = ( name ) => wp( 'option', 'get', name );
// A change that is not logged, like a plugin that writes straight to the database would make.
const sneaky = ( name, value ) => wpEval( `add_filter( 'activity_log_with_undo_should_log', '__return_false' ); update_option( '${ name }', '${ value }' );` );

test.describe( 'Roll back to a moment', () => {
	test.beforeAll( () => {
		wpEval( `wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( WP_PLUGIN_DIR . '/activity-log-with-undo/tests/e2e/fixtures/${ FIXTURE }', WPMU_PLUGIN_DIR . '/${ FIXTURE }' );` );
	} );

	test.beforeEach( () => {
		wpEval( `update_option( 'blogname', 'E2E' ); update_option( 'blogdescription', 'Just another site' ); update_option( 'posts_per_page', 10 );` );
		reset();
	} );

	test.afterAll( () => {
		reset();
		wpEval( `@unlink( WPMU_PLUGIN_DIR . '/${ FIXTURE }' );` );
	} );

	test( 'previews, runs and redoes a roll back from the screen', async ( { page } ) => {
		wp( 'option', 'update', 'blogname', 'First' );
		const anchor = entries()[ 0 ];
		wp( 'option', 'update', 'blogdescription', 'Second' );
		wp( 'option', 'update', 'posts_per_page', '7' );

		await page.goto( `${ ROLLBACK }&rb_choice=entry&rb_entry=${ anchor.id }&rb_go=1` );
		await expect( page.locator( '.alu-stats .axle-stat--good .axle-stat__value' ) ).toHaveText( '3' );
		const rows = page.locator( 'table.alu-plan tbody tr' );
		await expect( rows ).toHaveCount( 3 );
		await expect( rows.first() ).toContainText( 'Changed Blog pages show at most' );
		await expect( rows.first() ).toContainText( 'Will be undone' );
		// Nothing has happened yet.
		expect( option( 'blogname' ) ).toBe( 'First' );

		await page.getByRole( 'button', { name: 'Roll back 3 changes' } ).click();
		await expect( page.locator( '.alu-notice' ).first() ).toContainText( 'Rolled back 3 changes.' );

		expect( option( 'blogname' ) ).toBe( 'E2E' );
		expect( option( 'blogdescription' ) ).toBe( 'Just another site' );
		expect( option( 'posts_per_page' ) ).toBe( '10' );
		const summary = entries().find( ( entry ) => 'rollback' === entry.event );
		expect( summary ).toMatchObject( { undoable: true, undone_by: null } );
		// The reversals share the roll back's change set, so one Redo puts them all back.
		const undos = entries().filter( ( entry ) => entry.undo_of );
		expect( undos ).toHaveLength( 3 );
		expect( new Set( [ summary, ...undos ].map( ( entry ) => entry.change_set ) ).size ).toBe( 1 );
	} );

	test( 'the Redo link in the notice puts everything back', async ( { page } ) => {
		wp( 'option', 'update', 'blogname', 'First' );
		const anchor = entries()[ 0 ];
		wp( 'option', 'update', 'blogdescription', 'Second' );

		await page.goto( `${ ROLLBACK }&rb_choice=entry&rb_entry=${ anchor.id }&rb_go=1` );
		await page.getByRole( 'button', { name: 'Roll back 2 changes' } ).click();
		await expect( page.locator( '.alu-notice' ).first() ).toContainText( 'Rolled back 2 changes.' );
		expect( option( 'blogname' ) ).toBe( 'E2E' );

		await page.locator( '.alu-notice a.alu-notice-link', { hasText: 'Redo' } ).click();
		await expect( page.locator( '.alu-notice' ).first() ).toContainText( /Undid 3 changes/ );
		expect( option( 'blogname' ) ).toBe( 'First' );
		expect( option( 'blogdescription' ) ).toBe( 'Second' );
		expect( entries().find( ( entry ) => 'rollback' === entry.event ).undone_by ).not.toBeNull();
	} );

	test( 'a change made again by something not in the list is flagged and skipped', async ( { page } ) => {
		wp( 'option', 'update', 'blogname', 'Mine' );
		const anchor = entries()[ 0 ];
		sneaky( 'blogname', 'Sneaky' );
		wp( 'option', 'update', 'blogdescription', 'Other' );

		await page.goto( `${ ROLLBACK }&rb_choice=entry&rb_entry=${ anchor.id }&rb_go=1` );
		await expect( page.locator( '.alu-stats .axle-stat--warning .axle-stat__value' ) ).toHaveText( '1' );
		await expect( page.locator( 'tr.alu-plan-conflict' ) ).toContainText( 'Changed again by something that is not in this list' );

		await page.getByRole( 'button', { name: 'Roll back 2 changes' } ).click();
		await expect( page.locator( '.alu-notice' ).filter( { hasText: 'left as it is' } ) ).toBeVisible();
		await expect( page.locator( '.alu-notice' ).first() ).toContainText( 'Rolled back 1 change.' );
		expect( option( 'blogname' ) ).toBe( 'Sneaky' );
		expect( option( 'blogdescription' ) ).toBe( 'Just another site' );
	} );

	test( 'a roll back that is stopped at a clash says so', async ( { page } ) => {
		wp( 'option', 'update', 'blogdescription', 'Other' );
		const anchor = entries()[ 0 ];
		wp( 'option', 'update', 'blogname', 'Mine' );
		sneaky( 'blogname', 'Sneaky' );

		await page.goto( `${ ROLLBACK }&rb_choice=entry&rb_entry=${ anchor.id }&rb_go=1` );
		await page.getByLabel( 'Stop at the first change that clashes' ).check();
		await page.getByRole( 'button', { name: 'Roll back 2 changes' } ).click();
		await expect( page.locator( '.alu-notice' ).first() ).toContainText( 'Roll back stopped at a clash after 0 changes were undone.' );
		expect( option( 'blogdescription' ) ).toBe( 'Other' );
	} );

	test( 'an unfinished roll back can be continued or cancelled', async ( { page } ) => {
		wp( 'option', 'update', 'blogname', 'First' );
		const anchor = entries()[ 0 ];
		wp( 'option', 'update', 'blogdescription', 'Second' );
		// A tiny batch, and a browser that cannot reach the REST API, leave the job half done.
		wpEval( `
			$rollback = AryanX\\Axle\\ActivityLogWithUndo\\Plugin::instance()->rollback();
			wp_set_current_user( 1 );
			add_filter( 'activity_log_with_undo_rollback_batch_size', function () { return 1; } );
			$plan = $rollback->plan( array( 'to' => '${ anchor.id }' ) );
			$job = $rollback->start( $plan );
			$rollback->step( $job['id'] );
		` );
		expect( option( 'blogdescription' ) ).toBe( 'Just another site' );
		expect( option( 'blogname' ) ).toBe( 'First' );

		await page.route( '**/activity-log-with-undo/v1/rollback/**', ( route ) => route.abort() );
		await page.goto( ROLLBACK );
		await expect( page.locator( '.alu-job .axle-progress' ) ).toHaveAttribute( 'aria-valuenow', '50' );
		await expect( page.locator( '.alu-job-error' ) ).toContainText( 'press Continue to pick it up where it stopped' );
		await expect( page.locator( '.alu-job' ) ).toHaveAttribute( 'aria-busy', 'false' );

		await page.unroute( '**/activity-log-with-undo/v1/rollback/**' );
		await page.getByRole( 'button', { name: 'Continue' } ).click();
		await expect( page.locator( '.alu-notice' ).first() ).toContainText( 'Rolled back 2 changes.' );
		expect( option( 'blogname' ) ).toBe( 'E2E' );

		// A second job can be cancelled and keeps what it did.
		wp( 'option', 'update', 'blogname', 'Third' );
		wpEval( `
			$rollback = AryanX\\Axle\\ActivityLogWithUndo\\Plugin::instance()->rollback();
			wp_set_current_user( 1 );
			$plan = $rollback->plan( array( 'to' => 'hour' ) );
			$rollback->start( $plan );
		` );
		await page.route( '**/activity-log-with-undo/v1/rollback/**', ( route ) => route.abort() );
		await page.goto( ROLLBACK );
		await page.getByRole( 'button', { name: 'Cancel the roll back' } ).click();
		await expect( page.locator( '.alu-notice' ).first() ).toContainText( 'Roll back cancelled' );
		expect( wp( 'option', 'list', '--search=activity_log_with_undo_rollback_job', '--format=count' ) ).toBe( '0' );
	} );

	test( 'WP-CLI: preview, run and redo', async () => {
		wp( 'option', 'update', 'blogname', 'First' );
		const anchor = entries()[ 0 ];
		wp( 'option', 'update', 'blogdescription', 'Second' );

		const dry = wp( 'activity-log-with-undo', 'rollback', `--to=${ anchor.id }`, '--dry-run' );
		expect( dry ).toContain( '2 to undo, 0 that cannot be undone, 0 that clash with a later change.' );
		expect( option( 'blogname' ) ).toBe( 'First' );

		const ran = wp( 'activity-log-with-undo', 'rollback', `--to=${ anchor.id }`, '--yes' );
		expect( ran ).toContain( 'Rolled back 2 changes.' );
		expect( option( 'blogname' ) ).toBe( 'E2E' );
		expect( option( 'blogdescription' ) ).toBe( 'Just another site' );

		const summary = entries().find( ( entry ) => 'rollback' === entry.event );
		wp( 'activity-log-with-undo', 'undo', String( summary.id ), '--yes' );
		expect( option( 'blogname' ) ).toBe( 'First' );
		expect( option( 'blogdescription' ) ).toBe( 'Second' );

		// Narrowing by area leaves other areas alone.
		const none = wp( 'activity-log-with-undo', 'rollback', `--to=${ anchor.id }`, '--area=posts', '--dry-run' );
		expect( none ).toContain( '0 to undo' );
	} );

	test( 'abilities: preview is read-only, run is destructive and finishes the roll back', async ( { rest } ) => {
		test.skip( 'yes' !== wpEval( "echo function_exists( 'wp_register_ability' ) ? 'yes' : 'no';" ), 'The Abilities API needs WordPress 6.9.' );
		wp( 'option', 'update', 'blogname', 'First' );
		const anchor = entries()[ 0 ];
		wp( 'option', 'update', 'blogdescription', 'Second' );
		const base = '/wp-abilities/v1/abilities/activity-log-with-undo';

		const listed = await rest( 'GET', '/wp-abilities/v1/abilities?category=activity-log-with-undo' );
		const meta = ( name ) => listed.body.find( ( ability ) => ability.name === `activity-log-with-undo/${ name }` ).meta.annotations;
		expect( meta( 'preview-rollback' ) ).toMatchObject( { readonly: true } );
		expect( meta( 'run-rollback' ) ).toMatchObject( { destructive: true, readonly: false } );

		const preview = await rest( 'GET', `${ base }/preview-rollback/run?input[to]=${ anchor.id }` );
		expect( preview.status ).toBe( 200 );
		expect( preview.body.counts ).toMatchObject( { will: 2, blocked: 0, conflict: 0 } );
		expect( preview.body.changes[ 0 ] ).toMatchObject( { status: 'will' } );
		expect( option( 'blogname' ) ).toBe( 'First' );

		const run = await rest( 'POST', `${ base }/run-rollback/run`, { input: { to: String( anchor.id ) } } );
		expect( run.status ).toBe( 200 );
		expect( run.body ).toMatchObject( { state: 'done', undone: 2 } );
		expect( option( 'blogname' ) ).toBe( 'E2E' );
	} );

	test( 'a person who may open the log but not edit what changed does not see it and cannot undo it', async ( { browser } ) => {
		wp( 'user', 'create', 'alu_author', 'alu_author@example.com', '--role=author', `--user_pass=${ PASSWORD }` );
		try {
			const ownPost = wp( 'post', 'create', '--post_title=Mine', '--post_status=draft', '--post_author=' + wp( 'user', 'get', 'alu_author', '--field=ID' ), '--porcelain' );
			wp( 'option', 'update', 'blogdescription', 'Hidden change' );
			wp( 'post', 'update', ownPost, '--post_title=Mine, edited', '--user=alu_author' );
			const setting = entries().find( ( entry ) => 'option_updated' === entry.event );
			wp( 'option', 'update', 'alu_e2e_log_cap', 'edit_posts' );

			const { context, page } = await logInAs( browser, 'alu_author', PASSWORD );
			await page.goto( PAGE );
			const list = page.locator( '#the-list' );
			await expect( list ).toContainText( 'A change to something you are not allowed to see' );
			await expect( list ).not.toContainText( 'Tagline' );
			await expect( list ).not.toContainText( 'Hidden change' );
			await expect( list ).toContainText( 'Mine, edited' );
			await expect( list.locator( `#alu-entry-${ setting.id }` ) ).toHaveCount( 0 );

			await page.goto( `${ PAGE }&entry=${ setting.id }` );
			await expect( page.locator( '.alu-hidden' ) ).toContainText( 'not allowed to see or edit' );
			await expect( page.locator( 'body' ) ).not.toContainText( 'Hidden change' );

			// The REST route refuses too, and nothing changes.
			const refused = await page.evaluate( ( id ) => window.wp.apiFetch( { path: `/activity-log-with-undo/v1/entries/${ id }/undo`, method: 'POST', data: {} } ).then( () => 'undone', ( error ) => error.code ), setting.id );
			expect( refused ).toBe( 'activity_log_with_undo_forbidden' );
			expect( option( 'blogdescription' ) ).toBe( 'Hidden change' );

			// A roll back marks it as not theirs to undo.
			await page.goto( `${ ROLLBACK }&rb_choice=entry&rb_entry=${ setting.id }&rb_go=1` );
			await expect( page.locator( 'tr.alu-plan-blocked' ) ).toContainText( 'You are not allowed to change this.' );
			await expect( page.locator( 'body' ) ).not.toContainText( 'Hidden change' );
			await context.close();
		} finally {
			wp( 'user', 'delete', 'alu_author', '--yes', '--reassign=1' );
		}
	} );
} );
