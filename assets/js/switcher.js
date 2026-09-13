/*
 * mcLogiora language switcher enhancement.
 *
 * The server-rendered noscript links remain the no-JavaScript fallback. This
 * small delegated listener keeps the dropdown keyboard-friendly while
 * avoiding inline event handlers that conflict with a site's CSP.
 */
(function () {
	'use strict';

	function initCompactSwitchers() {
		document.querySelectorAll('[data-mclogiora-compact="1"]').forEach(function (details) {
			var summary = details.querySelector('.mclogiora-switcher__summary');
			if (!summary || summary.dataset.mclogioraReady) {
				return;
			}

			summary.dataset.mclogioraReady = '1';
			var sync = function () {
				summary.setAttribute('aria-expanded', details.open ? 'true' : 'false');
			};
			details.addEventListener('toggle', sync);
			details.addEventListener('keydown', function (event) {
				if ('Escape' === event.key && details.open) {
					event.preventDefault();
					details.open = false;
					summary.focus();
				}

				if ('ArrowDown' === event.key && details.open && event.target === summary) {
					var first = details.querySelector('[role="menuitem"]:not(.is-unavailable)');
					if (first) {
						event.preventDefault();
						first.focus();
					}
				}
			});
			sync();
		});
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', initCompactSwitchers);
	} else {
		initCompactSwitchers();
	}

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
