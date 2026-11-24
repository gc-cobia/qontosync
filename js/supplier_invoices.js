/*
 * QontoSync dolibarr module
 * @file       js/supplier_invoices.js
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$(document).ready(function () {
    const container = $('#qontosync_results_container');
    const searchForm = $('#qontosync_search_form');
    const pendingActionBox = $('#qonto-supplier-pending-action');
    let isImporting = false;

    // Fetching translations from HTML
    const langAjaxError = searchForm.data('lang-ajax-error') || 'Error';
    const langProcessBatch = searchForm.data('lang-processing-batch') || 'Processing...';
    const langSearching = searchForm.data('lang-searching') || 'Searching...';

    // ==========================================
    // CONTROLER ENGINE
    // ==========================================

    const routerUrl = 'ajax/router.php';

    async function apiCall(action, data = {}) {
        const tokenVal = searchForm.find('input[name="token"]').val() || $('input[name="token"]').val() || '';

        const payload = {
            token: tokenVal,
            controller: 'supplierinvoices',
            action: action,
            data: data
        };

        const response = await fetch(routerUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        const result = await response.json().catch(() => null);

        if (response.status === 401 || response.status === 403) {
            throw new Error(translateError((result && result.error) ? result.error : 'PERMISSION_DENIED'));
        }

        if (!result) {
            throw new Error('HTTP ' + response.status + ' ' + response.statusText);
        }

        if (!response.ok && !result.error) {
            throw new Error('HTTP ' + response.status + ' ' + response.statusText);
        }

        return result;
    }

    // ==========================================
    // ASYNC FETCH FUNCTIONS
    // ==========================================

    // Load supplier invoices (getSupplierInvoices)
    async function loadSupplierInvoices() {
        displaySpinner(langSearching);
        const refreshBtn = $('#btn_refresh_invoices');
        refreshBtn.prop('disabled', true);

        try {
            const data = await apiCall('getSupplierInvoices', {});

            if (data.error) {
                updateImportAllButton(0);
                let msg = translateError(data.error);
                if (data.error_detail) {
                    msg += ' (' + data.error_detail + ')';
                }
                displayAlert('error', msg);
            } else {
                renderSupplierInvoices(data);
            }
        } catch (error) {
            updateImportAllButton(0);
            displayAlert('error', langAjaxError + ' : ' + error.message);
        } finally {
            if (!isImporting) {
                refreshBtn.prop('disabled', false);
            }
        }
    }

    // Import or link a single supplier invoice (importInvoice)
    async function importInvoice(buttonEl) {
        if (isImporting) return;

        const $btn = $(buttonEl);
        const qontoId = $btn.attr('data-qonto-id') || '';
        const invoiceSig = $btn.attr('data-invoice-sig') || '';
        if (!qontoId || !invoiceSig) return;

        isImporting = true;
        clearPendingAction();

        const originalHtml = $btn.html();
        const refreshBtn = $('#btn_refresh_invoices');
        const importAllBtn = $('#btn_import_all_invoices');

        $btn.html('<i class="fas fa-spinner fa-pulse"></i>');
        container.find('.btn-import-invoice').prop('disabled', true);
        importAllBtn.prop('disabled', true);
        refreshBtn.prop('disabled', true);

        try {
            const data = await apiCall('importInvoice', {
                qonto_id: qontoId,
                sig: invoiceSig
            });

            if (data.error) {
                showNotification(data.error_message || data.error, 'error');
                if (data.pending_action_html) {
                    showPendingAction(data.pending_action_html);
                }
                $btn.html(originalHtml);
                container.find('.btn-import-invoice').prop('disabled', false);
                importAllBtn.prop('disabled', false);
                refreshBtn.prop('disabled', false);
                isImporting = false;
            } else {
                showNotification(data.message, 'success');
                if (Array.isArray(data.warnings)) {
                    data.warnings.forEach(function (w) {
                        showNotification(w, 'warning');
                    });
                }
                isImporting = false;
                await loadSupplierInvoices();
                importAllBtn.prop('disabled', false);
            }
        } catch (error) {
            $btn.html(originalHtml);
            container.find('.btn-import-invoice').prop('disabled', false);
            importAllBtn.prop('disabled', false);
            refreshBtn.prop('disabled', false);
            isImporting = false;
            showNotification(langAjaxError + ' : ' + error.message, 'error');
        }
    }

    // Batch synchronize all unlinked supplier invoices (importAll)
    async function importAll() {
        if (isImporting) return;

        isImporting = true;
        clearPendingAction();

        const btn = $('#btn_import_all_invoices');
        const refreshBtn = $('#btn_refresh_invoices');
        const originalHtml = btn.html();

        const spinnerIcon = document.createElement('i');
        spinnerIcon.className = 'fas fa-spinner fa-pulse marginrightonly';
        btn.empty().append(spinnerIcon).append(document.createTextNode(langProcessBatch));
        btn.prop('disabled', true);
        refreshBtn.prop('disabled', true);
        container.find('.btn-import-invoice').prop('disabled', true);

        try {
            const data = await apiCall('importAll', {});

            btn.html(originalHtml);
            btn.prop('disabled', false);
            isImporting = false;

            if (data.error) {
                container.find('.btn-import-invoice').prop('disabled', false);
                refreshBtn.prop('disabled', false);
                showNotification(data.error_message || data.error, 'error');
            } else {
                if (Array.isArray(data.warnings)) {
                    data.warnings.forEach(function (w) {
                        showNotification(w, 'warning');
                    });
                }
                if (Array.isArray(data.errors)) {
                    data.errors.forEach(function (err) {
                        showNotification(err, 'error');
                    });
                }
                showNotification(data.message, (data.error_count > 0 ? 'warning' : 'success'));
                await loadSupplierInvoices();
            }
        } catch (error) {
            btn.html(originalHtml);
            btn.prop('disabled', false);
            container.find('.btn-import-invoice').prop('disabled', false);
            refreshBtn.prop('disabled', false);
            isImporting = false;
            showNotification(langAjaxError + ' : ' + error.message, 'error');
        }
    }

    // Open temporary attachment URL in a new tab (getAttachmentUrl)
    async function openAttachment(buttonEl) {
        const $btn = $(buttonEl);
        const attachmentId = $btn.attr('data-attachment-id') || '';
        const attachmentSig = $btn.attr('data-attachment-sig') || '';
        if (!attachmentId || !attachmentSig) return;

        const previewWin = window.open('about:blank', '_blank');
        if (previewWin) {
            previewWin.opener = null;
        }

        const originalHtml = $btn.html();
        $btn.html('<i class="fas fa-spinner fa-pulse"></i>');
        $btn.prop('disabled', true);

        try {
            const data = await apiCall('getAttachmentUrl', {
                attachment_id: attachmentId,
                sig: attachmentSig
            });

            if (data.url) {
                let parsedUrl = null;
                try {
                    parsedUrl = new URL(data.url);
                } catch (e) {
                    parsedUrl = null;
                }

                if (parsedUrl && parsedUrl.protocol === 'https:') {
                    if (previewWin && !previewWin.closed) {
                        previewWin.location.href = parsedUrl.href;
                    } else {
                        window.open(parsedUrl.href, '_blank', 'noopener,noreferrer');
                    }
                } else {
                    if (previewWin && !previewWin.closed) {
                        previewWin.close();
                    }
                    showNotification('ERROR_FILE_NOT_FOUND', 'error');
                }
            } else {
                if (previewWin && !previewWin.closed) {
                    previewWin.close();
                }
                showNotification(data.error || 'ERROR_FILE_NOT_FOUND', 'error');
            }
        } catch (error) {
            if (previewWin && !previewWin.closed) {
                previewWin.close();
            }
            showNotification(langAjaxError + ' : ' + error.message, 'error');
        } finally {
            $btn.html(originalHtml);
            $btn.prop('disabled', false);
        }
    }

    // ==========================================
    // HELPER FUNCTIONS FOR DOM RENDERING
    // ==========================================

    function displaySpinner(message) {
        const tpl = document.getElementById('tpl-spinner');
        if (!tpl) return;
        const clone = tpl.content.cloneNode(true);
        const textSpan = clone.querySelector('.qonto-spinner-text');
        if (textSpan) {
            textSpan.textContent = message;
        }
        container.empty().append(clone);
    }

    function displayAlert(type, message) {
        const tpl = document.getElementById('tpl-alert-box');
        if (!tpl) return;
        const clone = tpl.content.cloneNode(true);
        const box = clone.querySelector('.qonto-alert-box');
        if (box) {
            box.classList.add(type);
        }
        const textSpan = clone.querySelector('.qonto-alert-text');
        if (textSpan) {
            textSpan.textContent = translateError(message);
        }
        container.empty().append(clone);
    }

    function translateError(errorCode) {
        if (!errorCode || typeof errorCode !== 'string') return errorCode || '';
        const translationsElem = document.getElementById('qonto-supplier-invoices-translations');
        if (translationsElem) {
            // e.g. ERROR_FETCHING_SUPPLIER_INVOICES -> errorErrorFetchingSupplierInvoices
            const parts = errorCode.toLowerCase().split('_');
            const camelKey = 'error' + parts.map(function (w) {
                return w.charAt(0).toUpperCase() + w.slice(1);
            }).join('');
            if (translationsElem.dataset && translationsElem.dataset[camelKey]) {
                return translationsElem.dataset[camelKey];
            }
        }
        return errorCode;
    }

    function showNotification(message, type) {
        if (!message) return;

        const displayMsg = (type === 'errors' || type === 'error' || type === 'warnings' || type === 'warning') ? translateError(message) : message;

        // Mapping PHP (Dolibarr) types to JS (jnotify)
        let jnType = 'success';
        if (type === 'warnings' || type === 'warning') jnType = 'warning';
        if (type === 'errors' || type === 'error') jnType = 'error';

        if (typeof $.jnotify === 'function') {
            $.jnotify(displayMsg, jnType);
        } else {
            alert(displayMsg);
        }
    }

    function updateImportAllButton(nbToProcess) {
        const btn = $('#btn_import_all_invoices');
        if (btn.length === 0) return;

        const count = parseInt(nbToProcess, 10) || 0;
        if (count > 0) {
            btn.find('.btn-import-all-count').text(' (' + count + ')');
            btn.css('display', 'inline-flex');
        } else {
            btn.find('.btn-import-all-count').text('');
            btn.hide();
        }
    }

    function clearPendingAction() {
        pendingActionBox.hide().empty();
    }

    function showPendingAction(html) {
        if (!html) return;
        pendingActionBox.html(html).show();
    }

    // ==========================================
    // CLIENT-SIDE RENDERING ENGINE
    // ==========================================

    function renderSupplierInvoices(data) {
        const tplTable = document.getElementById('tpl-supplier-invoices-table');
        const tplRow = document.getElementById('tpl-supplier-invoice-row');
        const tplEmpty = document.getElementById('tpl-supplier-invoices-empty');
        const translationsElem = document.getElementById('qonto-supplier-invoices-translations');

        if (!tplTable || !tplRow) return;

        updateImportAllButton(data.nb_to_process || 0);

        const transOpen = (translationsElem && translationsElem.dataset.open) ? translationsElem.dataset.open : 'Open';
        const transImport = (translationsElem && translationsElem.dataset.import) ? translationsElem.dataset.import : 'Import';
        const transStatusImported = (translationsElem && translationsElem.dataset.statusImported) ? translationsElem.dataset.statusImported : 'Imported';
        const transStatusMatched = (translationsElem && translationsElem.dataset.statusMatched) ? translationsElem.dataset.statusMatched : 'Exists in DB';
        const transStatusMatchedTooltip = (translationsElem && translationsElem.dataset.statusMatchedTooltip) ? translationsElem.dataset.statusMatchedTooltip : '';
        const transStatusNotImported = (translationsElem && translationsElem.dataset.statusNotImported) ? translationsElem.dataset.statusNotImported : 'Not imported';
        const transNoInvoices = (translationsElem && translationsElem.dataset.noInvoices) ? translationsElem.dataset.noInvoices : 'No supplier invoices found';

        const tableNode = tplTable.content.cloneNode(true);
        const tbody = tableNode.querySelector('.qonto-supplier-invoices-tbody');
        const invoices = Array.isArray(data.invoices) ? data.invoices : [];

        if (invoices.length === 0) {
            if (tplEmpty) {
                const emptyNode = tplEmpty.content.cloneNode(true);
                const emptyCell = emptyNode.querySelector('.col-empty-text');
                if (emptyCell) emptyCell.textContent = transNoInvoices;
                tbody.appendChild(emptyNode);
            }
            container.empty().append(tableNode);
            return;
        }

        invoices.forEach(function (item) {
            const row = tplRow.content.cloneNode(true);
            const tr = row.querySelector('tr');

            // 1. Date
            const colDate = tr.querySelector('.col-date');
            if (colDate) colDate.textContent = item.date_formatted || '-';

            // 2. Reference
            const colRefText = tr.querySelector('.col-ref-text');
            if (colRefText) colRefText.textContent = item.ref || item.id || '-';

            // 3. Supplier
            const colSupplier = tr.querySelector('.col-supplier');
            if (colSupplier) colSupplier.textContent = item.supplier || '-';

            // 4. Amount TTC
            const colAmount = tr.querySelector('.col-amount');
            if (colAmount) colAmount.textContent = (item.amount_formatted || '0') + ' ' + (item.currency || 'EUR');

            // 5. Qonto Status
            const statusBadge = tr.querySelector('.col-qonto-status-badge');
            if (statusBadge) {
                const status = item.status || '';
                let badgeClass = 'badge-status0';
                if (status === 'paid') badgeClass = 'badge-status4';
                else if (status === 'to_pay') badgeClass = 'badge-status1';
                else if (status === 'to_review') badgeClass = 'badge-status3';

                statusBadge.className = 'badge col-qonto-status-badge ' + badgeClass;
                statusBadge.textContent = status;
            }

            // 6. Dolibarr Status
            const colDolibarr = tr.querySelector('.col-dolibarr-status');
            if (colDolibarr) {
                colDolibarr.textContent = '';
                if (item.match_status === 'linked' && item.dolibarr_rowid > 0) {
                    const linkA = document.createElement('a');
                    linkA.href = item.dolibarr_url || '#';
                    linkA.target = '_blank';
                    linkA.rel = 'noopener noreferrer';
                    linkA.className = 'badge badge-status4';

                    const icon = document.createElement('i');
                    icon.className = 'fas fa-check-circle marginrightonly';
                    linkA.appendChild(icon);
                    linkA.appendChild(document.createTextNode(transStatusImported + ' (#' + item.dolibarr_rowid + ')'));
                    colDolibarr.appendChild(linkA);
                } else if (item.match_status === 'matched' && item.dolibarr_rowid > 0) {
                    const linkA = document.createElement('a');
                    linkA.href = item.dolibarr_url || '#';
                    linkA.target = '_blank';
                    linkA.rel = 'noopener noreferrer';
                    linkA.className = 'badge badge-status3';
                    if (transStatusMatchedTooltip) {
                        linkA.title = transStatusMatchedTooltip;
                    }

                    const icon = document.createElement('i');
                    icon.className = 'fas fa-link marginrightonly';
                    linkA.appendChild(icon);
                    linkA.appendChild(document.createTextNode(transStatusMatched + ' (#' + item.dolibarr_rowid + ')'));
                    colDolibarr.appendChild(linkA);
                } else {
                    const spanBadge = document.createElement('span');
                    spanBadge.className = 'badge badge-status0';
                    spanBadge.textContent = transStatusNotImported;
                    colDolibarr.appendChild(spanBadge);
                }
            }

            // 7. Actions
            const colAction = tr.querySelector('.col-action');
            if (colAction) {
                colAction.textContent = '';
                let hasAction = false;

                if (item.can_import) {
                    const importBtn = document.createElement('button');
                    importBtn.type = 'button';
                    importBtn.className = 'button smallpaddingimp marginrightonly btn-import-invoice';
                    importBtn.setAttribute('data-qonto-id', item.id || '');
                    importBtn.setAttribute('data-invoice-sig', item.sig || '');

                    const icon = document.createElement('i');
                    icon.className = 'fas fa-file-import marginrightonly';
                    importBtn.appendChild(icon);
                    importBtn.appendChild(document.createTextNode(transImport));
                    colAction.appendChild(importBtn);
                    hasAction = true;
                }

                if (item.can_read_pdf && item.attachment_id && item.attachment_sig) {
                    const openBtn = document.createElement('button');
                    openBtn.type = 'button';
                    openBtn.className = 'button smallpaddingimp btn-open-attachment';
                    openBtn.setAttribute('data-attachment-id', item.attachment_id);
                    openBtn.setAttribute('data-attachment-sig', item.attachment_sig);

                    const icon = document.createElement('i');
                    icon.className = 'fas fa-file-pdf marginrightonly';
                    openBtn.appendChild(icon);
                    openBtn.appendChild(document.createTextNode(transOpen));
                    colAction.appendChild(openBtn);
                    hasAction = true;
                }

                if (!hasAction) {
                    const emptySpan = document.createElement('span');
                    emptySpan.className = 'opacitymedium';
                    emptySpan.textContent = '-';
                    colAction.appendChild(emptySpan);
                }
            }

            tbody.appendChild(tr);
        });

        container.empty().append(tableNode);
    }

    // ==========================================
    // EVENT HANDLERS
    // ==========================================

    // Refresh supplier invoices list
    searchForm.on('submit', function (e) {
        e.preventDefault();
        if (!isImporting) {
            clearPendingAction();
            loadSupplierInvoices();
        }
    });

    // Batch import all pending invoices
    $(document).on('click', '#btn_import_all_invoices', function (e) {
        e.preventDefault();
        importAll();
    });

    // Single invoice import
    $(document).on('click', '.btn-import-invoice', function (e) {
        e.preventDefault();
        importInvoice(this);
    });

    // Open PDF attachment in a new tab
    $(document).on('click', '.btn-open-attachment', function (e) {
        e.preventDefault();
        openAttachment(this);
    });

    // Auto-load supplier invoices on page load
    loadSupplierInvoices();
});
