/**
 * mcLogiora translation suggestions for the admin screens.
 *
 * Plain admin JavaScript, no build step and no framework. The server rendered
 * each control and told it, in data attributes, which object and language it
 * speaks for; this file only fills in what happens below the button.
 *
 * One file serves every admin surface. What differs between a string, a term
 * and an attachment field is entirely server-side -- which source value is
 * authoritative and where the applied value is stored -- so nothing here needs
 * to know. A control carries a surface name, an object id and a target
 * language, and that is the whole contract.
 *
 * Every button is `type="button"` and no form is created, because these screens
 * are full of forms and a control that submitted one would save the wrong thing.
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
	 * Posts to admin-ajax and resolves the decoded payload.
	 *
	 * Carries a surface, an object id and a target language, never the text to
	 * translate: the endpoint resolves the authoritative source itself. Sending
	 * the source from here would let the browser choose what the site owner pays
	 * to translate.
	 *
	 * @param {string} action  AJAX action name.
	 * @param {Object} control Control state.
	 * @param {Object} payload Extra fields.
	 * @return {Promise} Resolves with the response data, rejects with a message.
	 */
	function request( action, control, payload ) {
		var body = new window.FormData();

		body.append( 'action', action );
		body.append( 'nonce', data.nonce );
		body.append( 'surface', control.surface );
		body.append( 'objectId', control.objectId );
		body.append( 'language', control.language );

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
	 * Creates a button that can never submit a form on the page.
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
	 * Keeps focus somewhere useful after a region is redrawn.
	 *
	 * Apply and Discard remove the very button that was just activated, which
	 * drops focus to the document body and loses a keyboard user's place. Focus
	 * moves to the control's Generate button -- the one control that survives
	 * every redraw -- but only when focus was inside the region being replaced.
	 *
	 * @param {Object}  control Control state.
	 * @param {boolean} wasInside Whether focus was inside the redrawn region.
	 * @return {void}
	 */
	function keepFocus( control, wasInside ) {
		if ( ! wasInside ) {
			return;
		}

		control.generate.focus();
	}

	/**
	 * Redraws one control's feedback region from its own state.
	 *
	 * Each control redraws only its own region, so a request for one string or
	 * field landing while another is in flight cannot disturb it.
	 *
	 * @param {Object} control Control state.
	 * @return {void}
	 */
	function render( control ) {
		var busy = 'loading' === control.phase || 'applying' === control.phase;

		control.feedback.textContent = '';

		control.generate.disabled = busy;
		control.generate.textContent = control.preview
			? __( 'Regenerate', 'mclogiora' )
			: __( 'Generate suggestion', 'mclogiora' );
		control.generate.setAttribute(
			'aria-label',
			control.preview ? control.labels.regenerate : control.labels.generate
		);

		if ( busy ) {
			var status = element(
				'p',
				'mclogiora-editor__meta',
				'applying' === control.phase
					? __( 'Applying suggestion…', 'mclogiora' )
					: __( 'Asking the provider…', 'mclogiora' )
			);

			status.setAttribute( 'role', 'status' );
			control.feedback.appendChild( status );
		}

		if ( control.error ) {
			var error = element( 'p', 'mclogiora-editor__notice', control.error );

			error.setAttribute( 'role', 'alert' );
			control.feedback.appendChild( error );
		}

		if ( ! control.preview ) {
			return;
		}

		control.feedback.appendChild(
			element( 'p', 'mclogiora-editor__suggestion', control.preview.text )
		);

		control.feedback.appendChild(
			element( 'p', 'mclogiora-editor__meta', attribution( control.preview ) )
		);

		var actions = element( 'div', 'mclogiora-editor__actions' );

		actions.appendChild(
			button(
				'button button-primary',
				__( 'Apply suggestion', 'mclogiora' ),
				control.labels.apply,
				function () {
					apply( control );
				},
				busy
			)
		);

		actions.appendChild(
			button(
				'button button-link',
				__( 'Discard', 'mclogiora' ),
				control.labels.discard,
				function () {
					discard( control );
				},
				busy
			)
		);

		control.feedback.appendChild( actions );
	}

	/**
	 * Asks the provider for a suggestion. Writes nothing.
	 *
	 * @param {Object} control Control state.
	 * @return {void}
	 */
	function generate( control ) {
		if ( 'loading' === control.phase || 'applying' === control.phase ) {
			return;
		}

		control.error = '';
		control.phase = 'loading';
		render( control );

		request( data.actions.generate, control, {} )
			.then( function ( result ) {
				control.preview = result;
				control.phase = 'preview';
				render( control );
			} )
			.catch( function ( err ) {
				control.preview = null;
				control.error = err.message;
				control.phase = 'idle';
				render( control );
			} );
	}

	/**
	 * Applies the current preview.
	 *
	 * The server persists the value and answers with what it stored, which is
	 * then copied into the visible field so the screen stops disagreeing with
	 * the database. No form is submitted: this action is its own mutation.
	 *
	 * @param {Object} control Control state.
	 * @return {void}
	 */
	function apply( control ) {
		if ( ! control.preview || 'applying' === control.phase ) {
			return;
		}

		var wasInside = control.feedback.contains( document.activeElement );

		control.error = '';
		control.phase = 'applying';
		render( control );

		request( data.actions.apply, control, { token: control.preview.token } )
			.then( function ( result ) {
				reflectPersistedValue( control, result.text );
				control.preview = null;
				control.phase = 'idle';
				render( control );
				keepFocus( control, wasInside );
			} )
			.catch( function ( err ) {
				control.error = err.message;
				control.phase = control.preview ? 'preview' : 'idle';
				render( control );
				keepFocus( control, wasInside );
			} );
	}

	/**
	 * Forgets the current preview, on the server and on the screen.
	 *
	 * @param {Object} control Control state.
	 * @return {void}
	 */
	function discard( control ) {
		if ( ! control.preview ) {
			return;
		}

		var token = control.preview.token;
		var wasInside = control.feedback.contains( document.activeElement );

		control.preview = null;
		control.error = '';
		control.phase = 'idle';
		render( control );
		keepFocus( control, wasInside );

		request( data.actions.discard, control, { token: token } ).catch( function () {
			/*
			 * The preview is already gone from the screen and it expires on its
			 * own. Surfacing a failure to forget something would be noise the
			 * user cannot act on.
			 */
		} );
	}

	/**
	 * Copies a persisted value into the visible field, when there is one.
	 *
	 * If the control names no field, or the field has gone, the value is simply
	 * not reflected: the server remains authoritative and a reload shows the
	 * applied text, which is a worse experience but never a wrong one.
	 *
	 * @param {Object} control Control state.
	 * @param {string} value Persisted value.
	 * @return {void}
	 */
	function reflectPersistedValue( control, value ) {
		if ( ! control.fieldId ) {
			return;
		}

		var field = document.getElementById( control.fieldId );

		if ( field ) {
			field.value = value;
		}
	}

	/**
	 * Wires the server-rendered controls to their behaviour.
	 *
	 * @return {void}
	 */
	function start() {
		var nodes = document.querySelectorAll( '[data-mclogiora-suggest]' );

		Array.prototype.forEach.call( nodes, function ( node ) {
			var surface = node.getAttribute( 'data-surface' );
			var objectId = node.getAttribute( 'data-object' );
			var language = node.getAttribute( 'data-language' );
			var generateButton = node.querySelector( '[data-mclogiora-generate]' );
			var feedback = node.querySelector( '[data-mclogiora-feedback]' );

			if ( ! surface || ! objectId || ! language || ! generateButton || ! feedback ) {
				return;
			}

			var fieldLabel = node.getAttribute( 'data-field-label' ) || '';

			var control = {
				surface: surface,
				objectId: objectId,
				language: language,
				fieldId: node.getAttribute( 'data-field' ) || '',
				phase: 'idle',
				preview: null,
				error: '',
				generate: generateButton,
				feedback: feedback,
				labels: {
					generate: generateButton.getAttribute( 'aria-label' ) || '',
					regenerate: label( 'Regenerate %s suggestion', fieldLabel, 'Regenerate' ),
					apply: label( 'Apply %s suggestion', fieldLabel, 'Apply suggestion' ),
					discard: label( 'Discard %s suggestion', fieldLabel, 'Discard' )
				}
			};

			generateButton.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				generate( control );
			} );
		} );
	}

	/**
	 * Builds an accessible action name that names its field.
	 *
	 * @param {string} pattern Translatable pattern with one placeholder.
	 * @param {string} fieldLabel Field name, for example "String" or "Term name".
	 * @param {string} fallback Name to use when no field label is available.
	 * @return {string} Accessible name.
	 */
	function label( pattern, fieldLabel, fallback ) {
		if ( ! sprintf || '' === fieldLabel ) {
			return __( fallback, 'mclogiora' );
		}

		/* translators: %s: field name, for example String or Term name. */
		return sprintf( __( pattern, 'mclogiora' ), fieldLabel );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}( window.wp, window.mcLogioraAdminSuggestions ) );
