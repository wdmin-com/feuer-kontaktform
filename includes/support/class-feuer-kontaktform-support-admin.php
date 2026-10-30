<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Feuer_Kontaktform_Support_Admin
{
    private const MENU_SLUG = 'feuer-kontaktform';
    private bool $registered = false;
    private bool $tickets_page_rendered = false;

    public function __construct(
        private Feuer_Kontaktform_Support_DB $db,
        private Feuer_Kontaktform_Support_Mailer $mailer,
        private Feuer_Kontaktform_Support_Inbound $inbound,
        private Feuer_Kontaktform_Updater $updater
    ) {
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'handle_actions']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('wp_ajax_feuer_kontaktform_telegram_validate', [$this, 'validate_telegram_token']);
        add_action('wp_dashboard_setup', [$this, 'dashboard_widget']);
    }

    public function assets(string $hook): void
    {
        if (! str_contains($hook, 'feuer-kontaktform') && ! ($hook === 'index.php' && $this->user_has_access('tickets_view'))) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style('feuer-kontaktform-support-admin', FEUER_KONTAKTFORM_URL . 'assets/css/support-admin.css', [], FEUER_KONTAKTFORM_VERSION);
        wp_enqueue_script('feuer-kontaktform-support-admin', FEUER_KONTAKTFORM_URL . 'assets/js/support-admin.js', [], FEUER_KONTAKTFORM_VERSION, true);
        wp_add_inline_script('feuer-kontaktform-support-admin', 'window.feuerKontaktformAdmin = ' . wp_json_encode([
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'telegramNonce' => wp_create_nonce('feuer_kontaktform_telegram_validate'),
        ]) . ';', 'before');
    }

    /** Validates an entered token without saving or returning it. */
    public function validate_telegram_token(): void
    {
        if (! $this->user_has_access('settings_manage')) {
            wp_send_json_error(['message' => 'Keine Berechtigung für die Telegram-Einstellungen.'], 403);
        }
        check_ajax_referer('feuer_kontaktform_telegram_validate', 'nonce');

        $token = trim((string) wp_unslash($_POST['token'] ?? ''));
        if ($token === '') {
            wp_send_json_error(['message' => 'Bitte zuerst den Bot-Token von @BotFather einfügen.'], 400);
        }
        if (strlen($token) < 20 || strpos($token, ':') === false) {
            wp_send_json_error(['message' => 'Der Bot-Token wirkt unvollständig. Er enthält eine Zahlenfolge, einen Doppelpunkt und einen geheimen Teil.'], 400);
        }

        $endpoint = 'https://api.telegram.org/bot' . str_replace('%3A', ':', rawurlencode($token)) . '/getMe';
        $response = wp_remote_get($endpoint, ['timeout' => 10]);
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Telegram ist momentan nicht erreichbar: ' . $response->get_error_message()], 502);
        }
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) !== 200 || ! is_array($payload) || empty($payload['ok']) || ! is_array($payload['result'] ?? null)) {
            $this->db->log('telegram_token_validation_failed', 'telegram', 0, ['http_status' => wp_remote_retrieve_response_code($response)]);
            wp_send_json_error(['message' => 'Telegram akzeptiert diesen Token nicht. Bitte den Token bei @BotFather erneut kopieren.'], 400);
        }

        $bot = $payload['result'];
        $this->db->log('telegram_token_validated', 'telegram', 0, ['bot_id' => absint($bot['id'] ?? 0)]);
        wp_send_json_success(['message' => 'Verbindung erfolgreich: @' . sanitize_text_field((string) ($bot['username'] ?? 'Ihr Bot')) . ' antwortet.']);
    }

    public function menu(): void
    {
        if (! $this->user_can_open_plugin()) {
            return;
        }

        add_menu_page('Kontaktform', 'Kontaktform', 'read', self::MENU_SLUG, [$this, 'landing_page'], 'dashicons-sos', 56);

        if ($this->user_has_access('tickets_view')) {
            add_submenu_page(self::MENU_SLUG, 'Anfragen', 'Anfragen', 'read', self::MENU_SLUG, [$this, 'tickets_page']);
        }

        if ($this->user_has_access('departments_manage')) {
            add_submenu_page(self::MENU_SLUG, 'Abteilungen & Themen', 'Abteilungen & Themen', 'read', 'feuer-kontaktform-departments', [$this, 'departments_page']);
        }

        if ($this->user_has_access('settings_manage')) {
            add_submenu_page(self::MENU_SLUG, 'Einstellungen', 'Einstellungen', 'read', 'feuer-kontaktform-settings', [$this, 'settings_page']);

            add_submenu_page(self::MENU_SLUG, 'Telegram', 'Telegram', 'read', 'feuer-kontaktform-telegram', [$this, 'telegram_page']);
        }

        if ($this->user_has_access('logs_view')) {
            add_submenu_page(self::MENU_SLUG, 'Protokolle', 'Protokolle', 'read', 'feuer-kontaktform-logs', [$this, 'logs_page']);
        }
    }

    public function handle_actions(): void
    {
        if (empty($_POST['feuer_support_action'])) {
            return;
        }

        $action = sanitize_key((string) wp_unslash($_POST['feuer_support_action']));
        $permission = $this->action_permission($action);
        if ($permission === '') {
            return;
        }

        $this->ensure_access($permission);
        check_admin_referer('feuer_support_admin');

        switch ($action) {
            case 'save_settings':
                $settings = $this->sanitize_settings($_POST);
                $this->db->update_settings($settings);
                do_action('feuer_kontaktform_telegram_settings_saved');
                Feuer_Kontaktform_Support_Plugin::ensure_inbound_cron();
                $this->db->log('settings_saved', 'settings', 0, []);
                $this->redirect('feuer-kontaktform-settings', 'settings_saved');
                break;

            case 'create_telegram_pairing':
                $wp_user_id = absint($_POST['telegram_wp_user_id'] ?? 0);
                $permissions = isset($_POST['telegram_permissions']) && is_array($_POST['telegram_permissions']) ? wp_unslash($_POST['telegram_permissions']) : [];
                $code = $this->db->create_telegram_pairing($wp_user_id, $permissions);
                $this->redirect('feuer-kontaktform-telegram', $code !== '' ? 'telegram_pairing_created' : 'telegram_pairing_failed', $code !== '' ? ['feuer-detail' => $code] : []);
                break;

            case 'save_telegram_route':
                $actions = isset($_POST['telegram_route_actions']) && is_array($_POST['telegram_route_actions']) ? wp_unslash($_POST['telegram_route_actions']) : [];
                $ok = $this->db->save_telegram_route(absint($_POST['telegram_department_id'] ?? 0), absint($_POST['telegram_topic_id'] ?? 0), absint($_POST['telegram_user_id'] ?? 0), $actions);
                $this->redirect('feuer-kontaktform-telegram', $ok ? 'telegram_route_saved' : 'telegram_route_failed');
                break;

            case 'send_test_mail':
                $settings = $this->db->get_settings();
                $target = sanitize_email((string) wp_unslash($_POST['test_email'] ?? get_option('admin_email')));
                $result = $this->mailer->send_test($settings, $target);
                $ok = ! empty($result['success']);
                $this->db->log($ok ? 'test_mail_sent' : 'test_mail_failed', 'settings', 0, [
                    'target' => $target,
                    'success' => $ok,
                    'message' => (string) ($result['message'] ?? ''),
                ]);
                $this->redirect(
                    'feuer-kontaktform-settings',
                    $ok ? 'test_mail_sent' : 'test_mail_failed',
                    $ok ? [] : ['feuer-detail' => (string) ($result['message'] ?? '')]
                );
                break;

            case 'check_inbound_mail':
                Feuer_Kontaktform_Support_Plugin::ensure_inbound_cron();
                $result = $this->inbound->check_now();
                $this->redirect(
                    'feuer-kontaktform-settings',
                    ! empty($result['success']) ? 'inbound_mail_checked' : 'inbound_mail_failed',
                    ['feuer-detail' => (string) ($result['message'] ?? '')]
                );
                break;

            case 'check_updates':
                $status = $this->updater->check_now();
                $this->db->log('update_checked', 'update', 0, $status);
                $this->redirect(
                    'feuer-kontaktform-settings',
                    ! empty($status['error']) ? 'update_failed' : (! empty($status['update_available']) ? 'update_available' : 'update_current'),
                    ! empty($status['error']) ? ['feuer-detail' => (string) ($status['error'] ?? '')] : []
                );
                break;

            case 'install_update':
                $result = $this->updater->install_update();
                $context = [
                    'success' => ! empty($result['success']),
                    'message' => (string) ($result['message'] ?? ''),
                    'remote_version' => (string) ($result['info']['version'] ?? ''),
                    'download_url' => (string) ($result['info']['download_url'] ?? ''),
                ];
                $this->db->log(! empty($result['success']) ? 'update_installed' : 'update_install_failed', 'update', 0, $context);

                if (! empty($result['success'])) {
                    $this->redirect('feuer-kontaktform-settings', 'update_installed');
                }

                if (! empty($result['info']) && empty($result['info']['error']) && empty($result['info']['update_available'])) {
                    $this->redirect('feuer-kontaktform-settings', 'update_no_update');
                }

                $this->redirect(
                    'feuer-kontaktform-settings',
                    'update_install_failed',
                    ['feuer-detail' => (string) ($result['message'] ?? '')]
                );
                break;

            case 'download_backup':
                $payload = $this->db->export_backup_payload();
                $this->db->log('backup_exported', 'backup', 0, [
                    'plugin_version' => (string) ($payload['plugin_version'] ?? ''),
                    'departments' => count((array) ($payload['departments'] ?? [])),
                    'topics' => count((array) ($payload['topics'] ?? [])),
                    'tickets' => count((array) ($payload['tickets'] ?? [])),
                    'messages' => count((array) ($payload['messages'] ?? [])),
                    'logs' => count((array) ($payload['logs'] ?? [])),
                ]);
                $this->send_backup_download($payload);
                break;

            case 'download_tickets_backup':
                $payload = $this->db->export_tickets_backup_payload();
                $this->db->log('tickets_backup_exported', 'backup', 0, [
                    'plugin_version' => (string) ($payload['plugin_version'] ?? ''),
                    'tickets' => count((array) ($payload['tickets'] ?? [])),
                    'messages' => count((array) ($payload['messages'] ?? [])),
                ]);
                $this->send_backup_download($payload, 'kontaktform-anfragen-backup');
                break;

            case 'restore_backup':
                if (empty($_POST['backup_confirm'])) {
                    $this->redirect(
                        'feuer-kontaktform-settings',
                        'backup_restore_failed',
                        ['feuer-detail' => 'Bitte bestätigen Sie, dass die aktuellen Plugin-Daten ersetzt werden dürfen.']
                    );
                }

                $uploaded = $this->read_uploaded_backup();
                if (empty($uploaded['success'])) {
                    $this->db->log('backup_restore_failed', 'backup', 0, ['message' => (string) ($uploaded['message'] ?? '')]);
                    $this->redirect(
                        'feuer-kontaktform-settings',
                        'backup_restore_failed',
                        ['feuer-detail' => (string) ($uploaded['message'] ?? '')]
                    );
                }

                $result = $this->db->import_backup_payload((array) ($uploaded['payload'] ?? []));
                if (empty($result['success'])) {
                    $this->db->log('backup_restore_failed', 'backup', 0, ['message' => (string) ($result['message'] ?? '')]);
                }

                $this->redirect(
                    'feuer-kontaktform-settings',
                    ! empty($result['success']) ? 'backup_restored' : 'backup_restore_failed',
                    ['feuer-detail' => (string) ($result['message'] ?? '')]
                );
                break;

            case 'save_department':
                $result = $this->db->save_department([
                    'id' => absint($_POST['department_id'] ?? 0),
                    'name' => wp_unslash($_POST['department_name'] ?? ''),
                    'slug' => wp_unslash($_POST['department_slug'] ?? ''),
                    'email' => wp_unslash($_POST['department_email'] ?? ''),
                    'description' => wp_unslash($_POST['department_description'] ?? ''),
                    'contact_name' => wp_unslash($_POST['contact_name'] ?? ''),
                    'contact_phone' => wp_unslash($_POST['contact_phone'] ?? ''),
                    'contact_area' => wp_unslash($_POST['contact_area'] ?? ''),
                    'contact_description' => wp_unslash($_POST['contact_description'] ?? ''),
                    'contact_public' => ! empty($_POST['contact_public']),
                    'contact_visibility' => wp_unslash($_POST['contact_visibility'] ?? 'protected'),
                    'sort_order' => absint($_POST['department_sort_order'] ?? 0),
                    'is_active' => ! empty($_POST['department_is_active']),
                ]);
                $this->redirect(
                    'feuer-kontaktform-departments',
                    ! empty($result['success']) ? 'department_saved' : 'department_failed',
                    ! empty($result['success']) ? [] : ['feuer-detail' => (string) ($result['message'] ?? '')]
                );
                break;

            case 'delete_department':
                $this->db->deactivate_department(absint($_POST['department_id'] ?? 0));
                $this->redirect('feuer-kontaktform-departments', 'department_deleted');
                break;

            case 'move_department':
                $moved = $this->db->move_department(
                    absint($_POST['department_id'] ?? 0),
                    sanitize_key((string) wp_unslash($_POST['department_direction'] ?? ''))
                );
                $this->redirect('feuer-kontaktform-departments', $moved ? 'department_moved' : 'department_move_failed');
                break;

            case 'save_topic':
                $result = $this->db->save_topic([
                    'id' => absint($_POST['topic_id'] ?? 0),
                    'department_id' => absint($_POST['department_id'] ?? 0),
                    'title' => wp_unslash($_POST['topic_title'] ?? ''),
                    'slug' => wp_unslash($_POST['topic_slug'] ?? ''),
                    'email_override' => wp_unslash($_POST['topic_email_override'] ?? ''),
                    'auto_reply_enabled' => ! empty($_POST['topic_auto_reply_enabled']),
                    'auto_reply_format' => wp_unslash($_POST['topic_auto_reply_format'] ?? 'html'),
                    'auto_subject' => wp_unslash($_POST['topic_auto_subject'] ?? ''),
                    'auto_body' => wp_unslash($_POST['topic_auto_body'] ?? ''),
                    'auto_html' => wp_unslash($_POST['topic_auto_html'] ?? ''),
                    'sort_order' => absint($_POST['topic_sort_order'] ?? 0),
                    'is_active' => ! empty($_POST['topic_is_active']),
                    'fields' => $this->collect_fields(),
                ]);
                $this->redirect(
                    'feuer-kontaktform-departments',
                    ! empty($result['success']) ? 'topic_saved' : 'topic_failed',
                    ! empty($result['success']) ? [] : ['feuer-detail' => (string) ($result['message'] ?? '')]
                );
                break;

            case 'delete_topic':
                $this->db->deactivate_topic(absint($_POST['topic_id'] ?? 0));
                $this->redirect('feuer-kontaktform-departments', 'topic_deleted');
                break;

            case 'reply_ticket':
                $ticket_id = absint($_POST['ticket_id'] ?? 0);
                $message = sanitize_textarea_field((string) wp_unslash($_POST['reply_message'] ?? ''));
                $ticket = $this->db->get_ticket($ticket_id);
                $ok = false;
                $result = ['success' => false, 'message' => 'Anfrage oder Antworttext fehlt.'];

                if ($ticket && $message !== '') {
                    $settings = $this->db->get_settings();
                    $actor = wp_get_current_user();
                    $actor_name = $this->user_display_name($actor);
                    $result = $this->mailer->send_admin_reply($ticket, $settings, $message);
                    $ok = ! empty($result['success']);
                    if ($ok) {
                        $this->db->add_message($ticket_id, 'outbound', (string) $settings['from_email'], (string) $ticket['sender_email'], $message, [
                            'source' => 'admin_reply',
                            'actor_id' => get_current_user_id(),
                            'actor_name' => $actor_name,
                            'actor_email' => (string) ($actor->user_email ?? ''),
                        ]);
                        $this->db->log('ticket_replied', 'ticket', $ticket_id, ['ticket_code' => (string) $ticket['ticket_code'], 'actor_name' => $actor_name]);
                    } else {
                        $this->db->log('ticket_reply_failed', 'ticket', $ticket_id, [
                            'ticket_code' => (string) $ticket['ticket_code'],
                            'actor_name' => $actor_name,
                            'message' => (string) ($result['message'] ?? ''),
                        ]);
                    }
                }

                $this->redirect(
                    self::MENU_SLUG,
                    $ok ? 'ticket_reply_sent' : 'ticket_reply_failed',
                    $ok ? [] : ['feuer-detail' => (string) ($result['message'] ?? '')]
                );
                break;

            case 'start_ticket_work':
                $ticket_id = absint($_POST['ticket_id'] ?? 0);
                $ticket = $this->db->get_ticket($ticket_id);
                $settings = $this->db->get_settings();
                $result = ['success' => false, 'message' => 'Anfrage wurde nicht gefunden.'];
                if ($ticket && (string) $ticket['status'] === 'quarantined') {
                    $result = $this->mailer->send_processing_notice($ticket, $settings);
                    if (! empty($result['success'])) {
                        $this->db->update_ticket_workflow($ticket_id, 'in_progress', get_current_user_id(), 'Bearbeitung übernommen; Absender informiert');
                        $this->db->add_message($ticket_id, 'outbound', (string) $settings['from_email'], (string) $ticket['sender_email'], 'Ihre Anfrage wird jetzt bearbeitet.', ['source' => 'processing_notice', 'actor_id' => get_current_user_id()]);
                    }
                }
                $this->redirect(self::MENU_SLUG, ! empty($result['success']) ? 'ticket_work_started' : 'ticket_work_start_failed', empty($result['success']) ? ['feuer-detail' => (string) $result['message']] : []);
                break;

            case 'resend_department_notification':
                $ticket_id = absint($_POST['ticket_id'] ?? 0);
                $ticket = $this->db->get_ticket($ticket_id);
                $settings = $this->db->get_settings();
                $result = ['success' => false, 'message' => 'Anfrage oder Empfänger wurde nicht gefunden.', 'recipient' => ''];

                if ($ticket && (string) ($ticket['origin'] ?? '') === 'direct_email') {
                    $latest_inbound = $this->db->get_latest_ticket_message($ticket_id, 'inbound');
                    $result = is_array($latest_inbound) && (string) ($latest_inbound['source'] ?? '') === 'inbound_imap'
                        ? $this->mailer->send_direct_email_update($ticket, $settings, (string) $latest_inbound['body'])
                        : $this->mailer->send_direct_email_ticket($ticket, $settings, false);
                } elseif ($ticket) {
                    $department = $this->db->get_department((int) $ticket['department_id']);
                    $topic = $this->db->get_topic((int) $ticket['topic_id']);
                    if ($department && $topic) {
                        $latest_inbound = $this->db->get_latest_ticket_message($ticket_id, 'inbound');
                        $result = is_array($latest_inbound) && (string) ($latest_inbound['source'] ?? '') === 'inbound_imap'
                            ? $this->mailer->send_department_update(
                                $ticket,
                                $department,
                                $topic,
                                $settings,
                                (string) $latest_inbound['body']
                            )
                            : $this->mailer->send_ticket($ticket, $department, $topic, $settings, false);
                    }
                }

                if ($ticket) {
                    $fallback_recipient = (string) ($result['recipient'] ?? '');
                    if ($fallback_recipient === '') {
                        $fallback_recipient = (string) ($ticket['delivery_recipient'] ?? '');
                    }
                    if ($fallback_recipient === '') {
                        $fallback_recipient = (string) ($ticket['topic_email'] ?? '');
                    }
                    if ($fallback_recipient === '') {
                        $fallback_recipient = (string) ($ticket['department_email'] ?? '');
                    }

                    $this->db->record_delivery_attempt(
                        $ticket_id,
                        $fallback_recipient,
                        $result
                    );
                }

                $this->redirect(
                    self::MENU_SLUG,
                    ! empty($result['success']) ? 'ticket_notification_resent' : 'ticket_notification_failed',
                    ! empty($result['success']) ? [] : ['feuer-detail' => (string) ($result['message'] ?? '')]
                );
                break;

            case 'revoke_department_access':
                $ticket_id = absint($_POST['ticket_id'] ?? 0);
                $revoked = $ticket_id > 0 && $this->db->revoke_department_access($ticket_id);
                $this->redirect(
                    self::MENU_SLUG,
                    $revoked ? 'ticket_access_revoked' : 'ticket_access_revoke_failed'
                );
                break;
        }
    }

    public function landing_page(): void
    {
        if (! $this->user_can_open_plugin()) {
            $this->deny_access();
        }

        $cards = [
            ['permission' => 'tickets_view', 'slug' => self::MENU_SLUG, 'icon' => 'dashicons-email-alt', 'tone' => 'red', 'title' => 'Anfragen', 'text' => 'Neue Nachrichten prüfen, beantworten und bearbeiten.'],
            ['permission' => 'departments_manage', 'slug' => 'feuer-kontaktform-departments', 'icon' => 'dashicons-networking', 'tone' => 'blue', 'title' => 'Abteilungen & Themen', 'text' => 'Zuständigkeiten, Empfänger und Formularfelder verwalten.'],
            ['permission' => 'settings_manage', 'slug' => 'feuer-kontaktform-settings', 'icon' => 'dashicons-admin-generic', 'tone' => 'violet', 'title' => 'Einstellungen', 'text' => 'E-Mail, Ticket-Postfach, Datenschutz und Spam-Schutz konfigurieren.'],
            ['permission' => 'settings_manage', 'slug' => 'feuer-kontaktform-telegram', 'icon' => 'dashicons-format-chat', 'tone' => 'cyan', 'title' => 'Telegram', 'text' => 'Bot, Empfänger, Routing und Benachrichtigungen verwalten.'],
            ['permission' => 'logs_view', 'slug' => 'feuer-kontaktform-logs', 'icon' => 'dashicons-list-view', 'tone' => 'amber', 'title' => 'Protokolle', 'text' => 'Versand, Zugriffe, Fehler und Sicherheitsereignisse nachvollziehen.'],
        ];
        ?>
        <div class="wrap feuer-support-admin feuer-admin-home">
            <section class="feuer-home-hero"><div><p class="feuer-kicker">Kontaktform · Verwaltung</p><h1>Alles Wichtige auf einen Blick</h1><p>Wählen Sie einen Bereich, um Anfragen sicher und schnell zu bearbeiten.</p></div><span class="dashicons dashicons-sos"></span></section>
            <div class="feuer-home-grid">
            <?php foreach ($cards as $card) : ?>
                <?php if ($this->user_has_access($card['permission'])) : ?>
                    <a class="feuer-home-card is-<?php echo esc_attr($card['tone']); ?>" href="<?php echo esc_url(admin_url('admin.php?page=' . $card['slug'])); ?>"><span class="dashicons <?php echo esc_attr($card['icon']); ?>"></span><div><h2><?php echo esc_html($card['title']); ?></h2><p><?php echo esc_html($card['text']); ?></p><b>Bereich öffnen <span aria-hidden="true">→</span></b></div></a>
                <?php endif; ?>
            <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    public function tickets_page(): void
    {
        if ($this->tickets_page_rendered) {
            return;
        }

        $this->tickets_page_rendered = true;

        $this->ensure_access('tickets_view');
        $search = sanitize_text_field((string) wp_unslash($_GET['feuer_search'] ?? ''));
        $tickets = $this->db->get_tickets(200, $search);
        $can_reply = $this->user_has_access('tickets_reply');
        ?>
        <div class="wrap feuer-support-admin">
            <h1>Anfragen</h1>
            <?php $this->notice(); ?>
            <div class="feuer-admin-card">
                <div class="feuer-section-head">
                <h2>Letzte Anfragen</h2>
                <p class="description">Die <strong>Anfrage Nr.</strong> gehört in den Betreff jeder E-Mail-Antwort, damit der Dialog eindeutig zugeordnet werden kann.</p>
                </div>
                <div class="feuer-section-body">
                <form method="get" class="feuer-search-row">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>">
                    <input class="regular-text" type="search" name="feuer_search" value="<?php echo esc_attr($search); ?>" placeholder="E-Mail, Name oder Anfrage Nr.">
                    <button class="button">Suchen</button>
                    <?php if ($search !== '') : ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>">Zurücksetzen</a><?php endif; ?>
                </form>
                <div class="feuer-ticket-list">
                    <?php if ($tickets === []) : ?>
                        <p>Noch keine Anfragen vorhanden.</p>
                    <?php endif; ?>
                    <?php foreach ($tickets as $ticket) : ?>
                        <?php $messages = $this->db->get_ticket_messages((int) $ticket['id']); ?>
                        <details class="feuer-ticket-card">
                            <summary>
                                <strong><?php echo esc_html((string) $ticket['ticket_code']); ?></strong>
                                <span><?php echo esc_html((string) ($ticket['department_name'] ?? '')); ?></span>
                                <span><?php echo esc_html((string) ($ticket['topic_title'] ?? '')); ?></span>
                                <span><?php echo esc_html((string) $ticket['sender_name']); ?></span>
                                <time><?php echo esc_html((string) $ticket['created_at']); ?></time>
                            </summary>
                            <div class="feuer-ticket-body">
                                <div class="feuer-ticket-meta">
                                    <span>Status: <strong><?php echo esc_html((string) $ticket['status']); ?></strong></span>
                                    <span>Eingang: <strong><?php echo (string) ($ticket['origin'] ?? '') === 'direct_email' ? 'Direkte E-Mail' : 'Kontaktformular'; ?></strong></span>
                                    <span>Datenschutz: <strong><?php echo empty($ticket['privacy_required']) ? 'deaktiviert' : (! empty($ticket['privacy_accepted']) ? 'ja' : 'nein'); ?></strong> / <?php echo esc_html((string) $ticket['privacy_version']); ?></span>
                                    <span>IP: <strong><?php echo esc_html((string) $ticket['ip_address']); ?></strong></span>
                                    <span>Gerät: <strong><?php echo esc_html((string) $ticket['device_type']); ?></strong></span>
                                    <span>Browser: <strong><?php echo esc_html((string) $ticket['browser']); ?></strong></span>
                                </div>
                                <?php if ((string) $ticket['status'] === 'quarantined' && $can_reply) : ?>
                                    <form method="post" class="feuer-ticket-action">
                                        <?php wp_nonce_field('feuer_support_admin'); ?>
                                        <input type="hidden" name="feuer_support_action" value="start_ticket_work">
                                        <input type="hidden" name="ticket_id" value="<?php echo esc_attr((string) $ticket['id']); ?>">
                                        <button class="button button-primary">In Bearbeitung übernehmen</button>
                                    </form>
                                <?php endif; ?>
                                <section class="feuer-mail-delivery <?php echo esc_attr($this->delivery_status_class((string) $ticket['delivery_status'])); ?>">
                                    <div>
                                        <small><?php echo (string) ($ticket['origin'] ?? '') === 'direct_email' ? 'E-Mail an den Standard-Empfänger' : 'E-Mail an die Abteilung'; ?></small>
                                        <strong><?php echo esc_html($this->delivery_status_label((string) $ticket['delivery_status'])); ?></strong>
                                        <p>
                                            <?php
                                            $display_recipient = (string) ($ticket['delivery_recipient'] ?? '');
                                            if ($display_recipient === '') {
                                                $display_recipient = (string) ($ticket['topic_email'] ?? '');
                                            }
                                            if ($display_recipient === '') {
                                                $display_recipient = (string) ($ticket['department_email'] ?? '');
                                            }
                                            ?>
                                            Empfänger: <?php echo esc_html($display_recipient); ?>
                                            · Versuche: <?php echo esc_html((string) $ticket['delivery_attempts']); ?>
                                            <?php if (! empty($ticket['delivery_last_attempt_at'])) : ?>
                                                · Letzter Versand: <?php echo esc_html((string) $ticket['delivery_last_attempt_at']); ?>
                                            <?php endif; ?>
                                        </p>
                                        <?php if ((string) $ticket['delivery_status'] === 'accepted') : ?>
                                            <p class="description">Der Versandweg hat die Nachricht angenommen. Erst der Klick des Empfängers auf „Empfang bestätigen“ weist den tatsächlichen Empfang nach.</p>
                                        <?php elseif ((string) $ticket['delivery_status'] === 'confirmed') : ?>
                                            <p class="description">Bestätigt am <?php echo esc_html((string) $ticket['delivery_confirmed_at']); ?> über den Link in der Abteilungs-E-Mail.</p>
                                        <?php elseif (! empty($ticket['delivery_last_error'])) : ?>
                                            <p class="feuer-mail-error"><?php echo esc_html((string) $ticket['delivery_last_error']); ?></p>
                                        <?php else : ?>
                                            <p class="description">Für ältere Anfragen oder noch nicht versuchte Sendungen liegt kein Versandnachweis vor.</p>
                                        <?php endif; ?>
                                        <?php if (! empty($ticket['access_token_hash']) && empty($ticket['access_revoked_at'])) : ?>
                                            <p class="description">
                                                Geschützter Zugriff gültig bis <?php echo esc_html((string) $ticket['access_expires_at']); ?>.
                                                <?php if (! empty($ticket['access_last_used_at'])) : ?>
                                                    Zuletzt geöffnet: <?php echo esc_html((string) $ticket['access_last_used_at']); ?>.
                                                <?php endif; ?>
                                            </p>
                                        <?php elseif (! empty($ticket['access_revoked_at'])) : ?>
                                            <p class="description">Geschützter Zugriff widerrufen am <?php echo esc_html((string) $ticket['access_revoked_at']); ?>.</p>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($can_reply) : ?>
                                        <div class="feuer-delivery-actions">
                                            <form method="post">
                                                <?php wp_nonce_field('feuer_support_admin'); ?>
                                                <input type="hidden" name="feuer_support_action" value="resend_department_notification">
                                                <input type="hidden" name="ticket_id" value="<?php echo esc_attr((string) $ticket['id']); ?>">
                                                <button class="button"><?php echo (int) $ticket['delivery_attempts'] > 0 ? 'Erneut senden' : 'Jetzt senden'; ?></button>
                                            </form>
                                            <?php if (! empty($ticket['access_token_hash']) && empty($ticket['access_revoked_at'])) : ?>
                                                <form method="post">
                                                    <?php wp_nonce_field('feuer_support_admin'); ?>
                                                    <input type="hidden" name="feuer_support_action" value="revoke_department_access">
                                                    <input type="hidden" name="ticket_id" value="<?php echo esc_attr((string) $ticket['id']); ?>">
                                                    <button class="button">Zugriff widerrufen</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </section>
                                <div class="feuer-ticket-field-grid">
                                    <?php foreach ((array) $ticket['fields'] as $key => $value) : ?>
                                        <?php if ($value === '' || $value === []) { continue; } ?>
                                        <div class="feuer-ticket-field">
                                            <small><?php echo esc_html($this->field_label((string) $key)); ?></small>
                                            <strong><?php echo esc_html($this->field_value($value)); ?></strong>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="feuer-ticket-message">
                                    <small>Nachricht</small>
                                    <p><?php echo nl2br(esc_html((string) $ticket['message'])); ?></p>
                                </div>
                                <?php if ($messages !== []) : ?>
                                    <div class="feuer-message-timeline">
                                        <h3>Verlauf</h3>
                                        <?php foreach ($messages as $message) : ?>
                                            <article>
                                                <b><?php echo esc_html($this->message_actor_label($message, $ticket)); ?></b>
                                                <span><?php echo esc_html((string) $message['created_at']); ?></span>
                                                <p><?php echo nl2br(esc_html((string) $message['body'])); ?></p>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($can_reply) : ?>
                                    <form method="post" class="feuer-reply-form">
                                        <?php wp_nonce_field('feuer_support_admin'); ?>
                                        <input type="hidden" name="feuer_support_action" value="reply_ticket">
                                        <input type="hidden" name="ticket_id" value="<?php echo esc_attr((string) $ticket['id']); ?>">
                                        <h3>Antwort per E-Mail schreiben</h3>
                                        <p class="description">Betreff: <?php echo esc_html($this->ticket_subject($ticket)); ?></p>
                                        <p class="description">Verfügbare Platzhalter: <code>{{site_name}}</code>, <code>{{site_url}}</code>, <code>{{ticket_id}}</code>, <code>{{name}}</code>, <code>{{topic}}</code>, <code>{{subject}}</code>.</p>
                                        <label>Antwort<textarea class="large-text" name="reply_message" rows="9" required><?php echo esc_textarea($this->default_reply_template($ticket)); ?></textarea></label>
                                        <button class="button button-primary">Antwort senden</button>
                                    </form>
                                <?php else : ?>
                                    <p class="description">Für diese Rolle ist das Antworten aus dem Plugin derzeit nicht freigeschaltet.</p>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function departments_page(): void
    {
        $this->ensure_access('departments_manage');
        $departments = $this->db->get_departments();
        ?>
        <div class="wrap feuer-support-admin">
            <h1>Abteilungen & Themen</h1>
            <?php $this->notice(); ?>
            <p class="description">Jede Abteilung ist eine eigene Support-Route. In den Tabs bearbeiten Sie zuerst Name und E-Mail, danach die einzelnen Themen mit Autoantwort und Feldern.</p>

            <div class="feuer-admin-card">
                <div class="feuer-tabs feuer-department-tabs" data-feuer-tabs>
                    <div class="feuer-tab-nav" role="tablist">
                        <button type="button" class="is-active" data-tab-target="department-new">Neue Abteilung</button>
                        <?php foreach ($departments as $department) : ?>
                            <button type="button" data-tab-target="<?php echo esc_attr('department-' . (int) $department['id']); ?>"><?php echo esc_html((string) $department['name']); ?></button>
                        <?php endforeach; ?>
                    </div>
                    <div class="feuer-tab-panel is-active" data-tab-panel="department-new">
                        <h2>Neue Abteilung</h2>
                        <?php $this->department_form(); ?>
                    </div>
                    <?php foreach ($departments as $department_index => $department) : ?>
                        <?php $topics = $this->db->get_topics((int) $department['id']); ?>
                        <?php $tab_prefix = 'department-' . (int) $department['id']; ?>
                        <div class="feuer-tab-panel" data-tab-panel="<?php echo esc_attr($tab_prefix); ?>">
                            <div class="feuer-department-head">
                                <div>
                                    <h2><?php echo esc_html((string) $department['name']); ?></h2>
                                    <p><code><?php echo esc_html((string) $department['slug']); ?></code> - <?php echo esc_html((string) $department['email']); ?></p>
                                </div>
                                <div class="feuer-department-actions">
                                    <strong><?php echo esc_html((string) count($topics)); ?> Themen</strong>
                                    <form method="post">
                                        <?php wp_nonce_field('feuer_support_admin'); ?>
                                        <input type="hidden" name="feuer_support_action" value="move_department">
                                        <input type="hidden" name="department_id" value="<?php echo esc_attr((string) $department['id']); ?>">
                                        <input type="hidden" name="department_direction" value="up">
                                        <button class="button" <?php disabled($department_index === 0); ?>>Hoch</button>
                                    </form>
                                    <form method="post">
                                        <?php wp_nonce_field('feuer_support_admin'); ?>
                                        <input type="hidden" name="feuer_support_action" value="move_department">
                                        <input type="hidden" name="department_id" value="<?php echo esc_attr((string) $department['id']); ?>">
                                        <input type="hidden" name="department_direction" value="down">
                                        <button class="button" <?php disabled($department_index === count($departments) - 1); ?>>Runter</button>
                                    </form>
                                </div>
                            </div>
                            <div class="feuer-tabs feuer-topic-tabs" data-feuer-tabs>
                                <div class="feuer-tab-nav" role="tablist">
                                    <button type="button" class="is-active" data-tab-target="<?php echo esc_attr($tab_prefix . '-base'); ?>">Abteilung</button>
                                    <button type="button" data-tab-target="<?php echo esc_attr($tab_prefix . '-shortcodes'); ?>">Shortcodes</button>
                                    <?php foreach ($topics as $topic) : ?>
                                        <button type="button" data-tab-target="<?php echo esc_attr($tab_prefix . '-topic-' . (int) $topic['id']); ?>"><?php echo esc_html((string) $topic['title']); ?></button>
                                    <?php endforeach; ?>
                                    <button type="button" data-tab-target="<?php echo esc_attr($tab_prefix . '-new-topic'); ?>">+ Thema</button>
                                </div>
                                <div class="feuer-tab-panel is-active" data-tab-panel="<?php echo esc_attr($tab_prefix . '-base'); ?>">
                                    <h3>Abteilung</h3>
                                    <p class="description"><strong>Name und E-Mail der Abteilung</strong>. Diese E-Mail wird verwendet, wenn ein Thema keine eigene E-Mail-Adresse hat.</p>
                                    <?php $this->department_form($department); ?>
                                </div>
                                <div class="feuer-tab-panel" data-tab-panel="<?php echo esc_attr($tab_prefix . '-shortcodes'); ?>">
                                    <?php $this->department_shortcodes($department, $topics); ?>
                                </div>
                                <?php foreach ($topics as $topic) : ?>
                                    <div class="feuer-tab-panel" data-tab-panel="<?php echo esc_attr($tab_prefix . '-topic-' . (int) $topic['id']); ?>">
                                        <?php $this->topic_form($department, $topic); ?>
                                    </div>
                                <?php endforeach; ?>
                                <div class="feuer-tab-panel" data-tab-panel="<?php echo esc_attr($tab_prefix . '-new-topic'); ?>">
                                    <h3>Neues Thema</h3>
                                    <p class="description">Neue Themen starten mit einer Standard-Autoantwort und den Standardfeldern.</p>
                                    <?php $this->topic_form($department); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function settings_page(): void
    {
        $this->ensure_access('settings_manage');
        $settings = $this->db->get_settings();
        $update = $this->updater->get_status();
        ?>
        <div class="wrap feuer-support-admin">
            <h1>Einstellungen</h1>
            <?php $this->notice(); ?>
            <div class="feuer-admin-card">
                <div class="feuer-tabs" data-feuer-tabs>
                    <div class="feuer-tab-nav" role="tablist">
                        <button type="button" class="is-active" data-tab-target="settings-brand">Website & Logo</button>
                        <button type="button" data-tab-target="settings-design">Darstellung</button>
                        <button type="button" data-tab-target="settings-mail">E-Mail</button>
                        <button type="button" data-tab-target="settings-ticket-mailbox">Ticket-Postfach</button>
                        <button type="button" data-tab-target="settings-deliverability">Zustellbarkeit</button>
                        <button type="button" data-tab-target="settings-privacy">Datenschutz</button>
                        <?php if ($this->user_has_admin_access()) : ?><button type="button" data-tab-target="settings-access">Zugriff</button><?php endif; ?>
                        <button type="button" data-tab-target="settings-system">System</button>
                        <button type="button" data-tab-target="settings-backup">Sicherung</button>
                        <button type="button" data-tab-target="settings-shortcodes">Shortcodes</button>
                    </div>

                    <div class="feuer-tab-panel is-active" data-tab-panel="settings-brand">
                        <form method="post">
                            <?php wp_nonce_field('feuer_support_admin'); ?>
                            <input type="hidden" name="feuer_support_action" value="save_settings">
                            <h2>Website & Logo</h2>
                            <?php $this->input('site_name', 'Name der Website', $settings['site_name']); ?>
                            <label>
                                Logo
                                <div class="feuer-media-picker">
                                    <input class="regular-text" type="url" name="logo_url" value="<?php echo esc_attr((string) $settings['logo_url']); ?>" data-media-url>
                                    <button type="button" class="button" data-media-select>Bild aus Mediathek wählen</button>
                                    <button type="button" class="button" data-media-clear>Entfernen</button>
                                </div>
                                <span class="description">Das Logo wird in der Kontaktstrecke und in HTML-Autoantworten verwendet.</span>
                                <img class="feuer-logo-preview" src="<?php echo esc_url((string) $settings['logo_url']); ?>" alt="" data-media-preview<?php echo (string) $settings['logo_url'] === '' ? ' hidden' : ''; ?>>
                            </label>
                            <p><button class="button button-primary">Speichern</button></p>
                        </form>
                    </div>

                    <div class="feuer-tab-panel" data-tab-panel="settings-design">
                        <form method="post">
                            <?php wp_nonce_field('feuer_support_admin'); ?>
                            <input type="hidden" name="feuer_support_action" value="save_settings">
                            <h2>Darstellung der Kontaktstrecke</h2>
                            <p class="description">Diese Auswahl steuert nur die Optik. Logik, Pflichtfelder und AJAX-Ablauf bleiben gleich.</p>
                            <div class="feuer-form-grid">
                                <label>
                                    Design
                                    <select name="design_variant">
                                        <option value="classic" <?php selected((string) $settings['design_variant'], 'classic'); ?>>Klassisch</option>
                                        <option value="clean" <?php selected((string) $settings['design_variant'], 'clean'); ?>>Clean</option>
                                        <option value="stepper" <?php selected((string) $settings['design_variant'], 'stepper'); ?>>Schritt-Karten</option>
                                        <option value="rail" <?php selected((string) $settings['design_variant'], 'rail'); ?>>Linke Bewegungsleiste</option>
                                        <option value="stripe" <?php selected((string) $settings['design_variant'], 'stripe'); ?>>Obere Bewegungsleiste</option>
                                        <option value="cards" <?php selected((string) $settings['design_variant'], 'cards'); ?>>Kontaktkarten mit Dialog</option>
                                        <option value="faq" <?php selected((string) $settings['design_variant'], 'faq'); ?>>FAQ mit Kontaktübergang</option>
                                    </select>
                                </label>
                                <label>
                                    Eingabefelder
                                    <select name="field_variant">
                                        <option value="soft" <?php selected((string) $settings['field_variant'], 'soft'); ?>>Weich ohne Kartenrand</option>
                                        <option value="line" <?php selected((string) $settings['field_variant'], 'line'); ?>>Linie</option>
                                        <option value="filled" <?php selected((string) $settings['field_variant'], 'filled'); ?>>Gefüllt</option>
                                        <option value="compact" <?php selected((string) $settings['field_variant'], 'compact'); ?>>Kompakt</option>
                                    </select>
                                </label>
                            </div>
                            <p><button class="button button-primary">Speichern</button></p>
                        </form>
                    </div>

                    <div class="feuer-tab-panel" data-tab-panel="settings-mail">
                        <form method="post">
                            <?php wp_nonce_field('feuer_support_admin'); ?>
                            <input type="hidden" name="feuer_support_action" value="save_settings">
                            <h2>E-Mail</h2>
                            <div class="feuer-form-grid">
                                <?php $this->input('base_email', 'Basis E-Mail', $settings['base_email'], 'email'); ?>
                                <?php $this->input('from_email', 'Absender E-Mail', $settings['from_email'], 'email'); ?>
                                <?php $this->input('from_name', 'Absender Name', $settings['from_name']); ?>
                            </div>
                            <h3>SMTP Versand</h3>
                            <p class="description">Empfohlen: SMTP-Konto mit demselben Domain wie die Absender-E-Mail. Wenn ein separates SMTP-Plugin genutzt wird, diese Option deaktiviert lassen.</p>
                            <input type="hidden" name="smtp_enabled" value="0">
                            <label class="feuer-check-label"><input type="checkbox" name="smtp_enabled" value="1" <?php checked(! empty($settings['smtp_enabled'])); ?>> SMTP direkt im Plugin verwenden</label>
                            <div class="feuer-form-grid">
                                <?php $this->input('smtp_host', 'SMTP Host', $settings['smtp_host']); ?>
                                <?php $this->input('smtp_port', 'SMTP Port', $settings['smtp_port'], 'number'); ?>
                                <label>
                                    Verschlüsselung
                                    <select name="smtp_encryption">
                                        <option value="tls" <?php selected((string) $settings['smtp_encryption'], 'tls'); ?>>TLS</option>
                                        <option value="ssl" <?php selected((string) $settings['smtp_encryption'], 'ssl'); ?>>SSL</option>
                                        <option value="none" <?php selected((string) $settings['smtp_encryption'], 'none'); ?>>Keine</option>
                                    </select>
                                </label>
                                <?php $this->input('smtp_username', 'SMTP Benutzername', $settings['smtp_username']); ?>
                            </div>
                            <label>
                                SMTP Passwort
                                <input class="regular-text" type="password" name="smtp_password" value="" autocomplete="new-password" placeholder="<?php echo empty($settings['smtp_password']) ? 'Nicht gespeichert' : 'Gespeichert - leer lassen zum Beibehalten'; ?>">
                                <span class="description">Leer lassen, um ein gespeichertes Passwort beizubehalten.</span>
                            </label>
                            <p><button class="button button-primary">Speichern</button></p>
                        </form>
                        <form method="post" class="feuer-subform">
                            <h3>E-Mail Verbindung prüfen</h3>
                            <?php wp_nonce_field('feuer_support_admin'); ?>
                            <input type="hidden" name="feuer_support_action" value="send_test_mail">
                            <?php $this->input('test_email', 'Test-Empfänger', get_option('admin_email'), 'email'); ?>
                            <p><button class="button">Testmail senden</button></p>
                        </form>
                    </div>

                    <div class="feuer-tab-panel" data-tab-panel="settings-ticket-mailbox">
                        <form method="post">
                            <?php wp_nonce_field('feuer_support_admin'); ?>
                            <input type="hidden" name="feuer_support_action" value="save_settings">
                            <h2>Ticket-Postfach</h2>
                            <p class="description">Beispiel: <code>anfrage@domain.de</code>. Antworten der Besucher und Abteilungen werden über dieses Postfach wieder in den Anfrageverlauf importiert. SMTP versendet Nachrichten, IMAP liest die Antworten.</p>
                            <p class="description">Der Mailserver muss Plus-Adressierung oder Catch-all unterstützen. Das Plugin erzeugt sichere Antwortadressen wie <code>anfrage+fkt-12345678-securecode@domain.de</code>; alle Nachrichten landen weiterhin im Postfach <code>anfrage@domain.de</code>.</p>
                            <p class="<?php echo function_exists('imap_open') ? 'notice notice-success inline' : 'notice notice-error inline'; ?>">
                                <?php echo function_exists('imap_open')
                                    ? 'PHP-IMAP ist auf diesem Server verfügbar.'
                                    : 'PHP-IMAP fehlt auf diesem Server. Der Hosting-Anbieter muss die PHP-Erweiterung imap aktivieren.'; ?>
                            </p>
                            <input type="hidden" name="ticket_mailbox_enabled" value="0">
                            <label class="feuer-check-label"><input type="checkbox" name="ticket_mailbox_enabled" value="1" <?php checked(! empty($settings['ticket_mailbox_enabled'])); ?>> Ticket-Postfach und E-Mail-Antwortimport aktivieren</label>
                            <?php $next_inbound_check = wp_next_scheduled('feuer_kontaktform_check_inbound_mail'); ?>
                            <p class="<?php echo $next_inbound_check ? 'notice notice-success inline' : 'notice notice-error inline'; ?>">
                                <?php echo $next_inbound_check
                                    ? 'WP-Cron ist geplant. Nächste Postfachprüfung: ' . esc_html(wp_date('Y-m-d H:i:s', (int) $next_inbound_check)) . '.'
                                    : 'WP-Cron ist noch nicht geplant. Speichern Sie die Einstellungen erneut oder prüfen Sie, ob WP-Cron auf dem Server deaktiviert wurde.'; ?>
                            </p>
                            <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) : ?>
                                <p class="notice notice-warning inline">DISABLE_WP_CRON ist aktiv. Der Server muss WordPress-Cron extern aufrufen, sonst wird das Postfach nur manuell geprüft.</p>
                            <?php endif; ?>
                            <div class="feuer-form-grid">
                                <?php $this->input('ticket_mailbox_email', 'Ticket E-Mail', $settings['ticket_mailbox_email'], 'email'); ?>
                                <?php $this->input('imap_host', 'IMAP Host', $settings['imap_host']); ?>
                                <?php $this->input('imap_port', 'IMAP Port', $settings['imap_port'], 'number'); ?>
                                <label>
                                    IMAP Verschlüsselung
                                    <select name="imap_encryption">
                                        <option value="ssl" <?php selected((string) $settings['imap_encryption'], 'ssl'); ?>>SSL</option>
                                        <option value="tls" <?php selected((string) $settings['imap_encryption'], 'tls'); ?>>TLS</option>
                                        <option value="none" <?php selected((string) $settings['imap_encryption'], 'none'); ?>>Keine</option>
                                    </select>
                                </label>
                                <?php $this->input('imap_username', 'IMAP Benutzername', $settings['imap_username']); ?>
                                <?php $this->input('imap_folder', 'IMAP Ordner', $settings['imap_folder']); ?>
                            </div>
                            <label>
                                IMAP Passwort
                                <input class="regular-text" type="password" name="imap_password" value="" autocomplete="new-password" placeholder="<?php echo empty($settings['imap_password']) ? 'Nicht gespeichert' : 'Gespeichert - leer lassen zum Beibehalten'; ?>">
                                <span class="description">Leer lassen, um das gespeicherte Passwort beizubehalten.</span>
                            </label>
                            <h3>Weitere Postfächer für den E-Mail-Sammelabruf</h3>
                            <p class="description">Zusätzliche IMAP-Postfächer werden beim Cron-Lauf mit geprüft. Das Standard-Postfach oben bleibt der Reply-To-Absender für sichere Ticket-Antwortadressen.</p>
                            <?php $this->mailbox_rows($settings); ?>
                            <p class="description">Wichtig: Der Betreff muss die Form <code>[Anfrage Nr. 12345678]</code> behalten. Zusätzlich prüft das Plugin den sicheren Code in der Empfängeradresse und akzeptiert nur die gespeicherte Besucher- oder Abteilungs-E-Mail.</p>
                            <hr>
                            <h3>Neue Anfragen direkt per E-Mail</h3>
                            <p class="description">Neue E-Mails ohne Anfrage Nr., die direkt an das Ticket-Postfach gesendet werden, erhalten automatisch eine 8-stellige Anfrage Nr., werden im Verlauf gespeichert und an den Standard-Empfänger weitergeleitet. Die Benachrichtigung enthält denselben geschützten Browserdialog wie eine Anfrage aus dem Formular.</p>
                            <?php
                            $direct_recipient = sanitize_email((string) ($settings['direct_email_recipient'] ?: $settings['base_email']));
                            $ticket_mailbox = sanitize_email((string) $settings['ticket_mailbox_email']);
                            $ticket_mailbox_emails = [];
                            foreach ((array) ($settings['ticket_mailboxes'] ?? []) as $mailbox) {
                                if (is_array($mailbox)) {
                                    $email = strtolower(sanitize_email((string) ($mailbox['email'] ?? '')));
                                    if ($email !== '') {
                                        $ticket_mailbox_emails[] = $email;
                                    }
                                }
                            }
                            ?>
                            <?php if (! empty($settings['direct_email_enabled']) && ($direct_recipient === '' || in_array(strtolower($direct_recipient), $ticket_mailbox_emails, true) || strtolower($direct_recipient) === strtolower($ticket_mailbox))) : ?>
                                <p class="notice notice-error inline">Direkte E-Mail-Anfragen können erst verarbeitet werden, wenn ein gültiger Standard-Empfänger eingetragen ist, der sich vom Ticket-Postfach unterscheidet.</p>
                            <?php endif; ?>
                            <input type="hidden" name="direct_email_enabled" value="0">
                            <label class="feuer-check-label"><input type="checkbox" name="direct_email_enabled" value="1" <?php checked(! empty($settings['direct_email_enabled'])); ?>> Neue direkte E-Mail-Anfragen annehmen</label>
                            <div class="feuer-form-grid">
                                <?php $this->input('direct_email_recipient', 'Standard-Empfänger für direkte E-Mails', $settings['direct_email_recipient'], 'email'); ?>
                            </div>
                            <input type="hidden" name="direct_email_auto_reply" value="0">
                            <label class="feuer-check-label"><input type="checkbox" name="direct_email_auto_reply" value="1" <?php checked(! empty($settings['direct_email_auto_reply'])); ?>> Empfangsbestätigung mit Anfrage Nr. an den Absender senden</label>
                            <p class="description">Ticket-Postfach und Standard-Empfänger müssen unterschiedliche Adressen sein. Automatische Antworten, Zustellberichte und interne Absender werden zum Schutz vor E-Mail-Schleifen nicht als neue Anfrage angelegt.</p>
                            <p class="description">In dieser Version wird der lesbare Textinhalt der E-Mail übernommen. E-Mail-Anhänge werden noch nicht in den Anfrageverlauf importiert.</p>
                            <p><button class="button button-primary">Postfach-Einstellungen speichern</button></p>
                        </form>
                        <form method="post" class="feuer-subform">
                            <?php wp_nonce_field('feuer_support_admin'); ?>
                            <input type="hidden" name="feuer_support_action" value="check_inbound_mail">
                            <h3>Posteingang prüfen</h3>
                            <p class="description">Prüft die IMAP-Verbindung und verarbeitet bis zu 25 neue Nachrichten oder Antworten. Automatisch wird der Posteingang alle fünf Minuten über WordPress Cron geprüft.</p>
                            <p><button class="button">Posteingang jetzt prüfen</button></p>
                        </form>
                    </div>

                    <div class="feuer-tab-panel" data-tab-panel="settings-deliverability">
                        <?php $report = $this->deliverability_report($settings); ?>
                        <form method="post">
                            <?php wp_nonce_field('feuer_support_admin'); ?>
                            <input type="hidden" name="feuer_support_action" value="save_settings">
                            <h2>E-Mail Zustellbarkeit</h2>
                            <p class="description">Diese Einstellungen reduzieren Spam-Risiken. Die wichtigsten Punkte bleiben SPF, DKIM und DMARC im DNS des Absender-Domains sowie ein sauber konfigurierter SMTP-Versand.</p>
                            <?php if (! empty($settings['ticket_mailbox_enabled'])) : ?>
                                <p class="notice notice-info inline">Das Ticket-Postfach ist aktiv. Reply-To wird deshalb automatisch auf die sichere Anfrage-Adresse des Ticket-Postfachs gesetzt; die beiden manuellen Reply-To-Auswahlen gelten nur bei deaktiviertem Ticket-Postfach.</p>
                            <?php endif; ?>
                            <div class="feuer-form-grid">
                                <label>
                                    Reply-To für interne Benachrichtigung
                                    <select name="notification_reply_to">
                                        <option value="sender" <?php selected((string) $settings['notification_reply_to'], 'sender'); ?>>Absender der Anfrage (empfohlen)</option>
                                        <option value="department" <?php selected((string) $settings['notification_reply_to'], 'department'); ?>>Abteilung</option>
                                        <option value="none" <?php selected((string) $settings['notification_reply_to'], 'none'); ?>>Kein Reply-To</option>
                                    </select>
                                    <span class="description">Wenn hier <strong>Absender der Anfrage</strong> gewählt ist, geht die Antwort aus dem Mailprogramm direkt an den Besucher.</span>
                                </label>
                                <label>
                                    Reply-To für Autoantwort
                                    <select name="auto_reply_reply_to">
                                        <option value="department" <?php selected((string) $settings['auto_reply_reply_to'], 'department'); ?>>Abteilung</option>
                                        <option value="base" <?php selected((string) $settings['auto_reply_reply_to'], 'base'); ?>>Basis E-Mail</option>
                                        <option value="none" <?php selected((string) $settings['auto_reply_reply_to'], 'none'); ?>>Kein Reply-To</option>
                                    </select>
                                </label>
                                <?php $this->input('dkim_selector', 'DKIM Selector für DNS-Prüfung', $settings['dkim_selector']); ?>
                            </div>
                            <input type="hidden" name="enforce_from_domain" value="0">
                            <label class="feuer-check-label"><input type="checkbox" name="enforce_from_domain" value="1" <?php checked(! empty($settings['enforce_from_domain'])); ?>> Warnen, wenn Absender-Domain nicht zur Website passt</label>
                            <input type="hidden" name="auto_response_headers" value="0">
                            <label class="feuer-check-label"><input type="checkbox" name="auto_response_headers" value="1" <?php checked(! empty($settings['auto_response_headers'])); ?>> Autoantwort-Header setzen: Auto-Submitted und X-Auto-Response-Suppress</label>
                            <p><button class="button button-primary">Speichern</button></p>
                        </form>

                        <div class="feuer-delivery-grid">
                            <div class="feuer-delivery-card <?php echo esc_attr($report['domain_match_class']); ?>">
                                <strong>Absender-Domain</strong>
                                <span><?php echo esc_html($report['from_domain']); ?></span>
                                <small><?php echo esc_html($report['domain_match_text']); ?></small>
                            </div>
                            <div class="feuer-delivery-card <?php echo esc_attr($report['spf_class']); ?>">
                                <strong>SPF</strong>
                                <span><?php echo esc_html($report['spf_text']); ?></span>
                                <small><?php echo esc_html($report['spf_hint']); ?></small>
                            </div>
                            <div class="feuer-delivery-card <?php echo esc_attr($report['dmarc_class']); ?>">
                                <strong>DMARC</strong>
                                <span><?php echo esc_html($report['dmarc_text']); ?></span>
                                <small><?php echo esc_html($report['dmarc_hint']); ?></small>
                            </div>
                            <div class="feuer-delivery-card <?php echo esc_attr($report['dkim_class']); ?>">
                                <strong>DKIM</strong>
                                <span><?php echo esc_html($report['dkim_text']); ?></span>
                                <small><?php echo esc_html($report['dkim_hint']); ?></small>
                            </div>
                            <div class="feuer-delivery-card <?php echo esc_attr($report['smtp_class']); ?>">
                                <strong>SMTP</strong>
                                <span><?php echo esc_html($report['smtp_text']); ?></span>
                                <small><?php echo esc_html($report['smtp_hint']); ?></small>
                            </div>
                        </div>

                        <div class="feuer-delivery-advice">
                            <h3>Empfohlene Praxis</h3>
                            <ul>
                                <li>From muss ein Postfach des eigenen Domains sein, z.B. <code>kontakt@<?php echo esc_html($report['site_domain']); ?></code>.</li>
                                <li>Autoantworten werden nie vom E-Mail-Absender des Besuchers verschickt.</li>
                                <li>SMTP sollte über dieselbe Domain laufen wie die From-Adresse.</li>
                                <li>SPF, DKIM und DMARC müssen beim DNS-Provider der Domain gesetzt sein.</li>
                                <li>HTML-Autoantworten sollten wenige Links, keine Scripts, keine Base64-Bilder und keine URL-Shortener enthalten.</li>
                            </ul>
                        </div>
                    </div>

                    <div class="feuer-tab-panel" data-tab-panel="settings-privacy">
                        <form method="post">
                            <?php wp_nonce_field('feuer_support_admin'); ?>
                            <input type="hidden" name="feuer_support_action" value="save_settings">
                            <h2>Datenschutz</h2>
                            <input type="hidden" name="privacy_enabled" value="0">
                            <label class="feuer-check-label"><input type="checkbox" name="privacy_enabled" value="1" <?php checked(! empty($settings['privacy_enabled'])); ?>> Datenschutz-Schritt im Frontend aktivieren</label>
                            <?php $this->input('privacy_url', 'URL zur Datenschutzerklärung', $settings['privacy_url'], 'url'); ?>
                            <label>Text für Datenschutzhinweis<textarea name="privacy_text" rows="5" class="large-text"><?php echo esc_textarea((string) $settings['privacy_text']); ?></textarea></label>
                            <?php $this->input('privacy_version', 'Datenschutz-Version', $settings['privacy_version']); ?>
                            <h3>Ticket-Zugriff der Abteilung</h3>
                            <input type="hidden" name="department_access_confirmation_required" value="0">
                            <label class="feuer-check-label"><input type="checkbox" name="department_access_confirmation_required" value="1" <?php checked(! empty($settings['department_access_confirmation_required'])); ?>> Empfangsbestaetigung vor dem Anzeigen der Anfrage erzwingen</label>
                            <p class="description">Aktiv: Die Abteilung muss den Empfang bestaetigen, bevor die Anfrage im geschuetzten Browserdialog sichtbar wird. Inaktiv: Der sichere Link oeffnet die Anfrage direkt und markiert den Empfang automatisch.</p>
                            <label>Text nach erfolgreichem Versand<textarea name="ticket_success_text" rows="4" class="large-text"><?php echo esc_textarea((string) $settings['ticket_success_text']); ?></textarea></label>
                            <p><button class="button button-primary">Speichern</button></p>
                        </form>
                    </div>

                    <?php if ($this->user_has_admin_access()) : ?>
                        <div class="feuer-tab-panel" data-tab-panel="settings-access">
                            <form method="post">
                                <?php wp_nonce_field('feuer_support_admin'); ?>
                                <input type="hidden" name="feuer_support_action" value="save_settings">
                                <h2>Zugriff auf das Plugin</h2>
                                <p class="description">Administratoren haben immer Vollzugriff. Für Redakteur und andere Rollen können Sie hier pro Bereich festlegen, was sichtbar und bedienbar ist.</p>
                                <?php $this->access_matrix($settings); ?>
                                <p><button class="button button-primary">Speichern</button></p>
                            </form>
                        </div>
                    <?php endif; ?>

                    <div class="feuer-tab-panel" data-tab-panel="settings-system">
                        <form method="post">
                            <?php wp_nonce_field('feuer_support_admin'); ?>
                            <input type="hidden" name="feuer_support_action" value="save_settings">
                            <h2>System</h2>
                            <?php $this->input('rate_limit_minutes', 'Captcha nach Wiederholung innerhalb Minuten', $settings['rate_limit_minutes'], 'number'); ?>
                            <h3>Spam-Schutz</h3>
                            <input type="hidden" name="antispam_honeypot_enabled" value="0">
                            <label class="feuer-check-label"><input type="checkbox" name="antispam_honeypot_enabled" value="1" <?php checked(! empty($settings['antispam_honeypot_enabled'])); ?>> Unsichtbares Honeypot-Feld aktivieren</label>
                            <div class="feuer-form-grid">
                                <label>
                                    <input type="hidden" name="turnstile_enabled" value="0">
                                    <span class="feuer-check-label"><input type="checkbox" name="turnstile_enabled" value="1" <?php checked(! empty($settings['turnstile_enabled'])); ?>> Cloudflare Turnstile aktivieren</span>
                                    <input class="regular-text" type="text" name="turnstile_site_key" value="<?php echo esc_attr((string) $settings['turnstile_site_key']); ?>" placeholder="Site Key">
                                </label>
                                <label>
                                    Turnstile Secret Key
                                    <input class="regular-text" type="password" name="turnstile_secret_key" value="" autocomplete="new-password" placeholder="<?php echo empty($settings['turnstile_secret_key']) ? 'Nicht gespeichert' : 'Gespeichert - leer lassen zum Beibehalten'; ?>">
                                </label>
                                <label>
                                    <input type="hidden" name="recaptcha_enabled" value="0">
                                    <span class="feuer-check-label"><input type="checkbox" name="recaptcha_enabled" value="1" <?php checked(! empty($settings['recaptcha_enabled'])); ?>> Google reCAPTCHA v2 aktivieren</span>
                                    <input class="regular-text" type="text" name="recaptcha_site_key" value="<?php echo esc_attr((string) $settings['recaptcha_site_key']); ?>" placeholder="Site Key">
                                </label>
                                <label>
                                    reCAPTCHA Secret Key
                                    <input class="regular-text" type="password" name="recaptcha_secret_key" value="" autocomplete="new-password" placeholder="<?php echo empty($settings['recaptcha_secret_key']) ? 'Nicht gespeichert' : 'Gespeichert - leer lassen zum Beibehalten'; ?>">
                                </label>
                            </div>
                            <p class="description">Turnstile und reCAPTCHA werden serverseitig geprüft. Aktivieren Sie nur einen externen Anbieter gleichzeitig.</p>
                            <div class="feuer-form-grid">
                                <?php $this->input('allowed_file_types', 'Erlaubte Dateiformate', $settings['allowed_file_types']); ?>
                                <?php $this->input('max_upload_mb', 'Maximale Dateigröße in MB', $settings['max_upload_mb'], 'number'); ?>
                            </div>
                            <p class="description">Dateiformate als kommagetrennte Liste, zum Beispiel <code>pdf,jpg,jpeg,png,doc,docx</code>.</p>
                            <p><button class="button button-primary">Speichern</button></p>
                        </form>
                        <div class="feuer-subform">
                            <h3>Plugin Update</h3>
                            <div class="feuer-update-status <?php echo ! empty($update['error']) ? 'is-error' : (! empty($update['update_available']) ? 'is-update' : 'is-current'); ?>">
                                <div><span>Installiert</span><strong><?php echo esc_html((string) $update['current_version']); ?></strong></div>
                                <div><span>Server</span><strong><?php echo esc_html((string) ($update['remote_version'] !== '' ? $update['remote_version'] : 'unbekannt')); ?></strong></div>
                                <div><span>WordPress getestet bis</span><strong><?php echo esc_html((string) ($update['tested'] !== '' ? $update['tested'] : 'unbekannt')); ?></strong></div>
                                <div><span>Status</span><strong><?php echo esc_html($this->update_status_label($update)); ?></strong></div>
                            </div>
                            <p>Plugin-Seite: <a href="<?php echo esc_url((string) $update['base_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string) $update['base_url']); ?></a></p>
                            <p class="description">Direkte Paket-URLs werden im Plugin nicht angezeigt. Updates laufen intern ueber den WordPress-Updater.</p>
                            <?php if ((string) $update['checked_at'] !== '') : ?><p>Letzte manuelle Prüfung: <strong><?php echo esc_html((string) $update['checked_at']); ?></strong></p><?php endif; ?>
                            <?php if (! empty($update['error'])) : ?><p class="notice notice-error inline"><?php echo esc_html((string) $update['error']); ?></p><?php endif; ?>
                            <form method="post">
                                <?php wp_nonce_field('feuer_support_admin'); ?>
                                <input type="hidden" name="feuer_support_action" value="check_updates">
                                <button class="button button-primary">Update prüfen</button>
                            </form>
                            <form method="post" class="feuer-update-install-form">
                                <?php wp_nonce_field('feuer_support_admin'); ?>
                                <input type="hidden" name="feuer_support_action" value="install_update">
                                <button class="button button-primary" <?php disabled(empty($update['update_available']) || ! empty($update['error'])); ?>>Update jetzt installieren</button>
                                <span class="description">Installiert die Version vom Entwickler-Server direkt über den WordPress-Updater.</span>
                            </form>
                        </div>
                    </div>

                    <div class="feuer-tab-panel" data-tab-panel="settings-backup">
                        <div class="feuer-subform">
                            <h2>Sicherung & Wiederherstellung</h2>
                            <p class="description">Die Sicherung enthält alle Plugin-Einstellungen, Abteilungen, Themen, Empfänger-E-Mails, Anfragen, Nachrichtenverlauf und Protokolle.</p>
                            <p class="description">Die Datei kann auch SMTP-Zugangsdaten und personenbezogene Daten enthalten. Bitte nur geschützt speichern.</p>
                            <form method="post">
                                <?php wp_nonce_field('feuer_support_admin'); ?>
                                <input type="hidden" name="feuer_support_action" value="download_backup">
                                <p><button class="button button-primary">Sicherung herunterladen</button></p>
                            </form>
                            <form method="post" class="feuer-subform">
                                <?php wp_nonce_field('feuer_support_admin'); ?>
                                <input type="hidden" name="feuer_support_action" value="download_tickets_backup">
                                <h3>Alle Anfragen sichern</h3>
                                <p class="description">Exportiert nur Abteilungen, Themen, alle Anfragen und den Nachrichtenverlauf als JSON. Gut fuer regelmaessige Archivierung ohne Systemeinstellungen und Protokolle.</p>
                                <p><button class="button">Alle Anfragen als JSON herunterladen</button></p>
                            </form>
                        </div>

                        <div class="feuer-subform">
                            <h3>Sicherung wiederherstellen</h3>
                            <p class="description">Beim Wiederherstellen werden die aktuellen Plugin-Daten komplett ersetzt: Einstellungen, Abteilungen, Themen, Anfragen, Verlauf und Protokolle.</p>
                            <p class="description">Datei-Uploads aus Anfragen bleiben nur dann vollständig nutzbar, wenn die zugehörigen Dateien im WordPress-Uploads-Ordner weiterhin vorhanden sind.</p>
                            <form method="post" enctype="multipart/form-data">
                                <?php wp_nonce_field('feuer_support_admin'); ?>
                                <input type="hidden" name="feuer_support_action" value="restore_backup">
                                <label>
                                    Sicherungsdatei (JSON)
                                    <input type="file" name="backup_file" accept=".json,application/json" required>
                                </label>
                                <label class="feuer-check-label">
                                    <input type="checkbox" name="backup_confirm" value="1" required>
                                    Aktuelle Plugin-Daten vollständig ersetzen
                                </label>
                                <p><button class="button">Sicherung wiederherstellen</button></p>
                            </form>
                        </div>
                    </div>

                    <div class="feuer-tab-panel" data-tab-panel="settings-shortcodes">
                        <h2>Shortcodes</h2>
                        <p><code>[feuer-kontaktform]</code> kompletter Wizard.</p>
                        <p><code>[feuer-kontaktform department="wehrfuehrung"]</code> direkt zu einer Abteilung.</p>
                        <p><code>[feuer-kontaktform department="wehrfuehrung" topic="mitglied-werden"]</code> direkt zu einem Thema.</p>
                        <p><code>[feuer-kontaktform-schnell department="wehrfuehrung" topic="allgemein"]</code> schnelle klassische Form.</p>
                        <p class="description">Links auf eine Seite mit eingebautem Formular können zusätzlich über URL-Parameter direkt zu einer Abteilung oder zu einem Thema springen.</p>
                        <p><code><?php echo esc_html($this->frontend_link_example(['feuer_department' => 'wehrfuehrung'])); ?></code></p>
                        <p><code><?php echo esc_html($this->frontend_link_example(['feuer_department' => 'wehrfuehrung', 'feuer_topic' => 'mitglied-werden'])); ?></code></p>
                        <p class="description">Ersetzen Sie <code>/ihre-formularseite/</code> durch die Seite, auf der der Shortcode eingebunden ist.</p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function telegram_page(): void
    {
        $this->ensure_access('settings_manage');
        $settings = $this->db->get_settings();
        $users = $this->db->get_telegram_users();
        $routes = $this->db->get_telegram_routes();
        $departments = $this->db->get_departments(true);
        $wp_users = get_users(['fields' => ['ID', 'display_name', 'user_email']]);
        $webhook_url = rest_url('feuer-kontaktform/v1/telegram/webhook');
        ?>
        <div class="wrap feuer-support-admin">
            <h1>Telegram-Arbeitsbereich</h1>
            <div class="feuer-admin-card">
                <h2>Bot-Verbindung</h2>
                <p class="description">Telegram wird nur als geschützter Benachrichtigungs- und Bedienkanal verwendet. Die Anfragen bleiben in WordPress gespeichert.</p>
                <details class="feuer-telegram-help" open>
                    <summary>Bot in Telegram erstellen – Anleitung</summary>
                    <ol>
                        <li>Öffnen Sie Telegram und suchen Sie nach <strong>@BotFather</strong>.</li>
                        <li>Senden Sie <code>/newbot</code>, vergeben Sie einen Anzeigenamen und einen Benutzernamen, der auf <code>bot</code> endet.</li>
                        <li>Kopieren Sie den von @BotFather angezeigten <strong>Bot-Token</strong> und fügen Sie ihn unten ein. Der Token wird verschlüsselt gespeichert und nie wieder angezeigt.</li>
                        <li>Warten Sie auf die grüne Live-Prüfung, speichern Sie, erzeugen Sie für jeden Mitarbeiter einen Kopplungscode und senden Sie ihn privat mit <code>/start CODE</code> an den Bot.</li>
                    </ol>
                </details>
                <form method="post">
                    <?php wp_nonce_field('feuer_support_admin'); ?>
                    <input type="hidden" name="feuer_support_action" value="save_settings">
                    <input type="hidden" name="telegram_webhook_secret" value="<?php echo esc_attr((string) $settings['telegram_webhook_secret']); ?>">
                    <p><label class="feuer-check-label"><input type="hidden" name="telegram_enabled" value="0"><input type="checkbox" name="telegram_enabled" value="1" <?php checked(! empty($settings['telegram_enabled'])); ?>> Telegram-Benachrichtigungen aktivieren</label></p>
                    <p><label>Bot-Token<br><input class="regular-text" type="password" name="telegram_bot_token" value="" autocomplete="new-password" data-telegram-token aria-describedby="feuer-telegram-token-status" placeholder="<?php echo empty($settings['telegram_bot_token']) ? 'Nicht gespeichert' : 'Gespeichert – leer lassen zum Beibehalten'; ?>"><span id="feuer-telegram-token-status" class="description" data-telegram-token-status aria-live="polite">Token einfügen: Die Verbindung wird automatisch geprüft, ohne den Token zu speichern.</span></label></p>
                    <p><label>Datenschutzmodus<br><select name="telegram_privacy_mode"><option value="summary" <?php selected((string) $settings['telegram_privacy_mode'], 'summary'); ?>>Zusammenfassung mit E-Mail-Adresse</option><option value="masked" <?php selected((string) $settings['telegram_privacy_mode'], 'masked'); ?>>E-Mail-Adresse maskieren</option></select></label></p>
                    <p><button class="button button-primary">Telegram-Einstellungen speichern</button></p>
                </form>
                <h3>Webhook</h3>
                <p class="description">Diese URL wird nach dem Speichern des Bot-Tokens bei Telegram als HTTPS-Webhook hinterlegt. Das geheime Header-Token wird serverseitig geprüft.</p>
                <p><code><?php echo esc_html($webhook_url); ?></code></p>
            </div>
            <div class="feuer-admin-card">
                <h2>Gekoppelte Empfänger</h2>
                <p class="description">Für die erste Kopplung erzeugen Sie einen zeitlich begrenzten Code. Der Mitarbeiter sendet ihn privat mit <code>/start CODE</code> an den Bot; anschließend kann er unten einer Abteilung zugeordnet werden.</p>
                <?php if ($users === []) : ?>
                    <p>Noch keine Telegram-Empfänger gekoppelt.</p>
                <?php else : ?>
                    <table class="widefat striped"><thead><tr><th>Telegram</th><th>Profil</th><th>Status</th><th>Letzte Aktivität</th></tr></thead><tbody>
                    <?php foreach ($users as $user) : ?><tr><td><?php echo esc_html((string) $user['display_name']); ?><br><small><?php echo esc_html((string) $user['telegram_user_id']); ?></small></td><td><?php echo esc_html(trim((string) $user['first_name'] . ' ' . (string) $user['last_name']) ?: 'Noch nicht eingerichtet'); ?></td><td><?php echo ! empty($user['is_active']) ? 'Aktiv' : 'Kopplung offen'; ?></td><td><?php echo esc_html((string) $user['last_seen_at']); ?></td></tr><?php endforeach; ?>
                    </tbody></table>
                <?php endif; ?>
                <h3>Empfänger koppeln</h3>
                <form method="post" class="feuer-form-grid">
                    <?php wp_nonce_field('feuer_support_admin'); ?>
                    <input type="hidden" name="feuer_support_action" value="create_telegram_pairing">
                    <label>WordPress-Benutzer<select name="telegram_wp_user_id" required><option value="">Bitte wählen</option><?php foreach ($wp_users as $wp_user) : ?><option value="<?php echo esc_attr((string) $wp_user->ID); ?>"><?php echo esc_html($wp_user->display_name . ' – ' . $wp_user->user_email); ?></option><?php endforeach; ?></select></label>
                    <fieldset><legend>Erlaubte Telegram-Aktionen</legend><?php foreach (['open_browser' => 'Im Browser öffnen', 'claim' => 'Übernehmen', 'read' => 'Als gelesen', 'reply' => 'Antworten', 'spam' => 'Als Spam markieren'] as $permission => $label) : ?><label class="feuer-check-label"><input type="checkbox" name="telegram_permissions[]" value="<?php echo esc_attr($permission); ?>" <?php checked($permission !== 'spam'); ?>> <?php echo esc_html($label); ?></label><?php endforeach; ?></fieldset>
                    <p><button class="button">Kopplungscode erzeugen</button></p>
                </form>
                <?php if ((string) ($_GET['feuer-message'] ?? '') === 'telegram_pairing_created') : ?><div class="notice notice-success inline"><p>Kopplungscode: <strong><code><?php echo esc_html((string) wp_unslash($_GET['feuer-detail'] ?? '')); ?></code></strong>. Der Mitarbeiter sendet privat <code>/start CODE</code> an den Bot.</p></div><?php endif; ?>
            </div>
            <div class="feuer-admin-card">
                <h2>Abteilungsrouting</h2>
                <p class="description">Jede neue Anfrage wird nur an die hier zugeordnete, aktiv gekoppelte Person gesendet.</p>
                <form method="post" class="feuer-form-grid">
                    <?php wp_nonce_field('feuer_support_admin'); ?>
                    <input type="hidden" name="feuer_support_action" value="save_telegram_route">
                    <label>Abteilung<select name="telegram_department_id" required><option value="">Bitte wählen</option><?php foreach ($departments as $department) : ?><option value="<?php echo esc_attr((string) $department['id']); ?>"><?php echo esc_html((string) $department['name']); ?></option><?php endforeach; ?></select></label>
                    <label>Telegram-Empfänger<select name="telegram_user_id" required><option value="">Bitte wählen</option><?php foreach ($users as $user) : ?><?php if (! empty($user['is_active'])) : ?><option value="<?php echo esc_attr((string) $user['telegram_user_id']); ?>"><?php echo esc_html((string) $user['display_name']); ?></option><?php endif; ?><?php endforeach; ?></select></label>
                    <input type="hidden" name="telegram_topic_id" value="0">
                    <fieldset><legend>Sichtbare Aktionen</legend><?php foreach (['open_browser' => 'Im Browser öffnen', 'claim' => 'Übernehmen', 'read' => 'Als gelesen', 'spam' => 'Als Spam markieren'] as $permission => $label) : ?><label class="feuer-check-label"><input type="checkbox" name="telegram_route_actions[]" value="<?php echo esc_attr($permission); ?>" checked> <?php echo esc_html($label); ?></label><?php endforeach; ?></fieldset>
                    <p><button class="button">Routing speichern</button></p>
                </form>
                <?php if ($routes !== []) : ?><table class="widefat striped"><thead><tr><th>Abteilung</th><th>Empfänger</th><th>Aktionen</th></tr></thead><tbody><?php foreach ($routes as $route) : ?><tr><td><?php echo esc_html((string) $route['department_name']); ?></td><td><?php echo esc_html((string) $route['display_name']); ?></td><td><?php echo esc_html(implode(', ', (array) $route['actions'])); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function logs_page(): void
    {
        $this->ensure_access('logs_view');
        $logs = $this->db->get_logs();
        ?>
        <div class="wrap feuer-support-admin">
            <h1>Protokolle</h1>
            <div class="feuer-admin-card">
                <h2>Aktivitäten im Plugin</h2>
                <p class="description">Hier steht in normaler Sprache, was im Plugin passiert ist: Einstellungen, Abteilungen, Themen, Update-Prüfungen und neue Anfragen.</p>
                <div class="feuer-log-list">
                    <?php if ($logs === []) : ?>
                        <p>Noch keine Protokolle vorhanden.</p>
                    <?php endif; ?>
                    <?php foreach ($logs as $log) : ?>
                        <?php $user = ! empty($log['actor_id']) ? get_user_by('id', (int) $log['actor_id']) : null; ?>
                        <article class="feuer-log-item">
                            <div class="feuer-log-main">
                                <strong><?php echo esc_html($this->log_action_label((string) $log['action'])); ?></strong>
                                <span><?php echo esc_html($this->object_type_label((string) $log['object_type'])); ?><?php echo ! empty($log['object_id']) ? ' #' . esc_html((string) $log['object_id']) : ''; ?></span>
                            </div>
                            <div class="feuer-log-meta">
                                <span><?php echo esc_html((string) $log['created_at']); ?></span>
                                <span>Admin: <?php echo esc_html($user ? $this->user_display_name($user) : ((int) $log['actor_id'] > 0 ? '#' . (string) $log['actor_id'] : 'System')); ?></span>
                                <span>IP: <?php echo esc_html((string) $log['ip_address']); ?></span>
                            </div>
                            <?php $context = $this->format_context((string) ($log['context_json'] ?? '')); ?>
                            <?php if ($context !== '') : ?><p class="feuer-log-context"><?php echo esc_html($context); ?></p><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function dashboard_widget(): void
    {
        if (! $this->user_has_access('tickets_view')) {
            return;
        }

        wp_add_dashboard_widget(
            'feuer_kontaktform_dashboard',
            'Letzte Kontaktform-Anfragen',
            [$this, 'render_dashboard_widget']
        );
    }

    public function render_dashboard_widget(): void
    {
        if (! $this->user_has_access('tickets_view')) {
            $this->deny_access();
        }

        $tickets = $this->db->get_tickets(5);
        if ($tickets === []) {
            echo '<p>Noch keine Anfragen vorhanden.</p>';
            return;
        }

        echo '<ul class="feuer-dashboard-ticket-list">';
        foreach ($tickets as $ticket) {
            $url = admin_url('admin.php?page=' . self::MENU_SLUG . '&feuer_search=' . rawurlencode((string) $ticket['ticket_code']));
            echo '<li><a href="' . esc_url($url) . '"><strong>' . esc_html((string) $ticket['ticket_code']) . '</strong></a> ';
            $dashboard_subject = (string) ($ticket['subject'] ?? '');
            if ($dashboard_subject === '') {
                $dashboard_subject = (string) ($ticket['topic_title'] ?? '');
            }
            if ($dashboard_subject === '') {
                $dashboard_subject = 'Anfrage';
            }
            echo esc_html($dashboard_subject);
            echo '<br><small>' . esc_html((string) $ticket['created_at']) . ' · ' . esc_html((string) $ticket['sender_name']) . '</small></li>';
        }
        echo '</ul>';
        echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)) . '">Alle Anfragen öffnen</a></p>';
    }

    private function log_action_label(string $action): string
    {
        $labels = [
            'settings_saved' => 'Einstellungen gespeichert',
            'test_mail_sent' => 'Testmail gesendet',
            'test_mail_failed' => 'Testmail konnte nicht gesendet werden',
            'inbound_mail_checked' => 'Ticket-Postfach geprüft',
            'inbound_mail_check_failed' => 'Ticket-Postfach konnte nicht geprüft werden',
            'inbound_mail_imported' => 'E-Mail-Antwort in Anfrage importiert',
            'inbound_mail_ignored' => 'Eingehende E-Mail ignoriert',
            'inbound_mail_notification_failed' => 'Abteilung konnte über neue Antwort nicht informiert werden',
            'inbound_department_reply_imported' => 'E-Mail-Antwort der Abteilung importiert und weitergeleitet',
            'inbound_department_reply_failed' => 'E-Mail-Antwort der Abteilung konnte nicht weitergeleitet werden',
            'direct_email_ticket_created' => 'Direkte E-Mail als neue Anfrage angelegt',
            'direct_email_ticket_failed' => 'Direkte E-Mail konnte nicht als Anfrage gespeichert werden',
            'direct_email_forward_failed' => 'Direkte E-Mail gespeichert, aber Weiterleitung fehlgeschlagen',
            'ticket_access_issued' => 'Geschützter Anfragezugriff erstellt',
            'ticket_access_revoked' => 'Geschützter Anfragezugriff widerrufen',
            'ticket_department_replied' => 'Abteilung hat über den geschützten Link geantwortet',
            'ticket_department_reply_failed' => 'Antwort über den geschützten Link fehlgeschlagen',
            'update_checked' => 'Update geprüft',
            'update_installed' => 'Update installiert',
            'update_install_failed' => 'Update-Installation fehlgeschlagen',
            'backup_exported' => 'Sicherung exportiert',
            'backup_restored' => 'Sicherung wiederhergestellt',
            'backup_restore_failed' => 'Sicherung konnte nicht wiederhergestellt werden',
            'department_name_required' => 'Abteilung konnte nicht gespeichert werden',
            'department_slug_invalid' => 'Abteilung konnte nicht gespeichert werden',
            'department_email_invalid' => 'Abteilung konnte nicht gespeichert werden',
            'department_slug_exists' => 'Abteilung konnte nicht gespeichert werden',
            'department_update_failed' => 'Abteilung konnte nicht gespeichert werden',
            'department_insert_failed' => 'Abteilung konnte nicht gespeichert werden',
            'department_insert_missing_id' => 'Abteilung konnte nicht gespeichert werden',
            'department_created' => 'Abteilung erstellt',
            'department_updated' => 'Abteilung bearbeitet',
            'department_deleted' => 'Abteilung deaktiviert',
            'department_moved' => 'Abteilung verschoben',
            'default_department_created' => 'Standard-Abteilung angelegt',
            'topic_department_missing' => 'Thema konnte nicht gespeichert werden',
            'topic_title_required' => 'Thema konnte nicht gespeichert werden',
            'topic_slug_invalid' => 'Thema konnte nicht gespeichert werden',
            'topic_email_invalid' => 'Thema konnte nicht gespeichert werden',
            'topic_slug_exists' => 'Thema konnte nicht gespeichert werden',
            'topic_update_failed' => 'Thema konnte nicht gespeichert werden',
            'topic_insert_failed' => 'Thema konnte nicht gespeichert werden',
            'topic_insert_missing_id' => 'Thema konnte nicht gespeichert werden',
            'topic_created' => 'Thema erstellt',
            'topic_updated' => 'Thema bearbeitet',
            'topic_deleted' => 'Thema deaktiviert',
            'ticket_created' => 'Neue Anfrage gespeichert',
            'ticket_create_failed' => 'Anfrage konnte nicht gespeichert werden',
            'ticket_delivery_accepted' => 'Abteilungs-E-Mail vom Mailserver angenommen',
            'ticket_delivery_failed' => 'Abteilungs-E-Mail konnte nicht gesendet werden',
            'ticket_delivery_confirmed' => 'Empfang durch die Abteilung bestätigt',
            'ticket_auto_reply_failed' => 'Autoantwort konnte nicht gesendet werden',
            'ticket_replied' => 'Antwort gesendet',
            'ticket_reply_failed' => 'Antwort konnte nicht gesendet werden',
        ];

        return $labels[$action] ?? $action;
    }

    private function object_type_label(string $type): string
    {
        $labels = [
            'settings' => 'Einstellungen',
            'mailbox' => 'Ticket-Postfach',
            'update' => 'Update',
            'backup' => 'Sicherung',
            'validation' => 'Validierung',
            'department' => 'Abteilung',
            'topic' => 'Thema',
            'ticket' => 'Anfrage',
        ];

        return $labels[$type] ?? ($type !== '' ? $type : 'Plugin');
    }

    private function format_context(string $json): string
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || $decoded === []) {
            return '';
        }

        $parts = [];
        foreach ($decoded as $key => $value) {
            if (is_array($value)) {
                $value = wp_json_encode($value);
            } elseif (is_bool($value)) {
                $value = $value ? 'ja' : 'nein';
            }

            $parts[] = (string) $key . ': ' . (string) $value;
        }

        return implode(' | ', $parts);
    }

    private function update_status_label(array $update): string
    {
        if (! empty($update['error'])) {
            return 'Prüfung fehlgeschlagen';
        }

        if (! empty($update['update_available'])) {
            return 'Update verfügbar';
        }

        if ((string) ($update['remote_version'] ?? '') === '') {
            return 'Noch keine Server-Version gelesen';
        }

        return 'Aktuell, kein Update verfügbar';
    }

    private function access_sections(): array
    {
        return [
            'tickets_view' => 'Anfragen ansehen',
            'tickets_reply' => 'Anfragen beantworten',
            'departments_manage' => 'Abteilungen & Themen verwalten',
            'settings_manage' => 'Einstellungen verwalten',
            'logs_view' => 'Protokolle ansehen',
        ];
    }

    private function access_matrix(array $settings): void
    {
        global $wp_roles;

        $sections = $this->access_sections();
        $role_access = is_array($settings['role_access'] ?? null) ? $settings['role_access'] : [];
        $roles = $wp_roles instanceof WP_Roles ? $wp_roles->roles : [];
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Rolle</th>
                    <?php foreach ($sections as $label) : ?>
                        <th><?php echo esc_html($label); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Administrator</strong><br><span class="description">Immer Vollzugriff</span></td>
                    <?php foreach (array_keys($sections) as $permission) : ?>
                        <td><input type="checkbox" checked disabled></td>
                    <?php endforeach; ?>
                </tr>
                <?php foreach ($roles as $role_key => $role) : ?>
                    <?php if ($role_key === 'administrator') { continue; } ?>
                    <tr>
                        <td><strong><?php echo esc_html((string) ($role['name'] ?? $role_key)); ?></strong><br><code><?php echo esc_html($role_key); ?></code></td>
                        <?php foreach (array_keys($sections) as $permission) : ?>
                            <?php $enabled = ! empty($role_access[$role_key][$permission]); ?>
                            <td>
                                <input type="hidden" name="role_access[<?php echo esc_attr($role_key); ?>][<?php echo esc_attr($permission); ?>]" value="0">
                                <input type="checkbox" name="role_access[<?php echo esc_attr($role_key); ?>][<?php echo esc_attr($permission); ?>]" value="1" <?php checked($enabled); ?>>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description">Hinweis: Wenn eine Rolle <strong>Anfragen beantworten</strong> darf, wird <strong>Anfragen ansehen</strong> automatisch mit aktiviert.</p>
        <?php
    }

    private function action_permission(string $action): string
    {
        return match ($action) {
            'save_settings',
            'send_test_mail',
            'check_inbound_mail',
            'check_updates',
            'install_update',
            'download_backup',
            'download_tickets_backup',
            'restore_backup' => 'settings_manage',
            'create_telegram_pairing',
            'save_telegram_route' => 'settings_manage',
            'save_department',
            'delete_department',
            'move_department',
            'save_topic',
            'delete_topic' => 'departments_manage',
            'reply_ticket',
            'start_ticket_work',
            'resend_department_notification',
            'revoke_department_access' => 'tickets_reply',
            default => '',
        };
    }

    private function user_can_open_plugin(): bool
    {
        if ($this->user_has_admin_access()) {
            return true;
        }

        foreach (array_keys($this->access_sections()) as $permission) {
            if ($this->user_has_access($permission)) {
                return true;
            }
        }

        return false;
    }

    private function user_has_access(string $permission): bool
    {
        if ($this->user_has_admin_access()) {
            return true;
        }

        $settings = $this->db->get_settings();
        $role_access = is_array($settings['role_access'] ?? null) ? $settings['role_access'] : [];
        $user = wp_get_current_user();

        foreach ((array) ($user->roles ?? []) as $role_key) {
            if (! empty($role_access[$role_key][$permission])) {
                return true;
            }
        }

        return false;
    }

    private function user_has_admin_access(): bool
    {
        return current_user_can('manage_options');
    }

    private function ensure_access(string $permission): void
    {
        if (! $this->user_has_access($permission)) {
            $this->deny_access();
        }
    }

    private function deny_access(): void
    {
        wp_die(
            esc_html__('Sie haben keinen Zugriff auf diesen Bereich des Plugins.', 'feuer-kontaktform'),
            'Kontaktform',
            ['response' => 403]
        );
    }

    private function sanitize_role_access($source): array
    {
        global $wp_roles;

        $sections = array_keys($this->access_sections());
        $input = is_array($source) ? $source : [];
        $roles = $wp_roles instanceof WP_Roles ? array_keys($wp_roles->roles) : [];
        $sanitized = [];

        foreach ($roles as $role_key) {
            if ($role_key === 'administrator') {
                continue;
            }

            $row = [];
            foreach ($sections as $permission) {
                $row[$permission] = ! empty($input[$role_key][$permission]) ? 1 : 0;
            }

            if (! empty($row['tickets_reply'])) {
                $row['tickets_view'] = 1;
            }

            $sanitized[$role_key] = $row;
        }

        return $sanitized;
    }

    private function user_display_name($user): string
    {
        if (! $user || empty($user->ID)) {
            return 'System';
        }

        $name = trim((string) ($user->first_name ?? '') . ' ' . (string) ($user->last_name ?? ''));
        if ($name !== '') {
            return $name;
        }

        if (! empty($user->display_name)) {
            return (string) $user->display_name;
        }

        return (string) ($user->user_login ?? ('#' . (string) $user->ID));
    }

    private function message_actor_label(array $message, array $ticket): string
    {
        $raw = json_decode((string) ($message['raw_json'] ?? ''), true);
        $raw = is_array($raw) ? $raw : [];
        $direction = (string) ($message['direction'] ?? '');

        if ($direction === 'inbound') {
            return (string) ($raw['sender_name'] ?? $ticket['sender_name'] ?? $message['from_email'] ?? 'Absender');
        }

        if ($direction === 'outbound') {
            return (string) ($raw['actor_name'] ?? $message['from_email'] ?? 'Antwort aus dem Plugin');
        }

        return (string) ($message['from_email'] ?: $direction);
    }

    private function default_reply_template(array $ticket): string
    {
        return "Hallo {{name}},\n\n\n\nMit freundlichen Grüßen\n{{department}}\n{{site_name}}\n{{site_url}}";
    }

    private function department_shortcodes(array $department, array $topics): void
    {
        ?>
        <div class="feuer-shortcode-list">
            <h3>Shortcodes für <?php echo esc_html((string) $department['name']); ?></h3>
            <p class="description">Diese Shortcodes sind direkt auf diese Abteilung oder ein Thema vorkonfiguriert.</p>
            <div><code>[feuer-kontaktform department="<?php echo esc_attr((string) $department['slug']); ?>"]</code><span>Wizard direkt für diese Abteilung.</span></div>
            <div><code>[feuer-kontaktform-schnell department="<?php echo esc_attr((string) $department['slug']); ?>"]</code><span>Schnellformular für diese Abteilung.</span></div>
            <div><code><?php echo esc_html($this->frontend_link_example(['feuer_department' => (string) $department['slug']])); ?></code><span>Direkter Link in diese Abteilung.</span></div>
            <?php foreach ($topics as $topic) : ?>
                <div><code>[feuer-kontaktform department="<?php echo esc_attr((string) $department['slug']); ?>" topic="<?php echo esc_attr((string) $topic['slug']); ?>"]</code><span><?php echo esc_html((string) $topic['title']); ?></span></div>
                <div><code>[feuer-kontaktform-schnell department="<?php echo esc_attr((string) $department['slug']); ?>" topic="<?php echo esc_attr((string) $topic['slug']); ?>"]</code><span>Schnellformular: <?php echo esc_html((string) $topic['title']); ?></span></div>
                <div><code><?php echo esc_html($this->frontend_link_example(['feuer_department' => (string) $department['slug'], 'feuer_topic' => (string) $topic['slug']])); ?></code><span>Direkter Link in <?php echo esc_html((string) $topic['title']); ?>.</span></div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function frontend_link_example(array $args): string
    {
        return add_query_arg($args, home_url('/ihre-formularseite/'));
    }

    private function delivery_status_label(string $status): string
    {
        return match ($status) {
            'accepted' => 'Vom Mailserver angenommen',
            'confirmed' => 'Vom Empfänger bestätigt',
            'failed' => 'Versand fehlgeschlagen',
            default => 'Noch nicht geprüft',
        };
    }

    private function delivery_status_class(string $status): string
    {
        return match ($status) {
            'accepted' => 'is-accepted',
            'confirmed' => 'is-confirmed',
            'failed' => 'is-failed',
            default => 'is-pending',
        };
    }

    private function ticket_subject(array $ticket): string
    {
        return '[Anfrage Nr. ' . (string) $ticket['ticket_code'] . '] ' . (string) ($ticket['topic_title'] ?? 'Anfrage') . ' - ' . (string) ($ticket['subject'] ?: 'Ohne Betreff');
    }

    private function field_label(string $key): string
    {
        $labels = [
            'vorname' => 'Vorname',
            'nachname' => 'Nachname',
            'titel' => 'Titel / Betreff',
            'telefon' => 'Telefon',
            'email' => 'E-Mail',
            'nachricht' => 'Nachricht',
        ];

        return $labels[$key] ?? ucfirst(str_replace(['_', '-'], ' ', $key));
    }

    private function field_value($value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        return (string) $value;
    }

    private function department_form(?array $department = null): void
    {
        $department = $department ?? ['id' => 0, 'name' => '', 'slug' => '', 'email' => '', 'description' => '', 'contact_name' => '', 'contact_phone' => '', 'contact_area' => '', 'contact_description' => '', 'contact_public' => false, 'contact_visibility' => 'protected', 'sort_order' => 0, 'is_active' => true];
        ?>
        <form method="post" class="feuer-inline-form">
            <?php wp_nonce_field('feuer_support_admin'); ?>
            <input type="hidden" name="feuer_support_action" value="save_department">
            <input type="hidden" name="department_id" value="<?php echo esc_attr((string) $department['id']); ?>">
            <div class="feuer-form-grid">
                <label>Name<input class="regular-text" type="text" name="department_name" value="<?php echo esc_attr((string) $department['name']); ?>" data-slug-source></label>
                <label>Kurzname / Slug<input class="regular-text" type="text" name="department_slug" value="<?php echo esc_attr((string) $department['slug']); ?>" data-slug-target></label>
                <?php $this->input('department_email', 'E-Mail-Empfänger', $department['email'], 'email'); ?>
                <?php $this->input('department_sort_order', 'Sortierung', $department['sort_order'], 'number'); ?>
            </div>
            <?php if ((string) $department['slug'] !== '') : ?>
                <p class="description">Direkt-Link zu dieser Abteilung: <code><?php echo esc_html($this->frontend_link_example(['feuer_department' => (string) $department['slug']])); ?></code></p>
            <?php endif; ?>
            <label>Beschreibung<textarea class="large-text" name="department_description" rows="2"><?php echo esc_textarea((string) $department['description']); ?></textarea></label>
            <fieldset class="feuer-contact-publish-settings"><legend>Ansprechpartner veröffentlichen</legend>
                <p class="description">Diese Daten werden nur angezeigt, wenn die Veröffentlichung freigegeben ist. Bei „Geschützt“ bleiben E-Mail und Telefon aus dem HTML und werden erst nach einer Sicherheitsfrage geladen.</p>
                <div class="feuer-form-grid"><label>Name<input class="regular-text" type="text" name="contact_name" value="<?php echo esc_attr((string) ($department['contact_name'] ?? '')); ?>"></label><label>Telefon<input class="regular-text" type="tel" name="contact_phone" value="<?php echo esc_attr((string) ($department['contact_phone'] ?? '')); ?>"></label><label>Bereich<input class="regular-text" type="text" name="contact_area" value="<?php echo esc_attr((string) ($department['contact_area'] ?? '')); ?>"></label></div>
                <label>Beschreibung des Ansprechpartners<textarea class="large-text" name="contact_description" rows="3"><?php echo esc_textarea((string) ($department['contact_description'] ?? '')); ?></textarea></label>
                <label>Anzeige der Kontaktdaten<select name="contact_visibility"><option value="protected" <?php selected((string) ($department['contact_visibility'] ?? 'protected'), 'protected'); ?>>Geschützt nach Sicherheitsprüfung</option><option value="public" <?php selected((string) ($department['contact_visibility'] ?? 'protected'), 'public'); ?>>Direkt öffentlich anzeigen</option></select></label>
                <label class="feuer-check-label"><input type="checkbox" name="contact_public" value="1" <?php checked(! empty($department['contact_public'])); ?>> Veröffentlichung für Besucher freigeben</label>
            </fieldset>
            <label><input type="checkbox" name="department_is_active" value="1" <?php checked(! empty($department['is_active'])); ?>> aktiv</label>
            <p><button class="button button-primary">Abteilung speichern</button></p>
        </form>
        <?php if ((int) $department['id'] > 0) : ?>
            <form method="post">
                <?php wp_nonce_field('feuer_support_admin'); ?>
                <input type="hidden" name="feuer_support_action" value="delete_department">
                <input type="hidden" name="department_id" value="<?php echo esc_attr((string) $department['id']); ?>">
                <button class="button button-link-delete">Abteilung deaktivieren</button>
            </form>
        <?php endif; ?>
        <?php
    }

    private function topic_form(array $department, ?array $topic = null): void
    {
        $topic = $topic ?? [
            'id' => 0,
            'department_id' => (int) $department['id'],
            'title' => '',
            'slug' => '',
            'email_override' => '',
            'auto_reply_enabled' => true,
            'auto_reply_format' => 'html',
            'auto_subject' => Feuer_Kontaktform_Support_DB::default_auto_subject(),
            'auto_body' => Feuer_Kontaktform_Support_DB::default_auto_body(),
            'auto_html' => Feuer_Kontaktform_Support_DB::default_auto_html_body(),
            'sort_order' => 0,
            'is_active' => true,
            'fields' => Feuer_Kontaktform_Support_DB::default_fields(),
        ];
        ?>
        <div class="feuer-topic-box">
            <h3><?php echo (int) $topic['id'] > 0 ? esc_html((string) $topic['title']) : 'Neues Thema konfigurieren'; ?></h3>
            <form method="post" data-topic-form>
                <?php wp_nonce_field('feuer_support_admin'); ?>
                <input type="hidden" name="feuer_support_action" value="save_topic">
                <input type="hidden" name="department_id" value="<?php echo esc_attr((string) $department['id']); ?>">
                <input type="hidden" name="topic_id" value="<?php echo esc_attr((string) $topic['id']); ?>">
                <div class="feuer-form-grid">
                    <label>Thema<input class="regular-text" type="text" name="topic_title" value="<?php echo esc_attr((string) $topic['title']); ?>" data-slug-source></label>
                    <label>Kurzname / Slug<input class="regular-text" type="text" name="topic_slug" value="<?php echo esc_attr((string) $topic['slug']); ?>" data-slug-target></label>
                    <?php $this->input('topic_email_override', 'E-Mail falls abweichend', $topic['email_override'], 'email'); ?>
                    <?php $this->input('topic_sort_order', 'Sortierung', $topic['sort_order'], 'number'); ?>
                </div>
                <?php if ((string) $department['slug'] !== '' && (string) $topic['slug'] !== '') : ?>
                    <p class="description">Direkt-Link zu diesem Thema: <code><?php echo esc_html($this->frontend_link_example(['feuer_department' => (string) $department['slug'], 'feuer_topic' => (string) $topic['slug']])); ?></code></p>
                <?php endif; ?>
                <div class="feuer-topic-auto-controls">
                    <label class="feuer-check-label"><input type="checkbox" name="topic_auto_reply_enabled" value="1" <?php checked(! empty($topic['auto_reply_enabled'])); ?>> Autoantwort für dieses Thema senden</label>
                    <div class="feuer-radio-row">
                        <span>Antwortformat</span>
                        <label><input type="radio" name="topic_auto_reply_format" value="text" <?php checked((string) ($topic['auto_reply_format'] ?? 'html'), 'text'); ?>> Text</label>
                        <label><input type="radio" name="topic_auto_reply_format" value="html" <?php checked((string) ($topic['auto_reply_format'] ?? 'html'), 'html'); ?>> HTML</label>
                    </div>
                </div>
                <?php $this->input('topic_auto_subject', 'Autoantwort Betreff', $topic['auto_subject']); ?>
                <div class="feuer-auto-reply-grid">
                    <label>
                        Autoantwort Text
                        <span class="description">Plain-Text Fallback für einfache Mailprogramme.</span>
                        <textarea class="large-text code" name="topic_auto_body" rows="11"><?php echo esc_textarea((string) $topic['auto_body']); ?></textarea>
                    </label>
                    <div class="feuer-email-editor" data-email-editor>
                        <div class="feuer-email-editor-head"><div><strong>Autoantwort HTML</strong><span class="description">HTML-Vorlage mit sicherem Live-Preview. Skripte werden im Preview blockiert.</span></div><div class="feuer-email-editor-tabs" role="tablist"><button type="button" class="button is-active" data-email-editor-mode="code" role="tab">HTML-Code</button><button type="button" class="button" data-email-editor-mode="preview" role="tab">Vorschau</button></div></div>
                        <textarea class="large-text code" name="topic_auto_html" rows="16" data-email-editor-source><?php echo esc_textarea((string) ($topic['auto_html'] ?? Feuer_Kontaktform_Support_DB::default_auto_html_body())); ?></textarea>
                        <iframe class="feuer-email-editor-preview" data-email-editor-preview title="Vorschau der HTML-E-Mail" sandbox="" hidden></iframe>
                        <p class="description">Formatierte E-Mail mit Logo, Website-Name und Anfrage Nr. Platzhalter können über die Schaltflächen unten eingefügt werden.</p>
                    </div>
                </div>
                <?php $this->auto_reply_macro_help(); ?>
                <label><input type="checkbox" name="topic_is_active" value="1" <?php checked(! empty($topic['is_active'])); ?>> aktiv</label>
                <h4>Felder für dieses Thema</h4>
                <div data-fields-list>
                    <?php foreach ((array) $topic['fields'] as $field) : ?>
                        <?php $this->field_row($field); ?>
                    <?php endforeach; ?>
                </div>
                <p><button type="button" class="button" data-add-field>Feld hinzufügen</button></p>
                <p><button class="button button-primary">Thema speichern</button></p>
            </form>
            <?php if ((int) $topic['id'] > 0) : ?>
                <form method="post">
                    <?php wp_nonce_field('feuer_support_admin'); ?>
                    <input type="hidden" name="feuer_support_action" value="delete_topic">
                    <input type="hidden" name="topic_id" value="<?php echo esc_attr((string) $topic['id']); ?>">
                    <button class="button button-link-delete">Thema deaktivieren</button>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private function field_row(array $field): void
    {
        ?>
        <div class="feuer-field-row" data-field-row>
            <input name="topic_fields[key][]" value="<?php echo esc_attr((string) ($field['key'] ?? '')); ?>" placeholder="key">
            <input name="topic_fields[label][]" value="<?php echo esc_attr((string) ($field['label'] ?? '')); ?>" placeholder="Label">
            <select name="topic_fields[type][]">
                <?php foreach (['text', 'email', 'tel', 'textarea', 'select', 'file'] as $type) : ?>
                    <option value="<?php echo esc_attr($type); ?>" <?php selected((string) ($field['type'] ?? 'text'), $type); ?>><?php echo esc_html($type); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="topic_fields[width][]">
                <?php foreach (['full', 'half', 'third'] as $width) : ?>
                    <option value="<?php echo esc_attr($width); ?>" <?php selected((string) ($field['width'] ?? 'full'), $width); ?>><?php echo esc_html($width); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="topic_fields[required][]" value="<?php echo ! empty($field['required']) ? '1' : '0'; ?>" data-required-hidden>
            <label><input type="checkbox" value="1" data-required-checkbox <?php checked(! empty($field['required'])); ?>> Pflicht</label>
            <input name="topic_fields[options][]" value="<?php echo esc_attr(implode(', ', (array) ($field['options'] ?? []))); ?>" placeholder="Optionen bei select">
            <div class="feuer-field-actions">
                <button type="button" class="button" data-move-field="up">Hoch</button>
                <button type="button" class="button" data-move-field="down">Runter</button>
                <button type="button" class="button" data-remove-field>Entfernen</button>
            </div>
        </div>
        <?php
    }

    private function auto_reply_macro_help(): void
    {
        ?>
        <div class="feuer-macro-help">
            <h4>Shortcodes für Autoantworten</h4>
            <p class="description">Diese Platzhalter können im Betreff, im Text und in der HTML-Vorlage verwendet werden.</p>
            <div class="feuer-macro-grid">
                <?php foreach (Feuer_Kontaktform_Support_DB::auto_reply_macros() as $macro => $description) : ?>
                    <div><button type="button" class="button feuer-macro-button" data-insert-macro="<?php echo esc_attr($macro); ?>"><?php echo esc_html($macro); ?></button><span><?php echo esc_html($description); ?></span></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private function deliverability_report(array $settings): array
    {
        $from_email = sanitize_email((string) ($settings['from_email'] ?? ''));
        $from_domain = $this->email_domain($from_email);
        $site_domain = $this->site_domain();
        $dkim_selector = sanitize_key((string) ($settings['dkim_selector'] ?? ''));
        $warn_domain = ! empty($settings['enforce_from_domain']);

        $domain_match = $from_domain !== '' && ($from_domain === $site_domain || str_ends_with($from_domain, '.' . $site_domain));
        $spf = $this->dns_txt_contains($from_domain, 'v=spf1');
        $dmarc = $this->dns_txt_contains('_dmarc.' . $from_domain, 'v=DMARC1');
        $dkim = $dkim_selector !== '' ? $this->dns_txt_contains($dkim_selector . '._domainkey.' . $from_domain, 'DKIM') : null;
        $smtp_enabled = ! empty($settings['smtp_enabled']);
        $smtp_host = sanitize_text_field((string) ($settings['smtp_host'] ?? ''));

        return [
            'from_domain' => $from_domain !== '' ? $from_domain : 'Keine gültige From-Adresse',
            'site_domain' => $site_domain !== '' ? $site_domain : 'website-domain.tld',
            'domain_match_class' => $domain_match ? 'is-good' : ($warn_domain ? 'is-warning' : 'is-neutral'),
            'domain_match_text' => $domain_match ? 'From-Domain passt zur Website.' : 'From-Domain weicht von der Website ab.',
            'spf_class' => $this->dns_status_class($spf),
            'spf_text' => $this->dns_status_text($spf, 'SPF-Eintrag gefunden.', 'Kein SPF-Eintrag gefunden.'),
            'spf_hint' => 'TXT-Eintrag für die Domain, z. B. v=spf1 include:mailserver.example -all',
            'dmarc_class' => $this->dns_status_class($dmarc),
            'dmarc_text' => $this->dns_status_text($dmarc, 'DMARC-Eintrag gefunden.', 'Kein DMARC-Eintrag gefunden.'),
            'dmarc_hint' => 'TXT unter _dmarc.' . ($from_domain !== '' ? $from_domain : 'domain.tld'),
            'dkim_class' => $dkim_selector === '' ? 'is-neutral' : $this->dns_status_class($dkim),
            'dkim_text' => $dkim_selector === '' ? 'Kein Selector eingetragen.' : $this->dns_status_text($dkim, 'DKIM-Eintrag gefunden.', 'DKIM-Eintrag nicht gefunden.'),
            'dkim_hint' => 'Selector vom SMTP-Anbieter, z.B. default oder mail.',
            'smtp_class' => $smtp_enabled && $smtp_host !== '' ? 'is-good' : 'is-neutral',
            'smtp_text' => $smtp_enabled && $smtp_host !== '' ? 'SMTP im Plugin aktiviert.' : 'SMTP im Plugin nicht aktiviert.',
            'smtp_hint' => $smtp_enabled && $smtp_host !== '' ? $smtp_host : 'Alternativ kann ein separates SMTP-Plugin genutzt werden.',
        ];
    }

    private function email_domain(string $email): string
    {
        $position = strrpos($email, '@');
        if ($position === false) {
            return '';
        }

        return $this->normalize_domain(substr($email, $position + 1));
    }

    private function site_domain(): string
    {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        return $this->normalize_domain($host);
    }

    private function normalize_domain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/^www\./', '', $domain) ?: '';
        return preg_replace('/[^a-z0-9.-]/', '', $domain) ?: '';
    }

    private function dns_txt_contains(string $domain, string $needle): ?bool
    {
        if ($domain === '' || ! function_exists('dns_get_record') || ! defined('DNS_TXT')) {
            return null;
        }

        $records = @dns_get_record($domain, DNS_TXT);
        if (! is_array($records)) {
            return null;
        }

        foreach ($records as $record) {
            $value = '';
            if (isset($record['txt'])) {
                $value = (string) $record['txt'];
            } elseif (isset($record['entries']) && is_array($record['entries'])) {
                $value = implode('', array_map('strval', $record['entries']));
            }

            if ($value !== '' && stripos($value, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function dns_status_class(?bool $status): string
    {
        if ($status === true) {
            return 'is-good';
        }

        if ($status === false) {
            return 'is-warning';
        }

        return 'is-neutral';
    }

    private function dns_status_text(?bool $status, string $good, string $bad): string
    {
        if ($status === true) {
            return $good;
        }

        if ($status === false) {
            return $bad;
        }

        return 'DNS-Prüfung auf diesem Server nicht verfügbar.';
    }

    private function collect_fields(): array
    {
        $input = isset($_POST['topic_fields']) && is_array($_POST['topic_fields']) ? wp_unslash($_POST['topic_fields']) : [];
        $labels = is_array($input['label'] ?? null) ? $input['label'] : [];
        $fields = [];

        foreach ($labels as $index => $label) {
            $fields[] = [
                'key' => (string) (($input['key'][$index] ?? '') ?: $label),
                'label' => (string) $label,
                'type' => (string) ($input['type'][$index] ?? 'text'),
                'width' => (string) ($input['width'][$index] ?? 'full'),
                'required' => ! empty($input['required'][$index]),
                'options' => (string) ($input['options'][$index] ?? ''),
                'sort_order' => $index,
            ];
        }

        return $fields;
    }

    private function sanitize_settings(array $source): array
    {
        $defaults = $this->db->get_settings();
        $notification_reply_to = (string) wp_unslash($source['notification_reply_to'] ?? $defaults['notification_reply_to']);
        if (! in_array($notification_reply_to, ['sender', 'department', 'none'], true)) {
            $notification_reply_to = 'sender';
        }

        $auto_reply_reply_to = (string) wp_unslash($source['auto_reply_reply_to'] ?? $defaults['auto_reply_reply_to']);
        if (! in_array($auto_reply_reply_to, ['department', 'base', 'none'], true)) {
            $auto_reply_reply_to = 'department';
        }

        $smtp_encryption = (string) wp_unslash($source['smtp_encryption'] ?? $defaults['smtp_encryption']);
        if (! in_array($smtp_encryption, ['tls', 'ssl', 'none'], true)) {
            $smtp_encryption = 'tls';
        }

        $smtp_password = array_key_exists('smtp_password', $source)
            ? (string) wp_unslash($source['smtp_password'])
            : (string) $defaults['smtp_password'];
        if ($smtp_password === '') {
            $smtp_password = (string) $defaults['smtp_password'];
        }

        $imap_encryption = (string) wp_unslash($source['imap_encryption'] ?? $defaults['imap_encryption']);
        if (! in_array($imap_encryption, ['ssl', 'tls', 'none'], true)) {
            $imap_encryption = 'ssl';
        }

        $imap_password = array_key_exists('imap_password', $source)
            ? (string) wp_unslash($source['imap_password'])
            : (string) $defaults['imap_password'];
        if ($imap_password === '') {
            $imap_password = (string) $defaults['imap_password'];
        }

        $turnstile_secret_key = array_key_exists('turnstile_secret_key', $source)
            ? (string) wp_unslash($source['turnstile_secret_key'])
            : (string) $defaults['turnstile_secret_key'];
        if ($turnstile_secret_key === '') {
            $turnstile_secret_key = (string) $defaults['turnstile_secret_key'];
        }

        $recaptcha_secret_key = array_key_exists('recaptcha_secret_key', $source)
            ? (string) wp_unslash($source['recaptcha_secret_key'])
            : (string) $defaults['recaptcha_secret_key'];
        if ($recaptcha_secret_key === '') {
            $recaptcha_secret_key = (string) $defaults['recaptcha_secret_key'];
        }

        $telegram_bot_token = array_key_exists('telegram_bot_token', $source)
            ? trim((string) wp_unslash($source['telegram_bot_token']))
            : (string) $defaults['telegram_bot_token'];
        if ($telegram_bot_token === '') {
            $telegram_bot_token = (string) $defaults['telegram_bot_token'];
        }
        $telegram_webhook_secret = array_key_exists('telegram_webhook_secret', $source)
            ? trim((string) wp_unslash($source['telegram_webhook_secret']))
            : (string) $defaults['telegram_webhook_secret'];
        if ($telegram_webhook_secret === '') {
            try {
                $telegram_webhook_secret = bin2hex(random_bytes(24));
            } catch (Throwable $exception) {
                $telegram_webhook_secret = (string) $defaults['telegram_webhook_secret'];
            }
        }
        $telegram_privacy_mode = sanitize_key((string) wp_unslash($source['telegram_privacy_mode'] ?? $defaults['telegram_privacy_mode']));
        if (! in_array($telegram_privacy_mode, ['summary', 'masked'], true)) {
            $telegram_privacy_mode = 'summary';
        }

        $design_variant = sanitize_key((string) wp_unslash($source['design_variant'] ?? $defaults['design_variant']));
        if (! in_array($design_variant, ['classic', 'clean', 'stepper', 'rail', 'stripe', 'cards', 'faq'], true)) {
            $design_variant = 'classic';
        }

        $field_variant = sanitize_key((string) wp_unslash($source['field_variant'] ?? $defaults['field_variant']));
        if (! in_array($field_variant, ['soft', 'line', 'filled', 'compact'], true)) {
            $field_variant = 'soft';
        }

        $role_access = $this->user_has_admin_access()
            ? $this->sanitize_role_access($source['role_access'] ?? ($defaults['role_access'] ?? []))
            : (is_array($defaults['role_access'] ?? null) ? $defaults['role_access'] : []);
        $turnstile_enabled = array_key_exists('turnstile_enabled', $source) ? (! empty($source['turnstile_enabled']) ? 1 : 0) : (int) $defaults['turnstile_enabled'];
        $recaptcha_enabled = array_key_exists('recaptcha_enabled', $source) ? (! empty($source['recaptcha_enabled']) ? 1 : 0) : (int) $defaults['recaptcha_enabled'];
        if ($turnstile_enabled) {
            $recaptcha_enabled = 0;
        }

        return [
            'site_name' => sanitize_text_field((string) wp_unslash($source['site_name'] ?? $defaults['site_name'])),
            'logo_url' => esc_url_raw((string) wp_unslash($source['logo_url'] ?? $defaults['logo_url'])),
            'base_email' => sanitize_email((string) wp_unslash($source['base_email'] ?? $defaults['base_email'])),
            'from_email' => sanitize_email((string) wp_unslash($source['from_email'] ?? $defaults['from_email'])),
            'from_name' => sanitize_text_field((string) wp_unslash($source['from_name'] ?? $defaults['from_name'])),
            'smtp_enabled' => array_key_exists('smtp_enabled', $source) ? (! empty($source['smtp_enabled']) ? 1 : 0) : (int) $defaults['smtp_enabled'],
            'smtp_host' => sanitize_text_field((string) wp_unslash($source['smtp_host'] ?? $defaults['smtp_host'])),
            'smtp_port' => max(1, absint($source['smtp_port'] ?? $defaults['smtp_port'])),
            'smtp_encryption' => $smtp_encryption,
            'smtp_username' => sanitize_text_field((string) wp_unslash($source['smtp_username'] ?? $defaults['smtp_username'])),
            'smtp_password' => $smtp_password,
            'ticket_mailbox_enabled' => array_key_exists('ticket_mailbox_enabled', $source) ? (! empty($source['ticket_mailbox_enabled']) ? 1 : 0) : (int) $defaults['ticket_mailbox_enabled'],
            'ticket_mailbox_email' => sanitize_email((string) wp_unslash($source['ticket_mailbox_email'] ?? $defaults['ticket_mailbox_email'])),
            'ticket_mailboxes' => array_key_exists('ticket_mailboxes', $source)
                ? $this->sanitize_ticket_mailboxes($source['ticket_mailboxes'], (array) ($defaults['ticket_mailboxes'] ?? []))
                : (array) ($defaults['ticket_mailboxes'] ?? []),
            'direct_email_enabled' => array_key_exists('direct_email_enabled', $source) ? (! empty($source['direct_email_enabled']) ? 1 : 0) : (int) $defaults['direct_email_enabled'],
            'direct_email_recipient' => sanitize_email((string) wp_unslash($source['direct_email_recipient'] ?? $defaults['direct_email_recipient'])),
            'direct_email_auto_reply' => array_key_exists('direct_email_auto_reply', $source) ? (! empty($source['direct_email_auto_reply']) ? 1 : 0) : (int) $defaults['direct_email_auto_reply'],
            'imap_host' => sanitize_text_field((string) wp_unslash($source['imap_host'] ?? $defaults['imap_host'])),
            'imap_port' => max(1, absint($source['imap_port'] ?? $defaults['imap_port'])),
            'imap_encryption' => $imap_encryption,
            'imap_username' => sanitize_text_field((string) wp_unslash($source['imap_username'] ?? $defaults['imap_username'])),
            'imap_password' => $imap_password,
            'imap_folder' => sanitize_text_field((string) wp_unslash($source['imap_folder'] ?? $defaults['imap_folder'])),
            'notification_reply_to' => $notification_reply_to,
            'auto_reply_reply_to' => $auto_reply_reply_to,
            'enforce_from_domain' => array_key_exists('enforce_from_domain', $source) ? (! empty($source['enforce_from_domain']) ? 1 : 0) : (int) $defaults['enforce_from_domain'],
            'auto_response_headers' => array_key_exists('auto_response_headers', $source) ? (! empty($source['auto_response_headers']) ? 1 : 0) : (int) $defaults['auto_response_headers'],
            'dkim_selector' => sanitize_key((string) wp_unslash($source['dkim_selector'] ?? $defaults['dkim_selector'])),
            'privacy_enabled' => array_key_exists('privacy_enabled', $source) ? (! empty($source['privacy_enabled']) ? 1 : 0) : (int) $defaults['privacy_enabled'],
            'privacy_url' => esc_url_raw((string) wp_unslash($source['privacy_url'] ?? $defaults['privacy_url'])),
            'privacy_text' => sanitize_textarea_field((string) wp_unslash($source['privacy_text'] ?? $defaults['privacy_text'])),
            'privacy_version' => sanitize_text_field((string) wp_unslash($source['privacy_version'] ?? $defaults['privacy_version'])),
            'department_access_confirmation_required' => array_key_exists('department_access_confirmation_required', $source) ? (! empty($source['department_access_confirmation_required']) ? 1 : 0) : (int) $defaults['department_access_confirmation_required'],
            'ticket_success_text' => sanitize_textarea_field((string) wp_unslash($source['ticket_success_text'] ?? $defaults['ticket_success_text'])),
            'rate_limit_minutes' => max(1, absint($source['rate_limit_minutes'] ?? $defaults['rate_limit_minutes'])),
            'antispam_honeypot_enabled' => array_key_exists('antispam_honeypot_enabled', $source) ? (! empty($source['antispam_honeypot_enabled']) ? 1 : 0) : (int) $defaults['antispam_honeypot_enabled'],
            'turnstile_enabled' => $turnstile_enabled,
            'turnstile_site_key' => sanitize_text_field((string) wp_unslash($source['turnstile_site_key'] ?? $defaults['turnstile_site_key'])),
            'turnstile_secret_key' => sanitize_text_field($turnstile_secret_key),
            'recaptcha_enabled' => $recaptcha_enabled,
            'recaptcha_site_key' => sanitize_text_field((string) wp_unslash($source['recaptcha_site_key'] ?? $defaults['recaptcha_site_key'])),
            'recaptcha_secret_key' => sanitize_text_field($recaptcha_secret_key),
            'design_variant' => $design_variant,
            'field_variant' => $field_variant,
            'allowed_file_types' => $this->sanitize_file_types((string) wp_unslash($source['allowed_file_types'] ?? $defaults['allowed_file_types'])),
            'max_upload_mb' => max(1, min(64, absint($source['max_upload_mb'] ?? $defaults['max_upload_mb']))),
            'role_access' => $role_access,
            'telegram_enabled' => array_key_exists('telegram_enabled', $source) ? (! empty($source['telegram_enabled']) ? 1 : 0) : (int) $defaults['telegram_enabled'],
            'telegram_bot_token' => $telegram_bot_token,
            'telegram_webhook_secret' => $telegram_webhook_secret,
            'telegram_privacy_mode' => $telegram_privacy_mode,
        ];
    }

    private function sanitize_ticket_mailboxes($source, array $defaults): array
    {
        $input = is_array($source) ? wp_unslash($source) : [];
        $stored_passwords = [];
        foreach ($defaults as $mailbox) {
            if (! is_array($mailbox)) {
                continue;
            }
            $email = strtolower(sanitize_email((string) ($mailbox['email'] ?? '')));
            if ($email !== '') {
                $stored_passwords[$email] = (string) ($mailbox['password'] ?? '');
            }
        }

        $mailboxes = [];
        foreach ($input as $mailbox) {
            if (! is_array($mailbox)) {
                continue;
            }

            $email = sanitize_email((string) ($mailbox['email'] ?? ''));
            if ($email === '') {
                continue;
            }

            $encryption = (string) ($mailbox['encryption'] ?? 'ssl');
            if (! in_array($encryption, ['ssl', 'tls', 'none'], true)) {
                $encryption = 'ssl';
            }

            $password = (string) ($mailbox['password'] ?? '');
            if ($password === '') {
                $password = $stored_passwords[strtolower($email)] ?? '';
            }

            $mailboxes[] = [
                'label' => sanitize_text_field((string) ($mailbox['label'] ?? '')),
                'email' => $email,
                'host' => sanitize_text_field((string) ($mailbox['host'] ?? '')),
                'port' => max(1, absint($mailbox['port'] ?? 993)),
                'encryption' => $encryption,
                'username' => sanitize_text_field((string) ($mailbox['username'] ?? '')),
                'password' => $password,
                'folder' => sanitize_text_field((string) ($mailbox['folder'] ?? 'INBOX')) ?: 'INBOX',
            ];
        }

        return $mailboxes;
    }

    private function sanitize_file_types(string $types): string
    {
        $allowed = [];
        foreach (preg_split('/[\s,;]+/', strtolower($types)) ?: [] as $type) {
            $type = preg_replace('/[^a-z0-9]/', '', $type) ?: '';
            if ($type !== '') {
                $allowed[] = $type;
            }
        }

        return implode(',', array_values(array_unique($allowed))) ?: 'pdf,jpg,jpeg,png,doc,docx';
    }

    private function input(string $name, string $label, $value, string $type = 'text'): void
    {
        ?>
        <label><?php echo esc_html($label); ?><input class="regular-text" type="<?php echo esc_attr($type); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $value); ?>"></label>
        <?php
    }

    private function mailbox_rows(array $settings): void
    {
        $mailboxes = [];
        foreach ((array) ($settings['ticket_mailboxes'] ?? []) as $mailbox) {
            if (is_array($mailbox) && empty($mailbox['primary'])) {
                $mailboxes[] = $mailbox;
            }
        }
        $mailboxes[] = [
            'label' => '',
            'email' => '',
            'host' => '',
            'port' => 993,
            'encryption' => 'ssl',
            'username' => '',
            'password' => '',
            'folder' => 'INBOX',
        ];
        ?>
        <div class="feuer-mailbox-rows">
            <?php foreach ($mailboxes as $index => $mailbox) : ?>
                <div class="feuer-mailbox-row">
                    <div class="feuer-form-grid">
                        <label>Bezeichnung<input class="regular-text" type="text" name="ticket_mailboxes[<?php echo esc_attr((string) $index); ?>][label]" value="<?php echo esc_attr((string) ($mailbox['label'] ?? '')); ?>"></label>
                        <label>E-Mail<input class="regular-text" type="email" name="ticket_mailboxes[<?php echo esc_attr((string) $index); ?>][email]" value="<?php echo esc_attr((string) ($mailbox['email'] ?? '')); ?>"></label>
                        <label>IMAP Host<input class="regular-text" type="text" name="ticket_mailboxes[<?php echo esc_attr((string) $index); ?>][host]" value="<?php echo esc_attr((string) ($mailbox['host'] ?? '')); ?>"></label>
                        <label>Port<input class="regular-text" type="number" name="ticket_mailboxes[<?php echo esc_attr((string) $index); ?>][port]" value="<?php echo esc_attr((string) ($mailbox['port'] ?? 993)); ?>"></label>
                        <label>Verschlüsselung
                            <select name="ticket_mailboxes[<?php echo esc_attr((string) $index); ?>][encryption]">
                                <option value="ssl" <?php selected((string) ($mailbox['encryption'] ?? 'ssl'), 'ssl'); ?>>SSL</option>
                                <option value="tls" <?php selected((string) ($mailbox['encryption'] ?? 'ssl'), 'tls'); ?>>TLS</option>
                                <option value="none" <?php selected((string) ($mailbox['encryption'] ?? 'ssl'), 'none'); ?>>Keine</option>
                            </select>
                        </label>
                        <label>Benutzername<input class="regular-text" type="text" name="ticket_mailboxes[<?php echo esc_attr((string) $index); ?>][username]" value="<?php echo esc_attr((string) ($mailbox['username'] ?? '')); ?>"></label>
                        <label>Ordner<input class="regular-text" type="text" name="ticket_mailboxes[<?php echo esc_attr((string) $index); ?>][folder]" value="<?php echo esc_attr((string) ($mailbox['folder'] ?? 'INBOX')); ?>"></label>
                        <label>Passwort<input class="regular-text" type="password" name="ticket_mailboxes[<?php echo esc_attr((string) $index); ?>][password]" value="" autocomplete="new-password" placeholder="<?php echo empty($mailbox['password']) ? 'Nicht gespeichert' : 'Gespeichert - leer lassen'; ?>"></label>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="description">Zum Hinzufügen weiterer Postfächer speichern Sie eine freie Zeile; danach erscheint automatisch eine neue leere Zeile.</p>
        <?php
    }

    private function send_backup_download(array $payload, string $prefix = 'kontaktform-backup'): void
    {
        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json) || $json === '') {
            $this->redirect(
                'feuer-kontaktform-settings',
                'backup_download_failed',
                ['feuer-detail' => 'Die Sicherungsdatei konnte nicht erstellt werden.']
            );
        }

        $filename = sanitize_file_name($prefix . '-' . gmdate('Y-m-d-His') . '.json');
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) strlen($json));
        header('X-Content-Type-Options: nosniff');
        echo $json;
        exit;
    }

    private function read_uploaded_backup(): array
    {
        $file = $_FILES['backup_file'] ?? null;
        if (! is_array($file)) {
            return [
                'success' => false,
                'message' => 'Es wurde keine Sicherungsdatei hochgeladen.',
            ];
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'message' => $this->upload_error_message($error),
            ];
        }

        $tmp_name = (string) ($file['tmp_name'] ?? '');
        if ($tmp_name === '' || (! is_uploaded_file($tmp_name) && ! file_exists($tmp_name))) {
            return [
                'success' => false,
                'message' => 'Die hochgeladene Sicherungsdatei konnte nicht gelesen werden.',
            ];
        }

        $raw = file_get_contents($tmp_name);
        if (! is_string($raw) || trim($raw) === '') {
            return [
                'success' => false,
                'message' => 'Die Sicherungsdatei ist leer.',
            ];
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return [
                'success' => false,
                'message' => 'Die Sicherungsdatei ist kein gültiges JSON.',
            ];
        }

        return [
            'success' => true,
            'payload' => $payload,
        ];
    }

    private function upload_error_message(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Sicherungsdatei ist größer als das erlaubte Upload-Limit des Servers.',
            UPLOAD_ERR_PARTIAL => 'Die Sicherungsdatei wurde nur teilweise hochgeladen.',
            UPLOAD_ERR_NO_FILE => 'Es wurde keine Sicherungsdatei ausgewählt.',
            UPLOAD_ERR_NO_TMP_DIR => 'Dem Server fehlt ein temporärer Upload-Ordner.',
            UPLOAD_ERR_CANT_WRITE => 'Die Sicherungsdatei konnte nicht auf den Server geschrieben werden.',
            UPLOAD_ERR_EXTENSION => 'Der Upload der Sicherungsdatei wurde vom Server blockiert.',
            default => 'Beim Hochladen der Sicherungsdatei ist ein unbekannter Fehler aufgetreten.',
        };
    }

    private function redirect(string $page, string $message, array $args = []): void
    {
        wp_safe_redirect(add_query_arg($args + ['page' => $page, 'feuer-message' => $message], admin_url('admin.php')));
        exit;
    }

    private function notice(): void
    {
        $message = sanitize_key((string) ($_GET['feuer-message'] ?? ''));
        $detail = sanitize_text_field((string) wp_unslash($_GET['feuer-detail'] ?? ''));
        if ($message === '') {
            return;
        }

        $labels = [
            'settings_saved' => 'Einstellungen gespeichert.',
            'test_mail_sent' => 'Testmail wurde gesendet.',
            'test_mail_failed' => 'Testmail konnte nicht gesendet werden.',
            'update_checked' => 'Update-Prüfung wurde ausgeführt.',
            'update_current' => 'Update-Prüfung wurde ausgeführt. Die installierte Version ist aktuell.',
            'update_available' => 'Update-Prüfung wurde ausgeführt. Eine neue Version ist verfügbar.',
            'update_failed' => 'Update-Prüfung fehlgeschlagen.',
            'update_installed' => 'Update wurde installiert.',
            'update_install_failed' => 'Update konnte nicht installiert werden.',
            'update_no_update' => 'Es ist keine neuere Version verfügbar.',
            'backup_restored' => 'Sicherung wurde wiederhergestellt.',
            'backup_restore_failed' => 'Sicherung konnte nicht wiederhergestellt werden.',
            'backup_download_failed' => 'Sicherung konnte nicht erstellt werden.',
            'department_saved' => 'Abteilung gespeichert.',
            'department_failed' => 'Abteilung konnte nicht gespeichert werden.',
            'department_deleted' => 'Abteilung wurde deaktiviert.',
            'department_moved' => 'Abteilung wurde verschoben.',
            'department_move_failed' => 'Abteilung konnte nicht verschoben werden.',
            'topic_saved' => 'Thema gespeichert.',
            'topic_failed' => 'Thema konnte nicht gespeichert werden.',
            'topic_deleted' => 'Thema wurde deaktiviert.',
            'ticket_reply_sent' => 'Antwort wurde gesendet.',
            'ticket_reply_failed' => 'Antwort konnte nicht gesendet werden.',
            'ticket_notification_resent' => 'Die Nachricht wurde erneut an den zuständigen Empfänger gesendet.',
            'ticket_notification_failed' => 'Die Nachricht konnte nicht an den zuständigen Empfänger gesendet werden.',
            'ticket_access_revoked' => 'Der geschützte Zugriff wurde widerrufen.',
            'ticket_access_revoke_failed' => 'Der geschützte Zugriff konnte nicht widerrufen werden.',
            'inbound_mail_checked' => 'Das Ticket-Postfach wurde geprüft.',
            'inbound_mail_failed' => 'Das Ticket-Postfach konnte nicht geprüft werden.',
        ];

        if (! isset($labels[$message])) {
            return;
        }

        $type = str_contains($message, 'failed') ? 'error' : 'success';
        $text = $labels[$message];
        if ($detail !== '') {
            $text .= ' ' . $detail;
        }

        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';
    }
}
