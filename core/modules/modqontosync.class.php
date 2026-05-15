<?php

/**
 * Module descriptor for QontoSync.
 */
class modQontoSync extends DolibarrModules
{
    /**
     * Constructor
     *
     * @param DoliDB $db Database connector
     */
    public function __construct($db)
    {
        global $langs;

        $this->db = $db;

        // Module Id
        $this->numero = 527000;
        // Module Name
        $this->name = 'QontoSync';
        // Module description
        $this->description = "Synchronisation intelligente entre Qonto et Dolibarr";
        // Editor name
        $this->editor_name = 'Custom Developer';
        // Editor url
        $this->editor_url = '';
        // Module version
        $this->version = '1.0.0';
        // Module family
        $this->family = "financial";
        // Module position
        $this->module_position = 500;

        // Key used for configuration constants
        $this->const_name = 'MAIN_MODULE_QONTOSYNC';

        // Module picto
        $this->picto = 'bank';

        // Data directories to create when module is enabled
        $this->dirs = array();

        // Config page url
        $this->config_page_url = array("setup.php@qontosync");

        // Dependencies
        $this->depends = array('modBanque');

        // Language files
        $this->langfiles = array("qontosync@qontosync");

        // Constants
        $this->const = array();

        // Permissions
        $this->rights = array();
        $this->rights_class = 'qontosync';

        $r = 0;
        $this->rights[$r][0] = 527001;
        $this->rights[$r][1] = 'Read transactions';
        $this->rights[$r][2] = 'read';
        $this->rights[$r][3] = 1;
        $this->rights[$r][4] = 'read';
        $r++;

        $this->rights[$r][0] = 527002;
        $this->rights[$r][1] = 'Setup module';
        $this->rights[$r][2] = 'config';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'setup';

        // Menus
        $this->menu = array();
        $this->menu[] = array(
            'fk_menu' => 'fk_mainmenu=bank',
            'type' => 'left',
            'titre' => 'QontoSync',
            'mainmenu' => 'bank',
            'leftmenu' => 'qontosync',
            'url' => '/custom/qontosync/dashboard.php',
            'langs' => 'qontosync@qontosync',
            'position' => 1000,
            'enabled' => '$conf->qontosync->enabled',
            'perms' => '$user->hasRight("qontosync", "read")',
            'target' => '',
            'user' => 2
        );
    }

    /**
     * Function called when module is enabled.
     *
     * @param string $options Options for setup
     * @return int 1 if OK, 0 if KO
     */
    public function init($options = '')
    {
        $sql = array();
        return $this->_init($sql, $options);
    }

    /**
     * Function called when module is disabled.
     *
     * @param string $options Options for setup
     * @return int 1 if OK, 0 if KO
     */
    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }
}
