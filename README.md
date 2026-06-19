# QontoSync for Dolibarr

Manual bank reconciliation module between Qonto API and Dolibarr, operating without any third-party data storage.

## Features

- **Selection Interface**: Choose the period (Month/Year) and the Dolibarr bank account via native dropdown lists.
- **On-the-fly Retrieval**: Real-time Qonto API calls to fetch transactions for the selected account (IBAN) and period.
- **Reconciliation Table**:
    - Display of Qonto data (Date, Label with reference badge, Transaction ID).
    - Dynamic suggestion in a dropdown list of Dolibarr bank entries, invoices, and VAT declarations with the **exact matching amount**.
- **Advanced Matching**: Modal interface to manually link a transaction to multiple documents (customer/supplier invoices, VAT) of the same third-party and handle partial payments.
- **Batch Processing (FIFO)**: Semi-automatic amount allocation via simultaneous selection of multiple documents. Reconciliations are processed in selection order (FIFO) on operations of the same sign (ex: all expenses, then all revenues). 
Calculates the remaining available balance on-the-fly and automatically allocates the full amount of the selected document (if it has the same sign).
- **Payment Modes Mapping**: Map Qonto transaction types (Transfer, Card, Direct Debit, Check) directly to your Dolibarr payment modes in the module setup.
- **Manual Linking**: Button to link a Qonto transaction to an existing Dolibarr entry by injecting the Qonto ID into an `extrafield`.
- **Sandbox Support**: Test your integrations safely using Qonto's Sandbox mode and Staging Tokens.

## Technical & Security Specifications

- **Zero Additional SQL Tables**: Uses only native tables and one `extrafield` on the `llx_bank` table.
- **Secure Storage**: API credentials (Secret Key, Login/Slug) stored in Dolibarr configuration constants (`llx_const`).
- **Data Integrity (Rollback)**: Complete database transaction management. If a single step fails during the linking process, nothing is saved, preventing partial or corrupted entries.
- **Anti-Duplicate Protection**: Built-in verification preventing a transaction from being processed twice (e.g., in case of page refresh or concurrent submissions).
- **Visual Integration**: Exclusive use of native Dolibarr CSS to ensure compatibility with all themes.
- **Multi-company Compatible**: Native support for Dolibarr's multi-entity environment.
- **High Performance**: Highly optimized SQL queries (manual object hydration) allowing instant processing of thousands of unpaid invoices without server overload.
- **Strict Permissions Management**: Granular separation of rights between read-only access and the ability to link/create payments (Write access).
- **XSS Protection**: Systematic sanitization of API data before HTML rendering.

## Prerequisites

The following native Dolibarr module must be enabled:
- **Bank & Cash** (`modBanque`)

The following modules are optional (the module checks if they are enabled before proposing their documents):
- **Invoices** (`modFacture`)
- **Supplier Invoices** (`modFournisseur`)
- **Taxes** (`modTax`)

## Useful Links & Resources

To use this module, you need Qonto API credentials. You can manage them on the official Qonto platforms:
- **Qonto Developer Portal**: [https://developer.qonto.com/](https://developer.qonto.com/) (To create your API Keys and Login/Slug).
- **API Documentation**: [https://api-doc.qonto.com/](https://api-doc.qonto.com/) (Technical details on endpoints and Sandbox mode).
- **Qonto Help Center**: For any questions regarding your business account permissions.

## Installation & Usage

1. Copy the `qontosync` folder into your Dolibarr `custom` directory.
2. Enable the module in **Home > Setup > Modules/Applications**.
3. Go to the module setup to enter your API Key and configure mappings.
4. Go to **Bank > QontoSync** to start reconciling your transactions.

---
Developed for Dolibarr.