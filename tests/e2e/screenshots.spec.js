// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const { test, expect, wp, wpEval, openEditor } = require( './utils' );
const { prepareSite, shot } = require( './utils/media' );
const { BASE_URL, STORAGE_STATE } = require( './utils/config' );

const PAGE = '/wp-admin/tools.php?page=activity-log-with-undo';
const latest = ( event ) => JSON.parse( wp( 'activity-log-with-undo', 'list', '--format=json', '--fields=id,event' ) ).find( ( entry ) => entry.event === event );

test.describe.serial( 'Screenshots', () => {
	test.use( { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 } );
	const made = { posts: [], users: [], terms: [] };

	test.beforeAll( async ( { browser } ) => {
		prepareSite( 'Kitchen Notes' );
		wpEval( `update_option( 'blogdescription', 'Recipes from a small kitchen' ); update_option( 'posts_per_page', 10 );` );
		made.users.push( wp( 'user', 'create', 'maria', 'maria@example.com', '--role=author', '--display_name=Maria', '--porcelain' ) );
		made.posts.push(
			wp(
				'post',
				'create',
				'--post_title=Lemon tart',
				'--post_status=publish',
				'--post_content=<!-- wp:paragraph --><p>A short pastry case, a sharp lemon curd and a thin layer of burnt sugar on top. Bake the case blind for 15 minutes first.</p><!-- /wp:paragraph -->',
				'--porcelain'
			)
		);
		made.posts.push( wp( 'post', 'create', '--post_title=Spring menu', '--post_status=publish', '--porcelain' ) );
		made.terms.push( wp( 'term', 'create', 'category', 'Desserts', '--porcelain' ) );
		wp( 'plugin', 'activate', 'hello' );
		wpEval( 'AryanX\\Axle\\ActivityLogWithUndo\\Plugin::instance()->store()->truncate();' );

		// A morning of changes, from the dashboard, the editor and WP-CLI.
		const context = await browser.newContext( { baseURL: BASE_URL, storageState: STORAGE_STATE } );
		const page = await context.newPage();
		const nonce = ( await ( await page.request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();
		const rest = ( method, path, data ) => page.request.fetch( `/index.php?rest_route=${ encodeURIComponent( path ) }`, { method, headers: { 'X-WP-Nonce': nonce }, data } );

		await page.goto( '/wp-admin/options-general.php' );
		await page.locator( '#blogname' ).fill( 'Kitchen Notes Weekly' );
		await page.locator( '#submit' ).click();
		wp( 'plugin', 'deactivate', 'hello', '--user=admin' );
		await rest( 'POST', `/wp/v2/posts/${ made.posts[ 0 ] }`, {
			content: '<!-- wp:paragraph --><p>A short pastry case, a sharp lemon curd and a thin layer of burnt sugar on top. Bake the case blind for 20 minutes, then let it cool before you pour in the curd.</p><!-- /wp:paragraph -->',
		} );
		await rest( 'POST', `/wp/v2/categories/${ made.terms[ 0 ] }`, { name: 'Sweet things' } );
		await page.goto( `/wp-admin/user-edit.php?user_id=${ made.users[ 0 ] }` );
		await page.locator( '#role' ).selectOption( 'editor' );
		await page.locator( '#submit' ).click();
		await rest( 'DELETE', `/wp/v2/posts/${ made.posts[ 1 ] }` );
		wpEval( `wp_set_current_user( 1 ); update_option( 'blogname', 'Kitchen Notes' ); update_option( 'blogdescription', 'Recipes, menus and kitchen notes' );` );
		await context.close();
	} );

	test.afterAll( () => {
		made.posts.forEach( ( id ) => wp( 'post', 'delete', id, '--force' ) );
		made.users.forEach( ( id ) => wp( 'user', 'delete', id, '--yes', '--reassign=1' ) );
		made.terms.forEach( ( id ) => wp( 'term', 'delete', 'category', id ) );
		wpEval( `update_option( 'blogname', 'E2E' ); update_option( 'blogdescription', 'Just another site' );` );
		wpEval( 'AryanX\\Axle\\ActivityLogWithUndo\\Plugin::instance()->store()->truncate();' );
	} );

	test( '1: the log', async ( { page } ) => {
		await page.goto( PAGE );
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 8 );
		await expect( page.locator( '#the-list tr' ).first() ).toContainText( 'Saved with 1 other change' );
		await shot( page, 1 );
	} );

	test( '2: one change with its before and after values', async ( { page } ) => {
		await page.goto( `${ PAGE }&entry=${ latest( 'post_updated' ).id }` );
		await expect( page.locator( '.alu-text-diff' ) ).toBeVisible();
		await shot( page, 2 );
	} );

	test( '3: changed since, with Undo anyway', async ( { page } ) => {
		const first = JSON.parse( wp( 'activity-log-with-undo', 'list', '--format=json', '--fields=id,event,object_id', '--event=option_updated' ) ).filter( ( entry ) => entry.object_id === 'blogname' ).pop();
		await page.goto( `${ PAGE }&entry=${ first.id }` );
		await expect( page.locator( '.alu-conflict' ) ).toBeVisible();
		await shot( page, 3 );
	} );

	test( '4: the settings', async ( { page } ) => {
		await page.goto( `${ PAGE }&tab=settings` );
		await shot( page, 4 );
	} );

	test( '5: Undo asks first, and says what else it puts back', async ( { page } ) => {
		await page.goto( PAGE );
		await page.locator( '#the-list a.alu-undo' ).first().click();
		await expect( page.locator( '.alu-ask' ) ).toBeVisible();
		await shot( page, 5 );
	} );

	test( '6: roll back, the preview of everything since a moment', async ( { page } ) => {
		await page.goto( `${ PAGE }&tab=rollback&rb_choice=hour&rb_go=1` );
		await expect( page.locator( '.alu-rb-preview, .axle-card' ).first() ).toBeVisible();
		await shot( page, 6 );
	} );

	test( '7: alerts, rules and starter rules', async ( { page } ) => {
		await page.goto( `${ PAGE }&tab=alerts` );
		await shot( page, 7 );
	} );

	test( '8: "Undo: ..." in the admin bar', async ( { page } ) => {
		wp( 'activity-log-with-undo', 'settings', '--admin-bar=on' );
		await page.goto( '/wp-admin/index.php' );
		const node = page.locator( '#wp-admin-bar-activity-log-with-undo' );
		await expect( node ).toContainText( 'Undo:' );
		await node.hover();
		await shot( page, 8, { keepHover: true, clip: { x: 0, y: 0, width: 1280, height: 220 } } );
		wp( 'activity-log-with-undo', 'settings', '--admin-bar=off' );
	} );

	test( '9: this post\'s activity in the editor', async ( { page } ) => {
		const id = made.posts[ 0 ];
		wp( 'post', 'update', id, '--post_status=draft' );
		wp( 'post', 'update', id, '--post_status=publish' );
		wpEval( `wp_set_current_user( 1 ); wp_set_object_terms( ${ id }, array( (int) get_cat_ID( 'Sweet things' ) ), 'category', false ); stick_post( ${ id } );` );
		await openEditor( page, Number( id ) );
		await page.evaluate( () => window.wp.data.dispatch( 'core/edit-post' ).openGeneralSidebar( 'edit-post/document' ) );
		// The panel remembers if it was open: open it only when it is closed.
		const toggle = page.getByRole( 'button', { name: 'Activity on this post' } );
		if ( 'false' === ( await toggle.getAttribute( 'aria-expanded' ) ) ) {
			await toggle.click();
		}
		await expect( page.locator( '.alu-panel__entry' ).first() ).toBeVisible();
		await shot( page, 9 );
	} );
} );
