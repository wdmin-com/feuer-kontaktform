<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Feuer_Kontaktform_Support_Inbound
{
    public function __construct(
        private Feuer_Kontaktform_Support_DB $db,
        private Feuer_Kontaktform_Support_Mailer $mailer
    ) {
    }

    public function check_now(): array
    {
        $settings = $this->db->get_settings();
        $configuration_error = $this->configuration_error($settings);
        if ($configuration_error !== '') {
            return [
                'success' => false,
                'message' => $configuration_error,
                'processed' => 0,
                'ignored' => 0,
                'failed' => 0,
            ];
        }

        $processed = 0;
        $ignored = 0;
        $failed = 0;
        $checked = 0;

        foreach ($this->mailboxes($settings) as $mailbox_config) {
            $checked++;
            $stream = @imap_open(
                $this->mailbox_path($mailbox_config),
                (string) $mailbox_config['username'],
                (string) $mailbox_config['password'],
                0,
                1
            );

            if ($stream === false) {
                $failed++;
                $errors = function_exists('imap_errors') ? (array) imap_errors() : [];
                $this->db->log('inbound_mail_check_failed', 'mailbox', 0, [
                    'mailbox' => (string) $mailbox_config['email'],
                    'message' => $errors !== [] ? implode(' | ', array_map('strval', $errors)) : 'Die IMAP-Verbindung konnte nicht geöffnet werden.',
                ]);
                continue;
            }

            try {
                $message_numbers = imap_search($stream, 'UNSEEN');
                if (! is_array($message_numbers)) {
                    $message_numbers = [];
                }

                foreach (array_slice($message_numbers, 0, 25) as $message_number) {
                    $result = $this->import_message($stream, (int) $message_number, $settings, $mailbox_config);
                    $status = (string) ($result['status'] ?? 'failed');

                    if ($status === 'processed') {
                        $processed++;
                    } elseif ($status === 'ignored') {
                        $ignored++;
                    } else {
                        $failed++;
                    }
                }
            } catch (Throwable $exception) {
                $failed++;
                $this->db->log('inbound_mail_check_failed', 'mailbox', 0, [
                    'mailbox' => (string) $mailbox_config['email'],
                    'message' => $exception->getMessage(),
                ]);
            } finally {
                imap_close($stream);
            }
        }

        $success = $failed === 0;
        $message = sprintf(
            'Posteingang geprüft: %d Postfach/Postfächer, %d Nachricht(en) verarbeitet, %d Nachricht(en) ignoriert, %d Fehler.',
            $checked,
            $processed,
            $ignored,
            $failed
        );

        $this->db->log($success ? 'inbound_mail_checked' : 'inbound_mail_check_failed', 'mailbox', 0, [
            'processed' => $processed,
            'ignored' => $ignored,
            'failed' => $failed,
            'mailboxes' => $checked,
        ]);

        return compact('success', 'message', 'processed', 'ignored', 'failed');
    }
    private function import_message($stream, int $message_number, array $settings, array $mailbox_config): array
    {
        $overview_rows = imap_fetch_overview($stream, (string) $message_number, 0);
        $overview = is_array($overview_rows) && isset($overview_rows[0]) ? $overview_rows[0] : null;
        $header = imap_headerinfo($stream, $message_number);

        if (! is_object($overview) || ! is_object($header)) {
            return ['status' => 'failed'];
        }

        $message_id = substr(sanitize_text_field((string) ($overview->message_id ?? '')), 0, 255);
        if ($message_id === '') {
            $message_id = 'imap:' . hash('sha256', $this->mailbox_path($mailbox_config) . ':' . (string) imap_uid($stream, $message_number));
        }

        if ($this->db->message_exists_by_external_id($message_id)) {
            imap_setflag_full($stream, (string) $message_number, '\\Seen');
            return ['status' => 'ignored'];
        }

        $subject = $this->decode_header((string) ($overview->subject ?? ''));
        $sender = $this->sender_address($header);
        $sender_name = $this->sender_name($header);
        $recipients = array_map('strtolower', $this->recipient_addresses($header));
        $ticket_code = $this->ticket_code_from_subject($subject);
        if ($ticket_code === '') {
            $ticket_code = $this->ticket_code_from_recipients($recipients, $settings);
        }

        if ($ticket_code === '') {
            return $this->import_new_direct_email(
                $stream,
                $message_number,
                $settings,
                $message_id,
                $subject,
                $sender,
                $sender_name,
                $recipients,
                $mailbox_config
            );
        }

        $ticket = $this->db->get_ticket_by_code($ticket_code);

        if (! $ticket || in_array((string) $ticket['status'], ['spam', 'trash'], true)) {
            $this->mark_ignored($stream, $message_number, 'Anfrage wurde nicht gefunden', $subject, $sender);
            return ['status' => 'ignored'];
        }

        $expected_recipient = strtolower($this->mailer->ticket_mailbox_reply_to($settings, $ticket));
        if ($expected_recipient === '' || ! in_array($expected_recipient, $recipients, true)) {
            $this->mark_ignored($stream, $message_number, 'Sicherer Dialog-Code in der Empfängeradresse fehlt oder ist ungültig', $subject, $sender, (int) $ticket['id']);
            return ['status' => 'ignored'];
        }

        $requester_email = strtolower(sanitize_email((string) $ticket['sender_email']));
        $department_email = (string) ($ticket['origin'] ?? '') === 'direct_email'
            ? strtolower($this->mailer->direct_email_recipient($settings, $ticket))
            : strtolower(sanitize_email((string) ($ticket['topic_email'] ?: $ticket['department_email'])));
        $sender_key = strtolower($sender);

        if ($sender === '' || ($sender_key !== $requester_email && $sender_key !== $department_email)) {
            $this->mark_ignored($stream, $message_number, 'Absender stimmt nicht mit der Anfrage überein', $subject, $sender, (int) $ticket['id']);
            return ['status' => 'ignored'];
        }

        if ($this->is_automated_message($stream, $message_number, $sender, $subject)) {
            $this->mark_ignored($stream, $message_number, 'Automatische E-Mail oder Zustellbericht wurde nicht importiert', $subject, $sender, (int) $ticket['id']);
            return ['status' => 'ignored'];
        }

        $body = trim($this->clean_reply_body($this->extract_message_body($stream, $message_number)));
        if ($body === '') {
            $this->mark_ignored($stream, $message_number, 'Nachricht enthält keinen lesbaren Text', $subject, $sender, (int) $ticket['id']);
            return ['status' => 'ignored'];
        }

        if ($sender_key === $department_email) {
            return $this->import_department_reply(
                $stream,
                $message_number,
                $ticket,
                $settings,
                $message_id,
                $subject,
                $sender,
                $sender_name,
                $body
            );
        }

        $this->db->add_message(
            (int) $ticket['id'],
            'inbound',
            $sender,
            (string) $mailbox_config['email'],
            $body,
            [
                'source' => 'inbound_imap',
                'external_id' => $message_id,
                'sender_name' => $sender_name !== '' ? $sender_name : (string) $ticket['sender_name'],
                'subject' => $subject,
            ]
        );

        if ((string) ($ticket['origin'] ?? '') === 'direct_email') {
            $result = $this->mailer->send_direct_email_update($ticket, $settings, $body);
        } else {
            $department = $this->db->get_department((int) $ticket['department_id']);
            $topic = $this->db->get_topic((int) $ticket['topic_id']);
            if (! $department || ! $topic) {
                $this->db->log('inbound_mail_notification_failed', 'ticket', (int) $ticket['id'], [
                    'message' => 'Abteilung oder Thema wurde nicht gefunden.',
                ]);
                imap_setflag_full($stream, (string) $message_number, '\\Seen');
                return ['status' => 'failed'];
            }

            $result = $this->mailer->send_department_update($ticket, $department, $topic, $settings, $body);
        }

        $this->db->record_delivery_attempt(
            (int) $ticket['id'],
            (string) ($result['recipient'] ?? $department_email),
            $result
        );
        imap_setflag_full($stream, (string) $message_number, '\\Seen');

        $this->db->log(
            ! empty($result['success']) ? 'inbound_mail_imported' : 'inbound_mail_notification_failed',
            'ticket',
            (int) $ticket['id'],
            [
                'message_id' => $message_id,
                'sender' => $sender,
                'notification' => (string) ($result['message'] ?? ''),
            ]
        );

        return ['status' => ! empty($result['success']) ? 'processed' : 'failed'];
    }

    private function import_new_direct_email(
        $stream,
        int $message_number,
        array $settings,
        string $message_id,
        string $subject,
        string $sender,
        string $sender_name,
        array $recipients,
        array $mailbox_config
    ): array {
        if (empty($settings['direct_email_enabled'])) {
            $this->mark_ignored($stream, $message_number, 'Direkte E-Mail-Anfragen sind deaktiviert', $subject, $sender);
            return ['status' => 'ignored'];
        }

        $mailbox = strtolower(sanitize_email((string) ($mailbox_config['email'] ?? '')));
        if ($mailbox === '' || ! in_array($mailbox, $recipients, true)) {
            $this->mark_ignored($stream, $message_number, 'Die Nachricht wurde nicht direkt an das Ticket-Postfach adressiert', $subject, $sender);
            return ['status' => 'ignored'];
        }

        if (stripos($subject, 'Anfrage Nr.') !== false) {
            $this->mark_ignored($stream, $message_number, 'Die Anfrage Nr. im Betreff ist unvollständig oder ungültig', $subject, $sender);
            return ['status' => 'ignored'];
        }

        if ($sender === '' || $this->is_automated_message($stream, $message_number, $sender, $subject)) {
            $this->mark_ignored($stream, $message_number, 'Automatische E-Mail, Zustellbericht oder ungültiger Absender', $subject, $sender);
            return ['status' => 'ignored'];
        }

        $recipient = $this->mailer->direct_email_recipient($settings);
        $blocked_candidates = [
            $recipient,
            $settings['from_email'] ?? '',
        ];
        foreach ($this->mailboxes($settings) as $configured_mailbox) {
            $blocked_candidates[] = (string) ($configured_mailbox['email'] ?? '');
        }

        $blocked_senders = array_filter(array_map(
            static fn($email): string => strtolower(sanitize_email((string) $email)),
            $blocked_candidates
        ));
        if (in_array(strtolower($sender), $blocked_senders, true)) {
            $this->mark_ignored($stream, $message_number, 'Interne Absenderadresse wurde zum Schutz vor einer E-Mail-Schleife abgewiesen', $subject, $sender);
            return ['status' => 'ignored'];
        }

        $body = trim($this->extract_message_body($stream, $message_number));
        if ($body === '') {
            $this->mark_ignored($stream, $message_number, 'Nachricht enthält keinen lesbaren Text', $subject, $sender);
            return ['status' => 'ignored'];
        }

        if (function_exists('mb_substr')) {
            $body = mb_substr($body, 0, 50000);
        } else {
            $body = substr($body, 0, 50000);
        }

        $ticket_code = $this->db->generate_ticket_code();
        $ticket = [
            'ticket_code' => $ticket_code,
            'origin' => 'direct_email',
            'status' => 'quarantined',
            'department_id' => 0,
            'topic_id' => 0,
            'subject' => $subject !== '' ? $subject : 'E-Mail ohne Betreff',
            'sender_name' => $sender_name !== '' ? $sender_name : $sender,
            'sender_email' => $sender,
            'sender_phone' => '',
            'message' => $body,
            'fields' => [
                'email' => $sender,
                'nachricht' => $body,
            ],
            'privacy_required' => false,
            'privacy_accepted' => false,
            'privacy_version' => 'direkter E-Mail-Eingang',
            'privacy_url' => '',
            'consent_text' => 'Die Nachricht wurde vom Absender direkt an das Ticket-Postfach gesendet.',
            'ip_address' => '',
            'visitor_cookie' => '',
            'browser' => '',
            'device_type' => 'E-Mail',
            'user_agent' => '',
            'captcha_required' => false,
            'recipient_email' => $recipient,
            'source' => 'direct_email',
            'external_id' => $message_id,
            'sender_name_original' => $sender_name,
            'original_subject' => $subject,
        ];

        $ticket_id = $this->db->create_ticket($ticket);
        if ($ticket_id <= 0) {
            $this->db->log('direct_email_ticket_failed', 'mailbox', 0, [
                'sender' => $sender,
                'subject' => $subject,
                'message_id' => $message_id,
            ]);
            return ['status' => 'failed'];
        }

        $ticket['id'] = $ticket_id;
        imap_setflag_full($stream, (string) $message_number, '\\Seen');
        $this->db->log(
            'direct_email_ticket_quarantined',
            'ticket',
            $ticket_id,
            [
                'ticket_code' => $ticket_code,
                'sender' => $sender,
                'recipient' => '',
                'message_id' => $message_id,
                'message' => 'Direkte E-Mail wurde ohne Weiterleitung und ohne Autoantwort in die Quarantäne gelegt.',
            ]
        );

        return ['status' => 'processed'];
    }

    private function import_department_reply(
        $stream,
        int $message_number,
        array $ticket,
        array $settings,
        string $message_id,
        string $subject,
        string $sender,
        string $sender_name,
        string $body
    ): array {
        $result = $this->mailer->send_admin_reply($ticket, $settings, $body);
        if (empty($result['success'])) {
            $this->db->log('inbound_department_reply_failed', 'ticket', (int) $ticket['id'], [
                'sender' => $sender,
                'message_id' => $message_id,
                'message' => (string) ($result['message'] ?? ''),
            ]);
            return ['status' => 'failed'];
        }

        $this->db->add_message(
            (int) $ticket['id'],
            'outbound',
            $sender,
            (string) $ticket['sender_email'],
            $body,
            [
                'source' => 'inbound_imap_department_reply',
                'external_id' => $message_id,
                'actor_name' => $sender_name !== '' ? $sender_name : (string) $ticket['department_name'],
                'actor_email' => $sender,
                'subject' => $subject,
            ]
        );
        $this->db->confirm_delivery((int) $ticket['id']);
        imap_setflag_full($stream, (string) $message_number, '\\Seen');
        $this->db->log('inbound_department_reply_imported', 'ticket', (int) $ticket['id'], [
            'sender' => $sender,
            'recipient' => (string) $ticket['sender_email'],
            'message_id' => $message_id,
        ]);

        return ['status' => 'processed'];
    }

    private function mark_ignored(
        $stream,
        int $message_number,
        string $reason,
        string $subject,
        string $sender,
        int $ticket_id = 0
    ): void {
        imap_setflag_full($stream, (string) $message_number, '\\Seen');
        $this->db->log('inbound_mail_ignored', $ticket_id > 0 ? 'ticket' : 'mailbox', $ticket_id, [
            'reason' => $reason,
            'subject' => $subject,
            'sender' => $sender,
        ]);
    }

    private function extract_message_body($stream, int $message_number): string
    {
        $structure = imap_fetchstructure($stream, $message_number);
        if (! is_object($structure)) {
            return '';
        }

        $plain = $this->extract_part($stream, $message_number, $structure, '', 'PLAIN');
        if ($plain !== '') {
            return $plain;
        }

        $html = $this->extract_part($stream, $message_number, $structure, '', 'HTML');
        return $html !== '' ? wp_strip_all_tags($html, true) : '';
    }

    private function clean_reply_body(string $body): string
    {
        $lines = preg_split('/\R/u', str_replace("\0", '', $body)) ?: [];
        $clean = [];

        foreach ($lines as $line) {
            $trimmed = trim((string) $line);
            if (
                preg_match('/^(Am .+ schrieb .+:|On .+ wrote:|Von:\s|From:\s|-{2,}\s*(Original|Ursprüngliche) Nachricht)/ui', $trimmed)
            ) {
                break;
            }

            if (str_starts_with($trimmed, '>')) {
                continue;
            }

            $clean[] = rtrim((string) $line);
        }

        return trim(implode("\n", $clean));
    }

    private function extract_part($stream, int $message_number, object $part, string $part_number, string $wanted_subtype): string
    {
        $subtype = strtoupper((string) ($part->subtype ?? ''));
        if ((int) ($part->type ?? -1) === 0 && $subtype === $wanted_subtype) {
            $raw = $part_number === ''
                ? imap_body($stream, $message_number)
                : imap_fetchbody($stream, $message_number, $part_number);

            return $this->decode_part((string) $raw, (int) ($part->encoding ?? 0), $part);
        }

        foreach ((array) ($part->parts ?? []) as $index => $child) {
            if (! is_object($child)) {
                continue;
            }

            $child_number = $part_number === '' ? (string) ($index + 1) : $part_number . '.' . (string) ($index + 1);
            $value = $this->extract_part($stream, $message_number, $child, $child_number, $wanted_subtype);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function decode_part(string $body, int $encoding, object $part): string
    {
        if ($encoding === 3) {
            $body = (string) base64_decode($body, true);
        } elseif ($encoding === 4) {
            $body = quoted_printable_decode($body);
        }

        $charset = '';
        foreach (array_merge((array) ($part->parameters ?? []), (array) ($part->dparameters ?? [])) as $parameter) {
            if (is_object($parameter) && strtoupper((string) ($parameter->attribute ?? '')) === 'CHARSET') {
                $charset = (string) ($parameter->value ?? '');
                break;
            }
        }

        if ($charset !== '' && strtoupper($charset) !== 'UTF-8' && function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $body);
            if (is_string($converted)) {
                $body = $converted;
            }
        }

        return trim(str_replace("\0", '', $body));
    }

    private function sender_address(object $header): string
    {
        $from = is_array($header->from ?? null) ? ($header->from[0] ?? null) : null;
        if (! is_object($from)) {
            return '';
        }

        return sanitize_email((string) ($from->mailbox ?? '') . '@' . (string) ($from->host ?? ''));
    }

    private function sender_name(object $header): string
    {
        $from = is_array($header->from ?? null) ? ($header->from[0] ?? null) : null;
        return is_object($from) ? sanitize_text_field($this->decode_header((string) ($from->personal ?? ''))) : '';
    }

    private function recipient_addresses(object $header): array
    {
        $addresses = [];
        foreach (['to', 'cc'] as $field) {
            foreach ((array) ($header->{$field} ?? []) as $recipient) {
                if (! is_object($recipient)) {
                    continue;
                }

                $email = sanitize_email((string) ($recipient->mailbox ?? '') . '@' . (string) ($recipient->host ?? ''));
                if ($email !== '') {
                    $addresses[] = $email;
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    private function ticket_code_from_subject(string $subject): string
    {
        return preg_match('/\[Anfrage Nr\.\s*(\d{8})\]/ui', $subject, $matches)
            ? (string) $matches[1]
            : '';
    }

    private function ticket_code_from_recipients(array $recipients, array $settings): string
    {
        $base_email = strtolower(sanitize_email((string) ($settings['ticket_mailbox_email'] ?? '')));
        [$local, $domain] = array_pad(explode('@', $base_email, 2), 2, '');
        if ($local === '' || $domain === '') {
            return '';
        }

        $pattern = '/^'
            . preg_quote($local, '/')
            . '\+fkt-(\d{8})-[a-f0-9]{16}@'
            . preg_quote($domain, '/')
            . '$/i';

        foreach ($recipients as $recipient) {
            if (preg_match($pattern, strtolower((string) $recipient), $matches)) {
                return (string) $matches[1];
            }
        }

        return '';
    }

    private function is_automated_message($stream, int $message_number, string $sender, string $subject): bool
    {
        $sender = strtolower($sender);
        if (
            preg_match('/(^|[._-])(mailer-daemon|postmaster|no-?reply)([._@+-]|$)/i', $sender)
            || preg_match('/^(undeliverable|delivery status notification|mail delivery failed|abwesenheitsnotiz|automatic reply|automatische antwort)\b/ui', trim($subject))
        ) {
            return true;
        }

        $raw_header = (string) imap_fetchheader($stream, $message_number);
        return (bool) (
            preg_match('/^Auto-Submitted:\s*(?!no(?:\s|$))/mi', $raw_header)
            || preg_match('/^Precedence:\s*(?:bulk|junk|list)\s*$/mi', $raw_header)
            || preg_match('/^(?:X-Autoreply|X-Autorespond|X-Auto-Response-Suppress):/mi', $raw_header)
        );
    }

    private function decode_header(string $value): string
    {
        if ($value === '' || ! function_exists('imap_mime_header_decode')) {
            return sanitize_text_field($value);
        }

        $decoded = '';
        foreach ((array) imap_mime_header_decode($value) as $part) {
            if (is_object($part)) {
                $decoded .= (string) ($part->text ?? '');
            }
        }

        return sanitize_text_field($decoded !== '' ? $decoded : $value);
    }

    private function mailbox_path(array $settings): string
    {
        $encryption = (string) ($settings['encryption'] ?? ($settings['imap_encryption'] ?? 'ssl'));
        $flags = $encryption === 'ssl' ? '/imap/ssl' : ($encryption === 'tls' ? '/imap/tls' : '/imap/notls');

        return sprintf(
            '{%s:%d%s}%s',
            (string) ($settings['host'] ?? $settings['imap_host'] ?? ''),
            max(1, (int) ($settings['port'] ?? $settings['imap_port'] ?? 993)),
            $flags,
            (string) ($settings['folder'] ?? $settings['imap_folder'] ?? 'INBOX')
        );
    }

    private function mailboxes(array $settings): array
    {
        $mailboxes = $this->db->normalize_ticket_mailboxes($settings);

        return array_values(array_filter($mailboxes, static function (array $mailbox): bool {
            return sanitize_email((string) ($mailbox['email'] ?? '')) !== ''
                && trim((string) ($mailbox['host'] ?? '')) !== ''
                && trim((string) ($mailbox['username'] ?? '')) !== ''
                && trim((string) ($mailbox['password'] ?? '')) !== ''
                && trim((string) ($mailbox['folder'] ?? '')) !== '';
        }));
    }

    private function configuration_error(array $settings): string
    {
        if (empty($settings['ticket_mailbox_enabled'])) {
            return 'Das Ticket-Postfach ist deaktiviert.';
        }

        if (! function_exists('imap_open')) {
            return 'Die PHP-IMAP-Erweiterung ist auf diesem Server nicht installiert.';
        }

        if ($this->mailboxes($settings) === []) {
            return 'Es ist kein vollständig konfiguriertes Ticket-Postfach vorhanden.';
        }

        return '';
    }
}
