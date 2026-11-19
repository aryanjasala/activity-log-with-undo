/**
 * "Activity on this post": the changes revisions do not keep, with Undo.
 *
 * No build step: plain wp.element calls.
 */
( function ( wp, config ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! config ) {
		return;
	}

	const { registerPlugin } = wp.plugins;
	const Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	if ( ! Panel ) {
		return;
	}

	const { createElement: h, useState, useEffect, useCallback, useRef } = wp.element;
	const { Button, Notice, Spinner } = wp.components;
	const { useSelect, select, dispatch } = wp.data;
	const { __, _n, sprintf } = wp.i18n;
	const apiFetch = wp.apiFetch;

	const hooks = () => wp.hooks;

	/**
	 * Reads the post again and puts it in the editor's store as the saved version.
	 * Without this the next Save would write the old value back.
	 *
	 * @param {string} postType Post type.
	 * @param {number} postId   Post ID.
	 * @return {Promise} Resolves when the store holds the fresh post.
	 */
	function refreshEditor( postType, postId ) {
		const type = select( 'core' ).getPostType( postType );
		const base = type && type.rest_base ? type.rest_base : postType;
		const space = type && type.rest_namespace ? type.rest_namespace : 'wp/v2';

		return apiFetch( { path: '/' + space + '/' + base + '/' + postId + '?context=edit' } ).then( function ( record ) {
			dispatch( 'core' ).receiveEntityRecords( 'postType', postType, [ record ], undefined, false );
			// Terms and the featured image are cached on their own.
			if ( dispatch( 'core' ).invalidateResolutionForStoreSelector ) {
				dispatch( 'core' ).invalidateResolutionForStoreSelector( 'getEntityRecords' );
			}
			return record;
		} );
	}

	function relative( iso ) {
		if ( window.axleUi && window.axleUi.relativeTime ) {
			return window.axleUi.relativeTime( iso );
		}
		return wp.date && wp.date.humanTimeDiff ? wp.date.humanTimeDiff( iso ) : iso;
	}

	function Entry( props ) {
		const entry = props.entry;
		const meta = entry.masked
			? ''
			: [ entry.who, relative( entry.when ) ].filter( Boolean ).join( ', ' );

		return h(
			'li',
			{ className: 'alu-panel__entry', style: { margin: '0 0 12px' } },
			h( 'div', { className: 'alu-panel__text' }, entry.description ),
			meta ? h( 'div', { className: 'alu-panel__meta', style: { color: '#50575e', fontSize: '12px' } }, meta ) : null,
			entry.undone ? h( 'div', { className: 'alu-panel__state', style: { color: '#50575e', fontSize: '12px' } }, __( 'Undone', 'activity-log-with-undo' ) ) : null,
			entry.can_undo && ! props.busy
				? h(
					Button,
					{
						variant: 'link',
						className: 'alu-panel__undo',
						onClick: function () {
							props.onAsk( entry, false );
						},
					},
					__( 'Undo', 'activity-log-with-undo' ),
					h( 'span', { className: 'screen-reader-text' }, ' ' + entry.description )
				)
				: null,
			! entry.can_undo && ! entry.undone && entry.reason
				? h( 'div', { className: 'alu-panel__reason', style: { color: '#50575e', fontSize: '12px' } }, entry.reason )
				: null
		);
	}

	function Activity() {
		const info = useSelect( function ( s ) {
			const editor = s( 'core/editor' );
			return {
				postId: editor.getCurrentPostId(),
				postType: editor.getCurrentPostType(),
				saving: editor.isSavingPost() && ! editor.isAutosavingPost(),
			};
		}, [] );
		const [ entries, setEntries ] = useState( null );
		const [ total, setTotal ] = useState( 0 );
		const [ error, setError ] = useState( '' );
		const [ ask, setAsk ] = useState( null );
		const [ busy, setBusy ] = useState( false );
		const [ conflict, setConflict ] = useState( '' );
		const wasSaving = useRef( false );

		const load = useCallback( function () {
			if ( ! info.postId ) {
				return Promise.resolve();
			}
			return apiFetch( { path: '/' + config.namespace + '/posts/' + info.postId + '/activity' } )
				.then( function ( data ) {
					setEntries( data.entries );
					setTotal( data.total );
					setError( '' );
				} )
				.catch( function ( failure ) {
					setError( failure && failure.message ? failure.message : __( 'The activity could not be loaded.', 'activity-log-with-undo' ) );
				} );
		}, [ info.postId ] );

		useEffect( function () {
			load();
		}, [ load ] );

		// A save can add entries: reload when one finishes.
		useEffect( function () {
			if ( wasSaving.current && ! info.saving ) {
				load();
			}
			wasSaving.current = info.saving;
		}, [ info.saving, load ] );

		function onAsk( entry, force ) {
			setConflict( '' );
			setAsk( { entry: entry, force: force } );
		}

		function confirm( force ) {
			const current = ask;
			setBusy( true );
			apiFetch( {
				path: '/' + config.namespace + '/posts/' + info.postId + '/activity/' + current.entry.id + '/undo',
				method: 'POST',
				data: { force: force },
			} )
				.then( function () {
					return refreshEditor( info.postType, info.postId );
				} )
				.then( function () {
					setAsk( null );
					wp.a11y.speak( __( 'Change undone.', 'activity-log-with-undo' ) );
					dispatch( 'core/notices' ).createSuccessNotice( __( 'Change undone.', 'activity-log-with-undo' ), { type: 'snackbar' } );
					if ( hooks() ) {
						/**
						 * Fires after a change was undone from the editor panel.
						 *
						 * @param {Object} entry The entry that was undone.
						 */
						hooks().doAction( 'activityLogWithUndo.panelUndone', current.entry );
					}
					return load();
				} )
				.catch( function ( failure ) {
					const text = failure && failure.message ? failure.message : __( 'The change was not undone.', 'activity-log-with-undo' );
					if ( failure && failure.code === 'activity_log_with_undo_conflict' ) {
						setConflict( text );
					} else {
						setError( text );
						setAsk( null );
					}
					wp.a11y.speak( text );
				} )
				.finally( function () {
					setBusy( false );
				} );
		}

		let askText = '';
		if ( ask ) {
			/* translators: %s: description of the change. */
			askText = sprintf( __( 'Undo this change? %s The undo is logged, so you can redo it from the log.', 'activity-log-with-undo' ), ask.entry.description );
			if ( hooks() ) {
				/**
				 * Filters the question shown before an undo in the editor panel.
				 *
				 * @param {string} message The question.
				 * @param {Object} entry   The entry.
				 */
				askText = hooks().applyFilters( 'activityLogWithUndo.panelConfirmMessage', askText, ask.entry );
			}
		}

		let body;
		if ( null === entries && ! error ) {
			body = h( Spinner );
		} else if ( entries && ! entries.length ) {
			body = h( 'p', { className: 'alu-panel__empty' }, __( 'No tracked change yet. Status, slug, author, template, featured image, terms, sticky and password changes show up here.', 'activity-log-with-undo' ) );
		} else if ( entries ) {
			body = h(
				'ul',
				{ className: 'alu-panel__list', style: { margin: 0, padding: 0, listStyle: 'none' } },
				entries.map( function ( entry ) {
					return h( Entry, { key: entry.id, entry: entry, busy: busy, onAsk: onAsk } );
				} )
			);
		}

		return h(
			'div',
			{ className: 'alu-panel' },
			error ? h( Notice, { status: 'error', isDismissible: false }, error ) : null,
			ask
				? h(
					Notice,
					{ status: conflict ? 'warning' : 'info', isDismissible: false, className: 'alu-panel__confirm' },
					h( 'p', null, conflict ? conflict : askText ),
					h(
						'div',
						{ style: { display: 'flex', gap: '8px', flexWrap: 'wrap' } },
						h( Button, { variant: 'primary', isBusy: busy, disabled: busy, onClick: function () {
							confirm( !! conflict );
						} }, conflict ? __( 'Undo anyway', 'activity-log-with-undo' ) : __( 'Undo this change', 'activity-log-with-undo' ) ),
						h( Button, { variant: 'secondary', disabled: busy, onClick: function () {
							setAsk( null );
							setConflict( '' );
						} }, __( 'Keep things as they are', 'activity-log-with-undo' ) )
					)
				)
				: null,
			body,
			total > ( entries ? entries.length : 0 )
				? h( 'p', { className: 'alu-panel__more', style: { color: '#50575e', fontSize: '12px' } }, sprintf(
					/* translators: 1: number shown, 2: total. */
					_n( 'Showing the latest %1$d of %2$d change.', 'Showing the latest %1$d of %2$d changes.', total, 'activity-log-with-undo' ),
					entries.length,
					total
				) )
				: null,
			config.logUrl ? h( 'p', null, h( 'a', { href: config.logUrl }, __( 'Open the whole activity log', 'activity-log-with-undo' ) ) ) : null
		);
	}

	registerPlugin( 'activity-log-with-undo', {
		render: function () {
			return h( Panel, { name: 'activity-log-with-undo-post', title: __( 'Activity on this post', 'activity-log-with-undo' ), className: 'alu-panel-wrap', initialOpen: false }, h( Activity ) );
		},
	} );
}( window.wp, window.activityLogWithUndoPanel ) );
