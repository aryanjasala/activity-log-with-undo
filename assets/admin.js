/**
 * Tools > Activity Log: undo without a page load.
 *
 * Undo, Redo and Undo anyway are plain links that work without JavaScript.
 * With it, each one asks first, undoes through the REST API, and then
 * reloads the log. When the undo is refused (another plugin blocks writes,
 * the change cannot be undone), the reason shows in a notice on the same
 * page. Other scripts can hook in:
 *
 *   wp.hooks.addFilter( 'activityLogWithUndo.confirmMessage', 'my-plugin', ( message, context ) => message );
 *   wp.hooks.addAction( 'activityLogWithUndo.undone', 'my-plugin', ( result, context ) => {} );
 *   wp.hooks.addAction( 'activityLogWithUndo.undoFailed', 'my-plugin', ( error, context ) => {} );
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
		let also = [];
		try {
			also = JSON.parse( link.getAttribute( 'data-alu-also' ) || '[]' );
		} catch ( error ) {
			also = [];
		}

		return {
			entryId: Number( link.getAttribute( 'data-alu-entry' ) ),
			force: '1' === link.getAttribute( 'data-alu-force' ),
			redo: '1' === link.getAttribute( 'data-alu-redo' ),
			count: Number( link.getAttribute( 'data-alu-count' ) || 1 ),
			deletesUser: '1' === link.getAttribute( 'data-alu-deletes-user' ),
			also: Array.isArray( also ) ? also : [],
		};
	}

	// Shows an error under the page heading, replacing the one shown before.
	function showError( message ) {
		const page = document.querySelector( '.alu-page' ) || document.querySelector( '.wrap' );
		if ( ! page ) {
			return;
		}
		const old = page.querySelector( '.alu-undo-error' );
		if ( old ) {
			old.remove();
		}

		const notice = document.createElement( 'div' );
		notice.className = 'notice notice-error inline alu-notice alu-undo-error';
		notice.setAttribute( 'role', 'alert' );
		const text = document.createElement( 'p' );
		text.textContent = message;
		notice.appendChild( text );

		const anchor = page.querySelector( '.wp-header-end' );
		if ( anchor && anchor.parentNode ) {
			anchor.parentNode.insertBefore( notice, anchor.nextSibling );
		} else {
			page.insertBefore( notice, page.firstChild );
		}
	}

	document.addEventListener( 'click', function ( event ) {
		const link = event.target.closest ? event.target.closest( 'a.alu-undo' ) : null;
		// Without the API client the link itself undoes the change, through admin-post.php.
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
			 * @param {Object} context entryId, force, redo, count, deletesUser, also.
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
					 * @param {Object} context entryId, force, redo, count, deletesUser, also.
					 */
					later.doAction( 'activityLogWithUndo.undone', result, context );
				}
				window.location.href = result.redirect;
			} )
			.catch( function ( error ) {
				// Changed since: the entry screen shows the differences and Undo anyway.
				if ( error && error.data && error.data.redirect ) {
					window.location.href = error.data.redirect;
					return;
				}

				link.removeAttribute( 'aria-disabled' );
				link.classList.remove( 'is-busy' );
				showError( error && error.message ? String( error.message ) : ( window.activityLogWithUndo && window.activityLogWithUndo.notUndone ) || 'The change was not undone.' );

				const later = hooks();
				if ( later ) {
					/**
					 * Fires when an undo was refused or failed. The page stays as it is.
					 *
					 * @param {Object} error   code, message, data.
					 * @param {Object} context entryId, force, redo, count, deletesUser, also.
					 */
					later.doAction( 'activityLogWithUndo.undoFailed', error, context );
				}
			} );
	} );
} )();
