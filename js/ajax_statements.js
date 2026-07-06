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
    let langSearching = searchForm.data('lang-searching');

    // 1. CENTRAL FUNCTION FOR LOAD STATEMENTS
    function loadTransactions() {
        container.html('<div style="text-align:center; padding:50px;" class="opacitymedium"><i class="fas fa-spinner fa-pulse fa-3x"></i><br><br>' + langSearching + '</div>');

        $.post(fetchUrl, searchForm.serialize(), function (htmlContent) {
            container.html(htmlContent);
            
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
    $(document).on('click', '#btn_import_statements', function(e) {
        e.preventDefault();
        $('#form_batch_link').trigger('submit');
    });
    
    $(document).on('submit', '#form_batch_link', function (e) {
        e.preventDefault();

        let btn = $('#btn_import_statements');
        let originalHtml = btn.html();

        // Change the import button to a loader instead of destroying the table
        btn.html('<i class="fas fa-spinner fa-pulse marginrightonly"></i>' + langProcessBatch);
        btn.prop('disabled', true);

        $.post(updateUrl, $(this).serialize(), function (response) {
            btn.html(originalHtml);
            btn.prop('disabled', false);

            $('#qontosync_results_container').prepend(response);

            $('.qonto-sync-available').each(function() {
                let url = $(this).data('url');
                let text = $(this).data('open-text');
                let successBadge = '<a href="' + url + '" target="_blank" class="badge badge-success" style="color: white; text-decoration: none; padding: 6px;"><i class="fas fa-file-pdf marginrightonly"></i>' + text + '</a>';
                $(this).replaceWith(successBadge);
            });

        }).fail(function (xhr, status, error) {
            if (xhr.status === 401 || xhr.status === 403) {
                window.location.reload();
                return;
            }
        });
    });
});