<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/** Telegram is deliberately a presentation and action channel; ticket data remains in WordPress. */
final class Feuer_Kontaktform_Support_Telegram
{
    private const REST_NAMESPACE = 'feuer-kontaktform/v1';

    public function __construct(private Feuer_Kontaktform_Support_DB $db, private Feuer_Kontaktform_Support_Mailer $mailer)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('feuer_kontaktform_ticket_created', [$this, 'notify_new_ticket']);
        add_action('feuer_kontaktform_telegram_settings_saved', [$this, 'register_webhook']);
    }

    public function register_routes(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/telegram/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function webhook_url(): string
    {
        return rest_url(self::REST_NAMESPACE . '/telegram/webhook');
    }

    public function register_webhook(): void
    {
        $settings = $this->db->get_settings();
        $token = (string) ($settings['telegram_bot_token'] ?? '');
        $secret = (string) ($settings['telegram_webhook_secret'] ?? '');
        if (empty($settings['telegram_enabled']) || ! $this->is_valid_bot_token($token) || $secret === '') {
            if (! empty($settings['telegram_enabled'])) {
                $this->db->log('telegram_webhook_skipped', 'telegram', 0, ['message' => 'Bot-Token oder Webhook-Geheimnis fehlt bzw. ist unvollständig.']);
            }
            return;
        }
        try {
            $response = wp_remote_post('https://api.telegram.org/bot' . $this->encoded_token($token) . '/setWebhook', [
                'timeout' => 10,
                'body' => [
                    'url' => $this->webhook_url(),
                    'secret_token' => $secret,
                    'allowed_updates' => wp_json_encode(['message', 'callback_query']),
                ],
            ]);
            $payload = is_wp_error($response) ? [] : json_decode((string) wp_remote_retrieve_body($response), true);
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200 || ! is_array($payload) || empty($payload['ok'])) {
                $this->db->log('telegram_webhook_failed', 'telegram', 0, ['message' => is_wp_error($response) ? $response->get_error_message() : 'Telegram hat den Webhook nicht bestätigt.']);
                return;
            }
            $this->db->log('telegram_webhook_registered', 'telegram', 0, ['url' => $this->webhook_url()]);
        } catch (Throwable $exception) {
            // A remote integration must never take the WordPress admin down.
            $this->db->log('telegram_webhook_failed', 'telegram', 0, ['message' => 'Webhook-Registrierung konnte nicht ausgeführt werden.']);
        }
    }

    public function notify_new_ticket(int $ticket_id): void
    {
        $settings = $this->db->get_settings();
        $ticket = $this->db->get_ticket($ticket_id);
        if (empty($settings['telegram_enabled']) || empty($settings['telegram_bot_token']) || ! $ticket || in_array((string) $ticket['status'], ['spam', 'quarantined', 'trash'], true)) {
            return;
        }

        foreach ($this->db->telegram_routes_for_ticket($ticket) as $route) {
            $permissions = array_values(array_intersect((array) $route['actions'], (array) $route['permissions']));
            if ($permissions === [] || empty($route['chat_id'])) {
                continue;
            }
            $this->send_ticket_notification($ticket, $route, $permissions, $settings);
        }
    }

    public function handle_webhook(WP_REST_Request $request): WP_REST_Response
    {
        $settings = $this->db->get_settings();
        $provided = (string) $request->get_header('x-telegram-bot-api-secret-token');
        $expected = (string) ($settings['telegram_webhook_secret'] ?? '');
        if (empty($settings['telegram_enabled']) || $expected === '' || ! hash_equals($expected, $provided)) {
            return new WP_REST_Response(['ok' => false], 403);
        }

        $update = $request->get_json_params();
        if (! is_array($update)) {
            return new WP_REST_Response(['ok' => false], 400);
        }
        $callback = $update['callback_query'] ?? null;
        $message = $update['message'] ?? null;
        if (is_array($callback)) {
            $this->handle_callback($callback, $settings);
        } elseif (is_array($message)) {
            $this->handle_message($message, $settings);
        }
        return new WP_REST_Response(['ok' => true], 200);
    }

    private function handle_message(array $message, array $settings): void
    {
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $text = trim((string) ($message['text'] ?? ''));
        $telegram_id = absint($from['id'] ?? 0);
        $chat_id = (int) ($chat['id'] ?? 0);
        if ($telegram_id <= 0 || $chat_id === 0) {
            return;
        }
        $name = trim((string) ($from['first_name'] ?? '') . ' ' . (string) ($from['last_name'] ?? ''));
        if (str_starts_with($text, '/start ')) {
            $code = trim(substr($text, 7));
            $user = $this->db->complete_telegram_pairing($code, $telegram_id, $chat_id, $name);
            $this->send_message($chat_id, $user ? 'Die Verbindung wurde erfolgreich eingerichtet. Bitte richten Sie jetzt Ihr Profil mit /einstellungen ein.' : 'Der Kopplungscode ist ungültig oder abgelaufen.', [], $settings);
            return;
        }
        if ($text === '/einstellungen') {
            $this->send_message($chat_id, "Persönliche Einstellungen\n\nBitte senden Sie Ihr Profil in einer Nachricht:\n/profil Vorname | Nachname | Ihre Signatur\n\nBeispiel:\n/profil Anna | Müller | Mit freundlichen Grüßen\nAnna Müller\nJugendfeuerwehr Musterstadt\nTelefon 040 123456", [], $settings);
            return;
        }
        if (str_starts_with($text, '/profil ')) {
            $parts = array_map('trim', explode('|', trim(substr($text, 8)), 3));
            $ok = count($parts) === 3 && $this->db->update_telegram_profile($telegram_id, (string) $parts[0], (string) $parts[1], (string) $parts[2]);
            $this->send_message($chat_id, $ok ? 'Ihr Name und Ihre Antwortsignatur wurden gespeichert.' : 'Bitte verwenden Sie: /profil Vorname | Nachname | Ihre Signatur', [], $settings);
        }
    }

    private function handle_callback(array $callback, array $settings): void
    {
        $from = is_array($callback['from'] ?? null) ? $callback['from'] : [];
        $telegram_id = absint($from['id'] ?? 0);
        $callback_id = sanitize_text_field((string) ($callback['id'] ?? ''));
        $token = sanitize_text_field((string) ($callback['data'] ?? ''));
        $action = $telegram_id > 0 ? $this->db->consume_telegram_action($token, $telegram_id) : null;
        if (! $action) {
            $this->answer_callback($callback_id, 'Diese Aktion ist ungültig oder abgelaufen.', true, $settings);
            return;
        }
        $ticket = $this->db->get_ticket((int) $action['ticket_id']);
        $user = $this->db->get_telegram_user($telegram_id);
        if (! $ticket || ! $user || in_array((string) $ticket['status'], ['spam', 'trash'], true)) {
            $this->answer_callback($callback_id, 'Die Anfrage wurde nicht gefunden.', true, $settings);
            return;
        }
        $permissions = (array) ($user['permissions'] ?? []);
        $type = (string) $action['action'];
        if (! in_array($type, $permissions, true)) {
            $this->answer_callback($callback_id, 'Sie sind für diese Aktion nicht berechtigt.', true, $settings);
            return;
        }

        if ($type === 'read') {
            $this->db->update_ticket_workflow((int) $ticket['id'], (string) $ticket['status'], (int) ($user['wp_user_id'] ?? 0), 'In Telegram gelesen');
            $this->answer_callback($callback_id, 'Als gelesen markiert.', false, $settings);
            return;
        }
        if ($type === 'claim') {
            $this->db->update_ticket_workflow((int) $ticket['id'], 'in_progress', (int) ($user['wp_user_id'] ?? 0), 'In Telegram übernommen');
            $this->answer_callback($callback_id, 'Die Anfrage wurde übernommen.', false, $settings);
            return;
        }
        if ($type === 'spam') {
            $this->db->update_ticket_workflow((int) $ticket['id'], 'spam', (int) ($user['wp_user_id'] ?? 0), 'In Telegram als Spam markiert');
            $this->answer_callback($callback_id, 'Die Anfrage wurde als Spam markiert.', false, $settings);
            return;
        }
        if ($type === 'open_browser') {
            $this->answer_callback($callback_id, 'Die geschützte Browseransicht wird in der nächsten Ausbaustufe bereitgestellt.', true, $settings);
            return;
        }
        $this->answer_callback($callback_id, 'Diese Aktion ist noch nicht verfügbar.', true, $settings);
    }

    private function send_ticket_notification(array $ticket, array $route, array $permissions, array $settings): void
    {
        $privacy = (string) ($settings['telegram_privacy_mode'] ?? 'summary');
        $sender = $privacy === 'masked' ? $this->mask_email((string) $ticket['sender_email']) : (string) $ticket['sender_email'];
        $text = "Neue Anfrage #" . (string) $ticket['ticket_code'] . "\n"
            . 'Von: ' . $sender . "\n"
            . 'Betreff: ' . (string) $ticket['subject'] . "\n"
            . 'Eingang: ' . (string) $ticket['created_at'];
        $buttons = [];
        foreach (['open_browser' => 'Im Browser öffnen', 'claim' => 'Übernehmen', 'read' => 'Als gelesen', 'spam' => 'Spam'] as $action => $label) {
            if (in_array($action, $permissions, true)) {
                $token = $this->db->create_telegram_action((int) $ticket['id'], (int) $route['telegram_user_id'], $action);
                if ($token !== '') {
                    $buttons[] = ['text' => $label, 'callback_data' => $token];
                }
            }
        }
        $keyboard = $buttons === [] ? [] : ['inline_keyboard' => array_chunk($buttons, 2)];
        $this->send_message((int) $route['chat_id'], $text, $keyboard, $settings);
    }

    private function send_message(int $chat_id, string $text, array $reply_markup, array $settings): void
    {
        $token = (string) ($settings['telegram_bot_token'] ?? '');
        if ($chat_id === 0 || $token === '') {
            return;
        }
        $body = ['chat_id' => $chat_id, 'text' => $text, 'disable_web_page_preview' => true];
        if ($reply_markup !== []) {
            $body['reply_markup'] = wp_json_encode($reply_markup);
        }
        $response = wp_remote_post('https://api.telegram.org/bot' . $this->encoded_token($token) . '/sendMessage', ['timeout' => 8, 'body' => $body]);
        if (is_wp_error($response)) {
            $this->db->log('telegram_notification_failed', 'telegram', 0, ['message' => $response->get_error_message()]);
        }
    }

    private function answer_callback(string $callback_id, string $text, bool $alert, array $settings): void
    {
        $token = (string) ($settings['telegram_bot_token'] ?? '');
        if ($callback_id === '' || $token === '') {
            return;
        }
        wp_remote_post('https://api.telegram.org/bot' . $this->encoded_token($token) . '/answerCallbackQuery', ['timeout' => 8, 'body' => ['callback_query_id' => $callback_id, 'text' => $text, 'show_alert' => $alert ? 'true' : 'false']]);
    }

    private function is_valid_bot_token(string $token): bool
    {
        return strlen($token) >= 20 && strpos($token, ':') !== false;
    }

    private function encoded_token(string $token): string
    {
        return str_replace('%3A', ':', rawurlencode($token));
    }

    private function mask_email(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return $local === '' || $domain === '' ? 'unbekannt' : substr($local, 0, 1) . '***@' . $domain;
    }
}
