/*
 * QontoSync dolibarr module 
 * @file       js/reconciliation.js
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$(document).ready(function () {
    const container = $('#qontosync_results_container');
    const searchForm = $('#qontosync_search_form');

    const fetchUrl = 'ajax/get_transactions.php';
    const updateUrl = 'ajax/update_batch.php';
    const updateAdvancedUrl = 'ajax/update_advanced.php';
    const unlinkUrl = 'ajax/unlink_transaction.php';
    const createVariousUrl = 'ajax/create_various.php';

    // Fetching translations from HTML
    const langAjaxError = searchForm.data('lang-ajax-error') || 'Error';
    const langProcessBatch = searchForm.data('lang-processing-batch') || 'Processing...';
    const langProcessAdvanced = searchForm.data('lang-processing-advanced') || 'Processing...';
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

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showError(message) {
        $('#advanced_modal_loader').hide();
        $('#advanced_error_container').html('<div class="error" style="margin-top: 15px;">' + escapeHtml(message) + '</div>').show();
    }

    function renderAdvancedModal(data, bankRowid) {
        $('#advanced_modal_loader').hide();
        $('#advanced_error_container').hide();
        $('#advanced_content_container').show();

        const trx = data.transaction;

        // Render Transaction Details Header
        const tplInfo = document.getElementById('tpl-advanced-info');
        if (tplInfo) {
            const cloneInfo = tplInfo.content.cloneNode(true);
            $(cloneInfo).find('.info-label').text(trx.label || '');
            $(cloneInfo).find('.info-date').text(trx.date_formatted || '');
            
            const amountEl = $(cloneInfo).find('.info-amount');
            amountEl.text((trx.amount_formatted || '0.00') + ' ' + (trx.currency || ''));
            if (trx.amount < 0) {
                amountEl.css('color', 'var(--amountremaintopaycolor, #d9534f)');
            } else {
                amountEl.css('color', 'var(--amountpaymentcomplete, #5cb85c)');
            }

            $(cloneInfo).find('.info-type').text(trx.type_label || '');
            $(cloneInfo).find('.info-id').text(trx.id || '');

            if (trx.reference) {
                $(cloneInfo).find('.info-ref-title').show();
                $(cloneInfo).find('.info-ref-val').text(trx.reference).show();
            }

            $('#advanced_transaction_info').empty().append(cloneInfo);
        }

        // Render Dynamic Section
        const dynamicSection = $('#advanced_dynamic_section');
        dynamicSection.empty();

        if (data.linked > 0) {
            // Transaction already linked
            const tplLinked = document.getElementById('tpl-advanced-linked');
            if (tplLinked) {
                const cloneLinked = tplLinked.content.cloneNode(true);
                $(cloneLinked).find('.linked-message-text').html(data.linked_message || '');

                if (data.linked_details && data.linked_details.length > 0) {
                    $(cloneLinked).find('.linked-details-container').show();
                    const tbody = $(cloneLinked).find('.linked-details-tbody');
                    const tplLinkedRow = document.getElementById('tpl-advanced-linked-row');

                    data.linked_details.forEach(function (detail) {
                        if (tplLinkedRow) {
                            const cloneRow = tplLinkedRow.content.cloneNode(true);
                            $(cloneRow).find('.col-type').text(detail.doc_type_label || '');
                            $(cloneRow).find('.col-payment span').text('ID: ' + detail.payment_id);
                            $(cloneRow).find('.col-ref').text(detail.ref || '');
                            if (detail.ref_supplier) {
                                $(cloneRow).find('.col-ref-supplier').text(detail.ref_supplier).show();
                            }
                            $(cloneRow).find('.col-amount').text(detail.amount_formatted || '');
                            tbody.append(cloneRow);
                        }
                    });
                }

                dynamicSection.append(cloneLinked);
            }
        } else if (data.linked < 0) {
            // Multiple links error
            const tplMultiple = document.getElementById('tpl-advanced-multiple-links');
            if (tplMultiple) {
                const cloneMultiple = tplMultiple.content.cloneNode(true);
                dynamicSection.append(cloneMultiple);
            }
        } else {
            // Pending documents to match
            const tplPending = document.getElementById('tpl-advanced-pending');
            if (tplPending) {
                const clonePending = tplPending.content.cloneNode(true);

                // Populate hidden inputs
                $(clonePending).find('input[name="token"]').val(data.token || $('input[name="token"]').val() || '');
                $(clonePending).find('input[name="qonto_id"]').val(trx.id || '');
                $(clonePending).find('input[name="bank_rowid"]').val(bankRowid || $('input[name="bank_rowid"]').val() || searchForm.find('select[name="bank_rowid"]').val() || '');
                $(clonePending).find('#qonto_trx_amount').val(trx.amount_abs || 0);

                const tbody = $(clonePending).find('.pending-documents-tbody');
                const tplPendingRow = document.getElementById('tpl-advanced-pending-row');

                if (data.pending_documents && data.pending_documents.length > 0) {
                    data.pending_documents.forEach(function (doc) {
                        if (tplPendingRow) {
                            const cloneRow = tplPendingRow.content.cloneNode(true);
                            const chk = $(cloneRow).find('.check_link_doc');
                            chk.val(doc.key)
                               .attr('data-doctype', doc.doctype)
                               .attr('data-thirdparty', doc.thirdparty)
                               .attr('data-remain', doc.remain_for_js);

                            $(cloneRow).find('.doctype-text').text(doc.doctype_label || '');
                            if (doc.is_credit_note) {
                                $(cloneRow).find('.badge-credit-note').text(doc.credit_note_label || 'Avoir').show();
                            }

                            $(cloneRow).find('.col-date').text(doc.date_formatted || '');
                            $(cloneRow).find('.ref-label').text(doc.label || '');
                            if (doc.ref_supplier) {
                                $(cloneRow).find('.ref-supplier').text('(' + doc.ref_supplier + ')').show();
                            }

                            $(cloneRow).find('.col-thirdparty').text(doc.thirdparty || '');
                            $(cloneRow).find('.col-remain').html(doc.amount_formatted || '');

                            const amtInput = $(cloneRow).find('.amount_input');
                            amtInput.attr('name', 'amount_' + doc.key);

                            tbody.append(cloneRow);
                        }
                    });
                } else {
                    const tplNoDocs = document.getElementById('tpl-advanced-no-docs');
                    if (tplNoDocs) {
                        tbody.append(tplNoDocs.content.cloneNode(true));
                    }
                }

                dynamicSection.append(clonePending);
            }
        }
    }

    // ==========================================
    // CLIENT-SIDE RENDERING ENGINE
    // ==========================================

    function renderTransactions(data) {
        const tplTable = document.getElementById('tpl-results-table');
        const tplRow = document.getElementById('tpl-transaction-row');
        const tplErrTooMany = document.getElementById('tpl-status-error-too-many');
        const tplLinked = document.getElementById('tpl-status-linked');
        const tplNoMatch = document.getElementById('tpl-status-no-correspondence');
        const tplSelect = document.getElementById('tpl-correspondence-select');
        const tplOptgroup = document.getElementById('tpl-optgroup');
        const tplOption = document.getElementById('tpl-select-option');

        if (!tplTable || !tplRow) return;

        const tableNode = tplTable.content.cloneNode(true);
        const formBatch = tableNode.querySelector('#form_batch_link');
        const tbody = tableNode.querySelector('.qonto-transactions-tbody');
        const bankRowidInput = formBatch ? formBatch.querySelector('input[name="bank_rowid"]') : null;

        if (formBatch) {
            const tokenInput = formBatch.querySelector('input[name="token"]');
            if (tokenInput) tokenInput.value = data.token || '';

            if (bankRowidInput) bankRowidInput.value = data.bank_rowid || '';
        }

        const translationsElem = document.getElementById('qonto-js-translations');
        const groupKeys = [
            { key: 'bank', dataAttr: 'optgroupBank' },
            { key: 'cinv', dataAttr: 'optgroupCinv' },
            { key: 'sinv', dataAttr: 'optgroupSinv' },
            { key: 'vat', dataAttr: 'optgroupVat' },
            { key: 'social', dataAttr: 'optgroupSocial' },
            { key: 'salary', dataAttr: 'optgroupSalary' },
            { key: 'expense', dataAttr: 'optgroupExpense' }
        ];

        const transactions = data.transactions || [];

        transactions.forEach(function (t) {
            const row = tplRow.content.cloneNode(true);
            const tr = row.querySelector('tr');

            const isLinked = (t.trx_status === 'linked' && t.linked_rowid > 0);
            tr.classList.add(isLinked ? 'trx-linked' : 'trx-unlinked');

            if (data.hide_linked && isLinked) {
                tr.style.display = 'none';
            }

            // 1. Date
            const dateCell = tr.querySelector('.trx-date');
            if (dateCell) dateCell.textContent = t.date_formatted || '';

            // 2. Label & Reference & ID
            const labelElem = tr.querySelector('.trx-label');
            if (labelElem) labelElem.textContent = t.label || '';

            if (t.reference) {
                const refBadge = tr.querySelector('.trx-reference');
                if (refBadge) {
                    refBadge.textContent = t.reference;
                    refBadge.style.display = 'inline-block';
                }
            }

            const idElem = tr.querySelector('.trx-id-display');
            if (idElem) idElem.textContent = 'ID: ' + t.id;

            // 3. Amount
            const amountElem = tr.querySelector('.trx-amount');
            if (amountElem) {
                amountElem.textContent = (t.amount_formatted || '') + ' ' + (t.currency || '');
                amountElem.style.color = t.is_negative ? 'var(--amountremaintopaycolor)' : 'var(--amountpaymentcomplete)';
            }

            // 4. Correspondence Column
            const correspCell = tr.querySelector('.trx-correspondence-cell');
            if (correspCell) {
                if (t.trx_status === 'errorTooManyTrx') {
                    if (tplErrTooMany) {
                        correspCell.appendChild(tplErrTooMany.content.cloneNode(true));
                    }
                } else if (isLinked) {
                    if (tplLinked) {
                        const linkedBadge = tplLinked.content.cloneNode(true);
                        const linkA = linkedBadge.querySelector('.trx-link-url');
                        if (linkA) linkA.href = t.linked_url;
                        const linkedIdSpan = linkedBadge.querySelector('.trx-linked-id');
                        if (linkedIdSpan) linkedIdSpan.textContent = t.linked_rowid;
                        correspCell.appendChild(linkedBadge);
                    }
                } else {
                    if (t.has_matches && tplSelect) {
                        const selectNode = tplSelect.content.cloneNode(true);
                        const select = selectNode.querySelector('select');
                        const sanitizedId = t.id.replace(/[^a-zA-Z0-9_-]/g, '_');
                        select.name = 'target_val[' + t.id + ']';
                        select.id = 'form_select_' + sanitizedId;

                        groupKeys.forEach(function (grp) {
                            const items = (t.matches && t.matches[grp.key]) ? t.matches[grp.key] : [];
                            if (items.length > 0 && tplOptgroup) {
                                const optgroupNode = tplOptgroup.content.cloneNode(true);
                                const optgroup = optgroupNode.querySelector('optgroup');
                                if (translationsElem && translationsElem.dataset[grp.dataAttr]) {
                                    optgroup.label = translationsElem.dataset[grp.dataAttr];
                                }

                                items.forEach(function (item) {
                                    if (tplOption) {
                                        const optNode = tplOption.content.cloneNode(true);
                                        const opt = optNode.querySelector('option');
                                        opt.value = item.value;
                                        opt.textContent = item.label;
                                        optgroup.appendChild(opt);
                                    }
                                });

                                select.appendChild(optgroup);
                            }
                        });

                        correspCell.appendChild(selectNode);
                    } else if (tplNoMatch) {
                        correspCell.appendChild(tplNoMatch.content.cloneNode(true));
                    }
                }
            }

            // 5. Actions Dropdown
            const advMatchBtn = tr.querySelector('.btn-open-advanced-match');
            if (advMatchBtn) {
                advMatchBtn.setAttribute('data-qonto-id', t.id);
                advMatchBtn.setAttribute('data-bank-rowid', data.bank_rowid || '');
            }

            if (isLinked) {
                const unlinkBtn = tr.querySelector('.btn-ask-unlink');
                if (unlinkBtn) {
                    unlinkBtn.setAttribute('data-bank-rowid', t.linked_rowid);
                    unlinkBtn.style.display = 'block';
                }
            } else if (t.trx_status === 'unlinked') {
                const odBtn = tr.querySelector('.btn-ask-create-od');
                if (odBtn) {
                    odBtn.setAttribute('data-qonto-id', t.id);
                    odBtn.setAttribute('data-bank-rowid', bankRowidInput ? bankRowidInput.value : (data.bank_rowid || ''));
                    odBtn.style.display = 'block';
                }
            }

            tbody.appendChild(tr);
        });

        container.empty().append(tableNode);

        // Initialize Select2 on newly rendered selects
        container.find('select.flat').select2({
            width: 'resolve',
            dropdownAutoWidth: false,
            minimumResultsForSearch: 5
        });
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
                renderTransactions(data);
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

    // Validate all batch matches
    $(document).on('click', '#btn_validate_conciliation', function (e) {
        e.preventDefault();
        $('#form_batch_link').trigger('submit');
    });

    $(document).on('submit', '#form_batch_link', async function (e) {
        e.preventDefault();
        displaySpinner(langProcessBatch);

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
            showNotification(data.message, data.type);

            loadTransactions();
        } catch (error) {
            displayAlert('error', langAjaxError + ' : ' + error.message);
        }
    });

    // Open Advanced Match in Dialog
    $(document).on('click', '.btn-open-advanced-match', function (e) {
        e.preventDefault();
        const qontoId = $(this).attr('data-qonto-id') || $(this).closest('tr').find('.trx-id-display').text().replace('ID: ', '').trim();
        const bankRowid = $(this).attr('data-bank-rowid') || $('input[name="bank_rowid"]').val() || searchForm.find('select[name="bank_rowid"]').val() || '';
        const title = $(this).attr('data-title') || $(this).attr('title') || 'Recherche avancée';

        const $dialog = $('<div id="qonto-advanced-dialog"></div>');

        const tplWrapper = document.getElementById('tpl-advanced-wrapper');
        if (tplWrapper) {
            $dialog.append(tplWrapper.content.cloneNode(true));
        }

        $dialog.dialog({
            modal: true,
            width: 1000,
            height: Math.min($(window).height() * 0.85, 700),
            title: title,
            close: function () {
                $(this).dialog('destroy').remove();
            }
        });

        const token = $('input[name="token"]').val() || '';
        const fetchAdvancedUrl = 'ajax/get_advanced.php?qonto_id=' + encodeURIComponent(qontoId) + 
                                 '&bank_rowid=' + encodeURIComponent(bankRowid) + 
                                 '&token=' + encodeURIComponent(token);

        fetch(fetchAdvancedUrl)
            .then(function (response) {
                if (response.status === 401 || response.status === 403) {
                    window.location.reload();
                    return;
                }
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                if (data.status === 'error') {
                    showError(data.message || 'Une erreur est survenue.');
                    return;
                }
                renderAdvancedModal(data, bankRowid);
            })
            .catch(function (error) {
                showError(error.message || 'Erreur lors de la récupération des données.');
            });
    });

    // Intercept advanced matching form submission
    $(document).on('submit', '#form_advanced_matching', async function (e) {
        e.preventDefault();

        const modalForm = $(this);
        modalForm.find('.action-buttons').hide();
        modalForm.find('#advanced_match_loader').show();

        const formData = new URLSearchParams(new FormData(this));

        try {
            const response = await fetch(updateAdvancedUrl, {
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
            showNotification(data.message, data.type);

            $('#qonto-advanced-dialog').dialog('close');
            loadTransactions();
        } catch (error) {
            modalForm.find('#advanced_match_loader').hide();
            modalForm.find('.action-buttons').show();
            showNotification(langAjaxError + ' : ' + error.message, 'error');
        }
    });

    // Toggle switch to hide/show linked transactions
    $(document).on('click', '[id*="QONTOSYNC_HIDE_LINKED"]', function () {
        $('.trx-linked').toggle();
        const eyeIcon = $('#qontosync_eye_icon');
        if (eyeIcon.hasClass('fa-eye-slash')) {
            eyeIcon.removeClass('fa-eye-slash').addClass('fa-eye');
        } else {
            eyeIcon.removeClass('fa-eye').addClass('fa-eye-slash');
        }
    });

    // Auto-loader if bank account is already selected
    const bankSelect = searchForm.find('select[name="bank_rowid"]');
    if (bankSelect.length > 0 && bankSelect.val() > 0) {
        setTimeout(function () {
            loadTransactions();
        }, 150);
    }

    // Confirmation modal for various payment
    $(document).on('click', '.btn-ask-create-od', function (e) {
        e.preventDefault();
        const qontoId = $(this).attr('data-qonto-id');
        const bankRowid = $(this).attr('data-bank-rowid');

        // Reset the accountancy selector to empty
        if ($('#od_accountancy_code').length > 0) {
            $('#od_accountancy_code').val(null).trigger('change');
        }

        const $dialog = $("#dialog-confirm-create-od");
        const textYes = $dialog.data('yes') || 'Yes';
        const textNo = $dialog.data('no') || 'No';

        const buttonsArray = [
            {
                text: textYes,
                click: async function () {
                    const accountancyCode = $('#od_accountancy_code').val() || '';
                    $(this).dialog("close");
                    displaySpinner(langProcessBatch);

                    const tokenVal = $('input[name="token"]').val() || '';
                    const params = new URLSearchParams({
                        token: tokenVal,
                        qonto_id: qontoId,
                        bank_rowid: bankRowid,
                        accountancy_code: accountancyCode
                    });

                    try {
                        const response = await fetch(createVariousUrl, {
                            method: 'POST',
                            body: params,
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                            }
                        });

                        if (response.status === 401 || response.status === 403) {
                            window.location.reload();
                            return;
                        }

                        const data = await response.json();
                        showNotification(data.message, data.type);

                        loadTransactions();
                    } catch (error) {
                        displayAlert('error', langAjaxError + ' : ' + error.message);
                    }
                }
            },
            {
                text: textNo,
                click: function () {
                    $(this).dialog("close");
                }
            }
        ];

        $dialog.dialog({
            resizable: false,
            height: "auto",
            width: 400,
            modal: true,
            buttons: buttonsArray
        });
    });

    // Confirmation modal for unlinking
    let bankRowidToUnlink = null;
    $(document).on('click', '.btn-ask-unlink', function (e) {
        e.preventDefault();
        bankRowidToUnlink = $(this).attr('data-bank-rowid');

        const $dialog = $("#dialog-confirm-unlink");
        const textYes = $dialog.data('yes') || 'Yes';
        const textNo = $dialog.data('no') || 'No';

        const buttonsArray = [
            {
                text: textYes,
                click: async function () {
                    $(this).dialog("close");
                    displaySpinner(langProcessBatch);

                    const tokenVal = $('input[name="token"]').val() || '';
                    const params = new URLSearchParams({
                        bank_line_id: bankRowidToUnlink,
                        token: tokenVal
                    });

                    try {
                        const response = await fetch(unlinkUrl, {
                            method: 'POST',
                            body: params,
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                            }
                        });

                        if (response.status === 401 || response.status === 403) {
                            window.location.reload();
                            return;
                        }

                        const data = await response.json();
                        showNotification(data.message, data.type);

                        loadTransactions();
                    } catch (error) {
                        displayAlert('error', langAjaxError + ' : ' + error.message);
                    }
                }
            },
            {
                text: textNo,
                click: function () {
                    $(this).dialog("close");
                }
            }
        ];

        $dialog.dialog({
            resizable: false,
            height: "auto",
            width: 400,
            modal: true,
            buttons: buttonsArray
        });
    });
});
