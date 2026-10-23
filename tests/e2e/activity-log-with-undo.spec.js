const { request: playwrightRequest } = require( '@playwright/test' );
const { test, expect, wp, wpEval } = require( './utils' );
const { BASE_URL } = require( './utils/config' );

const PAGE = '/wp-admin/tools.php?page=activity-log-with-undo';
const HELPER = 'alu-e2e-helper.php';
const FIXTURE = 'alu-e2e-fixture.php';
const FIXTURE_OPTIONS = [ 'alu_e2e_rate', 'alu_e2e_early', 'alu_e2e_freeze', 'alu_e2e_always', 'alu_e2e_internal', 'alu_e2e_index', 'alu_e2e_registered', 'alu_e2e_content_version', 'activity_log_with_undo_cleanup' ];

// Empties the log, the settings and the rate-limit counters, so each test starts from nothing.
const reset = () => {
	for ( const name of [ 'activity_log_with_undo_settings', ...FIXTURE_OPTIONS ] ) {
		try {
			wp( 'option', 'delete', name );
		} catch ( error ) {
			// Not set.
		}
	}
	wpEval( 'AryanX\\ActivityLogWithUndo\\Plugin::instance()->store()->truncate();' );
	wp( 'transient', 'delete', '--all' );
};
const sql = ( query ) => wpEval( `global $wpdb; echo wp_json_encode( $wpdb->get_results( ${ JSON.stringify( query ) }, ARRAY_N ) );` );
const count = ( where ) => Number( JSON.parse( sql( `SELECT COUNT(*) FROM wp_activity_log_with_undo WHERE ${ where }` ) )[ 0 ][ 0 ] );
// Writes an entry the way version 1 did, straight into the table.
const legacyEntry = ( fields ) =>
	wpEval( `
		global $wpdb;
		$row = array_merge( array( 'created_gmt' => gmdate( 'Y-m-d H:i:s' ), 'recorder' => 'options', 'event' => 'option_updated', 'object_type' => 'option', 'actor_user_id' => 1, 'actor_via' => 'admin', 'undoable' => 1 ), json_decode( ${ JSON.stringify( JSON.stringify( fields ) ) }, true ) );
		// Every column as a string: WordPress would otherwise treat object_id as a number.
		$wpdb->insert( $wpdb->prefix . 'activity_log_with_undo', $row, array_fill( 0, count( $row ), '%s' ) );
		wp_cache_set_last_changed( 'activity_log_with_undo' );
		echo $wpdb->insert_id;
	` );

// Every entry, newest first, straight from the database through WP-CLI.
const entries = () => JSON.parse( wp( 'activity-log-with-undo', 'list', '--format=json', '--fields=id,event,object_id,object_label,user,via,undoable,undone_by,undo_of,change_set,description' ) );
const latest = ( event ) => entries().find( ( entry ) => entry.event === event );
const option = ( name ) => wp( 'option', 'get', name );

// Clicks Undo (or Redo) for an entry on the log screen and waits for the result.
async function undoOnScreen( page, id, notice = /Undid 1 change/ ) {
	await page.goto( PAGE );
	await page.locator( `a.alu-undo[data-alu-entry="${ id }"]` ).click();
	await expect( page.locator( '.alu-notice' ).first() ).toContainText( notice );
}

