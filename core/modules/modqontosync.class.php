<?php

class modqontosync extends DolibarrModules
{
    public function __construct($db)
    {
        global $langs;

        $this->db = $db;

        $this->numero = 104500;

        $this->rights_class = 'qontosync';

        $this->family = "financial";
        $this->module_position = '90';

        $this->name = preg_replace('/^mod/i', '', get_class($this));

        $this->description = $langs->trans("qontosyncDescription");

        $this->version = '1.0.0';

        $this->const_name = 'MAIN_MODULE_QONTOSYNC';

        $this->picto = 'bank';

        $this->module_parts = array(
            'triggers' => 1,
            'cronjobs' => 1
        );

        $this->dirs = array();

        $this->config_page_url = array(
            "setup.php@qontosync"
        );

        $this->depends = array(
            'modBanque'
        );

        $this->langfiles = array(
            "qontosync@qontosync"
        );

        $this->rights = array();

        $r = 0;

        $this->rights[$r][0] = 104501;
        $this->rights[$r][1] = $langs->trans('Readqontosync');
        $this->rights[$r][4] = 'read';

        $r++;

        $this->rights[$r][0] = 104502;
        $this->rights[$r][1] = $langs->trans('Manageqontosync');
        $this->rights[$r][4] = 'write';

        $this->menu = array();

        $this->menu[] = array(
            'fk_menu' => 'fk_mainmenu=bank',
            'type' => 'left',
            'titre' => 'qontosyncDashboard',
            'mainmenu' => 'bank',
            'leftmenu' => 'qontosync',
            'url' => '/custom/qontosync/dashboard.php',
            'langs' => 'qontosync@qontosync',
            'position' => 100,
            'enabled' => '$conf->qontosync->enabled',
            'perms' => '$user->hasRight("qontosync", "read")',
            'target' => '',
            'user' => 2
        );

        // Cron jobs
        $this->cronjobs = array();
        $this->cronjobs[] = array(
            'label' => 'qontosyncFetchTransactions',
            'jobtype' => 'method',
            'class' => '/qontosync/class/cron.class.php',
            'objectname' => 'qontosyncCron',
            'method' => 'fetchTransactions',
            'methodname' => 'fetchTransactions',
            'parameters' => '',
            'comment' => $langs->trans('qontosyncFetchTransactionsDesc'),
            'frequency' => 1,
            'unitfrequency' => 3600,
            'status' => 0,
            'test' => '$conf->qontosync->enabled',
            'priority' => 50
        );
    }

    /**
     *  Function called when module is enabled.
     *  The init function add constants, menus, permissions and exports defined in constructor.
     *  It also creates tables and extrafields.
     *
     *  @param      string      $options    Options for setup
     *  @return     int                     1 if OK, 0 if KO
     */
    public function init($options = '')
    {
        $sql = array();

        // Add extrafields
        include_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $extrafields = new ExtraFields($this->db);

        // Champ pour l'ID de transaction Qonto sur les écritures bancaires
        $extrafields->addExtraField(
            'qonto_txn_id',
            'QontoTransactionID',
            'varchar',
            255,
            'bank',
            0, 0, '', '', '', 1, '', '', 0, 'qontosync@qontosync'
        );

        return $this->_init($sql, $options);
    }

    /**
     *  Function called when module is disabled.
     *  Remove of extrafields.
     *
     *  @param      string      $options    Options for setup
     *  @return     int                     1 if OK, 0 if KO
     */
    public function remove($options = '')
    {
        $sql = array();

        // On ne supprime PAS l'extrafield lors de la désactivation pour conserver les données de liaison
        // include_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        // $extrafields = new ExtraFields($this->db);
        // $extrafields->delete('qonto_txn_id', 'bank');

        return $this->_remove($sql, $options);
    }
}