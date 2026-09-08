<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Feuer_Kontaktform_Support_Frontend
{
    private const NONCE_ACTION = 'feuer_support_submit';
    private const COOKIE_NAME = 'feuer_kontaktform_visitor';
    private const CAPTCHA_PREFIX = 'feuer_support_captcha_';

    public function __construct(
        private Feuer_Kontaktform_Support_DB $db,
        private Feuer_Kontaktform_Support_Mailer $mailer
    ) {
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_shortcode('feuer-kontaktform', [$this, 'shortcode']);
        add_shortcode('feuer-kontaktform-schnell', [$this, 'quick_shortcode']);
        add_shortcode('feuer-kontaktform-formular', [$this, 'shortcode']);
        add_shortcode('feuer-kontaktform-alle', [$this, 'shortcode']);
        add_action('wp_ajax_feuer_support_submit_ticket', [$this, 'submit_ajax']);
        add_action('wp_ajax_nopriv_feuer_support_submit_ticket', [$this, 'submit_ajax']);
    }

    public function register_assets(): void
    {
        wp_register_style('feuer-kontaktform-support-frontend', FEUER_KONTAKTFORM_URL . 'assets/css/support-frontend.css', [], FEUER_KONTAKTFORM_VERSION);
        wp_register_script('feuer-kontaktform-support-frontend', FEUER_KONTAKTFORM_URL . 'assets/js/support-frontend.js', [], FEUER_KONTAKTFORM_VERSION, true);
    }

    public function shortcode(array $atts = []): string
    {
        return $this->render($atts, false);
    }

    public function quick_shortcode(array $atts = []): string
    {
        return $this->render($atts, true);
    }

    public function submit_ajax(): void
    {
        if (! check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => 'Sicherheitsprüfung fehlgeschlagen.'], 403);
        }

        $settings = $this->db->get_settings();
        $department_id = absint($_POST['department_id'] ?? 0);
        $topic_id = absint($_POST['topic_id'] ?? 0);
        $privacy = (string) wp_unslash($_POST['privacy'] ?? '') === 'yes';
        $privacy_enabled = ! empty($settings['privacy_enabled']);
        $captcha_token = sanitize_text_field((string) wp_unslash($_POST['captcha_token'] ?? ''));
        $captcha_answer = sanitize_text_field((string) wp_unslash($_POST['captcha_answer'] ?? ''));
        $honeypot = trim((string) wp_unslash($_POST['company'] ?? ''));

        if (! empty($settings['antispam_honeypot_enabled']) && $honeypot !== '') {
            wp_send_json_error(['message' => 'Die Anfrage konnte nicht verarbeitet werden.'], 400);
        }

        if ($privacy_enabled && ! $privacy) {
            wp_send_json_error(['message' => 'Ohne Einwilligung können wir Ihre Anfrage nicht verarbeiten.'], 400);
        }

        if (! $this->validate_captcha($captcha_token, $captcha_answer)) {
            wp_send_json_error(['message' => 'Die Sicherheitsfrage wurde nicht korrekt gelöst.', 'captcha' => $this->create_captcha()], 400);
        }

        $antispam = $this->validate_external_antispam($settings);
        if (! empty($antispam['error'])) {
            wp_send_json_error(['message' => (string) $antispam['error'], 'captcha' => $this->create_captcha()], 400);
        }

        $department = $this->db->get_department($department_id);
        $topic = $this->db->get_topic($topic_id);
        if (
            ! $department
            || ! $topic
            || empty($department['is_active'])
            || empty($topic['is_active'])
            || (int) $topic['department_id'] !== (int) $department['id']
        ) {
            wp_send_json_error(['message' => 'Abteilung oder Thema ist nicht gültig.'], 400);
        }

        $values = $this->collect_values($topic, $settings);
        if ($values['errors'] !== []) {
            wp_send_json_error(['message' => 'Bitte korrigieren Sie die markierten Felder.', 'fieldErrors' => $values['errors']], 400);
        }

        $fields = $values['fields'];
        $sender_email = (string) ($fields['email'] ?? '');
        $ip_address = $this->current_ip();
        if ($this->db->has_recent_ticket($ip_address, $sender_email, (int) ($settings['rate_limit_minutes'] ?? 10))) {
            wp_send_json_error([
                'message' => 'Bitte warten Sie kurz, bevor Sie eine weitere Anfrage senden.',
                'captcha' => $this->create_captcha(),
            ], 429);
        }

        $ticket_code = $this->db->generate_ticket_code();
        $recipient = sanitize_email((string) ($topic['email_override'] ?: $department['email']));
        $ticket = [
            'ticket_code' => $ticket_code,
            'department_id' => (int) $department['id'],
            'topic_id' => (int) $topic['id'],
            'subject' => (string) ($fields['titel'] ?? $topic['title']),
            'sender_name' => trim((string) ($fields['vorname'] ?? '') . ' ' . (string) ($fields['nachname'] ?? '')),
            'sender_email' => $sender_email,
            'sender_phone' => (string) ($fields['telefon'] ?? ''),
            'message' => (string) ($fields['nachricht'] ?? ''),
            'fields' => $fields,
            'privacy_required' => $privacy_enabled,
            'privacy_accepted' => $privacy_enabled && $privacy,
            'privacy_version' => $privacy_enabled ? (string) $settings['privacy_version'] : 'deaktiviert',
            'privacy_url' => $privacy_enabled ? (string) $settings['privacy_url'] : '',
            'consent_text' => $privacy_enabled ? (string) $settings['privacy_text'] : 'Datenschutz-Schritt war in den Plugin-Einstellungen deaktiviert.',
            'ip_address' => $ip_address,
            'visitor_cookie' => $this->visitor_cookie(),
            'browser' => $this->browser_label(),
            'device_type' => $this->device_type(),
            'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'captcha_required' => true,
            'recipient_email' => $recipient,
        ];

        $ticket_id = $this->db->create_ticket($ticket);
        if ($ticket_id <= 0) {
            wp_send_json_error([
                'message' => 'Ihre Anfrage konnte nicht gespeichert werden. Bitte versuchen Sie es erneut. Wenn der Fehler bleibt, prüfen Sie die Plugin-Protokolle.',
            ], 500);
        }

        $ticket['id'] = $ticket_id;
        $mail = $this->mailer->send_ticket($ticket, $department, $topic, $settings);
        $this->db->record_delivery_attempt(
            $ticket_id,
            (string) ($mail['recipient'] ?? $recipient),
            $mail
        );

        $message = empty($mail['success'])
            ? 'Ihre Anfrage wurde gespeichert, aber die Weiterleitung per E-Mail ist technisch fehlgeschlagen. Der Administrator kann den Versand erneut ausführen. Ihre Anfrage Nr. lautet ' . $ticket_code . '.'
            : (string) $settings['ticket_success_text'] . ' Ihre Anfrage Nr. lautet ' . $ticket_code . '.';

        wp_send_json_success([
            'message' => $message,
            'ticket' => $ticket_code,
            'ticketId' => $ticket_id,
        ]);
    }

    private function render(array $atts, bool $quick): string
    {
        $atts = shortcode_atts([
            'department' => '',
            'topic' => '',
        ], $atts);

        $shortcode_department_slug = sanitize_title((string) $atts['department']);
        $shortcode_topic_slug = sanitize_title((string) $atts['topic']);
        $request_department_slug = $this->request_slug('feuer_department');
        $request_topic_slug = $this->request_slug('feuer_topic');

        $config = $this->db->public_config(
            $shortcode_department_slug !== '' ? $shortcode_department_slug : null,
            $shortcode_topic_slug !== '' ? $shortcode_topic_slug : null
        );
        $settings = $this->db->get_settings();

        if ($config === []) {
            return '<div class="feuer-support-empty">Keine aktive Kontaktstrecke konfiguriert.</div>';
        }

        wp_enqueue_style('feuer-kontaktform-support-frontend');
        wp_enqueue_script('feuer-kontaktform-support-frontend');

        $captcha = $this->create_captcha();
        $selection = $this->resolve_direct_selection(
            $config,
            $shortcode_department_slug !== '' ? $shortcode_department_slug : $request_department_slug,
            $shortcode_topic_slug !== '' ? $shortcode_topic_slug : $request_topic_slug
        );
        $direct_department = (int) $selection['department_id'];
        $direct_topic = (int) $selection['topic_id'];
        $design = sanitize_key((string) ($settings['design_variant'] ?? 'classic'));
        $field_style = sanitize_key((string) ($settings['field_variant'] ?? 'soft'));
        $privacy_enabled = ! empty($settings['privacy_enabled']);

        ob_start();
        ?>
        <div class="feuer-support-shell feuer-design-<?php echo esc_attr($design); ?> feuer-fields-<?php echo esc_attr($field_style); ?><?php echo $quick ? ' is-quick' : ''; ?>">
            <?php if (in_array($design, ['cards', 'faq'], true) && $direct_department === 0) : ?>
                <section class="feuer-contact-launcher" data-contact-launcher>
                    <?php if ($design === 'cards') : ?>
                        <p class="feuer-kicker">Kontakt aufnehmen</p>
                        <h2>Wie können wir Ihnen helfen?</h2>
                        <p class="feuer-launcher-intro">Wählen Sie einen Bereich. Danach öffnet sich direkt das passende Kontaktformular.</p>
                        <div class="feuer-contact-cards">
                            <?php foreach ($config as $department) : ?>
                                <button type="button" class="feuer-contact-card" data-open-department="<?php echo esc_attr((string) $department['id']); ?>">
                                    <span class="feuer-contact-card-icon" aria-hidden="true">✦</span>
                                    <strong><?php echo esc_html((string) $department['name']); ?></strong>
                                    <span><?php echo esc_html((string) ($department['description'] ?: 'Kontakt zur zuständigen Abteilung')); ?></span>
                                    <b>Kontakt aufnehmen&nbsp; →</b>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <div class="feuer-contact-modal" data-contact-modal hidden role="dialog" aria-modal="true" aria-labelledby="feuer-contact-modal-title"><div class="feuer-contact-modal-panel"><button type="button" class="feuer-contact-modal-close" data-contact-modal-close aria-label="Fenster schließen">×</button><p class="feuer-kicker">Thema auswählen</p><h3 id="feuer-contact-modal-title">Worum geht es?</h3><div class="feuer-contact-modal-options" data-contact-modal-options></div></div></div>
                    <?php else : ?>
                        <p class="feuer-kicker">Häufige Fragen</p>
                        <h2>Womit können wir Ihnen weiterhelfen?</h2>
                        <p class="feuer-launcher-intro">Lesen Sie zunächst die passende Antwort. Über „Zu diesem Thema schreiben“ gelangen Sie direkt zur Anfrage.</p>
                        <div class="feuer-faq-list">
                            <?php foreach ($config as $department) : foreach ((array) ($department['topics'] ?? []) as $topic) : ?>
                                <details class="feuer-faq-item">
                                    <summary><?php echo esc_html((string) $topic['title']); ?><span aria-hidden="true">+</span></summary>
                                    <div class="feuer-faq-answer"><p><?php echo esc_html((string) ($department['description'] ?: 'Wir helfen Ihnen gerne bei diesem Anliegen weiter.')); ?></p><button type="button" class="button-primary" data-open-selection data-department-id="<?php echo esc_attr((string) $department['id']); ?>" data-topic-id="<?php echo esc_attr((string) $topic['id']); ?>">Zu diesem Thema schreiben&nbsp; →</button></div>
                                </details>
                            <?php endforeach; endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            <form class="feuer-support-form" enctype="multipart/form-data"
                data-config="<?php echo esc_attr((string) wp_json_encode($config)); ?>"
                data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
                data-direct-department="<?php echo esc_attr((string) $direct_department); ?>"
                data-direct-topic="<?php echo esc_attr((string) $direct_topic); ?>"
                data-file-types="<?php echo esc_attr((string) ($settings['allowed_file_types'] ?? '')); ?>"
                data-max-file-mb="<?php echo esc_attr((string) ($settings['max_upload_mb'] ?? 8)); ?>"
            >
                <input type="hidden" name="action" value="feuer_support_submit_ticket">
                <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce(self::NONCE_ACTION)); ?>">
                <input type="hidden" name="department_id" data-department-input value="<?php echo esc_attr((string) $direct_department); ?>">
                <input type="hidden" name="topic_id" data-topic-input value="<?php echo esc_attr((string) $direct_topic); ?>">
                <input type="hidden" name="captcha_token" value="<?php echo esc_attr((string) $captcha['token']); ?>">
                <?php if (! empty($settings['antispam_honeypot_enabled'])) : ?>
                    <label class="feuer-honeypot">Company<input type="text" name="company" value="" tabindex="-1" autocomplete="off"></label>
                <?php endif; ?>

                <div class="feuer-support-progress"><span data-progress-bar></span></div>
                <div class="feuer-support-message" data-message hidden></div>

                <section class="feuer-step is-active" data-step="department" <?php echo $direct_department ? 'data-skip="1"' : ''; ?>>
                    <p class="feuer-kicker">Abteilung</p>
                    <h2>An wen möchten Sie sich wenden?</h2>
                    <div class="feuer-options" data-departments></div>
                    <div class="feuer-step-actions"><button type="button" data-prev>Zurück</button><button type="button" class="button-primary" data-next>Weiter</button></div>
                </section>

                <section class="feuer-step" data-step="topic" <?php echo $direct_topic ? 'data-skip="1"' : ''; ?>>
                    <p class="feuer-kicker">Thema</p>
                    <h2>Welche Anfrage möchten Sie stellen?</h2>
                    <div class="feuer-options" data-topics></div>
                    <div class="feuer-step-actions"><button type="button" data-prev>Zurück</button><button type="button" class="button-primary" data-next>Weiter</button></div>
                </section>

                <section class="feuer-step" data-step="privacy" <?php echo $privacy_enabled ? '' : 'data-skip="1"'; ?>>
                    <?php if ((string) $settings['logo_url'] !== '') : ?><img class="feuer-support-logo" src="<?php echo esc_url((string) $settings['logo_url']); ?>" alt=""><?php endif; ?>
                    <p class="feuer-kicker">Datenschutz</p>
                    <h2>Sind Sie mit der Verarbeitung personenbezogener Daten einverstanden?</h2>
                    <p><?php echo esc_html((string) $settings['privacy_text']); ?></p>
                    <?php if ((string) $settings['privacy_url'] !== '') : ?><p><a href="<?php echo esc_url((string) $settings['privacy_url']); ?>" target="_blank" rel="noopener">Datenschutzerklärung öffnen</a></p><?php endif; ?>
                    <div class="feuer-choice-row">
                        <label class="feuer-privacy-choice is-yes"><input type="radio" name="privacy" value="yes"><span>Ja, ich bin einverstanden</span></label>
                        <label class="feuer-privacy-choice is-no"><input type="radio" name="privacy" value="no"><span>Nein</span></label>
                    </div>
                    <div class="feuer-denied" data-denied hidden>Ohne Einwilligung können wir Ihre Anfrage nicht verarbeiten.</div>
                    <div class="feuer-step-actions"><button type="button" data-prev>Zurück</button><button type="button" class="button-primary" data-next>Weiter</button></div>
                </section>

                <section class="feuer-step" data-step="fields">
                    <p class="feuer-kicker">Ihre Angaben</p>
                    <h2>Welche Informationen brauchen wir?</h2>
                    <div class="feuer-fields" data-fields></div>
                    <div class="feuer-step-actions"><button type="button" data-prev>Zurück</button><button type="button" class="button-primary" data-next>Weiter</button></div>
                </section>

                <section class="feuer-step" data-step="captcha">
                    <p class="feuer-kicker">Sicherheit</p>
                    <h2>Bitte beantworten Sie die Sicherheitsfrage</h2>
                    <label class="feuer-field"><span><?php echo esc_html((string) $captcha['question']); ?></span><input type="number" name="captcha_answer" inputmode="numeric" required></label>
                    <?php if (! empty($settings['turnstile_enabled']) && (string) $settings['turnstile_site_key'] !== '') : ?>
                        <div class="cf-turnstile" data-sitekey="<?php echo esc_attr((string) $settings['turnstile_site_key']); ?>"></div>
                        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                    <?php endif; ?>
                    <?php if (! empty($settings['recaptcha_enabled']) && (string) $settings['recaptcha_site_key'] !== '') : ?>
                        <div class="g-recaptcha" data-sitekey="<?php echo esc_attr((string) $settings['recaptcha_site_key']); ?>"></div>
                        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
                    <?php endif; ?>
                    <div class="feuer-step-actions"><button type="button" data-prev>Zurück</button><button type="submit" class="button-primary">Anfrage senden</button></div>
                </section>

                <section class="feuer-step" data-step="done">
                    <p class="feuer-kicker">Gesendet</p>
                    <h2>Ihre Anfrage wurde gespeichert</h2>
                    <p data-success-text></p>
                </section>

                <div class="feuer-submit-overlay" data-overlay hidden><span></span><b>Anfrage wird gespeichert...</b></div>
            </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function request_slug(string $key): string
    {
        $keys = [$key];
        if ($key === 'feuer_department') {
            $keys[] = 'abteilung';
        } elseif ($key === 'feuer_topic') {
            $keys[] = 'thema';
        }

        foreach ($keys as $candidate) {
            $value = sanitize_title((string) wp_unslash($_GET[$candidate] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolve_direct_selection(array $config, string $department_slug, string $topic_slug): array
    {
        $department_id = 0;
        $topic_id = 0;
        $selected_department = null;

        if ($department_slug !== '') {
            foreach ($config as $department) {
                if ((string) ($department['slug'] ?? '') !== $department_slug) {
                    continue;
                }

                $selected_department = $department;
                $department_id = (int) ($department['id'] ?? 0);
                break;
            }
        }

        if ($selected_department === null && $topic_slug !== '') {
            foreach ($config as $department) {
                foreach ((array) ($department['topics'] ?? []) as $topic) {
                    if ((string) ($topic['slug'] ?? '') !== $topic_slug) {
                        continue;
                    }

                    $selected_department = $department;
                    $department_id = (int) ($department['id'] ?? 0);
                    break 2;
                }
            }
        }

        if ($selected_department === null && count($config) === 1) {
            $selected_department = $config[0];
            $department_id = (int) ($selected_department['id'] ?? 0);
        }

        if ($selected_department !== null && $topic_slug !== '') {
            foreach ((array) ($selected_department['topics'] ?? []) as $topic) {
                if ((string) ($topic['slug'] ?? '') !== $topic_slug) {
                    continue;
                }

                $topic_id = (int) ($topic['id'] ?? 0);
                break;
            }
        }

        if ($topic_id === 0 && $selected_department !== null && count((array) ($selected_department['topics'] ?? [])) === 1) {
            $topic_id = (int) ($selected_department['topics'][0]['id'] ?? 0);
        }

        return [
            'department_id' => $department_id,
            'topic_id' => $topic_id,
        ];
    }

    private function collect_values(array $topic, array $settings): array
    {
        $raw = isset($_POST['fields']) && is_array($_POST['fields']) ? wp_unslash($_POST['fields']) : [];
        $fields = [];
        $errors = [];

        foreach ((array) $topic['fields'] as $field) {
            $key = sanitize_key((string) ($field['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            if (($field['type'] ?? '') === 'file') {
                $value = $this->handle_upload($key, $settings);
                if (! empty($_FILES['fields']['name'][$key]) && $value === '') {
                    $errors[$key] = 'Die Datei konnte nicht hochgeladen werden. Bitte prüfen Sie Format und Größe.';
                }
            } else {
                $value = isset($raw[$key]) ? sanitize_textarea_field((string) $raw[$key]) : '';
                if (($field['type'] ?? '') === 'email') {
                    $value = sanitize_email($value);
                }
            }

            if (! isset($errors[$key]) && ! empty($field['required']) && $value === '') {
                $errors[$key] = 'Dieses Feld ist erforderlich.';
            } elseif (! isset($errors[$key]) && ($field['type'] ?? '') === 'email' && $value !== '' && ! is_email($value)) {
                $errors[$key] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
            } elseif (! isset($errors[$key]) && ($field['type'] ?? '') === 'select' && $value !== '') {
                $options = array_map('strval', (array) ($field['options'] ?? []));
                if ($options !== [] && ! in_array($value, $options, true)) {
                    $errors[$key] = 'Bitte wählen Sie einen gültigen Wert aus der Liste.';
                }
            }

            $fields[$key] = $value;
        }

        return ['fields' => $fields, 'errors' => $errors];
    }

    private function handle_upload(string $key, array $settings): string
    {
        if (empty($_FILES['fields']['name'][$key])) {
            return '';
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $max_bytes = max(1, absint($settings['max_upload_mb'] ?? 8)) * MB_IN_BYTES;
        $size = absint($_FILES['fields']['size'][$key]);
        if ($size > $max_bytes) {
            return '';
        }

        $mimes = $this->allowed_mimes((string) ($settings['allowed_file_types'] ?? ''));

        $file = [
            'name' => $_FILES['fields']['name'][$key],
            'type' => $_FILES['fields']['type'][$key],
            'tmp_name' => $_FILES['fields']['tmp_name'][$key],
            'error' => $_FILES['fields']['error'][$key],
            'size' => $_FILES['fields']['size'][$key],
        ];

        $result = wp_handle_upload($file, [
            'test_form' => false,
            'mimes' => $mimes,
        ]);

        if (! is_array($result) || empty($result['url'])) {
            return '';
        }

        return esc_url_raw((string) $result['url']);
    }

    private function allowed_mimes(string $types): array
    {
        $known = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt' => 'text/plain',
        ];
        $mimes = [];

        foreach (preg_split('/[\s,;]+/', strtolower($types)) ?: [] as $type) {
            $type = preg_replace('/[^a-z0-9]/', '', $type) ?: '';
            if ($type !== '' && isset($known[$type])) {
                $mimes[$type] = $known[$type];
            }
        }

        return $mimes !== [] ? $mimes : [
            'pdf' => 'application/pdf',
            'jpg|jpeg' => 'image/jpeg',
            'png' => 'image/png',
        ];
    }

    private function create_captcha(): array
    {
        $a = random_int(2, 9);
        $b = random_int(2, 9);
        $token = wp_generate_password(24, false, false);
        set_transient(self::CAPTCHA_PREFIX . $token, (string) ($a + $b), 15 * MINUTE_IN_SECONDS);

        return [
            'token' => $token,
            'question' => sprintf('%d + %d = ?', $a, $b),
        ];
    }

    private function validate_captcha(string $token, string $answer): bool
    {
        if ($token === '' || $answer === '') {
            return false;
        }

        $expected = get_transient(self::CAPTCHA_PREFIX . $token);
        delete_transient(self::CAPTCHA_PREFIX . $token);

        return is_string($expected) && hash_equals($expected, trim($answer));
    }

    private function validate_external_antispam(array $settings): array
    {
        if (! empty($settings['turnstile_enabled'])) {
            $token = sanitize_text_field((string) wp_unslash($_POST['cf-turnstile-response'] ?? ''));
            $secret = (string) ($settings['turnstile_secret_key'] ?? '');
            if ($secret === '' || $token === '') {
                return ['error' => 'Bitte bestätigen Sie die Sicherheitsprüfung.'];
            }

            return $this->verify_antispam_token('https://challenges.cloudflare.com/turnstile/v0/siteverify', $secret, $token);
        }

        if (! empty($settings['recaptcha_enabled'])) {
            $token = sanitize_text_field((string) wp_unslash($_POST['g-recaptcha-response'] ?? ''));
            $secret = (string) ($settings['recaptcha_secret_key'] ?? '');
            if ($secret === '' || $token === '') {
                return ['error' => 'Bitte bestätigen Sie die Sicherheitsprüfung.'];
            }

            return $this->verify_antispam_token('https://www.google.com/recaptcha/api/siteverify', $secret, $token);
        }

        return ['error' => ''];
    }

    private function verify_antispam_token(string $url, string $secret, string $token): array
    {
        $response = wp_remote_post($url, [
            'timeout' => 8,
            'body' => [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $this->current_ip(),
            ],
        ]);

        if (is_wp_error($response)) {
            return ['error' => 'Die externe Sicherheitsprüfung ist gerade nicht erreichbar.'];
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($decoded) || empty($decoded['success'])) {
            return ['error' => 'Die externe Sicherheitsprüfung wurde nicht bestätigt.'];
        }

        return ['error' => ''];
    }

    private function visitor_cookie(): string
    {
        $value = sanitize_text_field((string) ($_COOKIE[self::COOKIE_NAME] ?? ''));
        if ($value !== '') {
            return $value;
        }

        $value = wp_generate_uuid4();
        $options = [
            'expires' => time() + YEAR_IN_SECONDS,
            'path' => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) {
            $options['domain'] = COOKIE_DOMAIN;
        }

        setcookie(self::COOKIE_NAME, $value, $options);

        $_COOKIE[self::COOKIE_NAME] = $value;
        return $value;
    }

    private function current_ip(): string
    {
        $candidate = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
        return sanitize_text_field(explode(',', $candidate)[0]);
    }

    private function browser_label(): string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (stripos($ua, 'Firefox') !== false) {
            return 'Firefox';
        }
        if (stripos($ua, 'Edg') !== false) {
            return 'Edge';
        }
        if (stripos($ua, 'Chrome') !== false) {
            return 'Chrome';
        }
        if (stripos($ua, 'Safari') !== false) {
            return 'Safari';
        }
        return 'Unbekannt';
    }

    private function device_type(): string
    {
        $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (str_contains($ua, 'tablet') || str_contains($ua, 'ipad')) {
            return 'tablet';
        }
        if (str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone')) {
            return 'mobile';
        }
        return 'desktop';
    }
}
