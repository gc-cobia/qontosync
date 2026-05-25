<?php
/*
 *  QontoSync dolibarr module 
 *  @file       modQontosync.class.php
 *  @author     Gaëtan COURTOIS
 *  @license    GPLv3
 */


include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 *  Description and activation class for module Qontosync
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
		$this->numero = 456789;
		$this->rights_class = 'qontosync';
		$this->family = 'financial';
		$this->module_position = '50';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "Manual bank reconciliation module between Qonto API and Dolibarr.";
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'bank';

		// Data directories to create when module is enabled
		$this->dirs = array();

		// Config pages
		$this->config_page_url = array("setup.php@qontosync");

		// Dependencies
		$this->depends = array('modBanque', 'modFacture', 'modFournisseur');
		$this->requiredby = array();
		$this->langfiles = array("qontosync@qontosync");

		// Prerequisites
		$this->phpmin = array(7, 2);
		$this->need_dolibarr_version = array(18, -3);
		$this->max_dolibarr_version = array(23, -3);
		$this->need_javascript_ajax = 0;

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
			'css' => array(),
			'js' => array(),
			'hooks' => array(),
			'moduleforexternal' => 0,
			'websitetemplates' => 0,
			'captcha' => 0
			);

		// Constants
		$this->const = array(
			array('QONTOSYNC_API_KEY', 'chaine', '', 'Qonto API Key', 0),
			array('QONTOSYNC_SLUG', 'chaine', '', 'Qonto Login/Slug', 0),
			array('QONTOSYNC_SANDBOX', 'chaine', '0', 'Qonto Sandbox Mode', 0),
			array('QONTOSYNC_SANDBOX_TOKEN', 'chaine', '', 'Qonto Sandbox Token', 0),
			array('QONTOSYNC_PM_TRANSFER', 'chaine', '', 'Payment Mode for Transfer', 0),
			array('QONTOSYNC_PM_CARD', 'chaine', '', 'Payment Mode for Card', 0),
			array('QONTOSYNC_PM_DD', 'chaine', '', 'Payment Mode for Direct Debit', 0),
			array('QONTOSYNC_PM_CHECK', 'chaine', '', 'Payment Mode for Check', 0),
		);

		// Permissions
		$this->rights = array();
		$this->rights_class = 'qontosync';
		
		$r = 0;
		$r++;
		$this->rights[$r][0] = 4567891;
		$this->rights[$r][1] = 'Read qontosync';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'read';

		// Main menu entries
		$this->menu = array();
		$r = 0;
		$r++;
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=bank',
			'type' => 'left',
			'titre' => 'QontoSync',
			'mainmenu' => 'bank',
			'leftmenu' => 'qontosync',
			'url' => '/qontosync/index.php',
			'langs' => 'qontosync@qontosync',
			'position' => 1000,
			'enabled' => '$conf->qontosync->enabled',
			'perms' => '$user->rights->qontosync->read',
			'target' => '',
			'user' => 2,
		);
	}

	/**
	 *  Function called when module is enabled.
	 *
	 *  @param string $options Options when enabling module
	 *  @return int 1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		global $conf, $langs;

		// Create extrafields
		include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);

		// Extrafield bank
		$result = $extrafields->addExtraField('qonto_id', 'Qonto Transaction ID', 'varchar', 100, 255, 'bank', 1, 0, '', '', 1, '', '1', 0, 'Qonto Transaction ID to avoid duplicates', '', 'qontosync', '$conf->qontosync->enabled');
		$sql = array();
		return $this->_init($sql, $options);
	}

	/**
	 *  Function called when module is disabled.
	 *
	 *  @param string $options Options when enabling module
	 *  @return int 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
