/*
 * QontoSync dolibarr module 
 * @file       fifo.js
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$(document).ready(function() {
    // Listen for checkbox changes within the modal
    $(document).on("change", ".check_link_doc", function() {
        let row = $(this).closest("tr");
        let amountInput = row.find(".amount_input");
        
        // Only run the FIFO calculation if the box is being checked
        if ($(this).is(":checked")) {
            
            // Retrieve the total absolute amount of the Qonto transaction
            let totalBankAmount = parseFloat($("#qonto_trx_amount").val()) || 0;
            
            // Calculate the sum of amounts already inputted in OTHER checked rows
            let currentSum = 0;
            $(".check_link_doc:checked").not(this).each(function() {
                let val = parseFloat($(this).closest("tr").find(".amount_input").val()) || 0;
                currentSum += val;
            });
            
            // Calculate how much money from the bank transaction is still available
            let availableBankAmount = totalBankAmount - currentSum;
            
            // Retrieve the remaining amount due for this specific document
            let docRemain = parseFloat($(this).data("remain")) || 0;
            
            // If the user hasn't manually typed an amount yet
            if (amountInput.val() === "") {
                if (availableBankAmount > 0) {
                    // Assign the smallest value between available bank money and document debt
                    let toAllocate = Math.min(availableBankAmount, docRemain);
                    amountInput.val(toAllocate.toFixed(2));
                } else {
                    // No money left from the bank transaction to allocate
                    amountInput.val("0.00");
                }
            }
        }
    });
});