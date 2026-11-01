<?php
/** @var array<string, mixed> $settings */
/** @var array<int, array<string, mixed>> $users */
/** @var array<int, array<string, mixed>> $routes */
/** @var array<int, array<string, mixed>> $departments */
/** @var array<int, object> $wp_users */

if (! defined('ABSPATH')) {
    exit;
}

$telegram_ready = ! empty($settings['telegram_enabled']) && ! empty($settings['telegram_bot_token']);
$telegram_status = $telegram_ready ? 'Benachrichtigungen aktiviert' : (empty($settings['telegram_bot_token']) ? 'Nicht eingerichtet' : 'Benachrichtigungen aus');
?>
<div class="wrap feuer-support-admin feuer-telegram-page">
    <header class="feuer-page-heading">
        <div>
            <p class="feuer-eyebrow">Kommunikation / Telegram</p>
            <h1>Telegram</h1>
            <p>Bot verbinden, Mitarbeitende koppeln und Benachrichtigungen an die richtige Abteilung senden.</p>
        </div>
        <span class="feuer-status-badge <?php echo $telegram_ready ? 'is-online' : 'is-offline'; ?>">
            <span aria-hidden="true"></span><?php echo esc_html($telegram_status); ?>
        </span>
    </header>

    <?php $this->notice(); ?>

    <div class="feuer-telegram-overview" aria-label="Telegram-Übersicht">
        <div><span>Bot-Verbindung</span><strong><?php echo empty($settings['telegram_bot_token']) ? 'Token fehlt' : 'Token gespeichert'; ?></strong></div>
        <div><span>Gekoppelte Empfänger</span><strong><?php echo esc_html((string) count($users)); ?></strong></div>
        <div><span>Abteilungsrouten</span><strong><?php echo esc_html((string) count($routes)); ?></strong></div>
    </div>

    <div class="feuer-telegram-top">
        <section class="feuer-admin-card" aria-labelledby="feuer-telegram-connection-title">
            <div class="feuer-section-head">
                <div class="feuer-section-title"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><div><h2 id="feuer-telegram-connection-title">Bot-Verbindung</h2><p>Telegram dient als geschützter Benachrichtigungs- und Bedienkanal. Anfragen bleiben in WordPress gespeichert.</p></div></div>
            </div>
            <div class="feuer-section-body">
                <form method="post" class="feuer-telegram-form">
                    <?php wp_nonce_field('feuer_support_admin'); ?>
                    <input type="hidden" name="feuer_support_action" value="save_settings">
                    <input type="hidden" name="telegram_webhook_secret" value="<?php echo esc_attr((string) $settings['telegram_webhook_secret']); ?>">
                    <input type="hidden" name="telegram_enabled" value="0">
                    <label class="feuer-toggle-row">
                        <span><strong>Telegram-Benachrichtigungen</strong><small>Neue Anfragen an gekoppelte Mitarbeitende senden.</small></span>
                        <input type="checkbox" name="telegram_enabled" value="1" <?php checked(! empty($settings['telegram_enabled'])); ?>>
                    </label>
                    <div class="feuer-form-grid">
                        <label>Bot-Token
                            <input type="password" name="telegram_bot_token" value="" autocomplete="new-password" data-telegram-token aria-describedby="feuer-telegram-token-status" placeholder="<?php echo empty($settings['telegram_bot_token']) ? 'Token von @BotFather einfügen' : 'Gespeichert – leer lassen zum Beibehalten'; ?>">
                            <span id="feuer-telegram-token-status" class="description" data-telegram-token-status aria-live="polite">Token einfügen: Die Verbindung wird automatisch geprüft, ohne den Token zu speichern.</span>
                        </label>
                        <label>Datenschutzmodus
                            <select name="telegram_privacy_mode">
                                <option value="summary" <?php selected((string) $settings['telegram_privacy_mode'], 'summary'); ?>>Zusammenfassung mit E-Mail-Adresse</option>
                                <option value="masked" <?php selected((string) $settings['telegram_privacy_mode'], 'masked'); ?>>E-Mail-Adresse maskieren</option>
                            </select>
                            <span class="description">Steuert die sichtbaren Daten in Telegram-Nachrichten.</span>
                        </label>
                    </div>
                    <div class="feuer-form-actions"><button class="button button-primary">Einstellungen speichern</button></div>
                </form>
                <div class="feuer-webhook-box">
                    <div><strong>Webhook</strong><p>Nach dem Speichern des Tokens wird diese HTTPS-Adresse bei Telegram hinterlegt. Das geheime Header-Token wird serverseitig geprüft.</p></div>
                    <code><?php echo esc_html($webhook_url); ?></code>
                </div>
            </div>
        </section>

        <aside class="feuer-admin-card feuer-telegram-guide" aria-labelledby="feuer-telegram-guide-title">
            <div class="feuer-section-head"><div class="feuer-section-title"><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span><div><h2 id="feuer-telegram-guide-title">Einrichtung in 4 Schritten</h2><p>So verbinden Sie den Bot mit Ihrem Team.</p></div></div></div>
            <div class="feuer-section-body">
                <ol class="feuer-steps-list">
                    <li><strong>Bot erstellen</strong><span>In Telegram <b>@BotFather</b> öffnen und <code>/newbot</code> senden.</span></li>
                    <li><strong>Token hinterlegen</strong><span>Bot-Token einfügen, Live-Prüfung abwarten und speichern.</span></li>
                    <li><strong>Team koppeln</strong><span>Für jede Person einen Code erzeugen. Die Person sendet <code>/start CODE</code> privat an den Bot.</span></li>
                    <li><strong>Route festlegen</strong><span>Gekoppelte Empfänger den passenden Abteilungen zuordnen.</span></li>
                </ol>
                <p class="feuer-guide-note">Der Bot-Token wird verschlüsselt gespeichert und danach nicht erneut angezeigt.</p>
            </div>
        </aside>
    </div>

    <div class="feuer-telegram-bottom">
        <section class="feuer-admin-card" aria-labelledby="feuer-telegram-users-title">
            <div class="feuer-section-head"><div class="feuer-section-title"><span class="dashicons dashicons-groups" aria-hidden="true"></span><div><h2 id="feuer-telegram-users-title">Empfänger</h2><p>WordPress-Benutzer mit ihrem privaten Telegram-Konto koppeln.</p></div></div></div>
            <div class="feuer-section-body">
                <?php if ($users === []) : ?>
                    <p class="feuer-empty-state">Noch keine Telegram-Empfänger gekoppelt.</p>
                <?php else : ?>
                    <div class="feuer-table-scroll"><table class="widefat striped"><thead><tr><th>Telegram</th><th>Profil</th><th>Status</th><th>Letzte Aktivität</th></tr></thead><tbody>
                        <?php foreach ($users as $user) : ?><tr><td><strong><?php echo esc_html((string) $user['display_name']); ?></strong><br><small><?php echo esc_html((string) $user['telegram_user_id']); ?></small></td><td><?php echo esc_html(trim((string) $user['first_name'] . ' ' . (string) $user['last_name']) ?: 'Noch nicht eingerichtet'); ?></td><td><span class="feuer-status-badge <?php echo ! empty($user['is_active']) ? 'is-online' : 'is-offline'; ?>"><?php echo ! empty($user['is_active']) ? 'Aktiv' : 'Kopplung offen'; ?></span></td><td><?php echo esc_html((string) $user['last_seen_at']); ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
                <div class="feuer-card-divider"><h3>Empfänger koppeln</h3><p>Der Code ist zeitlich begrenzt und wird privat mit <code>/start CODE</code> an den Bot gesendet.</p></div>
                <form method="post" class="feuer-telegram-form">
                    <?php wp_nonce_field('feuer_support_admin'); ?>
                    <input type="hidden" name="feuer_support_action" value="create_telegram_pairing">
                    <label>WordPress-Benutzer<select name="telegram_wp_user_id" required><option value="">Bitte wählen</option><?php foreach ($wp_users as $wp_user) : ?><option value="<?php echo esc_attr((string) $wp_user->ID); ?>"><?php echo esc_html($wp_user->display_name . ' – ' . $wp_user->user_email); ?></option><?php endforeach; ?></select></label>
                    <fieldset class="feuer-choice-fieldset"><legend>Erlaubte Telegram-Aktionen</legend><div class="feuer-choice-grid"><?php foreach (['open_browser' => 'Im Browser öffnen', 'claim' => 'Übernehmen', 'read' => 'Als gelesen', 'reply' => 'Antworten', 'spam' => 'Als Spam markieren'] as $permission => $label) : ?><label class="feuer-check-label"><input type="checkbox" name="telegram_permissions[]" value="<?php echo esc_attr($permission); ?>" <?php checked($permission !== 'spam'); ?>> <?php echo esc_html($label); ?></label><?php endforeach; ?></div></fieldset>
                    <div class="feuer-form-actions"><button class="button button-primary">Kopplungscode erzeugen</button></div>
                </form>
                <?php if ((string) ($_GET['feuer-message'] ?? '') === 'telegram_pairing_created') : ?><div class="notice notice-success inline"><p>Kopplungscode: <strong><code><?php echo esc_html((string) wp_unslash($_GET['feuer-detail'] ?? '')); ?></code></strong>. Der Mitarbeiter sendet privat <code>/start CODE</code> an den Bot.</p></div><?php endif; ?>
            </div>
        </section>

        <section class="feuer-admin-card" aria-labelledby="feuer-telegram-routes-title">
            <div class="feuer-section-head"><div class="feuer-section-title"><span class="dashicons dashicons-randomize" aria-hidden="true"></span><div><h2 id="feuer-telegram-routes-title">Abteilungsrouting</h2><p>Neue Anfragen gezielt an aktiv gekoppelte Personen senden.</p></div></div></div>
            <div class="feuer-section-body">
                <?php if ($routes === []) : ?><p class="feuer-empty-state">Noch keine Abteilungsroute eingerichtet.</p><?php else : ?>
                    <div class="feuer-table-scroll"><table class="widefat striped"><thead><tr><th>Abteilung</th><th>Empfänger</th><th>Aktionen</th></tr></thead><tbody><?php foreach ($routes as $route) : ?><tr><td><strong><?php echo esc_html((string) $route['department_name']); ?></strong></td><td><?php echo esc_html((string) $route['display_name']); ?></td><td><?php echo esc_html(implode(', ', (array) $route['actions'])); ?></td></tr><?php endforeach; ?></tbody></table></div>
                <?php endif; ?>
                <div class="feuer-card-divider"><h3>Route hinzufügen</h3><p>Nur aktiv gekoppelte Empfänger können ausgewählt werden.</p></div>
                <form method="post" class="feuer-telegram-form">
                    <?php wp_nonce_field('feuer_support_admin'); ?>
                    <input type="hidden" name="feuer_support_action" value="save_telegram_route">
                    <div class="feuer-form-grid">
                        <label>Abteilung<select name="telegram_department_id" required><option value="">Bitte wählen</option><?php foreach ($departments as $department) : ?><option value="<?php echo esc_attr((string) $department['id']); ?>"><?php echo esc_html((string) $department['name']); ?></option><?php endforeach; ?></select></label>
                        <label>Telegram-Empfänger<select name="telegram_user_id" required><option value="">Bitte wählen</option><?php foreach ($users as $user) : ?><?php if (! empty($user['is_active'])) : ?><option value="<?php echo esc_attr((string) $user['telegram_user_id']); ?>"><?php echo esc_html((string) $user['display_name']); ?></option><?php endif; ?><?php endforeach; ?></select></label>
                    </div>
                    <input type="hidden" name="telegram_topic_id" value="0">
                    <fieldset class="feuer-choice-fieldset"><legend>Sichtbare Aktionen</legend><div class="feuer-choice-grid"><?php foreach (['open_browser' => 'Im Browser öffnen', 'claim' => 'Übernehmen', 'read' => 'Als gelesen', 'spam' => 'Als Spam markieren'] as $permission => $label) : ?><label class="feuer-check-label"><input type="checkbox" name="telegram_route_actions[]" value="<?php echo esc_attr($permission); ?>" checked> <?php echo esc_html($label); ?></label><?php endforeach; ?></div></fieldset>
                    <div class="feuer-form-actions"><button class="button button-primary">Routing speichern</button></div>
                </form>
            </div>
        </section>
    </div>
</div>
