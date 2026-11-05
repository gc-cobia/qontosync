<?php
/*
 * QontoSync dolibarr module 
 * @file       ajax/reconciliation.ctrl.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */
 
class ReconciliationController {
    
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Multi-company security segregation (Bank account entity verification)
     * @param int $bank_rowid Bank account ID
     * @return bool True if the account belongs to the current company, false otherwise
     */
    private function checkAccountPermission($bank_rowid) {
        if (!class_exists('QontoRepository')) dol_include_once('/qontosync/class/qontorepository.class.php');
        $qontoRepo = new QontoRepository($this->db);

       return $qontoRepo->isAccountAllowed($bank_rowid);
    }
    
    /**
     * Get transactions for a specific month and year
     * @param array $data Array containing month, year, and bank_rowid
     * @return array Array containing http_code and transactions or error code
     */
    public function getTransactions(array $data) {
        global $conf, $langs;

        $month = (int) ($data['month'] ?? 0);
        $year = (int) ($data['year'] ?? 0);
        $bank_rowid = (int) ($data['bank_rowid'] ?? 0);

        if ($bank_rowid <= 0) {
            return ['http_code' => 400, 'error' => 'NO_BANK_SELECTED'];
        }

        if (!$this->checkAccountPermission($bank_rowid)) {
            return ['http_code' => 403, 'error' => 'PERMISSION_DENIED'];
        }

        if ($month <= 0 || $month > 12 || $year <= 0 || $year > (int) date('Y')) {
            return ['http_code' => 400, 'error' => 'INVALID_PARAMETERS'];
        }

        if (!class_exists('Account')) require_once DOL_DOCUMENT_ROOT .'/compta/bank/class/account.class.php';
        $account = new Account($this->db);

        if ($account->fetch($bank_rowid) <= 0) {
            return ['http_code' => 400, 'error' => 'BANK_ACCOUNT_NOT_FOUND'];
        }

        $dateStart = sprintf("%04d-%02d-01", $year, $month);
        $dateEnd = date("Y-m-t", strtotime($dateStart));

        if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php');
        $api = new QontoApi();

        $transactions = $api->getTransactions($account->iban, $dateStart, $dateEnd);

        if ($transactions === false) {
            return ['http_code' => 500, 'error' => 'ERROR_FETCHING_TRANSACTIONS'];
        }

        if (empty($transactions)) {
            return ['http_code' => 404, 'error' => 'NO_TRANSACTIONS_FOUND'];
        }

        // Load documents fetching Qonto transactions
        if (!class_exists('QontoMatching')) dol_include_once('/qontosync/class/qontomatching.class.php');
        $qontoMatching = new QontoMatching($this->db);

        $transactions = $qontoMatching->fetchTransactions($transactions, $bank_rowid);

        $formatted_transactions = [];

        foreach ($transactions as $t) {
            $q_id = $t['id'];
            $settled_time = !empty($t['settled_at']) ? strtotime($t['settled_at']) : null;
            $date_formatted = $settled_time ? dol_print_date($settled_time, 'day') : '';

            $trx_item = [
                'id' => $q_id,
                'label' => $t['label'],
                'reference' => !empty($t['reference']) ? $t['reference'] : '',
                'settled_at' => $t['settled_at'],
                'date_formatted' => $date_formatted,
                'amount' => (float) $t['amount'],
                'amount_formatted' => price($t['amount']),
                'currency' => !empty($t['currency']) ? $t['currency'] : $conf->currency,
                'is_negative' => ($t['amount'] < 0),
                'trx_status' => !empty($t['trx_status']) ? $t['trx_status'] : 'unlinked',
                'linked_rowid' => !empty($t['linked_rowid']) ? (int) $t['linked_rowid'] : 0,
                'linked_url' => !empty($t['linked_rowid']) ? (DOL_URL_ROOT . '/compta/bank/line.php?rowid=' . (int) $t['linked_rowid']) : '',
                'has_matches' => false,
                'matches' => [
                    'bank' => [],
                    'cinv' => [],
                    'sinv' => [],
                    'vat' => [],
                    'social' => [],
                    'salary' => [],
                    'expense' => []
                ]
            ];

            if ($trx_item['trx_status'] === 'unlinked') {
                // Build matching options
                // 1. Bank entries
                if (!empty($t['trx']) && is_array($t['trx'])) {
                    foreach ($t['trx'] as $de) {
                        $trans_label = $de->label;
                        if (preg_match('/^\(([^)]+)\)(.*)$/i', $trans_label, $reg)) {
                            $trans_label = '(' . $langs->transnoentities($reg[1]) . ')' . $reg[2];
                        } else {
                            $trans_label = $langs->transnoentities($trans_label);
                        }
                        $trx_item['matches']['bank'][] = [
                            'type' => 'bank',
                            'doc_id' => (int) $de->rowid,
                            'label' => dol_print_date($this->db->jdate($de->datev), 'day') . ' - ' . $trans_label . ' (' . price($de->amount) . ')'
                        ];
                    }
                }

                // 2. Customer invoices
                if (!empty($t['c_invoices']) && is_array($t['c_invoices'])) {
                    foreach ($t['c_invoices'] as $cinv) {
                        $amt_display = price($cinv->amount) . ' ' . $conf->currency;
                        if (!empty($cinv->mc_code) && $cinv->mc_code != $conf->currency) {
                            $amt_display = price($cinv->mc_amount) . ' ' . $cinv->mc_code . ' (' . $amt_display . ')';
                        }
                        $trx_item['matches']['cinv'][] = [
                            'type' => 'cinv',
                            'doc_id' => (int) $cinv->rowid,
                            'label' => dol_print_date($cinv->datev, 'day') . ' - ' . $cinv->label . ' (' . $amt_display . ') - ' . $cinv->thirdparty
                        ];
                    }
                }

                // 3. Supplier invoices
                if (!empty($t['s_invoices']) && is_array($t['s_invoices'])) {
                    foreach ($t['s_invoices'] as $sinv) {
                        $amt_display = price($sinv->amount) . ' ' . $conf->currency;
                        if (!empty($sinv->mc_code) && $sinv->mc_code != $conf->currency) {
                            $amt_display = price($sinv->mc_amount) . ' ' . $sinv->mc_code . ' (' . $amt_display . ')';
                        }
                        $ref_sup = !empty($sinv->ref_supplier) ? ' (' . $sinv->ref_supplier . ')' : '';
                        $trx_item['matches']['sinv'][] = [
                            'type' => 'sinv',
                            'doc_id' => (int) $sinv->rowid,
                            'label' => dol_print_date($sinv->datev, 'day') . ' - ' . $sinv->label . ' (' . $amt_display . ') - ' . $sinv->thirdparty . $ref_sup
                        ];
                    }
                }

                // 4. VAT
                if (!empty($t['vat']) && is_array($t['vat'])) {
                    foreach ($t['vat'] as $vat) {
                        $trx_item['matches']['vat'][] = [
                            'type' => 'vat',
                            'doc_id' => (int) $vat->rowid,
                            'label' => dol_print_date($vat->datev, 'day') . ' - ' . $vat->label . ' (' . price($vat->amount) . ')'
                        ];
                    }
                }

                // 5. Social contributions
                if (!empty($t['social']) && is_array($t['social'])) {
                    foreach ($t['social'] as $soc) {
                        $trx_item['matches']['social'][] = [
                            'type' => 'social',
                            'doc_id' => (int) $soc->rowid,
                            'label' => dol_print_date($soc->datev, 'day') . ' - ' . $soc->label . ' (' . price($soc->amount) . ')'
                        ];
                    }
                }

                // 6. Salaries
                if (!empty($t['salary']) && is_array($t['salary'])) {
                    foreach ($t['salary'] as $sal) {
                        $trx_item['matches']['salary'][] = [
                            'type' => 'salary',
                            'doc_id' => (int) $sal->rowid,
                            'label' => $sal->label . ' (' . price($sal->amount) . ')'
                        ];
                    }
                }

                // 7. Expense reports
                if (!empty($t['expense']) && is_array($t['expense'])) {
                    foreach ($t['expense'] as $exp) {
                        $trx_item['matches']['expense'][] = [
                            'type' => 'expense',
                            'doc_id' => (int) $exp->rowid,
                            'label' => dol_print_date($exp->datev, 'day') . ' - ' . $exp->label . ' (' . price($exp->amount) . ')'
                        ];
                    }
                }

                $trx_item['has_matches'] = (
                    !empty($trx_item['matches']['bank']) ||
                    !empty($trx_item['matches']['cinv']) ||
                    !empty($trx_item['matches']['sinv']) ||
                    !empty($trx_item['matches']['vat']) ||
                    !empty($trx_item['matches']['social']) ||
                    !empty($trx_item['matches']['salary']) ||
                    !empty($trx_item['matches']['expense'])
                );
            }

            $formatted_transactions[] = $trx_item;
        }

        return [
            'http_code' => 200,
            'bank_rowid' => (int) $bank_rowid,
            'hide_linked' => (bool) getDolGlobalString('QONTOSYNC_HIDE_LINKED'),
            'transactions' => $formatted_transactions
        ];
    }

