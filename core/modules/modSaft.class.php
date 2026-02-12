<?php
/* Copyright (C) 2004-2018      Laurent Destailleur                     <eldy@users.sourceforge.net>
 * Copyright (C) 2018-2019      Nicolas ZABOURI                         <info@inovea-conseil.com>
 * Copyright (C) 2019-2024      Frédéric France                         <frederic.france@free.fr>
 * Copyright (C) 2026           Virgilio Filho                          <virgilio.filho@cialinux.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modSaft extends DolibarrModules
{
    public function __construct($db)
    {
        global $conf, $langs;

        $this->db = $db;

        // Id do módulo (tem que ser único)
        $this->numero = 500000;

        $this->rights_class = 'saft';
        $this->family = "other";
        $this->module_position = '90';

        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = "SaftDescription";
        $this->descriptionlong = "SaftDescription";

        $this->editor_name = 'Cia Linux, LDA';
        $this->editor_url = 'https://www.cialinux.com';
        $this->editor_squarred_logo = '';

        $this->version = '1.0';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

        // Ícone
        $this->picto = 'fa-file';

        $this->module_parts = array(
            'triggers' => 0,
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

        $this->dirs = array("/saft/temp");
        $this->config_page_url = array("setup.php@saft");

        $this->hidden = getDolGlobalInt('MODULE_SAFT_DISABLED');
        $this->depends = array();
        $this->requiredby = array();
        $this->conflictwith = array();

        $this->langfiles = array("saft@saft");

        $this->phpmin = array(7, 1);
        $this->need_dolibarr_version = array(19, -3);
        $this->need_javascript_ajax = 0;

        $this->warnings_activation = array();
        $this->warnings_activation_ext = array();

        $this->const = array();

        if (!isModEnabled("saft")) {
            $conf->saft = new stdClass();
            $conf->saft->enabled = 0;
        }

        $this->tabs = array();
        $this->dictionaries = array();
        $this->boxes = array();
        $this->cronjobs = array();

        // Permissions (por agora, sem permissões específicas)
        $this->rights = array();

        // =========================================================
        // MENUS
        // =========================================================
        $this->menu = array();
        $r = 0;

        // TOP menu (aparece na barra de cima)
        $this->menu[$r++] = array(
            'fk_menu'    => '',
            'type'       => 'top',
            'titre'      => 'ModuleSaftName',
            'prefix'     => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
            'mainmenu'   => 'saft',
            'leftmenu'   => '',
            // IMPORTANTE: suas páginas estão em /custom/saft/
            'url'        => '/custom/saft/saftindex.php',
            'langs'      => 'saft@saft',
            'position'   => 1000 + $r,
            'enabled'    => 'isModEnabled("saft")',
            'perms'      => '1',
            'target'     => '',
            'user'       => 2
        );

        // LEFT menu: "Validador"
        $this->menu[$r++] = array(
            'fk_menu'    => 'fk_mainmenu=saft',
            'type'       => 'left',
            'titre'      => 'Validador SAF-T',
            'mainmenu'   => 'saft',
            'leftmenu'   => 'saft_validator',
            'url'        => '/custom/saft/saftindex.php',
            'langs'      => 'saft@saft',
            'position'   => 1100,
            'enabled'    => 'isModEnabled("saft")',
            'perms'      => '1',
            'target'     => '',
            'user'       => 2
        );

        // LEFT menu: "Importar faturas"
        $this->menu[$r++] = array(
            'fk_menu'    => 'fk_mainmenu=saft',
            'type'       => 'left',
            'titre'      => 'Importar faturas (SAF-T)',
            'mainmenu'   => 'saft',
            'leftmenu'   => 'saft_import',
            'url'        => '/custom/saft/import/index.php',
            'langs'      => 'saft@saft',
            'position'   => 1110,
            'enabled'    => 'isModEnabled("saft")',
            'perms'      => '1',
            'target'     => '',
            'user'       => 2
        );
    }

    public function init($options = '')
    {
        global $conf, $langs;

        $result = $this->_load_tables('/saft/sql/');
        if ($result < 0) {
            return -1;
        }

        // Remove e recria menus/const/rights
        $this->remove($options);

        $sql = array();

        return $this->_init($sql, $options);
    }

    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }
}