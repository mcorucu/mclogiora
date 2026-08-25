/**
 * mcLogiora admin foundation script.
 *
 * No settings, tracking, remote calls, or feature behavior are registered in Phase 02.
 */

( function () {
	'use strict';

	document.documentElement.classList.add( 'mclogiora-admin-ready' );

	document.addEventListener( 'change', function ( event ) {
		var control = event.target;

		if ( ! control || 'SELECT' !== control.tagName || ! control.hasAttribute( 'data-mclogiora-submit-on-change' ) ) {
			return;
		}

		if ( control.form ) {
			control.form.submit();
		}
	} );

	var heading = document.querySelector( '[data-mclogiora-focus-heading="1"]' );

	if ( heading ) {
		heading.focus();
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var submit = form && form.querySelector( 'button[type="submit"]' );

		if ( form && form.hasAttribute( 'data-mclogiora-confirm' ) && ! window.confirm( form.getAttribute( 'data-mclogiora-confirm' ) ) ) {
			event.preventDefault();
			return;
		}

		if ( ! submit || ! form.checkValidity() ) {
			return;
		}

		submit.disabled = true;
		submit.setAttribute( 'aria-disabled', 'true' );
	} );

	document.querySelectorAll( '[data-mclogiora-language-picker]' ).forEach( function ( picker ) {
		var search = picker.querySelector( '[data-mclogiora-language-search]' );
		var options = Array.prototype.slice.call( picker.querySelectorAll( '[data-mclogiora-language-option]' ) );
		var empty = picker.querySelector( '[data-mclogiora-language-empty]' );
		var submit = picker.querySelector( 'button[type="submit"]' );
		var primaryGroup = picker.querySelector( '[data-mclogiora-language-group="primary"]' );
		var targetGroup = picker.querySelector( '[data-mclogiora-language-group="target"]' );

		var sync = function () {
			var term = search ? search.value.toLocaleLowerCase() : '';
			var visible = 0;

			options.forEach( function ( option ) {
				var matches = ! term || ( option.getAttribute( 'data-search' ) || '' ).toLocaleLowerCase().indexOf( term ) !== -1;
				option.hidden = ! matches;
				if ( matches ) {
					visible += 1;
				}
			} );

			if ( empty ) {
				empty.hidden = visible > 0;
			}

			if ( submit ) {
				submit.disabled = ! picker.querySelector( '[data-mclogiora-language-choice]:checked' );
			}

			if ( primaryGroup && targetGroup ) {
				var primary = primaryGroup.querySelector( '[data-mclogiora-language-choice]:checked' );
				var primaryValue = primary ? primary.value : '';
				targetGroup.querySelectorAll( '[data-mclogiora-language-choice]' ).forEach( function ( target ) {
					target.disabled = target.value === primaryValue;
					if ( target.disabled ) {
						target.checked = false;
					}
				} );
			}
		};

		if ( search ) {
			search.addEventListener( 'input', sync );
		}

		picker.addEventListener( 'change', sync );
		sync();
	} );
}() );