    /**
     * Link transactions in batch mode.
     * 
     * @param array $data Array containing bank_rowid and target_val (array of target documents for each Qonto transaction)
     * @return array Associative array with http_code and result counts
     */
    public function batchLink(array $data) {
        $bank_rowid = (int) ($data['bank_rowid'] ?? 0);
        $matches = $data['matches'] ?? [];

        if ($bank_rowid <= 0) {
            return ['http_code' => 400, 'error' => 'NO_BANK_SELECTED'];
        }

        if (!$this->checkAccountPermission($bank_rowid)) {
            return ['http_code' => 403, 'error' => 'PERMISSION_DENIED'];
        }

        if (empty($matches) || !is_array($matches)) {
            return ['http_code' => 400, 'error' => 'INVALID_PARAMETERS'];
        }

        if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php');
        if (!class_exists('QontoUpdater')) dol_include_once('/qontosync/class/qontoupdater.class.php');
        $api = new QontoApi();
        $qontoUpdater = new QontoUpdater($this->db);
        
        $success_count = 0;
        $error_count = 0;

        foreach ($matches as $qonto_id => $match) {
            $qonto_id = sanitizeVal($qonto_id, 'alphanohtml');
            $type = sanitizeVal($match['type'], 'alpha');
            $doc_id = (int) $match['doc_id'];

            if (empty($match) || !is_array($match)) continue;

            $selected_trx = $api->getTransaction($qonto_id);
            if ($selected_trx) {
                $amount = (float) $selected_trx['amount'];
                
                // Calculate the real amount with its sign
                if (isset($selected_trx['side']) && $selected_trx['side'] == 'debit') $amount = -$amount;
                
                // Adapt sign to the document type
                if (in_array($type, ['sinv', 'vat', 'social', 'salary', 'expense'])) $amount = -$amount;
                
                $amounts_array = [$doc_id => $amount];

                $res = $qontoUpdater->update($type, $amounts_array, $selected_trx, $bank_rowid);
                if ($res > 0) $success_count++;
                else $error_count++;
                
            } else {
                $error_count++;
            }
        }

        return [
            'http_code' => 200,
            'bank_rowid' => $bank_rowid,
            'success_count' => $success_count,
            'error_count' => $error_count
        ];
    }

