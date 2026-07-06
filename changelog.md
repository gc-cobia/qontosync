# Changelog

## [1.2]

### Changed
- API key is now saved encrypted

## [1.1]

### Added
- Bank statements management and synchronization interface (`statements.php`).
- Automatic monthly comparison between Dolibarr and Qonto balances with delta highlighting.
- Automatic import of Qonto PDF bank statements directly into Dolibarr's EDM (GED).
- Multicurrency support for customer and supplier invoices reconciliation.
- Smart matching with Qonto transaction local amounts (`local_amount` and `local_currency`).
- Automatic handling of currency exchange rate differences (generation of dedicated loss/gain bookkeeping lines).
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