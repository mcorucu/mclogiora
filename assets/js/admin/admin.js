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

		if ( ! submit || ! form.checkValidity() ) {
			return;
		}

		submit.disabled = true;
		submit.setAttribute( 'aria-disabled', 'true' );
	} );
}() );
