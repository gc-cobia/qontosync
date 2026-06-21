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

    // MODALE SPINNER
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

    // TOGGLE SWITCH TO HIDE LINKED TRANSACTIONS
    $(document).on('click', '[id*="QONTOSYNC_HIDE_LINKED"]', function () {
        $('.trx-linked').toggle();
        let eyeIcon = $('#qontosync_eye_icon');
        if (eyeIcon.hasClass('fa-eye-slash')) {
            eyeIcon.removeClass('fa-eye-slash').addClass('fa-eye');
        } else {
            eyeIcon.removeClass('fa-eye').addClass('fa-eye-slash');
        }
    });
});