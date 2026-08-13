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

        // 1. On collecte toutes les valeurs actuellement sélectionnées
        allSelects.each(function () {
            let val = $(this).val();
            if (val) {
                selectedValues.push(val);
            }
        });

        // 2. On met à jour les options de chaque liste déroulante
        allSelects.each(function () {
            let currentSelect = $(this);
            let currentVal = currentSelect.val();
            let hasChanged = false;

            currentSelect.find('option').each(function () {
                let optionVal = $(this).val();

                // On ignore les options vides (le placeholder)
                if (optionVal) {
                    // Si l'option est sélectionnée ailleurs, on la désactive
                    if (optionVal !== currentVal && selectedValues.includes(optionVal)) {
                        if (!$(this).prop('disabled')) {
                            $(this).prop('disabled', true);
                            hasChanged = true;
                        }
                    } else {
                        // Sinon, on s'assure qu'elle est active
                        if ($(this).prop('disabled')) {
                            $(this).prop('disabled', false);
                            hasChanged = true;
                        }
                    }
                }
            });

            // 3. Si des options ont été modifiées, on force Select2 à se rafraîchir
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