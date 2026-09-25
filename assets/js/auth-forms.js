/**
 * Smart Portal Suite - Authentication Forms JavaScript
 * Handles button loading state, double-submit protection, error recovery, and custom event dispatching.
 */

(function () {
	'use strict';

	var successEventFired = false;
	var successPatterns = [
		'registrierung erfolgreich',
		'prüfe dein postfach',
		'link wurde gesendet',
		'anmeldelink wurde gesendet'
	];

	/**
	 * Prüft, ob im Container eine Erfolgsmeldung sichtbar ist.
	 */
	function checkForSuccess(wrapper) {
		if (successEventFired || !wrapper) {
			return;
		}

		var text = (wrapper.innerText || '').toLowerCase();
		var matched = successPatterns.some(function (pattern) {
			return text.indexOf(pattern) !== -1;
		});

		if (matched) {
			successEventFired = true;

			// Dispatch Custom DOM Event for external Analytics / GTM / Tracking
			var event = new CustomEvent('sps_registration_success', {
				detail: {
					timestamp: new Date().toISOString(),
					type: wrapper.classList.contains('sps-registration-wrapper') ? 'registration' : 'login'
				}
			});
			document.dispatchEvent(event);
		}
	}

	/**
	 * Button sperren und Ladezustand anzeigen.
	 */
	function setButtonLoading(btn, isRegister) {
		if (!btn || btn.disabled) {
			return;
		}

		var originalText = btn.value || btn.innerText || (isRegister ? 'Registrieren' : 'Anmelden');
		btn.dataset.originalText = originalText;
		btn.disabled = true;

		var loadingText = isRegister ? 'Wird registriert...' : 'Wird gesendet...';
		if (btn.tagName.toLowerCase() === 'input') {
			btn.value = loadingText;
		} else {
			btn.innerText = loadingText;
		}

		btn.style.opacity = '0.65';
		btn.style.cursor = 'not-allowed';
		btn.style.pointerEvents = 'none';
	}

	/**
	 * Button reaktivieren und Originaltext wiederherstellen.
	 */
	function resetButton(btn) {
		if (!btn) {
			return;
		}

		btn.disabled = false;
		var fallback = btn.dataset.originalText || 'Absenden';

		if (btn.tagName.toLowerCase() === 'input') {
			btn.value = fallback;
		} else {
			btn.innerText = fallback;
		}

		btn.style.opacity = '1';
		btn.style.cursor = 'pointer';
		btn.style.pointerEvents = 'auto';
	}

	/**
	 * Initialisierung für alle SPS-Auth-Formulare auf der Seite.
	 */
	function initAuthForms() {
		var wrappers = document.querySelectorAll('.sps-auth-wrapper');
		if (!wrappers.length) {
			return;
		}

		wrappers.forEach(function (wrapper) {
			var isRegister = wrapper.classList.contains('sps-registration-wrapper');

			// Sofortige Prüfung nach Page-Reload
			checkForSuccess(wrapper);

			// 1. Submit-Event abfangen
			wrapper.addEventListener('submit', function (e) {
				var submitBtn = wrapper.querySelector('input[type="submit"], button[type="submit"], .magic-login-submit');
				if (submitBtn) {
					setTimeout(function () {
						setButtonLoading(submitBtn, isRegister);
					}, 10);
				}

				var checkInterval = setInterval(function () {
					checkForSuccess(wrapper);
				}, 500);

				setTimeout(function () {
					clearInterval(checkInterval);
				}, 10000);
			}, true);

			// 2. DOM-Mutationen beobachten (z. B. wenn Magic Login per AJAX Fehler einblendet)
			if (window.MutationObserver) {
				var observer = new MutationObserver(function () {
					checkForSuccess(wrapper);

					var errorBox = wrapper.querySelector('#login_error, .login-error, .magic-login-error, .message.error, .error');
					if (errorBox && errorBox.innerText.trim().length > 0) {
						var submitBtn = wrapper.querySelector('input[type="submit"], button[type="submit"], .magic-login-submit');
						resetButton(submitBtn);
					}
				});

				observer.observe(wrapper, {
					childList: true,
					subtree: true,
					characterData: true
				});
			}

			// 3. Fallback über jQuery AJAX falls vorhanden
			if (typeof jQuery !== 'undefined') {
				jQuery(document).ajaxComplete(function (event, xhr, settings) {
					var submitBtn = wrapper.querySelector('input[type="submit"], button[type="submit"], .magic-login-submit');
					if (xhr.responseText && xhr.responseText.toLowerCase().indexOf('erfolgreich') !== -1) {
						setTimeout(function () {
							checkForSuccess(wrapper);
						}, 300);
					} else {
						setTimeout(function () {
							resetButton(submitBtn);
						}, 400);
					}
				});
			}
		});
	}

	/**
	 * Header Auth Buttons Redirect Speicherung
	 */
	function initHeaderButtons() {
		var buttons = document.querySelectorAll('.sps-header-auth-buttons a.btn-login, .sps-header-auth-buttons a.btn-register');
		if (!buttons.length) {
			return;
		}

		var currentPath = window.location.pathname + window.location.search;
		buttons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				document.cookie = 'sps_redirect=' + encodeURIComponent(currentPath) + '; path=/; max-age=3600';
				document.cookie = 'qc_redirect=' + encodeURIComponent(currentPath) + '; path=/; max-age=3600';
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			initAuthForms();
			initHeaderButtons();
		});
	} else {
		initAuthForms();
		initHeaderButtons();
	}
})();
