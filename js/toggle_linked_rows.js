/*
 *  QontoSync dolibarr module 
 *  @file       js/toggle_linked_rows.js
 *  @author     Gaëtan COURTOIS
 *  @license    GPLv3
 */

$(document).ready(function () {
    function toggleLinkedRows() {
        if ($("#filter_linked").is(":checked")) {
            $(".trx-linked").hide();
        } else {
            $(".trx-linked").show();
        }
    }

    toggleLinkedRows();

    $("#filter_linked").change(function () {
        toggleLinkedRows();
    });
});