<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/includes/class-feuer-kontaktform-updater.php';
require_once __DIR__ . '/includes/support/class-feuer-kontaktform-support-db.php';
require_once __DIR__ . '/includes/support/class-feuer-kontaktform-support-mailer.php';
require_once __DIR__ . '/includes/support/class-feuer-kontaktform-support-inbound.php';
require_once __DIR__ . '/includes/support/class-feuer-kontaktform-support-admin.php';
require_once __DIR__ . '/includes/support/class-feuer-kontaktform-support-frontend.php';
require_once __DIR__ . '/includes/support/class-feuer-kontaktform-support-plugin.php';

Feuer_Kontaktform_Support_Plugin::uninstall();

