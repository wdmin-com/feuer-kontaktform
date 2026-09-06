<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Feuer_Kontaktform_Updater
{
    private const UPDATE_BASE_URL = 'https://wdmin.com/plugins/feuer-kontaktform/';
    private const UPDATE_META_URL = 'https://wdmin.com/plugins/feuer-kontaktform/update.json';
    private const CACHE_KEY = 'feuer-kontaktform-update-info';
    private const LAST_CHECK_OPTION = 'feuer-kontaktform-update-last-check';

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_action('upgrader_process_complete', [$this, 'clear_cache']);
    }

    public function check_now(): array
    {
        delete_site_transient(self::CACHE_KEY);
        delete_site_transient('update_plugins');
        $info = $this->fetch_update_info(true);
        $this->refresh_wordpress_update_transient($info);
        update_option(self::LAST_CHECK_OPTION, [
            'checked_at' => current_time('mysql'),
            'remote_version' => (string) ($info['version'] ?? ''),
            'update_available' => ! empty($info['update_available']),
            'download_url' => (string) ($info['download_url'] ?? ''),
            'error' => (string) ($info['error'] ?? ''),
        ], false);

        return $info;
    }

    public function install_update(): array
    {
        if (! current_user_can('update_plugins')) {
            return [
                'success' => false,
                'message' => 'Der aktuelle Benutzer darf keine Plugins aktualisieren.',
            ];
        }

        $info = $this->check_now();
        if (! empty($info['error'])) {
            return [
                'success' => false,
                'message' => (string) $info['error'],
                'info' => $info,
            ];
        }

        if (empty($info['update_available'])) {
            return [
                'success' => false,
                'message' => 'Es ist keine neuere Version verfügbar.',
                'info' => $info,
            ];
        }

        if ((string) ($info['download_url'] ?? '') === '') {
            return [
                'success' => false,
                'message' => 'Es ist keine Download-URL für das Update vorhanden.',
                'info' => $info,
            ];
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $plugin_file = plugin_basename(FEUER_KONTAKTFORM_FILE);
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $result = $upgrader->upgrade($plugin_file);

        if (is_wp_error($result)) {
            return [
                'success' => false,
                'message' => implode(' ', array_map('sanitize_text_field', $result->get_error_messages())),
                'info' => $info,
            ];
        }

        if (isset($skin->result) && is_wp_error($skin->result)) {
            return [
                'success' => false,
                'message' => implode(' ', array_map('sanitize_text_field', $skin->result->get_error_messages())),
                'info' => $info,
            ];
        }

        if ($result !== true) {
            return [
                'success' => false,
                'message' => 'WordPress konnte das Plugin-Update nicht installieren.',
                'info' => $info,
            ];
        }

        $this->clear_cache();
        $installed_version = $this->installed_version();
        $remote_version = sanitize_text_field((string) ($info['version'] ?? ''));
        $post_install_info = $this->with_current_version($info);
        $this->refresh_wordpress_update_transient($post_install_info);
        update_option(self::LAST_CHECK_OPTION, [
            'checked_at' => current_time('mysql'),
            'remote_version' => $remote_version,
            'installed_version' => $installed_version,
            'update_available' => ! empty($post_install_info['update_available']),
            'download_url' => (string) ($post_install_info['download_url'] ?? ''),
            'error' => (string) ($post_install_info['error'] ?? ''),
        ], false);

        if ($remote_version !== '' && ($installed_version === '' || version_compare($installed_version, $remote_version, '<'))) {
            return [
                'success' => false,
                'message' => 'Das Update wurde zwar gestartet, aber die installierte Version ist weiterhin ' . ($installed_version !== '' ? $installed_version : 'unbekannt') . ' statt ' . $remote_version . '. Bitte prüfen Sie Dateirechte, Server-Cache und das Update-Paket.',
                'info' => $post_install_info,
            ];
        }

        return [
            'success' => true,
            'message' => 'Update wurde installiert.',
            'info' => $post_install_info,
        ];
    }

    public function get_status(): array
    {
        $last = get_option(self::LAST_CHECK_OPTION, []);
        $info = $this->fetch_update_info(false);

        return [
            'base_url' => self::UPDATE_BASE_URL,
            'meta_url' => self::UPDATE_META_URL,
            'current_version' => $this->installed_version(),
            'remote_version' => (string) ($info['version'] ?? ($last['remote_version'] ?? '')),
            'update_available' => ! empty($info['update_available']),
            'download_url' => (string) ($info['download_url'] ?? (is_array($last) ? ($last['download_url'] ?? '') : '')),
            'tested' => (string) ($info['tested'] ?? ''),
            'checked_at' => is_array($last) ? (string) ($last['checked_at'] ?? '') : '',
            'error' => (string) ($info['error'] ?? (is_array($last) ? ($last['error'] ?? '') : '')),
        ];
    }

    public function inject_update($transient)
    {
        if (! is_object($transient)) {
            return $transient;
        }

        $info = $this->fetch_update_info(false);
        $plugin_file = plugin_basename(FEUER_KONTAKTFORM_FILE);
        if (! isset($transient->response) || ! is_array($transient->response)) {
            $transient->response = [];
        }
        if (! isset($transient->no_update) || ! is_array($transient->no_update)) {
            $transient->no_update = [];
        }
        if (empty($info['update_available'])) {
            unset($transient->response[$plugin_file]);
            $transient->no_update[$plugin_file] = (object) [
                'id' => 'feuer-kontaktform',
                'slug' => 'feuer-kontaktform',
                'plugin' => $plugin_file,
                'new_version' => $this->installed_version(),
                'url' => self::UPDATE_BASE_URL,
                'package' => '',
                'tested' => $this->compatible_tested_version((string) ($info['tested'] ?? '')),
                'requires' => (string) ($info['requires'] ?? '6.9'),
                'requires_php' => (string) ($info['requires_php'] ?? '8.1'),
            ];
            return $transient;
        }

        $transient->response[$plugin_file] = (object) [
            'id' => 'feuer-kontaktform',
            'slug' => 'feuer-kontaktform',
            'plugin' => $plugin_file,
            'new_version' => (string) $info['version'],
            'url' => self::UPDATE_BASE_URL,
            'package' => (string) $info['download_url'],
            'tested' => $this->compatible_tested_version((string) ($info['tested'] ?? '')),
            'requires' => (string) ($info['requires'] ?? '6.9'),
            'requires_php' => (string) ($info['requires_php'] ?? '8.1'),
        ];

        return $transient;
    }

    public function plugin_info($result, string $action, object $args)
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'feuer-kontaktform') {
            return $result;
        }

        $info = $this->fetch_update_info(false);
        if (($info['error'] ?? '') !== '') {
            return (object) [
                'name' => 'Feuer-Kontaktform',
                'slug' => 'feuer-kontaktform',
                'version' => $this->installed_version(),
                'author' => '<a href="https://wdmin.com/">Walter Färber</a>',
                'homepage' => self::UPDATE_BASE_URL,
                'requires' => '6.9',
                'requires_php' => '8.1',
                'tested' => $this->compatible_tested_version(''),
                'download_link' => '',
                'last_updated' => '',
                'sections' => [
                    'description' => 'Kontaktform ist ein Kontaktformular- und Anfrage-System für Websites freiwilliger Feuerwehren.',
                    'changelog' => 'Update-Metadaten konnten gerade nicht geladen werden: ' . (string) $info['error'],
                ],
            ];
        }

        return (object) [
            'name' => 'Feuer-Kontaktform',
            'slug' => 'feuer-kontaktform',
            'version' => (string) ($info['version'] ?? $this->installed_version()),
            'author' => '<a href="https://wdmin.com/">Walter Färber</a>',
            'homepage' => self::UPDATE_BASE_URL,
            'requires' => (string) ($info['requires'] ?? '6.9'),
            'requires_php' => (string) ($info['requires_php'] ?? '8.1'),
            'tested' => $this->compatible_tested_version((string) ($info['tested'] ?? '')),
            'download_link' => '',
            'last_updated' => (string) ($info['last_updated'] ?? ''),
            'sections' => [
                'description' => (string) ($info['description'] ?? 'Kontaktformular- und Ticket-Plugin für freiwillige Feuerwehren.'),
                'changelog' => (string) ($info['changelog'] ?? ''),
            ],
        ];
    }

    private function fetch_update_info(bool $force): array
    {
        if (! $force) {
            $cached = get_site_transient(self::CACHE_KEY);
            if (is_array($cached)) {
                return $this->with_current_version($cached);
            }
        }

        $response = wp_remote_get(self::UPDATE_META_URL, [
            'timeout' => 12,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return $this->cache_result([
                'error' => implode(' ', array_map('sanitize_text_field', $response->get_error_messages())),
                'update_available' => false,
            ]);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300 || trim($body) === '') {
            return $this->cache_result([
                'error' => 'Update-Server antwortete mit HTTP ' . $code . '.',
                'update_available' => false,
            ]);
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return $this->cache_result([
                'error' => 'Update-Informationen enthalten kein gültiges JSON.',
                'update_available' => false,
            ]);
        }

        $version = sanitize_text_field((string) ($decoded['version'] ?? ''));
        $download_url = esc_url_raw((string) ($decoded['download_url'] ?? self::UPDATE_BASE_URL . 'release/latest/feuer-kontaktform.zip'));
        $result = [
            'version' => $version,
            'download_url' => $download_url,
            'requires' => sanitize_text_field((string) ($decoded['requires'] ?? '6.9')),
            'requires_php' => sanitize_text_field((string) ($decoded['requires_php'] ?? '8.1')),
            'tested' => $this->compatible_tested_version(sanitize_text_field((string) ($decoded['tested'] ?? ''))),
            'last_updated' => sanitize_text_field((string) ($decoded['last_updated'] ?? '')),
            'description' => wp_kses_post((string) ($decoded['description'] ?? '')),
            'changelog' => wp_kses_post((string) ($decoded['changelog'] ?? '')),
            'update_available' => $version !== '' && version_compare($version, $this->installed_version(), '>'),
            'error' => '',
        ];

        if ($download_url === '' || ! str_starts_with($download_url, self::UPDATE_BASE_URL)) {
            $result['download_url'] = '';
            $result['update_available'] = false;
            $result['error'] = 'Download-URL ist nicht im freigegebenen Plugin-Verzeichnis.';
        }

        return $this->cache_result($this->with_current_version($result));
    }

    private function cache_result(array $result): array
    {
        set_site_transient(self::CACHE_KEY, $result, HOUR_IN_SECONDS);
        return $result;
    }

    public function clear_cache($upgrader = null, array $hook_extra = []): void
    {
        delete_site_transient(self::CACHE_KEY);
        delete_site_transient('update_plugins');

        if (! function_exists('wp_clean_plugins_cache')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(true);
        }
    }

    private function refresh_wordpress_update_transient(array $info): void
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $plugin_file = plugin_basename(FEUER_KONTAKTFORM_FILE);
        $plugins = function_exists('get_plugins') ? get_plugins() : [];
        $transient = get_site_transient('update_plugins');

        if (! is_object($transient)) {
            $transient = new stdClass();
        }

        if (! isset($transient->checked) || ! is_array($transient->checked)) {
            $transient->checked = [];
        }

        foreach ($plugins as $file => $plugin) {
            $transient->checked[$file] = (string) ($plugin['Version'] ?? '');
        }

        if (! isset($transient->response) || ! is_array($transient->response)) {
            $transient->response = [];
        }

        if (! isset($transient->no_update) || ! is_array($transient->no_update)) {
            $transient->no_update = [];
        }

        $transient->last_checked = time();
        unset($transient->response[$plugin_file], $transient->no_update[$plugin_file]);

        if (! empty($info['update_available']) && (string) ($info['download_url'] ?? '') !== '') {
            $transient->response[$plugin_file] = (object) [
                'id' => 'feuer-kontaktform',
                'slug' => 'feuer-kontaktform',
                'plugin' => $plugin_file,
                'new_version' => (string) $info['version'],
                'url' => self::UPDATE_BASE_URL,
                'package' => (string) $info['download_url'],
                'tested' => $this->compatible_tested_version((string) ($info['tested'] ?? '')),
                'requires' => (string) ($info['requires'] ?? '6.9'),
                'requires_php' => (string) ($info['requires_php'] ?? '8.1'),
            ];
        } else {
            $transient->no_update[$plugin_file] = (object) [
                'id' => 'feuer-kontaktform',
                'slug' => 'feuer-kontaktform',
                'plugin' => $plugin_file,
                'new_version' => $this->installed_version(),
                'url' => self::UPDATE_BASE_URL,
                'package' => '',
                'tested' => $this->compatible_tested_version((string) ($info['tested'] ?? '')),
                'requires' => (string) ($info['requires'] ?? '6.9'),
                'requires_php' => (string) ($info['requires_php'] ?? '8.1'),
            ];
        }

        set_site_transient('update_plugins', $transient);
    }

    private function with_current_version(array $result): array
    {
        $version = sanitize_text_field((string) ($result['version'] ?? ''));
        $current = $this->installed_version();
        $result['update_available'] = $version !== '' && $current !== '' && version_compare($version, $current, '>');
        $result['tested'] = $this->compatible_tested_version((string) ($result['tested'] ?? ''));
        return $result;
    }

    private function installed_version(): string
    {
        $file = defined('FEUER_KONTAKTFORM_FILE') ? (string) FEUER_KONTAKTFORM_FILE : '';
        if ($file !== '' && is_readable($file)) {
            $contents = @file_get_contents($file);
            if (is_string($contents) && preg_match('/^[ \t\/*#@]*Version:\s*([0-9][^\r\n]*)/mi', $contents, $matches)) {
                return sanitize_text_field(trim((string) $matches[1]));
            }
        }

        return defined('FEUER_KONTAKTFORM_VERSION') ? (string) FEUER_KONTAKTFORM_VERSION : '';
    }

    private function compatible_tested_version(string $tested): string
    {
        $tested = sanitize_text_field($tested);
        $current = function_exists('get_bloginfo') ? sanitize_text_field((string) get_bloginfo('version')) : '';
        $current = preg_replace('/[^0-9.].*$/', '', $current) ?: $current;

        if ($current === '') {
            return $tested;
        }

        if ($tested === '' || version_compare($tested, $current, '<')) {
            return $current;
        }

        return $tested;
    }
}
