<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Feuer_Kontaktform_Support_Plugin
{
    private Feuer_Kontaktform_Updater $updater;
    private Feuer_Kontaktform_Support_Admin $admin;
    private Feuer_Kontaktform_Support_Frontend $frontend;
    private Feuer_Kontaktform_Support_Inbound $inbound;
    private Feuer_Kontaktform_Support_Telegram $telegram;

    public function __construct(
        private Feuer_Kontaktform_Support_DB $db,
        private Feuer_Kontaktform_Support_Mailer $mailer
    ) {
        $this->updater = new Feuer_Kontaktform_Updater();
        $this->inbound = new Feuer_Kontaktform_Support_Inbound($this->db, $this->mailer);
        $this->telegram = new Feuer_Kontaktform_Support_Telegram($this->db, $this->mailer);
        $this->admin = new Feuer_Kontaktform_Support_Admin($this->db, $this->mailer, $this->inbound, $this->updater);
        $this->frontend = new Feuer_Kontaktform_Support_Frontend($this->db, $this->mailer);

        $this->register();
    }

    public static function activate(): void
    {
        $db = new Feuer_Kontaktform_Support_DB();
        $db->create_tables();
        add_filter('cron_schedules', [self::class, 'cron_schedules']);
        self::schedule_inbound_check();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('feuer_kontaktform_check_inbound_mail');
    }

    public static function ensure_inbound_cron(): void
    {
        self::schedule_inbound_check();
    }

    public static function uninstall(): void
    {
        $db = new Feuer_Kontaktform_Support_DB();
        $db->uninstall();
    }

    private function register(): void
    {
        add_action('plugins_loaded', [$this, 'maybe_upgrade']);
        add_action('phpmailer_init', [$this->mailer, 'configure_phpmailer']);
        add_action('admin_post_feuer_confirm_delivery', [$this, 'confirm_delivery']);
        add_action('admin_post_nopriv_feuer_confirm_delivery', [$this, 'confirm_delivery']);
        add_action('admin_post_feuer_department_ticket', [$this, 'department_ticket']);
        add_action('admin_post_nopriv_feuer_department_ticket', [$this, 'department_ticket']);
        add_action('feuer_kontaktform_check_inbound_mail', [$this, 'check_inbound_mail']);
        add_action('feuer_kontaktform_ticket_created', [$this, 'notify_ticket_recipients']);
        add_filter('cron_schedules', [self::class, 'cron_schedules']);
        add_filter('wp_privacy_personal_data_exporters', [$this, 'register_privacy_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'register_privacy_eraser']);
        $this->updater->register();
        $this->admin->register();
        $this->frontend->register();
        $this->telegram->register();
    }

    public static function cron_schedules(array $schedules): array
    {
        $schedules['feuer_five_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => 'Alle fünf Minuten',
        ];

        return $schedules;
    }

    public function check_inbound_mail(): void
    {
        $settings = $this->db->get_settings();
        if (! empty($settings['ticket_mailbox_enabled'])) {
            $this->inbound->check_now();
        }
    }

    public function notify_ticket_recipients(int $ticket_id): void
    {
        $settings = $this->db->get_settings();
        if (empty($settings['ticket_notification_enabled'])) {
            return;
        }
        $ticket = $this->db->get_ticket($ticket_id);
        if (! $ticket || in_array((string) $ticket['status'], ['spam', 'quarantined', 'trash', 'archived'], true)) {
            return;
        }
        $emails = [];
        foreach ((array) ($settings['ticket_notification_user_ids'] ?? []) as $user_id) {
            $user = get_user_by('id', absint($user_id));
            if ($user && is_email($user->user_email)) {
                $emails[] = sanitize_email($user->user_email);
            }
        }
        $emails = array_values(array_unique($emails));
        if ($emails === []) {
            return;
        }
        $subject = sprintf('[%s] Neue Anfrage #%s', sanitize_text_field((string) $settings['site_name']), (string) $ticket['ticket_code']);
        $body = "Eine neue Anfrage ist eingegangen.\n\nAnfrage: #" . (string) $ticket['ticket_code']
            . "\nBetreff: " . sanitize_text_field((string) ($ticket['subject'] ?: $ticket['topic_title'] ?: 'Anfrage'))
            . "\nAbteilung: " . sanitize_text_field((string) ($ticket['department_name'] ?? ''))
            . "\nVon: " . sanitize_text_field((string) $ticket['sender_name'])
            . "\n\nÖffnen: " . admin_url('admin.php?page=feuer-kontaktform-tickets&feuer_search=' . rawurlencode((string) $ticket['ticket_code']));
        if (! wp_mail($emails, $subject, $body)) {
            $this->db->log('ticket_notification_email_failed', 'ticket', $ticket_id, ['recipient_count' => count($emails)]);
        }
    }

    private static function schedule_inbound_check(): void
    {
        add_filter('cron_schedules', [self::class, 'cron_schedules']);

        if (! wp_next_scheduled('feuer_kontaktform_check_inbound_mail')) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'feuer_five_minutes', 'feuer_kontaktform_check_inbound_mail');
        }
    }

    public function confirm_delivery(): void
    {
        $ticket_id = absint($_REQUEST['ticket'] ?? 0);
        $token = sanitize_text_field((string) wp_unslash($_REQUEST['token'] ?? ''));
        $ticket = $ticket_id > 0 ? $this->db->get_ticket($ticket_id) : null;

        if (! $ticket || in_array((string) $ticket['status'], ['spam', 'trash'], true) || ! $this->mailer->verify_delivery_confirmation($ticket, $token)) {
            wp_die(
                'Der Bestätigungslink ist ungültig oder nicht mehr aktuell.',
                'Empfang konnte nicht bestätigt werden',
                ['response' => 403]
            );
        }

        $request_method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $settings = $this->db->get_settings();
        if ($request_method !== 'POST' && empty($settings['department_access_confirmation_required'])) {
            if (! $this->db->confirm_delivery($ticket_id)) {
                wp_die(
                    'Die Empfangsbestätigung konnte nicht gespeichert werden. Bitte informieren Sie den Website-Administrator.',
                    'Technischer Fehler',
                    ['response' => 500]
                );
            }

            wp_die(
                '<p>Der Empfang der Anfrage Nr. <strong>' . esc_html((string) $ticket['ticket_code']) . '</strong> wurde automatisch bestaetigt.</p><p>Sie koennen dieses Fenster jetzt schliessen.</p>',
                'Empfang bestaetigt',
                ['response' => 200]
            );
        }

        if ($request_method !== 'POST') {
            $form = '<p>Bitte bestätigen Sie, dass die Anfrage Nr. <strong>'
                . esc_html((string) $ticket['ticket_code'])
                . '</strong> die zuständige Abteilung erreicht hat.</p>'
                . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
                . '<input type="hidden" name="action" value="feuer_confirm_delivery">'
                . '<input type="hidden" name="ticket" value="' . esc_attr((string) $ticket_id) . '">'
                . '<input type="hidden" name="token" value="' . esc_attr($token) . '">'
                . '<p><button type="submit" class="button button-primary">Empfang jetzt bestätigen</button></p>'
                . '</form>';

            wp_die($form, 'Empfang der Anfrage bestätigen', ['response' => 200]);
        }

        if (! $this->db->confirm_delivery($ticket_id)) {
            wp_die(
                'Die Empfangsbestätigung konnte nicht gespeichert werden. Bitte informieren Sie den Website-Administrator.',
                'Technischer Fehler',
                ['response' => 500]
            );
        }

        wp_die(
            '<p>Der Empfang der Anfrage Nr. <strong>' . esc_html((string) $ticket['ticket_code']) . '</strong> wurde bestätigt.</p><p>Sie können dieses Fenster jetzt schließen.</p>',
            'Empfang bestätigt',
            ['response' => 200]
        );
    }

    public function department_ticket(): void
    {
        $ticket_id = absint($_REQUEST['ticket'] ?? 0);
        $settings = $this->db->get_settings();
        $cookie_name = 'feuer_ticket_access_' . $ticket_id;
        $request_token = sanitize_text_field((string) wp_unslash($_REQUEST['code'] ?? $_REQUEST['token'] ?? ''));
        $cookie_token = sanitize_text_field((string) wp_unslash($_COOKIE[$cookie_name] ?? ''));
        $token = $request_token !== '' ? $request_token : $cookie_token;
        $ticket = $this->db->validate_department_access($ticket_id, $token);

        if (! $ticket) {
            $this->render_department_access_error(
                'Dieser Zugriffslink ist ungültig, abgelaufen oder wurde vom Administrator widerrufen.',
                403
            );
        }

        if ($request_token !== '') {
            setcookie($cookie_name, $request_token, [
                'expires' => time() + (30 * DAY_IN_SECONDS),
                'path' => '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }

        $this->db->mark_department_access_used($ticket_id);
        $notice = '';
        $error = '';
        $confirmation_required = ! empty($settings['department_access_confirmation_required']);

        if (! $confirmation_required && (string) ($ticket['delivery_status'] ?? '') !== 'confirmed') {
            if ($this->db->confirm_delivery($ticket_id)) {
                $ticket = $this->db->get_ticket($ticket_id) ?? $ticket;
            }
        }

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            $operation = sanitize_key((string) wp_unslash($_POST['feuer_department_action'] ?? ''));
            $honeypot = trim((string) wp_unslash($_POST['company'] ?? ''));

            if ($honeypot !== '') {
                $this->render_department_access_error('Die Anfrage konnte nicht verarbeitet werden.', 400);
            }

            if ($operation === 'confirm') {
                if ($this->db->confirm_delivery($ticket_id)) {
                    $notice = 'Der Empfang wurde bestätigt.';
                    $ticket = $this->db->get_ticket($ticket_id) ?? $ticket;
                } else {
                    $error = 'Die Empfangsbestätigung konnte nicht gespeichert werden.';
                }
            } elseif ($operation === 'reply') {
                $reply = $this->handle_department_reply($ticket, $token);
                if (! empty($reply['success'])) {
                    $notice = 'Ihre Antwort wurde per E-Mail gesendet und im Verlauf gespeichert.';
                    $ticket = $this->db->get_ticket($ticket_id) ?? $ticket;
                } else {
                    $error = (string) ($reply['message'] ?? 'Die Antwort konnte nicht gesendet werden.');
                }
            }
        }

        if ($confirmation_required && (string) ($ticket['delivery_status'] ?? '') !== 'confirmed') {
            $this->render_department_confirmation_page($ticket, $token, $notice, $error);
        }

        $this->render_department_ticket_page($ticket, $token, $notice, $error);
    }

    private function render_department_confirmation_page(array $ticket, string $token, string $notice, string $error): void
    {
        $settings = $this->db->get_settings();
        $site_name = (string) (($settings['site_name'] ?? '') ?: get_bloginfo('name'));

        $this->send_department_page_headers(200);
        ?>
        <!doctype html>
        <html lang="de">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Empfang bestätigen · Anfrage Nr. <?php echo esc_html((string) $ticket['ticket_code']); ?></title>
            <style>
                body{margin:0;background:#eef1f4;color:#20242a;font-family:Arial,Helvetica,sans-serif;line-height:1.5}
                main{width:min(720px,calc(100% - 28px));margin:40px auto;padding:28px 0;border-top:4px solid #b3192b}
                small{display:block;color:#66707d;font-size:12px;font-weight:700;text-transform:uppercase}
                h1{margin:6px 0 12px;font-size:clamp(26px,5vw,38px);line-height:1.15}
                .box{padding:18px 0;border-top:1px solid #dce1e7;border-bottom:1px solid #dce1e7}
                .notice,.error{padding:12px 14px;margin:0 0 16px;border-radius:6px;font-weight:700}
                .notice{background:#edf7ef;color:#1d5c30;border:1px solid #b8d8c0}
                .error{background:#fff0f0;color:#8a2424;border:1px solid #e0b7b7}
                button{min-height:42px;padding:10px 16px;border:0;border-radius:6px;background:#23763d;color:#fff;font-weight:700;cursor:pointer}
            </style>
        </head>
        <body>
        <main>
            <small><?php echo esc_html($site_name); ?></small>
            <h1>Empfang der Anfrage bestätigen</h1>
            <?php if ($notice !== '') : ?><div class="notice"><?php echo esc_html($notice); ?></div><?php endif; ?>
            <?php if ($error !== '') : ?><div class="error"><?php echo esc_html($error); ?></div><?php endif; ?>
            <div class="box">
                <p>Bitte bestätigen Sie zuerst, dass die Anfrage Nr. <strong><?php echo esc_html((string) $ticket['ticket_code']); ?></strong> die zuständige Abteilung erreicht hat.</p>
                <p>Erst danach wird der geschützte Browserdialog mit den vollständigen Anfragedaten geöffnet.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="feuer_department_ticket">
                    <input type="hidden" name="ticket" value="<?php echo esc_attr((string) $ticket['id']); ?>">
                    <input type="hidden" name="code" value="<?php echo esc_attr($token); ?>">
                    <input type="hidden" name="feuer_department_action" value="confirm">
                    <button type="submit">Empfang bestätigen und Anfrage öffnen</button>
                </form>
            </div>
        </main>
        </body>
        </html>
        <?php
        exit;
    }

    private function handle_department_reply(array $ticket, string $token): array
    {
        $ticket_id = (int) $ticket['id'];
        $actor_name = sanitize_text_field((string) wp_unslash($_POST['actor_name'] ?? ''));
        $message = sanitize_textarea_field((string) wp_unslash($_POST['reply_message'] ?? ''));

        if ($actor_name === '') {
            return ['success' => false, 'message' => 'Bitte geben Sie Ihren vollständigen Namen an.'];
        }

        if ($message === '') {
            return ['success' => false, 'message' => 'Bitte schreiben Sie eine Antwort.'];
        }

        if (function_exists('mb_strlen') ? mb_strlen($message) > 10000 : strlen($message) > 10000) {
            return ['success' => false, 'message' => 'Die Antwort ist zu lang. Erlaubt sind maximal 10.000 Zeichen.'];
        }

        $rate_key = 'feuer_department_reply_' . hash('sha256', $token);
        if (get_transient($rate_key)) {
            return ['success' => false, 'message' => 'Bitte warten Sie kurz, bevor Sie eine weitere Antwort senden.'];
        }

        set_transient($rate_key, 1, 20);
        $settings = $this->db->get_settings();
        $result = $this->mailer->send_admin_reply($ticket, $settings, $message);

        if (empty($result['success'])) {
            delete_transient($rate_key);
            $this->db->log('ticket_department_reply_failed', 'ticket', $ticket_id, [
                'actor_name' => $actor_name,
                'recipient' => (string) $ticket['sender_email'],
                'message' => (string) ($result['message'] ?? ''),
            ]);
            return $result;
        }

        $actor_email = sanitize_email((string) ($ticket['access_recipient'] ?? $ticket['delivery_recipient'] ?? ''));
        $this->db->add_message(
            $ticket_id,
            'outbound',
            $actor_email,
            (string) $ticket['sender_email'],
            $message,
            [
                'source' => 'department_access_link',
                'actor_id' => 0,
                'actor_name' => $actor_name,
                'actor_email' => $actor_email,
            ]
        );
        $this->db->confirm_delivery($ticket_id);
        $this->db->log('ticket_department_replied', 'ticket', $ticket_id, [
            'actor_name' => $actor_name,
            'actor_email' => $actor_email,
            'recipient' => (string) $ticket['sender_email'],
        ]);

        return ['success' => true, 'message' => 'Antwort wurde gesendet.'];
    }

    private function render_department_ticket_page(array $ticket, string $token, string $notice, string $error): void
    {
        $settings = $this->db->get_settings();
        $messages = $this->db->get_ticket_messages((int) $ticket['id']);
        $dialog_messages = $this->public_dialog_messages($ticket, $messages);
        $site_name = (string) (($settings['site_name'] ?? '') ?: get_bloginfo('name'));
        $actor_value = $error !== ''
            ? sanitize_text_field((string) wp_unslash($_POST['actor_name'] ?? ''))
            : $this->current_user_full_name();
        $reply_template = $error !== ''
            ? sanitize_textarea_field((string) wp_unslash($_POST['reply_message'] ?? ''))
            : $this->default_department_reply($ticket, $site_name);

        $this->send_department_page_headers(200);
        ?>
        <!doctype html>
        <html lang="de">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Anfrage Nr. <?php echo esc_html((string) $ticket['ticket_code']); ?> · <?php echo esc_html($site_name); ?></title>
            <style>
                :root{color-scheme:light;--red:#b3192b;--ink:#20242a;--muted:#66707d;--line:#dce1e7;--soft:#f5f7f9;--green:#23763d}
                *{box-sizing:border-box}
                body{margin:0;background:#eef1f4;color:var(--ink);font-family:Arial,Helvetica,sans-serif;line-height:1.5}
                main{width:min(920px,calc(100% - 28px));margin:28px auto 48px}
                header{padding:24px 0 20px;border-bottom:4px solid var(--red)}
                header small,.label{display:block;color:var(--muted);font-size:12px;font-weight:700;text-transform:uppercase}
                h1{margin:5px 0 0;font-size:clamp(24px,5vw,36px);line-height:1.2}
                h2{margin:0 0 14px;font-size:20px}
                .security{margin:16px 0 0;color:var(--muted);font-size:13px}
                .band{padding:22px 0;border-bottom:1px solid var(--line)}
                .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
                .field{padding:12px 0;border-bottom:1px solid var(--line);overflow-wrap:anywhere}
                .field strong{display:block;margin-top:3px}
                .timeline{display:grid;gap:10px}
                .timeline article{max-width:78%;padding:14px 16px;background:#fff;border:1px solid var(--line);border-left:4px solid #87909c;border-radius:8px;overflow-wrap:anywhere}
                .timeline article.inbound{justify-self:start}
                .timeline article.outbound{justify-self:end;border-left-color:var(--red);background:#fff8f8}
                .timeline-head{display:flex;justify-content:space-between;gap:12px;margin-bottom:8px}
                .timeline time{color:var(--muted);font-size:12px}
                .notice,.error{padding:12px 14px;margin:0 0 16px;border-radius:6px;font-weight:700}
                .notice{background:#edf7ef;color:#1d5c30;border:1px solid #b8d8c0}
                .error{background:#fff0f0;color:#8a2424;border:1px solid #e0b7b7}
                form{margin:0}
                label{display:grid;gap:6px;margin:0 0 14px;font-weight:700}
                input,textarea{width:100%;padding:11px 12px;border:1px solid #9da6b1;border-radius:6px;background:#fff;color:var(--ink);font:inherit}
                textarea{min-height:220px;resize:vertical}
                button{min-height:40px;padding:9px 15px;border:1px solid transparent;border-radius:6px;background:var(--red);color:#fff;font-weight:700;cursor:pointer}
                button.secondary{background:#fff;color:var(--green);border-color:var(--green)}
                .actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
                .honeypot{position:absolute!important;left:-10000px!important;width:1px!important;height:1px!important;overflow:hidden!important}
                footer{padding-top:18px;color:var(--muted);font-size:12px}
                @media(max-width:650px){main{margin-top:12px}.grid{grid-template-columns:1fr}.timeline article{max-width:100%}.timeline-head{display:block}.timeline time{display:block;margin-top:3px}}
            </style>
        </head>
        <body>
        <main>
            <header>
                <small><?php echo esc_html($site_name); ?></small>
                <h1>Anfrage Nr. <?php echo esc_html((string) $ticket['ticket_code']); ?></h1>
                <p><?php echo esc_html((string) ($ticket['department_name'] ?? '')); ?> · <?php echo esc_html((string) ($ticket['topic_title'] ?? '')); ?> · <?php echo esc_html((string) $ticket['subject']); ?></p>
                <p class="security">Vertraulicher Zugriff für <?php echo esc_html((string) $ticket['access_recipient']); ?>. Gültig bis <?php echo esc_html((string) $ticket['access_expires_at']); ?>. Geben Sie diesen Link nur an berechtigte Personen weiter.</p>
            </header>

            <section class="band">
                <?php if ($notice !== '') : ?><div class="notice"><?php echo esc_html($notice); ?></div><?php endif; ?>
                <?php if ($error !== '') : ?><div class="error"><?php echo esc_html($error); ?></div><?php endif; ?>
                <div class="actions">
                    <?php if ((string) $ticket['delivery_status'] !== 'confirmed') : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="feuer_department_ticket">
                            <input type="hidden" name="ticket" value="<?php echo esc_attr((string) $ticket['id']); ?>">
                            <input type="hidden" name="code" value="<?php echo esc_attr($token); ?>">
                            <input type="hidden" name="feuer_department_action" value="confirm">
                            <button class="secondary" type="submit">Empfang bestätigen</button>
                        </form>
                    <?php else : ?>
                        <strong>Empfang bestätigt am <?php echo esc_html((string) $ticket['delivery_confirmed_at']); ?></strong>
                    <?php endif; ?>
                </div>
            </section>

            <section class="band">
                <h2>Absender und Anfrage</h2>
                <div class="grid">
                    <div class="field"><span class="label">Name</span><strong><?php echo esc_html((string) $ticket['sender_name']); ?></strong></div>
                    <div class="field"><span class="label">E-Mail</span><strong><?php echo esc_html((string) $ticket['sender_email']); ?></strong></div>
                    <?php if ((string) $ticket['sender_phone'] !== '') : ?>
                        <div class="field"><span class="label">Telefon</span><strong><?php echo esc_html((string) $ticket['sender_phone']); ?></strong></div>
                    <?php endif; ?>
                    <?php foreach ((array) $ticket['fields'] as $key => $value) : ?>
                        <?php if ($value === '' || $value === [] || in_array((string) $key, ['vorname', 'nachname', 'email', 'telefon', 'nachricht'], true)) { continue; } ?>
                        <div class="field">
                            <span class="label"><?php echo esc_html($this->public_field_label((string) $key)); ?></span>
                            <strong><?php echo esc_html($this->public_field_value($value)); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="band">
                <h2>Dialog</h2>
                <div class="timeline">
                    <?php foreach ($dialog_messages as $message) : ?>
                        <article class="<?php echo esc_attr((string) $message['direction']); ?>">
                            <div class="timeline-head">
                                <strong><?php echo esc_html($this->public_message_actor($message, $ticket)); ?></strong>
                                <time><?php echo esc_html((string) $message['created_at']); ?></time>
                            </div>
                            <div><?php echo nl2br(esc_html($this->public_message_body((string) $message['body'], $ticket, $site_name))); ?></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="band">
                <h2>Direkt antworten</h2>
                <p>Die Antwort wird an <?php echo esc_html((string) $ticket['sender_email']); ?> gesendet und im Plugin-Verlauf gespeichert.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="feuer_department_ticket">
                    <input type="hidden" name="ticket" value="<?php echo esc_attr((string) $ticket['id']); ?>">
                    <input type="hidden" name="code" value="<?php echo esc_attr($token); ?>">
                    <input type="hidden" name="feuer_department_action" value="reply">
                    <label class="honeypot">Firma<input type="text" name="company" value="" tabindex="-1" autocomplete="off"></label>
                    <label>Ihr vollständiger Name
                        <input type="text" name="actor_name" value="<?php echo esc_attr($actor_value); ?>" maxlength="190" required autocomplete="name">
                    </label>
                    <label>Antwort
                        <textarea name="reply_message" maxlength="10000" required><?php echo esc_textarea($reply_template); ?></textarea>
                    </label>
                    <button type="submit">Antwort per E-Mail senden</button>
                </form>
            </section>

            <footer>Dieser Zugriff ist personenbezogen und zeitlich begrenzt. Geben Sie den Link nur an berechtigte Personen weiter. Bei Verdacht auf Missbrauch kann der Administrator den Zugriff sofort widerrufen.</footer>
        </main>
        </body>
        </html>
        <?php
        exit;
    }

    private function render_department_access_error(string $message, int $status): void
    {
        $this->send_department_page_headers($status);
        echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Zugriff nicht möglich</title><style>body{margin:0;background:#eef1f4;color:#20242a;font-family:Arial,sans-serif}main{max-width:680px;margin:10vh auto;padding:28px}h1{font-size:28px}p{line-height:1.6}</style></head><body><main><h1>Zugriff nicht möglich</h1><p>'
            . esc_html($message)
            . '</p></main></body></html>';
        exit;
    }

    private function send_department_page_headers(int $status): void
    {
        status_header($status);
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    }

    private function default_department_reply(array $ticket, string $site_name): string
    {
        return "Hallo " . (string) $ticket['sender_name']
            . ",\n\n\n\nMit freundlichen Grüßen\n"
            . (string) ($ticket['department_name'] ?? '')
            . "\n"
            . $site_name;
    }

    private function current_user_full_name(): string
    {
        if (! is_user_logged_in()) {
            return '';
        }

        $user = wp_get_current_user();
        if (! $user || empty($user->ID)) {
            return '';
        }

        $name = trim((string) ($user->first_name ?? '') . ' ' . (string) ($user->last_name ?? ''));
        if ($name !== '') {
            return $name;
        }

        if (! empty($user->display_name)) {
            return (string) $user->display_name;
        }

        return (string) ($user->user_login ?? '');
    }

    private function public_message_actor(array $message, array $ticket): string
    {
        $raw = json_decode((string) ($message['raw_json'] ?? ''), true);
        $raw = is_array($raw) ? $raw : [];

        if ((string) ($message['direction'] ?? '') === 'inbound') {
            return (string) ($raw['sender_name'] ?? $ticket['sender_name'] ?? 'Absender');
        }

        return (string) ($raw['actor_name'] ?? $ticket['department_name'] ?? 'Abteilung');
    }

    private function public_dialog_messages(array $ticket, array $messages): array
    {
        if ($messages === [] && trim((string) ($ticket['message'] ?? '')) !== '') {
            $messages[] = [
                'direction' => 'inbound',
                'from_email' => (string) ($ticket['sender_email'] ?? ''),
                'to_email' => (string) ($ticket['delivery_recipient'] ?? ''),
                'body' => (string) ($ticket['message'] ?? ''),
                'raw_json' => wp_json_encode([
                    'sender_name' => (string) ($ticket['sender_name'] ?? ''),
                ]),
                'created_at' => (string) ($ticket['created_at'] ?? ''),
            ];
        }

        $dialog = [];
        $seen = [];
        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $body = trim((string) ($message['body'] ?? ''));
            if ($body === '') {
                continue;
            }

            $key = implode('|', [
                strtolower((string) ($message['direction'] ?? '')),
                strtolower((string) ($message['from_email'] ?? '')),
                strtolower((string) ($message['to_email'] ?? '')),
                preg_replace('/\s+/', ' ', $body) ?: $body,
            ]);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $dialog[] = $message;
        }

        return $dialog;
    }

    private function public_message_body(string $body, array $ticket, string $site_name): string
    {
        return strtr($body, [
            '{{ticket}}' => (string) $ticket['ticket_code'],
            '{{ticket_id}}' => (string) $ticket['ticket_code'],
            '{{site_name}}' => $site_name,
            '{{site_url}}' => (string) home_url('/'),
            '{{department}}' => (string) ($ticket['department_name'] ?? ''),
            '{{topic}}' => (string) ($ticket['topic_title'] ?? ''),
            '{{subject}}' => (string) $ticket['subject'],
            '{{name}}' => (string) $ticket['sender_name'],
            '{{email}}' => (string) $ticket['sender_email'],
        ]);
    }

    private function public_field_label(string $key): string
    {
        $labels = [
            'titel' => 'Titel / Betreff',
            'vorname' => 'Vorname',
            'nachname' => 'Nachname',
            'telefon' => 'Telefon',
            'email' => 'E-Mail',
            'nachricht' => 'Nachricht',
        ];

        return $labels[$key] ?? ucfirst(str_replace(['_', '-'], ' ', $key));
    }

    private function public_field_value($value): string
    {
        return is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
    }

    public function maybe_upgrade(): void
    {
        $installed = (string) get_option(Feuer_Kontaktform_Support_DB::OPTION_VERSION, '');
        if ($installed !== FEUER_KONTAKTFORM_VERSION) {
            $this->db->create_tables();
            $this->updater->clear_cache();
            delete_site_transient('update_plugins');
        }

        self::schedule_inbound_check();
    }

    public function register_privacy_exporter(array $exporters): array
    {
        $exporters['feuer-kontaktform'] = [
            'exporter_friendly_name' => 'Kontaktform Anfragen',
            'callback' => [$this, 'export_personal_data'],
        ];

        return $exporters;
    }

    public function register_privacy_eraser(array $erasers): array
    {
        $erasers['feuer-kontaktform'] = [
            'eraser_friendly_name' => 'Kontaktform Anfragen',
            'callback' => [$this, 'erase_personal_data'],
        ];

        return $erasers;
    }

    public function export_personal_data(string $email_address, int $page = 1): array
    {
        $items = [];
        if ($page > 1) {
            return ['data' => [], 'done' => true];
        }

        foreach ($this->db->get_tickets_by_email($email_address, 500) as $ticket) {
            $data = [
                ['name' => 'Anfrage Nr.', 'value' => (string) $ticket['ticket_code']],
                ['name' => 'Eingangsweg', 'value' => (string) ($ticket['origin'] ?? '') === 'direct_email' ? 'Direkte E-Mail' : 'Kontaktformular'],
                ['name' => 'Abteilung', 'value' => (string) ($ticket['department_name'] ?? '')],
                ['name' => 'Thema', 'value' => (string) ($ticket['topic_title'] ?? '')],
                ['name' => 'Betreff', 'value' => (string) $ticket['subject']],
                ['name' => 'Name', 'value' => (string) $ticket['sender_name']],
                ['name' => 'E-Mail', 'value' => (string) $ticket['sender_email']],
                ['name' => 'Telefon', 'value' => (string) $ticket['sender_phone']],
                ['name' => 'Nachricht', 'value' => (string) $ticket['message']],
                ['name' => 'Datenschutz-Schritt aktiv', 'value' => ! empty($ticket['privacy_required']) ? 'ja' : 'nein'],
                ['name' => 'Datenschutz bestätigt', 'value' => empty($ticket['privacy_required']) ? 'nicht abgefragt' : (! empty($ticket['privacy_accepted']) ? 'ja' : 'nein')],
                ['name' => 'Datenschutz-Version', 'value' => (string) $ticket['privacy_version']],
                ['name' => 'IP-Adresse', 'value' => (string) $ticket['ip_address']],
                ['name' => 'Cookie-ID', 'value' => (string) $ticket['visitor_cookie']],
                ['name' => 'Gerät', 'value' => (string) $ticket['device_type']],
                ['name' => 'Browser', 'value' => (string) $ticket['browser']],
                ['name' => 'Erstellt am', 'value' => (string) $ticket['created_at']],
            ];

            foreach ((array) ($ticket['fields'] ?? []) as $key => $value) {
                $data[] = [
                    'name' => 'Feld: ' . (string) $key,
                    'value' => is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value,
                ];
            }

            foreach ($this->db->get_ticket_messages((int) $ticket['id']) as $index => $message) {
                $data[] = [
                    'name' => 'Verlauf ' . (string) ($index + 1) . ' (' . (string) $message['created_at'] . ')',
                    'value' => (string) $message['body'],
                ];
            }

            foreach ($this->db->get_ticket_notes((int) $ticket['id']) as $index => $note) {
                $data[] = [
                    'name' => 'Interne Notiz ' . (string) ($index + 1) . ' (' . (string) $note['created_at'] . ')',
                    'value' => (string) $note['body'],
                ];
            }

            $items[] = [
                'group_id' => 'feuer-kontaktform',
                'group_label' => 'Kontaktform',
                'item_id' => 'feuer-ticket-' . (string) $ticket['id'],
                'data' => $data,
            ];
        }

        return ['data' => $items, 'done' => true];
    }

    public function erase_personal_data(string $email_address, int $page = 1): array
    {
        if ($page > 1) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }

        $count = $this->db->erase_personal_data($email_address);
        return [
            'items_removed' => $count > 0,
            'items_retained' => false,
            'messages' => $count > 0 ? ['Kontaktform Anfragen wurden anonymisiert.'] : [],
            'done' => true,
        ];
    }
}
