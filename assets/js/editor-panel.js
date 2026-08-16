/**
 * mcLogiora translation panel for the Block Editor.
 *
 * Written against the WordPress editor packages WordPress itself registers
 * (wp-plugins, wp-editor, wp-element, wp-components, wp-i18n), with no JSX and
 * no bundler. The file shipped is the file written, which keeps the plugin
 * readable to a WordPress.org reviewer and means no build artefact can drift
 * away from its source.
 *
 * `wp.editor.PluginDocumentSettingPanel` is used rather than the
 * `wp.editPost` export of the same name: the latter has been deprecated since
 * WordPress 6.6 and logs a console deprecation on every editor load.
 *
 * WordPress 7.1 renders the editing canvas in an iframe. Nothing in this file
 * touches the canvas, reads editor internals, or queries the DOM. It renders
 * data prepared on the server into stable editor chrome.
 */
( function ( wp, data ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.editor || ! wp.element || ! data ) {
		return;
	}

	var registerPlugin = wp.plugins.registerPlugin;
	var PluginDocumentSettingPanel = wp.editor.PluginDocumentSettingPanel;
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var Button = wp.components.Button;

	if ( ! PluginDocumentSettingPanel ) {
		return;
	}


	var useState = wp.element.useState;
	var suggestions = data.suggestions || null;

	/**
	 * Posts to admin-ajax and resolves the decoded payload.
	 *
	 * The server owns every decision this request depends on -- capability,
	 * nonce, translation context, provider readiness and which text is
	 * actually translated. This function carries an object id and a field
	 * name, never the text to translate, because the endpoint resolves the
	 * authoritative source itself. Sending the source from here would make the
	 * browser able to choose what the site owner pays to translate.
	 *
	 * @param {string} action  AJAX action name.
	 * @param {Object} payload Extra fields.
	 * @return {Promise} Resolves with the response data, rejects with a message.
	 */
	function request( action, payload ) {
		var body = new window.FormData();

		body.append( 'action', action );
		body.append( 'nonce', suggestions.nonce );
		body.append( 'objectId', data.objectId );

		Object.keys( payload || {} ).forEach( function ( key ) {
			body.append( key, payload[ key ] );
		} );

		return window
			.fetch( window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json().catch( function () {
					return null;
				} );
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error(
						json && json.data && json.data.message
							? json.data.message
							: __( 'The suggestion request could not be completed.', 'mclogiora' )
					);
				}

				return json.data;
			} );
	}

	/**
	 * Reflects an applied value in the editor without dirtying the post.
	 *
	 * The server has already written the field, so the editor's *saved*
	 * baseline is what is stale -- not its edits. Updating the baseline record
	 * makes the new value visible while leaving the post clean, which matters
	 * because `editPost()` would mark it dirty and invite the user to save a
	 * second, redundant write of text the server already stored.
	 *
	 * If the entity API is unavailable the value is simply not reflected; the
	 * server remains authoritative and a reload shows the applied text. That is
	 * a worse experience, never a wrong one.
	 *
	 * @param {string} field Field name.
	 * @param {string} value Persisted value.
	 */
	function reflectPersistedValue( field, value ) {
		if ( ! wp.data || ! wp.data.select || ! wp.data.dispatch ) {
			return;
		}

		var editor = wp.data.select( 'core/editor' );
		var core = wp.data.dispatch( 'core' );

		if ( ! editor || ! core || ! core.receiveEntityRecords || ! data.postType ) {
			return;
		}

		var record = wp.data.select( 'core' ).getEntityRecord( 'postType', data.postType, data.objectId );

		if ( ! record ) {
			return;
		}

		var updated = Object.assign( {}, record );

		if ( 'title' === field ) {
			updated.title = Object.assign( {}, record.title, { raw: value, rendered: value } );
		} else {
			updated.excerpt = Object.assign( {}, record.excerpt, { raw: value, rendered: value } );
		}

		core.receiveEntityRecords( 'postType', data.postType, [ updated ] );
	}

	/**
	 * Renders the suggestion controls for one field.
	 *
	 * Each field owns its own state. A title request landing while an excerpt
	 * request is in flight must not disturb the other, so nothing here is
	 * shared between the two instances.
	 *
	 * @param {Object} props Field label and name.
	 * @return {Object} Element.
	 */
	function SuggestionField( props ) {
		var stateHook = useState( 'idle' );
		var phase = stateHook[ 0 ];
		var setPhase = stateHook[ 1 ];

		var previewHook = useState( null );
		var preview = previewHook[ 0 ];
		var setPreview = previewHook[ 1 ];

		var errorHook = useState( '' );
		var error = errorHook[ 0 ];
		var setError = errorHook[ 1 ];

		function fail( message ) {
			setError( message );
			setPhase( preview ? 'preview' : 'idle' );
		}

		function generate() {
			if ( 'loading' === phase || 'applying' === phase ) {
				return;
			}

			setError( '' );
			setPhase( 'loading' );

			request( suggestions.actions.generate, { field: props.field } )
				.then( function ( result ) {
					setPreview( result );
					setPhase( 'preview' );
				} )
				.catch( function ( err ) {
					setPreview( null );
					setError( err.message );
					setPhase( 'idle' );
				} );
		}

		function apply() {
			if ( ! preview || 'applying' === phase ) {
				return;
			}

			setError( '' );
			setPhase( 'applying' );

			request( suggestions.actions.apply, { field: props.field, token: preview.token } )
				.then( function ( result ) {
					reflectPersistedValue( props.field, result.text );
					setPreview( null );
					setPhase( 'idle' );
				} )
				.catch( function ( err ) {
					fail( err.message );
				} );
		}

		function discard() {
			if ( ! preview ) {
				return;
			}

			var token = preview.token;

			setPreview( null );
			setError( '' );
			setPhase( 'idle' );

			request( suggestions.actions.discard, { field: props.field, token: token } ).catch( function () {
				/*
				 * The preview is already gone from the screen and it expires
				 * on its own. Surfacing a failure to forget something would be
				 * noise the user cannot act on.
				 */
			} );
		}

		var busy = 'loading' === phase || 'applying' === phase;
		var children = [];

		children.push(
			el(
				'div',
				{ key: 'head', className: 'mclogiora-editor__row-head' },
				el( 'strong', null, props.label ),
				el(
					Button,
					{
						variant: 'secondary',
						isBusy: 'loading' === phase,
						disabled: busy,
						onClick: generate
					},
					preview
						? __( 'Regenerate', 'mclogiora' )
						: __( 'Generate suggestion', 'mclogiora' )
				)
			)
		);

		if ( busy ) {
			children.push(
				el(
					'p',
					{ key: 'busy', className: 'mclogiora-editor__meta', role: 'status' },
					'applying' === phase
						? __( 'Applying suggestion…', 'mclogiora' )
						: __( 'Asking the provider…', 'mclogiora' )
				)
			);
		}

		if ( error ) {
			children.push(
				el(
					'p',
					{ key: 'error', className: 'mclogiora-editor__notice', role: 'alert' },
					error
				)
			);
		}

		if ( preview ) {
			children.push(
				el( 'p', { key: 'text', className: 'mclogiora-editor__suggestion' }, preview.text )
			);

			children.push(
				el(
					'p',
					{ key: 'meta', className: 'mclogiora-editor__meta' },
					preview.model
						? sprintf(
							/* translators: 1: provider name, 2: model name. */
							__( 'Suggested by %1$s (%2$s)', 'mclogiora' ),
							suggestions.providerLabel,
							preview.model
						)
						: sprintf(
							/* translators: %s: provider name. */
							__( 'Suggested by %s', 'mclogiora' ),
							suggestions.providerLabel
						)
				)
			);

			children.push(
				el(
					'div',
					{ key: 'actions', className: 'mclogiora-editor__actions' },
					el(
						Button,
						{ variant: 'primary', disabled: busy, onClick: apply },
						__( 'Apply suggestion', 'mclogiora' )
					),
					el(
						Button,
						{ variant: 'tertiary', disabled: busy, onClick: discard },
						__( 'Discard', 'mclogiora' )
					)
				)
			);
		}

		return el( 'li', { className: 'mclogiora-editor__row' }, children );
	}

	/**
	 * Renders the Translation Suggestions section.
	 *
	 * Only title and excerpt are offered. A post body is serialised blocks or a
	 * builder payload, and Phase 16 deliberately does not translate those, so
	 * no control here implies that it does.
	 *
	 * @return {Object|null} Element.
	 */
	function renderSuggestions() {
		if ( ! suggestions || data.isSource ) {
			return null;
		}

		var body;

		if ( ! suggestions.available ) {
			body = [
				el( 'p', { key: 'why', className: 'mclogiora-editor__meta' }, suggestions.reason )
			];

			if ( suggestions.settingsUrl ) {
				body.push(
					el(
						Button,
						{ key: 'settings', variant: 'link', href: suggestions.settingsUrl },
						__( 'Translation Suggestions settings', 'mclogiora' )
					)
				);
			}
		} else {
			body = [
				el(
					'ul',
					{ key: 'fields', className: 'mclogiora-editor__list' },
					el( SuggestionField, { key: 'title', field: 'title', label: __( 'Title', 'mclogiora' ) } ),
					el( SuggestionField, { key: 'excerpt', field: 'excerpt', label: __( 'Excerpt', 'mclogiora' ) } )
				)
			];
		}

		return el(
			'div',
			{ className: 'mclogiora-editor__suggestions' },
			el( 'h3', null, __( 'Translation Suggestions', 'mclogiora' ) ),
			body
		);
	}

	/**
	 * Submits the create-translation request as a real form POST.
	 *
	 * The server owns translation creation: capability, nonce, validation,
	 * content policy and rollback all live in the workflow the Translation
	 * Manager already uses. Posting a form reaches exactly that endpoint and
	 * follows its redirect into the new draft, so the editor never duplicates
	 * creation logic and never needs authority of its own.
	 *
	 * @param {string} languageCode Target language code.
	 */
	function createTranslation( languageCode ) {
		var action = data.createAction;

		if ( ! action ) {
			return;
		}

		var form = document.createElement( 'form' );
		form.method = 'post';
		form.action = action.url;

		var fields = {
			action: action.action,
			source_id: action.sourceId,
			target_language: languageCode
		};

		fields[ action.nonceField ] = action.nonce;

		Object.keys( fields ).forEach( function ( name ) {
			var input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = name;
			input.value = fields[ name ];
			form.appendChild( input );
		} );

		document.body.appendChild( form );
		form.submit();
	}

	/**
	 * Renders the source-change notice for an outdated translation.
	 *
	 * @param {Object} row Language row.
	 * @return {Object|null} Element.
	 */
	function renderSourceChange( row ) {
		if ( ! row.needsUpdate || ! row.sourceChange ) {
			return null;
		}

		var lines = [ el( 'p', { key: 'msg' }, row.sourceChange.message ) ];

		if ( row.sourceChange.sourceModified ) {
			lines.push(
				el(
					'p',
					{ key: 'src', className: 'mclogiora-editor__meta' },
					sprintf( __( 'Source updated: %s', 'mclogiora' ), row.sourceChange.sourceModified )
				)
			);
		}

		if ( row.sourceChange.translationModified ) {
			lines.push(
				el(
					'p',
					{ key: 'trn', className: 'mclogiora-editor__meta' },
					sprintf( __( 'Translation updated: %s', 'mclogiora' ), row.sourceChange.translationModified )
				)
			);
		}

		return el( 'div', { className: 'mclogiora-editor__notice' }, lines );
	}

	/**
	 * Renders the actions available for one language.
	 *
	 * Only actions the server would accept are offered. A button that leads to
	 * a refusal is worse than no button.
	 *
	 * @param {Object} row Language row.
	 * @return {Object|null} Element.
	 */
	function renderActions( row ) {
		var actions = [];

		if ( row.isCurrent ) {
			if ( row.viewUrl ) {
				actions.push(
					el(
						Button,
						{ key: 'view', variant: 'link', href: row.viewUrl },
						__( 'View', 'mclogiora' )
					)
				);
			}
		} else if ( row.objectId && row.editUrl ) {
			actions.push(
				el(
					Button,
					{ key: 'edit', variant: 'link', href: row.editUrl },
					__( 'Edit translation', 'mclogiora' )
				)
			);

			if ( row.viewUrl ) {
				actions.push(
					el(
						Button,
						{ key: 'view', variant: 'link', href: row.viewUrl },
						__( 'View', 'mclogiora' )
					)
				);
			}
		} else if ( row.canCreate ) {
			actions.push(
				el(
					Button,
					{
						key: 'create',
						variant: 'secondary',
						onClick: function () {
							createTranslation( row.code );
						}
					},
					__( 'Create translation', 'mclogiora' )
				)
			);
		}

		if ( ! actions.length ) {
			return null;
		}

		return el( 'div', { className: 'mclogiora-editor__actions' }, actions );
	}

	/**
	 * Renders one language row.
	 *
	 * The status is always rendered as text. The tone class is a styling hint
	 * layered on top, never the only way to tell the states apart.
	 *
	 * @param {Object} row Language row.
	 * @return {Object} Element.
	 */
	function renderRow( row ) {
		return el(
			'li',
			{
				key: row.code,
				className: 'mclogiora-editor__row' + ( row.isCurrent ? ' is-current' : '' )
			},
			el(
				'div',
				{ className: 'mclogiora-editor__row-head' },
				el(
					'span',
					{ className: 'mclogiora-editor__language', lang: row.code, dir: row.direction },
					row.name
				),
				el(
					'span',
					{
						className: 'mclogiora-editor__status is-' + row.status.tone,
						title: row.status.description
					},
					row.status.label
				)
			),
			el( 'span', { className: 'screen-reader-text' }, row.accessibleLabel ),
			renderSourceChange( row ),
			renderActions( row )
		);
	}

	/**
	 * Renders the panel body.
	 *
	 * @return {Object} Element.
	 */
	function Panel() {
		var summary = el(
			'div',
			{ className: 'mclogiora-editor__summary' },
			el(
				'p',
				null,
				el( 'strong', null, __( 'Language', 'mclogiora' ) ),
				' ',
				el(
					'span',
					{ lang: data.currentLanguage.code, dir: data.currentLanguage.direction },
					data.currentLanguage.name
				)
			),
			el(
				'p',
				null,
				el( 'strong', null, __( 'Source', 'mclogiora' ) ),
				' ',
				el(
					'span',
					{ lang: data.sourceLanguage.code, dir: data.sourceLanguage.direction },
					data.sourceLanguage.name
				),
				data.sourceEditUrl
					? el(
						Button,
						{ variant: 'link', href: data.sourceEditUrl, className: 'mclogiora-editor__source-link' },
						__( 'Edit source', 'mclogiora' )
					)
					: null
			)
		);

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'mclogiora-translations',
				title: __( 'mcLogiora', 'mclogiora' ),
				className: 'mclogiora-editor'
			},
			el(
				Fragment,
				null,
				summary,
				el(
					'ul',
					{ className: 'mclogiora-editor__list' },
					( data.languages || [] ).map( renderRow )
				),
				renderSuggestions(),
				! data.canManage
					? el(
						'p',
						{ className: 'mclogiora-editor__meta' },
						__( 'You do not have permission to change translations.', 'mclogiora' )
					)
					: null
			)
		);
	}

	registerPlugin( 'mclogiora-translation-panel', { render: Panel } );
} )( window.wp, window.mcLogioraEditor );