    /**
     * Unlink a transaction from a bank line.
     * 
     * @param array $data Array containing bank_rowid and bank_line_id
     * @return array Associative array with http_code for result
     */
    public function unlinkTransaction(array $data) {
        $bank_rowid = (int) ($data['bank_rowid'] ?? 0);
        $bank_line_id = (int) ($data['bank_line_id'] ?? 0);

        if ($bank_rowid <= 0) {
            return ['http_code' => 400, 'error' => 'NO_BANK_SELECTED'];
        }

        if (!$this->checkAccountPermission($bank_rowid)) {
            return ['http_code' => 403, 'error' => 'PERMISSION_DENIED'];
        }

        if ($bank_line_id <= 0) {
            return ['http_code' => 400, 'error' => 'INVALID_PARAMETERS'];
        }

        if (!class_exists('QontoUpdater')) dol_include_once('/qontosync/class/qontoupdater.class.php');
        $updater = new QontoUpdater($this->db);

        $res = $updater->unlinkTransaction($bank_line_id);
        if ($res <= 0) {
            return ['http_code' => 500, 'error' => 'ERROR_UNLINKING_TRANSACTION'];
        }

        return [
            'http_code' => 200,
            'bank_rowid' => $bank_rowid
        ];
    }

    /**
     * Get advanced information about a transaction.
     * 
     * @param array $data Array containing bank_rowid and qonto_id
     * @return array Associative array with http_code and linked data array for result
     */
    public function getAdvancedInfo(array $data) {
        global $conf, $langs;

        $bank_rowid = (int) ($data['bank_rowid'] ?? 0);
        $qonto_id = ($data['qonto_id'] ?? '');

        if ($bank_rowid <= 0) {
            return ['http_code' => 400, 'error' => 'NO_BANK_SELECTED'];
        }

        if (!$this->checkAccountPermission($bank_rowid)) {
            return ['http_code' => 403, 'error' => 'PERMISSION_DENIED'];
        }

        if (empty($qonto_id)) {
            return ['http_code' => 400, 'error' => 'INVALID_PARAMETERS'];
        }

        if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php');
        $api = new QontoApi();

        $raw_trx = $api->getTransaction($qonto_id);
        if (empty($raw_trx)) {
            return ['http_code' => 404, 'error' => 'TRANSACTION_NOT_FOUND'];
        }

        // Calculate the real Qonto amount with its sign
        $processed_trx = $raw_trx;
        $processed_trx['amount'] = (float) $raw_trx['amount'];
        if (isset($processed_trx['side']) && $processed_trx['side'] == 'debit') {
            $processed_trx['amount'] = -$processed_trx['amount'];
        }

        if (!class_exists('QontoRepository')) dol_include_once('/qontosync/class/qontorepository.class.php');
        $qontoRepo = new QontoRepository($this->db);

        if (!class_exists('QontoMatching')) dol_include_once('/qontosync/class/qontomatching.class.php');
        $qontoMatching = new QontoMatching($this->db);

        // Verify if the transaction is linked with a bank entry
        $linked = $qontoMatching->isLinked($qonto_id, $bank_rowid, false);

        // Payment type label determination
        $op_type = isset($processed_trx['operation_type']) ? $processed_trx['operation_type'] : '';
        $mop = $qontoMatching->getPaymentModeId($op_type, isset($processed_trx['side']) ? $processed_trx['side'] : '');
        $payment_label = '';
        if (!empty($mop)) {
            $payment_code = $langs->getLabelFromKey($this->db, $mop, 'c_paiement', 'id', 'code');
            $payment_label = $langs->transnoentities("PaymentTypeShort" . $payment_code);
            if ($payment_label == "PaymentTypeShort" . $payment_code) {
                $payment_label = $langs->getLabelFromKey($this->db, $mop, 'c_paiement', 'id', 'libelle');
            }
        }

        $linked_details = [];
        $pending_documents = [];

        if ($linked > 0) {
            // If the transaction is already linked, retrieve the details (Payments -> Invoices)
            $raw_linked_details = $qontoRepo->getLinkedDetails($linked);
            foreach ($raw_linked_details as $detail) {
                $doc_type_label = '';
                if ($detail->doc_type == 'cinv') $doc_type_label = $langs->transnoentities("CustomersInvoices");
                elseif ($detail->doc_type == 'sinv') $doc_type_label = $langs->transnoentities("SupplierInvoices");
                elseif ($detail->doc_type == 'vat') $doc_type_label = $langs->transnoentities("VAT");
                elseif ($detail->doc_type == 'social') $doc_type_label = $langs->transnoentities("SocialContributions");
                elseif ($detail->doc_type == 'expense') $doc_type_label = $langs->transnoentities("ExpenseReports");
                elseif ($detail->doc_type == 'various') $doc_type_label = $langs->transnoentities("VariousPayment");
                elseif ($detail->doc_type == 'salary') $doc_type_label = $langs->transnoentities("SalaryPayment");

                $linked_details[] = [
                    'doc_type' => $detail->doc_type,
                    'doc_type_label' => $doc_type_label,
                    'payment_id' => $detail->payment_id,
                    'ref' => $detail->ref,
                    'ref_supplier' => !empty($detail->ref_supplier) ? $detail->ref_supplier : '',
                    'amount' => (float) $detail->amount,
                    'amount_formatted' => price($detail->amount)
                ];
            }

        } elseif ($linked === 0) {
            // Get unpaid documents
            $filtered_documents = $qontoRepo->getUnpaidDocuments('all');

            // Customer Invoices
            if (!empty($filtered_documents['c_invoices'])) {
                foreach ($filtered_documents['c_invoices'] as $d) {
                    $remain_for_js = (float) $d->amount;
                    $amt_display = price($d->amount) . ' ' . $conf->currency;

                    if (!empty($d->mc_code) && $d->mc_code != $conf->currency) {
                        if (isset($processed_trx['currency']) && $d->mc_code == $processed_trx['currency']) {
                            $remain_for_js = (float) $d->mc_amount;
                        }
                        $amt_display = price($d->mc_amount) . ' ' . $d->mc_code . '<br><span class="opacitymedium small">' . price($d->amount) . ' ' . $conf->currency . '</span>';
                    }

                    $pending_documents[] = [
                        'key' => 'cinv_' . $d->rowid,
                        'doctype' => 'cinv',
                        'doctype_label' => $langs->transnoentities("CustomersInvoices"),
                        'is_credit_note' => ((int) $d->inv_type == 2),
                        'credit_note_label' => $langs->transnoentities("CreditNote"),
                        'rowid' => (int) $d->rowid,
                        'date_formatted' => dol_print_date($d->datev, 'day'),
                        'label' => $d->label,
                        'ref_supplier' => '',
                        'thirdparty' => $d->thirdparty,
                        'amount' => (float) $d->amount,
                        'remain_for_js' => $remain_for_js,
                        'amount_formatted' => $amt_display
                    ];
                }
            }

            // Supplier Invoices
            if (!empty($filtered_documents['s_invoices'])) {
                foreach ($filtered_documents['s_invoices'] as $d) {
                    $remain_for_js = (float) $d->amount;
                    $amt_display = price($d->amount) . ' ' . $conf->currency;

                    if (!empty($d->mc_code) && $d->mc_code != $conf->currency) {
                        if (isset($processed_trx['currency']) && $d->mc_code == $processed_trx['currency']) {
                            $remain_for_js = (float) $d->mc_amount;
                        }
                        $amt_display = price($d->mc_amount) . ' ' . $d->mc_code . '<br><span class="opacitymedium small">' . price($d->amount) . ' ' . $conf->currency . '</span>';
                    }

                    $pending_documents[] = [
                        'key' => 'sinv_' . $d->rowid,
                        'doctype' => 'sinv',
                        'doctype_label' => $langs->transnoentities("SupplierInvoices"),
                        'is_credit_note' => ((int) $d->inv_type == 2),
                        'credit_note_label' => $langs->transnoentities("CreditNote"),
                        'rowid' => (int) $d->rowid,
                        'date_formatted' => dol_print_date($d->datev, 'day'),
                        'label' => $d->label,
                        'ref_supplier' => !empty($d->ref_supplier) ? $d->ref_supplier : '',
                        'thirdparty' => $d->thirdparty,
                        'amount' => (float) $d->amount,
                        'remain_for_js' => $remain_for_js,
                        'amount_formatted' => $amt_display
                    ];
                }
            }

            // VAT
            if (!empty($filtered_documents['vat'])) {
                foreach ($filtered_documents['vat'] as $d) {
                    $pending_documents[] = [
                        'key' => 'vat_' . $d->rowid,
                        'doctype' => 'vat',
                        'doctype_label' => $langs->transnoentities("VAT"),
                        'is_credit_note' => false,
                        'credit_note_label' => '',
                        'rowid' => (int) $d->rowid,
                        'date_formatted' => dol_print_date($d->datev, 'day'),
                        'label' => $d->label,
                        'ref_supplier' => '',
                        'thirdparty' => 'VAT',
                        'amount' => (float) $d->amount,
                        'remain_for_js' => (float) $d->amount,
                        'amount_formatted' => price($d->amount)
                    ];
                }
            }

            // Social Charges
            if (!empty($filtered_documents['social'])) {
                foreach ($filtered_documents['social'] as $d) {
                    $pending_documents[] = [
                        'key' => 'social_' . $d->rowid,
                        'doctype' => 'social',
                        'doctype_label' => $langs->transnoentities("SocialContributions"),
                        'is_credit_note' => false,
                        'credit_note_label' => '',
                        'rowid' => (int) $d->rowid,
                        'date_formatted' => dol_print_date($d->datev, 'day'),
                        'label' => $d->label,
                        'ref_supplier' => '',
                        'thirdparty' => 'SOCIAL',
                        'amount' => (float) $d->amount,
                        'remain_for_js' => (float) $d->amount,
                        'amount_formatted' => price($d->amount)
                    ];
                }
            }

            // Salary Payments
            if (!empty($filtered_documents['salary'])) {
                foreach ($filtered_documents['salary'] as $d) {
                    $pending_documents[] = [
                        'key' => 'salary_' . $d->rowid,
                        'doctype' => 'salary',
                        'doctype_label' => $langs->transnoentities("SalaryPayment"),
                        'is_credit_note' => false,
                        'credit_note_label' => '',
                        'rowid' => (int) $d->rowid,
                        'date_formatted' => dol_print_date($d->datev, 'day'),
                        'label' => $d->label,
                        'ref_supplier' => '',
                        'thirdparty' => 'SALARY',
                        'amount' => (float) $d->amount,
                        'remain_for_js' => (float)$d->amount,
                        'amount_formatted' => price($d->amount)
                    ];
                }
            }

            // Expense Reports
            if (!empty($filtered_documents['expense'])) {
                foreach ($filtered_documents['expense'] as $d) {
                    $pending_documents[] = [
                        'key' => 'expense_' . $d->rowid,
                        'doctype' => 'expense',
                        'doctype_label' => $langs->transnoentities("ExpenseReports"),
                        'is_credit_note' => false,
                        'credit_note_label' => '',
                        'rowid' => (int) $d->rowid,
                        'date_formatted' => dol_print_date($d->datev, 'day'),
                        'label' => $d->label,
                        'ref_supplier' => '',
                        'thirdparty' => 'EXPENSE',
                        'amount' => (float) $d->amount,
                        'remain_for_js' => (float) $d->amount,
                        'amount_formatted' => price($d->amount)
                    ];
                }
            }
        } else {
            return ['http_code' => 405, 'error' => "TOO_MANY_LINKS"];
        }

        $date_formatted = !empty($processed_trx['settled_at']) ? dol_print_date(strtotime($processed_trx['settled_at']), 'day') : '';

        $transaction_data = [
            'id' => $processed_trx['id'],
            'label' => !empty($processed_trx['label']) ? $processed_trx['label'] : '',
            'settled_at' => !empty($processed_trx['settled_at']) ? $processed_trx['settled_at'] : '',
            'date_formatted' => $date_formatted,
            'amount' => (float) $processed_trx['amount'],
            'amount_formatted' => price($processed_trx['amount']),
            'amount_abs' => abs((float) $processed_trx['amount']),
            'currency' => !empty($processed_trx['currency']) ? $processed_trx['currency'] : $conf->currency,
            'type_label' => $payment_label,
            'reference' => !empty($processed_trx['reference']) ? $processed_trx['reference'] : '',
            'side' => !empty($processed_trx['side']) ? $processed_trx['side'] : ''
        ];

        return [
            'http_code' => 200,
            'transaction' => $transaction_data,
            'linked' => $linked,
            'linked_message' => ($linked > 0) ? $langs->transnoentities("TransactionAlreadyLinked", "<strong>" . $langs->transnoentities("AlreadyLinked") . "</strong>", $linked) : ($linked < 0 ? $langs->transnoentities("ErrorMultipleLinks", "<strong>" . $langs->transnoentities("MultipleLinks") . "</strong>") : ''),
            'linked_details' => $linked_details,
            'pending_documents' => $pending_documents
        ];
    }

