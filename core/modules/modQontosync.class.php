<?php
/*
 * QontoSync dolibarr module 
 * @file       core/modules/modQontosync.class.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module Qontosync
 */
class modQontosync extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;
		$this->numero = 424019;
		$this->rights_class = 'qontosync';
		$this->family = 'financial';
		$this->module_position = '50';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "Manual bank reconciliation module between Qonto API and Dolibarr.";
		$this->version = '1.2';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'qonto.svg@qontosync';

		// Data directories to create when module is enabled
		$this->dirs = array();

		// Config pages
		$this->config_page_url = array("setup.php@qontosync");

		// Dependencies
		// Only 'modBanque' is strictly required. Invoices and VAT are dynamically checked in the code.
		$this->depends = array('modBanque');
		$this->requiredby = array();
		$this->langfiles = array("qontosync@qontosync");

		// Prerequisites
		$this->phpmin = array(7, 2);
		$this->need_dolibarr_version = array(18, -3);
		$this->max_dolibarr_version = array(23, -3);
		// Changed to 1 because the module relies on JS (Select2, Modal boxes, UI interactions)
		$this->need_javascript_ajax = 1;

		// Define some features supported by module (triggers, login, substitutions, menus, css, etc...)
		$this->module_parts = array(
			'triggers' => 1,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array('/qontosync/css/qontosync.css'),
			'js' => array(),
			'hooks' => array(),
			'moduleforexternal' => 0,
			'websitetemplates' => 0,
			'captcha' => 0
			);

		// Constants
		$this->const = array(
		    // API configuration
			array('QONTOSYNC_API_KEY', 'chaine', '', 'Qonto API Key', 0),
			array('QONTOSYNC_SLUG', 'chaine', '', 'Qonto Login/Slug', 0),
			// Sandbox
			array('QONTOSYNC_SANDBOX', 'chaine', '0', 'Qonto Sandbox Mode', 0),
			array('QONTOSYNC_SANDBOX_TOKEN', 'chaine', '', 'Qonto Sandbox Token', 0),
			// Payment mode
			array('QONTOSYNC_PM_TRANSFER', 'chaine', '', 'Payment Mode for Transfer', 0),
			array('QONTOSYNC_PM_CARD', 'chaine', '', 'Payment Mode for Card', 0),
			array('QONTOSYNC_PM_DD', 'chaine', '', 'Payment Mode for Direct Debit', 0),
			array('QONTOSYNC_PM_CHECK', 'chaine', '', 'Payment Mode for Check', 0),
			// Tolerance for bank matching (date +/7 days by default)
			array('QONTOSYNC_TOLERANCE_DAYS', 'chaine', '7', 'Tolerance in days for bank matching', 0),
			// Max history for unpaid documents (24 months by default)
			array('QONTOSYNC_MAX_MONTHS', 'chaine', '24', 'Max months of history to fetch for unpaid documents', 0),
			// Format for bank statement reference (num_releve)
			array('QONTOSYNC_STATEMENT_FORMAT', 'chaine', '{Y}-{m}', 'Format for bank statement reference', 0),
			// Hide linked transactions
			array('QONTOSYNC_HIDE_LINKED', 'chaine', '0', 'Hide already linked transactions by default', 0),
		);

        // Permissions
		$this->rights = array();
		$this->rights_class = 'qontosync';
		
		$r = 0;

		$r++;
		$this->rights[$r][0] = ($this->numero * 10) + 1;
		$this->rights[$r][1] = 'PermissionReadQontoSync';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'read';

		$r++;
		$this->rights[$r][0] = ($this->numero * 10) + 2;
		$this->rights[$r][1] = 'PermissionWriteQontoSync';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'write';

		// Main menu entries
		$this->menu = array();
		$r = 0;
      
        $this->menu[$r] = array(
            'fk_menu'   => 'fk_mainmenu=bank',              
            'type'      => 'left',                          
            'mainmenu'  => 'bank',
            'leftmenu'  => 'qontosync_main',
            'url'       => '/qontosync/index.php',
            'langs'     => 'qontosync@qontosync',           
            'position'  => 2000,                            
            'enabled'   => '$conf->qontosync->enabled',     
            'perms'     => '$user->rights->qontosync->read',
            'target'    => '',
            'user'      => 2,                               
            'titre'     => 'QontoSync',
            'prefix'    => img_picto('', 'object_qonto.svg@qontosync', 'style="width: 16px; margin-right: 5px; vertical-align: text-bottom;"')
        );
        $r++;
        
        $this->menu[$r] = array(
            'fk_menu'   => 'fk_mainmenu=bank,fk_leftmenu=qontosync_main', 
            'type'      => 'left',
            'mainmenu'  => 'bank',
            'leftmenu'  => 'qontosync_reconciliation',
            'url'       => '/qontosync/reconciliation.php',
            'langs'     => 'banks',
            'position'  => 2001,
            'enabled'   => '$conf->qontosync->enabled',
            'perms'     => '$user->rights->qontosync->read',
            'target'    => '',
            'user'      => 2,
            'titre'     => 'Conciliation'
        );
        $r++;
        
        $this->menu[$r] = array(
            'fk_menu'   => 'fk_mainmenu=bank,fk_leftmenu=qontosync_main', 
            'type'      => 'left',
            'mainmenu'  => 'bank',
            'leftmenu'  => 'qontosync_statements',
            'url'       => '/qontosync/statements.php',
            'langs'     => 'banks',
            'position'  => 2002,
            'enabled'   => '$conf->qontosync->enabled',
            'perms'     => '$user->rights->qontosync->read',
            'target'    => '',
            'user'      => 2,
            'titre'     => 'AccountStatements' 
        );
        $r++;
	}

	/**
	 * Function called when module is enabled.
	 *
	 * @param string $options Options when enabling module
	 * @return int 1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		global $conf, $langs;

		// Create extrafields
		include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);

		// Extrafield bank
		$result = $extrafields->addExtraField('qonto_id', 'Qonto Transaction ID', 'varchar', 100, 255, 'bank', 1, 0, '', '', 1, '', '5', 'Qonto Transaction ID to avoid duplicates', '', 0, 'qontosync', '$conf->qontosync->enabled');
		$sql = array();
		return $this->_init($sql, $options);
	}

	/**
	 * Function called when module is disabled.
	 *
	 * @param string $options Options when enabling module
	 * @return int 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}