/*
 * QontoSync dolibarr module 
 * @file       js/ajax_search.js
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$(document).ready(function () {
    let container = $('#qontosync_results_container');
    let searchForm = $('#qontosync_search_form');

    let fetchUrl = 'ajax/get_transactions.php';
    let updateUrl = 'ajax/update_batch.php';

    // Fetching translations from HTML
    let langAjaxError = searchForm.data('lang-ajax-error');
    let langProcessBatch = searchForm.data('lang-processing-batch');
    let langProcessAdvanced = searchForm.data('lang-processing-advanced');
    let langSearching = searchForm.data('lang-searching');

    // 1. CENTRAL FUNCTION FOR LOAD TRANSACTIONS
    function loadTransactions() {
        container.html('<div style="text-align:center; padding:50px;" class="opacitymedium"><i class="fas fa-spinner fa-pulse fa-3x"></i><br><br>' + langSearching + '</div>');

        $.post(fetchUrl, searchForm.serialize(), function (htmlContent) {
            container.html(htmlContent);
            container.find('select.flat').select2({
                width: 'resolve',
                dropdownAutoWidth: false,
                minimumResultsForSearch: 5
            });
        }).fail(function (xhr, status, error) {
            // Silence reload in case of session expiration or token invalidity
            if (xhr.status === 401 || xhr.status === 403) {
                window.location.reload();
                return;
            }
            container.html('<div class="error">' + langAjaxError + ' : ' + error + '</div>');
        });
    }

    // 2. QUERY & PRINTING QONTO TRANSACTIONS
    searchForm.on('submit', function (e) {
        e.preventDefault();
        loadTransactions();
    });

    // 3. PROCESSING UPDATE
    $(document).on('click', '#btn_validate_conciliation', function (e) {
        e.preventDefault();
        $('#form_batch_link').trigger('submit');
    });

    $(document).on('submit', '#form_batch_link', function (e) {
        e.preventDefault();

        container.html('<div style="text-align:center; padding:50px;" class="opacitymedium"><i class="fas fa-spinner fa-pulse fa-3x"></i><br><br>' + langProcessBatch + '</div>');

        $.post(updateUrl, $(this).serialize(), function (response) {
            loadTransactions();
        }).fail(function (xhr, status, error) {
            if (xhr.status === 401 || xhr.status === 403) {
                window.location.reload();
                return;
            }
            container.html('<div class="error">' + langAjaxError + ' : ' + error + '</div>');
        });
    });

    // 4. PROCESSING ADVANCED UPDATE (Modale)
    window.processAdvancedMatch = function (formData) {
        let updateAdvancedUrl = 'ajax/update_advanced.php';

        container.html('<div style="text-align:center; padding:50px;" class="opacitymedium"><i class="fas fa-spinner fa-pulse fa-3x"></i><br><br>' + langProcessAdvanced + '</div>');

        $.post(updateAdvancedUrl, formData, function (response) {
            loadTransactions();
        }).fail(function (xhr, status, error) {
            if (xhr.status === 401 || xhr.status === 403) {
                window.location.reload();
                return;
            }
            container.html('<div class="error">' + langAjaxError + ' : ' + error + '</div>');
        });
    };

    // 7. MODALE SPINNER
    $(document).on('dialogopen', function (e) {
        let dialog = $(e.target);
        if (dialog.find('iframe').length > 0) {
            if (dialog.find('#qonto-spinner-loader').length === 0) {
                dialog.append('<div id="qonto-spinner-loader" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 1;"><i class="fas fa-spinner fa-pulse fa-3x"></i></div>');
                dialog.find('iframe').css({
                    'position': 'relative',
                    'z-index': '2',
                    'background-color': 'transparent'
                });
            }
        }
    });

    // 8. TOGGLE SWITCH TO HIDE LINKED TRANSACTIONS
    $(document).on('click', '[id*="QONTOSYNC_HIDE_LINKED"]', function () {
        $('.trx-linked').toggle();
        let eyeIcon = $('#qontosync_eye_icon');
        if (eyeIcon.hasClass('fa-eye-slash')) {
            eyeIcon.removeClass('fa-eye-slash').addClass('fa-eye');
        } else {
            eyeIcon.removeClass('fa-eye').addClass('fa-eye-slash');
        }
    });

    // 9. AUTO-LOADER
    let bankSelect = searchForm.find('select[name="bank_rowid"]');
    if (bankSelect.length > 0 && bankSelect.val() > 0) {
        setTimeout(function () {
            loadTransactions();
        }, 150);
    }

    // 10. CONFIRMATION MODAL FOR VARIOUS PAYMENT
    $(document).on('click', '.btn-ask-create-od', function (e) {
        e.preventDefault();
        let targetUrl = $(this).data('url');

        // Reset the accountancy selector to empty at the opening of the modal
        if ($('#od_accountancy_code').length > 0) {
            $('#od_accountancy_code').val(null).trigger('change');
        }

        let $dialog = $("#dialog-confirm-create-od");
        let textYes = $dialog.data('yes') || 'Yes';
        let textNo = $dialog.data('no') || 'No';

        let buttonsArray = [];
        let btnYes = {
            text: textYes,
            click: function () {
                // Get the accountancy code if selected
                let accountancyCode = $('#od_accountancy_code').val();
                if (accountancyCode) {
                    targetUrl += '&accountancy_code=' + encodeURIComponent(accountancyCode);
                }

                $(this).dialog("close");
                window.location.href = targetUrl;
            }
        };
        let btnNo = {
            text: textNo,
            click: function () {
                $(this).dialog("close");
            }
        };

        buttonsArray.push(btnYes);
        buttonsArray.push(btnNo);

        $dialog.dialog({
            resizable: false,
            height: "auto",
            width: 400,
            modal: true,
            buttons: buttonsArray
        });
    });

    // 11. CONFIRMATION MODAL FOR UNLINKING
    let bankRowidToUnlink = null;
    $(document).on('click', '.btn-ask-unlink', function (e) {
        e.preventDefault();
        bankRowidToUnlink = $(this).data('bank-rowid');

        let $dialog = $("#dialog-confirm-unlink");
        let textYes = $dialog.data('yes') || 'Yes';
        let textNo = $dialog.data('no') || 'No';

        let buttonsArray = [];
        let btnYes = {
            text: textYes,
            click: function () {
                $(this).dialog("close");
                container.html('<div style="text-align:center; padding:50px;" class="opacitymedium"><i class="fas fa-spinner fa-pulse fa-3x"></i></div>');
                $.post('ajax/unlink_transaction.php', { bank_line_id: bankRowidToUnlink, token: $('input[name="token"]').val() }, function (response) {
                    loadTransactions();
                }).fail(function (xhr, status, error) {
                    if (xhr.status === 401 || xhr.status === 403) {
                        window.location.reload();
                        return;
                    }
                    container.html('<div class="error">' + langAjaxError + ' : ' + error + '</div>');
                });
            }
        };
        let btnNo = {
            text: textNo,
            click: function () {
                $(this).dialog("close");
            }
        };

        buttonsArray.push(btnYes);
        buttonsArray.push(btnNo);

        $dialog.dialog({
            resizable: false,
            height: "auto",
            width: 400,
            modal: true,
            buttons: buttonsArray
        });
    });
});