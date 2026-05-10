<?php

/**
 * Classe de service pour gérer la réconciliation entre Qonto et Dolibarr.
 * Centralise les appels API et l'algorithme de recherche de correspondances.
 */
class qontosyncMatcher
{
    /** @var DoliDB Gestionnaire de base de données */
    private $db;
    
    /** @var string Message d'erreur interne */
    public $error = '';

    /**
     * Constructeur
     * @param DoliDB $db Gestionnaire de base de données Dolibarr
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Récupère les transactions depuis Qonto pour un compte bancaire et une période donnée.
     * Cette méthode gère la récupération de l'IBAN, la configuration et les dates.
     * 
     * @param int $bank_id ID du compte bancaire Dolibarr
     * @param int $month   Mois (1-12)
     * @param int $year    Année (YYYY)
     * @return array|int   Liste des transactions ou code d'erreur négatif
     */
    public function getTransactions($bank_id, $month, $year)
    {
        global $conf;

        // Inclusion des classes nécessaires
        require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
        require_once dirname(__FILE__) . '/api.qonto.class.php';

        // 1. Récupération du compte bancaire Dolibarr
        $account = new Account($this->db);
        if ($account->fetch($bank_id) <= 0) {
            $this->error = "AccountNotFound"; // Compte non trouvé
            return -1;
        }

        // 2. Nettoyage de l'IBAN (suppression des espaces) pour l'API Qonto
        $iban = str_replace(' ', '', $account->iban);
        if (empty($iban)) {
            $this->error = "QontoErrorNoIBAN"; // IBAN manquant sur la fiche Dolibarr
            return -2;
        }

        // 3. Vérification de la configuration API
        if (empty($conf->global->QONTOSYNC_API_LOGIN) || empty($conf->global->QONTOSYNC_API_KEY)) {
            $this->error = "qontosyncApiNotConfigured"; // Identifiants API non configurés
            return -3;
        }

        // 4. Préparation des dates au format ISO Qonto (ex: 2026-03-01T00:00:00Z)
        $date_from = date('Y-m-d\T00:00:00\Z', mktime(0, 0, 0, $month, 1, $year));
        $date_to = date('Y-m-d\T23:59:59\Z', mktime(0, 0, 0, $month + 1, 0, $year));

        // 5. Appel à la classe API bas niveau
        $api = new QontoAPI($this->db, $conf->global->QONTOSYNC_API_LOGIN, $conf->global->QONTOSYNC_API_KEY);
        $response = $api->fetchTransactions('', $iban, $date_from, $date_to);

        // Gestion des erreurs API
        if (is_int($response) && $response < 0) {
            $this->error = "ApiError_" . abs($response);
            return $response;
        }

        return $response['transactions'];
    }

