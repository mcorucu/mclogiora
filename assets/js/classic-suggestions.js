/**
 * mcLogiora translation suggestions for the Classic Editor.
 *
 * Plain admin JavaScript, no build step and no framework, matching the rest of
 * the plugin's admin scripts. The server rendered the section and its buttons;
 * this file only fills in what happens below each button.
 *
 * The Classic Editor wraps the whole screen in `<form id="post">`. Two rules
 * follow from that and both are load-bearing:
 *
 * 1. Nothing here creates a form. Every action is an AJAX call to the same
 *    endpoints the Block Editor uses, so there is no nested form to be silently
 *    discarded by the HTML parser and no button that could submit the post.
 * 2. Applying a suggestion never submits the post. The server has already
 *    written the field; this file copies the persisted value into the visible
 *    input so the screen stops disagreeing with the database.
 */
( function ( wp, data ) {
	'use strict';

	if ( ! data || ! data.actions || ! data.nonce ) {
		return;
	}

	var __ = wp && wp.i18n ? wp.i18n.__ : function ( text ) {
		return text;
	};

	var sprintf = wp && wp.i18n ? wp.i18n.sprintf : null;

	/**
	 * Maps a suggestible field to the Classic input that displays it.
	 *
	 * These are WordPress core's own element ids. The server is not told about
	 * them: which DOM node shows a field is a property of this screen, not of
	 * the translation domain.
	 */
	var INPUTS = {
		title: 'title',
		excerpt: 'excerpt'
	};

	var fields = {};

	/**
	 * Posts to admin-ajax and resolves the decoded payload.
	 *
	 * Carries an object id and a field name, never the text to translate: the
	 * endpoint resolves the authoritative source itself. Sending the source from
	 * here would let the browser choose what the site owner pays to translate.
	 *
	 * @param {string} action  AJAX action name.
	 * @param {Object} payload Extra fields.
	 * @return {Promise} Resolves with the response data, rejects with a message.
	 */
	function request( action, payload ) {
		var body = new window.FormData();

		body.append( 'action', action );
		body.append( 'nonce', data.nonce );
		body.append( 'objectId', data.objectId );

		Object.keys( payload || {} ).forEach( function ( key ) {
			body.append( key, payload[ key ] );
		} );

		return window
			.fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
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
	 * Returns the provider attribution line for a preview.
	 *
	 * @param {Object} preview Preview payload.
	 * @return {string} Attribution text.
	 */
	function attribution( preview ) {
		if ( ! sprintf ) {
			return data.providerLabel;
		}

		if ( preview.model ) {
			return sprintf(
				/* translators: 1: provider name, 2: model name. */
				__( 'Suggested by %1$s (%2$s)', 'mclogiora' ),
				data.providerLabel,
				preview.model
			);
		}

		return sprintf(
			/* translators: %s: provider name. */
			__( 'Suggested by %s', 'mclogiora' ),
			data.providerLabel
		);
	}

	/**
	 * Creates an element with text content.
	 *
	 * Text is assigned rather than interpolated into markup, so a provider's
	 * suggestion can never be parsed as HTML on an admin screen.
	 *
	 * @param {string} tag Tag name.
	 * @param {string} className Class attribute.
	 * @param {string} text Text content.
	 * @return {HTMLElement} Element.
	 */
	function element( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( undefined !== text ) {
			node.textContent = text;
		}

		return node;
	}

	/**
	 * Creates a button that can never submit the post form.
	 *
	 * @param {string}   className Class attribute.
	 * @param {string}   label Visible label.
	 * @param {string}   accessibleLabel Accessible name.
	 * @param {Function} onClick Click handler.
	 * @param {boolean}  disabled Whether the button is disabled.
	 * @return {HTMLElement} Button.
	 */
	function button( className, label, accessibleLabel, onClick, disabled ) {
		var node = element( 'button', className, label );

		node.type = 'button';
		node.setAttribute( 'aria-label', accessibleLabel );

		if ( disabled ) {
			node.disabled = true;
		}

		node.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			onClick();
		} );

		return node;
	}

	/**
	 * Redraws one field's feedback region from its own state.
	 *
	 * Each field redraws only its own region, so a title request landing while
	 * an excerpt request is in flight cannot disturb the other.
	 *
	 * @param {string} name Field name.
	 * @return {void}
	 */
	function render( name ) {
		var field = fields[ name ];
		var busy = 'loading' === field.phase || 'applying' === field.phase;

		field.feedback.textContent = '';

		field.generate.disabled = busy;
		field.generate.textContent = field.preview
			? __( 'Regenerate', 'mclogiora' )
			: __( 'Generate suggestion', 'mclogiora' );
		field.generate.setAttribute(
			'aria-label',
			field.preview ? field.labels.regenerate : field.labels.generate
		);

		if ( busy ) {
			var status = element(
				'p',
				'mclogiora-editor__meta',
				'applying' === field.phase
					? __( 'Applying suggestion…', 'mclogiora' )
					: __( 'Asking the provider…', 'mclogiora' )
			);

			status.setAttribute( 'role', 'status' );
			field.feedback.appendChild( status );
		}

		if ( field.error ) {
			var error = element( 'p', 'mclogiora-editor__notice', field.error );

			error.setAttribute( 'role', 'alert' );
			field.feedback.appendChild( error );
		}

		if ( ! field.preview ) {
			return;
		}

		field.feedback.appendChild(
			element( 'p', 'mclogiora-editor__suggestion', field.preview.text )
		);

		field.feedback.appendChild(
			element( 'p', 'mclogiora-editor__meta', attribution( field.preview ) )
		);

		var actions = element( 'div', 'mclogiora-editor__actions' );

		actions.appendChild(
			button(
				'button button-primary',
				__( 'Apply suggestion', 'mclogiora' ),
				field.labels.apply,
				function () {
					apply( name );
				},
				busy
			)
		);

		actions.appendChild(
			button(
				'button button-link',
				__( 'Discard', 'mclogiora' ),
				field.labels.discard,
				function () {
					discard( name );
				},
				busy
			)
		);

		field.feedback.appendChild( actions );
	}

	/**
	 * Keeps focus somewhere useful after a region is redrawn.
	 *
	 * Apply and Discard remove the very button that was just activated, which
	 * drops focus to the document body and loses a keyboard user's place. Focus
	 * moves to the field's Generate button -- the one control that survives every
	 * redraw and the sensible next stop -- but only when focus was inside the
	 * region being replaced, so a user who has already clicked elsewhere is never
	 * yanked back.
	 *
	 * @param {Object}  field Field state.
	 * @param {boolean} wasInside Whether focus was inside the redrawn region.
	 * @return {void}
	 */
	function keepFocus( field, wasInside ) {
		if ( ! wasInside ) {
			return;
		}

		field.generate.focus();
	}

	/**
	 * Asks the provider for a suggestion. Writes nothing.
	 *
	 * @param {string} name Field name.
	 * @return {void}
	 */
	function generate( name ) {
		var field = fields[ name ];

		if ( 'loading' === field.phase || 'applying' === field.phase ) {
			return;
		}

		field.error = '';
		field.phase = 'loading';
		render( name );

		request( data.actions.generate, { field: name } )
			.then( function ( result ) {
				field.preview = result;
				field.phase = 'preview';
				render( name );
			} )
			.catch( function ( err ) {
				field.preview = null;
				field.error = err.message;
				field.phase = 'idle';
				render( name );
			} );
	}

	/**
	 * Applies the current preview.
	 *
	 * The server persists the value and answers with what it stored, which is
	 * then copied into the visible input. The post form is never submitted:
	 * this action is its own mutation and the field is only catching up.
	 *
	 * @param {string} name Field name.
	 * @return {void}
	 */
	function apply( name ) {
		var field = fields[ name ];

		if ( ! field.preview || 'applying' === field.phase ) {
			return;
		}

		var wasInside = field.feedback.contains( document.activeElement );

		field.error = '';
		field.phase = 'applying';
		render( name );

		request( data.actions.apply, { field: name, token: field.preview.token } )
			.then( function ( result ) {
				reflectPersistedValue( name, result.text );
				field.preview = null;
				field.phase = 'idle';
				render( name );
				keepFocus( field, wasInside );
			} )
			.catch( function ( err ) {
				field.error = err.message;
				field.phase = field.preview ? 'preview' : 'idle';
				render( name );
				keepFocus( field, wasInside );
			} );
	}

	/**
	 * Forgets the current preview, on the server and on the screen.
	 *
	 * @param {string} name Field name.
	 * @return {void}
	 */
	function discard( name ) {
		var field = fields[ name ];

		if ( ! field.preview ) {
			return;
		}

		var token = field.preview.token;
		var wasInside = field.feedback.contains( document.activeElement );

		field.preview = null;
		field.error = '';
		field.phase = 'idle';
		render( name );
		keepFocus( field, wasInside );

		request( data.actions.discard, { field: name, token: token } ).catch( function () {
			/*
			 * The preview is already gone from the screen and it expires on its
			 * own. Surfacing a failure to forget something would be noise the
			 * user cannot act on.
			 */
		} );
	}

	/**
	 * Copies a persisted value into the visible Classic input.
	 *
	 * WordPress overlays the title field with a prompt label that its own script
	 * only hides on focus, so a title written from here would otherwise appear
	 * underneath "Add title". If the expected input is missing the value is
	 * simply not reflected: the server remains authoritative and a reload shows
	 * the applied text, which is a worse experience but never a wrong one.
	 *
	 * @param {string} name Field name.
	 * @param {string} value Persisted value.
	 * @return {void}
	 */
	function reflectPersistedValue( name, value ) {
		var input = document.getElementById( INPUTS[ name ] );

		if ( ! input ) {
			return;
		}

		input.value = value;

		if ( 'title' !== name ) {
			return;
		}

		var prompt = document.getElementById( 'title-prompt-text' );

		if ( prompt && '' !== value ) {
			prompt.classList.add( 'screen-reader-text' );
		}
	}

	/**
	 * Wires the server-rendered rows to their behaviour.
	 *
	 * @return {void}
	 */
	function start() {
		var rows = document.querySelectorAll( '[data-mclogiora-field]' );

		Array.prototype.forEach.call( rows, function ( row ) {
			var name = row.getAttribute( 'data-mclogiora-field' );
			var generateButton = row.querySelector( '[data-mclogiora-generate]' );
			var feedback = row.querySelector( '[data-mclogiora-feedback]' );

			if ( ! name || ! generateButton || ! feedback || ! INPUTS[ name ] ) {
				return;
			}

			var visible = generateButton.getAttribute( 'aria-label' ) || '';

			fields[ name ] = {
				phase: 'idle',
				preview: null,
				error: '',
				generate: generateButton,
				feedback: feedback,
				labels: {
					generate: visible,
					regenerate: sprintf
						? sprintf(
							/* translators: %s: field name, for example Title. */
							__( 'Regenerate %s suggestion', 'mclogiora' ),
							row.querySelector( 'strong' ) ? row.querySelector( 'strong' ).textContent : name
						)
						: __( 'Regenerate', 'mclogiora' ),
					apply: sprintf
						? sprintf(
							/* translators: %s: field name, for example Title. */
							__( 'Apply %s suggestion', 'mclogiora' ),
							row.querySelector( 'strong' ) ? row.querySelector( 'strong' ).textContent : name
						)
						: __( 'Apply suggestion', 'mclogiora' ),
					discard: sprintf
						? sprintf(
							/* translators: %s: field name, for example Title. */
							__( 'Discard %s suggestion', 'mclogiora' ),
							row.querySelector( 'strong' ) ? row.querySelector( 'strong' ).textContent : name
						)
						: __( 'Discard', 'mclogiora' )
				}
			};

			generateButton.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				generate( name );
			} );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}( window.wp, window.mcLogioraClassicSuggestions ) );
