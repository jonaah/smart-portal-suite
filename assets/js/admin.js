/**
 * Smart Portal Suite - Admin JavaScript
 * Handles color picker initialization, live connection tests, and debug log actions.
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		// 1. Initialize Color Picker
		if ($.fn.wpColorPicker) {
			$('.sps-color-picker').wpColorPicker();
		}

		// 2. Nextcloud Live Connection Test
		var $testBtn = $('#sps-run-connection-test');
		var $resultsBox = $('#sps-test-results');

		if ($testBtn.length) {
			$testBtn.on('click', function(e) {
				e.preventDefault();

				$testBtn.prop('disabled', true);
				var originalBtnHtml = $testBtn.html();
				$testBtn.html('<span class="spinner is-active sps-spinner"></span> ' + (spsAdminData.i18n.testing || 'Verbindung wird geprüft...'));

				$resultsBox.show().html('<p style="color: #646970;"><span class="spinner is-active sps-spinner" style="float:none; margin: 0 6px 0 0;"></span>' + (spsAdminData.i18n.testing || 'Prüfung läuft, bitte warten...') + '</p>');

				$.ajax({
					url: spsAdminData.ajaxUrl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'sps_test_connection',
						nonce: spsAdminData.diagNonce
					}
				}).done(function(response) {
					if (!response.success || !response.data) {
						var errMsg = response.data && response.data.message ? response.data.message : (spsAdminData.i18n.testFailed || 'Verbindungstest fehlgeschlagen.');
						$resultsBox.html('<div class="notice notice-error inline" style="margin: 0;"><p><strong>' + escapeHtml(errMsg) + '</strong></p></div>');
						return;
					}

					var data = response.data;
					var html = '';

					// Summary banner
					if (data.success) {
						html += '<div class="notice notice-success inline" style="margin: 0 0 14px 0;"><p><strong>' + (spsAdminData.i18n.testSuccess || 'Verbindungstest erfolgreich!') + '</strong> Die Nextcloud-Instanz ist erreichbar und voll einsatzbereit.</p></div>';
					} else {
						html += '<div class="notice notice-error inline" style="margin: 0 0 14px 0;"><p><strong>' + (spsAdminData.i18n.testFailed || 'Verbindungstest unvollständig oder fehlgeschlagen.') + '</strong> Bitte die markierten Schritte unten prüfen.</p></div>';
					}

					// Steps details
					if (data.steps && data.steps.length) {
						html += '<div class="sps-steps-list">';
						data.steps.forEach(function(step) {
							var iconClass = 'dashicons-yes-alt sps-icon-success';
							if (step.status === 'error') {
								iconClass = 'dashicons-dismiss sps-icon-error';
							} else if (step.status === 'warning') {
								iconClass = 'dashicons-warning sps-icon-warning';
							}

							html += '<div class="sps-test-step">';
							html += '  <span class="dashicons ' + iconClass + ' sps-step-icon"></span>';
							html += '  <div class="sps-step-content">';
							html += '    <div class="sps-step-title">' + escapeHtml(step.title) + '</div>';
							html += '    <div class="sps-step-msg">' + escapeHtml(step.message) + '</div>';
							html += '  </div>';
							html += '</div>';
						});
						html += '</div>';
					}

					$resultsBox.html(html);
				}).fail(function(jqXHR) {
					var errText = 'Serverfehler beim Verbindungstest (HTTP ' + jqXHR.status + ')';
					$resultsBox.html('<div class="notice notice-error inline" style="margin: 0;"><p><strong>' + escapeHtml(errText) + '</strong></p></div>');
				}).always(function() {
					$testBtn.prop('disabled', false).html(originalBtnHtml);
				});
			});
		}

		// 3. Clear Debug Log
		var $clearBtn = $('#sps-clear-log-btn');
		if ($clearBtn.length) {
			$clearBtn.on('click', function(e) {
				e.preventDefault();

				var confirmMsg = spsAdminData.i18n.confirmClear || 'Möchtest du das Debug-Log wirklich leeren?';
				if (!window.confirm(confirmMsg)) {
					return;
				}

				$clearBtn.prop('disabled', true);

				$.ajax({
					url: spsAdminData.ajaxUrl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'sps_clear_log',
						nonce: spsAdminData.diagNonce
					}
				}).done(function(response) {
					if (response.success) {
						var $card = $clearBtn.closest('.sps-card');
						$card.find('.inside').html('<div class="sps-empty-log"><p class="description">' + (spsAdminData.i18n.logCleared || 'Debug-Log erfolgreich geleert.') + '</p></div>');
					} else {
						alert(response.data && response.data.message ? response.data.message : 'Fehler beim Leeren des Logs.');
					}
				}).fail(function() {
					alert('Verbindungsfehler beim Leeren des Logs.');
				}).always(function() {
					$clearBtn.prop('disabled', false);
				});
			});
		}

		// 4. Copy System Report to Clipboard
		var $copyBtn = $('#sps-copy-report-btn');
		if ($copyBtn.length) {
			$copyBtn.on('click', function(e) {
				e.preventDefault();
				var reportText = $(this).attr('data-report');
				if (!reportText) {
					reportText = $(this).closest('.sps-card').find('textarea').val();
				}

				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(reportText).then(function() {
						showCopiedFeedback($copyBtn);
					}).catch(function() {
						fallbackCopy(reportText, $copyBtn);
					});
				} else {
					fallbackCopy(reportText, $copyBtn);
				}
			});
		}

		function showCopiedFeedback($btn) {
			var origHtml = $btn.html();
			$btn.html('<span class="dashicons dashicons-yes" style="vertical-align: text-top; font-size: 14px;"></span> ' + (spsAdminData.i18n.copied || 'Kopiert!'));
			setTimeout(function() {
				$btn.html(origHtml);
			}, 2500);
		}

		function fallbackCopy(text, $btn) {
			var $temp = $('<textarea>');
			$('body').append($temp);
			$temp.val(text).select();
			document.execCommand('copy');
			$temp.remove();
			showCopiedFeedback($btn);
		}

		function escapeHtml(text) {
			if (!text) return '';
			return $('<div>').text(text).html();
		}
	});
})(jQuery);
