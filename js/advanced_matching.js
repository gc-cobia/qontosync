/*
 * QontoSync dolibarr module 
 * @file       js/advanced_matching.js
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$(document).ready(function () {
    // Event delegation is required because the modal content is loaded via AJAX
    $(document).on("change", ".check_link_doc", function () {
        let modalForm = $(this).closest("form");
        let checkedBoxes = modalForm.find(".check_link_doc:checked");
        let row = $(this).closest("tr");
        let amountInput = row.find(".amount_input");

        // Enable/Disable the amount input field for the current row
        if ($(this).is(":checked")) {
            amountInput.prop("disabled", false);
        } else {
            amountInput.prop("disabled", true).val("");
        }

        // Locking logic for the other rows
        if (checkedBoxes.length === 0) {
            // If nothing is checked, unlock everything
            modalForm.find(".check_link_doc").prop("disabled", false);
            modalForm.find(".doc_row").removeClass("opacitymedium");
        } else {
            // If at least one checkbox is checked, retrieve its attributes
            let firstChecked = checkedBoxes.first();
            let docType = firstChecked.data("doctype");
            let thirdParty = firstChecked.data("thirdparty");

            // Definition of strictly "1 to 1" document types
            let isStrictlyOneToOne = ['vat', 'social', 'salary', 'expense'].includes(docType);

            modalForm.find(".check_link_doc").each(function () {
                if (!$(this).prop("checked")) {
                    let thisType = $(this).data("doctype");
                    let thisThirdParty = $(this).data("thirdparty");

                    // If it is a 1-to-1 document, disable all other cases
                    // Otherwise (ex: invoices), just check that it's the same type and same third party
                    if (isStrictlyOneToOne || thisType !== docType || thisThirdParty !== thirdParty) {
                        $(this).prop("disabled", true);
                        $(this).closest("tr").addClass("opacitymedium");
                    } else {
                        $(this).prop("disabled", false);
                        $(this).closest("tr").removeClass("opacitymedium");
                    }
                }
            });
        }
    });
    
    
    // Click anywhere on the line to check/uncheck
    $(document).on("click", ".doc_row", function (e) {
        // The event is ignored if the user clicked directly on the checkbox or in the input field
        if ($(e.target).is("input")) return;

        let checkbox = $(this).find(".check_link_doc");
        
        // If the line is not grayed out or disabled, a click on the checkbox is simulated
        if (!checkbox.prop("disabled")) {
            checkbox.prop("checked", !checkbox.prop("checked")).trigger("change");
        }
    });
    
    // Live Search
    $(document).on("keyup", "#advanced_search_input", function() {
        let value = $(this).val().toLowerCase().replace(/\./g, ',').replace(/\s+/g, '');
        let modalContainer = $(this).closest("#qontosync_advanced_wrapper");
        
        let visibleCount = 0;
        
        modalContainer.find(".doc_row").each(function() {
            let rowText = $(this).text().toLowerCase().replace(/\s+/g, '');
            
            if (rowText.indexOf(value) > -1) {
                $(this).show();
                visibleCount++;
            } else {
                $(this).hide();
            }
        });
        
        // No result
        if (visibleCount === 0 && value !== "") {
            modalContainer.find("#no_search_result_row").show();
        } else {
            modalContainer.find("#no_search_result_row").hide();
        }
    });

});