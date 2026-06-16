/*
 * QontoSync dolibarr module 
 * @file       advanced_matching.js
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$(document).ready(function() {
    $(".check_link_doc").on("change", function() {
        let checkedBoxes = $(".check_link_doc:checked");
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
            $(".check_link_doc").prop("disabled", false);
            $(".doc_row").removeClass("opacitymedium");
        } else {
            // If at least one checkbox is checked, retrieve its attributes
            let firstChecked = checkedBoxes.first();
            let docType = firstChecked.data("doctype");
            let thirdParty = firstChecked.data("thirdparty");

            $(".check_link_doc").each(function() {
                if (!$(this).prop("checked")) {
                    let thisType = $(this).data("doctype");
                    let thisThirdParty = $(this).data("thirdparty");

                    // If the document type OR the third party is different, gray out and disable
                    if (thisType !== docType || thisThirdParty !== thirdParty) {
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
});