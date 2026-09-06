<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Feuer_Kontaktform_Support_DB
{
    public const OPTION_SETTINGS = 'feuer_kontaktform_support_settings';
    public const OPTION_VERSION = 'feuer_kontaktform_support_version';

    private string $departments_table;
    private string $topics_table;
    private string $tickets_table;
    private string $messages_table;
    private string $logs_table;
    private string $telegram_users_table;
    private string $telegram_routes_table;
    private string $telegram_actions_table;

    public function __construct()
    {
        global $wpdb;

        $this->departments_table = $wpdb->prefix . 'feuer_kontaktform_departments';
        $this->topics_table      = $wpdb->prefix . 'feuer_kontaktform_topics';
        $this->tickets_table     = $wpdb->prefix . 'feuer_kontaktform_tickets';
        $this->messages_table    = $wpdb->prefix . 'feuer_kontaktform_ticket_messages';
        $this->logs_table        = $wpdb->prefix . 'feuer_kontaktform_logs';
        $this->telegram_users_table = $wpdb->prefix . 'feuer_kontaktform_telegram_users';
        $this->telegram_routes_table = $wpdb->prefix . 'feuer_kontaktform_telegram_routes';
        $this->telegram_actions_table = $wpdb->prefix . 'feuer_kontaktform_telegram_actions';
    }

    public static function default_settings(): array
    {
        return [
            'site_name' => get_bloginfo('name'),
            'logo_url' => '',
            'base_email' => get_option('admin_email'),
            'from_email' => get_option('admin_email'),
            'from_name' => get_bloginfo('name'),
            'smtp_enabled' => 0,
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => '',
            'smtp_password' => '',
            'ticket_mailbox_enabled' => 0,
            'ticket_mailbox_email' => '',
            'ticket_mailboxes' => [],
            'direct_email_enabled' => 0,
            'direct_email_recipient' => get_option('admin_email'),
            'direct_email_auto_reply' => 1,
            'imap_host' => '',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => '',
            'imap_password' => '',
            'imap_folder' => 'INBOX',
            'notification_reply_to' => 'sender',
            'auto_reply_reply_to' => 'department',
            'enforce_from_domain' => 1,
            'auto_response_headers' => 1,
            'dkim_selector' => '',
            'privacy_enabled' => 1,
            'privacy_url' => function_exists('get_privacy_policy_url') ? (string) get_privacy_policy_url() : '',
            'privacy_text' => 'Ich stimme zu, dass meine Angaben zur Bearbeitung meiner Anfrage verarbeitet werden. Es gilt die Datenschutzerklärung.',
            'privacy_version' => '1',
            'department_access_confirmation_required' => 1,
            'ticket_success_text' => 'Vielen Dank. Ihre Anfrage wurde gespeichert und per E-Mail weitergeleitet.',
            'rate_limit_minutes' => 10,
            'antispam_honeypot_enabled' => 1,
            'turnstile_enabled' => 0,
            'turnstile_site_key' => '',
            'turnstile_secret_key' => '',
            'recaptcha_enabled' => 0,
            'recaptcha_site_key' => '',
            'recaptcha_secret_key' => '',
            'design_variant' => 'classic',
            'field_variant' => 'soft',
            'allowed_file_types' => 'pdf,jpg,jpeg,png,doc,docx',
            'max_upload_mb' => 8,
            'role_access' => [],
            'telegram_enabled' => 0,
            'telegram_bot_token' => '',
            'telegram_webhook_secret' => '',
            'telegram_privacy_mode' => 'summary',
        ];
    }

    public static function default_fields(): array
    {
        return [
            ['key' => 'titel', 'label' => 'Titel / Betreff', 'type' => 'text', 'width' => 'full', 'required' => false],
            ['key' => 'vorname', 'label' => 'Vorname', 'type' => 'text', 'width' => 'half', 'required' => true],
            ['key' => 'nachname', 'label' => 'Nachname', 'type' => 'text', 'width' => 'half', 'required' => true],
            ['key' => 'email', 'label' => 'E-Mail Adresse', 'type' => 'email', 'width' => 'half', 'required' => true],
            ['key' => 'telefon', 'label' => 'Telefonnummer', 'type' => 'tel', 'width' => 'half', 'required' => false],
            ['key' => 'nachricht', 'label' => 'Nachricht', 'type' => 'textarea', 'width' => 'full', 'required' => true],
        ];
    }

    public static function default_auto_subject(): string
    {
        return '{{topic}} - {{subject}} [Anfrage Nr. {{ticket_id}}]';
    }

    public static function default_auto_body(): string
    {
        return "Hallo {{vorname}} {{nachname}},\n\nvielen Dank für Ihre Anfrage an {{site_name}}.\n\nWir haben Ihre Nachricht zum Thema \"{{topic}}\" erhalten und unter der Anfrage Nr. {{ticket_id}} gespeichert. Ihre Anfrage wird geprüft und schnellstmöglich beantwortet.\n\nMit freundlichen Grüßen\n{{site_name}}";
    }

    public static function default_auto_html_body(): string
    {
        return '<div style="margin:0;padding:0;background:#f3f5f7;font-family:Arial,Helvetica,sans-serif;color:#20242a;">'
            . '<div style="max-width:680px;margin:0 auto;padding:28px 18px;">'
            . '<div style="background:#ffffff;border:1px solid #dde2e8;border-radius:10px;overflow:hidden;">'
            . '<div style="padding:24px 28px;border-bottom:4px solid #b3192b;background:#ffffff;">'
            . '{{logo_html}}'
            . '<h1 style="margin:8px 0 0;font-size:24px;line-height:1.25;color:#20242a;">Ihre Nachricht wurde empfangen</h1>'
            . '</div>'
            . '<div style="padding:28px;">'
            . '<p style="margin:0 0 16px;font-size:16px;line-height:1.55;">Hallo {{vorname}} {{nachname}},</p>'
            . '<p style="margin:0 0 16px;font-size:16px;line-height:1.55;">vielen Dank für Ihre Anfrage an <strong>{{site_name}}</strong>.</p>'
            . '<p style="margin:0 0 16px;font-size:16px;line-height:1.55;">Wir haben Ihre Nachricht zum Thema &quot;<strong>{{topic}}</strong>&quot; erhalten und unter der Anfrage Nr. <strong>{{ticket_id}}</strong> gespeichert. Ihre Anfrage wird geprüft und schnellstmöglich beantwortet.</p>'
            . '<p style="margin:24px 0 0;font-size:16px;line-height:1.55;">Mit freundlichen Grüßen<br><strong>{{site_name}}</strong></p>'
            . '</div>'
            . '<div style="padding:18px 28px;background:#f8f9fb;border-top:1px solid #e6e9ee;font-size:12px;line-height:1.45;color:#687281;">'
            . 'Diese E-Mail wurde automatisch erstellt.'
            . '</div>'
            . '</div>'
            . '</div>'
            . '</div>';
    }

    public static function auto_reply_macros(): array
    {
        return [
            '{{ticket_id}}' => '8-stellige Anfrage Nr.',
            '{{ticket}}' => 'Alias für die Anfrage Nr.',
            '{{site_name}}' => 'Name der Website aus den Einstellungen',
            '{{logo_html}}' => 'Fertiger HTML-Block für das Logo, wenn eine Logo-URL gesetzt ist',
            '{{logo_url}}' => 'Logo-URL aus den Einstellungen',
            '{{privacy_url}}' => 'URL zur Datenschutzerklärung',
            '{{department}}' => 'Name der ausgewählten Abteilung',
            '{{topic}}' => 'Name des ausgewählten Themas',
            '{{subject}}' => 'Betreff/Titel, den der Absender eingegeben hat',
            '{{name}}' => 'Vollständiger Name des Absenders',
            '{{vorname}}' => 'Vorname des Absenders',
            '{{nachname}}' => 'Nachname des Absenders',
            '{{email}}' => 'E-Mail-Adresse des Absenders',
            '{{telefon}}' => 'Telefonnummer des Absenders',
        ];
    }

    public function create_tables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $installed_version = (string) get_option(self::OPTION_VERSION, '');

        dbDelta("CREATE TABLE {$this->departments_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            slug varchar(120) NOT NULL,
            email varchar(255) NOT NULL,
            description text NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY is_active (is_active),
            KEY sort_order (sort_order)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->topics_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            department_id bigint(20) unsigned NOT NULL,
            title varchar(190) NOT NULL,
            slug varchar(120) NOT NULL,
            email_override varchar(255) NOT NULL DEFAULT '',
            auto_reply_enabled tinyint(1) NOT NULL DEFAULT 1,
            auto_reply_format varchar(20) NOT NULL DEFAULT 'html',
            auto_subject varchar(255) NOT NULL DEFAULT '',
            auto_body longtext NULL,
            auto_html longtext NULL,
            fields_json longtext NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY department_slug (department_id, slug),
            KEY department_id (department_id),
            KEY is_active (is_active),
            KEY sort_order (sort_order)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tickets_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ticket_code varchar(8) NOT NULL,
            origin varchar(40) NOT NULL DEFAULT 'web_form',
            department_id bigint(20) unsigned NOT NULL,
            topic_id bigint(20) unsigned NOT NULL,
            subject varchar(255) NOT NULL DEFAULT '',
            sender_name varchar(255) NOT NULL DEFAULT '',
            sender_email varchar(255) NOT NULL DEFAULT '',
            sender_phone varchar(100) NOT NULL DEFAULT '',
            message longtext NULL,
            fields_json longtext NULL,
            status varchar(40) NOT NULL DEFAULT 'open',
            delivery_status varchar(30) NOT NULL DEFAULT 'pending',
            delivery_recipient varchar(255) NOT NULL DEFAULT '',
            delivery_attempts int(11) unsigned NOT NULL DEFAULT 0,
            delivery_last_error text NULL,
            delivery_last_attempt_at datetime NULL,
            delivery_accepted_at datetime NULL,
            delivery_confirmed_at datetime NULL,
            access_token_hash varchar(64) NOT NULL DEFAULT '',
            access_recipient varchar(255) NOT NULL DEFAULT '',
            access_expires_at datetime NULL,
            access_last_used_at datetime NULL,
            access_revoked_at datetime NULL,
            privacy_required tinyint(1) NOT NULL DEFAULT 1,
            privacy_accepted tinyint(1) NOT NULL DEFAULT 0,
            privacy_version varchar(80) NOT NULL DEFAULT '',
            privacy_url text NULL,
            consent_text text NULL,
            ip_address varchar(100) NOT NULL DEFAULT '',
            visitor_cookie varchar(120) NOT NULL DEFAULT '',
            browser text NULL,
            device_type varchar(80) NOT NULL DEFAULT '',
            user_agent text NULL,
            captcha_required tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY ticket_code (ticket_code),
            KEY origin (origin),
            KEY department_id (department_id),
            KEY topic_id (topic_id),
            KEY sender_email (sender_email),
            KEY delivery_status (delivery_status),
            KEY ip_address (ip_address),
            KEY created_at (created_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->messages_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ticket_id bigint(20) unsigned NOT NULL,
            direction varchar(20) NOT NULL DEFAULT 'inbound',
            source varchar(40) NOT NULL DEFAULT '',
            external_id varchar(255) NOT NULL DEFAULT '',
            from_email varchar(255) NOT NULL DEFAULT '',
            to_email varchar(255) NOT NULL DEFAULT '',
            body longtext NULL,
            raw_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY ticket_id (ticket_id),
            KEY direction (direction),
            KEY external_id (external_id(190)),
            KEY created_at (created_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->logs_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action varchar(120) NOT NULL,
            object_type varchar(80) NOT NULL DEFAULT '',
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            ip_address varchar(100) NOT NULL DEFAULT '',
            user_agent text NULL,
            context_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY actor_id (actor_id),
            KEY action (action),
            KEY object_type (object_type),
            KEY created_at (created_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->telegram_users_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            telegram_user_id bigint(20) unsigned NULL,
            chat_id bigint(20) NULL,
            display_name varchar(255) NOT NULL DEFAULT '',
            first_name varchar(120) NOT NULL DEFAULT '',
            last_name varchar(120) NOT NULL DEFAULT '',
            signature_text text NULL,
            pairing_code_hash varchar(64) NOT NULL DEFAULT '',
            pairing_expires_at datetime NULL,
            permissions_json longtext NULL,
            is_active tinyint(1) NOT NULL DEFAULT 0,
            last_seen_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY telegram_user_id (telegram_user_id),
            KEY wp_user_id (wp_user_id),
            KEY pairing_code_hash (pairing_code_hash),
            KEY is_active (is_active)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->telegram_routes_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            department_id bigint(20) unsigned NOT NULL DEFAULT 0,
            topic_id bigint(20) unsigned NOT NULL DEFAULT 0,
            telegram_user_id bigint(20) unsigned NOT NULL,
            actions_json longtext NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY route_target (department_id, topic_id, telegram_user_id),
            KEY department_id (department_id),
            KEY telegram_user_id (telegram_user_id),
            KEY is_active (is_active)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->telegram_actions_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token_hash varchar(64) NOT NULL,
            ticket_id bigint(20) unsigned NOT NULL,
            telegram_user_id bigint(20) unsigned NOT NULL,
            action varchar(40) NOT NULL,
            payload_json longtext NULL,
            expires_at datetime NOT NULL,
            consumed_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY ticket_id (ticket_id),
            KEY telegram_user_id (telegram_user_id),
            KEY expires_at (expires_at)
        ) $charset;");

        if (! is_array(get_option(self::OPTION_SETTINGS))) {
            add_option(self::OPTION_SETTINGS, self::default_settings(), '', false);
        }

        $this->seed_defaults();
        $this->migrate_auto_reply_templates($installed_version);
        update_option(self::OPTION_VERSION, FEUER_KONTAKTFORM_VERSION);
    }

    private function migrate_auto_reply_templates(string $installed_version): void
    {
        global $wpdb;

        if ($installed_version !== '' && version_compare($installed_version, '3.0.21', '>=')) {
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->topics_table}
                 SET auto_body = %s,
                     auto_html = %s,
                     updated_at = %s",
                self::default_auto_body(),
                self::default_auto_html_body(),
                current_time('mysql')
            )
        );
    }

    public function uninstall(): void
    {
        global $wpdb;

        $wpdb->query("DROP TABLE IF EXISTS {$this->messages_table}");
        $wpdb->query("DROP TABLE IF EXISTS {$this->tickets_table}");
        $wpdb->query("DROP TABLE IF EXISTS {$this->topics_table}");
        $wpdb->query("DROP TABLE IF EXISTS {$this->departments_table}");
        $wpdb->query("DROP TABLE IF EXISTS {$this->logs_table}");
        $wpdb->query("DROP TABLE IF EXISTS {$this->telegram_actions_table}");
        $wpdb->query("DROP TABLE IF EXISTS {$this->telegram_routes_table}");
        $wpdb->query("DROP TABLE IF EXISTS {$this->telegram_users_table}");

        delete_option(self::OPTION_SETTINGS);
        delete_option(self::OPTION_VERSION);
    }

    public function get_settings(): array
    {
        $settings = get_option(self::OPTION_SETTINGS);
        $settings = wp_parse_args(is_array($settings) ? $settings : [], self::default_settings());
        $settings['smtp_password'] = $this->decrypt_secret((string) ($settings['smtp_password'] ?? ''));
        $settings['imap_password'] = $this->decrypt_secret((string) ($settings['imap_password'] ?? ''));
        $settings['turnstile_secret_key'] = $this->decrypt_secret((string) ($settings['turnstile_secret_key'] ?? ''));
        $settings['recaptcha_secret_key'] = $this->decrypt_secret((string) ($settings['recaptcha_secret_key'] ?? ''));
        $settings['telegram_bot_token'] = $this->decrypt_secret((string) ($settings['telegram_bot_token'] ?? ''));
        $settings['telegram_webhook_secret'] = $this->decrypt_secret((string) ($settings['telegram_webhook_secret'] ?? ''));
        $settings['ticket_mailboxes'] = $this->normalize_ticket_mailboxes($settings);

        return $settings;
    }

    public function update_settings(array $settings): void
    {
        $settings = wp_parse_args($settings, self::default_settings());
        $settings['ticket_mailboxes'] = $this->normalize_ticket_mailboxes($settings);
        foreach (['smtp_password', 'imap_password', 'turnstile_secret_key', 'recaptcha_secret_key', 'telegram_bot_token', 'telegram_webhook_secret'] as $key) {
            $settings[$key] = $this->encrypt_secret((string) ($settings[$key] ?? ''));
        }
        foreach ($settings['ticket_mailboxes'] as $index => $mailbox) {
            $settings['ticket_mailboxes'][$index]['password'] = $this->encrypt_secret((string) ($mailbox['password'] ?? ''));
        }

        update_option(self::OPTION_SETTINGS, $settings, false);
    }

    public function create_telegram_pairing(int $wp_user_id, array $permissions, int $minutes = 30): string
    {
        global $wpdb;

        try {
            $code = strtoupper(substr(strtr(base64_encode(random_bytes(12)), '+/', 'AZ'), 0, 16));
        } catch (Throwable $exception) {
            return '';
        }

        $now = current_time('mysql');
        $expires = current_datetime()->modify('+' . max(5, min(60, $minutes)) . ' minutes')->format('Y-m-d H:i:s');
        $wpdb->insert($this->telegram_users_table, [
            'wp_user_id' => max(0, $wp_user_id),
            'pairing_code_hash' => hash_hmac('sha256', $code, wp_salt('auth')),
            'pairing_expires_at' => $expires,
            'permissions_json' => wp_json_encode($this->normalize_telegram_permissions($permissions)),
            'is_active' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $wpdb->insert_id ? $code : '';
    }

    public function complete_telegram_pairing(string $code, int $telegram_user_id, int $chat_id, string $display_name): ?array
    {
        global $wpdb;

        $hash = hash_hmac('sha256', strtoupper(trim($code)), wp_salt('auth'));
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->telegram_users_table} WHERE pairing_code_hash = %s AND pairing_expires_at >= %s AND is_active = 0 ORDER BY id DESC LIMIT 1",
            $hash,
            current_time('mysql')
        ), ARRAY_A);
        if (! is_array($row) || $telegram_user_id <= 0 || $chat_id === 0) {
            return null;
        }

        $updated = $wpdb->update($this->telegram_users_table, [
            'telegram_user_id' => $telegram_user_id,
            'chat_id' => $chat_id,
            'display_name' => sanitize_text_field($display_name),
            'pairing_code_hash' => '',
            'pairing_expires_at' => null,
            'is_active' => 1,
            'last_seen_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => (int) $row['id']]);

        return $updated === false ? null : $this->get_telegram_user($telegram_user_id);
    }

    public function get_telegram_user(int $telegram_user_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->telegram_users_table} WHERE telegram_user_id = %d AND is_active = 1 LIMIT 1", $telegram_user_id), ARRAY_A);
        if (! is_array($row)) {
            return null;
        }
        $row['permissions'] = $this->normalize_telegram_permissions(json_decode((string) $row['permissions_json'], true));
        return $row;
    }

    public function update_telegram_profile(int $telegram_user_id, string $first_name, string $last_name, string $signature): bool
    {
        global $wpdb;
        if ($telegram_user_id <= 0 || trim($first_name) === '' || trim($last_name) === '') {
            return false;
        }
        $updated = $wpdb->update($this->telegram_users_table, [
            'first_name' => sanitize_text_field($first_name),
            'last_name' => sanitize_text_field($last_name),
            'signature_text' => sanitize_textarea_field($signature),
            'updated_at' => current_time('mysql'),
        ], ['telegram_user_id' => $telegram_user_id, 'is_active' => 1], ['%s', '%s', '%s', '%s'], ['%d', '%d']);
        if ($updated !== false) {
            $this->log('telegram_profile_updated', 'telegram', $telegram_user_id, []);
        }
        return $updated !== false;
    }

    public function get_telegram_users(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$this->telegram_users_table} ORDER BY is_active DESC, display_name ASC, id DESC", ARRAY_A);
        return is_array($rows) ? array_map(function (array $row): array {
            $row['permissions'] = $this->normalize_telegram_permissions(json_decode((string) $row['permissions_json'], true));
            return $row;
        }, $rows) : [];
    }

    public function telegram_routes_for_ticket(array $ticket): array
    {
        global $wpdb;
        $department_id = absint($ticket['department_id'] ?? 0);
        $topic_id = absint($ticket['topic_id'] ?? 0);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, u.chat_id, u.display_name, u.permissions_json FROM {$this->telegram_routes_table} r INNER JOIN {$this->telegram_users_table} u ON u.telegram_user_id = r.telegram_user_id WHERE r.is_active = 1 AND u.is_active = 1 AND r.department_id = %d AND (r.topic_id = 0 OR r.topic_id = %d)",
            $department_id,
            $topic_id
        ), ARRAY_A);
        return is_array($rows) ? array_map(function (array $row): array {
            $row['actions'] = $this->normalize_telegram_permissions(json_decode((string) $row['actions_json'], true));
            $row['permissions'] = $this->normalize_telegram_permissions(json_decode((string) $row['permissions_json'], true));
            return $row;
        }, $rows) : [];
    }

    public function get_telegram_routes(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT r.*, u.display_name, d.name AS department_name, p.title AS topic_title FROM {$this->telegram_routes_table} r INNER JOIN {$this->telegram_users_table} u ON u.telegram_user_id = r.telegram_user_id LEFT JOIN {$this->departments_table} d ON d.id = r.department_id LEFT JOIN {$this->topics_table} p ON p.id = r.topic_id ORDER BY d.name ASC, p.title ASC, u.display_name ASC", ARRAY_A);
        return is_array($rows) ? array_map(function (array $row): array {
            $row['actions'] = $this->normalize_telegram_permissions(json_decode((string) $row['actions_json'], true));
            return $row;
        }, $rows) : [];
    }

    public function save_telegram_route(int $department_id, int $topic_id, int $telegram_user_id, array $actions): bool
    {
        global $wpdb;
        if ($department_id <= 0 || $telegram_user_id <= 0 || $this->get_telegram_user($telegram_user_id) === null) {
            return false;
        }
        $now = current_time('mysql');
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->telegram_routes_table} WHERE department_id = %d AND topic_id = %d AND telegram_user_id = %d", $department_id, max(0, $topic_id), $telegram_user_id));
        $data = ['actions_json' => wp_json_encode($this->normalize_telegram_permissions($actions)), 'is_active' => 1, 'updated_at' => $now];
        $result = $existing
            ? $wpdb->update($this->telegram_routes_table, $data, ['id' => (int) $existing])
            : $wpdb->insert($this->telegram_routes_table, $data + ['department_id' => $department_id, 'topic_id' => max(0, $topic_id), 'telegram_user_id' => $telegram_user_id, 'created_at' => $now]);
        return $result !== false;
    }

    public function create_telegram_action(int $ticket_id, int $telegram_user_id, string $action, array $payload = [], int $minutes = 15): string
    {
        global $wpdb;
        try {
            $token = substr(strtr(base64_encode(random_bytes(18)), '+/', 'AZ'), 0, 24);
        } catch (Throwable $exception) {
            return '';
        }
        $wpdb->insert($this->telegram_actions_table, [
            'token_hash' => hash_hmac('sha256', $token, wp_salt('auth')),
            'ticket_id' => $ticket_id,
            'telegram_user_id' => $telegram_user_id,
            'action' => sanitize_key($action),
            'payload_json' => wp_json_encode($payload),
            'expires_at' => current_datetime()->modify('+' . max(1, min(30, $minutes)) . ' minutes')->format('Y-m-d H:i:s'),
            'created_at' => current_time('mysql'),
        ]);
        return $wpdb->insert_id ? $token : '';
    }

    public function consume_telegram_action(string $token, int $telegram_user_id): ?array
    {
        global $wpdb;
        $hash = hash_hmac('sha256', $token, wp_salt('auth'));
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->telegram_actions_table} WHERE token_hash = %s AND telegram_user_id = %d AND consumed_at IS NULL AND expires_at >= %s LIMIT 1",
            $hash,
            $telegram_user_id,
            current_time('mysql')
        ), ARRAY_A);
        if (! is_array($row)) {
            return null;
        }
        $wpdb->update($this->telegram_actions_table, ['consumed_at' => current_time('mysql')], ['id' => (int) $row['id']]);
        $row['payload'] = json_decode((string) $row['payload_json'], true);
        return $row;
    }

    private function normalize_telegram_permissions($permissions): array
    {
        $source = is_array($permissions) ? $permissions : [];
        $allowed = ['view', 'open_browser', 'claim', 'read', 'reply', 'spam'];
        return array_values(array_intersect($allowed, array_map('sanitize_key', array_map('strval', $source))));
    }

    public function normalize_ticket_mailboxes(array $settings): array
    {
        $mailboxes = [];
        $primary = [
            'label' => 'Standard',
            'email' => sanitize_email((string) ($settings['ticket_mailbox_email'] ?? '')),
            'host' => sanitize_text_field((string) ($settings['imap_host'] ?? '')),
            'port' => max(1, absint($settings['imap_port'] ?? 993)),
            'encryption' => in_array((string) ($settings['imap_encryption'] ?? 'ssl'), ['ssl', 'tls', 'none'], true) ? (string) $settings['imap_encryption'] : 'ssl',
            'username' => sanitize_text_field((string) ($settings['imap_username'] ?? '')),
            'password' => $this->decrypt_secret((string) ($settings['imap_password'] ?? '')),
            'folder' => sanitize_text_field((string) ($settings['imap_folder'] ?? 'INBOX')) ?: 'INBOX',
            'primary' => true,
        ];

        if ($primary['email'] !== '') {
            $mailboxes[] = $primary;
        }

        foreach ((array) ($settings['ticket_mailboxes'] ?? []) as $mailbox) {
            if (! is_array($mailbox)) {
                continue;
            }

            $email = sanitize_email((string) ($mailbox['email'] ?? ''));
            if ($email === '' || ($primary['email'] !== '' && strtolower($email) === strtolower($primary['email']))) {
                continue;
            }

            $encryption = (string) ($mailbox['encryption'] ?? 'ssl');
            if (! in_array($encryption, ['ssl', 'tls', 'none'], true)) {
                $encryption = 'ssl';
            }

            $mailboxes[] = [
                'label' => sanitize_text_field((string) ($mailbox['label'] ?? '')),
                'email' => $email,
                'host' => sanitize_text_field((string) ($mailbox['host'] ?? '')),
                'port' => max(1, absint($mailbox['port'] ?? 993)),
                'encryption' => $encryption,
                'username' => sanitize_text_field((string) ($mailbox['username'] ?? '')),
                'password' => $this->decrypt_secret((string) ($mailbox['password'] ?? '')),
                'folder' => sanitize_text_field((string) ($mailbox['folder'] ?? 'INBOX')) ?: 'INBOX',
                'primary' => false,
            ];
        }

        return array_values($mailboxes);
    }

    public function get_departments(bool $active_only = false): array
    {
        global $wpdb;

        $where = $active_only ? 'WHERE is_active = 1' : '';
        $rows = $wpdb->get_results("SELECT * FROM {$this->departments_table} {$where} ORDER BY sort_order ASC, name ASC", ARRAY_A);
        return is_array($rows) ? array_map([$this, 'hydrate_department'], $rows) : [];
    }

    public function get_department(int $id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->departments_table} WHERE id = %d", $id), ARRAY_A);
        return is_array($row) ? $this->hydrate_department($row) : null;
    }

    public function get_department_by_slug(string $slug): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->departments_table} WHERE slug = %s AND is_active = 1", $slug), ARRAY_A);
        return is_array($row) ? $this->hydrate_department($row) : null;
    }

    public function save_department(array $data): array
    {
        global $wpdb;

        $id = absint($data['id'] ?? 0);
        $now = current_time('mysql');
        $name = sanitize_text_field(trim((string) ($data['name'] ?? '')));
        $slug_input = trim((string) ($data['slug'] ?? ''));
        $slug = sanitize_title($slug_input !== '' ? $slug_input : $name);
        $email_input = trim((string) ($data['email'] ?? ''));
        $email = sanitize_email($email_input);

        if ($name === '') {
            return $this->save_error(
                'department_name_required',
                'Bitte geben Sie einen Namen für die Abteilung ein.'
            );
        }

        if ($slug === '') {
            return $this->save_error(
                'department_slug_invalid',
                'Aus Name oder Kurzname konnte kein gültiger Slug erstellt werden. Bitte verwenden Sie Buchstaben, Zahlen oder Bindestriche.'
            );
        }

        if ($email === '' || ! is_email($email_input)) {
            return $this->save_error(
                'department_email_invalid',
                'Bitte geben Sie eine gültige Empfänger-E-Mail-Adresse für diese Abteilung ein.'
            );
        }

        $duplicate_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->departments_table} WHERE slug = %s AND id <> %d LIMIT 1",
                $slug,
                $id
            )
        );

        if ($duplicate_id > 0) {
            return $this->save_error(
                'department_slug_exists',
                'Der Kurzname / Slug "' . $slug . '" ist bereits vergeben. Bitte wählen Sie einen anderen Kurzname.',
                ['slug' => $slug, 'conflict_id' => $duplicate_id]
            );
        }

        $sort_order = (int) ($data['sort_order'] ?? 0);
        if ($sort_order <= 0) {
            $sort_order = $this->next_department_sort_order($id);
        }

        $record = [
            'name' => $name,
            'slug' => $slug,
            'email' => $email,
            'description' => sanitize_textarea_field((string) ($data['description'] ?? '')),
            'sort_order' => $sort_order,
            'is_active' => empty($data['is_active']) ? 0 : 1,
            'updated_at' => $now,
        ];

        if ($id > 0) {
            $updated = $wpdb->update($this->departments_table, $record, ['id' => $id]);
            if ($updated === false) {
                return $this->save_error(
                    'department_update_failed',
                    'Die Abteilung konnte wegen eines Datenbankfehlers nicht aktualisiert werden.',
                    ['slug' => $slug, 'db_error' => (string) $wpdb->last_error]
                );
            }

            $this->log('department_updated', 'department', $id, $record);
            return $this->save_success($id);
        }

        $record['created_at'] = $now;
        $inserted = $wpdb->insert($this->departments_table, $record);
        if ($inserted === false) {
            return $this->save_error(
                'department_insert_failed',
                'Die Abteilung konnte wegen eines Datenbankfehlers nicht gespeichert werden.',
                ['slug' => $slug, 'db_error' => (string) $wpdb->last_error]
            );
        }

        $new_id = (int) $wpdb->insert_id;
        if ($new_id <= 0) {
            return $this->save_error(
                'department_insert_missing_id',
                'Die Abteilung wurde von der Datenbank nicht bestätigt. Bitte versuchen Sie es erneut.',
                ['slug' => $slug]
            );
        }

        $this->log('department_created', 'department', $new_id, $record);
        return $this->save_success($new_id);
    }

    public function deactivate_department(int $id): void
    {
        global $wpdb;

        $wpdb->update($this->departments_table, ['is_active' => 0, 'updated_at' => current_time('mysql')], ['id' => $id]);
        $this->log('department_deleted', 'department', $id, []);
    }

    public function move_department(int $id, string $direction): bool
    {
        global $wpdb;

        if ($id <= 0 || ! in_array($direction, ['up', 'down'], true)) {
            return false;
        }

        $departments = $this->get_departments();
        $current_index = null;

        foreach ($departments as $index => $department) {
            if ((int) $department['id'] === $id) {
                $current_index = $index;
                break;
            }
        }

        if ($current_index === null) {
            return false;
        }

        $target_index = $direction === 'up' ? $current_index - 1 : $current_index + 1;
        if (! isset($departments[$target_index])) {
            return false;
        }

        $current_department = $departments[$current_index];
        $departments[$current_index] = $departments[$target_index];
        $departments[$target_index] = $current_department;

        $now = current_time('mysql');
        foreach (array_values($departments) as $index => $department) {
            $wpdb->update(
                $this->departments_table,
                [
                    'sort_order' => ($index + 1) * 10,
                    'updated_at' => $now,
                ],
                ['id' => (int) $department['id']]
            );
        }

        $this->log('department_moved', 'department', $id, ['direction' => $direction]);
        return true;
    }

    public function get_topics(int $department_id = 0, bool $active_only = false): array
    {
        global $wpdb;

        $where = [];
        $args = [];

        if ($department_id > 0) {
            $where[] = 'department_id = %d';
            $args[] = $department_id;
        }

        if ($active_only) {
            $where[] = 'is_active = 1';
        }

        $sql = "SELECT * FROM {$this->topics_table}";
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY sort_order ASC, title ASC';

        if ($args !== []) {
            $sql = $wpdb->prepare($sql, $args);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? array_map([$this, 'hydrate_topic'], $rows) : [];
    }

    public function get_topic(int $id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->topics_table} WHERE id = %d", $id), ARRAY_A);
        return is_array($row) ? $this->hydrate_topic($row) : null;
    }

    public function get_topic_by_slug(int $department_id, string $slug): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->topics_table} WHERE department_id = %d AND slug = %s AND is_active = 1", $department_id, $slug),
            ARRAY_A
        );

        return is_array($row) ? $this->hydrate_topic($row) : null;
    }

    public function save_topic(array $data): array
    {
        global $wpdb;

        $id = absint($data['id'] ?? 0);
        $department_id = absint($data['department_id'] ?? 0);
        $title = sanitize_text_field(trim((string) ($data['title'] ?? '')));
        $slug_input = trim((string) ($data['slug'] ?? ''));
        $slug = sanitize_title($slug_input !== '' ? $slug_input : $title);
        $email_override_input = trim((string) ($data['email_override'] ?? ''));
        $email_override = sanitize_email($email_override_input);

        if ($department_id <= 0) {
            return $this->save_error(
                'topic_department_missing',
                'Das Thema konnte nicht gespeichert werden, weil keine gültige Abteilung ausgewählt wurde.'
            );
        }

        if ($title === '') {
            return $this->save_error(
                'topic_title_required',
                'Bitte geben Sie einen Namen für das Thema ein.'
            );
        }

        if ($slug === '') {
            return $this->save_error(
                'topic_slug_invalid',
                'Aus Thema oder Kurzname konnte kein gültiger Slug erstellt werden. Bitte verwenden Sie Buchstaben, Zahlen oder Bindestriche.'
            );
        }

        if ($email_override_input !== '' && ($email_override === '' || ! is_email($email_override_input))) {
            return $this->save_error(
                'topic_email_invalid',
                'Die abweichende Empfänger-E-Mail für dieses Thema ist ungültig.'
            );
        }

        $duplicate_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->topics_table} WHERE department_id = %d AND slug = %s AND id <> %d LIMIT 1",
                $department_id,
                $slug,
                $id
            )
        );

        if ($duplicate_id > 0) {
            return $this->save_error(
                'topic_slug_exists',
                'Der Kurzname / Slug "' . $slug . '" ist in dieser Abteilung bereits vergeben. Bitte wählen Sie einen anderen Kurzname.',
                ['department_id' => $department_id, 'slug' => $slug, 'conflict_id' => $duplicate_id]
            );
        }

        $now = current_time('mysql');
        $fields = $this->normalize_fields(is_array($data['fields'] ?? null) ? $data['fields'] : self::default_fields());
        $sort_order = (int) ($data['sort_order'] ?? 0);
        if ($sort_order <= 0) {
            $sort_order = $this->next_topic_sort_order($department_id, $id);
        }

        $record = [
            'department_id' => $department_id,
            'title' => $title,
            'slug' => $slug,
            'email_override' => $email_override,
            'auto_reply_enabled' => empty($data['auto_reply_enabled']) ? 0 : 1,
            'auto_reply_format' => in_array((string) ($data['auto_reply_format'] ?? 'html'), ['text', 'html'], true) ? (string) $data['auto_reply_format'] : 'html',
            'auto_subject' => sanitize_text_field((string) ($data['auto_subject'] ?? self::default_auto_subject())),
            'auto_body' => sanitize_textarea_field((string) ($data['auto_body'] ?? self::default_auto_body())),
            'auto_html' => $this->sanitize_auto_html((string) ($data['auto_html'] ?? self::default_auto_html_body())),
            'fields_json' => wp_json_encode($fields),
            'sort_order' => $sort_order,
            'is_active' => empty($data['is_active']) ? 0 : 1,
            'updated_at' => $now,
        ];

        if ($id > 0) {
            $updated = $wpdb->update($this->topics_table, $record, ['id' => $id]);
            if ($updated === false) {
                return $this->save_error(
                    'topic_update_failed',
                    'Das Thema konnte wegen eines Datenbankfehlers nicht aktualisiert werden.',
                    ['department_id' => $department_id, 'slug' => $slug, 'db_error' => (string) $wpdb->last_error]
                );
            }

            $this->log('topic_updated', 'topic', $id, ['department_id' => $department_id, 'title' => $title]);
            return $this->save_success($id);
        }

        $record['created_at'] = $now;
        $inserted = $wpdb->insert($this->topics_table, $record);
        if ($inserted === false) {
            return $this->save_error(
                'topic_insert_failed',
                'Das Thema konnte wegen eines Datenbankfehlers nicht gespeichert werden.',
                ['department_id' => $department_id, 'slug' => $slug, 'db_error' => (string) $wpdb->last_error]
            );
        }

        $new_id = (int) $wpdb->insert_id;
        if ($new_id <= 0) {
            return $this->save_error(
                'topic_insert_missing_id',
                'Das Thema wurde von der Datenbank nicht bestätigt. Bitte versuchen Sie es erneut.',
                ['department_id' => $department_id, 'slug' => $slug]
            );
        }

        $this->log('topic_created', 'topic', $new_id, ['department_id' => $department_id, 'title' => $title]);
        return $this->save_success($new_id);
    }

    public function deactivate_topic(int $id): void
    {
        global $wpdb;

        $wpdb->update($this->topics_table, ['is_active' => 0, 'updated_at' => current_time('mysql')], ['id' => $id]);
        $this->log('topic_deleted', 'topic', $id, []);
    }

    public function public_config(?string $department_slug = null, ?string $topic_slug = null): array
    {
        $departments = [];

        foreach ($this->get_departments(true) as $department) {
            if ($department_slug !== null && $department['slug'] !== $department_slug) {
                continue;
            }

            $topics = [];
            foreach ($this->get_topics((int) $department['id'], true) as $topic) {
                if ($topic_slug !== null && $topic['slug'] !== $topic_slug) {
                    continue;
                }

                $topics[] = [
                    'id' => (int) $topic['id'],
                    'title' => $topic['title'],
                    'slug' => $topic['slug'],
                    'fields' => $topic['fields'],
                ];
            }

            if ($topics === []) {
                continue;
            }

            $departments[] = [
                'id' => (int) $department['id'],
                'name' => $department['name'],
                'slug' => $department['slug'],
                'description' => $department['description'],
                'topics' => $topics,
            ];
        }

        return $departments;
    }

    public function generate_ticket_code(): string
    {
        global $wpdb;

        for ($i = 0; $i < 50; $i++) {
            $code = (string) random_int(10000000, 99999999);
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tickets_table} WHERE ticket_code = %s LIMIT 1", $code));
            if ($exists === null) {
                return $code;
            }
        }

        return substr((string) random_int(10000000, 99999999), 0, 8);
    }

    public function create_ticket(array $data): int
    {
        global $wpdb;

        $now = current_time('mysql');
        $record = [
            'ticket_code' => sanitize_text_field((string) $data['ticket_code']),
            'origin' => sanitize_key((string) ($data['origin'] ?? 'web_form')) ?: 'web_form',
            'department_id' => absint($data['department_id']),
            'topic_id' => absint($data['topic_id']),
            'subject' => sanitize_text_field((string) ($data['subject'] ?? '')),
            'sender_name' => sanitize_text_field((string) ($data['sender_name'] ?? '')),
            'sender_email' => sanitize_email((string) ($data['sender_email'] ?? '')),
            'sender_phone' => sanitize_text_field((string) ($data['sender_phone'] ?? '')),
            'message' => sanitize_textarea_field((string) ($data['message'] ?? '')),
            'fields_json' => wp_json_encode($data['fields'] ?? []),
            'status' => in_array((string) ($data['status'] ?? 'open'), ['open', 'in_progress', 'quarantined', 'spam', 'resolved'], true) ? (string) ($data['status'] ?? 'open') : 'open',
            'privacy_required' => empty($data['privacy_required']) ? 0 : 1,
            'privacy_accepted' => empty($data['privacy_accepted']) ? 0 : 1,
            'privacy_version' => sanitize_text_field((string) ($data['privacy_version'] ?? '')),
            'privacy_url' => esc_url_raw((string) ($data['privacy_url'] ?? '')),
            'consent_text' => sanitize_textarea_field((string) ($data['consent_text'] ?? '')),
            'ip_address' => sanitize_text_field((string) ($data['ip_address'] ?? '')),
            'visitor_cookie' => sanitize_text_field((string) ($data['visitor_cookie'] ?? '')),
            'browser' => sanitize_text_field((string) ($data['browser'] ?? '')),
            'device_type' => sanitize_text_field((string) ($data['device_type'] ?? '')),
            'user_agent' => sanitize_textarea_field((string) ($data['user_agent'] ?? '')),
            'captcha_required' => empty($data['captcha_required']) ? 0 : 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $inserted = $wpdb->insert($this->tickets_table, $record);
        if ($inserted === false) {
            $this->log('ticket_create_failed', 'ticket', 0, [
                'ticket_code' => (string) $record['ticket_code'],
                'department_id' => (int) $record['department_id'],
                'topic_id' => (int) $record['topic_id'],
                'db_error' => (string) $wpdb->last_error,
            ]);

            return 0;
        }

        $ticket_id = (int) $wpdb->insert_id;
        if ($ticket_id <= 0) {
            $this->log('ticket_create_failed', 'ticket', 0, [
                'ticket_code' => (string) $record['ticket_code'],
                'department_id' => (int) $record['department_id'],
                'topic_id' => (int) $record['topic_id'],
                'db_error' => 'insert_id ist leer',
            ]);

            return 0;
        }

        $this->add_message($ticket_id, 'inbound', $record['sender_email'], (string) ($data['recipient_email'] ?? ''), $record['message'], $data);
        $this->log('ticket_created', 'ticket', $ticket_id, ['ticket_code' => $record['ticket_code']]);
        do_action('feuer_kontaktform_ticket_created', $ticket_id);

        return $ticket_id;
    }

    public function update_ticket_workflow(int $ticket_id, string $status, int $actor_id = 0, string $reason = ''): bool
    {
        global $wpdb;
        $allowed = ['open', 'in_progress', 'quarantined', 'spam', 'resolved'];
        if ($ticket_id <= 0 || ! in_array($status, $allowed, true)) {
            return false;
        }
        $updated = $wpdb->update($this->tickets_table, [
            'status' => $status,
            'updated_at' => current_time('mysql'),
        ], ['id' => $ticket_id], ['%s', '%s'], ['%d']);
        if ($updated === false) {
            return false;
        }
        $this->log('ticket_workflow_updated', 'ticket', $ticket_id, [
            'status' => $status,
            'actor_id' => $actor_id,
            'reason' => sanitize_text_field($reason),
        ]);
        return true;
    }

    public function add_message(int $ticket_id, string $direction, string $from, string $to, string $body, array $raw = []): void
    {
        global $wpdb;

        $wpdb->insert($this->messages_table, [
            'ticket_id' => $ticket_id,
            'direction' => sanitize_key($direction),
            'source' => sanitize_key((string) ($raw['source'] ?? '')),
            'external_id' => sanitize_text_field((string) ($raw['external_id'] ?? '')),
            'from_email' => sanitize_email($from),
            'to_email' => sanitize_email($to),
            'body' => sanitize_textarea_field($body),
            'raw_json' => wp_json_encode($raw),
            'created_at' => current_time('mysql'),
        ]);

        $wpdb->update(
            $this->tickets_table,
            ['updated_at' => current_time('mysql')],
            ['id' => $ticket_id],
            ['%s'],
            ['%d']
        );
    }

    public function message_exists_by_external_id(string $external_id): bool
    {
        global $wpdb;

        $external_id = sanitize_text_field($external_id);
        if ($external_id === '') {
            return false;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->messages_table} WHERE external_id = %s",
                $external_id
            )
        ) > 0;
    }

    public function record_delivery_attempt(int $ticket_id, string $recipient, array $result): void
    {
        global $wpdb;

        $success = ! empty($result['success']);
        $error = $success ? '' : sanitize_textarea_field((string) ($result['message'] ?? 'Unbekannter Fehler beim E-Mail-Versand.'));
        $now = current_time('mysql');
        $status = $success ? 'accepted' : 'failed';

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->tickets_table}
                 SET delivery_status = %s,
                     delivery_recipient = %s,
                     delivery_attempts = delivery_attempts + 1,
                     delivery_last_error = %s,
                     delivery_last_attempt_at = %s,
                     delivery_accepted_at = CASE WHEN %s = 'accepted' THEN %s ELSE NULL END,
                     delivery_confirmed_at = NULL,
                     updated_at = %s
                 WHERE id = %d",
                $status,
                sanitize_email($recipient),
                $error,
                $now,
                $status,
                $now,
                $now,
                $ticket_id
            )
        );

        $this->log(
            $success ? 'ticket_delivery_accepted' : 'ticket_delivery_failed',
            'ticket',
            $ticket_id,
            [
                'recipient' => sanitize_email($recipient),
                'message' => $success ? 'Die Nachricht wurde vom konfigurierten Versandweg angenommen.' : $error,
                'database_updated' => $updated !== false,
            ]
        );
    }

    public function confirm_delivery(int $ticket_id): bool
    {
        global $wpdb;

        $now = current_time('mysql');
        $updated = $wpdb->update(
            $this->tickets_table,
            [
                'delivery_status' => 'confirmed',
                'delivery_confirmed_at' => $now,
                'updated_at' => $now,
            ],
            ['id' => $ticket_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return false;
        }

        $this->log('ticket_delivery_confirmed', 'ticket', $ticket_id, [
            'confirmation' => 'Der Empfänger hat den Empfang über den Bestätigungslink bestätigt.',
        ]);

        return true;
    }

    public function issue_department_access(int $ticket_id, string $recipient, int $days = 30): array
    {
        global $wpdb;

        $recipient = sanitize_email($recipient);
        if ($ticket_id <= 0 || $recipient === '') {
            return [
                'success' => false,
                'message' => 'Für den geschützten Zugriff fehlen Anfrage oder Empfänger-E-Mail.',
            ];
        }

        $token = $this->generate_access_code();
        if ($token === '') {
            return [
                'success' => false,
                'message' => 'Der sichere Zugriffsschlüssel konnte nicht erzeugt werden.',
            ];
        }

        $expires_at = current_datetime()
            ->modify('+' . max(1, min(90, $days)) . ' days')
            ->format('Y-m-d H:i:s');
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->tickets_table}
                 SET access_token_hash = %s,
                     access_recipient = %s,
                     access_expires_at = %s,
                     access_last_used_at = NULL,
                     access_revoked_at = NULL,
                     updated_at = %s
                 WHERE id = %d",
                $this->hash_access_token($token),
                $recipient,
                $expires_at,
                current_time('mysql'),
                $ticket_id
            )
        );

        if ($updated === false) {
            return [
                'success' => false,
                'message' => 'Der sichere Zugriffslink konnte nicht in der Datenbank gespeichert werden.',
            ];
        }

        $this->log('ticket_access_issued', 'ticket', $ticket_id, [
            'recipient' => $recipient,
            'expires_at' => $expires_at,
        ]);

        return [
            'success' => true,
            'token' => $token,
            'code' => $token,
            'expires_at' => $expires_at,
            'recipient' => $recipient,
        ];
    }

    public function validate_department_access(int $ticket_id, string $token): ?array
    {
        $ticket = $this->get_ticket($ticket_id);
        if (
            ! $ticket
            || $token === ''
            || (string) ($ticket['access_token_hash'] ?? '') === ''
            || ! empty($ticket['access_revoked_at'])
            || (string) ($ticket['access_expires_at'] ?? '') < current_time('mysql')
            || (
                ! hash_equals((string) $ticket['access_token_hash'], $this->hash_access_token($token))
                && ! hash_equals((string) $ticket['access_token_hash'], $this->hash_legacy_access_token($token))
            )
        ) {
            return null;
        }

        return $ticket;
    }

    public function mark_department_access_used(int $ticket_id): void
    {
        global $wpdb;

        $wpdb->update(
            $this->tickets_table,
            [
                'access_last_used_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $ticket_id],
            ['%s', '%s'],
            ['%d']
        );
    }

    public function revoke_department_access(int $ticket_id): bool
    {
        global $wpdb;

        $now = current_time('mysql');
        $updated = $wpdb->update(
            $this->tickets_table,
            [
                'access_token_hash' => '',
                'access_revoked_at' => $now,
                'updated_at' => $now,
            ],
            ['id' => $ticket_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return false;
        }

        $this->log('ticket_access_revoked', 'ticket', $ticket_id, []);
        return true;
    }

    public function get_ticket(int $id): ?array
    {
        global $wpdb;

        $sql = "SELECT t.*, d.name AS department_name, d.email AS department_email, p.title AS topic_title, p.email_override AS topic_email
            FROM {$this->tickets_table} t
            LEFT JOIN {$this->departments_table} d ON d.id = t.department_id
            LEFT JOIN {$this->topics_table} p ON p.id = t.topic_id
            WHERE t.id = %d";

        $row = $wpdb->get_row($wpdb->prepare($sql, $id), ARRAY_A);
        return is_array($row) ? $this->hydrate_ticket($row) : null;
    }

    public function get_ticket_by_code(string $ticket_code): ?array
    {
        global $wpdb;

        $ticket_code = sanitize_text_field($ticket_code);
        if (! preg_match('/^\d{8}$/', $ticket_code)) {
            return null;
        }

        $id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->tickets_table} WHERE ticket_code = %s LIMIT 1",
                $ticket_code
            )
        );

        return $id > 0 ? $this->get_ticket($id) : null;
    }

    public function get_tickets(int $limit = 100, string $search = ''): array
    {
        global $wpdb;

        $where = '';
        $args = [];
        $search = trim($search);

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where = 'WHERE t.sender_email LIKE %s OR t.sender_name LIKE %s OR t.ticket_code LIKE %s';
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
        }

        $sql = "SELECT t.*, d.name AS department_name, d.email AS department_email, p.title AS topic_title, p.email_override AS topic_email
            FROM {$this->tickets_table} t
            LEFT JOIN {$this->departments_table} d ON d.id = t.department_id
            LEFT JOIN {$this->topics_table} p ON p.id = t.topic_id
            {$where}
            ORDER BY t.created_at DESC
            LIMIT %d";

        $args[] = max(1, min(500, $limit));
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
        return is_array($rows) ? array_map([$this, 'hydrate_ticket'], $rows) : [];
    }

    public function get_ticket_messages(int $ticket_id): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$this->messages_table} WHERE ticket_id = %d ORDER BY created_at ASC", $ticket_id),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    public function get_latest_ticket_message(int $ticket_id, string $direction = ''): ?array
    {
        global $wpdb;

        $direction = sanitize_key($direction);
        $sql = "SELECT * FROM {$this->messages_table} WHERE ticket_id = %d";
        $args = [$ticket_id];

        if ($direction !== '') {
            $sql .= ' AND direction = %s';
            $args[] = $direction;
        }

        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 1';
        $row = $wpdb->get_row($wpdb->prepare($sql, $args), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function get_tickets_by_email(string $email, int $limit = 200): array
    {
        global $wpdb;

        $email = sanitize_email($email);
        if ($email === '') {
            return [];
        }

        $sql = "SELECT t.*, d.name AS department_name, d.email AS department_email, p.title AS topic_title, p.email_override AS topic_email
            FROM {$this->tickets_table} t
            LEFT JOIN {$this->departments_table} d ON d.id = t.department_id
            LEFT JOIN {$this->topics_table} p ON p.id = t.topic_id
            WHERE t.sender_email = %s
            ORDER BY t.created_at DESC
            LIMIT %d";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $email, max(1, min(500, $limit))), ARRAY_A);
        return is_array($rows) ? array_map([$this, 'hydrate_ticket'], $rows) : [];
    }

    public function erase_personal_data(string $email): int
    {
        global $wpdb;

        $email = sanitize_email($email);
        if ($email === '') {
            return 0;
        }

        $tickets = $this->get_tickets_by_email($email, 500);
        foreach ($tickets as $ticket) {
            $this->delete_ticket_uploads((array) ($ticket['fields'] ?? []));

            $wpdb->update($this->tickets_table, [
                'sender_name' => 'Anonymisiert',
                'sender_email' => '',
                'sender_phone' => '',
                'message' => '',
                'fields_json' => wp_json_encode([]),
                'ip_address' => '',
                'visitor_cookie' => '',
                'browser' => '',
                'device_type' => '',
                'user_agent' => '',
                'access_token_hash' => '',
                'access_revoked_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ], ['id' => (int) $ticket['id']]);

            $wpdb->update($this->messages_table, [
                'external_id' => '',
                'from_email' => '',
                'to_email' => '',
                'body' => '',
                'raw_json' => wp_json_encode(['privacy_erased' => true]),
            ], ['ticket_id' => (int) $ticket['id']]);
            $wpdb->update($this->logs_table, [
                'context_json' => wp_json_encode(['privacy_erased' => true]),
            ], [
                'object_type' => 'ticket',
                'object_id' => (int) $ticket['id'],
            ]);
        }

        return count($tickets);
    }

    public function has_recent_ticket(string $ip_address, string $email, int $minutes): bool
    {
        global $wpdb;

        $threshold = gmdate('Y-m-d H:i:s', time() - max(1, $minutes) * MINUTE_IN_SECONDS);
        $ip_address = sanitize_text_field($ip_address);
        $email = sanitize_email($email);

        if ($ip_address === '' && $email === '') {
            return false;
        }

        if ($email === '') {
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->tickets_table} WHERE created_at >= %s AND ip_address = %s",
                    $threshold,
                    $ip_address
                )
            );

            return (int) $count > 0;
        }

        if ($ip_address === '') {
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->tickets_table} WHERE created_at >= %s AND sender_email = %s",
                    $threshold,
                    $email
                )
            );

            return (int) $count > 0;
        }

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tickets_table} WHERE created_at >= %s AND (ip_address = %s OR sender_email = %s)",
                $threshold,
                $ip_address,
                $email
            )
        );

        return (int) $count > 0;
    }

    public function get_logs(int $limit = 150): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$this->logs_table} ORDER BY created_at DESC LIMIT %d", max(1, min(500, $limit))),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    public function export_backup_payload(): array
    {
        return [
            'format' => 'feuer-kontaktform-backup',
            'plugin_version' => defined('FEUER_KONTAKTFORM_VERSION') ? (string) FEUER_KONTAKTFORM_VERSION : '',
            'created_at' => current_time('mysql'),
            'site_url' => home_url('/'),
            'settings' => $this->get_settings(),
            'departments' => $this->backup_rows($this->departments_table),
            'topics' => $this->backup_rows($this->topics_table),
            'tickets' => $this->backup_rows($this->tickets_table),
            'messages' => $this->backup_rows($this->messages_table),
            'logs' => $this->backup_rows($this->logs_table),
        ];
    }

    public function export_tickets_backup_payload(): array
    {
        return [
            'format' => 'feuer-kontaktform-tickets-backup',
            'plugin_version' => defined('FEUER_KONTAKTFORM_VERSION') ? (string) FEUER_KONTAKTFORM_VERSION : '',
            'created_at' => current_time('mysql'),
            'site_url' => home_url('/'),
            'departments' => $this->backup_rows($this->departments_table),
            'topics' => $this->backup_rows($this->topics_table),
            'tickets' => $this->backup_rows($this->tickets_table),
            'messages' => $this->backup_rows($this->messages_table),
        ];
    }

    public function import_backup_payload(array $payload): array
    {
        global $wpdb;

        if (($payload['format'] ?? '') !== 'feuer-kontaktform-backup') {
            return [
                'success' => false,
                'message' => 'Die Sicherungsdatei gehört nicht zu diesem Plugin oder ist beschädigt.',
            ];
        }

        $settings = is_array($payload['settings'] ?? null) ? $payload['settings'] : null;
        $departments = is_array($payload['departments'] ?? null) ? $payload['departments'] : null;
        $topics = is_array($payload['topics'] ?? null) ? $payload['topics'] : null;
        $tickets = is_array($payload['tickets'] ?? null) ? $payload['tickets'] : null;
        $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : null;
        $logs = is_array($payload['logs'] ?? null) ? $payload['logs'] : null;

        if ($settings === null || $departments === null || $topics === null || $tickets === null || $messages === null || $logs === null) {
            return [
                'success' => false,
                'message' => 'Die Sicherungsdatei enthält nicht alle erforderlichen Datenbereiche.',
            ];
        }

        $this->create_tables();
        $wpdb->query('START TRANSACTION');

        if (
            ! $this->replace_table_rows($this->departments_table, $departments, ['id', 'name', 'slug', 'email', 'description', 'sort_order', 'is_active', 'created_at', 'updated_at'])
            || ! $this->replace_table_rows($this->topics_table, $topics, ['id', 'department_id', 'title', 'slug', 'email_override', 'auto_reply_enabled', 'auto_reply_format', 'auto_subject', 'auto_body', 'auto_html', 'fields_json', 'sort_order', 'is_active', 'created_at', 'updated_at'])
            || ! $this->replace_table_rows($this->tickets_table, $tickets, ['id', 'ticket_code', 'origin', 'department_id', 'topic_id', 'subject', 'sender_name', 'sender_email', 'sender_phone', 'message', 'fields_json', 'status', 'delivery_status', 'delivery_recipient', 'delivery_attempts', 'delivery_last_error', 'delivery_last_attempt_at', 'delivery_accepted_at', 'delivery_confirmed_at', 'access_token_hash', 'access_recipient', 'access_expires_at', 'access_last_used_at', 'access_revoked_at', 'privacy_required', 'privacy_accepted', 'privacy_version', 'privacy_url', 'consent_text', 'ip_address', 'visitor_cookie', 'browser', 'device_type', 'user_agent', 'captcha_required', 'created_at', 'updated_at'])
            || ! $this->replace_table_rows($this->messages_table, $messages, ['id', 'ticket_id', 'direction', 'source', 'external_id', 'from_email', 'to_email', 'body', 'raw_json', 'created_at'])
            || ! $this->replace_table_rows($this->logs_table, $logs, ['id', 'actor_id', 'action', 'object_type', 'object_id', 'ip_address', 'user_agent', 'context_json', 'created_at'])
        ) {
            $wpdb->query('ROLLBACK');
            return [
                'success' => false,
                'message' => 'Die Sicherung konnte nicht vollständig wiederhergestellt werden. ' . (string) $wpdb->last_error,
            ];
        }

        update_option(self::OPTION_SETTINGS, wp_parse_args($settings, self::default_settings()), false);
        update_option(self::OPTION_VERSION, defined('FEUER_KONTAKTFORM_VERSION') ? (string) FEUER_KONTAKTFORM_VERSION : '', false);
        $wpdb->query('COMMIT');

        $this->log('backup_restored', 'backup', 0, [
            'plugin_version' => sanitize_text_field((string) ($payload['plugin_version'] ?? '')),
            'created_at' => sanitize_text_field((string) ($payload['created_at'] ?? '')),
            'site_url' => esc_url_raw((string) ($payload['site_url'] ?? '')),
            'departments' => count($departments),
            'topics' => count($topics),
            'tickets' => count($tickets),
            'messages' => count($messages),
            'logs' => count($logs),
        ]);

        return [
            'success' => true,
            'message' => 'Sicherung wurde erfolgreich wiederhergestellt.',
        ];
    }

    public function log(string $action, string $object_type, int $object_id, array $context = []): void
    {
        global $wpdb;

        $wpdb->insert($this->logs_table, [
            'actor_id' => get_current_user_id(),
            'action' => sanitize_key($action),
            'object_type' => sanitize_key($object_type),
            'object_id' => $object_id,
            'ip_address' => $this->current_ip(),
            'user_agent' => sanitize_textarea_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
            'context_json' => wp_json_encode($context),
            'created_at' => current_time('mysql'),
        ]);
    }

    private function seed_defaults(): void
    {
        if ($this->get_departments() !== []) {
            return;
        }

        $settings = $this->get_settings();
        $result = $this->save_department([
            'name' => 'Wehrführung',
            'slug' => 'wehrfuehrung',
            'email' => (string) $settings['base_email'],
            'description' => 'Allgemeine Kontaktstelle der Feuerwehr.',
            'is_active' => 1,
        ]);
        $department_id = (int) ($result['id'] ?? 0);

        if ($department_id > 0) {
            $this->log('default_department_created', 'department', $department_id, ['note' => 'Keine Standard-Themen angelegt.']);
        }
    }

    private function next_department_sort_order(int $exclude_id = 0): int
    {
        global $wpdb;

        $max = (int) $wpdb->get_var(
            $exclude_id > 0
                ? $wpdb->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM {$this->departments_table} WHERE id <> %d", $exclude_id)
                : "SELECT COALESCE(MAX(sort_order), 0) FROM {$this->departments_table}"
        );

        return $max > 0 ? $max + 10 : 10;
    }

    private function next_topic_sort_order(int $department_id, int $exclude_id = 0): int
    {
        global $wpdb;

        $max = (int) $wpdb->get_var(
            $exclude_id > 0
                ? $wpdb->prepare(
                    "SELECT COALESCE(MAX(sort_order), 0) FROM {$this->topics_table} WHERE department_id = %d AND id <> %d",
                    $department_id,
                    $exclude_id
                )
                : $wpdb->prepare(
                    "SELECT COALESCE(MAX(sort_order), 0) FROM {$this->topics_table} WHERE department_id = %d",
                    $department_id
                )
        );

        return $max > 0 ? $max + 10 : 10;
    }

    private function save_success(int $id): array
    {
        return [
            'success' => $id > 0,
            'id' => $id,
            'message' => '',
            'code' => '',
            'context' => [],
        ];
    }

    private function save_error(string $code, string $message, array $context = []): array
    {
        $this->log($code, 'validation', 0, $context + ['message' => $message]);

        return [
            'success' => false,
            'id' => 0,
            'message' => $message,
            'code' => $code,
            'context' => $context,
        ];
    }

    private function backup_rows(string $table): array
    {
        global $wpdb;

        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    private function replace_table_rows(string $table, array $rows, array $columns): bool
    {
        global $wpdb;

        if ($wpdb->query("DELETE FROM {$table}") === false) {
            return false;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $record = [];
            foreach ($columns as $column) {
                if (! array_key_exists($column, $row)) {
                    continue;
                }

                $value = $row[$column];
                if (is_bool($value)) {
                    $value = $value ? 1 : 0;
                } elseif (is_array($value) || is_object($value)) {
                    $value = wp_json_encode($value);
                }

                $record[$column] = $value;
            }

            if ($record === []) {
                continue;
            }

            if ($wpdb->insert($table, $record) === false) {
                return false;
            }
        }

        return true;
    }

    private function normalize_fields(array $fields): array
    {
        $normalized = [];

        foreach ($fields as $index => $field) {
            if (! is_array($field)) {
                continue;
            }

            $label = sanitize_text_field((string) ($field['label'] ?? ''));
            $key = sanitize_key((string) ($field['key'] ?? $label));
            if ($label === '' || $key === '') {
                continue;
            }

            $type = sanitize_key((string) ($field['type'] ?? 'text'));
            if (! in_array($type, ['text', 'email', 'tel', 'textarea', 'select', 'file'], true)) {
                $type = 'text';
            }

            $width = sanitize_key((string) ($field['width'] ?? 'full'));
            if (! in_array($width, ['full', 'half', 'third'], true)) {
                $width = 'full';
            }

            $options = [];
            if (isset($field['options'])) {
                $raw_options = is_array($field['options']) ? $field['options'] : preg_split('/[\r\n,;]+/', (string) $field['options']);
                foreach ((array) $raw_options as $option) {
                    $option = sanitize_text_field((string) $option);
                    if ($option !== '') {
                        $options[] = $option;
                    }
                }
            }

            $normalized[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'width' => $width,
                'required' => ! empty($field['required']),
                'options' => $options,
                'sort_order' => (int) ($field['sort_order'] ?? $index),
            ];
        }

        usort($normalized, static function (array $a, array $b): int {
            return $a['sort_order'] <=> $b['sort_order'];
        });

        return $normalized !== [] ? $normalized : self::default_fields();
    }

    private function sanitize_auto_html(string $html): string
    {
        $html = wp_kses_post($html);
        $html = preg_replace('/\s(src|href)=["\']data:[^"\']*["\']/i', '', $html) ?: $html;
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?: $html;

        return trim($html);
    }

    private function hydrate_department(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['sort_order'] = (int) $row['sort_order'];
        $row['is_active'] = ! empty($row['is_active']);
        return $row;
    }

    private function hydrate_topic(array $row): array
    {
        $decoded = json_decode((string) ($row['fields_json'] ?? ''), true);
        $row['id'] = (int) $row['id'];
        $row['department_id'] = (int) $row['department_id'];
        $row['sort_order'] = (int) $row['sort_order'];
        $row['is_active'] = ! empty($row['is_active']);
        $row['auto_reply_enabled'] = ! array_key_exists('auto_reply_enabled', $row) || ! empty($row['auto_reply_enabled']);
        $row['auto_reply_format'] = in_array((string) ($row['auto_reply_format'] ?? 'html'), ['text', 'html'], true) ? (string) $row['auto_reply_format'] : 'html';
        $stored_subject = (string) ($row['auto_subject'] ?? '');
        $row['auto_subject'] = $stored_subject === '' || $stored_subject === 'Ihre Anfrage Nr. {{ticket_id}} wurde empfangen'
            ? self::default_auto_subject()
            : $stored_subject;
        $row['auto_body'] = (string) ($row['auto_body'] ?: self::default_auto_body());
        $row['auto_html'] = (string) (($row['auto_html'] ?? '') ?: self::default_auto_html_body());
        $row['fields'] = $this->normalize_fields(is_array($decoded) ? $decoded : self::default_fields());
        return $row;
    }

    private function hydrate_ticket(array $row): array
    {
        $decoded = json_decode((string) ($row['fields_json'] ?? ''), true);
        $row['id'] = (int) $row['id'];
        $row['origin'] = sanitize_key((string) ($row['origin'] ?? 'web_form')) ?: 'web_form';
        $row['department_id'] = (int) $row['department_id'];
        $row['topic_id'] = (int) $row['topic_id'];
        $row['privacy_required'] = ! array_key_exists('privacy_required', $row) || ! empty($row['privacy_required']);
        $row['privacy_accepted'] = ! empty($row['privacy_accepted']);
        $row['captcha_required'] = ! empty($row['captcha_required']);
        $row['delivery_attempts'] = (int) ($row['delivery_attempts'] ?? 0);
        $row['delivery_status'] = (string) ($row['delivery_status'] ?? 'pending');
        $row['fields'] = is_array($decoded) ? $decoded : [];

        if ($row['origin'] === 'direct_email') {
            $row['department_name'] = (string) ($row['department_name'] ?: 'Direkte E-Mail');
            $row['department_email'] = (string) ($row['department_email'] ?: ($row['delivery_recipient'] ?? ''));
            $row['topic_title'] = (string) ($row['topic_title'] ?: 'E-Mail-Anfrage');
            $row['topic_email'] = (string) ($row['topic_email'] ?: ($row['delivery_recipient'] ?? ''));
        }

        return $row;
    }

    private function current_ip(): string
    {
        $candidates = [
            (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
            (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $ip = trim(explode(',', $candidate)[0]);
            if ($ip !== '') {
                return sanitize_text_field($ip);
            }
        }

        return '';
    }

    private function hash_access_token(string $token): string
    {
        return hash_hmac('sha256', strtoupper(trim($token)), wp_salt('auth'));
    }

    private function encrypt_secret(string $value): string
    {
        if ($value === '' || $this->string_starts_with($value, 'enc:') || $this->string_starts_with($value, 'encg:')) {
            return $value;
        }

        if (! function_exists('openssl_encrypt')) {
            return $value;
        }

        $key = hash('sha256', wp_salt('secure_auth'), true);
        try {
            $iv = random_bytes(12);
        } catch (Throwable $exception) {
            return $value;
        }
        $tag = '';
        $encrypted = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if (! is_string($encrypted)) {
            return $value;
        }

        return 'encg:' . base64_encode($iv . $tag . $encrypted);
    }

    private function decrypt_secret(string $value): string
    {
        if ($value === '' || ! function_exists('openssl_decrypt')) {
            return $value;
        }

        $key = hash('sha256', wp_salt('secure_auth'), true);
        if ($this->string_starts_with($value, 'encg:')) {
            $raw = base64_decode(substr($value, 5), true);
            if (! is_string($raw) || strlen($raw) <= 28) {
                return '';
            }
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $payload = substr($raw, 28);
            $decrypted = openssl_decrypt($payload, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return is_string($decrypted) ? $decrypted : '';
        }
        if (! $this->string_starts_with($value, 'enc:')) {
            return $value;
        }

        $raw = base64_decode(substr($value, 4), true);
        if (! is_string($raw) || strlen($raw) <= 16) {
            return '';
        }

        $iv = substr($raw, 0, 16);
        $payload = substr($raw, 16);
        $decrypted = openssl_decrypt($payload, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return is_string($decrypted) ? $decrypted : '';
    }

    private function delete_ticket_uploads(array $fields): void
    {
        $uploads = wp_get_upload_dir();
        $base_url = isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '';
        $base_dir = isset($uploads['basedir']) ? wp_normalize_path((string) $uploads['basedir']) : '';
        if ($base_url === '' || $base_dir === '') {
            return;
        }

        foreach ($fields as $value) {
            foreach ((array) $value as $maybe_url) {
                $url = esc_url_raw((string) $maybe_url);
                if ($url === '' || ! $this->string_starts_with($url, $base_url)) {
                    continue;
                }

                $relative = ltrim(substr($url, strlen($base_url)), '/');
                $path = wp_normalize_path($base_dir . '/' . $relative);
                if ($this->string_starts_with($path, $base_dir . '/') && is_file($path)) {
                    wp_delete_file($path);
                }
            }
        }
    }

    private function hash_legacy_access_token(string $token): string
    {
        return hash_hmac('sha256', $token, wp_salt('auth'));
    }

    private function string_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }

    private function generate_access_code(): string
    {
        try {
            return strtoupper(substr(strtr(base64_encode(random_bytes(9)), '+/', 'AZ'), 0, 12));
        } catch (Throwable $exception) {
            return '';
        }
    }
}
