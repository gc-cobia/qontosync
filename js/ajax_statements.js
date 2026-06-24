/*
 * QontoSync dolibarr module 
 * @file       js/ajax_statements.js
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$(document).ready(function () {
    let container = $('#qontosync_results_container');
    let searchForm = $('#qontosync_search_form');

    let fetchUrl = 'ajax/get_statements.php';
    let updateUrl = 'ajax/update_statements.php';

    // Fetching statements from HTML
    let langAjaxError = searchForm.data('lang-ajax-error');
    let langProcessBatch = searchForm.data('lang-processing-batch');
    let langProcessAdvanced = searchForm.data('lang-processing-advanced');
    let langSearching = searchForm.data('lang-searching');

    // 1. CENTRAL FUNCTION FOR LOAD STATEMENTS
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

    // 2. QUERY & PRINTING QONTO STATEMENTS
    searchForm.on('submit', function (e) {
        e.preventDefault();
        loadTransactions();
    });

    // 3. PROCESSING DOWNLOAD STATEMENTS
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
});