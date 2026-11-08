/*
 * QontoSync dolibarr module 
 * @file       js/reconciliation.js
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$(document).ready(function () {
    const container = $('#qontosync_results_container');
    const searchForm = $('#qontosync_search_form');

    // ==========================================
    // CONTROLER ENGINE
    // ==========================================

    const routerUrl = 'ajax/router.php';

    async function apiCall(action, data = {}) {
        const tokenVal = $('input[name="token"]').val() || '';

        const payload = {
            token: tokenVal,
            controller: 'reconciliation',
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

        if (response.status === 401 || response.status === 403) {
            throw new Error("Session expirée ou permission refusée");
        }

        const result = await response.json().catch(() => null);

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

    // Load transactions (getTransactions)
    async function loadTransactions() {
        displaySpinner(langSearching);

        const payloadData = {
            month: parseInt(searchForm.find('[name="month"]').val(), 10) || 0,
            year: parseInt(searchForm.find('[name="year"]').val(), 10) || 0,
            bank_rowid: parseInt(searchForm.find('[name="bank_rowid"]').val(), 10) || 0
        };

        try {
            const data = await apiCall('getTransactions', payloadData);

            if (data.error) {
                displayAlert('error', translateError(data.error));
            } else {
                renderTransactions(data);
            }

        } catch (error) {
            displayAlert('error', langAjaxError + ' : ' + error.message);
        }
    }

    // Batch link transactions (batchLink)
    async function batchLink() {
        const tableWrapper = container.find('#qonto-table-wrapper');
        const bankRowid = parseInt(tableWrapper.attr('data-bank-rowid') || searchForm.find('[name="bank_rowid"]').val(), 10) || 0;

        const itemsToLink = [];
        container.find('select.trx-select').each(function () {
            const selectEl = $(this);
            const val = selectEl.val();
            if (!val) return;

            const qontoId = selectEl.attr('data-qonto-id');
            const selectedOpt = selectEl.find('option:selected');
            const type = selectedOpt.attr('data-type');
            const docId = parseInt(selectedOpt.attr('data-doc-id'), 10) || 0;

            if (qontoId && type && docId > 0) {
                itemsToLink.push({
                    qonto_id: qontoId,
                    type: type,
                    doc_id: docId
                });
            }
        });

        if (itemsToLink.length === 0) {
            showNotification('NO_TRANSACTION_TO_LINK', 'warning');
            return;
        }

        displaySpinner(langProcessBatch);

        let successCount = 0;
        let errorCount = 0;
        let completedCount = 0;
        const total = itemsToLink.length;

        const spinnerText = container.find('.qonto-spinner-text');
        if (spinnerText.length > 0) {
            spinnerText.text(langProcessBatch + ' (0/' + total + ')');
        }

        const promises = itemsToLink.map(function (item) {
            return apiCall('batchLink', {
                bank_rowid: bankRowid,
                matches: {
                    [item.qonto_id]: {
                        type: item.type,
                        doc_id: item.doc_id
                    }
                }
            }).then(function (res) {
                if (res && !res.error && res.success_count > 0) {
                    successCount++;
                } else {
                    errorCount++;
                }
            }).catch(function () {
                errorCount++;
            }).finally(function () {
                completedCount++;
                if (spinnerText.length > 0) {
                    spinnerText.text(langProcessBatch + ' (' + completedCount + '/' + total + ')');
                }
            });
        });

        await Promise.all(promises);

        const translationsElem = document.getElementById('qonto-js-translations');
        const batchPattern = (translationsElem && translationsElem.dataset.successBatchCompleted) ? translationsElem.dataset.successBatchCompleted : '%s transaction(s) linked, %s failed.';
        let msg = batchPattern;
        if (msg.includes('%s')) {
            msg = msg.replace('%s', successCount).replace('%s', errorCount);
        } else {
            msg = msg + ' (' + successCount + ' OK, ' + errorCount + ' KO)';
        }
        showNotification(msg, (errorCount > 0 ? 'warning' : 'success'));
        loadTransactions();
    }

    // Load advanced info (getAdvancedInfo)
    async function loadAdvancedInfo(buttonEl) {
        const $btn = $(buttonEl);
        const qontoId = $btn.attr('data-qonto-id') || '';
        const bankRowid = parseInt($btn.attr('data-bank-rowid') || searchForm.find('[name="bank_rowid"]').val(), 10) || 0;
        const title = $btn.attr('title') || 'Advanced Search';

        $('#qonto-advanced-dialog').remove();
        const $dialog = $('<div id="qonto-advanced-dialog"></div>');

        const tplWrapper = document.getElementById('tpl-advanced-wrapper');
        if (tplWrapper) {
            $dialog.append(tplWrapper.content.cloneNode(true));
        }

        $dialog.dialog({
            modal: true,
            width: $(window).width() * 0.75,
            height: Math.min($(window).height() * 0.85, 700),
            title: title,
            close: function () {
                $(this).dialog('destroy').remove();
            }
        });

        try {
            const data = await apiCall('getAdvancedInfo', {
                qonto_id: qontoId,
                bank_rowid: bankRowid
            });

            if (data.error) {
                showError(data.error);
            } else {
                renderAdvancedModal(data, bankRowid);
            }

        } catch (error) {
            showError(langAjaxError + ' : ' + error.message);
        }
    }

    // Batch link advanced matching (batchLinkAdvanced)
    async function batchLinkAdvanced(formEl) {
        const modalForm = $(formEl);
        modalForm.find('.action-buttons').hide();
        modalForm.find('#advanced_match_loader').show();

        const qontoId = modalForm.find('input[name="qonto_id"]').val() || '';
        const bankRowid = parseInt(modalForm.find('input[name="bank_rowid"]').val(), 10) || 0;
        const linkDocs = [];
        const amounts = {};

        modalForm.find('.check_link_doc:checked').each(function () {
            const docKey = $(this).val();
            if (docKey) {
                linkDocs.push(docKey);
                const amtVal = $(this).closest('tr').find('.amount_input').val() || '0';
                amounts[docKey] = amtVal;
            }
        });

        try {
            const data = await apiCall('batchLinkAdvanced', {
                bank_rowid: bankRowid,
                qonto_id: qontoId,
                link_doc: linkDocs,
                amounts: amounts
            });

            if (data.error) {
                modalForm.find('#advanced_match_loader').hide();
                modalForm.find('.action-buttons').show();
                showNotification(data.error, 'error');
            } else {
                $('#qonto-advanced-dialog').dialog('close');
                const translationsElem = document.getElementById('qonto-js-translations');
                const msg = (translationsElem && translationsElem.dataset.successTransactionLinked) ? translationsElem.dataset.successTransactionLinked : 'Transaction successfully linked';
                showNotification(msg, 'success');
                loadTransactions();
            }

        } catch (error) {
            modalForm.find('#advanced_match_loader').hide();
            modalForm.find('.action-buttons').show();
            showNotification(langAjaxError + ' : ' + error.message, 'error');
        }
    }

    /**
     * Convert an HTML5 File object to raw Base64 string
     * @param {File} file
     * @returns {Promise<string>}
     */
    function fileToBase64(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => {
                const base64Data = (reader.result && typeof reader.result === 'string')
                    ? (reader.result.split(',')[1] || '')
                    : '';
                resolve(base64Data);
            };
            reader.onerror = error => reject(error);
            reader.readAsDataURL(file);
        });
    }

    // New various payment (createVariousPayment)
    async function newVariousPayment(qontoId, bankRowid, accountancyCode, attachedFile = null) {
        displaySpinner(langProcessBatch);

        try {
            const data = await apiCall('createVariousPayment', {
                qonto_id: qontoId,
                bank_rowid: parseInt(bankRowid, 10) || 0,
                accountancy_code: accountancyCode || '',
                attached_file: attachedFile
            });

            if (data.error) {
                showNotification(data.error, 'error');
                loadTransactions();
            } else {
                const translationsElem = document.getElementById('qonto-js-translations');
                const msg = (translationsElem && translationsElem.dataset.successVariousPaymentCreated) ? translationsElem.dataset.successVariousPaymentCreated : 'Various payment created successfully';
                showNotification(msg, 'success');
                loadTransactions();
            }

        } catch (error) {
            showNotification(langAjaxError + ' : ' + error.message, 'error');
            loadTransactions();
        }
    }

    // Unlink transaction (unlinkTransaction)
    async function unlinkTransaction(bankRowid, bankLineId) {
        displaySpinner(langProcessBatch);

        try {
            const data = await apiCall('unlinkTransaction', {
                bank_rowid: parseInt(bankRowid, 10) || 0,
                bank_line_id: parseInt(bankLineId, 10) || 0
            });

            if (data.error) {
                showNotification(data.error, 'error');
                loadTransactions();
            } else {
                const translationsElem = document.getElementById('qonto-js-translations');
                const msg = (translationsElem && translationsElem.dataset.successTransactionUnlinked) ? translationsElem.dataset.successTransactionUnlinked : 'Transaction successfully unlinked';
                showNotification(msg, 'success');
                loadTransactions();
            }

        } catch (error) {
            showNotification(langAjaxError + ' : ' + error.message, 'error');
            loadTransactions();
        }
    }

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

    function translateError(errorCode) {
        if (!errorCode || typeof errorCode !== 'string') return errorCode || '';
        const translationsElem = document.getElementById('qonto-js-translations');
        if (translationsElem) {
            // e.g. FAILED_TO_CREATE_PAYMENT -> errorFailedToCreatePayment
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
        let jnType = 'success'; // Default
        if (type === 'warnings' || type === 'warning') jnType = 'warning';
        if (type === 'errors' || type === 'error') jnType = 'error';

        if (typeof $.jnotify === 'function') {
            $.jnotify(displayMsg, jnType);
        } else {
            // Native Dolibarr notifications fallback 
            alert(displayMsg);
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
                $(clonePending).find('input[name="qonto_id"]').val(trx.id || '');
                $(clonePending).find('input[name="bank_rowid"]').val(bankRowid || searchForm.find('[name="bank_rowid"]').val() || '');
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
        const tableWrapper = tableNode.querySelector('#qonto-table-wrapper');
        const tbody = tableNode.querySelector('.qonto-transactions-tbody');

        if (tableWrapper) {
            tableWrapper.setAttribute('data-bank-rowid', data.bank_rowid || '');
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
                        select.setAttribute('data-qonto-id', t.id);

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
                                        opt.value = item.type + '_' + item.doc_id;
                                        opt.setAttribute('data-type', item.type);
                                        opt.setAttribute('data-doc-id', item.doc_id);
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
                    unlinkBtn.setAttribute('data-bank-line-id', t.linked_rowid);
                    unlinkBtn.setAttribute('data-bank-rowid', data.bank_rowid || '');
                    unlinkBtn.style.display = 'block';
                }
            } else if (t.trx_status === 'unlinked') {
                const odBtn = tr.querySelector('.btn-ask-create-od');
                if (odBtn) {
                    odBtn.setAttribute('data-qonto-id', t.id);
                    odBtn.setAttribute('data-bank-rowid', data.bank_rowid || '');
                    odBtn.style.display = 'block';
                }
            }

            tbody.appendChild(tr);
        });

        container.empty().append(tableNode);

        const tplAllLinked = document.getElementById('tpl-all-linked-message');
        if (tplAllLinked) {
            container.append(tplAllLinked.content.cloneNode(true));
        }

        // Initialize Select2 on newly rendered selects
        container.find('select.flat').select2({
            width: 'resolve',
            dropdownAutoWidth: false,
            minimumResultsForSearch: 5
        });

        checkAllLinkedVisibility();
    }

    function checkAllLinkedVisibility() {
        const totalRows = container.find('.trx-row').length;
        const unlinkedRows = container.find('.trx-unlinked').length;

        const isHidingLinked = $('#qontosync_eye_icon').hasClass('fa-eye-slash');

        const tableWrapper = container.find('#qonto-table-wrapper');
        const allLinkedMsg = container.find('#qonto-all-linked-message');

        if (totalRows > 0 && unlinkedRows === 0 && isHidingLinked) {
            tableWrapper.hide();
            allLinkedMsg.show();
        } else {
            tableWrapper.show();
            allLinkedMsg.hide();
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
        batchLink();
    });

    // Open Advanced Match (hamburger menu)
    $(document).on('click', '.btn-open-advanced-match', function (e) {
        e.preventDefault();
        loadAdvancedInfo(this);
    });

    // Intercept advanced matching form submission
    $(document).on('submit', '#form_advanced_matching', function (e) {
        e.preventDefault();
        batchLinkAdvanced(this);
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
        checkAllLinkedVisibility();
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

        // Retrieve data from the table row
        const tr = $(this).closest('tr');
        const trxDate = tr.find('.trx-date').text();
        const trxLabel = tr.find('.trx-label').text();
        const trxAmount = tr.find('.trx-amount').text();
        const trxAmountColor = tr.find('.trx-amount').css('color');

        // Hydrate the modal
        $('#od_dialog_date').text(trxDate);
        $('#od_dialog_label').text(trxLabel);
        $('#od_dialog_amount').text(trxAmount).css('color', trxAmountColor);

        // Reset the accountancy selector to empty
        if ($('#od_accountancy_code').length > 0) {
            $('#od_accountancy_code').val(null).trigger('change');
        }

        // Reset the attached file input
        $('#od_attached_file').val('');

        const $dialog = $("#dialog-confirm-create-od");
        const textYes = $dialog.data('yes') || 'Yes';
        const textNo = $dialog.data('no') || 'No';

        const buttonsArray = [
            {
                text: textYes,
                click: async function () {
                    const $thisDialog = $(this);
                    const accountancyCode = $('#od_accountancy_code').val() || '';
                    const fileInput = document.getElementById('od_attached_file');

                    let attachedFile = null;
                    if (fileInput && fileInput.files && fileInput.files.length > 0) {
                        const file = fileInput.files[0];
                        try {
                            const base64Content = await fileToBase64(file);
                            attachedFile = {
                                filename: file.name,
                                content: base64Content
                            };
                        } catch (err) {
                            showNotification((typeof langAjaxError !== 'undefined' ? langAjaxError : 'Error') + ' : ' + err.message, 'error');
                            return;
                        }
                    }

                    $thisDialog.dialog("close");
                    await newVariousPayment(qontoId, bankRowid, accountancyCode, attachedFile);
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
            width: Math.min($(window).width() * 0.95, 550),
            modal: true,
            buttons: buttonsArray
        });
    });

    // Confirmation modal for unlinking
    $(document).on('click', '.btn-ask-unlink', function (e) {
        e.preventDefault();
        const bankLineIdToUnlink = $(this).attr('data-bank-line-id');
        const bankRowidToUnlink = $(this).attr('data-bank-rowid') || searchForm.find('[name="bank_rowid"]').val();

        const $dialog = $("#dialog-confirm-unlink");
        const textYes = $dialog.data('yes') || 'Yes';
        const textNo = $dialog.data('no') || 'No';

        const buttonsArray = [
            {
                text: textYes,
                click: function () {
                    $(this).dialog("close");
                    unlinkTransaction(bankRowidToUnlink, bankLineIdToUnlink);
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
            width: Math.min($(window).width() * 0.95, 400),
            modal: true,
            buttons: buttonsArray
        });
    });
});