$(document).ready(function () {
    $('#qontosync_search_form').submit(function (e) {
        e.preventDefault(); 
        
        let container = $('#qontosync_results_container');
        
        // Loading icon
        container.html('<div style="text-align:center; padding:50px;"><i class="fas fa-spinner fa-pulse fa-3x"></i></div>');

        // Target php to execute
        let ajaxUrl = 'ajax/get_transactions.php';

        $.post(ajaxUrl, $(this).serialize(), function (htmlContent) {
            // Succes
            container.html(htmlContent); 
        }).fail(function (xhr, status, error) {
            // Error
            container.html('<div class="error">Erreur de communication AJAX : ' + error + ' (Code: ' + xhr.status + ')</div>');
        });
    });
});