# QontoSync for Dolibarr

Manual bank reconciliation module between Qonto API and Dolibarr, operating without any third-party data storage.

## Features

- **Selection Interface**: Choose the period (Month/Year) and the Dolibarr bank account via native dropdown lists. Includes a friendly welcoming guide on load and an explicit animated progress loader ("Searching for transactions...") during background operations.
- **On-the-fly Retrieval**: Real-time Qonto API calls to fetch transactions for the selected account (IBAN) and period.
- **Bank Statements Management**: Automatic monthly balance comparison between Dolibarr and Qonto, and seamless import of official Qonto PDF statements directly into Dolibarr's EDM (GED).
- **Multicurrency Support**: Reconcile customer and supplier invoices issued in foreign currencies, including automatic generation of bookkeeping lines for exchange rate differences (loss/gain).
- **Bank Statement Format Customization**: Total freedom in defining your bank statement reference codes (`num_releve`) inside the settings using native PHP `date()` tokens enclosed in braces (e.g., `QONTO-{Y}-{m}` or `STATEMENT-{Y}`).
- **Reconciliation Table**:
    - Display of Qonto data (Date, Label with reference badge, Transaction ID).
    - Dynamic suggestion in a dropdown list of Dolibarr bank entries, invoices, VAT declarations, social contributions, salaries, and expense reports with the **exact matching amount**.
    - Direct access via clickable link to the Dolibarr bank transaction entry (`line.php`) on linked status badges.
- **Various Payments Creation**: On-the-fly Various Payment (Opération Diverse) creation modal directly from the Qonto transaction line with accounting account selector (`FormAccounting`) and confirmation modal.
- **Reconciliation Unlinking**: One-click action and confirmation dialog to unlink previously reconciled Qonto transactions and Dolibarr bank entries.
- **Advanced Matching**: Modal interface to manually link a transaction to multiple documents (customer/supplier invoices, VAT, social contributions, salaries, expense reports) of the same third-party and handle partial payments. Features a **live search bar** to quickly filter documents and an elegant desktop-centered floating action button for quick, frictionless validation.
- **Batch Processing (FIFO)**: Semi-automatic amount allocation via simultaneous selection of multiple documents. Reconciliations are processed in selection order (FIFO) on operations of the same sign (ex: all expenses, then all revenues). Includes an anti-duplicate guard preventing the simultaneous selection of the same target document across multiple rows.
- **Payment Modes Mapping**: Map Qonto transaction types (Transfer, Card, Direct Debit, Check) directly to your native Dolibarr payment modes in the module setup.
- **Manual Linking**: Button to link a Qonto transaction to an existing Dolibarr entry by injecting the Qonto ID into an `extrafield`.
- **GUI Customization**: Dedicated visual configuration page allowing custom badge colors for both light and dark themes.
- **Sandbox Support**: Test your integrations safely using Qonto's Sandbox mode and Staging Tokens.

## Technical & Security Specifications

- **Zero Additional SQL Tables**: Uses only native tables and one `extrafield` on the `llx_bank` table.
- **Secure Storage**: API credentials (Secret Key, Login/Slug) stored securely in Dolibarr configuration constants (`llx_const`) with masked inputs.
- **Data Integrity (Rollback)**: Complete database transaction management. If a single step fails during the linking process, nothing is saved, preventing partial or corrupted entries.
- **End-to-End Multi-Entity & Strict Anti-IDOR Security**: Complete, tamper-proof data segregation between Dolibarr entities (Multi-Company). Access controls (`isAccountAllowed()`) are systematically enforced at both application and database layers across all AJAX endpoints down to the individual bank record (`AccountLine::fk_account`), strictly preventing cross-account reconciliations and unauthorized payment creations.
- **Modern Decoupled Architecture & Lazy Loading Performance**: Clean separation between structured JSON backend APIs and high-performance client-side asynchronous renderers. Native Dolibarr compatibility is secured using the standard `isModEnabled()` helper, while on-demand lazy loading of heavy accounting and payment classes dramatically reduces memory consumption and optimizes server response times.
- **Business Logic Locking & Anti-Duplicate**: Backend strict locking preventing the linking of multiple documents for operations requiring a 1-to-1 ratio (e.g., VAT, Social Taxes, Salaries), and strict blocking of already paid documents.
- **Anti-CSRF & Session Resilience**: Full synchronization against multi-tab token invalidations or session expirations (HTTP 401/403) with seamless, silent background page auto-reloads.
- **Advanced Sanitization & XSS Protection**: Systematic sanitization of user-defined formatting strings (`dol_sanitizeFileName()`) and API data (`dol_escape_htmltag()`) to protect against Data Truncation and Cross-Site Scripting.
- **Theme-Adaptive Accessibility**: Customized theme adjustments securing perfect color contrast for reference and default status badges under both light and native Dolibarr dark modes.
- **Visual Integration**: Exclusive use of native Dolibarr CSS to ensure compatibility with all themes.
- **Strict Permissions Management**: Granular separation of rights between read-only access and the ability to link/create payments (Write access).

## Prerequisites

The following native Dolibarr module must be enabled:
- **Bank & Cash** (`modBanque`)

The following modules are optional (the module checks if they are enabled before proposing their documents):
- **Invoices** (`modFacture`)
- **Supplier Invoices** (`modFournisseur`)
- **Taxes** (`modTax`)
- **Salaries** (`modSalaries`)
- **Expense Reports** (`modExpenseReport`)

## Useful Links & Resources

To use this module, you need Qonto API credentials. You can manage them on the official Qonto platforms:
- **Qonto Developer Portal**: [https://developers.qonto.com/](https://developers.qonto.com/) (To create your API Keys and Login/Slug).
- **API Documentation**: [https://api-doc.qonto.com/](https://api-doc.qonto.com/) (Technical details on endpoints and Sandbox mode).
- **Qonto Help Center**: For any questions regarding your business account permissions.

## Installation & Usage

1. Copy the `qontosync` folder into your Dolibarr `custom` directory.
2. Enable the module in **Home > Setup > Modules/Applications**.
3. Go to the module setup to enter your API Key and configure mappings.
4. Go to **Bank > QontoSync** to start reconciling your transactions.

---
Developed for Dolibarr.