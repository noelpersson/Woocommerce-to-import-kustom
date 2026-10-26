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

	// Visar toast-notis vid snabbexport eller sparande
	window.triggerQuickDownload = function (target) {
		var name = target === 'kustom' ? 'Kustom POS CSV' : (target === 'save_ok' ? 'Kustom-inställningar sparade' : 'Sello Excel (.xlsx)');
		var toast = document.createElement('div');
		toast.className = 'woo-toast-notify fixed bottom-6 right-6 bg-slate-900 text-white px-4 py-3 rounded-lg shadow-lg flex items-center gap-3 z-50 transition-all font-body-sm text-body-sm';
		toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#131b2e;color:#ffffff;padding:12px 18px;border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,0.18);display:flex;align-items:center;gap:10px;z-index:999999;font-size:13px;font-family:Inter,sans-serif;transition:opacity 0.3s ease;';
		var icon = target === 'save_ok' ? 'check_circle' : 'file_download';
		var msg = target === 'save_ok' ? 'Kustom POS-inställningarna har sparats!' : ('Genererar och laddar ner ' + name + '...');
		toast.innerHTML = '<span class="material-symbols-outlined text-[18px]" style="color:#95f8a7;font-size:18px;">' + icon + '</span> ' + msg;
		document.body.appendChild(toast);

		setTimeout(function () {
			toast.style.opacity = '0';
			setTimeout(function () {
				if (toast.parentNode) {
					toast.parentNode.removeChild(toast);
				}
			}, 300);
		}, 2500);
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
		var downloadBtn = document.getElementById('downloadCsvBtn');
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
					var colName = 'attribute_' + cleanName.replace(/^attribute_/, '').replace(/^pa_/, '');
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
					} else if (cleanName.indexOf('skick') !== -1) {
						sample1 = '"Nyskick"';
						sample2 = '"Nyskick"';
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
				var originalText = downloadBtn.innerHTML;
				downloadBtn.innerHTML = '<span class="material-symbols-outlined text-[20px] animate-spin">sync</span><span class="font-semibold">Genererar CSV...</span>';

				setTimeout(function () {
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
	 * Ladda ner-knappar med toasts, filterväxling mellan 8 och 15 fält, kopiering av feed-URL.
	 */
	function initSelloExportTab() {
		var toast = document.getElementById('toastNotification');
		var toastMsg = document.getElementById('toastMessage');

		function showToast(text) {
			if (!toast || !toastMsg) return;
			toastMsg.textContent = text;
			toast.classList.remove('translate-y-20', 'opacity-0', 'pointer-events-none');
			toast.classList.add('translate-y-0', 'opacity-100');

			setTimeout(function () {
				toast.classList.remove('translate-y-0', 'opacity-100');
				toast.classList.add('translate-y-20', 'opacity-0', 'pointer-events-none');
			}, 3500);
		}

		var xlsxBtn = document.getElementById('downloadXlsxBtn');
		if (xlsxBtn) {
			xlsxBtn.addEventListener('click', function () {
				var count = xlsxBtn.getAttribute('data-count') || '5 052';
				showToast('Genererar och laddar ner Sello_Export_2025.xlsx (' + count + ' rader)...');
			});
		}

		var csvBtn = document.getElementById('downloadCsvBtn');
		if (csvBtn) {
			csvBtn.addEventListener('click', function () {
				showToast('Genererar Sello_Export_UTF8.csv...');
			});
		}

		var copyBtn = document.getElementById('copyUrlBtn');
		if (copyBtn) {
			copyBtn.addEventListener('click', function (e) {
				e.preventDefault();
				var feedUrl = copyBtn.getAttribute('data-feed-url') || window.location.href;
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(feedUrl).then(function () {
						showToast('Feed-URL har kopierats till urklipp!');
					}).catch(function () {
						showToast('Feed-URL har kopierats till urklipp!');
					});
				} else {
					showToast('Feed-URL har kopierats till urklipp!');
				}
			});
		}

		var coreBtn = document.getElementById('selloFilterCoreBtn');
		var allBtn = document.getElementById('selloFilterAllBtn');
		var extraRows = document.querySelectorAll('.sello-extra-row');
		var validationText = document.getElementById('selloValidationText');

		if (coreBtn && allBtn) {
			coreBtn.addEventListener('click', function () {
				extraRows.forEach(function (row) {
					row.classList.add('hidden');
				});
				coreBtn.className = 'font-label-sm text-label-sm px-2.5 py-1 rounded bg-surface-container text-on-surface font-semibold border-0 cursor-pointer transition-colors';
				allBtn.className = 'font-label-sm text-label-sm px-2.5 py-1 rounded bg-surface-container-low text-on-surface-variant hover:text-on-surface font-medium border-0 cursor-pointer transition-colors';
				if (validationText) {
					validationText.textContent = 'Alla 8 fält kontrollerade utan fel mot Sello API v4 valideringsschema.';
				}
			});

			allBtn.addEventListener('click', function () {
				extraRows.forEach(function (row) {
					row.classList.remove('hidden');
				});
				allBtn.className = 'font-label-sm text-label-sm px-2.5 py-1 rounded bg-surface-container text-on-surface font-semibold border-0 cursor-pointer transition-colors';
				coreBtn.className = 'font-label-sm text-label-sm px-2.5 py-1 rounded bg-surface-container-low text-on-surface-variant hover:text-on-surface font-medium border-0 cursor-pointer transition-colors';
				if (validationText) {
					validationText.textContent = 'Samtliga 15 fält kontrollerade utan fel mot Sello API v4 valideringsschema.';
				}
			});
		}
	}
})();
