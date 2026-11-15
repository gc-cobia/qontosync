<?php
/*
 * QontoSync dolibarr module 
 * @file       class/qontoaccount.trait.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
    http_response_code(403);
    exit('Access Forbidden');
}

/**
 * Shares a bank account (Dolibarr Account object) and its entity-scope check
 * with the classes that use it.
 *
 * The host class must provide a DoliDB handler in $this->db.
 *
 * @property DoliDB $db Database handler (declared by the host class)
 */
trait QontoAccountTrait
{
    /** @var Account|null */
    protected $account;

    protected function initAccount($accountOrId): bool
    {
        $this->account = null;

        if ($accountOrId instanceof Account) {
            if ($accountOrId->id > 0) {
                $this->account = $accountOrId;
                return true;
            }
            return false;
        }

        $bankId = (int) $accountOrId;
        if ($bankId > 0) {
            if (!class_exists('Account')) require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
            $acc = new Account($this->db);
            if ($acc->fetch($bankId) > 0) {
                $this->account = $acc;
                return true;
            }
        }

        return false;
    }

    protected function isAccountAllowed(): bool
    {
        if (empty($this->account) || $this->account->id <= 0) {
            return false;
        }

        return in_array((string) (int) $this->account->entity, explode(',', getEntity('bank_account')), true);
    }
}