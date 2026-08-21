# Changelog

## [1.3]

### Security
- Anti-IDOR hardening: Enforced strict bank account ownership check (`fk_account`) on bank lines in `QontoUpdater` to prevent unauthorized cross-account linking.
- Entity segregation: Added multi-entity permissions verification (`isAccountAllowed()`) across all AJAX endpoints, including the new Various Payment creation modal.
- Business integrity guards: Added automated backend checks preventing reconciliation of already paid documents (Customer/Supplier Invoices, VAT, Social Taxes, Salaries, and Expense Reports).
- Sanitization & XSS prevention: Enforced rigorous parameter cleaning and systematic escaping via `dol_escape_htmltag()` on dynamic attributes, tooltips, and customizable CSS properties.
- Session & CSRF resilience: Standardized JSON 401 error payloads (`SESSION_EXPIRED`) with shutdown interceptors and CSRF token validations on all AJAX actions.

### Added
- Various Payments creation: Integrated on-the-fly Various Payment (Opération Diverse) creation modal with accounting account selector (`FormAccounting`).
- Transaction unlinking: Added one-click action and confirmation modal to unlink previously reconciled Qonto transactions and Dolibarr bank entries.
- GUI configuration page (`admin/gui.php`): Added dedicated settings tab with customizable badge colors for light and dark themes (`QONTOSYNC_BADGE_COLOR`, `QONTOSYNC_BADGE_COLOR_DARK`).
- Batch matching anti-duplicate guard (`js/batch_matching.js`): Prevents selecting the same pending document across multiple transaction dropdowns simultaneously.
- Direct navigation: Added direct clickable links to Dolibarr bank transaction entries (`line.php`) on linked status badges.
- New drop down menu to access to the actions available on a Qonto transaction line.
- Button to create a new various payment from the reconciliation interface.
- Confirmation modal for new various payment creation.
- Option to delete the bank reconciliation with a single button.
- Confirmation modal for deletion of a bank reconciliation.
- Add configurable background color for the reference badge.

### Changed
- Architectural refactoring: Converted AJAX endpoints (`get_transactions.php`, `get_advanced.php`, `get_statements.php`, etc.) to structured JSON responses paired with dedicated client-side JavaScript renderers (`reconciliation.js`, `statements.js`).
- Modernized module checks: Replaced legacy `$conf->module->enabled` checks with the standard Dolibarr `isModEnabled()` helper across the entire codebase.
- Template reorganization: Consolidated all modal views and table templates into the root `/tpl` directory.
- Performance optimization: Implemented on-demand lazy loading for Dolibarr core accounting and payment classes.
- In multi-currency mode, the module no longer pushes the exchange rate variance entry to the general journal; the data is being prepared for transfer to the accounting module.

### Fixed
- Date handling on Various Payments: Fixed and aligned operation date (`datep`) and value date (`datev`) using Qonto's `emitted_at` and `settled_at` timestamps.
- Bank line unlinking NULL persistence: Used direct SQL updates to set `qonto_id = NULL` on bank extrafields, bypassing ORM empty string conversions that broke database UNIQUE constraints.
- Balance calculation precision: Updated monthly bank balance queries (`getMonthlyDolibarrBalances`) to use value dates (`datev`) and exact end-of-day timestamps (`23:59:59`).
- Supplier invoice matching: Fixed an issue where the supplier third-party name duplicated the last customer document data.
- Improved support for multi-currency allocations.
- Fixed ChangeLog location (filename missmatch with dolibarr).

## [1.2]

### Security
- Hardening (Anti-IDOR): Added strict Multi-Company / Entity verification on all documents fetched during reconciliation (Customer Invoices, Supplier Invoices, VAT, Social Taxes, Salaries, Expense Reports) to prevent cross-entity interactions.
- Log sanitization: Truncated raw Qonto API error responses in Dolibarr syslog to prevent potential sensitive data leaks.

### Fixed
- Browser compatibility: Fixed an AJAX serialization bug specific to Firefox preventing the validation of bank reconciliations from the dropdown lists.
- Subdirectory installations: Fixed a 404 error when opening the advanced matching modal on Dolibarr instances installed in a subfolder (forced absolute URL generation).
- Advanced matching: Fixed a bug preventing the reconciliation of negative amounts (credit notes, expenses, salaries, etc.) in the advanced modal.

## [1.1]

### Added
- Bank statements management and synchronization interface (`statements.php`).
- Automatic monthly comparison between Dolibarr and Qonto balances with delta highlighting.
- Automatic import of Qonto PDF bank statements directly into Dolibarr's EDM (GED).
- Multicurrency support for customer and supplier invoices reconciliation.
- Smart matching with Qonto transaction local amounts (`local_amount` and `local_currency`).
- Automatic handling of currency exchange rate differences (generation of dedicated loss/gain bookkeeping lines).
  (Note: The accounting entry for the exchange rate difference is not yet active)
- Live search bar in the advanced matching modal to quickly filter pending documents by reference or third-party name.

### Changed
- Navigation menu enriched and structured into a dedicated group ("Conciliation" and "Account Statements").
- Payment modes configuration now uses the native Dolibarr function (`select_types_paiements`) for better integration.
- Improved SQL hydration reliability in `qontoupdater.class.php`.

### Security
- Anti-IDOR protection (Multi-Company / Entity segregation): implemented `isAccountAllowed()` strictly on all requests (AJAX and modals) to prevent interacting with other entities' bank accounts.
- Sensitive credentials masking: "Qonto API Key" field changed to a password input (`type="password"`) in the setup page.
- Incompatible links locking: backend blocking to prevent linking multiple documents for operations requiring a strict 1-to-1 ratio (VAT, Social taxes, Salaries, Expense reports).
- XSS (Cross-Site Scripting) fixes: systematic data sanitization using native `dol_escape_htmltag()` in views and modals.
- Enhanced string sanitization for statement filename generation (`dol_sanitizeFileName()`).

## [1.0.0]
- Initial release.