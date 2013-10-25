<?php
/**
 * @package SMF Zebroid Import
 * @author digger http://mysmf.ru
 * @copyright 2013
 * @license CC BY-NC-ND http://creativecommons.org/licenses/by-nc-nd/3.0/
 * @version 1.0
 */

global $context, $user_info;

if (file_exists(dirname(__FILE__) . '/SSI.php') && !defined('SMF'))
    require_once(dirname(__FILE__) . '/SSI.php');
elseif (!defined('SMF'))
    die('<b>Error:</b> Cannot install - please verify that you put this file in the same place as SMF\'s index.php and SSI.php files.');

if ((SMF == 'SSI') && !$user_info['is_admin'])
    die('Admin privileges required.');

if (!empty($context['uninstalling']))
    $call = 'remove_integration_function';
else
    $call = 'add_integration_function';

$hooks = array(
    'integrate_pre_include' => '$sourcedir/Mod-ZebroidImport.php',
    'integrate_admin_areas' => 'addZebroidAdminArea',
    'integrate_modify_modifications' => 'addZebroidAdminAction',
);

foreach ($hooks as $hook => $function)
    $call($hook, $function);

if (SMF == 'SSI')
    echo 'Database changes are complete! <a href="/">Return to the main page</a>.';
