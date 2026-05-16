# qontosync for Dolibarr

Manual bank reconciliation module between Qonto API and Dolibarr, operating without any third-party data storage.

## Features

- **Selection Interface**: Choose the period (Month/Year) and the Dolibarr bank account via native dropdown lists.
- **On-the-fly Retrieval**: Real-time Qonto API calls to fetch transactions for the selected account (IBAN) and period.
- **Reconciliation Table**:
    - Display of Qonto data (Date, Label with reference badge, Transaction ID).
    - Dynamic suggestion in a dropdown list of Dolibarr bank entries with the **exact matching amount**.
- **Manual Linking**: Button to link a Qonto transaction to an existing Dolibarr entry by injecting the Qonto ID into an `extrafield`.
- **Error Handling**: Comprehensive processing of API error returns and cases with no matches.

## Technical Specifications

- **Zero Additional SQL Tables**: Uses only native tables and one `extrafield` on the `llx_bank` table.
- **Secure Storage**: API credentials (Secret Key, Login/Slug) stored in Dolibarr configuration constants (`llx_const`).
- **MVC Architecture / Clean Code**:
    - **Orchestrator**: Main page at the module root managing the flow.
    - **Business Logic**: Classes located in `/class/`.
    - **Display**: Rendering functions centralized in `/lib/qontosync.lib.php`.
- **Visual Integration**: Exclusive use of native Dolibarr CSS to ensure compatibility with all themes.

## Installation

1. Copy the `qontosync` folder into your `custom` directory.
2. Enable the module in **Configuration > Modules**.
3. Enter your API Key and Login/Slug in the module setup.
4. The module automatically creates the `qonto_id` extrafield on the Bank Entry object (`bank`) upon activation.

## Usage

1. Go to the **Bank > qontosync** menu.
2. Select the Month, Year, and the relevant Bank Account.
3. Click the search button to query the Qonto API.
4. For each Qonto line, select the corresponding Dolibarr entry from the list and click "Link".

---
Developed for Dolibarr.
