/**
 * Smart Portal Suite - Account-Sync Admin JavaScript
 * Handles single user sync, sequential batch sync, and Nextcloud DB connectivity test.
 */

(function ($) {
	'use strict';

	$(document).ready(function () {
		var ajaxUrl = spsSyncData.ajaxUrl;
		var nonce   = spsSyncData.nonce;

		// 1. Nextcloud DB Connection Test
		var $testDbBtn = $('#sps-test-db-btn');
		var $dbResultBox = $('#sps-db-test-result');

		if ($testDbBtn.length) {
			$testDbBtn.on('click', function (e) {
				e.preventDefault();

				$testDbBtn.prop('disabled', true);
				var originalText = $testDbBtn.html();
				$testDbBtn.html('<span class="spinner is-active sps-inline-spinner"></span> ' + (spsSyncData.i18n.testingDb || 'Prüfe DB-Verbindung...'));

				$dbResultBox.hide().empty();

				$.ajax({
					url: ajaxUrl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'sps_test_nc_db',
						nonce: nonce
					}
				}).done(function (res) {
					var cls = res.success ? 'notice-success' : 'notice-error';
					var msg = res.data && res.data.message ? res.data.message : 'Unbekannte Antwort.';
					$dbResultBox.html('<div class="notice ' + cls + ' inline" style="margin: 12px 0 0 0;"><p><strong>' + escapeHtml(msg) + '</strong></p></div>').slideDown(200);
				}).fail(function (xhr) {
					$dbResultBox.html('<div class="notice notice-error inline" style="margin: 12px 0 0 0;"><p><strong>Serverfehler beim DB-Verbindungstest (HTTP ' + xhr.status + ').</strong></p></div>').slideDown(200);
				}).always(function () {
					$testDbBtn.prop('disabled', false).html(originalText);
				});
			});
		}

		// 2. Single User Sync
		$(document).on('click', '.sps-sync-user-btn', function (e) {
			e.preventDefault();

			var $btn = $(this);
			var userId = $btn.data('user-id');
			if (!userId || $btn.prop('disabled')) {
				return;
			}

			$btn.prop('disabled', true);
			var originalHtml = $btn.html();
			$btn.html('<span class="spinner is-active sps-inline-spinner"></span> ' + (spsSyncData.i18n.syncing || 'Synchronisiere...'));

			var $row = $('#sps-user-row-' + userId);

			$.ajax({
				url: ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'sps_sync_single_user',
					user_id: userId,
					nonce: nonce
				}
			}).done(function (res) {
				if (res.success && res.data) {
					var d = res.data;
					// Update Badges
					$row.find('.cell-nc-status').html('<span class="sps-badge sps-badge-success">✔ ' + (spsSyncData.i18n.created || 'Angelegt') + '</span>');

					if (d.social_linked) {
						$row.find('.cell-social-status').html('<span class="sps-badge sps-badge-success">✔ ' + (spsSyncData.i18n.linked || 'Verknüpft') + '</span>');
					} else {
						$row.find('.cell-social-status').html('<span class="sps-badge sps-badge-warning">⚠ ' + (spsSyncData.i18n.dbMissing || 'Keine DB-Verknüpfung') + '</span>');
					}

					$row.find('.cell-synced-at').text(d.synced_at || 'Gerade eben');
					$btn.removeClass('button-primary').addClass('button-secondary').html('<span class="dashicons dashicons-yes" style="vertical-align: text-top; font-size: 15px;"></span> ' + (spsSyncData.i18n.resync || 'Erneut syncen'));
				} else {
					var err = res.data && res.data.message ? res.data.message : 'Fehler beim Synchronisieren.';
					alert(err);
					$btn.html(originalHtml);
				}
			}).fail(function (xhr) {
				alert('Serverfehler beim Synchronisieren (HTTP ' + xhr.status + ').');
				$btn.html(originalHtml);
			}).always(function () {
				$btn.prop('disabled', false);
			});
		});

		// 3. Batch User Sync
		var $batchBtn = $('#sps-batch-sync-btn');
		var $progressBox = $('#sps-batch-progress-box');
		var $progressBar = $('#sps-batch-progress-bar');
		var $progressText = $('#sps-batch-progress-text');

		if ($batchBtn.length) {
			$batchBtn.on('click', function (e) {
				e.preventDefault();

				var confirmMsg = spsSyncData.i18n.confirmBatch || 'Möchtest du alle ausstehenden Benutzer jetzt synchronisieren?';
				if (!window.confirm(confirmMsg)) {
					return;
				}

				$batchBtn.prop('disabled', true);
				$progressBox.slideDown(200);
				$progressBar.css('width', '5%');
				$progressText.text(spsSyncData.i18n.startingBatch || 'Starte Stapelverarbeitung...');

				// Ermittle alle User-IDs, die synchronisiert werden müssen
				var pendingIds = [];
				$('.sps-sync-user-btn[data-pending="1"]').each(function () {
					pendingIds.push($(this).data('user-id'));
				});

				if (!pendingIds.length) {
					$progressBar.css('width', '100%');
					$progressText.text(spsSyncData.i18n.noPending || 'Keine ausstehenden Benutzer gefunden.');
					$batchBtn.prop('disabled', false);
					return;
				}

				var total = pendingIds.length;
				var processed = 0;
				var errors = 0;

				function processNext() {
					if (pendingIds.length === 0) {
						$progressBar.css('width', '100%');
						$progressText.text((spsSyncData.i18n.batchComplete || 'Abgeschlossen:') + ' ' + processed + '/' + total + ' ' + (spsSyncData.i18n.usersSynced || 'Benutzer synchronisiert. Seite wird aktualisiert...'));
						setTimeout(function () {
							window.location.reload();
						}, 1500);
						return;
					}

					var currentId = pendingIds.shift();
					var currentPct = Math.round(((processed + 1) / total) * 100);

					$progressText.text((spsSyncData.i18n.syncingUser || 'Synchronisiere Benutzer-ID') + ' ' + currentId + ' (' + (processed + 1) + '/' + total + ')...');

					$.ajax({
						url: ajaxUrl,
						type: 'POST',
						dataType: 'json',
						data: {
							action: 'sps_sync_single_user',
							user_id: currentId,
							nonce: nonce
						}
					}).done(function (res) {
						if (!res.success) {
							errors++;
						}
					}).fail(function () {
						errors++;
					}).always(function () {
						processed++;
						$progressBar.css('width', currentPct + '%');
						processNext();
					});
				}

				processNext();
			});
		}

		function escapeHtml(string) {
			var entityMap = {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#39;'
			};
			return String(string).replace(/[&<>"']/g, function (s) {
				return entityMap[s];
			});
		}
	});
})(jQuery);
