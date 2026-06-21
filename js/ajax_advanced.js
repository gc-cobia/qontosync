/*
 * QontoSync dolibarr module 
 * @file       js/ajax_advanced.js
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$(document).ready(function () {

    // 1. AS SOON AS THE PAGE IS LOADED: Remove the spinner from the parent page!
    if (window.parent && window.parent.$) {
        window.parent.$('#qonto-spinner-loader').remove();
    }

    // 2. INTERCEPT FORM SUBMISSION
    $(document).on('submit', '#form_advanced_matching', function (e) {
        e.preventDefault();

        let modalForm = $(this);
        let formData = modalForm.serialize();

        let langErrorMain = $('#qontosync_advanced_wrapper').data('lang-error-main');

        // Display the internal spinner when clicking on Submit
        modalForm.find('.action-buttons').hide();
        modalForm.find('#advanced_match_loader').show();

        // Delegate the processing to the parent window
        if (typeof window.parent.processAdvancedMatch === 'function') {
            window.parent.processAdvancedMatch(formData);
        } else {
            alert(langErrorMain);
            return;
        }

        // Close the modal instantly
        window.parent.$('.ui-dialog-content').dialog('close');
    });

});