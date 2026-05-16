<?php

/**
 * Description du module QontoSync
 */
class modQontoSync extends DolibarrModules
{
	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->numero = 542900;
		$this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = "QontoSyncModuleDescription";
        $this->editor_name = 'COBIA';
		$this->editor_url = 'https://www.cobia.fr';
		$this->version = '0.1';
		$this->family = 'financial';
		$this->flags = 'module';
		$this->picto = 'accountancy';
		$this->dirs = array('/qontosync');
		$this->config_page_url = array("setup.php@qontosync");

		$this->depends = array("modBanque");

		// Droits (Permissions)
		$this->rights = array();
		$r = 0;

		$r++;
		$this->rights[$r][0] = 542901; 
		$this->rights[$r][1] = 'QontoSyncPermissionRead';
		$this->rights[$r][2] = 'r'; 
		$this->rights[$r][3] = 1; 
		$this->rights[$r][4] = 'read';

		// Menus
		$this->menu = array();
		$r = 0;

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=bank,fk_leftmenu=bank_entries', // Sous-menu de l'onglet Banque
			'type'      => 'left',
			'titre'     => 'QontoSyncMenuTitle',
			'mainmenu'  => 'bank',
			'leftmenu'  => 'qontosync',
			'url'       => '/qontosync/index.php',
			'langs'     => 'qontosync@qontosync',
			'position'  => 1000,
			'enabled'   => '$conf->qontosync->enabled',
			'perms'     => '$user->rights->qontosync->read',
			'target'    => '',
			'user'      => 0
		);
	}

    /**
	* Fonction d'installation du module
	* * @param string $options Options d'installation
	* @return int 1 si OK, 0 si KO
	*/
	public function init($options = '')
	{		
		$res = $this->_insert_extrafield();
		if ($res < 0) {
			return 0;
		}

		return 1;
	}

	/**
	 * Fonction de désactivation (ne supprime pas les données par sécurité)
	 */
	public function remove($options = '')
	{
		return 1;
	}

	/**
	* Crée l'extrafield qonto_id sur la table bank de manière programmatique
	* * @return int 1 if OK, -1 if KO
	*/
	private function _insert_extrafield()
	{
		global $langs;
		include_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);

		$attrname = 'qonto_id';
		$label = 'QontoTransactionID';
		$type = 'varchar';
		$size = '255';
		$elementtype = 'bank'; // Rattaché aux écritures bancaires

		// On vérifie si l'extrafield existe déjà pour ne pas lever d'erreur
		if (!isset($extrafields->attributes[$elementtype]['label'][$attrname])) {
			$res = $extrafields->addExtraField(
				$attrname,
				$label,
				$type,
				0,    // Position
				$size,
				$elementtype,
				0,    // Unique
				1,    // Required
				'',   // Default value
				array('options' => array()), // Paramètres
				1,    // Enabled
				'',   // Visible
				1,    // Printable
				0,    // Totalizable
				''    // Help text
			);

			if ($res < 0) {
				$this->error = $extrafields->error;
				return -1;
			}
		}

		return 1;
	}
}
