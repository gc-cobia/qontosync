/*
 * QontoSync dolibarr module 
 * @file       js/statements.js
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$(document).ready(function () {
    const container = $('#qontosync_results_container');
    const searchForm = $('#qontosync_search_form');

    const fetchUrl = 'ajax/get_statements.php';
    const updateUrl = 'ajax/update_statements.php';

    // Fetching translations from HTML
    const langAjaxError = searchForm.data('lang-ajax-error') || 'Error';
    const langProcessBatch = searchForm.data('lang-processing-batch') || 'Processing...';
    const langSearching = searchForm.data('lang-searching') || 'Searching...';

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
            textSpan.textContent = message;
        }
        container.empty().append(clone);
    }

    function showNotification(message, type) {
        if (!message) return;

        // Mapping PHP (Dolibarr) types to JS (jnotify)
        let jnType = 'success'; // Default
        if (type === 'warnings' || type === 'warning') jnType = 'warning';
        if (type === 'errors' || type === 'error') jnType = 'error';

        if (typeof $.jnotify === 'function') {
            $.jnotify(message, jnType);
        } else {
            // Native Dolibarr notifications fallback 
            alert(message);
        }
    }

    // ==========================================
    // CLIENT-SIDE RENDERING ENGINE
    // ==========================================

    function renderStatements(data) {
        const tplTable = document.getElementById('tpl-statements-table');
        const tplRow = document.getElementById('tpl-statement-row');
        const translationsElem = document.getElementById('qonto-statements-translations');

        if (!tplTable || !tplRow) return;

        const tableNode = tplTable.content.cloneNode(true);
        const formBatch = tableNode.querySelector('#form_batch_link');
        const tbody = tableNode.querySelector('.qonto-statements-tbody');

        if (formBatch) {
            const tokenInput = formBatch.querySelector('input[name="token"]');
            if (tokenInput) tokenInput.value = data.token || $('input[name="token"]').val() || '';

            const bankRowidInput = formBatch.querySelector('input[name="bank_rowid"]');
            if (bankRowidInput) bankRowidInput.value = data.bank_rowid || $('select[name="bank_rowid"]').val() || '';

            const yearInput = formBatch.querySelector('input[name="year"]');
            if (yearInput) yearInput.value = data.year || $('select[name="year"]').val() || '';
        }

        const transOpen = (translationsElem && translationsElem.dataset.open) ? translationsElem.dataset.open : 'Open';
        const transAvailable = (translationsElem && translationsElem.dataset.available) ? translationsElem.dataset.available : 'Available';
        const transNotAvailable = (translationsElem && translationsElem.dataset.notAvailable) ? translationsElem.dataset.notAvailable : 'Not available';

        const months = data.data || [];

        months.forEach(function (item) {
            const row = tplRow.content.cloneNode(true);
            const tr = row.querySelector('tr');

            // Month label
            const monthText = tr.querySelector('.col-month-text');
            if (monthText) {
                monthText.textContent = item.label || '';
            } else {
                const monthCol = tr.querySelector('.col-month');
                if (monthCol) monthCol.textContent = item.label || '';
            }

            const colDoliOpen = tr.querySelector('.col-doli-open');
            const colQontoOpen = tr.querySelector('.col-qonto-open');
            const colDiffOpen = tr.querySelector('.col-diff-open');
            const colDoliClose = tr.querySelector('.col-doli-close');
            const colQontoClose = tr.querySelector('.col-qonto-close');
            const colDiffClose = tr.querySelector('.col-diff-close');
            const colAction = tr.querySelector('.col-action');

            if (item.is_future) {
                if (colDoliOpen) colDoliOpen.textContent = '-';
                if (colQontoOpen) colQontoOpen.textContent = '-';
                if (colDiffOpen) colDiffOpen.textContent = '-';
                if (colDoliClose) colDoliClose.textContent = '-';
                if (colQontoClose) colQontoClose.textContent = '-';
                if (colDiffClose) colDiffClose.textContent = '-';
                if (colAction) colAction.textContent = '-';
            } else {
                if (colDoliOpen) colDoliOpen.textContent = item.dolibarr_opening || '0';
                if (colQontoOpen) colQontoOpen.textContent = item.qonto_opening || '0';
                if (colDiffOpen) {
                    colDiffOpen.textContent = item.diff_open_formatted || '0';
                    colDiffOpen.style.color = (item.diff_open === 0) ? 'var(--amountpaymentcomplete)' : 'var(--amountremaintopaycolor)';
                }

                if (colDoliClose) colDoliClose.textContent = item.dolibarr_closing || '0';
                if (colQontoClose) colQontoClose.textContent = item.qonto_closing || '0';
                if (colDiffClose) {
                    colDiffClose.textContent = item.diff_close_formatted || '0';
                    colDiffClose.style.color = (item.diff_close === 0) ? 'var(--amountpaymentcomplete)' : 'var(--amountremaintopaycolor)';
                }

                if (colAction) {
                    colAction.textContent = '';
                    if (item.in_dolibarr) {
                        const linkA = document.createElement('a');
                        linkA.href = item.dolibarr_url;
                        linkA.target = '_blank';
                        linkA.className = 'badge badge-success';
                        linkA.style.color = 'white';
                        linkA.style.textDecoration = 'none';
                        linkA.style.padding = '6px';
                        
                        const icon = document.createElement('i');
                        icon.className = 'fas fa-file-pdf marginrightonly';
                        linkA.appendChild(icon);
                        linkA.appendChild(document.createTextNode(transOpen));

                        colAction.appendChild(linkA);
                    } else if (item.in_qonto) {
                        const spanAvail = document.createElement('span');
                        spanAvail.className = 'badge badge-warning qonto-sync-available';
                        spanAvail.setAttribute('data-url', item.dolibarr_url || '');
                        spanAvail.setAttribute('data-open-text', transOpen);

                        const icon = document.createElement('i');
                        icon.className = 'fas fa-sync marginrightonly';
                        spanAvail.appendChild(icon);
                        spanAvail.appendChild(document.createTextNode(transAvailable));

                        colAction.appendChild(spanAvail);
                    } else {
                        const spanNotAvail = document.createElement('span');
                        spanNotAvail.className = 'opacitymedium small';
                        spanNotAvail.textContent = transNotAvailable;
                        colAction.appendChild(spanNotAvail);
                    }
                }
            }

            tbody.appendChild(tr);
        });

        container.empty().append(tableNode);
    }

    // ==========================================
    // ASYNC FETCH FUNCTIONS
    // ==========================================

    async function loadTransactions() {
        displaySpinner(langSearching);

        const formData = new URLSearchParams(new FormData(searchForm[0]));

        try {
            const response = await fetch(fetchUrl, {
                method: 'POST',
                body: formData,
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                }
            });

            if (response.status === 401 || response.status === 403) {
                window.location.reload();
                return;
            }

            if (!response.ok) {
                throw new Error('HTTP ' + response.status + ' ' + response.statusText);
            }

            const data = await response.json();

            if (data.status === 'error') {
                displayAlert('error', data.message || langAjaxError);
            } else if (data.status === 'warning') {
                displayAlert('warning', data.message || '');
            } else if (data.status === 'info') {
                displayAlert('info', data.message || '');
            } else if (data.status === 'success') {
                renderStatements(data);
            } else {
                displayAlert('error', langAjaxError);
            }
        } catch (error) {
            displayAlert('error', langAjaxError + ' : ' + error.message);
        }
    }

    // ==========================================
    // EVENT HANDLERS
    // ==========================================

    // Form search submit
    searchForm.on('submit', function (e) {
        e.preventDefault();
        loadTransactions();
    });

    // Process import statements
    $(document).on('click', '#btn_import_statements', function (e) {
        e.preventDefault();
        $('#form_batch_link').trigger('submit');
    });

    $(document).on('submit', '#form_batch_link', async function (e) {
        e.preventDefault();

        const btn = $('#btn_import_statements');
        const originalHtml = btn.html();

        // Change import button to loader
        btn.html('<i class="fas fa-spinner fa-pulse marginrightonly"></i>' + langProcessBatch);
        btn.prop('disabled', true);

        const formData = new URLSearchParams(new FormData(this));

        try {
            const response = await fetch(updateUrl, {
                method: 'POST',
                body: formData,
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                }
            });

            if (response.status === 401 || response.status === 403) {
                window.location.reload();
                return;
            }

            const data = await response.json();
            btn.html(originalHtml);
            btn.prop('disabled', false);

            showNotification(data.message, data.type);

            // Turn available badges into open buttons
            $('.qonto-sync-available').each(function () {
                const url = $(this).attr('data-url') || $(this).data('url');
                const text = $(this).attr('data-open-text') || $(this).data('open-text');
                const linkA = document.createElement('a');
                linkA.href = url;
                linkA.target = '_blank';
                linkA.className = 'badge badge-success';
                linkA.style.color = 'white';
                linkA.style.textDecoration = 'none';
                linkA.style.padding = '6px';

                const icon = document.createElement('i');
                icon.className = 'fas fa-file-pdf marginrightonly';
                linkA.appendChild(icon);
                linkA.appendChild(document.createTextNode(text));

                $(this).replaceWith(linkA);
            });
        } catch (error) {
            btn.html(originalHtml);
            btn.prop('disabled', false);
            displayAlert('error', langAjaxError + ' : ' + error.message);
        }
    });

    // Auto-loader if bank account is already selected
    const bankSelect = searchForm.find('select[name="bank_rowid"]');
    if (bankSelect.length > 0 && bankSelect.val() > 0) {
        setTimeout(function () {
            loadTransactions();
        }, 150);
    }
});
