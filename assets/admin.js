/**
 * Tools > Activity Log: undo without a page load.
 *
 * Undo, Redo and Undo anyway are plain links that work without JavaScript.
 * With it, each one asks first, undoes through the REST API, and then
 * reloads the log. Other scripts can hook in:
 *
 *   wp.hooks.addFilter( 'activityLogWithUndo.confirmMessage', 'my-plugin', ( message, context ) => message );
 *   wp.hooks.addAction( 'activityLogWithUndo.undone', 'my-plugin', ( result, context ) => {} );
 *
 * Return an empty string from the filter to undo without asking.
 */
( function () {
	'use strict';

	// Read lazily, so hooks added by scripts that load later still count.
	function hooks() {
		return window.wp && window.wp.hooks ? window.wp.hooks : null;
	}

	function contextFor( link ) {
		return {
			entryId: Number( link.getAttribute( 'data-alu-entry' ) ),
			force: '1' === link.getAttribute( 'data-alu-force' ),
			redo: '1' === link.getAttribute( 'data-alu-redo' ),
			count: Number( link.getAttribute( 'data-alu-count' ) || 1 ),
			deletesUser: '1' === link.getAttribute( 'data-alu-deletes-user' ),
		};
	}

	document.addEventListener( 'click', function ( event ) {
		const link = event.target.closest ? event.target.closest( 'a.alu-undo' ) : null;
		if ( ! link || ! window.wp || ! window.wp.apiFetch ) {
			return;
		}
		event.preventDefault();
		if ( link.getAttribute( 'aria-disabled' ) === 'true' ) {
			return;
		}

		const context = contextFor( link );
		let message = link.getAttribute( 'data-alu-confirm' ) || '';
		const wpHooks = hooks();
		if ( wpHooks ) {
			/**
			 * Filters the question asked before an undo. An empty string skips it.
			 *
			 * @param {string} message Question.
			 * @param {Object} context entryId, force, redo, count, deletesUser.
			 */
			message = wpHooks.applyFilters( 'activityLogWithUndo.confirmMessage', message, context );
		}
		if ( message && ! window.confirm( String( message ) ) ) {
			return;
		}

		link.setAttribute( 'aria-disabled', 'true' );
		link.classList.add( 'is-busy' );

		window.wp
			.apiFetch( {
				path: '/activity-log-with-undo/v1/entries/' + context.entryId + '/undo',
				method: 'POST',
				data: { force: context.force },
			} )
			.then( function ( result ) {
				const later = hooks();
				if ( later ) {
					/**
					 * Fires after an undo finished, before the log reloads.
					 *
					 * @param {Object} result  undone, failed, entries, forced, redirect.
					 * @param {Object} context entryId, force, redo, count, deletesUser.
					 */
					later.doAction( 'activityLogWithUndo.undone', result, context );
				}
				window.location.href = result.redirect;
			} )
			.catch( function ( error ) {
				if ( error && error.data && error.data.redirect ) {
					window.location.href = error.data.redirect;
					return;
				}
				// Fall back to the link itself, which shows what went wrong.
				window.location.href = link.href;
			} );
	} );
} )();
