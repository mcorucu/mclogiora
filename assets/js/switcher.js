/*
 * mcLogiora language switcher enhancement.
 *
 * The server-rendered noscript links remain the no-JavaScript fallback. This
 * small delegated listener keeps the dropdown keyboard-friendly while
 * avoiding inline event handlers that conflict with a site's CSP.
 */
(function () {
	'use strict';

	document.addEventListener('change', function (event) {
		var select = event.target;

		if (!select || 'SELECT' !== select.tagName || !select.classList.contains('mclogiora-switcher__select')) {
			return;
		}

		if (select.value) {
			window.location.href = select.value;
		}
	});
}());
