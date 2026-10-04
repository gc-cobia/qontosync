/*
 * QontoSync dolibarr module 
 * @file       js/hamburger.js
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$(document).ready(function () {

    function closeAllMenus() {
        $('.qonto-dropdown-content').removeClass('show');
        $('.qonto-dropdown-toggle').attr('aria-expanded', 'false');
    }

    // Toggle button only (not the menu items, which have their own handlers)
    $(document).on('click', '.qonto-dropdown-toggle', function (e) {
        e.preventDefault();
        e.stopPropagation();

        var $menu = $(this).siblings('.qonto-dropdown-content');
        var willOpen = !$menu.hasClass('show');

        closeAllMenus();
        if (willOpen) {
            $menu.addClass('show');
            $(this).attr('aria-expanded', 'true');
        }
    });

    // Close the menu once an item is chosen
    $(document).on('click', '.qonto-dropdown-item', function () {
        closeAllMenus();
    });

    // Click outside closes the menu
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.qonto-dropdown').length) {
            closeAllMenus();
        }
    });

    // Escape closes the menu
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            closeAllMenus();
        }
    });
});