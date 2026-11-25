# QontoSync for Dolibarr

Manual bank reconciliation module between Qonto and Dolibarr, operating in real time without storing banking data on third-party servers.

## Functional Overview

### Real-Time Transaction Retrieval
- Select a Dolibarr bank account and a period (month/year) to fetch Qonto transactions directly via the Qonto API.
- Test configurations safely using Qonto's Sandbox mode.

### Reconciliation & Matching
- **Automatic Suggestions**: Proposes matching Dolibarr bank entries, customer/supplier invoices, VAT declarations, social contributions, salaries, and expense reports based on exact amounts.
- **Advanced & Partial Matching**: Link a transaction to multiple documents for the same third party, handle partial payments, and filter documents with live search.
- **Batch Reconciliation**: Select and reconcile multiple documents at once in selection order (FIFO).
- **Manual Linking & Unlinking**: Link a Qonto transaction to an existing Dolibarr bank entry or unlink previously reconciled operations in one click.
- **Various Payments**: Create Various Payments (Opérations Diverses) directly from a Qonto transaction line.

### Bank Statements & Multicurrency
- **Statement Control & Archiving**: Compare monthly balances between Dolibarr and Qonto, and import official Qonto PDF bank statements directly into Dolibarr's document management (EDM).
- **Multicurrency Support**: Reconcile foreign-currency invoices with automatic handling of exchange rate gains and losses.

### Configuration & Customization
- **Payment Mode Mapping**: Map Qonto transaction types (transfer, card, direct debit, check) to your Dolibarr payment modes.
- **Custom Statement References**: Define your own bank statement reference format using date patterns (e.g. `QONTO-{Y}-{m}`).
- **Visual Preferences**: Customize badge colors for light and dark themes.

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