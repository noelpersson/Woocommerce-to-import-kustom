/**
 * WooCommerce Product Export – admin-interaktioner och mikro-animationer.
 */
(function () {
	'use strict';

	// Micro-interaktion för att spara filter asynkront
	window.saveGlobalFilters = function (e) {
		if (e && e.preventDefault) {
			e.preventDefault();
		}

		var form = document.getElementById('shared-filters-form');
		var btn = document.getElementById('save-btn');
		var indicator = document.getElementById('save-indicator');
		var timestamp = document.getElementById('last-saved-timestamp');

		if (!form || !btn) {
			return;
		}

		btn.disabled = true;
		var originalHtml = btn.innerHTML;
		btn.innerHTML = '<span class="material-symbols-outlined text-[16px] animate-spin">refresh</span><span>Sparar...</span>';

		var formData = new FormData(form);

		fetch(form.action, {
			method: 'POST',
			body: formData,
			headers: {
				'X-Requested-With': 'XMLHttpRequest'
			}
		}).then(function (response) {
			btn.disabled = false;
			btn.innerHTML = originalHtml;

			var now = new Date();
			var hours = String(now.getHours()).padStart(2, '0');
			var mins = String(now.getMinutes()).padStart(2, '0');
			if (timestamp) {
				timestamp.textContent = 'Senast sparat: Idag kl ' + hours + ':' + mins;
			}

			if (indicator) {
				indicator.classList.remove('hidden');
				indicator.classList.add('inline-flex');
				setTimeout(function () {
					indicator.classList.remove('inline-flex');
					indicator.classList.add('hidden');
				}, 3500);
			}
		}).catch(function () {
			// Fallback till vanlig submit om AJAX stöter på nätverksfel
			btn.disabled = false;
			btn.innerHTML = originalHtml;
			form.submit();
		});
	};

	// Persistent export-notis med aktiv laddningsindikator, timer och tidsuppskattning
	var activeExportBanner = null;
	var exportTimerInterval = null;

	window.showExportNotification = function (formatName, rowCount) {
		if (activeExportBanner && activeExportBanner.parentNode) {
			activeExportBanner.parentNode.removeChild(activeExportBanner);
		}
		if (exportTimerInterval) {
			clearInterval(exportTimerInterval);
		}

		var countText = rowCount ? ' (' + rowCount + ')' : '';
		var banner = document.createElement('div');
		banner.className = 'woo-export-loading-banner fixed bottom-6 right-6 bg-slate-900 text-white rounded-xl shadow-2xl p-4 flex flex-col gap-2 z-50 transition-all font-body-sm text-body-sm border border-slate-700';
		banner.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#0f172a;color:#ffffff;padding:16px 20px;border-radius:12px;box-shadow:0 15px 35px rgba(0,0,0,0.35);display:flex;flex-direction:column;gap:10px;z-index:999999;font-size:13px;font-family:Inter,sans-serif;max-width:440px;border:1px solid #334155;';

		var seconds = 0;

		banner.innerHTML =
			'<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">' +
				'<div style="display:flex;align-items:center;gap:10px;">' +
					'<span class="material-symbols-outlined text-blue-400 animate-spin" style="animation:woo-spin 1.2s linear infinite;font-size:22px;color:#60a5fa;">sync</span>' +
					'<strong style="font-size:14px;color:#f8fafc;font-weight:600;">Genererar export' + countText + '...</strong>' +
				'</div>' +
				'<button type="button" class="woo-export-close-btn" style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:20px;line-height:1;padding:0;margin:0;" title="Stäng">&times;</button>' +
			'</div>' +
			'<p style="margin:0;color:#cbd5e1;line-height:1.45;font-size:12.5px;">' +
				'Katalogen läses in batchvis (100 i taget). För stora kataloger (3 000+ produkter) tar detta normalt <strong>30–90 sekunder</strong>. Lämna inte sidan.' +
			'</p>' +
			'<div style="display:flex;align-items:center;justify-content:space-between;border-top:1px solid #1e293b;padding-top:8px;margin-top:2px;font-size:11.5px;color:#94a3b8;">' +
				'<span class="woo-export-timer-txt">Tid: 0s • Sparas i Exportarkivet</span>' +
				'<span style="color:#38bdf8;font-weight:500;">' + (formatName || 'Export') + '</span>' +
			'</div>';

		// Injicera animations-keyframe om den saknas
		if (!document.getElementById('woo-spin-style')) {
			var style = document.createElement('style');
			style.id = 'woo-spin-style';
			style.textContent = '@keyframes woo-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }';
			document.head.appendChild(style);
		}

		var container = document.getElementById('woo-kustom-export-root') || document.body;
		container.appendChild(banner);
		activeExportBanner = banner;

		var closeBtn = banner.querySelector('.woo-export-close-btn');
		if (closeBtn) {
			closeBtn.addEventListener('click', function () {
				if (banner.parentNode) {
					banner.parentNode.removeChild(banner);
				}
				if (exportTimerInterval) {
					clearInterval(exportTimerInterval);
				}
			});
		}

		var timerEl = banner.querySelector('.woo-export-timer-txt');
		exportTimerInterval = setInterval(function () {
			seconds += 1;
			if (timerEl) {
				if (seconds >= 30) {
					timerEl.innerHTML = 'Tid: ' + seconds + 's • Bearbetar batcher...';
				} else {
					timerEl.innerHTML = 'Tid: ' + seconds + 's • Sparas i Exportarkivet';
				}
			}
			if (seconds >= 120) {
				clearInterval(exportTimerInterval);
				if (banner.parentNode) {
					banner.style.opacity = '0';
					setTimeout(function () {
						if (banner.parentNode) banner.parentNode.removeChild(banner);
					}, 300);
				}
			}
		}, 1000);
	};

	// Visar toast-notis vid snabbexport eller sparande
	window.triggerQuickDownload = function (target) {
		if (target === 'save_ok') {
			var toast = document.createElement('div');
			toast.className = 'woo-toast-notify fixed bottom-6 right-6 bg-slate-900 text-white px-4 py-3 rounded-lg shadow-lg flex items-center gap-3 z-50 transition-all font-body-sm text-body-sm';
			toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#131b2e;color:#ffffff;padding:12px 18px;border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,0.18);display:flex;align-items:center;gap:10px;z-index:999999;font-size:13px;font-family:Inter,sans-serif;transition:opacity 0.3s ease;';
			toast.innerHTML = '<span class="material-symbols-outlined text-[18px]" style="color:#95f8a7;font-size:18px;">check_circle</span> Kustom POS-inställningarna har sparats!';
			var container = document.getElementById('woo-kustom-export-root') || document.body;
			container.appendChild(toast);

			setTimeout(function () {
				toast.style.opacity = '0';
				setTimeout(function () {
					if (toast.parentNode) {
						toast.parentNode.removeChild(toast);
					}
				}, 300);
			}, 2500);
			return;
		}

		var name = target === 'kustom' ? 'Kustom POS CSV' : 'Sello Excel (.xlsx)';
		window.showExportNotification(name);
	};

	document.addEventListener('DOMContentLoaded', function () {
		// Koppla ajax-sparande till delade filter på översikten
		var sharedForm = document.getElementById('shared-filters-form');
		if (sharedForm) {
			sharedForm.addEventListener('submit', function (e) {
				window.saveGlobalFilters(e);
			});
		}

		// Snabbval för valfria standardkolumner
		var selectAllFieldsBtn = document.querySelector('.js-woo-export-select-all-fields');
		var deselectAllFieldsBtn = document.querySelector('.js-woo-export-deselect-all-fields');
		var fieldCheckboxes = document.querySelectorAll('.js-woo-export-field-checkbox');

		if (selectAllFieldsBtn) {
			selectAllFieldsBtn.addEventListener('click', function (e) {
				e.preventDefault();
				fieldCheckboxes.forEach(function (cb) {
					cb.checked = true;
				});
			});
		}

		if (deselectAllFieldsBtn) {
			deselectAllFieldsBtn.addEventListener('click', function (e) {
				e.preventDefault();
				fieldCheckboxes.forEach(function (cb) {
					cb.checked = false;
				});
			});
		}

		// Snabbval för produktattribut
		var selectAllAttrsBtn = document.querySelector('.js-woo-export-select-all-attrs');
		var deselectAllAttrsBtn = document.querySelector('.js-woo-export-deselect-all-attrs');
		var attrCheckboxes = document.querySelectorAll('.js-woo-export-attr-checkbox');

		if (selectAllAttrsBtn) {
			selectAllAttrsBtn.addEventListener('click', function (e) {
				e.preventDefault();
				attrCheckboxes.forEach(function (cb) {
					cb.checked = true;
				});
			});
		}

		if (deselectAllAttrsBtn) {
			deselectAllAttrsBtn.addEventListener('click', function (e) {
				e.preventDefault();
				attrCheckboxes.forEach(function (cb) {
					cb.checked = false;
				});
			});
		}

		// Initialisera den moderna Kustom POS export-sidan
		initKustomExportTab();

		// Initialisera den moderna Sello export-sidan
		initSelloExportTab();
	});

	/**
	 * Logik för Kustom POS-fliken:
	 * Dynamisk realtidsuppdatering av kolumnordning, CSV-snippet, snabbval och asynkront sparande.
	 */
	function initKustomExportTab() {
		var kustomForm = document.getElementById('kustomSettingsForm');
		var standardGrid = document.getElementById('standardFieldsGrid');
		var selectAllBtn = document.getElementById('selectAllFields');
		var deselectAllBtn = document.getElementById('deselectAllFields');
		var resetBtn = document.getElementById('resetDefaultsBtn');
		var saveBtn = document.getElementById('saveSettingsBtn');
		var downloadBtn = document.getElementById('kustomDownloadCsvBtn') || document.getElementById('downloadCsvBtn');
		var toggleMoreBtn = document.getElementById('toggleMoreFieldsBtn');
		var moreFieldsContainer = document.getElementById('moreFieldsContainer');
		var columnsPreviewList = document.getElementById('columnsPreviewList');
		var columnCountBadge = document.getElementById('columnCountBadge');
		var activeAttrCount = document.getElementById('activeAttrCount');
		var totalAttrCount = document.getElementById('totalAttrCount');
		var csvPreviewCode = document.getElementById('csvPreviewCode');

		if (!standardGrid && !kustomForm) {
			return;
		}

		function escapeHtml(str) {
			return String(str)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;');
		}

		// Uppdatera kolumnöversikten och CSV-snippet i realtid
		function updatePreview() {
			var cols = [
				{ name: 'product_name', badge: 'KRAV', badgeClass: 'bg-secondary-fixed text-primary font-bold', sample1: '"Linnéskjorta Klassisk"', sample2: '"Linnéskjorta Klassisk"' },
				{ name: 'price_including_vat', badge: 'KRAV', badgeClass: 'bg-secondary-fixed text-primary font-bold', sample1: '799.00', sample2: '799.00' },
				{ name: 'vat_rate', badge: 'KRAV', badgeClass: 'bg-secondary-fixed text-primary font-bold', sample1: '25.00', sample2: '25.00' },
				{ name: 'currency', badge: 'KRAV', badgeClass: 'bg-secondary-fixed text-primary font-bold', sample1: '"SEK"', sample2: '"SEK"' }
			];

			// Valbara standardfält & extra fält
			var colCheckboxes = document.querySelectorAll('#kustomSettingsForm .js-col-checkbox:checked');
			colCheckboxes.forEach(function (cb) {
				var colLabel = cb.dataset.colLabel || cb.value;
				var sample1 = '"Värde"';
				var sample2 = '"Värde"';

				switch (colLabel) {
					case 'sku':
						sample1 = '"LSH-001-L"';
						sample2 = '"LSH-001-M"';
						break;
					case 'gtin':
					case 'ean':
						sample1 = '"735001234567"';
						sample2 = '"735001234568"';
						break;
					case 'regular_price':
						sample1 = '799.00';
						sample2 = '799.00';
						break;
					case 'sale_price':
						sample1 = '699.00';
						sample2 = '699.00';
						break;
					case 'stock_quantity':
						sample1 = '14';
						sample2 = '8';
						break;
					case 'image_url':
						sample1 = '"https://butik.se/linne-1.jpg"';
						sample2 = '"https://butik.se/linne-1.jpg"';
						break;
					case 'short_description':
						sample1 = '"Kort info"';
						sample2 = '"Kort info"';
						break;
					case 'category':
					case 'category_tree':
						sample1 = '"Skjortor"';
						sample2 = '"Skjortor"';
						break;
					default:
						sample1 = '"Info"';
						sample2 = '"Info"';
						break;
				}

				cols.push({
					name: colLabel,
					badge: 'TILLVAL',
					badgeClass: 'bg-surface-container text-on-surface-variant font-medium',
					sample1: sample1,
					sample2: sample2,
					key: cb.dataset.colId
				});
			});

			// Attribut
			var attrCheckboxes = document.querySelectorAll('#kustomSettingsForm .js-attr-checkbox');
			var checkedAttrs = 0;
			attrCheckboxes.forEach(function (cb) {
				if (cb.checked) {
					checkedAttrs++;
					var cleanName = (cb.dataset.attrLabel || cb.value || '').toLowerCase().trim();
					if (cleanName === 'skick' || cleanName === 'condition' || cleanName === 'pa_skick') {
						return;
					}
					var colName = cleanName.replace(/^attribute_/, '').replace(/^pa_/, '');
					var sample1 = '"Val 1"';
					var sample2 = '"Val 2"';

					if (cleanName.indexOf('storlek') !== -1 || cleanName.indexOf('size') !== -1) {
						sample1 = '"Large"';
						sample2 = '"Medium"';
					} else if (cleanName.indexOf('färg') !== -1 || cleanName.indexOf('farg') !== -1 || cleanName.indexOf('color') !== -1) {
						sample1 = '"Marinblå"';
						sample2 = '"Marinblå"';
					} else if (cleanName.indexOf('varumärke') !== -1 || cleanName.indexOf('brand') !== -1) {
						sample1 = '"Linthor"';
						sample2 = '"Linthor"';
					} else if (cleanName.indexOf('material') !== -1) {
						sample1 = '"100% Linne"';
						sample2 = '"100% Linne"';
					}

					cols.push({
						name: colName,
						badge: 'ATTRIBUT',
						badgeClass: 'bg-primary-fixed text-on-primary-fixed-variant font-semibold',
						sample1: sample1,
						sample2: sample2,
						key: cb.dataset.attrKey
					});
				}
			});

			// Uppdatera badges
			if (columnCountBadge) {
				columnCountBadge.textContent = cols.length;
			}
			if (activeAttrCount) {
				activeAttrCount.textContent = checkedAttrs;
			}
			if (totalAttrCount) {
				totalAttrCount.textContent = attrCheckboxes.length;
			}

			// Rendera kolumnlista
			if (columnsPreviewList) {
				var listHtml = '';
				cols.forEach(function (item, index) {
					var num = index + 1;
					var isReq = item.badge === 'KRAV';
					var bgClass = isReq ? 'bg-surface-container-low/90' : 'bg-surface-container-low';
					var textStyle = isReq ? 'font-semibold text-on-surface' : 'text-on-surface';
					listHtml += '<div class="flex items-center justify-between p-2 rounded-md ' + bgClass + '" data-preview-key="' + (item.key || item.name) + '">';
					listHtml += '<div class="flex items-center gap-space-sm">';
					listHtml += '<span class="font-code-sm text-code-sm text-outline font-semibold w-5 text-right">' + num + '</span>';
					listHtml += '<span class="font-code-sm text-code-sm ' + textStyle + '">' + escapeHtml(item.name) + '</span>';
					listHtml += '</div>';
					listHtml += '<span class="px-2 py-0.5 rounded-full font-label-sm text-label-sm tracking-wider ' + item.badgeClass + '">' + item.badge + '</span>';
					listHtml += '</div>';
				});
				columnsPreviewList.innerHTML = listHtml;
			}

			// Uppdatera CSV Snippet
			if (csvPreviewCode) {
				var headerRow = cols.map(function (c) { return '"' + c.name + '"'; }).join(',');
				var sampleRow1 = cols.map(function (c) { return c.sample1; }).join(',');
				var sampleRow2 = cols.map(function (c) { return c.sample2; }).join(',');
				csvPreviewCode.textContent = headerRow + '\n' + sampleRow1 + '\n' + sampleRow2;
			}
		}

		// Event listeners för checkboxar
		var allKustomCheckboxes = document.querySelectorAll('#kustomSettingsForm input[type="checkbox"]');
		allKustomCheckboxes.forEach(function (cb) {
			cb.addEventListener('change', updatePreview);
		});

		// Markera alla standardfält
		if (selectAllBtn) {
			selectAllBtn.addEventListener('click', function () {
				document.querySelectorAll('#kustomSettingsForm .js-col-checkbox').forEach(function (cb) {
					cb.checked = true;
				});
				updatePreview();
			});
		}

		// Avmarkera alla standardfält
		if (deselectAllBtn) {
			deselectAllBtn.addEventListener('click', function () {
				document.querySelectorAll('#kustomSettingsForm .js-col-checkbox').forEach(function (cb) {
					cb.checked = false;
				});
				updatePreview();
			});
		}

		// Återställ standard
		if (resetBtn) {
			resetBtn.addEventListener('click', function () {
				var standardIds = ['col-sku', 'col-gtin', 'col-reg-price', 'col-sale-price', 'col-stock', 'col-image', 'col-desc', 'col-cat'];
				document.querySelectorAll('#kustomSettingsForm .js-col-checkbox').forEach(function (cb) {
					cb.checked = standardIds.indexOf(cb.dataset.colId) !== -1;
				});

				document.querySelectorAll('#kustomSettingsForm .js-attr-checkbox').forEach(function (cb) {
					var val = (cb.value || '').toLowerCase();
					cb.checked = (val === 'storlek' || val === 'färg' || val === 'farg' || val === 'varumärke' || val === 'brand');
				});

				updatePreview();
			});
		}

		// Visa / dölj fler fält
		if (toggleMoreBtn && moreFieldsContainer) {
			toggleMoreBtn.addEventListener('click', function () {
				var isHidden = moreFieldsContainer.classList.contains('hidden');
				var icon = document.getElementById('toggleMoreFieldsIcon');
				var text = document.getElementById('toggleMoreFieldsText');
				if (isHidden) {
					moreFieldsContainer.classList.remove('hidden');
					if (icon) icon.textContent = 'expand_less';
					if (text) text.textContent = 'Dölj extra WooCommerce-fält';
				} else {
					moreFieldsContainer.classList.add('hidden');
					if (icon) icon.textContent = 'expand_more';
					if (text) text.textContent = 'Visa fler WooCommerce-fält (mått, frakt, produkt-ID m.m.)';
				}
			});
		}

		// Spara inställningar via AJAX
		if (kustomForm) {
			kustomForm.addEventListener('submit', function (e) {
				e.preventDefault();
				if (!saveBtn) {
					kustomForm.submit();
					return;
				}

				var originalContent = saveBtn.innerHTML;
				saveBtn.innerHTML = '<span class="material-symbols-outlined text-[18px] animate-spin">progress_activity</span><span>Sparar...</span>';
				saveBtn.disabled = true;

				var formData = new FormData(kustomForm);

				fetch(kustomForm.action, {
					method: 'POST',
					body: formData,
					headers: {
						'X-Requested-With': 'XMLHttpRequest'
					}
				}).then(function (res) {
					return res.json();
				}).then(function () {
					saveBtn.innerHTML = '<span class="material-symbols-outlined text-[18px]">done</span><span>Sparat!</span>';
					window.triggerQuickDownload('save_ok');
					setTimeout(function () {
						saveBtn.innerHTML = originalContent;
						saveBtn.disabled = false;
					}, 1500);
				}).catch(function () {
					saveBtn.disabled = false;
					saveBtn.innerHTML = originalContent;
					kustomForm.submit();
				});
			});
		}

		// Ladda ner CSV med interaktiv feedback
		if (downloadBtn) {
			downloadBtn.addEventListener('click', function () {
				var url = downloadBtn.getAttribute('data-download-url');
				var countEl = document.getElementById('countTotal') || document.getElementById('kpi-total');
				var count = countEl ? countEl.textContent.trim() : '';
				window.showExportNotification('Kustom POS CSV', count);

				var originalText = downloadBtn.innerHTML;
				downloadBtn.innerHTML = '<span class="material-symbols-outlined text-[20px] animate-spin" style="animation:woo-spin 1.2s linear infinite;">sync</span><span class="font-semibold">Genererar CSV...</span>';
				downloadBtn.disabled = true;

				setTimeout(function () {
					downloadBtn.disabled = false;
					downloadBtn.innerHTML = originalText;
					if (url) {
						window.location.href = url;
					}
				}, 600);
			});
		}

		// Initial synk
		updatePreview();
	}

	/**
	 * Logik för Sello-fliken:
	 * Ladda ner-knappar med toasts och filnamnsfeedback för Tradera-export.
	 */
	function initSelloExportTab() {
		var xlsxBtn = document.getElementById('selloDownloadXlsxBtn') || document.getElementById('downloadXlsxBtn');
		if (xlsxBtn) {
			xlsxBtn.addEventListener('click', function () {
				var count = xlsxBtn.getAttribute('data-count') || '';
				window.showExportNotification('Sello Tradera Excel (.xlsx)', count);
			});
		}

		var csvBtn = document.getElementById('selloDownloadCsvBtn') || document.getElementById('downloadCsvBtn');
		if (csvBtn) {
			csvBtn.addEventListener('click', function () {
				var count = (xlsxBtn && xlsxBtn.getAttribute('data-count')) || '';
				window.showExportNotification('Sello Tradera CSV', count);
			});
		}

		// Snabbval och interaktiv förhandsgranskning för Sello-kolumner
		var selectAllColsBtn = document.getElementById('selloSelectAllBtn');
		var deselectAllColsBtn = document.getElementById('selloDeselectAllBtn');
		var selloColCheckboxes = document.querySelectorAll('.sello-col-checkbox');

		function updateSelloActiveColumnsUI() {
			var activeCount = 0;
			var totalCount = 0;
			var checkboxes = document.querySelectorAll('.sello-col-checkbox');

			checkboxes.forEach(function (cb) {
				totalCount++;
				var colMatch = cb.name.match(/\[sello_columns\]\[(.*?)\]/);
				var colName = colMatch ? colMatch[1] : cb.value;
				var isChecked = cb.checked;

				if (isChecked) {
					activeCount++;
				}

				// 1. Uppdatera raden i Kolumnöversikt: Tradera-kolumner
				var overviewRows = document.querySelectorAll('#selloColumnsTableBody tr');
				overviewRows.forEach(function (row) {
					if (row.getAttribute('data-col') === colName) {
						var badge = row.querySelector('.js-col-status-badge');
						var badgeDot = row.querySelector('.js-col-status-badge span.rounded-full');
						var badgeText = row.querySelector('.js-col-status-text');

						if (isChecked) {
							row.classList.remove('opacity-40', 'bg-slate-50/50');
							if (badge) {
								badge.className = 'js-col-status-badge font-label-sm text-[11px] bg-emerald-100 text-emerald-800 border border-emerald-300 font-semibold px-2 py-0.5 rounded-full inline-flex items-center gap-1';
							}
							if (badgeDot) {
								badgeDot.className = 'w-1.5 h-1.5 rounded-full bg-emerald-600';
							}
							if (badgeText) {
								badgeText.textContent = 'Vald';
							}
						} else {
							row.classList.add('opacity-40', 'bg-slate-50/50');
							if (badge) {
								badge.className = 'js-col-status-badge font-label-sm text-[11px] bg-slate-100 text-slate-500 border border-slate-200 font-medium px-2 py-0.5 rounded-full inline-flex items-center gap-1';
							}
							if (badgeDot) {
								badgeDot.className = 'w-1.5 h-1.5 rounded-full bg-slate-400';
							}
							if (badgeText) {
								badgeText.textContent = 'Ej vald';
							}
						}
					}
				});

				// 2. Uppdatera celler i Förhandsgranskning av exporten
				var previewCells = document.querySelectorAll('#selloPreviewTable [data-col]');
				previewCells.forEach(function (cell) {
					if (cell.getAttribute('data-col') === colName) {
						if (isChecked) {
							cell.classList.remove('hidden');
						} else {
							cell.classList.add('hidden');
						}
					}
				});
			});

			// 3. Uppdatera räknare
			var counterBadge = document.getElementById('selloActiveColsCounter');
			if (counterBadge) {
				counterBadge.textContent = activeCount + ' / ' + totalCount + ' kolumner aktiva';
			}

			var previewBadge = document.getElementById('selloPreviewColCount');
			if (previewBadge) {
				previewBadge.textContent = activeCount + ' kolumner';
			}

			var previewSub = document.getElementById('selloPreviewSubtitle');
			if (previewSub) {
				previewSub.textContent = 'Visar exakt hur de ' + activeCount + ' valda kolumnerna genereras för dina enkla och variabla produkter i den nedladdade filen.';
			}

			var overviewSub = document.getElementById('selloColumnsOverviewSub');
			if (overviewSub) {
				overviewSub.textContent = 'Följande ' + activeCount + ' kolumner genereras i ordning i både .xlsx- och .csv-filen:';
			}

			var overviewSummary = document.getElementById('selloColumnsOverviewSummary');
			if (overviewSummary) {
				overviewSummary.textContent = activeCount + ' av ' + totalCount + ' kolumner är aktiva och inkluderas i Sello-exporten.';
			}
		}

		if (selloColCheckboxes.length) {
			selloColCheckboxes.forEach(function (cb) {
				cb.addEventListener('change', updateSelloActiveColumnsUI);
			});
		}

		if (selectAllColsBtn) {
			selectAllColsBtn.addEventListener('click', function (e) {
				e.preventDefault();
				selloColCheckboxes.forEach(function (cb) {
					cb.checked = true;
				});
				updateSelloActiveColumnsUI();
			});
		}

		if (deselectAllColsBtn) {
			deselectAllColsBtn.addEventListener('click', function (e) {
				e.preventDefault();
				selloColCheckboxes.forEach(function (cb) {
					cb.checked = false;
				});
				updateSelloActiveColumnsUI();
			});
		}

		// Spara Sello-inställningar asynkront via AJAX
		var selloForm = document.getElementById('sello-columns-form');
		var selloSaveBtn = document.getElementById('selloSaveBtn');

		if (selloForm) {
			selloForm.addEventListener('submit', function (e) {
				e.preventDefault();
				if (!selloSaveBtn) {
					selloForm.submit();
					return;
				}

				var originalContent = selloSaveBtn.innerHTML;
				selloSaveBtn.innerHTML = '<span class="material-symbols-outlined text-[15px] animate-spin">progress_activity</span><span>Sparar...</span>';
				selloSaveBtn.disabled = true;

				var formData = new FormData(selloForm);

				fetch(selloForm.action, {
					method: 'POST',
					body: formData,
					headers: {
						'X-Requested-With': 'XMLHttpRequest'
					}
				}).then(function (res) {
					return res.json();
				}).then(function () {
					selloSaveBtn.innerHTML = '<span class="material-symbols-outlined text-[15px]">done</span><span>Sparat!</span>';
					showToast('Inställningarna för Sello & Tradera har sparats! Laddar om sidan...');
					setTimeout(function () {
						window.location.reload();
					}, 700);
				}).catch(function () {
					selloSaveBtn.disabled = false;
					selloSaveBtn.innerHTML = originalContent;
					selloForm.submit();
				});
			});
		}

		// Interaktivitet för Skick- och Vintagefilter
		var condRadios = document.querySelectorAll('.js-sello-cond-radio');
		var customCondWrap = document.getElementById('selloCustomCondWrap');
		if (condRadios.length && customCondWrap) {
			condRadios.forEach(function (radio) {
				radio.addEventListener('change', function () {
					if (radio.checked && radio.value === 'custom') {
						customCondWrap.classList.remove('hidden');
					} else if (radio.checked) {
						customCondWrap.classList.add('hidden');
					}
				});
			});
		}

		// Interaktivitet för Kategori-inställningar
		var catRadios = document.querySelectorAll('.js-sello-cat-radio');
		var customCatWrap = document.getElementById('selloCustomCategoryWrap');
		if (catRadios.length && customCatWrap) {
			catRadios.forEach(function (radio) {
				radio.addEventListener('change', function () {
					if (radio.checked && radio.value === 'fixed') {
						customCatWrap.classList.remove('hidden');
					} else if (radio.checked) {
						customCatWrap.classList.add('hidden');
					}
				});
			});
		}

		// Interaktivitet för Varumärkesfilter
		var brandRadios = document.querySelectorAll('.js-sello-brand-radio');
		var brandListWrap = document.getElementById('selloBrandListWrap');
		if (brandRadios.length && brandListWrap) {
			brandRadios.forEach(function (radio) {
				radio.addEventListener('change', function () {
					if (radio.checked && radio.value === 'include_only') {
						brandListWrap.classList.remove('hidden');
					} else if (radio.checked) {
						brandListWrap.classList.add('hidden');
					}
				});
			});
		}

		// Realtids-sökning bland varumärken
		var brandSearchInput = document.getElementById('selloBrandSearchInput');
		var brandItems = document.querySelectorAll('.js-brand-item');
		if (brandSearchInput && brandItems.length) {
			brandSearchInput.addEventListener('input', function () {
				var q = brandSearchInput.value.toLowerCase().trim();
				brandItems.forEach(function (item) {
					var brand = (item.getAttribute('data-brand') || '').toLowerCase();
					if (!q || brand.indexOf(q) !== -1) {
						item.classList.remove('hidden');
					} else {
						item.classList.add('hidden');
					}
				});
			});
		}

		// Markera / Avmarkera alla varumärken
		var selectAllBrandsBtn = document.getElementById('selloSelectAllBrandsBtn');
		var deselectAllBrandsBtn = document.getElementById('selloDeselectAllBrandsBtn');
		var brandCheckboxes = document.querySelectorAll('.js-sello-brand-cb');

		if (selectAllBrandsBtn) {
			selectAllBrandsBtn.addEventListener('click', function () {
				brandCheckboxes.forEach(function (cb) {
					var parentLabel = cb.closest('.js-brand-item');
					if (!parentLabel || !parentLabel.classList.contains('hidden')) {
						cb.checked = true;
					}
				});
			});
		}

		if (deselectAllBrandsBtn) {
			deselectAllBrandsBtn.addEventListener('click', function () {
				brandCheckboxes.forEach(function (cb) {
					var parentLabel = cb.closest('.js-brand-item');
					if (!parentLabel || !parentLabel.classList.contains('hidden')) {
						cb.checked = false;
					}
				});
			});
		}
	}
})();