    /**
     * Batch link transaction to invoices, credit notes, etc.
     * 
     * @param array $data Array containing bank_rowid, qonto_id, and link_docs
     * @return array Associative array with http_code for result or error
     */
    public function batchLinkAdvanced(array $data) {
        $bank_rowid = (int) ($data['bank_rowid'] ?? 0);
        $qonto_id = ($data['qonto_id'] ?? '');
        $link_docs = $data['link_doc'] ?? [];

        if ($bank_rowid <= 0) {
            return ['http_code' => 400, 'error' => 'NO_BANK_SELECTED'];
        }

        if (!$this->checkAccountPermission($bank_rowid)) {
            return ['http_code' => 403, 'error' => 'PERMISSION_DENIED'];
        }

        if (empty($qonto_id) || empty($link_docs) || !is_array($link_docs)) {
            return ['http_code' => 400, 'error' => 'INVALID_PARAMETERS'];
        }

        if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php');
        $api = new QontoApi();

        if (!class_exists('QontoUpdater')) dol_include_once('/qontosync/class/qontoupdater.class.php');
        $qontoUpdater = new QontoUpdater($this->db);
    
        $selected_trx = $api->getTransaction($qonto_id);

        if (empty($selected_trx)) {
            return ['http_code' => 400, 'error' => 'NO_TRANSACTION_FOUND'];
        }

        $amount = abs((float) $selected_trx['amount']);
        if (isset($selected_trx['side']) && $selected_trx['side'] == 'debit') {
            $amount = -$amount;
        }
    
        $amounts_array = [];
        $doc_type = '';
        $total_imputed = 0.0;
    
        foreach ($link_docs as $doc) {
            if (count($parts = explode('_', strip_tags($doc), 2)) != 2) {
                continue;
            }
        
            $current_type = sanitizeVal($parts[0], 'alpha');
            $doc_id = (int) $parts[1];
        
            if (empty($doc_type)) {
                $doc_type = $current_type;
            } elseif ($doc_type !== $current_type) {
                return ['http_code' => 400, 'error' => 'ERROR_MIXED_DOCUMENT_TYPES'];
            }
        
            $imputed_amount = (float) price2num($data['amounts'][$current_type . '_' . $doc_id] ?? 0);
        
            // Retrieves the entered gross amount (Invoice = Positive, Credit Note = Negative)
            if ($imputed_amount != 0) {
                $amounts_array[$doc_id] = $imputed_amount;
                $total_imputed += $imputed_amount;
            }
        }
    
        if (empty($amounts_array)) {
            return ['http_code' => 400, 'error' => 'ERROR_NO_AMOUNT_SPECIFIED'];
        }

        // Adapt sign to the document type
        if (in_array($doc_type, ['sinv', 'vat', 'social', 'salary', 'expense'])) {
            $total_imputed = -$total_imputed;
        }
    
        // The total must EXACTLY match the transaction amount to be compatible with QontoUpdater::update()
        if (price2num($total_imputed, 'MT') != price2num($amount, 'MT')) {
            return ['http_code' => 400, 'error' => 'ERROR_IMPUTED_AMOUNT_EXCEEDS'];
        }

        $res = $qontoUpdater->update($doc_type, $amounts_array, $selected_trx, $bank_rowid);
        if ($res <= 0) {
            return ['http_code' => 500, 'error' => 'FAILED_TO_LINK_TRANSACTION'];
        }

        return [
            'http_code' => 200,
            'bank_rowid' => $bank_rowid,
        ];
    }

