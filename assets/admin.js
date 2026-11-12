/**
 * Tools > Activity Log: undo and roll back without a page load.
 *
 * Undo, Redo and Undo anyway are plain links that work without JavaScript.
 * With it, each one first says what will change, in place (an .axle-confirm
 * panel, never a browser dialog), then undoes through the REST API and
 * reloads the log. When the undo is refused (another plugin blocks writes,
 * the change cannot be undone), the reason shows in a notice on the same
 * page. A roll back runs in small steps through the REST API, with a progress
 * bar. Other scripts can hook in:
 *
 *   wp.hooks.addFilter( 'activityLogWithUndo.confirmMessage', 'my-plugin', ( message, context ) => message );
 *   wp.hooks.addAction( 'activityLogWithUndo.undone', 'my-plugin', ( result, context ) => {} );
 *   wp.hooks.addAction( 'activityLogWithUndo.undoFailed', 'my-plugin', ( error, context ) => {} );
 *   wp.hooks.addAction( 'activityLogWithUndo.rollbackProgress', 'my-plugin', ( state ) => {} );
 *   wp.hooks.addAction( 'activityLogWithUndo.rollbackDone', 'my-plugin', ( state ) => {} );
 *
 * Return an empty string from the filter to undo without asking.
 */
( function () {
	'use strict';

	const text = window.activityLogWithUndo || {};
	let panelCounter = 0;

	// Read lazily, so hooks added by scripts that load later still count.
	function hooks() {
		return window.wp && window.wp.hooks ? window.wp.hooks : null;
	}

	function speak( message, politeness ) {
		if ( window.wp && window.wp.a11y && window.wp.a11y.speak && message ) {
			window.wp.a11y.speak( message, politeness || 'polite' );
		}
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
		const paragraph = document.createElement( 'p' );
		paragraph.textContent = message;
		notice.appendChild( paragraph );

		const anchor = page.querySelector( '.axle-intro' ) || page.querySelector( '.wp-header-end' );
		if ( anchor && anchor.parentNode ) {
			anchor.parentNode.insertBefore( notice, anchor.nextSibling );
		} else {
			page.insertBefore( notice, page.firstChild );
		}
		speak( message, 'assertive' );
	}

	function element( tag, className, content ) {
		const node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( content ) {
			node.textContent = content;
		}
		return node;
	}

	// Closes the open confirm panel, if any, and puts focus back where it came from.
	function closePanel( panel, returnFocus ) {
		if ( ! panel || ! panel.parentNode ) {
			return;
		}
		const holder = panel.closest( '.alu-confirm-row' );
		( holder || panel ).remove();
		if ( returnFocus && returnFocus.focus ) {
			returnFocus.focus();
		}
	}

	/**
	 * Asks in place, before anything changes.
	 *
	 * @param {Element}  anchor  The link or button that started it.
	 * @param {Object}   options message, note, verb, danger, onConfirm.
	 */
	function ask( anchor, options ) {
		document.querySelectorAll( '.alu-ask' ).forEach( function ( open ) {
			closePanel( open, null );
		} );

		panelCounter += 1;
		const id = 'alu-ask-' + panelCounter;
		const panel = element( 'div', 'axle-confirm alu-ask' + ( options.danger ? ' axle-confirm--danger' : '' ) );
		panel.setAttribute( 'role', 'group' );
		panel.setAttribute( 'aria-labelledby', id );
		panel.setAttribute( 'tabindex', '-1' );

		const heading = element( 'p', '' );
		heading.id = id;
		const strong = element( 'strong', '', options.message );
		heading.appendChild( strong );
		panel.appendChild( heading );
		if ( options.note ) {
			panel.appendChild( element( 'p', '', options.note ) );
		}

		const actions = element( 'p', 'axle-actions' );
		const yes = element( 'button', 'button ' + ( options.danger ? 'axle-button--danger' : 'button-primary' ), options.verb );
		yes.type = 'button';
		const no = element( 'button', 'button', text.keep || 'Keep things as they are' );
		no.type = 'button';
		actions.appendChild( yes );
		actions.appendChild( no );
		panel.appendChild( actions );

		const row = anchor.closest( 'tr' );
		if ( row && row.parentNode ) {
			const confirmRow = element( 'tr', 'alu-confirm-row' );
			const cell = element( 'td' );
			cell.colSpan = row.children.length;
			cell.appendChild( panel );
			confirmRow.appendChild( cell );
			row.parentNode.insertBefore( confirmRow, row.nextSibling );
		} else {
			const host = anchor.closest( '.alu-actions' ) || anchor.parentNode;
			host.parentNode.insertBefore( panel, host.nextSibling );
		}

		yes.addEventListener( 'click', function () {
			closePanel( panel, null );
			options.onConfirm();
		} );
		no.addEventListener( 'click', function () {
			closePanel( panel, anchor );
		} );
		panel.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				closePanel( panel, anchor );
			}
		} );

		panel.focus();
		speak( options.message );
	}

	function busy( link, on ) {
		const spinnerId = 'alu-spinner-' + link.getAttribute( 'data-alu-entry' );
		let spinner = document.getElementById( spinnerId );
		if ( on ) {
			link.setAttribute( 'aria-disabled', 'true' );
			link.setAttribute( 'aria-busy', 'true' );
			link.classList.add( 'is-busy' );
			if ( ! spinner ) {
				spinner = element( 'span', 'spinner is-active alu-spinner' );
				spinner.id = spinnerId;
				spinner.setAttribute( 'role', 'status' );
				link.parentNode.insertBefore( spinner, link.nextSibling );
			}
			speak( text.working || 'Working' );
		} else {
			link.removeAttribute( 'aria-disabled' );
			link.removeAttribute( 'aria-busy' );
			link.classList.remove( 'is-busy' );
			if ( spinner ) {
				spinner.remove();
			}
		}
	}

	function runUndo( link, context ) {
		busy( link, true );

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

				busy( link, false );
				showError( error && error.message ? String( error.message ) : text.notUndone || 'The change was not undone.' );
				link.focus();

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
			 * Filters what the confirm step says before an undo. An empty string skips the step.
			 *
			 * @param {string} message What will change.
			 * @param {Object} context entryId, force, redo, count, deletesUser, also.
			 */
			message = wpHooks.applyFilters( 'activityLogWithUndo.confirmMessage', message, context );
		}

		if ( ! message ) {
			runUndo( link, context );
			return;
		}

		ask( link, {
			message: String( message ),
			note: link.getAttribute( 'data-alu-note' ) || '',
			verb: link.getAttribute( 'data-alu-verb' ) || link.textContent,
			danger: context.force || context.deletesUser,
			onConfirm: function () {
				runUndo( link, context );
			},
		} );
	} );

	// Bulk undo asks first too.
	document.addEventListener( 'submit', function ( event ) {
		const form = event.target;
		if ( ! form.classList || ! form.classList.contains( 'alu-log' ) || form.getAttribute( 'data-alu-confirmed' ) ) {
			return;
		}
		const sender = event.submitter;
		if ( sender && 'doaction' !== sender.id && 'doaction2' !== sender.id ) {
			return;
		}
		const top = form.querySelector( '#bulk-action-selector-top' );
		const bottom = form.querySelector( '#bulk-action-selector-bottom' );
		const action = ( top && top.value !== '-1' ? top.value : '' ) || ( bottom && bottom.value !== '-1' ? bottom.value : '' );
		if ( 'undo' !== action ) {
			return;
		}

		event.preventDefault();
		const picked = form.querySelectorAll( 'input[name="entry[]"]:checked' ).length;
		const submitter = event.submitter || form.querySelector( '#doaction' );
		if ( ! picked ) {
			showError( text.bulkNone || 'Select at least one change to undo.' );
			return;
		}

		ask( submitter, {
			message: ( text.bulkMessage || 'This undoes %d selected changes.' ).replace( '%d', String( picked ) ),
			note: '',
			verb: text.bulkVerb || 'Undo the selected changes',
			danger: false,
			onConfirm: function () {
				form.setAttribute( 'data-alu-confirmed', '1' );
				form.submit();
			},
		} );
	} );

	// Panels that start closed (the delete step on the settings screen). Without JavaScript they stay open.
	function collapsible() {
		document.querySelectorAll( '[data-alu-collapsible]' ).forEach( function ( panel ) {
			const trigger = document.querySelector( '[data-alu-reveal="' + panel.id + '"]' );
			const cancel = panel.querySelector( '[data-alu-cancel]' );
			if ( ! trigger ) {
				return;
			}
			panel.hidden = true;
			trigger.hidden = false;
			if ( cancel ) {
				cancel.hidden = false;
			}
			trigger.addEventListener( 'click', function () {
				panel.hidden = false;
				trigger.hidden = true;
				trigger.setAttribute( 'aria-expanded', 'true' );
				panel.setAttribute( 'tabindex', '-1' );
				panel.focus();
			} );
			function close() {
				panel.hidden = true;
				trigger.hidden = false;
				trigger.setAttribute( 'aria-expanded', 'false' );
				trigger.focus();
			}
			if ( cancel ) {
				cancel.addEventListener( 'click', close );
			}
			panel.addEventListener( 'keydown', function ( event ) {
				if ( 'Escape' === event.key ) {
					close();
				}
			} );
		} );
	}

	// A roll back: ask the server for the next batch until it is done.
	function rollback() {
		const job = document.querySelector( '.alu-job[data-alu-job]' );
		if ( ! job || ! window.wp || ! window.wp.apiFetch ) {
			return;
		}

		const id = job.getAttribute( 'data-alu-job' );
		const bar = job.querySelector( '.axle-progress' );
		const barFill = job.querySelector( '.axle-progress__bar' );
		const label = job.querySelector( '.alu-job-text' );
		const spinner = job.querySelector( '.alu-spinner' );
		const errorBox = job.querySelector( '.alu-job-error' );
		const form = job.querySelector( '.alu-job-continue' );
		const button = form ? form.querySelector( 'input[type="submit"], button[type="submit"]' ) : null;
		let running = false;

		function setBusy( on ) {
			running = on;
			job.setAttribute( 'aria-busy', on ? 'true' : 'false' );
			if ( spinner ) {
				spinner.classList.toggle( 'is-active', on );
			}
			if ( button ) {
				button.disabled = on;
			}
		}

		function show( state ) {
			const percent = state.total ? Math.floor( ( state.done / state.total ) * 100 ) : 0;
			if ( bar ) {
				bar.setAttribute( 'aria-valuenow', String( percent ) );
			}
			if ( barFill ) {
				barFill.style.width = percent + '%';
			}
			if ( label ) {
				const message = ( text.rollbackStep || 'Undid %1$d of %2$d groups of changes.' ).replace( '%1$d', String( state.done ) ).replace( '%2$d', String( state.total ) );
				const node = label.firstChild;
				if ( node && 3 === node.nodeType ) {
					node.nodeValue = message + ' ';
				}
				speak( message );
			}
		}

		function next() {
			if ( running ) {
				return;
			}
			setBusy( true );
			if ( errorBox ) {
				errorBox.hidden = true;
			}

			window.wp
				.apiFetch( { path: '/activity-log-with-undo/v1/rollback/' + id + '/step', method: 'POST' } )
				.then( function ( state ) {
					show( state );
					const wpHooks = hooks();
					if ( wpHooks ) {
						/**
						 * Fires after each batch of a roll back.
						 *
						 * @param {Object} state state, done, total, undone, skipped, summary, deferred.
						 */
						wpHooks.doAction( 'activityLogWithUndo.rollbackProgress', state );
					}
					if ( 'running' === state.state ) {
						setBusy( false );
						next();
						return;
					}
					if ( wpHooks ) {
						/**
						 * Fires when a roll back ended, before the log reloads.
						 *
						 * @param {Object} state state, done, total, undone, skipped, summary, deferred, redirect.
						 */
						wpHooks.doAction( 'activityLogWithUndo.rollbackDone', state );
					}
					speak( text.rollbackDone || 'The roll back is finished.' );
					window.location.href = state.redirect;
				} )
				.catch( function ( error ) {
					setBusy( false );
					const message = error && error.message ? String( error.message ) : '';
					if ( errorBox ) {
						errorBox.textContent = ( message ? message + ' ' : '' ) + ( text.rollbackError || 'The roll back stopped. Press Continue to pick it up where it stopped.' );
						errorBox.className = 'notice notice-error inline alu-job-error';
						errorBox.hidden = false;
					}
					speak( errorBox ? errorBox.textContent : message, 'assertive' );
				} );
		}

		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				next();
			} );
		}
		if ( '1' === job.getAttribute( 'data-alu-auto' ) ) {
			next();
		}
	}

	function announceNotices() {
		const notice = document.querySelector( '.alu-notice:not(.alu-undo-error)' );
		if ( notice ) {
			speak( notice.textContent.trim() );
		}
	}

	function ready() {
		collapsible();
		rollback();
		announceNotices();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', ready );
	} else {
		ready();
	}
} )();
