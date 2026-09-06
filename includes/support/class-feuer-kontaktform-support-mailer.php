<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Feuer_Kontaktform_Support_Mailer
{
    private string $last_mail_error = '';

    public function __construct(private Feuer_Kontaktform_Support_DB $db)
    {
    }

    public function send_ticket(array $ticket, array $department, array $topic, array $settings, bool $send_auto_reply = true): array
    {
        $recipient = sanitize_email((string) ($topic['email_override'] ?: $department['email']));
        if ($recipient === '') {
            return ['success' => false, 'message' => 'Für diese Abteilung ist keine gültige Empfänger-E-Mail konfiguriert.', 'recipient' => ''];
        }

        if (! empty($settings['ticket_mailbox_enabled']) && $this->ticket_mailbox_reply_to($settings, $ticket) === '') {
            return ['success' => false, 'message' => 'Für das Ticket-Postfach konnte keine sichere Plus-Adresse erzeugt werden.', 'recipient' => $recipient];
        }

        $access = $this->db->issue_department_access((int) ($ticket['id'] ?? 0), $recipient);
        if (empty($access['success'])) {
            return [
                'success' => false,
                'message' => (string) ($access['message'] ?? 'Der geschützte Zugriff auf die Anfrage konnte nicht vorbereitet werden.'),
                'recipient' => $recipient,
            ];
        }

        $ticket['department_access_url'] = $this->department_access_url(
            (int) ($ticket['id'] ?? 0),
            (string) ($access['code'] ?? $access['token'] ?? '')
        );
        $ticket['delivery_confirmation_url'] = $this->delivery_confirmation_url($ticket, $recipient);
        $headers = $this->notification_headers($settings, $ticket, $department, $topic, true);
        $subject = $this->ticket_subject($ticket, $topic);
        $body = $this->notification_body($ticket, $department, $topic, $settings);

        $delivery = $this->send_mail($recipient, $subject, $body, $headers, $settings);
        if (empty($delivery['success'])) {
            return [
                'success' => false,
                'message' => (string) $delivery['message'],
                'recipient' => $recipient,
                'auto_reply' => ['attempted' => false, 'success' => false, 'message' => 'Nicht versucht, weil die Abteilungsnachricht fehlgeschlagen ist.'],
            ];
        }

        $auto_reply_result = ['attempted' => false, 'success' => true, 'message' => 'Autoantwort ist deaktiviert oder es fehlt eine Empfänger-E-Mail.'];
        $reply_email = sanitize_email((string) ($ticket['sender_email'] ?? ''));
        if ($send_auto_reply && $reply_email !== '' && ! empty($topic['auto_reply_enabled'])) {
            $auto_subject = $this->replace_macros((string) ($topic['auto_subject'] ?: Feuer_Kontaktform_Support_DB::default_auto_subject()), $ticket, $department, $topic, $settings, false);
            $auto_subject = $this->ensure_ticket_reference($auto_subject, (string) ($ticket['ticket_code'] ?? ''));
            $auto_body = $this->replace_macros((string) ($topic['auto_body'] ?: Feuer_Kontaktform_Support_DB::default_auto_body()), $ticket, $department, $topic, $settings, false);
            $auto_html = trim((string) ($topic['auto_html'] ?? ''));
            $format = (string) ($topic['auto_reply_format'] ?? 'html');

            if ($format === 'html' && $auto_html !== '') {
                $auto_html = $this->replace_macros($auto_html, $ticket, $department, $topic, $settings, true);
                $auto_reply_result = $this->send_mail(
                    $reply_email,
                    $auto_subject,
                    $auto_html,
                    $this->headers($settings, $this->auto_reply_to($settings, $department, $ticket), '', true, true),
                    $settings
                );
            } else {
                $auto_reply_result = $this->send_mail(
                    $reply_email,
                    $auto_subject,
                    $auto_body,
                    $this->headers($settings, $this->auto_reply_to($settings, $department, $ticket), '', false, true),
                    $settings
                );
            }

            $auto_reply_result['attempted'] = true;
            if (empty($auto_reply_result['success']) && ! empty($ticket['id'])) {
                $this->db->log('ticket_auto_reply_failed', 'ticket', (int) $ticket['id'], [
                    'recipient' => $reply_email,
                    'message' => (string) ($auto_reply_result['message'] ?? ''),
                ]);
            }
        }

        return [
            'success' => true,
            'message' => 'Die Nachricht wurde vom konfigurierten Versandweg angenommen.',
            'recipient' => $recipient,
            'auto_reply' => $auto_reply_result,
        ];
    }

    public function send_admin_reply(array $ticket, array $settings, string $message): array
    {
        $recipient = sanitize_email((string) ($ticket['sender_email'] ?? ''));
        if ($recipient === '' || trim($message) === '') {
            return ['success' => false, 'message' => 'Empfänger-E-Mail oder Antworttext fehlt.', 'recipient' => $recipient];
        }

        if (! empty($settings['ticket_mailbox_enabled']) && $this->ticket_mailbox_reply_to($settings, $ticket) === '') {
            return ['success' => false, 'message' => 'Für das Ticket-Postfach konnte keine sichere Plus-Adresse erzeugt werden.', 'recipient' => $recipient];
        }

        $subject = '[Anfrage Nr. ' . (string) $ticket['ticket_code'] . '] ' . (string) ($ticket['topic_title'] ?? 'Anfrage') . ' - ' . (string) ($ticket['subject'] ?: 'Ohne Betreff');
        $site_name = sanitize_text_field((string) (($settings['site_name'] ?? '') ?: get_bloginfo('name')));
        $logo_url = esc_url_raw((string) ($settings['logo_url'] ?? ''));
        $logo_html = $logo_url !== ''
            ? '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($site_name) . '" style="max-width:150px;max-height:72px;margin:0 0 14px 0;display:block;">'
            : '';
        $body = nl2br(esc_html($this->replace_admin_reply_macros($message, $ticket, $settings)));
        $body = '<div style="margin:0;padding:0;background:#f3f5f7;font-family:Arial,Helvetica,sans-serif;color:#20242a;">'
            . '<div style="max-width:680px;margin:0 auto;padding:28px 18px;">'
            . '<div style="background:#ffffff;border:1px solid #dde2e8;border-radius:10px;overflow:hidden;">'
            . '<div style="padding:24px 28px;border-bottom:4px solid #b3192b;">'
            . $logo_html
            . '<div style="font-size:13px;color:#b3192b;font-weight:700;text-transform:uppercase;">Anfrage Nr. ' . esc_html((string) $ticket['ticket_code']) . '</div>'
            . '<h1 style="margin:8px 0 0;font-size:22px;line-height:1.25;color:#20242a;">' . esc_html($site_name) . '</h1>'
            . '</div>'
            . '<div style="padding:28px;font-size:16px;line-height:1.55;">'
            . '<p style="margin:0 0 16px;">' . $body . '</p>'
            . '<hr style="border:0;border-top:1px solid #e1e5ea;margin:22px 0;">'
            . '<p style="font-size:13px;color:#687281;margin:0;">Bitte lassen Sie die Anfrage Nr. im Betreff, damit der Dialog eindeutig zugeordnet werden kann.</p>'
            . '<p style="margin:18px 0 0;font-size:15px;line-height:1.55;">Mit freundlichen Grüßen<br><strong>' . esc_html($site_name) . '</strong></p>'
            . '</div>'
            . '</div>'
            . '</div>'
            . '</div>';

        return $this->send_mail(
            $recipient,
            $subject,
            $body,
            $this->headers($settings, $this->ticket_mailbox_reply_to($settings, $ticket), '', true),
            $settings
        );
    }

    public function send_processing_notice(array $ticket, array $settings): array
    {
        $recipient = sanitize_email((string) ($ticket['sender_email'] ?? ''));
        if ($recipient === '') {
            return ['success' => false, 'message' => 'Es fehlt eine gültige E-Mail-Adresse.', 'recipient' => ''];
        }
        $site_name = sanitize_text_field((string) (($settings['site_name'] ?? '') ?: get_bloginfo('name')));
        $subject = '[Anfrage Nr. ' . (string) $ticket['ticket_code'] . '] Ihre Anfrage wird bearbeitet';
        $body = "Guten Tag,\n\nIhre Anfrage wurde von unserem Team übernommen und wird jetzt bearbeitet.\n\nAnfrage Nr.: " . (string) $ticket['ticket_code'] . "\n\nMit freundlichen Grüßen\n" . $site_name;
        return $this->send_mail($recipient, $subject, $body, $this->headers($settings, '', '', false, true), $settings);
    }

    public function send_department_update(array $ticket, array $department, array $topic, array $settings, string $message): array
    {
        $ticket['message'] = $message;
        $ticket['fields'] = [];
        $ticket['notification_label'] = 'Neue Antwort zur Anfrage Nr.';

        return $this->send_ticket($ticket, $department, $topic, $settings, false);
    }

    public function send_direct_email_ticket(array $ticket, array $settings, bool $send_auto_reply = true): array
    {
        $recipient = $this->direct_email_recipient($settings, $ticket);
        $mailboxes = $this->mailbox_emails($settings);

        if ($recipient === '') {
            return [
                'success' => false,
                'message' => 'Für direkte E-Mail-Anfragen ist kein gültiger Standard-Empfänger konfiguriert.',
                'recipient' => '',
            ];
        }

        if (in_array(strtolower($recipient), $mailboxes, true)) {
            return [
                'success' => false,
                'message' => 'Ticket-Postfach und Standard-Empfänger dürfen nicht dieselbe E-Mail-Adresse verwenden.',
                'recipient' => $recipient,
            ];
        }

        [$department, $topic] = $this->direct_email_context($recipient, $settings);
        return $this->send_ticket($ticket, $department, $topic, $settings, $send_auto_reply);
    }

    public function send_direct_email_update(array $ticket, array $settings, string $message): array
    {
        $recipient = $this->direct_email_recipient($settings, $ticket);
        $mailboxes = $this->mailbox_emails($settings);
        if ($recipient === '') {
            return [
                'success' => false,
                'message' => 'Für diese direkte E-Mail-Anfrage ist kein gültiger Standard-Empfänger vorhanden.',
                'recipient' => '',
            ];
        }

        if (in_array(strtolower($recipient), $mailboxes, true)) {
            return [
                'success' => false,
                'message' => 'Ticket-Postfach und Standard-Empfänger dürfen nicht dieselbe E-Mail-Adresse verwenden.',
                'recipient' => $recipient,
            ];
        }

        [$department, $topic] = $this->direct_email_context($recipient, $settings);
        return $this->send_department_update($ticket, $department, $topic, $settings, $message);
    }

    public function direct_email_recipient(array $settings, array $ticket = []): string
    {
        $stored_recipient = sanitize_email((string) ($ticket['delivery_recipient'] ?? ''));
        if ($stored_recipient !== '') {
            return $stored_recipient;
        }

        $recipient = sanitize_email((string) ($settings['direct_email_recipient'] ?? ''));
        return $recipient !== '' ? $recipient : sanitize_email((string) ($settings['base_email'] ?? ''));
    }

    public function send_test(array $settings, string $target_email): array
    {
        $target = sanitize_email($target_email);
        if ($target === '') {
            return ['success' => false, 'message' => 'Die Test-E-Mail-Adresse ist ungültig.', 'recipient' => ''];
        }

        $subject = 'Kontaktform Testmail';
        $body = "Diese Testmail wurde über WordPress wp_mail() gesendet.\n\nWenn diese Nachricht ankommt, ist der konfigurierte Versandweg grundsätzlich erreichbar.";
        return $this->send_mail($target, $subject, $body, $this->headers($settings), $settings);
    }

    public function capture_mail_error($error): void
    {
        if (is_wp_error($error)) {
            $this->last_mail_error = sanitize_textarea_field($error->get_error_message());
        }
    }

    public function verify_delivery_confirmation(array $ticket, string $token): bool
    {
        $recipient = sanitize_email((string) ($ticket['delivery_recipient'] ?? ''));
        if ($recipient === '' || $token === '') {
            return false;
        }

        return hash_equals($this->delivery_confirmation_token($ticket, $recipient), $token);
    }

    public function configure_phpmailer($phpmailer): void
    {
        $settings = $this->db->get_settings();
        if (empty($settings['smtp_enabled'])) {
            return;
        }

        $host = sanitize_text_field((string) ($settings['smtp_host'] ?? ''));
        if ($host === '') {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host = $host;
        $phpmailer->Port = max(1, absint($settings['smtp_port'] ?? 587));
        $phpmailer->SMTPAuth = (string) ($settings['smtp_username'] ?? '') !== '';
        $phpmailer->Username = (string) ($settings['smtp_username'] ?? '');
        $phpmailer->Password = (string) ($settings['smtp_password'] ?? '');

        $encryption = (string) ($settings['smtp_encryption'] ?? 'tls');
        if ($encryption === 'ssl' || $encryption === 'tls') {
            $phpmailer->SMTPSecure = $encryption;
        } else {
            $phpmailer->SMTPSecure = '';
        }

        $phpmailer->Timeout = 20;
        $phpmailer->CharSet = 'UTF-8';
    }

    private function notification_body(array $ticket, array $department, array $topic, array $settings): string
    {
        $fields = is_array($ticket['fields'] ?? null) ? $ticket['fields'] : [];
        $skip = ['vorname', 'nachname', 'titel', 'telefon', 'email', 'nachricht'];
        $subject = (string) ($ticket['subject'] ?: ($topic['title'] ?? 'Kontaktanfrage'));
        $notification_label = (string) ($ticket['notification_label'] ?? 'Neue Anfrage Nr.');
        $reply_target = $this->notification_reply_target($settings, $ticket, $department, $topic);
        $access_url = esc_url_raw((string) ($ticket['department_access_url'] ?? ''));
        $reply_hint = $reply_target['email'] !== ''
            ? $this->mail_field(
                $this->ticket_mailbox_reply_to($settings, $ticket) !== '' ? 'Antwort über Ticket-Postfach' : 'Direkte Antwort an',
                $reply_target['email']
            )
            : '';
        $html = '<div style="margin:0;padding:0;background:#f3f5f7;font-family:Arial,Helvetica,sans-serif;color:#20242a;">'
            . '<div style="max-width:720px;margin:0 auto;padding:28px 18px;">'
            . '<div style="background:#ffffff;border:1px solid #dde2e8;border-radius:10px;overflow:hidden;">'
            . '<div style="padding:24px 28px;border-bottom:4px solid #b3192b;">'
            . '<div style="font-size:13px;color:#b3192b;font-weight:700;text-transform:uppercase;">' . esc_html($notification_label) . ' ' . esc_html((string) $ticket['ticket_code']) . '</div>'
            . '<h1 style="margin:8px 0 0;font-size:24px;line-height:1.25;color:#20242a;">' . esc_html((string) ($topic['title'] ?? 'Kontaktanfrage')) . ' - ' . esc_html($subject) . '</h1>'
            . '</div>'
            . '<div style="padding:26px 28px;">'
            . '<div style="display:grid;gap:10px;margin:0 0 20px;">'
            . $this->mail_field(
                (string) ($ticket['origin'] ?? '') === 'direct_email' ? 'Eingangsweg' : 'Abteilung',
                (string) ($department['name'] ?? '')
            )
            . $this->mail_field('Name', (string) ($ticket['sender_name'] ?? ''))
            . $this->mail_field('E-Mail', (string) ($ticket['sender_email'] ?? ''))
            . $this->mail_field('Telefon', (string) ($ticket['sender_phone'] ?? ''))
            . $reply_hint
            . '</div>'
            . '<div style="margin:18px 0;padding:18px;border-radius:8px;background:#f8f9fb;border:1px solid #e1e5ea;">'
            . '<div style="font-size:12px;color:#687281;text-transform:uppercase;font-weight:700;">Nachricht</div>'
            . '<p style="margin:8px 0 0;font-size:16px;line-height:1.55;">' . nl2br(esc_html((string) ($ticket['message'] ?? ''))) . '</p>'
            . '</div>';

        $extra = '';
        foreach ($fields as $key => $value) {
            if (in_array((string) $key, $skip, true) || $value === '' || $value === []) {
                continue;
            }
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            $extra .= $this->mail_field($this->field_label((string) $key), (string) $value);
        }

        if ($extra !== '') {
            $html .= '<div style="display:grid;gap:10px;margin-top:18px;">' . $extra . '</div>';
        }

        if ($access_url !== '') {
            $html .= '<div style="margin:22px 0 0;padding:18px;border-radius:8px;background:#f0f7f2;border:1px solid #b8d8c0;">'
                . '<div style="font-size:15px;font-weight:700;color:#1f5130;">Anfrage geschützt öffnen</div>'
                . '<p style="margin:7px 0 14px;font-size:13px;line-height:1.5;color:#365a42;">Über den vertraulichen Link können Sie die vollständige Anfrage und den bisherigen Verlauf ansehen, den Empfang bestätigen und direkt antworten.</p>'
                . '<a href="' . esc_url($access_url) . '" style="display:inline-block;padding:10px 15px;border-radius:6px;background:#23763d;color:#ffffff;text-decoration:none;font-weight:700;">Anfrage sicher öffnen</a>'
                . '<p style="margin:12px 0 0;font-size:12px;line-height:1.45;color:#526a59;">Der Link ist 30 Tage gültig. Geben Sie ihn nur an berechtigte Personen weiter.</p>'
                . '</div>';
        }

        $html .= '<p style="margin:22px 0 0;font-size:13px;color:#687281;">'
            . ($this->ticket_mailbox_reply_to($settings, $ticket) !== ''
                ? 'Bei Klick auf Antworten wird Ihre Nachricht über das Ticket-Postfach ' . esc_html($reply_target['email']) . ' verarbeitet, im Verlauf gespeichert und an den Besucher weitergeleitet.'
                : ($reply_target['email'] !== ''
                ? 'Bei Klick auf Antworten wird die E-Mail direkt an ' . esc_html($reply_target['email']) . ' gesendet. Der Betreff mit der Anfrage Nr. bleibt dabei erhalten.'
                : 'Für diese Nachricht ist aktuell kein direkter Reply-To gesetzt.')
            )
            . '</p>'
            . '</div></div></div></div>';

        return $html;
    }

    private function direct_email_context(string $recipient, array $settings): array
    {
        $auto_body = "Guten Tag,\n\nvielen Dank für Ihre Nachricht an {{site_name}}.\n\nWir haben Ihre E-Mail mit dem Betreff \"{{subject}}\" unter der Anfrage Nr. {{ticket_id}} gespeichert. Ihre Nachricht wird geprüft und schnellstmöglich beantwortet.\n\nMit freundlichen Grüßen\n{{site_name}}";
        $auto_html = '<div style="margin:0;padding:0;background:#f3f5f7;font-family:Arial,Helvetica,sans-serif;color:#20242a;">'
            . '<div style="max-width:680px;margin:0 auto;padding:28px 18px;">'
            . '<div style="background:#ffffff;border:1px solid #dde2e8;border-radius:10px;overflow:hidden;">'
            . '<div style="padding:24px 28px;border-bottom:4px solid #b3192b;">{{logo_html}}'
            . '<h1 style="margin:8px 0 0;font-size:24px;line-height:1.25;">Ihre E-Mail wurde empfangen</h1></div>'
            . '<div style="padding:28px;font-size:16px;line-height:1.55;">'
            . '<p style="margin:0 0 16px;">Guten Tag,</p>'
            . '<p style="margin:0 0 16px;">vielen Dank für Ihre Nachricht an <strong>{{site_name}}</strong>.</p>'
            . '<p style="margin:0 0 16px;">Wir haben Ihre E-Mail mit dem Betreff &quot;<strong>{{subject}}</strong>&quot; unter der Anfrage Nr. <strong>{{ticket_id}}</strong> gespeichert. Ihre Nachricht wird geprüft und schnellstmöglich beantwortet.</p>'
            . '<p style="margin:24px 0 0;">Mit freundlichen Grüßen<br><strong>{{site_name}}</strong></p>'
            . '</div></div></div></div>';

        return [
            [
                'id' => 0,
                'name' => 'Direkte E-Mail',
                'email' => $recipient,
            ],
            [
                'id' => 0,
                'title' => 'E-Mail-Anfrage',
                'email_override' => '',
                'auto_reply_enabled' => ! empty($settings['direct_email_auto_reply']),
                'auto_reply_format' => 'html',
                'auto_subject' => 'Ihre E-Mail wurde empfangen [Anfrage Nr. {{ticket_id}}]',
                'auto_body' => $auto_body,
                'auto_html' => $auto_html,
            ],
        ];
    }

    private function send_mail(string $recipient, string $subject, string $body, array $headers, array $settings): array
    {
        $configuration_error = $this->transport_configuration_error($settings);
        if ($configuration_error !== '') {
            return ['success' => false, 'message' => $configuration_error, 'recipient' => $recipient];
        }

        $this->last_mail_error = '';
        add_action('wp_mail_failed', [$this, 'capture_mail_error']);

        try {
            $sent = wp_mail($recipient, $subject, $body, $headers);
        } catch (Throwable $exception) {
            $sent = false;
            $this->last_mail_error = sanitize_textarea_field($exception->getMessage());
        } finally {
            remove_action('wp_mail_failed', [$this, 'capture_mail_error']);
        }

        if (! $sent) {
            $message = $this->last_mail_error !== ''
                ? $this->last_mail_error
                : 'WordPress wp_mail() hat die Nachricht abgelehnt, aber keine genaue Fehlerbeschreibung geliefert.';

            return ['success' => false, 'message' => $message, 'recipient' => $recipient];
        }

        return [
            'success' => true,
            'message' => 'Die Nachricht wurde vom konfigurierten Versandweg angenommen.',
            'recipient' => $recipient,
        ];
    }

    private function transport_configuration_error(array $settings): string
    {
        $from_email = sanitize_email((string) ($settings['from_email'] ?? ''));
        if ($from_email === '') {
            $from_email = sanitize_email((string) get_option('admin_email'));
        }

        if ($from_email === '') {
            return 'Es ist keine gültige Absender-E-Mail konfiguriert.';
        }

        if (! empty($settings['smtp_enabled']) && trim((string) ($settings['smtp_host'] ?? '')) === '') {
            return 'SMTP ist aktiviert, aber der SMTP-Host fehlt.';
        }

        if (
            ! empty($settings['smtp_enabled'])
            && trim((string) ($settings['smtp_username'] ?? '')) !== ''
            && (string) ($settings['smtp_password'] ?? '') === ''
        ) {
            return 'SMTP-Authentifizierung ist aktiviert, aber das SMTP-Passwort fehlt.';
        }

        if (
            ! empty($settings['ticket_mailbox_enabled'])
            && sanitize_email((string) ($settings['ticket_mailbox_email'] ?? '')) === ''
        ) {
            return 'Das Ticket-Postfach ist aktiviert, aber die Ticket-E-Mail ist ungültig.';
        }

        return '';
    }

    private function delivery_confirmation_url(array $ticket, string $recipient): string
    {
        if (empty($ticket['id']) || empty($ticket['ticket_code'])) {
            return '';
        }

        return add_query_arg(
            [
                'action' => 'feuer_confirm_delivery',
                'ticket' => (int) $ticket['id'],
                'token' => $this->delivery_confirmation_token($ticket, $recipient),
            ],
            admin_url('admin-post.php')
        );
    }

    private function department_access_url(int $ticket_id, string $code): string
    {
        if ($ticket_id <= 0 || $code === '') {
            return '';
        }

        return add_query_arg(
            [
                'action' => 'feuer_department_ticket',
                'ticket' => $ticket_id,
                'code' => $code,
            ],
            admin_url('admin-post.php')
        );
    }

    private function delivery_confirmation_token(array $ticket, string $recipient): string
    {
        $payload = (int) ($ticket['id'] ?? 0)
            . '|'
            . (string) ($ticket['ticket_code'] ?? '')
            . '|'
            . strtolower(sanitize_email($recipient));

        return hash_hmac('sha256', $payload, wp_salt('auth'));
    }

    private function replace_macros(string $text, array $ticket, array $department, array $topic, array $settings, bool $html): string
    {
        $fields = is_array($ticket['fields'] ?? null) ? $ticket['fields'] : [];
        $site_name = (string) ($settings['site_name'] ?? get_bloginfo('name'));
        $logo_url = esc_url_raw((string) ($settings['logo_url'] ?? ''));
        $privacy_url = esc_url_raw((string) ($settings['privacy_url'] ?? ''));
        $logo_html = $logo_url !== ''
            ? '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($site_name) . '" style="max-width:150px;max-height:72px;margin:0 0 14px 0;display:block;">'
            : '';
        $macros = [
            '{{ticket}}' => (string) ($ticket['ticket_code'] ?? ''),
            '{{ticket_id}}' => (string) ($ticket['ticket_code'] ?? ''),
            '{{site_name}}' => $site_name,
            '{{logo_url}}' => $logo_url,
            '{{logo_html}}' => $logo_html,
            '{{privacy_url}}' => $privacy_url,
            '{{department}}' => (string) ($department['name'] ?? ''),
            '{{topic}}' => (string) ($topic['title'] ?? ''),
            '{{subject}}' => (string) ($ticket['subject'] ?? ''),
            '{{name}}' => (string) ($ticket['sender_name'] ?? ''),
            '{{email}}' => (string) ($ticket['sender_email'] ?? ''),
            '{{vorname}}' => (string) ($fields['vorname'] ?? ''),
            '{{nachname}}' => (string) ($fields['nachname'] ?? ''),
            '{{telefon}}' => (string) ($fields['telefon'] ?? ''),
        ];

        if (! $html) {
            $macros['{{logo_html}}'] = $logo_url;
            return strtr($text, $macros);
        }

        $safe = [];
        foreach ($macros as $macro => $value) {
            $safe[$macro] = $macro === '{{logo_html}}' ? $value : esc_html($value);
        }

        return wp_kses_post(strtr($text, $safe));
    }

    private function replace_admin_reply_macros(string $text, array $ticket, array $settings): string
    {
        $fields = is_array($ticket['fields'] ?? null) ? $ticket['fields'] : [];
        $macros = [
            '{{ticket}}' => (string) ($ticket['ticket_code'] ?? ''),
            '{{ticket_id}}' => (string) ($ticket['ticket_code'] ?? ''),
            '{{site_name}}' => (string) (($settings['site_name'] ?? '') ?: get_bloginfo('name')),
            '{{site_url}}' => (string) home_url('/'),
            '{{department}}' => (string) ($ticket['department_name'] ?? ''),
            '{{topic}}' => (string) ($ticket['topic_title'] ?? ''),
            '{{subject}}' => (string) ($ticket['subject'] ?? ''),
            '{{name}}' => (string) ($ticket['sender_name'] ?? ''),
            '{{email}}' => (string) ($ticket['sender_email'] ?? ''),
            '{{vorname}}' => (string) ($fields['vorname'] ?? ''),
            '{{nachname}}' => (string) ($fields['nachname'] ?? ''),
            '{{telefon}}' => (string) ($fields['telefon'] ?? ''),
        ];

        return strtr($text, $macros);
    }

    private function ticket_subject(array $ticket, array $topic): string
    {
        $subject = (string) ($ticket['subject'] ?: 'Ohne Betreff');
        return '[Anfrage Nr. ' . (string) $ticket['ticket_code'] . '] ' . (string) ($topic['title'] ?? 'Anfrage') . ' - ' . $subject;
    }

    private function ensure_ticket_reference(string $subject, string $ticket_code): string
    {
        if ($ticket_code === '' || preg_match('/\[Anfrage Nr\.\s*' . preg_quote($ticket_code, '/') . '\]/ui', $subject)) {
            return $subject;
        }

        return trim($subject) . ' [Anfrage Nr. ' . $ticket_code . ']';
    }

    private function mail_field(string $label, string $value): string
    {
        return '<div style="padding:12px 14px;border-radius:8px;background:#f8f9fb;border:1px solid #e1e5ea;">'
            . '<div style="font-size:12px;color:#687281;text-transform:uppercase;font-weight:700;">' . esc_html($label) . '</div>'
            . '<div style="font-size:15px;color:#20242a;margin-top:4px;">' . esc_html($value) . '</div>'
            . '</div>';
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

    private function notification_headers(array $settings, array $ticket, array $department, array $topic, bool $html = false): array
    {
        $reply_target = $this->notification_reply_target($settings, $ticket, $department, $topic);
        return $this->headers($settings, $reply_target['email'], $reply_target['name'], $html);
    }

    private function auto_reply_to(array $settings, array $department, array $ticket): string
    {
        $ticket_mailbox = $this->ticket_mailbox_reply_to($settings, $ticket);
        if ($ticket_mailbox !== '') {
            return $ticket_mailbox;
        }

        $strategy = (string) ($settings['auto_reply_reply_to'] ?? 'department');

        if ($strategy === 'department') {
            return (string) ($department['email'] ?? '');
        }

        if ($strategy === 'base') {
            return (string) ($settings['base_email'] ?? '');
        }

        return '';
    }

    public function ticket_mailbox_reply_to(array $settings, array $ticket = []): string
    {
        if (empty($settings['ticket_mailbox_enabled'])) {
            return '';
        }

        $base_email = sanitize_email((string) ($settings['ticket_mailbox_email'] ?? ''));
        if ($base_email === '' || empty($ticket['id']) || empty($ticket['ticket_code'])) {
            return $base_email;
        }

        [$local, $domain] = array_pad(explode('@', $base_email, 2), 2, '');
        $token = substr(
            hash_hmac(
                'sha256',
                (int) $ticket['id'] . '|' . (string) $ticket['ticket_code'] . '|' . strtolower((string) ($ticket['sender_email'] ?? '')),
                wp_salt('secure_auth')
            ),
            0,
            16
        );
        $tagged_local = $local . '+fkt-' . (string) $ticket['ticket_code'] . '-' . $token;

        if ($domain === '' || strlen($tagged_local) > 64) {
            return '';
        }

        return sanitize_email($tagged_local . '@' . $domain);
    }

    private function headers(array $settings, string $reply_to = '', string $reply_name = '', bool $html = false, bool $auto_response = false): array
    {
        $from_email = sanitize_email((string) ($settings['from_email'] ?? get_option('admin_email')));
        if ($from_email === '') {
            $from_email = sanitize_email((string) get_option('admin_email'));
        }

        $from_name = sanitize_text_field((string) ($settings['from_name'] ?? get_bloginfo('name')));
        $headers = [
            'Content-Type: ' . ($html ? 'text/html' : 'text/plain') . '; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_email . '>',
        ];

        $reply = sanitize_email($reply_to);
        if ($reply !== '') {
            $headers[] = 'Reply-To: ' . $reply;
        }

        if ($auto_response && ! empty($settings['auto_response_headers'])) {
            $headers[] = 'Auto-Submitted: auto-replied';
            $headers[] = 'X-Auto-Response-Suppress: All';
        }

        return $headers;
    }

    private function notification_reply_target(array $settings, array $ticket, array $department, array $topic): array
    {
        $ticket_mailbox = $this->ticket_mailbox_reply_to($settings, $ticket);
        if ($ticket_mailbox !== '') {
            return [
                'email' => $ticket_mailbox,
                'name' => (string) (($settings['site_name'] ?? '') ?: 'Ticket-Postfach'),
            ];
        }

        $strategy = (string) ($settings['notification_reply_to'] ?? 'sender');
        $email = '';
        $name = '';

        if ($strategy === 'sender') {
            $email = (string) ($ticket['sender_email'] ?? '');
            $name = (string) ($ticket['sender_name'] ?? '');
        } elseif ($strategy === 'department') {
            $email = (string) ($topic['email_override'] ?: ($department['email'] ?? ''));
            $name = (string) ($department['name'] ?? '');
        }

        $email = sanitize_email($email);
        $name = sanitize_text_field($name);

        return [
            'email' => $email,
            'name' => $email !== '' ? $name : '',
        ];
    }

    private function mailbox_emails(array $settings): array
    {
        $mailboxes = $this->db->normalize_ticket_mailboxes($settings);
        $emails = [];

        foreach ($mailboxes as $mailbox) {
            $email = strtolower(sanitize_email((string) ($mailbox['email'] ?? '')));
            if ($email !== '') {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

}
