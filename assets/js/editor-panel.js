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