test.describe( 'Activity Log with Undo', () => {
	test.beforeAll( () => {
		// A small plugin to switch on and off.
		wpEval( `file_put_contents( WP_PLUGIN_DIR . '/${ HELPER }', "<?php\\n/**\\n * Plugin Name: ALU E2E Helper\\n */\\n" );` );
		// Test-only hooks: an always-logged option, a visitor's page view, a write refused like a freeze.
		wpEval( `wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( WP_PLUGIN_DIR . '/activity-log-with-undo/tests/e2e/fixtures/${ FIXTURE }', WPMU_PLUGIN_DIR . '/${ FIXTURE }' );` );
	} );

	test.beforeEach( async ( { page } ) => {
		// Known starting values for the options the tests change.
		wpEval( `update_option( 'blogname', 'E2E' ); update_option( 'blogdescription', 'Just another site' ); update_option( 'posts_per_page', 10 );` );
		reset();
		page.on( 'dialog', ( dialog ) => dialog.accept() );
	} );

	test.afterAll( () => {
		reset();
		wpEval( `@unlink( WP_PLUGIN_DIR . '/${ HELPER }' ); @unlink( WPMU_PLUGIN_DIR . '/${ FIXTURE }' );` );
	} );

	test( 'a settings change shows up in the log, and Undo puts the old title back', async ( { page } ) => {
		await page.goto( '/wp-admin/options-general.php' );
		await page.locator( '#blogname' ).fill( 'Kitchen Notes' );
		await page.locator( '#submit' ).click();
		await expect( page.locator( '#setting-error-settings_updated' ) ).toBeVisible();

		const entry = latest( 'option_updated' );
		expect( entry ).toMatchObject( { object_id: 'blogname', user: 'admin', via: 'admin', undoable: true, undone_by: null } );

		await page.goto( PAGE );
		const row = page.locator( 'tr', { has: page.locator( `#alu-entry-${ entry.id }` ) } );
		await expect( row ).toContainText( 'Changed Site title' );
		await expect( row ).toContainText( 'Dashboard' );

		await undoOnScreen( page, entry.id );
		expect( option( 'blogname' ) ).toBe( 'E2E' );

		const after = entries();
		const undo = after.find( ( item ) => item.undo_of === entry.id );
		expect( undo ).toMatchObject( { event: 'option_updated', object_id: 'blogname', via: 'rest' } );
		expect( after.find( ( item ) => item.id === entry.id ).undone_by ).toBe( undo.id );
		await expect( page.locator( `a[href*="entry=${ undo.id }"]` ).first() ).toBeVisible();
	} );

	test( 'a change that was changed again since shows the differences and needs Undo anyway', async ( { page } ) => {
		wp( 'option', 'update', 'blogname', 'Spring menu' );
		wp( 'option', 'update', 'blogname', 'Summer menu' );
		const [ , first ] = entries();

		await page.goto( PAGE );
		await page.locator( `a.alu-undo[data-alu-entry="${ first.id }"]` ).click();
		await expect( page ).toHaveURL( new RegExp( `entry=${ first.id }` ) );
		await expect( page.locator( '.alu-conflict' ) ).toContainText( 'Changed since' );
		await expect( page.locator( '.alu-diff-table' ).last() ).toContainText( 'Summer menu' );
		expect( option( 'blogname' ) ).toBe( 'Summer menu' );

		await page.getByRole( 'link', { name: 'Undo anyway' } ).click();
		await expect( page.locator( '.alu-notice' ).first() ).toContainText( 'overwriting what changed since' );
		expect( option( 'blogname' ) ).toBe( 'E2E' );
	} );

	test( 'an undo can be undone again (redo)', async ( { page } ) => {
		wp( 'option', 'update', 'blogdescription', 'Fresh every day' );
		const change = latest( 'option_updated' );
		wp( 'activity-log-with-undo', 'undo', String( change.id ) );
		expect( option( 'blogdescription' ) ).toBe( 'Just another site' );
		const undo = entries().find( ( entry ) => entry.undo_of === change.id );

		await page.goto( PAGE );
		await expect( page.locator( `a.alu-undo[data-alu-entry="${ undo.id }"]` ) ).toHaveText( 'Redo' );
		await undoOnScreen( page, undo.id );

		expect( option( 'blogdescription' ) ).toBe( 'Fresh every day' );
		expect( entries().find( ( entry ) => entry.undo_of === undo.id ) ).toBeTruthy();
	} );

	test( 'deactivating a plugin on the Plugins screen can be undone', async ( { page } ) => {
		wp( 'plugin', 'activate', HELPER );
		reset();

		await page.goto( '/wp-admin/plugins.php' );
		await page.locator( `tr[data-plugin="${ HELPER }"] .deactivate a` ).click();
		await expect( page.locator( `tr[data-plugin="${ HELPER }"] .activate a` ) ).toBeVisible();

		const entry = latest( 'plugin_deactivated' );
		expect( entry ).toMatchObject( { object_id: HELPER, object_label: 'ALU E2E Helper', via: 'admin' } );

		await undoOnScreen( page, entry.id );
		expect( wp( 'plugin', 'list', '--status=active', '--field=file' ) ).toContain( HELPER );
		wp( 'plugin', 'deactivate', HELPER );
	} );

	test( 'a role change on the profile screen is one entry, and Undo restores the role', async ( { page } ) => {
		const userId = wp( 'user', 'create', 'sam', 'sam@example.com', '--role=author', '--porcelain' );
		reset();

		await page.goto( `/wp-admin/user-edit.php?user_id=${ userId }` );
		await page.locator( '#role' ).selectOption( 'editor' );
		await page.locator( '#submit' ).click();
		await expect( page.locator( '#message' ) ).toContainText( 'User updated' );

		const roleEntries = entries().filter( ( entry ) => entry.event === 'user_role_changed' );
		expect( roleEntries ).toHaveLength( 1 );
		expect( roleEntries[ 0 ].description ).toBe( 'Changed the role of sam from Author to Editor' );

		await undoOnScreen( page, roleEntries[ 0 ].id );
		expect( wp( 'user', 'get', userId, '--field=roles' ) ).toBe( 'author' );
		wp( 'user', 'delete', userId, '--yes' );
	} );

	test( 'a post trashed through the REST API is restored with its old status', async ( { page, rest } ) => {
		const postId = wp( 'post', 'create', '--post_title=Lemon tart', '--post_status=publish', '--porcelain' );
		reset();

		expect( ( await rest( 'DELETE', `/wp/v2/posts/${ postId }` ) ).status ).toBe( 200 );
		expect( wp( 'post', 'get', postId, '--field=post_status' ) ).toBe( 'trash' );
		const entry = latest( 'post_trashed' );
		expect( entry ).toMatchObject( { object_id: postId, via: 'rest', description: 'Moved the Post "Lemon tart" to the trash' } );

		await undoOnScreen( page, entry.id );
		expect( wp( 'post', 'get', postId, '--field=post_status' ) ).toBe( 'publish' );
		expect( wp( 'post', 'get', postId, '--field=post_name' ) ).toBe( 'lemon-tart' );
		wp( 'post', 'delete', postId, '--force' );
	} );

	test( 'a menu edit is undone as a whole: removed items come back and renamed ones get their name back', async ( { page } ) => {
		wp( 'menu', 'create', 'Main' );
		wp( 'menu', 'item', 'add-custom', 'main', 'Home', 'https://example.com/' );
		const about = wp( 'menu', 'item', 'add-custom', 'main', 'About', 'https://example.com/about', '--porcelain' );
		wp( 'menu', 'item', 'add-custom', 'main', 'Team', 'https://example.com/team', `--parent-id=${ about }`, '--porcelain' );
		wp( 'menu', 'item', 'add-custom', 'main', 'Contact', 'https://example.com/contact' );
		reset();
		const titles = () => JSON.parse( wp( 'menu', 'item', 'list', 'main', '--format=json', '--fields=title,menu_item_parent' ) );

		// One request that removes About and renames Contact.
		wpEval( `
			$items = wp_get_nav_menu_items( 'main' );
			foreach ( $items as $item ) {
				if ( 'About' === $item->title ) { wp_delete_post( $item->ID, true ); }
				if ( 'Contact' === $item->title ) { wp_update_post( array( 'ID' => $item->ID, 'post_title' => 'Say hello' ) ); }
			}
		` );
		expect( titles().map( ( item ) => item.title ) ).toEqual( [ 'Home', 'Team', 'Say hello' ] );

		const entry = latest( 'menu_updated' );
		expect( entry.description ).toBe( 'Changed the menu "Main" (4 items before, 3 after)' );
		await page.goto( `${ PAGE }&entry=${ entry.id }` );
		await expect( page.locator( '.alu-items' ).first() ).toContainText( 'About' );

		await undoOnScreen( page, entry.id );
		const restored = titles();
		expect( restored.map( ( item ) => item.title ) ).toEqual( [ 'Home', 'About', 'Team', 'Contact' ] );
		// Team still hangs under About, whose item got a new ID.
		const items = JSON.parse( wp( 'menu', 'item', 'list', 'main', '--format=json', '--fields=db_id,title,menu_item_parent' ) );
		expect( String( items.find( ( item ) => item.title === 'Team' ).menu_item_parent ) ).toBe( String( items.find( ( item ) => item.title === 'About' ).db_id ) );
		wp( 'menu', 'delete', 'main' );
	} );

	test( 'a category renamed through the REST API gets its name and slug back', async ( { page, rest } ) => {
		const termId = wp( 'term', 'create', 'category', 'Desserts', '--porcelain' );
		reset();

		await rest( 'POST', `/wp/v2/categories/${ termId }`, { name: 'Sweets', slug: 'sweets' } );
		const entry = latest( 'term_updated' );
		expect( entry.description ).toBe( 'Edited the name, slug of the Category "Sweets"' );

		await undoOnScreen( page, entry.id );
		expect( wp( 'term', 'get', 'category', termId, '--field=name' ) ).toBe( 'Desserts' );
		expect( wp( 'term', 'get', 'category', termId, '--field=slug' ) ).toBe( 'desserts' );
		wp( 'term', 'delete', 'category', termId );
	} );

	test( 'a deleted tag comes back with its posts and meta', async ( { page } ) => {
		const postId = wp( 'post', 'create', '--post_title=Tagged', '--post_status=publish', '--porcelain' );
		const termId = wp( 'term', 'create', 'post_tag', 'Seasonal', '--porcelain' );
		wp( 'post', 'term', 'set', postId, 'post_tag', termId, '--by=id' );
		wp( 'term', 'meta', 'add', termId, 'color', 'green' );
		reset();

		wp( 'term', 'delete', 'post_tag', termId );
		const entry = latest( 'term_deleted' );
		expect( entry.undoable ).toBe( true );

		await undoOnScreen( page, entry.id );
		const tag = JSON.parse( wp( 'term', 'get', 'post_tag', 'seasonal', '--by=slug', '--format=json' ) );
		expect( tag.name ).toBe( 'Seasonal' );
		expect( wp( 'term', 'meta', 'get', String( tag.term_id ), 'color' ) ).toBe( 'green' );
		expect( wp( 'post', 'term', 'list', postId, 'post_tag', '--field=slug' ) ).toBe( 'seasonal' );
		wp( 'post', 'delete', postId, '--force' );
		wp( 'term', 'delete', 'post_tag', String( tag.term_id ) );
	} );

	test( 'changes saved in one request are one change set and undone together', async ( { page } ) => {
		wpEval( `update_option( 'blogname', 'One' ); update_option( 'blogdescription', 'Two' ); update_option( 'posts_per_page', 7 );` );
		const set = entries();
		expect( set ).toHaveLength( 3 );
		expect( new Set( set.map( ( entry ) => entry.change_set ) ).size ).toBe( 1 );

		const messages = [];
		page.removeAllListeners( 'dialog' );
		page.on( 'dialog', ( dialog ) => {
			messages.push( dialog.message() );
			dialog.accept();
		} );
		await page.goto( PAGE );
		await expect( page.locator( 'tr', { has: page.locator( `#alu-entry-${ set[ 0 ].id }` ) } ) ).toContainText( 'Saved with 2 other changes' );
		await undoOnScreen( page, set[ 1 ].id, /Undid 3 changes/ );
		// The question names what else the undo reverses.
		expect( messages[ 0 ] ).toContain( 'Undo this change? It also undoes 2 other changes saved with it:' );
		expect( messages[ 0 ] ).toContain( 'Changed Site title' );
		expect( messages[ 0 ] ).toContain( 'Changed Blog pages show at most' );

		expect( option( 'blogname' ) ).toBe( 'E2E' );
		expect( option( 'posts_per_page' ) ).toBe( '10' );
		expect( option( 'blogdescription' ) ).toBe( 'Just another site' );
	} );

	test( 'bulk undo reverses the selected changes', async ( { page } ) => {
		wp( 'option', 'update', 'blogname', 'Bulk one' );
		wp( 'option', 'update', 'posts_per_page', '3' );
		const [ second, first ] = entries();

		await page.goto( PAGE );
		await page.locator( `#alu-entry-${ first.id }` ).check();
		await page.locator( `#alu-entry-${ second.id }` ).check();
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'undo' );
		await page.locator( '#doaction' ).click();
		await expect( page.locator( '.alu-notice' ).first() ).toContainText( 'Undid 2 changes' );

		expect( option( 'blogname' ) ).toBe( 'E2E' );
		expect( option( 'posts_per_page' ) ).toBe( '10' );
	} );

	test( 'filters, search and the details view', async ( { page } ) => {
		wp( 'option', 'update', 'blogname', 'Filter me' );
		const postId = wp( 'post', 'create', '--post_title=Searchable soup', '--post_status=draft', '--porcelain' );

		await page.goto( PAGE );
		await page.locator( '#alu-event' ).selectOption( 'post_created' );
		await page.locator( '#alu-filter' ).click();
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 1 );
		await expect( page.locator( '#the-list' ) ).toContainText( 'Searchable soup' );

		await page.goto( `${ PAGE }&alu_via=cli&s=Site+title` );
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 1 );
		await expect( page.locator( '#the-list' ) ).toContainText( 'Changed Site title' );

		await page.goto( `${ PAGE }&alu_via=rest` );
		await expect( page.locator( '#the-list' ) ).toContainText( 'No changes logged yet' );

		await page.goto( PAGE );
		await page.locator( '#the-list .row-title', { hasText: 'Changed Site title' } ).click();
		await expect( page.locator( '.alu-diff-table' ) ).toContainText( 'Filter me' );
		await expect( page.locator( '.alu-facts' ) ).toContainText( 'WP-CLI' );
		wp( 'post', 'delete', postId, '--force' );
	} );

	test( 'the JavaScript hooks can change the question and see the result', async ( { page } ) => {
		wp( 'option', 'update', 'blogname', 'Hooked' );
		const entry = latest( 'option_updated' );
		const messages = [];
		page.removeAllListeners( 'dialog' );
		page.on( 'dialog', ( dialog ) => {
			messages.push( dialog.message() );
			dialog.accept();
		} );

		await page.goto( PAGE );
		await page.evaluate( () => {
			window.wp.hooks.addFilter( 'activityLogWithUndo.confirmMessage', 'e2e', ( message, context ) => `Sure? #${ context.entryId } ${ message }` );
			window.wp.hooks.addAction( 'activityLogWithUndo.undone', 'e2e', ( result ) => sessionStorage.setItem( 'alu-undone', JSON.stringify( result.undone ) ) );
		} );
		await page.locator( `a.alu-undo[data-alu-entry="${ entry.id }"]` ).click();
		await expect( page.locator( '.alu-notice' ).first() ).toContainText( 'Undid 1 change' );

		expect( messages ).toEqual( [ `Sure? #${ entry.id } Undo this change?` ] );
		expect( await page.evaluate( () => sessionStorage.getItem( 'alu-undone' ) ) ).toBe( JSON.stringify( [ entry.id ] ) );
		expect( option( 'blogname' ) ).toBe( 'E2E' );
	} );

	test( 'the admin bar offers to undo my last change when turned on', async ( { page } ) => {
		await page.goto( '/wp-admin/options-general.php' );
		await page.locator( '#blogdescription' ).fill( 'Admin bar test' );
		await page.locator( '#submit' ).click();
		await expect( page.locator( '#wp-admin-bar-activity-log-with-undo' ) ).toHaveCount( 0 );

		wp( 'activity-log-with-undo', 'settings', '--admin-bar=on' );
		await page.goto( '/wp-admin/index.php' );
		const node = page.locator( '#wp-admin-bar-activity-log-with-undo > a' );
		await expect( node ).toContainText( 'Undo last change' );
		await node.click();
		await expect( page ).toHaveURL( /index\.php/ );
		await expect( page.locator( '.alu-notice' ) ).toContainText( 'Undid 1 change' );
		expect( option( 'blogdescription' ) ).toBe( 'Just another site' );
	} );

	test( 'a new user is undone by deleting the account, with their posts given to the admin', async ( { page } ) => {
		const ids = JSON.parse(
			wpEval( `
				wp_set_current_user( 1 );
				$user = wp_insert_user( array( 'user_login' => 'temp', 'user_email' => 'temp@example.com', 'user_pass' => wp_generate_password(), 'role' => 'author' ) );
				$post = wp_insert_post( array( 'post_title' => 'By temp', 'post_status' => 'draft', 'post_author' => $user ) );
				echo wp_json_encode( array( $user, $post ) );
			` )
		);
		const entry = latest( 'user_created' );
		expect( entry ).toMatchObject( { object_id: String( ids[ 0 ] ), user: 'admin' } );

		const messages = [];
		page.removeAllListeners( 'dialog' );
		page.on( 'dialog', ( dialog ) => {
			messages.push( dialog.message() );
			dialog.accept();
		} );
		await undoOnScreen( page, entry.id, /Undid/ );

		expect( messages[ 0 ] ).toContain( 'This deletes the user temp' );
		expect( wpEval( `echo get_userdata( ${ ids[ 0 ] } ) ? 'exists' : 'gone';` ) ).toBe( 'gone' );
		expect( wp( 'post', 'get', String( ids[ 1 ] ), '--field=post_author' ) ).toBe( '1' );
		wp( 'post', 'delete', String( ids[ 1 ] ), '--force' );
	} );

	test( 'global styles saved in the Site Editor go back to the previous version', async ( { page, rest } ) => {
		const id = wpEval( 'echo WP_Theme_JSON_Resolver::get_user_global_styles_post_id();' );
		const before = wp( 'post', 'get', id, '--field=post_content' );
		reset();

		const saved = await rest( 'POST', `/wp/v2/global-styles/${ id }`, { styles: { color: { background: '#fdf6e3' } }, settings: {} } );
		expect( saved.status ).toBe( 200 );
		expect( wp( 'post', 'get', id, '--field=post_content' ) ).toContain( '#fdf6e3' );
		const entry = latest( 'site_editor_updated' );
		expect( entry.description ).toBe( 'Changed the global styles' );

		await undoOnScreen( page, entry.id );
		expect( wp( 'post', 'get', id, '--field=post_content' ) ).toBe( before );
	} );

	test( 'the settings screen saves, and a recorder turned off stops logging', async ( { page } ) => {
		await page.goto( `${ PAGE }&tab=settings` );
		await page.getByLabel( 'Settings and widgets' ).uncheck();
		await page.locator( '#alu-retention' ).fill( '30' );
		await page.getByLabel( 'Do not keep IP addresses' ).check();
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.locator( '.alu-notice' ) ).toContainText( 'Settings saved.' );

		const settings = JSON.parse( wp( 'option', 'get', 'activity_log_with_undo_settings', '--format=json' ) );
		expect( settings ).toMatchObject( { disabled: [ 'options' ], retention_days: 30, ip: 'off' } );

		wp( 'option', 'update', 'blogname', 'Not logged' );
		expect( entries() ).toHaveLength( 0 );
		await page.goto( '/wp-admin/site-health.php' );
		await expect( page.getByText( 'Some changes are not logged' ) ).toBeVisible();
	} );

	test( 'old entries are deleted by the daily job and the purge command', async () => {
		const age = ( id, days ) =>
			wpEval( `global $wpdb; $wpdb->update( $wpdb->prefix . 'activity_log_with_undo', array( 'created_gmt' => gmdate( 'Y-m-d H:i:s', time() - ${ days } * DAY_IN_SECONDS ) ), array( 'id' => ${ id } ) ); wp_cache_set_last_changed( 'activity_log_with_undo' );` );
		wp( 'option', 'update', 'blogname', 'Old one' );
		wp( 'option', 'update', 'blogname', 'Older one' );
		wp( 'option', 'update', 'blogname', 'New one' );
		const [ newest, middle, oldest ] = entries();
		age( oldest.id, 100 );
		age( middle.id, 40 );

		// The daily job keeps 90 days.
		wpEval( 'AryanX\\ActivityLogWithUndo\\Retention::schedule();' );
		wp( 'cron', 'event', 'run', 'activity_log_with_undo_purge' );
		expect( entries().map( ( entry ) => entry.id ) ).toEqual( [ newest.id, middle.id ] );

		expect( wp( 'activity-log-with-undo', 'purge', '--older-than=30' ) ).toContain( 'Deleted 1 entries' );
		expect( entries().map( ( entry ) => entry.id ) ).toEqual( [ newest.id ] );

		expect( wp( 'activity-log-with-undo', 'purge', '--older-than=0' ) ).toContain( 'Deleted 1 entries' );
		expect( entries() ).toHaveLength( 0 );
	} );

	test( 'the privacy tools export a user\'s changes and erase them from the log', async () => {
		wpEval( `wp_set_current_user( 1 ); update_option( 'blogname', 'Private' );` );

		const privacy = ( tool ) =>
			JSON.parse(
				wpEval( `
					$tools = apply_filters( 'wp_privacy_personal_data_${ tool }s', array() );
					echo wp_json_encode( call_user_func( $tools['activity-log-with-undo']['callback'], 'admin@example.com', 1 ) );
				` )
			);
		const exported = privacy( 'exporter' );
		expect( exported.data ).toHaveLength( 1 );
		expect( exported.data[ 0 ].data[ 1 ].value ).toBe( 'Changed Site title' );

		expect( privacy( 'eraser' ) ).toMatchObject( { items_removed: true, items_retained: true, done: true } );
		expect( entries()[ 0 ] ).toMatchObject( { user: '(removed)', description: 'Changed Site title' } );
	} );

	test( 'only settings are logged: other plugins\' internal options and settings a visitor changes are left out', async () => {
		// Another plugin rewriting its index on every scanned post, the way Block Doctor did.
		wpEval( 'for ( $i = 0; $i < 200; $i++ ) { update_option( "alu_e2e_index", array( "posts" => array_fill( 0, 2000, "core/paragraph" ), "n" => $i ), false ); }' );
		wp( 'option', 'update', 'alu_e2e_internal', 'cli' );
		expect( entries() ).toHaveLength( 0 );
		expect( count( '1=1' ) ).toBe( 0 );

		// A visitor's page view: the setting it changes is not logged, the always-logged option is.
		const visitor = await playwrightRequest.newContext( { baseURL: BASE_URL } );
		expect( await ( await visitor.get( '/?alu_e2e_visit=1' ) ).text() ).toBe( 'ok' );
		await visitor.dispose();
		expect( option( 'blogdescription' ) ).toBe( 'Changed by a visitor' );
		expect( entries().map( ( entry ) => entry.object_id ) ).toEqual( [ 'alu_e2e_always' ] );

		// A setting registered with the Settings API counts, with its label.
		wpEval( 'register_setting( "general", "alu_e2e_registered", array( "label" => "E2E newsletter" ) ); update_option( "alu_e2e_registered", "weekly" );' );
		expect( latest( 'option_added' ) ).toMatchObject( { object_id: 'alu_e2e_registered', description: 'Added E2E newsletter' } );
		wp( 'option', 'update', 'blogdescription', 'Just another site' );
	} );

	test( 'one option written many times is rate-limited, and a value over the size guard is logged without it', async ( { page } ) => {
		wp( 'option', 'update', 'alu_e2e_rate', '2' );
		for ( const name of [ 'One', 'Two', 'Three' ] ) {
			wp( 'option', 'update', 'blogname', name );
		}
		expect( entries().filter( ( entry ) => entry.object_id === 'blogname' ) ).toHaveLength( 2 );
		// Several writes in one request are one entry.
		wpEval( 'update_option( "blogdescription", "A" ); update_option( "blogdescription", "B" ); update_option( "blogdescription", "C" );' );
		const tagline = entries().filter( ( entry ) => entry.object_id === 'blogdescription' );
		expect( tagline ).toHaveLength( 1 );
		const shown = JSON.parse( wp( 'activity-log-with-undo', 'show', String( tagline[ 0 ].id ), '--format=json' ) )[ 0 ];
		expect( [ shown.before, shown.after ] ).toEqual( [ 'Just another site', 'C' ] );

		wpEval( 'update_option( "alu_e2e_always", str_repeat( "x", 70000 ) );' );
		const big = latest( 'option_added' );
		expect( big ).toMatchObject( { object_id: 'alu_e2e_always', undoable: false } );
		await page.goto( `${ PAGE }&entry=${ big.id }` );
		await expect( page.locator( '.alu-left-out' ) ).toContainText( 'too big to keep' );
		wp( 'option', 'update', 'blogname', 'E2E' );
		wp( 'option', 'update', 'blogdescription', 'Just another site' );
	} );

	test( 'after the upgrade, the notice offers to delete what version 1 logged of internal options', async ( { page } ) => {
		for ( let i = 0; i < 30; i++ ) {
			legacyEntry( { object_id: 'block_doctor_index', object_label: 'block_doctor_index', change_set: `legacy${ i }`, before_state: '{"v":1}', after_state: '{"v":2}' } );
		}
		legacyEntry( { object_id: 'blogname', object_label: 'Site title', change_set: 'legacy-setting', before_state: '{"v":"A"}', after_state: '{"v":"B"}' } );
		// The table as version 1 had it.
		wp( 'option', 'update', 'activity_log_with_undo_db_version', '1' );

		await page.goto( '/wp-admin/index.php' );
		const notice = page.locator( '.alu-cleanup' );
		await expect( notice ).toContainText( '30 entries of options that are not settings (block_doctor_index)' );
		expect( option( 'activity_log_with_undo_db_version' ) ).toBe( '2' );
		expect( JSON.parse( sql( "SHOW COLUMNS FROM wp_activity_log_with_undo LIKE 'approved_by'" ) ) ).toHaveLength( 1 );

		await notice.getByRole( 'button', { name: 'Delete these entries' } ).click();
		await expect( page.locator( '.alu-notice' ) ).toContainText( 'Deleted 30 old entries of internal options.' );
		await expect( page.locator( '.alu-cleanup' ) ).toHaveCount( 0 );
		expect( count( "object_id = 'block_doctor_index'" ) ).toBe( 0 );
		expect( count( "object_id = 'blogname'" ) ).toBe( 1 );
		expect( JSON.parse( wp( 'option', 'get', 'activity_log_with_undo_cleanup', '--format=json' ) ) ).toMatchObject( { status: 'done', deleted: 30 } );

		// The same from WP-CLI, with a dry run first.
		for ( let i = 0; i < 5; i++ ) {
			legacyEntry( { object_id: 'alu_e2e_job_state', change_set: `job${ i }`, before_state: '{"v":1}', after_state: '{"v":2}' } );
		}
		expect( wp( 'activity-log-with-undo', 'cleanup', '--dry-run' ) ).toContain( '5 entries of 1 options would be deleted.' );
		expect( count( "object_id = 'alu_e2e_job_state'" ) ).toBe( 5 );
		expect( wp( 'activity-log-with-undo', 'cleanup', '--yes' ) ).toContain( 'Deleted 5 entries.' );
		expect( count( "object_id = 'alu_e2e_job_state'" ) ).toBe( 0 );
	} );

	test( 'an undo leaves out another plugin\'s state logged in the same request, and WP-CLI names what it undoes', async ( { rest } ) => {
		const termId = wp( 'term', 'create', 'category', 'Soups', '--porcelain' );
		reset();
		expect( ( await rest( 'DELETE', `/wp/v2/categories/${ termId }?force=true` ) ).status ).toBe( 200 );
		const deleted = latest( 'term_deleted' );
		// What version 1 wrote next to it: a theme's cache-buster, in the same change set.
		wp( 'option', 'update', 'alu_e2e_content_version', '2' );
		legacyEntry( { object_id: 'alu_e2e_content_version', object_label: 'alu_e2e_content_version', change_set: deleted.change_set, before_state: '{"v":"1"}', after_state: '{"v":"2"}' } );

		const output = wp( 'activity-log-with-undo', 'undo', String( deleted.id ) );
		expect( output ).toContain( `Undid #${ deleted.id }.` );
		expect( output ).not.toContain( 'saved together' );
		expect( wp( 'term', 'list', 'category', '--name=Soups', '--field=name' ) ).toBe( 'Soups' );
		expect( option( 'alu_e2e_content_version' ) ).toBe( '2' );

		// Two settings saved together: WP-CLI lists the other one and asks first.
		wpEval( 'update_option( "blogname", "Together one" ); update_option( "blogdescription", "Together two" );' );
		const [ tagline, title ] = entries();
		const asked = wp( 'activity-log-with-undo', 'undo', String( title.id ) );
		expect( asked ).toContain( `#${ title.id } was saved together with 1 other change(s), which are undone with it:` );
		expect( asked ).toContain( `#${ tagline.id } Changed Tagline` );
		expect( option( 'blogname' ) ).toBe( 'Together one' );
		expect( wp( 'activity-log-with-undo', 'undo', String( title.id ), '--yes' ) ).toContain( 'Undid #' );
		expect( option( 'blogname' ) ).toBe( 'E2E' );
		expect( option( 'blogdescription' ) ).toBe( 'Just another site' );
		wp( 'term', 'delete', 'category', wp( 'term', 'list', 'category', '--name=Soups', '--field=term_id' ) );
	} );

	test( 'a setting changed before init is logged without loading translations early', async ( { page } ) => {
		wpEval( '@unlink( WP_CONTENT_DIR . "/debug.log" );' );
		wp( 'option', 'update', 'alu_e2e_early', '1' );
		await page.goto( '/wp-admin/index.php' );
		wp( 'option', 'delete', 'alu_e2e_early' );

		const early = entries().filter( ( entry ) => entry.object_id === 'blogdescription' );
		expect( early.length ).toBeGreaterThan( 0 );
		expect( early[ 0 ] ).toMatchObject( { object_label: 'blogdescription', description: 'Changed Tagline' } );
		expect( wpEval( 'echo file_exists( WP_CONTENT_DIR . "/debug.log" ) ? file_get_contents( WP_CONTENT_DIR . "/debug.log" ) : "";' ) ).not.toContain( 'activity-log-with-undo</code> domain was triggered too early' );
		wp( 'option', 'update', 'blogdescription', 'Just another site' );
	} );

	test( 'when another plugin refuses the undo, the reason shows on the same page', async ( { page } ) => {
		wp( 'option', 'update', 'blogname', 'Frozen' );
		const entry = latest( 'option_updated' );
		wp( 'option', 'update', 'alu_e2e_freeze', '1' );

		await page.goto( PAGE );
		await page.evaluate( () => window.wp.hooks.addAction( 'activityLogWithUndo.undoFailed', 'e2e', ( error, context ) => sessionStorage.setItem( 'alu-failed', `${ error.code } ${ context.entryId }` ) ) );
		const link = page.locator( `a.alu-undo[data-alu-entry="${ entry.id }"]` );
		await link.click();
		await expect( page.locator( '.alu-undo-error' ) ).toContainText( 'This site is frozen for the test. Try again later.' );
		await expect( page ).toHaveURL( /page=activity-log-with-undo$/ );
		await expect( link ).not.toHaveAttribute( 'aria-disabled', 'true' );
		expect( await page.evaluate( () => sessionStorage.getItem( 'alu-failed' ) ) ).toBe( `alu_e2e_frozen ${ entry.id }` );
		expect( option( 'blogname' ) ).toBe( 'Frozen' );
		expect( entries().find( ( item ) => item.id === entry.id ).undone_by ).toBeNull();

		// Once writes are allowed again, the same link works.
		wp( 'option', 'delete', 'alu_e2e_freeze' );
		await link.click();
		await expect( page.locator( '.alu-notice' ).first() ).toContainText( 'Undid 1 change' );
		expect( option( 'blogname' ) ).toBe( 'E2E' );
	} );

	test( 'the entry screen uses names people know, keeps the raw values under Technical details, and reads well right to left', async ( { page } ) => {
		const postId = wp( 'post', 'create', '--post_title=Status check', '--post_status=draft', '--post_content=<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->', '--porcelain' );
		reset();
		wp( 'post', 'update', postId, '--post_status=publish' );
		const entry = latest( 'post_status_changed' );

		await page.goto( `${ PAGE }&entry=${ entry.id }` );
		const facts = page.locator( '.alu-facts' );
		await expect( facts ).toContainText( 'Status changed' );
		await expect( facts ).toContainText( 'Post: Status check' );
		await expect( facts ).not.toContainText( 'post_status_changed' );
		const table = page.locator( '.alu-diff-table' );
		await expect( table.locator( 'tbody th' ) ).toHaveText( 'Status' );
		await expect( table ).toContainText( 'Published' );
		await expect( table ).toContainText( 'Draft' );
		await expect( table ).not.toContainText( 'post_status' );
		await expect( table.locator( 'thead th' ).first() ).toHaveText( 'Field' );
		await expect( table.locator( 'td' ).first() ).toHaveAttribute( 'dir', 'auto' );

		const details = page.locator( 'details.alu-tech' );
		await expect( details ).not.toHaveAttribute( 'open', '' );
		await expect( details.locator( 'code' ).nth( 1 ) ).toBeHidden();
		await details.locator( 'summary' ).click();
		await expect( details ).toContainText( 'post_status_changed' );
		await expect( details ).toContainText( `post ${ postId }` );
		await expect( details.locator( 'code' ).first() ).toHaveAttribute( 'dir', 'ltr' );

		// Content edits: markup reads left to right.
		wp( 'post', 'update', postId, '--post_content=<!-- wp:paragraph --><p>Body changed with a much longer sentence so it is shown as a text diff</p><!-- /wp:paragraph -->' );
		await page.goto( `${ PAGE }&entry=${ latest( 'post_updated' ).id }` );
		await expect( page.locator( '.alu-text-diff' ).first() ).toHaveAttribute( 'dir', 'ltr' );
		await expect( page.locator( '.alu-diff-field' ).first() ).toHaveText( 'Content' );
		wp( 'post', 'delete', postId, '--force' );
	} );

	test( 'accessibility: links in running text are underlined, muted text has contrast, every row has a checkbox header text', async ( { page } ) => {
		const userId = wp( 'user', 'create', 'axe', 'axe@example.com', '--porcelain' );
		reset();
		wpEval( 'update_option( "blogname", "Axe one" ); update_option( "blogdescription", "Axe two" );' );
		// Deleting a user is logged only, so its row has no checkbox.
		wp( 'user', 'delete', userId, '--yes' );

		await page.goto( PAGE );
		const link = page.locator( '.alu-meta a', { hasText: 'Saved with 1 other change' } ).first();
		expect( await link.evaluate( ( element ) => getComputedStyle( element ).textDecorationLine ) ).toBe( 'underline' );
		const loggedOnly = page.locator( '#the-list tr', { hasText: 'Deleted the user axe' } ).first();
		await expect( loggedOnly.locator( '.check-column .screen-reader-text' ) ).toContainText( 'cannot be selected for undo' );
		expect( await loggedOnly.locator( '.alu-muted' ).evaluate( ( element ) => getComputedStyle( element ).color ) ).toBe( 'rgb(100, 105, 112)' );
		wp( 'option', 'update', 'blogname', 'E2E' );
		wp( 'option', 'update', 'blogdescription', 'Just another site' );
	} );

	test( 'a change an AI agent made after someone approved it in Agent Governance names the agent and the approver', async ( { page } ) => {
		// What Agent Governance does when an admin approves a held request: these two actions around the run.
		wpEval( `
			wp_set_current_user( 1 );
			do_action( 'agent_governance_request_approved', array( 'id' => 7, 'agent' => 'Claude', 'identity' => 'app:e2e', 'via' => 'app-password', 'user_id' => 1 ), 1 );
			update_option( 'blogname', 'Written by an agent' );
			do_action( 'agent_governance_request_executed', array( 'id' => 7 ), true );
			update_option( 'blogdescription', 'Written after' );
		` );
		const [ after, byAgent ] = entries();
		const shown = JSON.parse( wp( 'activity-log-with-undo', 'show', String( byAgent.id ), '--format=json' ) )[ 0 ];
		expect( shown.agent ).toEqual( { name: 'Claude', request: 7, approved_by: 1, approver: 'admin' } );
		expect( JSON.parse( wp( 'activity-log-with-undo', 'show', String( after.id ), '--format=json' ) )[ 0 ].agent ).toBeNull();
		expect( JSON.parse( sql( `SELECT agent, agent_request, approved_by FROM wp_activity_log_with_undo WHERE id = ${ byAgent.id }` ) )[ 0 ] ).toEqual( [ 'Claude', '7', '1' ] );

		await page.goto( PAGE );
		await expect( page.locator( 'tr', { has: page.locator( `#alu-entry-${ byAgent.id }` ) } ).locator( '.alu-agent' ) ).toHaveText( 'Claude, request #7, approved by admin' );
		await expect( page.locator( 'tr', { has: page.locator( `#alu-entry-${ after.id }` ) } ).locator( '.alu-agent' ) ).toHaveCount( 0 );
		await page.goto( `${ PAGE }&entry=${ byAgent.id }` );
		await expect( page.locator( '.alu-facts .alu-agent' ) ).toContainText( 'Claude, request #7, approved by admin' );
		wp( 'option', 'update', 'blogname', 'E2E' );
		wp( 'option', 'update', 'blogdescription', 'Just another site' );
	} );

	test( 'abilities: an agent can list, inspect and undo, and undo refuses a conflict unless forced', async ( { rest } ) => {
		test.skip( 'yes' !== wpEval( "echo function_exists( 'wp_register_ability' ) ? 'yes' : 'no';" ), 'The Abilities API needs WordPress 6.9.' );
		const run = ( name, method, input ) => {
			const path = `/wp-abilities/v1/abilities/activity-log-with-undo/${ name }/run`;
			if ( 'GET' === method ) {
				const query = Object.entries( input || {} ).map( ( [ key, value ] ) => `input[${ key }]=${ encodeURIComponent( value ) }` ).join( '&' );
				return rest( 'GET', query ? `${ path }?${ query }` : path );
			}
			return rest( method, path, { input } );
		};

		const listed = await rest( 'GET', '/wp-abilities/v1/abilities?category=activity-log-with-undo' );
		expect( listed.body.map( ( ability ) => ability.name ).sort() ).toEqual( [ 'activity-log-with-undo/get-entry', 'activity-log-with-undo/list-activity', 'activity-log-with-undo/undo-change' ] );
		expect( listed.body.find( ( ability ) => ability.name.endsWith( 'undo-change' ) ).meta.annotations ).toMatchObject( { destructive: true, readonly: false } );
		// MCP clients only list abilities marked public.
		expect( JSON.parse( wpEval( `echo wp_json_encode( array_values( array_filter( array_map( function ( $ability ) { $meta = $ability->get_meta(); return ( ! empty( $meta['public'] ) && ! empty( $meta['mcp']['public'] ) ) ? '' : $ability->get_name(); }, wp_get_abilities() ), function ( $name ) { return 0 === strpos( $name, 'activity-log-with-undo/' ); } ) ) );` ) ) ).toEqual( [] );

		wp( 'option', 'update', 'blogname', 'Agent one' );
		wp( 'option', 'update', 'blogname', 'Agent two' );
		const list = await run( 'list-activity', 'GET', { event: 'option_updated', limit: 10 } );
		expect( list.status ).toBe( 200 );
		expect( list.body ).toHaveLength( 2 );
		const older = list.body[ 1 ];

		const detail = await run( 'get-entry', 'GET', { id: older.id } );
		expect( detail.body ).toMatchObject( { before: 'E2E', after: 'Agent one', can_undo: true, changed_since: true } );

		const refused = await run( 'undo-change', 'POST', { id: older.id } );
		expect( refused.status ).toBe( 409 );
		expect( refused.body.code ).toBe( 'activity_log_with_undo_conflict' );
		expect( option( 'blogname' ) ).toBe( 'Agent two' );

		const forced = await run( 'undo-change', 'POST', { id: older.id, force: true } );
		expect( forced.body ).toMatchObject( { undone: [ older.id ], forced: true } );
		expect( option( 'blogname' ) ).toBe( 'E2E' );
		expect( entries().find( ( entry ) => entry.undo_of === older.id ).via ).toBe( 'ability' );
	} );
} );
