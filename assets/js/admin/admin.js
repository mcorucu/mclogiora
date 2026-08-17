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
}() );
