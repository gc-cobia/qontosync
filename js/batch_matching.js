/*
 * QontoSync dolibarr module 
 * @file       js/batch_matching.js
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$(document).ready(function () {
    // PREVENT DUPLICATE SELECTION IN DROPDOWNS
    $(document).on('change', 'select[name^="target_val"]', function () {
        let allSelects = $('select[name^="target_val"]');
        let selectedValues = [];

        // Collect all selected values
        allSelects.each(function () {
            let val = $(this).val();
            if (val) {
                selectedValues.push(val);
            }
        });

        // Update the options of each dropdown
        allSelects.each(function () {
            let currentSelect = $(this);
            let currentVal = currentSelect.val();
            let hasChanged = false;

            currentSelect.find('option').each(function () {
                let optionVal = $(this).val();

                // Ignore empty options (placeholder)
                if (optionVal) {
                    // If the option is selected elsewhere, disable it
                    if (optionVal !== currentVal && selectedValues.includes(optionVal)) {
                        if (!$(this).prop('disabled')) {
                            $(this).prop('disabled', true);
                            hasChanged = true;
                        }
                    } else {
                        // Ensure it's active
                        if ($(this).prop('disabled')) {
                            $(this).prop('disabled', false);
                            hasChanged = true;
                        }
                    }
                }
            });

            // Refresh Select2 if options changed
            if (hasChanged && currentSelect.hasClass('select2-hidden-accessible')) {
                currentSelect.select2('destroy').select2({
                    width: 'resolve',
                    dropdownAutoWidth: false,
                    minimumResultsForSearch: 5
                });
            }
        });
    });
});