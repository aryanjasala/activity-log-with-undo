// Records .github/media/demo.gif for the README. Run on demand: npm run demo
const { test, expect, wp, wpEval } = require( './utils' );
const { prepareSite, recordDemo } = require( './utils/media' );

const PAGE = '/wp-admin/tools.php?page=activity-log-with-undo';

test( 'demo: change a setting and a plugin, then undo both from the log', async ( { browser } ) => {
	test.setTimeout( 180_000 );
	prepareSite( 'Kitchen Notes' );
	wpEval( `update_option( 'blogdescription', 'Recipes from a small kitchen' );` );
	wp( 'plugin', 'activate', 'hello' );
	wpEval( 'AryanX\\Axle\\ActivityLogWithUndo\\Plugin::instance()->store()->truncate();' );

	try {
		await recordDemo( browser, async ( page, demo ) => {
			// Someone changes the tagline and switches off a plugin.
			await page.goto( '/wp-admin/options-general.php' );
			await demo.start();
			await demo.pause( 800 );
			await demo.type( page.locator( '#blogdescription' ), 'Closed for the summer' );
			await demo.click( page.locator( '#submit' ), 600 );
			await page.goto( '/wp-admin/plugins.php' );
			await demo.pause( 600 );
			await demo.click( page.locator( 'tr[data-plugin="hello.php"] .deactivate a' ), 600 );

			// The log has both, with Undo next to each.
			await page.goto( PAGE );
			await expect( page.locator( '#the-list tr' ) ).toHaveCount( 2 );
			await demo.moveTo( page.locator( '#the-list tr' ).first() );
			await demo.pause( 1500 );
			await demo.click( page.locator( '#the-list .row-title', { hasText: 'Changed Tagline' } ), 400 );
			await expect( page.locator( '.alu-diff-table' ) ).toBeVisible();
			await demo.moveTo( page.locator( '.alu-diff-table' ) );
			await demo.pause( 1800 );
			await demo.click( page.locator( '.alu-actions a.alu-undo' ), 300 );
			await expect( page.locator( '.alu-notice' ).first() ).toContainText( 'Undid 1 change' );
			await demo.pause( 1200 );

			const plugin = page.locator( '#the-list tr', { hasText: 'Deactivated the Hello Dolly plugin' } ).locator( 'a.alu-undo' );
			await demo.click( plugin, 300 );
			await expect( page.locator( '.alu-notice' ).first() ).toContainText( 'Undid 1 change' );
			await demo.moveTo( page.locator( '#the-list tr' ).first() );
			await demo.pause( 1800 );
		} );
		expect( wp( 'option', 'get', 'blogdescription' ) ).toBe( 'Recipes from a small kitchen' );
		expect( wp( 'plugin', 'list', '--status=active', '--field=name' ) ).toContain( 'hello' );
	} finally {
		wp( 'plugin', 'deactivate', 'hello' );
		wpEval( `update_option( 'blogname', 'E2E' ); update_option( 'blogdescription', 'Just another site' );` );
		wpEval( 'AryanX\\Axle\\ActivityLogWithUndo\\Plugin::instance()->store()->truncate();' );
	}
} );
