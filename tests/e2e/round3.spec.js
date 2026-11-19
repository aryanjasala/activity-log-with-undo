const { test, expect, wp, wpEval, openEditor, logInAs } = require( './utils' );
const { PASSWORD } = require( './utils/config' );

const PAGE = '/wp-admin/tools.php?page=activity-log-with-undo';
const HELPER = 'alu-e2e-helper.php';
const PLUGIN_BODY = ( version ) => `<?php\\n/**\\n * Plugin Name: ALU E2E Helper\\n * Version: ${ version }\\n */\\n`;

const reset = () => {
	try {
		wp( 'option', 'delete', 'activity_log_with_undo_settings' );
	} catch ( error ) {
		// Not set.
	}
	wpEval( 'AryanX\\Axle\\ActivityLogWithUndo\\Plugin::instance()->store()->truncate();' );
	wp( 'transient', 'delete', '--all' );
};
const entries = () => JSON.parse( wp( 'activity-log-with-undo', 'list', '--format=json', '--fields=id,event,description,undoable,undone_by' ) );
const post = ( id, field ) => wp( 'post', 'get', String( id ), `--field=${ field }` );

test.describe( 'Activity Log with Undo, round 3', () => {
	test.beforeEach( () => reset() );
	test.afterEach( () => {
		wp( 'option', 'update', 'blogname', 'E2E' );
		wp( 'option', 'update', 'blogdescription', 'Just another site' );
	} );

	test( 'an update remembers the version it replaced', () => {
		wpEval( `file_put_contents( WP_PLUGIN_DIR . '/${ HELPER }', "${ PLUGIN_BODY( '1.0' ) }" ); wp_clean_plugins_cache();` );
		// The recorder is called directly: core's own upgrader_process_complete callbacks need a real upgrade.
		wpEval( `
			$recorder = AryanX\\Axle\\ActivityLogWithUndo\\Plugin::instance()->recorders()->get( 'plugins' );
			$recorder->before_install( true, array( 'plugin' => '${ HELPER }' ) );
			file_put_contents( WP_PLUGIN_DIR . '/${ HELPER }', "${ PLUGIN_BODY( '1.1' ) }" );
			wp_clean_plugins_cache();
			$recorder->upgraded( null, array( 'type' => 'plugin', 'action' => 'update', 'plugins' => array( '${ HELPER }' ) ) );
		` );

		const entry = entries().find( ( item ) => item.event === 'plugin_updated' );
		expect( entry.description ).toContain( 'from 1.0 to 1.1' );
		// No saved copy of the old files (Smoke Test is not here), so it is logged only.
		expect( entry.undoable ).toBe( false );
		const before = JSON.parse( wpEval( `echo wp_json_encode( AryanX\\Axle\\ActivityLogWithUndo\\Plugin::instance()->store()->get( ${ entry.id } )->before() );` ) );
		expect( before ).toEqual( { version: '1.0' } );
		wpEval( `unlink( WP_PLUGIN_DIR . '/${ HELPER }' ); wp_clean_plugins_cache();` );
	} );

	test( 'the log has core views, an Undo row action and a More filters disclosure', async ( { page } ) => {
		wp( 'option', 'update', 'blogname', 'Views test' );
		wp( 'post', 'create', '--post_title=Viewed', '--post_status=draft', '--porcelain' );

		await page.goto( PAGE );
		const views = page.locator( 'ul.subsubsub' );
		await expect( views ).toContainText( 'All' );
		await expect( views ).toContainText( 'Settings' );
		await expect( views ).toContainText( 'Content' );
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 2 );
		await expect( page.locator( '#the-list .row-actions a.alu-undo' ).first() ).toBeVisible();
		await expect( page.locator( '#the-list button.alu-undo, #the-list a.button.alu-undo' ) ).toHaveCount( 0 );
		await expect( page.locator( '#alu-event' ) ).toBeHidden();

		await views.getByRole( 'link', { name: /^Settings/ } ).click();
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 1 );
		await expect( page.locator( '#the-list' ) ).toContainText( 'Site title' );

		await page.goto( PAGE );
		await page.locator( '.alu-more summary' ).click();
		await page.locator( '#alu-event' ).selectOption( 'post_created' );
		await page.locator( '#alu-apply' ).click();
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 1 );
		await expect( page.locator( '.alu-more' ) ).toHaveAttribute( 'open', '' );
	} );

	test( 'the row action undoes a change after the question', async ( { page } ) => {
		wp( 'option', 'update', 'blogname', 'Row action' );
		const entry = entries().find( ( item ) => item.event === 'option_updated' );
		await page.goto( PAGE );
		await page.locator( `a.alu-undo[data-alu-entry="${ entry.id }"]` ).click();
		await page.locator( '.alu-ask .axle-actions button' ).first().click();
		await expect( page.locator( '.alu-notice' ).first() ).toContainText( 'Undid 1 change' );
		expect( wp( 'option', 'get', 'blogname' ) ).toBe( 'E2E' );
	} );

	test( 'Roll back offers yesterday at 6 am before 6 am', () => {
		const out = JSON.parse( wpEval( `
			$zone = new DateTimeZone( 'UTC' );
			echo wp_json_encode( array(
				AryanX\\Axle\\ActivityLogWithUndo\\Rollback::morning( strtotime( '2026-11-20 04:00:00 UTC' ), $zone )['label'],
				AryanX\\Axle\\ActivityLogWithUndo\\Rollback::morning( strtotime( '2026-11-20 15:00:00 UTC' ), $zone )['label'],
			) );
		` ) );
		expect( out ).toEqual( [ 'Yesterday 6 am', 'This morning (6 am)' ] );
	} );

	test( 'the admin bar names the change and stays visible on a phone', async ( { page } ) => {
		wp( 'option', 'update', 'blogdescription', 'Phone tagline', '--user=admin' );
		wp( 'activity-log-with-undo', 'settings', '--admin-bar=on' );

		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( '/wp-admin/index.php' );
		const node = page.locator( '#wp-admin-bar-activity-log-with-undo' );
		await expect( node ).toBeVisible();
		await expect( node.locator( '.ab-icon' ) ).toBeVisible();
		await expect( node.locator( '> a' ) ).toContainText( 'Undo:' );
		await expect( node.locator( '> a' ) ).toContainText( 'changed' );
	} );

	test( 'the editor panel lists a status change and undoing it keeps the editor in step', async ( { page } ) => {
		const id = wp( 'post', 'create', '--post_title=Pricing', '--post_status=publish', '--porcelain' );
		wp( 'post', 'update', id, '--post_status=draft' );

		await openEditor( page, Number( id ) );
		await page.evaluate( () => window.wp.data.dispatch( 'core/edit-post' ).openGeneralSidebar( 'edit-post/document' ) );
		// The panel remembers if it was open: open it only when it is closed.
		const toggle = page.getByRole( 'button', { name: 'Activity on this post' } );
		if ( 'false' === ( await toggle.getAttribute( 'aria-expanded' ) ) ) {
			await toggle.click();
		}
		const panel = page.locator( '.alu-panel' );
		await expect( panel ).toContainText( 'from Published to Draft' );

		await panel.locator( '.alu-panel__undo' ).first().click();
		await expect( panel.locator( '.alu-panel__confirm' ) ).toContainText( 'Undo this change?' );
		await panel.getByRole( 'button', { name: 'Undo this change' } ).click();
		await expect( panel.locator( '.alu-panel__state' ).first() ).toContainText( 'Undone' );

		expect( post( id, 'post_status' ) ).toBe( 'publish' );
		// The editor holds the published post, so the next Save does not write the draft back.
		expect( await page.evaluate( () => window.wp.data.select( 'core/editor' ).getCurrentPostAttribute( 'status' ) ) ).toBe( 'publish' );
		await page.evaluate( async () => {
			window.wp.data.dispatch( 'core/editor' ).editPost( { title: 'Pricing, new' } );
			await window.wp.data.dispatch( 'core/editor' ).savePost();
		} );
		expect( post( id, 'post_title' ) ).toBe( 'Pricing, new' );
		expect( post( id, 'post_status' ) ).toBe( 'publish' );
		wp( 'post', 'delete', id, '--force' );
	} );

	test( 'terms, sticky and password changes show in the post activity and undo', () => {
		const id = wp( 'post', 'create', '--post_title=Tagged', '--post_status=publish', '--porcelain' );
		const category = wp( 'term', 'create', 'category', 'Soup', '--porcelain' );
		wpEval( `wp_set_object_terms( ${ id }, array( ${ category } ), 'category', false );` );
		wpEval( `stick_post( ${ id } );` );
		wp( 'post', 'update', id, '--post_password=secret' );

		const list = JSON.parse( wp( 'activity-log-with-undo', 'post-activity', id, '--format=json' ) );
		const events = list.map( ( item ) => item.description ).join( '\n' );
		expect( events ).toContain( 'categories' );
		expect( events ).toContain( 'Stuck the Post' );
		expect( events ).toContain( 'password' );

		const sticky = list.find( ( item ) => /Stuck the Post/.test( item.description ) );
		wp( 'activity-log-with-undo', 'undo', String( sticky.id ), '--yes' );
		expect( wpEval( `echo is_sticky( ${ id } ) ? 'yes' : 'no';` ) ).toBe( 'no' );

		const terms = list.find( ( item ) => /categories/.test( item.description ) );
		wp( 'activity-log-with-undo', 'undo', String( terms.id ), '--yes' );
		expect( wp( 'post', 'term', 'list', id, 'category', '--field=name' ) ).toBe( 'Uncategorized' );
		wp( 'post', 'delete', id, '--force' );
		wp( 'term', 'delete', 'category', category );
	} );

	test( 'the panel route needs the right to edit that post and undoes only that post change', async ( { browser } ) => {
		const id = wp( 'post', 'create', '--post_title=Private plans', '--post_status=publish', '--porcelain' );
		wp( 'post', 'update', id, '--post_status=draft' );
		try {
			wp( 'user', 'delete', 'alu-contrib', '--yes' );
		} catch ( error ) {
			// Not there yet.
		}
		wp( 'user', 'create', 'alu-contrib', 'alu-contrib@example.com', '--role=contributor', `--user_pass=${ PASSWORD }` );
		const { context, page } = await logInAs( browser, 'alu-contrib', PASSWORD );
		try {
			const nonce = ( await ( await page.request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();
			const call = async ( method, route ) => {
				const response = await page.request.fetch( `/index.php?rest_route=${ encodeURIComponent( route ) }`, { method, headers: { 'X-WP-Nonce': nonce } } );
				return { status: response.status(), body: await response.json().catch( () => null ) };
			};
			const denied = await call( 'GET', `/activity-log-with-undo/v1/posts/${ id }/activity` );
			expect( denied.status ).toBe( 403 );
			expect( JSON.stringify( denied.body ) ).not.toContain( 'Private plans' );
			const undo = await call( 'POST', `/activity-log-with-undo/v1/posts/${ id }/activity/1/undo` );
			expect( undo.status ).toBe( 403 );
		} finally {
			await context.close();
		}
		expect( post( id, 'post_status' ) ).toBe( 'draft' );
		wp( 'post', 'delete', id, '--force' );
		wp( 'user', 'delete', 'alu-contrib', '--yes' );
	} );
} );