    /**
     * Recherche des suggestions d'appairage pour une transaction Qonto donnée.
     * Cherche d'abord dans les écritures bancaires (montant exact + tolérance date).
     * Cherche ensuite dans les factures clients et fournisseurs impayées (montant exact, sans notion de date).
     * 
     * @param int    $bank_id       ID du compte bancaire Dolibarr
     * @param float  $amount        Montant (négatif pour débit, positif pour crédit)
     * @param string $date_iso      Date de la transaction Qonto (ISO)
     * @param int    $tolerance_days Nombre de jours de tolérance autour de la date
     * @return array                Liste des candidats trouvés
     */
    public function getMatchingCandidates($bank_id, $amount, $date_iso, $tolerance_days = 7)
    {
        $candidates = array();
        $abs_amount = abs((float) $amount);

        // -------------------------------------------------------------
        // 1. Recherche dans les écritures bancaires (llx_bank)
        // -------------------------------------------------------------
        $date_ts = strtotime($date_iso);
        $date_min = date('Y-m-d', strtotime('-' . $tolerance_days . ' days', $date_ts));
        $date_max = date('Y-m-d', strtotime('+' . $tolerance_days . ' days', $date_ts));

        $sql = "SELECT b.rowid, b.dateo, b.label, b.amount";
        $sql .= " FROM " . MAIN_DB_PREFIX . "bank as b";
        $sql .= " WHERE b.fk_account = " . (int) $bank_id;
        $sql .= " AND ABS(b.amount - " . (float) $amount . ") < 0.01";
        $sql .= " AND b.dateo >= '" . $this->db->escape($date_min) . " 00:00:00'";
        $sql .= " AND b.dateo <= '" . $this->db->escape($date_max) . " 23:59:59'";

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                // Vérifier s'il est déjà lié
                $sql_check = "SELECT fk_object FROM " . MAIN_DB_PREFIX . "bank_extrafields";
                $sql_check .= " WHERE fk_object = " . (int) $obj->rowid;
                $sql_check .= " AND options_qonto_txn_id IS NOT NULL AND options_qonto_txn_id != ''";
                
                $resql_check = $this->db->query($sql_check);
                $is_already_linked = ($resql_check && $this->db->num_rows($resql_check) > 0);

                if (!$is_already_linked) {
                    $candidates[] = array(
                        'type' => 'bank_line',
                        'id' => $obj->rowid,
                        'label' => $obj->label,
                        'date' => $obj->dateo,
                        'amount' => $obj->amount
                    );
                }
            }
        }

        // -------------------------------------------------------------
        // 2. Recherche dans les Factures Clients impayées (llx_facture)
        // -------------------------------------------------------------
        $sql = "SELECT f.rowid, f.datef, f.ref, f.total_ttc, f.type";
        $sql .= " FROM " . MAIN_DB_PREFIX . "facture as f";
        $sql .= " WHERE f.fk_statut = 1"; // 1 = Impayée
        // On compare directement le signe car Dolibarr stocke les avoirs en négatif pour les clients
        $sql .= " AND ABS(f.total_ttc - " . (float) $amount . ") < 0.01";

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $candidates[] = array(
                    'type' => 'customer_invoice',
                    'id' => $obj->rowid,
                    'label' => ($obj->type == 2 ? 'Avoir Client : ' : 'Facture Client : ') . $obj->ref,
                    'date' => $obj->datef,
                    'amount' => $obj->total_ttc
                );
            }
        }

        // -------------------------------------------------------------
        // 3. Recherche dans les Factures Fournisseurs impayées (llx_facture_fourn)
        // -------------------------------------------------------------
        $sql = "SELECT f.rowid, f.datef, f.ref, f.ref_supplier, f.total_ttc, f.type";
        $sql .= " FROM " . MAIN_DB_PREFIX . "facture_fourn as f";
        $sql .= " WHERE f.fk_statut = 1"; // 1 = Impayée
        // Pour les fournisseurs, une facture positive dans Dolibarr correspond à un débit (négatif) dans Qonto
        $sql .= " AND ABS(f.total_ttc - " . (-(float) $amount) . ") < 0.01";

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $candidates[] = array(
                    'type' => 'supplier_invoice',
                    'id' => $obj->rowid,
                    'label' => ($obj->type == 2 ? 'Avoir Fournisseur : ' : 'Facture Fournisseur : ') . ($obj->ref_supplier ? $obj->ref_supplier : $obj->ref),
                    'date' => $obj->datef,
                    'amount' => $obj->total_ttc
                );
            }
        }

        return $candidates;
    }

    /**
     * Vérifie si une transaction Qonto est déjà liée à une écriture Dolibarr.
     * 
     * @param string $qonto_txn_id ID de la transaction Qonto
     * @return int|bool            ID de la ligne bank Dolibarr si liée, sinon false
     */
    public function getLinkedBankLine($qonto_txn_id)
    {
        $sql = "SELECT be.fk_object, b.rappro FROM " . MAIN_DB_PREFIX . "bank_extrafields as be";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "bank as b ON b.rowid = be.fk_object";
        $sql .= " WHERE be.options_qonto_txn_id = '" . $this->db->escape($qonto_txn_id) . "'";
        
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            return array('id' => $obj->fk_object, 'rappro' => $obj->rappro);
        }
        
        return false;
    }

    /**
     * Traite l'action de liaison. Si c'est une écriture bancaire, la lie directement.
     * Si c'est une facture, crée d'abord le paiement (ce qui génère l'écriture bancaire)
     * puis lie l'écriture bancaire nouvellement créée.
     * 
     * @param string $qonto_txn_id ID de la transaction Qonto
     * @param string $candidate_type Type de candidat ('bank_line', 'customer_invoice', 'supplier_invoice')
     * @param int    $candidate_id   ID de l'objet (llx_bank ou llx_facture)
     * @param float  $amount         Montant de la transaction (positif ou négatif)
     * @param string $date_iso       Date de la transaction Qonto
     * @param int    $bank_id        ID du compte bancaire
     * @return int                   >0 si succès, <=0 si erreur
     */
    public function linkCandidate($qonto_txn_id, $candidate_type, $candidate_id, $amount, $date_iso, $bank_id, $operation_type = '', $reference = '')
    {
        global $user, $langs, $conf;

        $date_ts = strtotime($date_iso);
        $abs_amount = abs((float) $amount);

        if ($candidate_type == 'bank_line') {
            require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
            $accountline = new AccountLine($this->db);
            if ($accountline->fetch($candidate_id) > 0) {
                // Vérifier si elle n'est pas rapprochée (rappro = 0)
                if ($accountline->rappro == 0) {
                    // Mapping du type d'opération Qonto vers les codes Dolibarr
                    $payment_method = 'VIR'; // Défaut
                    if ($operation_type == 'card') $payment_method = 'CB';
                    elseif ($operation_type == 'direct_debit') $payment_method = 'PRE';
                    elseif ($operation_type == 'income' || $operation_type == 'transfer') $payment_method = 'VIR';
                    
                    // Mise à jour des champs non supportés par AccountLine::update()
                    $sql = "UPDATE " . MAIN_DB_PREFIX . "bank SET ";
                    $sql .= " datev = '" . $this->db->idate($date_ts) . "'";
                    $sql .= ", fk_type = '" . $this->db->escape($payment_method) . "'";
                    if (!empty($reference)) {
                        $sql .= ", num_chq = '" . $this->db->escape($reference) . "'";
                    }
                    $sql .= " WHERE rowid = " . (int)$candidate_id;
                    $this->db->query($sql);
                    
                    // Utilisation de la méthode native pour le rapprochement (gère les logs et l'utilisateur)
                    $accountline->num_releve = date('Ym', $date_ts);
                    $res_conc = $accountline->update_conciliation($user, 0, 1);
                    if ($res_conc < 0) {
                        $this->error = "Erreur de rapprochement : " . $accountline->error;
                        return -1;
                    }
                }
            }
            return $this->link($qonto_txn_id, $candidate_id);
        }
        
        if ($candidate_type == 'customer_invoice') {
            require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
            require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
            
            $fac = new Facture($this->db);
            if ($fac->fetch($candidate_id) > 0) {
                $paiement = new Paiement($this->db);
                $paiement->datepaye = $date_ts;
                $paiement->amounts = array($fac->id => $abs_amount);
                $paiement->multicurrency_amounts = array($fac->id => $abs_amount);
                $paiement->fk_account = $bank_id;
                
                // Mapping Qonto -> Dolibarr (llx_c_paiement: 2=VIR, 6=CB, 3=PRE)
                $paiementid = 2; // VIR
                if ($operation_type == 'card') $paiementid = 6;
                elseif ($operation_type == 'direct_debit') $paiementid = 3;
                
                $paiement->paiementid = $paiementid;
                $paiement->num_payment = !empty($reference) ? $reference : 'Qonto';

                $payment_id = $paiement->create($user, 1); // 1 = Close paid invoices automatically
                
                if ($payment_id > 0) {
                    // Create bank line
                    $bank_line_id = $paiement->addPaymentToBank($user, 'payment', '(CustomerInvoicePayment)', $bank_id, '', '');
                    if ($bank_line_id > 0) {
                        // Utilisation de la méthode native pour le rapprochement
                        require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
                        $accountline = new AccountLine($this->db);
                        if ($accountline->fetch($bank_line_id) > 0) {
                            $accountline->num_releve = date('Ym', $date_ts);
                            $res_conc = $accountline->update_conciliation($user, 0, 1);
                            if ($res_conc < 0) {
                                $this->error = "Erreur de rapprochement : " . $accountline->error;
                                return -1;
                            }
                        }
                        
                        return $this->link($qonto_txn_id, $bank_line_id);
                    }
                }
            }
        }
        
        if ($candidate_type == 'supplier_invoice') {
            require_once DOL_DOCUMENT_ROOT . '/fourn/class/paiementfourn.class.php';
            require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
            
            $fac = new FactureFournisseur($this->db);
            if ($fac->fetch($candidate_id) > 0) {
                $paiement = new PaiementFourn($this->db);
                $paiement->datepaye = $date_ts;
                $paiement->amounts = array($fac->id => $abs_amount);
                $paiement->multicurrency_amounts = array($fac->id => $abs_amount);
                $paiement->fk_account = $bank_id;
                
                $paiementid = 2; // VIR
                if ($operation_type == 'card') $paiementid = 6;
                elseif ($operation_type == 'direct_debit') $paiementid = 3;
                
                $paiement->paiementid = $paiementid;
                $paiement->num_payment = !empty($reference) ? $reference : 'Qonto';

                $payment_id = $paiement->create($user, 1); // 1 = Close paid invoices automatically
                
                if ($payment_id > 0) {
                    // Create bank line
                    $bank_line_id = $paiement->addPaymentToBank($user, 'payment_supplier', '(SupplierInvoicePayment)', $bank_id, '', '');
                    if ($bank_line_id > 0) {
                        // Utilisation de la méthode native pour le rapprochement
                        require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
                        $accountline = new AccountLine($this->db);
                        if ($accountline->fetch($bank_line_id) > 0) {
                            $accountline->num_releve = date('Ym', $date_ts);
                            $res_conc = $accountline->update_conciliation($user, 0, 1);
                            if ($res_conc < 0) {
                                $this->error = "Erreur de rapprochement : " . $accountline->error;
                                return -1;
                            }
                        }
                        
                        return $this->link($qonto_txn_id, $bank_line_id);
                    }
                }
            }
        }
        
        return -1;
    }

    /**
     * Lie formellement une transaction Qonto à une écriture bancaire Dolibarr.
     * 
     * @param string $qonto_txn_id ID de la transaction Qonto
     * @param int    $bank_line_id ID de l'écriture Dolibarr (rowid de llx_bank)
     * @return int                 >0 si succès, <=0 si erreur
     */
    public function link($qonto_txn_id, $bank_line_id)
    {
        require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';

        $extrafields = new ExtraFields($this->db);

        // 1. On s'assure que le dictionnaire est à jour pour l'élément 'bank'
        // C'est l'approche native suggérée par l'utilisateur
        $labels = $extrafields->fetch_name_optionals_label('bank');
        if (empty($labels) || !array_key_exists('qonto_txn_id', $labels)) {
            $extrafields->addExtraField(
                'qonto_txn_id',
                'Qonto Transaction ID',
                'varchar',
                255,
                'bank',
                0, 0, '', '', '', 1, '', '', 0, 'qontosync@qontosync'
            );
        }

        // 2. On charge la ligne bancaire
        $accountline = new AccountLine($this->db);
        if ($accountline->fetch($bank_line_id) > 0) {
            // 3. On ajoute notre valeur
            $accountline->array_options['options_qonto_txn_id'] = $qonto_txn_id;

            // 4. On utilise la méthode native qui aura désormais accès au dictionnaire !
            $result = $accountline->updateExtraField('qonto_txn_id');

            if ($result >= 0) {
                return 1;
            } else {
                $this->error = "Erreur ExtraFields native : " . $accountline->error;
                dol_syslog("qontosyncMatcher::link Error updating extrafields: " . $accountline->error, LOG_ERR);
                return -1;
            }
        }

        $this->error = "Ligne bancaire introuvable pour la liaison.";
        return -1;
    }
}
