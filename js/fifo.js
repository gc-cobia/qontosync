/*
 * QontoSync dolibarr module 
 * @file       js/fifo.js
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$(document).ready(function () {

    // Function to parse an amount (handles French comma)
    function getAmount(val) {
        if (!val) return 0;
        let clean = String(val).replace(/\s/g, '').replace(',', '.');
        return parseFloat(clean) || 0;
    }

    // 1. AUTO-FILL WHEN CHECKING THE BOX
    $(document).on("change", ".check_link_doc", function () {
        let amountInput = $(this).closest("tr").find(".amount_input");
        let modalForm = $(this).closest("form");

        if ($(this).is(":checked")) {
            // The transaction amount is always absolute in HTML
            let totalBank = getAmount($("#qonto_trx_amount").val());

            // Add the values already entered (positive or negative)
            let otherAllocated = 0;
            modalForm.find(".amount_input").not(amountInput).each(function () {
                if (!$(this).prop("disabled") && $(this).val() !== "") {
                    otherAllocated += getAmount($(this).val());
                }
            });

            // Remaining amount to allocate
            let available = totalBank - otherAllocated;

            // Remaining amount to pay (positive for invoice, negative for credit note)
            let remain = getAmount($(this).data("remain"));
            let toAllocate = 0;

            if (remain > 0) {
                // It's an invoice, allocate the maximum available without exceeding the remainder
                toAllocate = (available > 0) ? Math.min(available, remain) : 0;
            } else if (remain < 0) {
                // It's a credit note (negative), allocate it systematically in full 
                // to "dig" the gap and increase the available amount for other invoices
                toAllocate = remain;
            }

            if (toAllocate !== 0) {
                amountInput.val(toAllocate.toFixed(2));
            } else {
                amountInput.val("");
            }
        }
    });

    // 2. LIVE CONTROL DURING ENTRY (UX)
    $(document).on("input", ".amount_input", function () {
        let checkbox = $(this).closest("tr").find(".check_link_doc");
        if (!checkbox.is(":checked")) return;

        let rawVal = $(this).val();
        let remain = getAmount(checkbox.data("remain"));

        // --- LIVE UX FEATURE: FORCING SIGNS DIRECTLY ---
        if (remain < 0 && rawVal !== "" && rawVal !== "-" && !rawVal.startsWith("-")) {
            // If it's a credit note and he types a number without the minus, add it
            rawVal = "-" + rawVal;
            $(this).val(rawVal);
        } else if (remain > 0 && rawVal.startsWith("-")) {
            // If it's an invoice and he types a minus, remove it
            rawVal = rawVal.replace("-", "");
            $(this).val(rawVal);
        }
        // ----------------------------------------------------

        // Let the user type a minus alone or clear the box without blocking it
        if (rawVal === "" || rawVal === "-") return;

        let val = getAmount(rawVal);

        // Mathematical limitation of boundaries
        if (remain > 0) {
            // Entry on an invoice: Only negative numbers are forbidden
            if (val < 0) val = 0;
        } else if (remain < 0) {
            // Entry on a credit note: Force in negative, without blocking the quantity
            if (val > 0) val = -val;
        }

        // Reformat completely (which breaks decimal entry) only if the user has entered an amount outside the limits
        if (getAmount(rawVal) !== val) {
            $(this).val(val.toFixed(2));
        }
    });

    // 3. CLEAN FORMATTING WHEN LEAVING THE FIELD
    $(document).on("change", ".amount_input", function () {
        let rawVal = $(this).val();
        // When the user clicks elsewhere, ensure cents are displayed (e.g.: -50.00)
        if (rawVal !== "" && rawVal !== "-") {
            $(this).val(getAmount(rawVal).toFixed(2));
        }
    });

});