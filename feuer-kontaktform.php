<?php
/**
 * Plugin Name: Feuer-Kontaktform
 * Plugin URI: https://wdmin.com/plugins/feuer-kontaktform/
  * Description: Support- und Anfrageformular fuer Freiwillige Feuerwehren mit Abteilungen, Themen, Datenschutz, AJAX-Wizard, Protokollen und Entwickler-Updates.
 * Version: 3.6.1
  * Author: Walter Faerber
 * Author URI: https://wdmin.com/
 * Requires at least: 7.1
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('FEUER_KONTAKTFORM_VERSION', '3.6.1');
define('FEUER_KONTAKTFORM_FILE', __FILE__);
define('FEUER_KONTAKTFORM_PATH', plugin_dir_path(__FILE__));
define('FEUER_KONTAKTFORM_URL', plugin_dir_url(__FILE__));

require_once FEUER_KONTAKTFORM_PATH . 'includes/class-feuer-kontaktform-updater.php';
require_once FEUER_KONTAKTFORM_PATH . 'includes/support/class-feuer-kontaktform-support-db.php';
require_once FEUER_KONTAKTFORM_PATH . 'includes/support/class-feuer-kontaktform-support-mailer.php';
require_once FEUER_KONTAKTFORM_PATH . 'includes/support/class-feuer-kontaktform-support-inbound.php';
require_once FEUER_KONTAKTFORM_PATH . 'includes/support/class-feuer-kontaktform-support-telegram.php';
require_once FEUER_KONTAKTFORM_PATH . 'includes/support/class-feuer-kontaktform-support-admin.php';
require_once FEUER_KONTAKTFORM_PATH . 'includes/support/class-feuer-kontaktform-support-frontend.php';
require_once FEUER_KONTAKTFORM_PATH . 'includes/support/class-feuer-kontaktform-support-plugin.php';

register_activation_hook(__FILE__, ['Feuer_Kontaktform_Support_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['Feuer_Kontaktform_Support_Plugin', 'deactivate']);

function feuer_kontaktform_plugin(): Feuer_Kontaktform_Support_Plugin
{
    static $plugin = null;

    if (! $plugin instanceof Feuer_Kontaktform_Support_Plugin) {
        $db      = new Feuer_Kontaktform_Support_DB();
        $mailer  = new Feuer_Kontaktform_Support_Mailer($db);
        $plugin  = new Feuer_Kontaktform_Support_Plugin($db, $mailer);
    }

    return $plugin;
}

feuer_kontaktform_plugin();

