/*
 * QontoSync dolibarr module 
 * @file       js/hamburger.js
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$(document).ready(function () {
    $(document).on('click', '.qonto-dropdown button', function (e) {
        e.preventDefault();

        var $dropdownMenu = $(this).siblings('.qonto-dropdown-content');

        $('.qonto-dropdown-content').not($dropdownMenu).removeClass('show');
        $dropdownMenu.toggleClass('show');
        e.stopPropagation();
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('.qonto-dropdown').length) {
            $('.qonto-dropdown-content').removeClass('show');
        }
    });
});