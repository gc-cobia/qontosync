/*
 * QontoSync dolibarr module 
 * @file       fifo.js
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$(document).ready(function () {
    
    // Simple function to parse an amount (handles French comma)
    function getAmount(val) {
        if (!val) return 0;
        let clean = String(val).replace(/\s/g, '').replace(',', '.');
        return parseFloat(clean) || 0;
    }

    // 1. AUTO-FILL ON CHECK
    $(document).on("change", ".check_link_doc", function () {
        let amountInput = $(this).closest("tr").find(".amount_input");
        let modalForm = $(this).closest("form");

        if ($(this).is(":checked")) {
            // The total amount of the bank transaction (can be + or -)
            let totalBank = getAmount($("#qonto_trx_amount").val()); 
            
            // Sum what is already filled in the other inputs
            let otherAllocated = 0;
            modalForm.find(".amount_input").not(amountInput).each(function () {
                if (!$(this).prop("disabled") && $(this).val() !== "") {
                    otherAllocated += getAmount($(this).val());
                }
            });

            // What remains to be distributed (+ or -)
            let available = totalBank - otherAllocated;
            
            // The remaining amount to pay for the clicked document (+ for invoice, - for credit note)
            let remain = getAmount($(this).data("remain"));
            let toAllocate = 0;

            // STRICT RULE: Check the signs
            if (totalBank > 0) {
                // The transaction is POSITIVE (e.g. +3000)
                if (remain > 0 && available > 0) {
                    // It's an invoice, give the maximum possible without exceeding it
                    toAllocate = Math.min(available, remain);
                } else {
                    // It's a credit note, or there's nothing left to distribute -> 0
                    toAllocate = 0;
                }
            } 
            else if (totalBank < 0) {
                // The transaction is NEGATIVE (e.g. -3000)
                if (remain < 0 && available < 0) {
                    // It's a credit note, give the "maximum" possible (closest to zero)
                    toAllocate = Math.max(available, remain);
                } else {
                    // It's an invoice, or there's nothing left to distribute -> 0
                    toAllocate = 0;
                }
            }

            // Display
            if (toAllocate !== 0) {
                amountInput.val(toAllocate.toFixed(2));
            } else {
                amountInput.val("");
            }
        }
    });

    // 2. CONTROL DURING MANUAL INPUT
    $(document).on("input", ".amount_input", function () {
        let checkbox = $(this).closest("tr").find(".check_link_doc");
        if (!checkbox.is(":checked")) return;

        let rawVal = $(this).val();
        // Let the user type a "minus" sign without blocking them immediately
        if (rawVal === "" || rawVal === "-") return;

        let val = getAmount(rawVal);
        let remain = getAmount(checkbox.data("remain"));

        // SIMPLE RESTRICTION TO THE DOCUMENT ONLY
        if (remain > 0) {
            // --- INVOICE (Positive) ---
            if (val < 0) val = 0; // No negative input
            if (val > remain) val = remain; // Not greater than the invoice
        } else if (remain < 0) {
            // --- CREDIT NOTE (Negative) ---
            if (val > 0) val = 0; // No positive input
            if (val < remain) val = remain; // Not smaller than the credit note (e.g. you can't type -600 on a -500 credit note)
        }

        // Only update the input if mathematically necessary (to not disrupt typing)
        if (getAmount(rawVal) !== val) {
            $(this).val(val.toFixed(2));
        }
    });

});