    /**
     * Create various payment for a transaction.
     * 
     * @param array $data Array containing bank_rowid, qonto_id, and accountancy_code
     * @return array Associative array with http_code for result or error
     */
    public function createVariousPayment(array $data) {
        $bank_rowid = (int) ($data['bank_rowid'] ?? 0);
        $qonto_id = ($data['qonto_id'] ?? '');
        $accountancy_code = ($data['accountancy_code'] ?? '');

        if ($bank_rowid <= 0) {
            return ['http_code' => 400, 'error' => 'NO_BANK_SELECTED'];
        }

        if (!$this->checkAccountPermission($bank_rowid)) {
            return ['http_code' => 403, 'error' => 'PERMISSION_DENIED'];
        }

        if (empty($qonto_id)) {
            return ['http_code' => 400, 'error' => 'INVALID_PARAMETERS'];
        }

        if (!class_exists('QontoUpdater')) dol_include_once('/qontosync/class/qontoupdater.class.php');
        $qontoUpdater = new QontoUpdater($this->db);

        $res = $qontoUpdater->createVariousPayment($qonto_id, $bank_rowid, $accountancy_code, 'od_attached_file');
        if ($res <= 0) {
            return ['http_code' => 500, 'error' => 'FAILED_TO_LINK_TRANSACTION'];
        }

        return [
            'http_code' => 200,
            'bank_rowid' => $bank_rowid,
        ];
    }
}