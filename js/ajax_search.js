/*
 * QontoSync dolibarr module 
 * @file       js/ajax_search.js
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */
 
 $(document).ready(function () {
    let container = $('#qontosync_results_container');
    let fetchUrl = 'ajax/get_transactions.php';
    let updateUrl = 'ajax/update_batch.php';

    // QUERY & PRINTING QONTO TRANSACTIONS
    $('#qontosync_search_form').submit(function (e) {
        e.preventDefault();
        
        container.html('<div style="text-align:center; padding:50px;"><i class="fas fa-spinner fa-pulse fa-3x"></i></div>');

        $.post(fetchUrl, $(this).serialize(), function (htmlContent) {
            container.html(htmlContent);
            container.find('select.flat').select2({
                width: 'resolve',
                dropdownAutoWidth: false,
                minimumResultsForSearch: 5
            });
            
        }).fail(function (xhr, status, error) {
            // CLEAN NETWORK INTERCEPTION (HTTP 401)
            if (xhr.status === 401) {
                window.location.reload(); 
                return;
            }
            container.html('<div class="error">Erreur AJAX : ' + error + '</div>');
        });
    });

    // PROCESSING UPDATE
    $(document).on('submit', '#form_batch_link', function (e) {
        e.preventDefault();
        
        container.html('<div style="text-align:center; padding:50px;"><i class="fas fa-spinner fa-pulse fa-3x"></i><br><br>Traitement en cours...</div>');

        $.post(updateUrl, $(this).serialize(), function (response) {
            $('#qontosync_search_form').submit();

        }).fail(function (xhr, status, error) {
            if (xhr.status === 401) {
                window.location.reload(); 
                return;
            }
            container.html('<div class="error">Error during update : ' + error + '</div>');
        });
    });
    
    // PROCESSING ADVANCED UPDATE
    window.processAdvancedMatch = function(formData) {
        let updateAdvancedUrl = 'ajax/update_advanced.php';

        container.html('<div style="text-align:center; padding:50px;"><i class="fas fa-spinner fa-pulse fa-3x"></i><br><br>Traitement du rapprochement complexe en cours...</div>');

        $.post(updateAdvancedUrl, formData, function (response) {
            $('#qontosync_search_form').submit();

        }).fail(function (xhr, status, error) {
            if (xhr.status === 401) {
                window.location.reload(); 
                return;
            }
            container.html('<div class="error">Error during advanced update : ' + error + '</div>');
        });
    };

    // MODALE SPINNER
    $(document).on('dialogopen', function(e) {
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
        // Hiding transactions
        $('.trx-linked').toggle();
        
        // Icon statement
        let eyeIcon = $('#qontosync_eye_icon');
        if (eyeIcon.hasClass('fa-eye-slash')) {
            eyeIcon.removeClass('fa-eye-slash').addClass('fa-eye');
        } else {
            eyeIcon.removeClass('fa-eye').addClass('fa-eye-slash');
        }
    });
});