<?php
/*
Plugin Name: Breach Radar via verisizintisi.com
Plugin URI: https://verisizintisi.com/breach-radar
Description: Check your WordPress users' emails against known data breaches via verisizintisi.com API and take action on risks.
Version: 1.0.1
Author: verisizintisi.com
Author URI: https://verisizintisi.com
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.2
Text Domain: breach-radar
*/

if (!defined('ABSPATH')) exit;

define('BREACH_RADAR_VERSION', '1.0.1');

class VeriSizintisi_Plugin {
    private static $instance = null;
    const OPTION_GROUP = 'verisizintisi_options';
    const OPTION_NAME  = 'verisizintisi_settings';
    const API_BASE     = 'https://api.verisizintisi.com/plugins/wordpress/v1';
    private $vs_locale_switched = false;
    private $vs_i18n_locale = '';
    private $vs_po_map = [];

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_admin_pages']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        // Charts removed; ajax route disabled
        // Fallback: tablo yoksa admin_init'te oluşturmayı dene
        add_action('admin_init', [$this, 'maybe_create_table']);
        // Plugin row action: Settings link
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'plugin_action_links']);
        // Shortcode ve widgetlarda shortcode desteği
        add_action('init', [$this, 'register_shortcodes']);
        add_filter('widget_text', 'do_shortcode');
        // CRON handler
        add_action('verisizintisi_daily_scan', ['VeriSizintisi_Plugin', 'cron_run']);
        // Admin-post handler for scan
        add_action('admin_post_verisizintisi_scan_now', [$this, 'handle_scan_now']);
        add_action('admin_post_verisizintisi_notify_user', [$this, 'handle_notify_user']);
        // WP Dashboard widget
        add_action('wp_dashboard_setup', [$this, 'register_wp_dashboard_widget']);
        // Customizer
        add_action('customize_register', [$this, 'customizer_register']);
        add_action('wp_footer', [$this, 'render_badge_in_footer']);
        // i18n: WordPress.org auto-loads translations since 4.6; no manual load
        // Ensure daily cron exists (self-healing if activation was missed)
        add_action('admin_init', [$this, 'ensure_cron_scheduled']);
    }

    private function get_plugin_locale() {
        $opts = get_option(self::OPTION_NAME);
        $sel = isset($opts['plugin_language']) ? (string)$opts['plugin_language'] : 'auto';
        if ($sel && $sel !== 'auto') return $sel;
        return function_exists('get_locale') ? get_locale() : 'en_US';
    }

    private function is_tr() {
        $loc = $this->get_plugin_locale();
        return (stripos($loc, 'tr') === 0);
    }

    private function L($tr, $en) {
        // Prefer Turkish literal for TR
        if ($this->is_tr()) return $tr;
        // For other locales, try PO map (az_AZ, ru_RU) then gettext fallback
        $loc = $this->get_plugin_locale();
        if ($loc !== $this->vs_i18n_locale) {
            $this->load_po_translations($loc);
        }
        if (!empty($this->vs_po_map) && isset($this->vs_po_map[$en]) && $this->vs_po_map[$en] !== '') {
            return $this->vs_po_map[$en];
        }
        // Fallback: return English source
        return $en;
    }

    private function load_po_translations($locale) {
        $this->vs_i18n_locale = $locale;
        $this->vs_po_map = [];
        $path = trailingslashit(plugin_dir_path(__FILE__)) . 'languages/breach-radar-' . $locale . '.po';
        if (!file_exists($path)) return;
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) return;
        $currentId = null; $currentStr = null; $mode = '';
        $append = function(&$target, $line) {
            // strip starting and ending quotes
            $line = preg_replace('/^\s*"(.*)"\s*$/', '$1', $line);
            // unescape common sequences
            $line = str_replace(['\\"','\\n','\\r','\\t','\\\\'], ['"',"\n","\r","\t","\\"], $line);
            $target .= $line;
        };
        foreach ($lines as $ln) {
            if (strpos($ln, 'msgid "') === 0) { $mode = 'id'; $currentId = ''; $currentStr = ''; $append($currentId, substr($ln, 6)); continue; }
            if (strpos($ln, 'msgstr "') === 0) { $mode = 'str'; $append($currentStr, substr($ln, 7)); continue; }
            if ($ln === '') {
                if ($currentId !== null) {
                    $id = $currentId; $str = $currentStr;
                    if ($id !== '' || $str !== '') { $this->vs_po_map[$id] = $str; }
                }
                $currentId = $currentStr = null; $mode = '';
                continue;
            }
            if ($mode === 'id') { $append($currentId, $ln); }
            elseif ($mode === 'str') { $append($currentStr, $ln); }
        }
        if ($currentId !== null) {
            $id = $currentId; $str = $currentStr; $this->vs_po_map[$id] = $str;
        }
    }

    private function apply_locale() {
        $target = $this->get_plugin_locale();
        if (function_exists('switch_to_locale') && $target && $target !== get_locale()) {
            $this->vs_locale_switched = switch_to_locale($target);
        }
    }

    private function restore_locale() {
        if ($this->vs_locale_switched && function_exists('restore_previous_locale')) {
            restore_previous_locale();
            $this->vs_locale_switched = false;
        }
    }

    // Build a sanitized site URL with https scheme and trailing slash
    private static function get_site_url_safe() {
        if (function_exists('home_url')) {
            $u = home_url('/');
            $u = esc_url_raw($u);
            return $u ? $u : '';
        }
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        // keep only safe host characters
        $host = preg_replace('/[^A-Za-z0-9\-\.:]/', '', $host);
        if ($host === '') return '';
        // strip anything after host
        $hostOnly = preg_split('#[\/\?#]#', $host)[0];
        return 'https://' . $hostOnly . '/';
    }

    // Lightweight object cache helpers
    private static function cache_get($key) {
        if (function_exists('wp_cache_get')) {
            $v = wp_cache_get($key, 'verisizintisi');
            return ($v !== false) ? $v : false;
        }
        return false;
    }
    private static function cache_set($key, $value, $ttl = 60) {
        if (function_exists('wp_cache_set')) {
            wp_cache_set($key, $value, 'verisizintisi', $ttl);
        }
    }

    // DB table name (static for activation hook and instance methods)
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'vs_scan_logs';
    }

    // Activation hook: create logs table
    public static function on_activate() {
        global $wpdb;
        $table = self::table();
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL,
            found TINYINT(1) NOT NULL DEFAULT 0,
            breach_count INT UNSIGNED NOT NULL DEFAULT 0,
            response_ms INT UNSIGNED NULL,
            http_status SMALLINT UNSIGNED NULL,
            error_code VARCHAR(64) NULL,
            scanned_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_created (created_at),
            KEY idx_email (email)
        ) {$charset_collate};";
        dbDelta($sql);
    }

    public static function activate() {
        // Create table
        self::on_activate();
        // Schedule daily scan if not already
        if (!wp_next_scheduled('verisizintisi_daily_scan')) {
            wp_schedule_event(time() + wp_rand(300, 3600), 'daily', 'verisizintisi_daily_scan');
        }
        // Initialize last cron timestamp
        if (!get_option('verisizintisi_last_cron')) {
            update_option('verisizintisi_last_cron', time());
        }
    }

    public static function deactivate() {
        // Clear scheduled event
        $ts = wp_next_scheduled('verisizintisi_daily_scan');
        if ($ts) wp_unschedule_event($ts, 'verisizintisi_daily_scan');
    }

    public function ensure_cron_scheduled() {
        $opts = get_option(self::OPTION_NAME);
        $enabled = isset($opts['daily_scan_enabled']) ? (bool)$opts['daily_scan_enabled'] : true;
        if ($enabled && !wp_next_scheduled('verisizintisi_daily_scan')) {
            wp_schedule_event(time() + wp_rand(300, 3600), 'daily', 'verisizintisi_daily_scan');
        }
        if (!$enabled) {
            $ts = wp_next_scheduled('verisizintisi_daily_scan');
            if ($ts) wp_unschedule_event($ts, 'verisizintisi_daily_scan');
        }
        // Self-heal: if last run was > 36h ago and nothing is scheduled, queue a single run
        if ($enabled) {
            $last = (int) get_option('verisizintisi_last_cron', 0);
            $has_future = (bool) wp_next_scheduled('verisizintisi_daily_scan');
            if ((!$last || (time() - $last) > 36 * HOUR_IN_SECONDS) && !$has_future) {
                wp_schedule_single_event(time() + 60, 'verisizintisi_daily_scan');
            }
        }
    }

    // Fallback oluşturma (aktivasyon kaçırıldıysa)
    public function maybe_create_table() {
        global $wpdb;
        $table = self::table();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            self::on_activate();
        }
    }

    public function add_admin_pages() {
        add_menu_page(
            $this->L('Breach Radar', 'Breach Radar'),
            $this->L('Breach Radar', 'Breach Radar'),
            'manage_options',
            'verisizintisi',
            [$this, 'render_dashboard_page'],
            'dashicons-shield-alt',
            80
        );
        // Order: Genel Bakış, Tarama, Kayıtlar, Rozet, Ayarlar
        add_submenu_page('verisizintisi', $this->L('Genel Bakış', 'Overview'), $this->L('Genel Bakış', 'Overview'), 'manage_options', 'verisizintisi', [$this, 'render_dashboard_page']);
        add_submenu_page('verisizintisi', $this->L('Tarama', 'Scan'), $this->L('Tarama', 'Scan'), 'manage_options', 'verisizintisi_scan', [$this, 'render_scan_page']);
        add_submenu_page('verisizintisi', $this->L('Kayıtlar', 'Logs'), $this->L('Kayıtlar', 'Logs'), 'manage_options', 'verisizintisi_logs', [$this, 'render_logs_page']);
        add_submenu_page('verisizintisi', $this->L('Rozet', 'Badge'), $this->L('Rozet', 'Badge'), 'manage_options', 'verisizintisi_badge', [$this, 'render_badge_page']);
        add_submenu_page('verisizintisi', $this->L('Ayarlar', 'Settings'), $this->L('Ayarlar', 'Settings'), 'manage_options', 'verisizintisi_settings', [$this, 'render_settings_page']);
    }

    public function register_settings() {
        register_setting(self::OPTION_GROUP, self::OPTION_NAME, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default' => [
                'api_token' => '',
                'notifications_enabled' => false,
                'notification_delta_threshold' => 1,
                'plugin_language' => 'auto',
                'daily_scan_enabled' => true,
            ]
        ]);

        add_settings_section('verisizintisi_api_section', $this->L('API Ayarları', 'API Settings'), function() {
            // API adresi bilgisini göstermiyoruz
        }, 'verisizintisi_settings');

        add_settings_field('api_token', $this->L('API Anahtarı', 'API Key'), function() {
            $opts = get_option(self::OPTION_NAME);
            printf('<input type="text" name="%s[api_token]" value="%s" class="regular-text" placeholder="vs_xxx.yyy" />', esc_attr(self::OPTION_NAME), esc_attr($opts['api_token'] ?? ''));
        }, 'verisizintisi_settings', 'verisizintisi_api_section');

        add_settings_section('verisizintisi_notify_section', $this->L('Bildirimler', 'Notifications'), function() {
            echo '<p class="description">' . esc_html($this->L('Artış olduğunda yöneticiye e‑posta gönder.', 'Email the admin when counts increase.')) . '</p>';
        }, 'verisizintisi_settings');

        add_settings_field('notifications_enabled', $this->L('Artış bildirimleri', 'Increase alerts'), function(){
            $opts = get_option(self::OPTION_NAME);
            $checked = !empty($opts['notifications_enabled']) ? 'checked' : '';
            printf('<label><input type="checkbox" name="%s[notifications_enabled]" value="1" %s> %s</label>', esc_attr(self::OPTION_NAME), esc_attr($checked), esc_html($this->L('Etkin', 'Enabled')));
        }, 'verisizintisi_settings', 'verisizintisi_notify_section');

        add_settings_field('notification_delta_threshold', $this->L('Eşik (artış ≥)', 'Threshold (increase ≥)'), function(){
            $opts = get_option(self::OPTION_NAME);
            $val = isset($opts['notification_delta_threshold']) ? intval($opts['notification_delta_threshold']) : 1;
            printf('<input type="number" min="1" step="1" name="%s[notification_delta_threshold]" value="%d" class="small-text" />', esc_attr(self::OPTION_NAME), esc_attr($val));
        }, 'verisizintisi_settings', 'verisizintisi_notify_section');

        // Daily scan toggle
        add_settings_field('daily_scan_enabled', $this->L('Günlük tarama', 'Daily scan'), function(){
            $opts = get_option(self::OPTION_NAME);
            $checked = !empty($opts['daily_scan_enabled']) ? 'checked' : '';
            printf('<label><input type="checkbox" name="%s[daily_scan_enabled]" value="1" %s> %s</label>', esc_attr(self::OPTION_NAME), esc_attr($checked), esc_html($this->L('Etkin', 'Enabled')));
        }, 'verisizintisi_settings', 'verisizintisi_notify_section');

        // Language selector
        add_settings_section('verisizintisi_lang_section', $this->L('Dil', 'Language'), function() {
            echo '<p class="description">' . esc_html($this->L('Eklenti arayüz dili (yalnızca bu eklenti için).', 'Plugin UI language (only for this plugin).')) . '</p>';
        }, 'verisizintisi_settings');

        add_settings_field('plugin_language', $this->L('Dil Seçimi', 'Language Selection'), function(){
            $opts = get_option(self::OPTION_NAME);
            $val = isset($opts['plugin_language']) ? (string)$opts['plugin_language'] : 'auto';
            $choices = [
                'auto' => $this->L('Otomatik (Site dili)', 'Auto (Site language)'),
                'tr_TR' => 'Türkçe',
                'en_US' => 'English',
                'az_AZ' => 'Azərbaycanca',
                'ru_RU' => 'Русский',
            ];
            echo '<select name="' . esc_attr(self::OPTION_NAME) . '[plugin_language]">';
            foreach ($choices as $k => $label) {
                printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($val, $k, false), esc_html($label));
            }
            echo '</select>';
        }, 'verisizintisi_settings', 'verisizintisi_lang_section');
    }

    public function sanitize_settings($input) {
        $out = [];
        $out['api_token'] = isset($input['api_token']) ? sanitize_text_field($input['api_token']) : '';
        $out['notifications_enabled'] = !empty($input['notifications_enabled']);
        $out['notification_delta_threshold'] = max(1, intval($input['notification_delta_threshold'] ?? 1));
        $allowed_locales = ['auto','tr_TR','en_US','az_AZ','ru_RU'];
        $pl = isset($input['plugin_language']) ? (string)$input['plugin_language'] : 'auto';
        $out['plugin_language'] = in_array($pl, $allowed_locales, true) ? $pl : 'auto';
        $out['daily_scan_enabled'] = !empty($input['daily_scan_enabled']);
        return $out;
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'verisizintisi') === false && $hook !== 'index.php') return;
        // Styles
        wp_add_inline_style('wp-admin', '.vs-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}.vs-card{background:#fff;border:1px solid #eceff5;border-radius:12px;padding:16px;margin-top:14px;box-shadow:0 1px 2px rgba(16,24,40,.04)}.vs-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.vs-success{border-left:3px solid #22c55e;padding-left:12px}.vs-error{border-left:3px solid #ef4444;padding-left:12px}.vs-kpi{font-size:22px;font-weight:600}.vs-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-top:8px}.kpi-card{display:flex;align-items:center;gap:12px;background:#fff;border:1px solid #eceff5;border-radius:12px;padding:14px}.kpi-ico{width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;font-size:14px}.vs-muted{color:#64748b}.vs-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px}.vs-badge.ok{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}.vs-badge.err{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}.vs-table{width:100%;border-collapse:collapse}.vs-table th,.vs-table td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left}');
        // Charts removed; no scripts enqueued
    }

    // AJAX: chart data provider
    // ajax_chart_data removed

    public function render_settings_page() {
        $this->apply_locale();
        if (!current_user_can('manage_options')) return;
        $opts = get_option(self::OPTION_NAME);
        $roles_all = function_exists('wp_roles') ? wp_roles()->roles : [];
        $include_roles = isset($opts['include_roles']) && is_array($opts['include_roles']) ? array_map('sanitize_text_field', $opts['include_roles']) : [];
        $exclude_emails = isset($opts['exclude_emails']) ? (string) $opts['exclude_emails'] : '';
        echo '<div class="wrap">';
        echo '<h1>' . esc_html( $this->L('Breach Radar • Ayarlar', 'Breach Radar • Settings') ) . '</h1>';
        // API adresi sabit bilgisini kullanıcıya göstermiyoruz
        echo '<form method="post" action="options.php" class="vs-card">';
        settings_fields(self::OPTION_GROUP);
        do_settings_sections('verisizintisi_settings');
        echo '<h2>' . esc_html( $this->L('Tarama Filtreleri', 'Scan Filters') ) . '</h2>';
        echo '<p class="vs-muted">' . esc_html( $this->L('Dahil edilecek roller', 'Included roles') ) . '</p>';
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap">';
        foreach ($roles_all as $role_key => $role) {
            $checked = in_array($role_key, $include_roles, true) ? 'checked' : '';
            printf('<label><input type="checkbox" name="%s[include_roles][]" value="%s" %s> %s</label>', esc_attr(self::OPTION_NAME), esc_attr($role_key), esc_attr($checked), esc_html($role['name']));
        }
        echo '</div>';
        echo '<p class="vs-muted" style="margin-top:12px">' . esc_html( $this->L('Hariç tutulacak e‑postalar (virgülle ayırın)', 'Excluded emails (comma‑separated)') ) . '</p>';
        printf('<textarea name="%s[exclude_emails]" rows="3" style="width:100%%">%s</textarea>', esc_attr(self::OPTION_NAME), esc_textarea($exclude_emails));
        submit_button();
        echo '</form>';

        // Integration content moved here
        echo '<div class="vs-card" style="margin-top:12px">';
        echo '<h2>' . esc_html( $this->L('Entegrasyon', 'Integration') ) . '</h2>';
        $apiLink = 'https://get.verisizintisi.com/wordpress';
        echo '<p class="vs-muted">' . sprintf( esc_html( $this->L('Eklentiyi kullanmak için %s üzerinden WordPress için API anahtarı oluşturun ve Ayarlar sayfasına yapıştırın.', 'To use the plugin, create your WordPress API key at %s and paste it in Settings.') ), '<a href="' . esc_url($apiLink) . '" target="_blank" rel="noopener">get.verisizintisi.com/wordpress</a>' ) . '</p>';
        echo '<ol class="vs-muted" style="padding-left:18px">';
        echo '<li>' . esc_html( $this->L('API anahtarını alın.', 'Get your API key.') ) . '</li>';
        echo '<li>' . esc_html( $this->L('WordPress yönetimde Breach Radar → Ayarlar menüsünden anahtarı kaydedin.', 'In WordPress admin, go to Breach Radar → Settings and save the key.') ) . '</li>';
        echo '<li>' . esc_html( $this->L('Panelden tarama ve öngörüleri takip edin.', 'Track scans and insights from the Dashboard.') ) . '</li>';
        echo '</ol>';
        echo '<h3 style="margin-top:12px">' . esc_html( $this->L('Rozet (Badge)', 'Badge') ) . '</h3>';
        echo '<p class="vs-muted">' . esc_html( $this->L('Koruma rozetini sitenize eklemek için kısa kodu kullanın veya tema özelleştirmeden alt bilgiye ekleyin.', 'Add the protection badge via shortcode or from theme customizer to footer.') ) . '</p>';
        echo '<div style="margin-top:8px">' . do_shortcode('[verisizintisi_badge]') . '</div>';
        echo '</div>';

        // Consent notice
        echo '<div class="vs-card" style="margin-top:12px">';
        echo '<h2>' . esc_html( $this->L('Rıza ve Bilgilendirme', 'Consent & Notice') ) . '</h2>';
        echo '<p class="vs-muted">' . esc_html( $this->L('E‑posta adreslerini sızıntı veri setlerine karşı kontrol etmeden önce kullanıcıları bilgilendirin ve gerekiyorsa rızalarını alın. Yerel mevzuata ve gizlilik politikanıza uyun.', 'Before checking email addresses against breach datasets, inform users and obtain consent where required. Follow local laws and your privacy policy.') ) . '</p>';
        echo '<p class="vs-muted">' . esc_html( $this->L('Bu eklenti WordPress içinde özetler gösterir; detaylar verisizintisi.com üzerinde kullanıcıya özeldir.', 'This plugin shows summaries in WordPress; detailed contents remain user‑private on verisizintisi.com.') ) . '</p>';
        echo '</div>';
        echo '</div>';
        $this->restore_locale();
    }

    private function api_check_email($opts, $email) {
        $base = rtrim(self::API_BASE, '/');
        $token = $opts['api_token'] ?? '';
        if (!$token) return new WP_Error('vs_config', $this->L('Önce ayarları kaydedin (API anahtarı).', 'Save settings first (API key).'));

        $endpoint = $base . '/check';
        $t0 = microtime(true);
        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'site' => self::get_site_url_safe(),
                'emails' => [ $email ],
                'include_details' => false
            ]),
            'timeout' => 10,
        ];
        $response = wp_remote_post($endpoint, $args);
        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code !== 200) {
            $bodyArr = json_decode($body, true);
            if (is_array($bodyArr) && !empty($bodyArr['error'])) {
                // Minimal and user-friendly error messages
                $msg = $this->L('API hatası. Lütfen ayarlarınızı kontrol edin.', 'API error. Please check your settings.');
                return new WP_Error('vs_http', $msg);
            }
            return new WP_Error('vs_http', $this->L('Sunucuya bağlanılamadı.', 'Could not connect to server.'));
        }
        $data = json_decode($body, true);
        if (!is_array($data)) return new WP_Error('vs_json', __('Invalid JSON response.', 'breach-radar'));
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $res = $data['results'][0] ?? [ 'found' => false, 'count' => 0 ];
        if (is_array($res)) {
            $res['_meta'] = [ 'ms' => $ms, 'code' => $code ];
        }
        return $res;
    }

    // Run a batch scan according to settings (filters)
    private function run_scan_batch($limit = 100) {
        $opts = get_option(self::OPTION_NAME);
        $token = $opts['api_token'] ?? '';
        if (!$token) return 0;
        $include_roles = isset($opts['include_roles']) && is_array($opts['include_roles']) ? array_map('sanitize_text_field', $opts['include_roles']) : [];
        $exclude_emails = isset($opts['exclude_emails']) ? array_map('trim', explode(',', (string)$opts['exclude_emails'])) : [];
        $args = ['number' => max(1, intval($limit)), 'orderby' => 'registered', 'order' => 'DESC'];
        if (!empty($include_roles)) $args['role__in'] = $include_roles;
        $users = get_users($args);
        $processed = 0;
        foreach ($users as $u) {
            if (in_array($u->user_email, $exclude_emails, true)) continue;
            $resp = $this->api_check_email($opts, $u->user_email);
            if (!is_wp_error($resp)) {
                $found = !empty($resp['found']);
                $count = intval($resp['count'] ?? 0);
                $meta  = $resp['_meta'] ?? [];
                $this->insert_log($u->user_email, $found ? 1 : 0, $count, intval($meta['ms'] ?? 0), intval($meta['code'] ?? 200), null);
                $processed++;
            }
        }
        return $processed;
    }

    // No manual textdomain loading required on WordPress.org

    // Run scan and collect results for display
    private function run_scan_collect($limit = 50) {
        $opts = get_option(self::OPTION_NAME);
        $token = $opts['api_token'] ?? '';
        if (!$token) return [];
        $include_roles = isset($opts['include_roles']) && is_array($opts['include_roles']) ? array_map('sanitize_text_field', $opts['include_roles']) : [];
        $exclude_emails = isset($opts['exclude_emails']) ? array_map('trim', explode(',', (string)$opts['exclude_emails'])) : [];
        $args = ['number' => max(1, intval($limit)), 'orderby' => 'registered', 'order' => 'DESC'];
        if (!empty($include_roles)) $args['role__in'] = $include_roles;
        $users = get_users($args);
        $rows = [];
        foreach ($users as $u) {
            if (in_array($u->user_email, $exclude_emails, true)) continue;
            $resp = $this->api_check_email($opts, $u->user_email);
            if (!is_wp_error($resp)) {
                $found = !empty($resp['found']);
                $count = intval($resp['count'] ?? 0);
                $meta  = $resp['_meta'] ?? [];
                $this->insert_log($u->user_email, $found ? 1 : 0, $count, intval($meta['ms'] ?? 0), intval($meta['code'] ?? 200), null);
                $rows[] = [
                    'user' => $u->display_name,
                    'email' => $u->user_email,
                    'found' => $found,
                    'count' => $count,
                    'ms' => intval($meta['ms'] ?? 0)
                ];
            }
        }
        return $rows;
    }

    // Static CRON entry point
    public static function cron_run() {
        try {
            $inst = self::instance();
            $inst->run_scan_batch(100);
            update_option('verisizintisi_last_cron', time());
        } catch (\Throwable $e) {
            error_log('[VeriSizintisi_Plugin] cron_run error: ' . $e->getMessage());
        }
    }

    public function render_dashboard_page() {
        $this->apply_locale();
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $table = self::table();
        $last = null;
        $total = 0;
        $today = 0;
        $todayFound = 0;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            // Cache keys
            $ck_last  = $table . ':last_row';
            $ck_total = $table . ':sum_total';
            $ck_today = $table . ':today_found';
            // Try cache then DB
            $last  = self::cache_get($ck_last);
            if ($last === false) { $last = $wpdb->get_row("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 1"); self::cache_set($ck_last, $last, 60); }
            $total = self::cache_get($ck_total);
            if ($total === false) { $total = (int) $wpdb->get_var("SELECT COALESCE(SUM(breach_count),0) FROM {$table}"); self::cache_set($ck_total, $total, 300); }
            // Today metric: number of distinct emails with found=1 today
            $todayFound = self::cache_get($ck_today);
            if ($todayFound === false) { $todayFound = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE DATE(created_at)=CURDATE() AND found=1"); self::cache_set($ck_today, $todayFound, 60); }
            $today = $todayFound;
        }
        // Connectivity
        $opts = get_option(self::OPTION_NAME);
        $token = $opts['api_token'] ?? '';
        $conn_badge = '<span class="vs-badge err">' . esc_html( $this->L('Bağlantı başarısız', 'Connection failed') ) . '</span>';
        if ($token && $this->connectivity_check($token)) {
            $conn_badge = '<span class="vs-badge ok">' . esc_html( $this->L('Bağlantı başarılı', 'Connection OK') ) . '</span>';
        }
        echo '<div class="wrap">';
        echo '<h1>' . esc_html( $this->L('Breach Radar • Genel Bakış', 'Breach Radar • Overview') ) . '</h1>';
        echo '<div class="vs-kpis">';
        $last_label = $last ? $last->created_at : '—';
        echo '<div class="kpi-card"><span class="kpi-ico">🕒</span><div><div class="vs-kpi">' . esc_html($last_label) . '</div><div class="vs-muted">' . esc_html( $this->L('Son Tarama', 'Last Scan') ) . '</div></div></div>';
        echo '<div class="kpi-card"><span class="kpi-ico">📊</span><div><div class="vs-kpi">' . esc_html( number_format_i18n($today) ) . '</div><div class="vs-muted">' . esc_html( $this->L('Bugün Tespit Edilen (adet)', 'Found Today (items)') ) . '</div></div></div>';
        echo '<div class="kpi-card"><span class="kpi-ico">🔌</span><div><div>' . wp_kses_post($conn_badge) . '</div><div class="vs-muted" style="margin-top:2px">' . esc_html( $this->L('Sunucu ile bağlantı', 'Server connectivity') ) . '</div></div></div>';
        // API usage (approximate by count of today requests)
        $limit = 10;
        $used = self::cache_get($table . ':used_today');
        if ($used === false) { $used = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE DATE(created_at)=CURDATE()"); self::cache_set($table . ':used_today', $used, 60); }
        $remain = max(0, $limit - $used);
        echo '<div class="kpi-card"><span class="kpi-ico">⚡</span><div><div class="vs-kpi">' . esc_html($used . ' / ' . $limit) . '</div><div class="vs-muted">' . esc_html( $this->L('Günlük API kullanımı (tahmini)', 'Daily API usage (approx)') ) . ' • ' . esc_html( sprintf($this->L('Kalan: %d', 'Left: %d'), $remain) ) . '</div></div></div>';
        echo '</div>';

        // Risk Summary
        echo '<div class="vs-card">';
        echo '<h2>' . esc_html( $this->L('Risk Özeti', 'Risk Summary') ) . '</h2>';
        $increaseCountToday = 0; $topIncreases = [];
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $todayLatest = $wpdb->get_results("SELECT l1.* FROM {$table} l1 INNER JOIN (SELECT email, MAX(created_at) mx FROM {$table} WHERE DATE(created_at)=CURDATE() GROUP BY email) t ON l1.email=t.email AND l1.created_at=t.mx ORDER BY l1.created_at DESC LIMIT 200");
            foreach ($todayLatest as $row) {
                $prev = (int) $wpdb->get_var($wpdb->prepare("SELECT breach_count FROM {$table} WHERE email=%s AND created_at < %s ORDER BY created_at DESC LIMIT 1", $row->email, $row->created_at));
                $delta = intval($row->breach_count) - $prev;
                if ($delta > 0) {
                    $increaseCountToday++;
                    $topIncreases[] = [ 'email' => $row->email, 'prev' => $prev, 'curr' => intval($row->breach_count), 'delta' => $delta ];
                }
            }
            usort($topIncreases, function($a,$b){ return $b['delta'] <=> $a['delta']; });
        }
        echo '<p class="vs-muted">' . esc_html( sprintf($this->L('Bugün artış yaşayan kullanıcılar: %d', 'Users with increases today: %d'), $increaseCountToday) ) . '</p>';
        if (!empty($topIncreases)) {
            echo '<ul style="margin:0;padding-left:18px">';
            foreach (array_slice($topIncreases, 0, 5) as $it) {
                echo '<li>' . esc_html($it['email']) . ' — ' . esc_html( sprintf($this->L('%1$d → %2$d (+%3$d)', '%1$d → %2$d (+%3$d)'), $it['prev'], $it['curr'], $it['delta']) ) . '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p class="vs-muted">' . esc_html( $this->L('Bugün artış yok.', 'No increases today.') ) . '</p>';
        }
        echo '</div>';

        // Insights
        echo '<div class="vs-card">';
        echo '<h2>' . esc_html( $this->L('Öngörüler', 'Insights') ) . '</h2>';
        $insight = ($increaseCountToday > 0)
            ? sprintf( $this->L('Bugün %d kullanıcıda sızıntı sayısı arttı. Şifre sıfırlama ve 2FA önerin.', '%d users show increased breach counts today. Recommend password resets and 2FA.'), $increaseCountToday )
            : $this->L('Artış tespit edilmedi. Yine de kritik roller için düzenli denetim önerilir.', 'No increases detected. Still recommend regular checks for critical roles.');
        $ico = ($increaseCountToday > 0) ? '⚠️' : '✅';
        $dashUrl = 'https://verisizintisi.com/dashboard';
        $privacy = $this->L('Detaylar kullanıcıya özeldir; etkilenen kullanıcı kendi e‑postasıyla giriş yaparak görebilir.', 'Details are user‑private; affected user must sign in with their email.');
        // Build Logs filtered link (today, found=1)
        $logsBase = admin_url('admin.php?page=verisizintisi_logs');
        $todayDate = gmdate('Y-m-d');
        $logsLink = add_query_arg(['vs_found' => 1, 'vs_from' => $todayDate, 'vs_to' => $todayDate], $logsBase);
        echo '<p>' . esc_html($ico . ' ' . $insight) . ' — <a href="' . esc_url($logsLink) . '">' . esc_html($this->L('Detaylar', 'Details')) . '</a></p>';
        echo '<p class="vs-muted">' . esc_html($privacy) . ' — <a href="' . esc_url($dashUrl) . '" target="_blank" rel="noopener">' . esc_html($this->L('Kullanıcı girişi', 'User sign‑in')) . '</a></p>';
        echo '</div>';

        // Badge promotion
        echo '<div class="vs-card">';
        echo '<h2>' . esc_html( $this->L('Koruma Rozeti', 'Protection Badge') ) . '</h2>';
        $site_url = self::get_site_url_safe();
        $parts = wp_parse_url($site_url);
        $host = isset($parts['host']) ? $parts['host'] : '';
        $path = isset($parts['path']) ? $parts['path'] : '';
        if (function_exists('untrailingslashit')) { $path = untrailingslashit($path); } else { $path = rtrim($path, '/'); }
        $host_path = $host . $path; // e.g., example.com/blog
        $verify_url = 'https://verisizintisi.com/verify-protection/url/' . $host_path;
        $settings_url = admin_url('admin.php?page=verisizintisi_settings');
        $promo_tr = 'Sitenizin Veri Sızıntısı Platformu tarafından korunduğunu ziyaretçilerinize bildirin ve korunma durumunuzu paylaşın: <a href="' . esc_url($verify_url) . '" target="_blank" rel="noopener">' . esc_html($verify_url) . '</a>. Rozeti eklemek için <a href="' . esc_url($settings_url) . '">Breach Radar Ayarları</a> sayfasındaki rozet alanını kullanın.';
        $promo_en = 'Let visitors know your site is protected by Veri Sızıntısı Platform and share your protection status: <a href="' . esc_url($verify_url) . '" target="_blank" rel="noopener">' . esc_html($verify_url) . '</a>. To add the badge, use the badge settings in the <a href="' . esc_url($settings_url) . '">Breach Radar Settings</a> page.';
        echo '<p>' . wp_kses_post($this->is_tr() ? $promo_tr : $promo_en) . '</p>';
        echo '</div>';

        // Last 10 scans table on dashboard
        echo '<div class="vs-grid">';
        echo '<div class="vs-card">';
        echo '<h2>' . esc_html( $this->L('Son 10 Tarama', 'Last 10 Scans') ) . '</h2>';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $cacheKey = $table . ':last10';
            $rows = self::cache_get($cacheKey);
            if ($rows === false) {
                $rows = $wpdb->get_results("SELECT email, found, breach_count, response_ms, http_status, error_code, created_at FROM {$table} ORDER BY created_at DESC LIMIT 10");
                self::cache_set($cacheKey, $rows, 60);
            }
            if ($rows) {
                echo '<table class="vs-table"><thead><tr><th>' . esc_html($this->L('E‑posta','Email')) . '</th><th>' . esc_html($this->L('Bulundu','Found')) . '</th><th>' . esc_html($this->L('Sayı','Count')) . '</th><th>ms</th><th>HTTP</th><th>' . esc_html($this->L('Hata','Error')) . '</th><th>' . esc_html($this->L('Tarih','Date')) . '</th></tr></thead><tbody>';
                foreach ($rows as $r) {
                    echo '<tr>';
                    echo '<td>' . esc_html($r->email) . '</td>';
                    echo '<td>' . ($r->found ? '<span class="vs-badge err">' . esc_html($this->L('Bulundu','Found')) . '</span>' : '<span class="vs-badge ok">' . esc_html($this->L('Yok','None')) . '</span>') . '</td>';
                    echo '<td>' . esc_html((string)$r->breach_count) . '</td>';
                    echo '<td>' . esc_html((string)$r->response_ms) . '</td>';
                    echo '<td>' . esc_html((string)$r->http_status) . '</td>';
                    echo '<td>' . esc_html($r->error_code ? $r->error_code : '') . '</td>';
                    echo '<td>' . esc_html($r->created_at) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p class="vs-muted">' . esc_html( $this->L('Kayıt yok.', 'No records.') ) . '</p>';
            }
        } else {
            echo '<p class="vs-muted">' . esc_html( $this->L('Tablo mevcut değil.', 'Table missing.') ) . '</p>';
        }
        echo '</div>';
        // Chart: last 7 days
        $labels = [];
        $counts = [];
        $prevCounts = [];
        for ($i = 6; $i >= 0; $i--) { $labels[] = date_i18n('d.m', strtotime('-'.$i.' days')); $counts[] = 0; $prevCounts[] = 0; }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $ck_7d = $table . ':sum7d';
            $rows = self::cache_get($ck_7d);
            if ($rows === false) {
                $rows = $wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) d, SUM(breach_count) c FROM {$table} WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(created_at)"));
                self::cache_set($ck_7d, $rows, 300);
            }
            $map = [];
            foreach ($rows as $r) { $map[$r->d] = (int)$r->c; }
            for ($i = 6; $i >= 0; $i--) { $d = gmdate('Y-m-d', strtotime('-'.$i.' days')); $idx = 6 - $i; $counts[$idx] = $map[$d] ?? 0; }
            // previous 7 days for comparison
            $ck_prev = $table . ':sumPrev';
            $rowsPrev = self::cache_get($ck_prev);
            if ($rowsPrev === false) {
                $rowsPrev = $wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) d, SUM(breach_count) c FROM {$table} WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) AND created_at < DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(created_at)"));
                self::cache_set($ck_prev, $rowsPrev, 300);
            }
            $mapPrev = [];
            foreach ($rowsPrev as $r) { $mapPrev[$r->d] = (int)$r->c; }
            for ($i = 13; $i >= 7; $i--) { $d = gmdate('Y-m-d', strtotime('-'.$i.' days')); $idx = 13 - $i; // 0..6
                if ($idx >=0 && $idx < 7) $prevCounts[$idx] = $mapPrev[$d] ?? 0; }
        }
        // Grafiği geçici olarak kaldırıldı
        echo '</div>'; // end vs-grid

        // Scan now
        echo '<div class="vs-card">';
        echo '<h2>' . esc_html( $this->L('Hızlı Eylemler', 'Quick Actions') ) . '</h2>';
        echo '<form method="post" action="' . esc_url( admin_url('admin-post.php') ) . '">';
        wp_nonce_field('vs_scan_now');
        echo '<input type="hidden" name="action" value="verisizintisi_scan_now" />';
        echo '<button class="button button-primary">' . esc_html( $this->L('Şimdi Tara', 'Scan Now') ) . '</button>';
        echo '</form>';
        echo '</div>';

        // Users list
        echo '<div class="vs-card">';
        echo '<h2>' . esc_html( $this->L('Kullanıcılar (İlk 10)', 'Users (Top 10)') ) . '</h2>';
        $users = get_users(['number' => 10, 'orderby' => 'registered', 'order' => 'DESC']);
        if (!empty($users)) {
            echo '<table class="vs-table"><thead><tr><th>' . esc_html( $this->L('Kullanıcı', 'User') ) . '</th><th>' . esc_html( $this->L('E‑posta', 'Email') ) . '</th><th>' . esc_html( $this->L('Kayıt Tarihi', 'Registered') ) . '</th></tr></thead><tbody>';
            foreach ($users as $u) {
                echo '<tr><td>' . esc_html($u->display_name) . '</td><td>' . esc_html($u->user_email) . '</td><td>' . esc_html($u->user_registered) . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p class="vs-muted">' . esc_html( $this->L('Kullanıcı bulunamadı.', 'No users found.') ) . '</p>';
        }
        echo '</div>';

        echo '</div>';
        $this->restore_locale();
    }

    public function render_scan_page() {
        $this->apply_locale();
        if (!current_user_can('manage_options')) return;
        $results = [];
        $notice = '';
        if (isset($_POST['vs_scan_trigger'])) {
            check_admin_referer('vs_scan_manual');
            $results = $this->run_scan_collect(50);
            $notice = $this->L('Tarama tamamlandı.', 'Scan completed.');
        }
        echo '<div class="wrap">';
        echo '<h1>' . esc_html( $this->L('Manuel Tarama', 'Manual Scan') ) . '</h1>';
        echo '<div class="vs-card">';
        echo '<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
        wp_nonce_field('vs_scan_manual');
        echo '<input type="hidden" name="vs_scan_trigger" value="1" />';
        submit_button( $this->L('Kullanıcıları Tara', 'Scan Users'), 'primary', 'submit', false );
        echo '</form>';
        if ($notice) echo '<p class="vs-muted" style="margin-top:8px">' . esc_html($notice) . '</p>';
        echo '</div>';

        if (!empty($results)) {
            echo '<div class="vs-card" style="margin-top:12px">';
            echo '<h2>' . esc_html( $this->L('Sonuçlar', 'Results') ) . '</h2>';
            echo '<table class="vs-table"><thead><tr>';
            echo '<th>' . esc_html( $this->L('Kullanıcı', 'User') ) . '</th>';
            echo '<th>' . esc_html( $this->L('E‑posta', 'Email') ) . '</th>';
            echo '<th>' . esc_html( $this->L('Bulundu', 'Found') ) . '</th>';
            echo '<th>' . esc_html( $this->L('Sayı', 'Count') ) . '</th>';
            echo '<th>ms</th>';
            echo '</tr></thead><tbody>';
            foreach ($results as $r) {
                echo '<tr>';
                echo '<td>' . esc_html($r['user']) . '</td>';
                echo '<td>' . esc_html($r['email']) . '</td>';
                echo '<td>' . ($r['found'] ? '<span class="vs-badge err">' . esc_html($this->L('Bulundu','Found')) . '</span>' : '<span class="vs-badge ok">' . esc_html($this->L('Yok','None')) . '</span>') . '</td>';
                echo '<td>' . esc_html( (string)$r['count'] ) . '</td>';
                echo '<td>' . esc_html( (string)$r['ms'] ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
        }
        echo '</div>';
        $this->restore_locale();
    }

    public function render_logs_page() {
        $this->apply_locale();
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $table = self::table();
        echo '<div class="wrap">';
        echo '<h1>' . esc_html( $this->L('Breach Radar • Kayıtlar', 'Breach Radar • Logs') ) . '</h1>';

        // Guidance card about privacy and actions
        $dashUrl = 'https://verisizintisi.com/dashboard';
        $guidance_tr = '<strong>Önemli Bilgilendirme:</strong> Bu sayfadaki kayıtlar sızıntı var/yok ve adet gibi özet bilgileri içerir. Detaylı sızıntı içeriği kullanıcı mahremiyeti gereği yalnızca etkilenen kullanıcı tarafından görüntülenebilir. Etkilenen kullanıcının <a href="' . esc_url($dashUrl) . '" target="_blank" rel="noopener">verisizintisi.com/dashboard</a> adresine kendi e‑posta adresiyle giriş yapması gerekir.<br><br><strong>Yönetici için önerilen aksiyonlar:</strong><br>- Etkilenen kullanıcıyı gecikmeden bilgilendirin.<br>- Güçlü ve benzersiz şifre belirlemesini, mümkünse <em>parola yöneticisi</em> kullanmasını isteyin.<br>- 2 Adımlı Doğrulamayı (2FA) etkinleştirmesini önerin.<br>- Aynı parolayı kullanabileceği diğer servislerde de şifre değişimi yapmasını hatırlatın.<br>- Olağan dışı oturum/IP etkinliğini kontrol edin, gerekirse açık oturumları sonlandırın.<br><br><strong>Kullanıcıya iletebileceğiniz örnek mesaj:</strong><br>"Merhaba, hesabınızla ilişkili veri sızıntısı tespit edilmiştir. Lütfen <a href="' . esc_url($dashUrl) . '" target="_blank" rel="noopener">verisizintisi.com/dashboard</a> adresine e‑postanızla giriş yaparak detayları görebilir ve önerilen adımları uygulayın (şifre değişimi, 2FA)."';
        $guidance_en = '<strong>Important:</strong> This page shows summary info (presence and counts). Detailed breach contents are user‑private and can only be viewed by the affected user after signing in at <a href="' . esc_url($dashUrl) . '" target="_blank" rel="noopener">verisizintisi.com/dashboard</a> with their email.<br><br><strong>Recommended admin actions:</strong><br>- Notify the affected user promptly.<br>- Advise setting a strong unique password (ideally via a password manager).<br>- Recommend enabling Two‑Factor Authentication (2FA).<br>- Remind to change passwords on other services using the same password.<br>- Review unusual sessions/IPs and terminate active sessions if needed.<br><br><strong>Suggested message to forward:</strong><br>"Hello, a data breach related to your account was detected. Please sign in at <a href="' . esc_url($dashUrl) . '" target="_blank" rel="noopener">verisizintisi.com/dashboard</a> with your email to view details and follow the recommended steps (password reset, 2FA)."';
        echo '<div class="vs-card vs-muted" style="margin-bottom:12px">' . wp_kses_post($this->is_tr() ? $guidance_tr : $guidance_en) . '</div>';

        // Filters
        $q_email = isset($_GET['vs_email']) ? sanitize_email($_GET['vs_email']) : '';
        $q_found = isset($_GET['vs_found']) ? intval($_GET['vs_found']) : -1; // -1 any, 0 none, 1 found
        $q_http  = isset($_GET['vs_http']) ? intval($_GET['vs_http']) : 0;
        $q_from  = isset($_GET['vs_from']) ? sanitize_text_field($_GET['vs_from']) : '';
        $q_to    = isset($_GET['vs_to']) ? sanitize_text_field($_GET['vs_to']) : '';
        echo '<form method="get" class="vs-card" style="margin-bottom:12px;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px">';
        echo '<input type="hidden" name="page" value="verisizintisi_logs" />';
        echo '<input type="email" name="vs_email" placeholder="' . esc_attr($this->L('E‑posta', 'Email')) . '" value="' . esc_attr($q_email) . '" />';
        echo '<select name="vs_found"><option value="-1"' . selected($q_found,-1,false) . '>' . esc_html($this->L('Bulundu/Yok', 'Found/None')) . '</option><option value="1"' . selected($q_found,1,false) . '>' . esc_html($this->L('Bulundu', 'Found')) . '</option><option value="0"' . selected($q_found,0,false) . '>' . esc_html($this->L('Yok', 'None')) . '</option></select>';
        echo '<input type="number" name="vs_http" placeholder="HTTP" value="' . esc_attr($q_http ? (string)$q_http : '') . '" min="0" step="1" />';
        echo '<input type="date" name="vs_from" value="' . esc_attr($q_from) . '" />';
        echo '<input type="date" name="vs_to" value="' . esc_attr($q_to) . '" />';
        submit_button($this->L('Filtrele', 'Filter'), 'secondary', '', false);
        echo '</form>';

        echo '<div class="vs-card">';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            // Build WHERE
            $where = '1=1';
            $params = [];
            if ($q_email) { $where .= ' AND email = %s'; $params[] = $q_email; }
            if ($q_found === 0 || $q_found === 1) { $where .= ' AND found = %d'; $params[] = $q_found; }
            if ($q_http > 0) { $where .= ' AND http_status = %d'; $params[] = $q_http; }
            if ($q_from) { $where .= ' AND DATE(created_at) >= %s'; $params[] = $q_from; }
            if ($q_to) { $where .= ' AND DATE(created_at) <= %s'; $params[] = $q_to; }
            $sql = "SELECT email, found, breach_count, response_ms, http_status, error_code, scanned_by, created_at FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d";
            $params_prepared = $params;
            $params_prepared[] = 100;
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params_prepared));
            if ($rows) {
                echo '<table class="vs-table"><thead><tr><th>' . esc_html($this->L('E‑posta','Email')) . '</th><th>' . esc_html($this->L('Bulundu','Found')) . '</th><th>' . esc_html($this->L('Sayı','Count')) . '</th><th>ms</th><th>HTTP</th><th>' . esc_html($this->L('Hata','Error')) . '</th><th>' . esc_html($this->L('Taranan','Scanned By')) . '</th><th>' . esc_html($this->L('Tarih','Date')) . '</th><th></th></tr></thead><tbody>';
                foreach ($rows as $r) {
                    echo '<tr>';
                    echo '<td>' . esc_html($r->email) . '</td>';
                    echo '<td>' . ($r->found ? '<span class="vs-badge err">' . esc_html($this->L('Bulundu','Found')) . '</span>' : '<span class="vs-badge ok">' . esc_html($this->L('Yok','None')) . '</span>') . '</td>';
                    echo '<td>' . esc_html((string)$r->breach_count) . '</td>';
                    echo '<td>' . esc_html((string)$r->response_ms) . '</td>';
                    echo '<td>' . esc_html((string)$r->http_status) . '</td>';
                    echo '<td>' . esc_html($r->error_code ? $r->error_code : '') . '</td>';
                    echo '<td>' . esc_html($r->scanned_by ? (string)$r->scanned_by : '-') . '</td>';
                    echo '<td>' . esc_html($r->created_at) . '</td>';
                    echo '<td>';
                    echo '<form method="post" action="' . esc_url( admin_url('admin-post.php') ) . '" style="margin:0">';
                    wp_nonce_field('vs_notify_user');
                    echo '<input type="hidden" name="action" value="verisizintisi_notify_user" />';
                    echo '<input type="hidden" name="vs_email" value="' . esc_attr($r->email) . '" />';
                    echo '<button class="button button-secondary" type="submit">' . esc_html($this->L('Kullanıcıyı Bilgilendir', 'Notify User')) . '</button>';
                    echo '</form>';
                    echo '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p class="vs-muted">' . esc_html( $this->L('Kayıt yok.', 'No records.') ) . '</p>';
            }
        } else {
            echo '<p class="vs-muted">' . esc_html( $this->L('Tablo mevcut değil.', 'Table missing.') ) . '</p>';
        }
        echo '</div>';
        echo '</div>';
        $this->restore_locale();
    }

    private function insert_log($email, $found, $count, $ms, $http_status, $error_code) {
        global $wpdb;
        $table = self::table();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return;

        // Fetch previous count for delta calculation
        $prev_count = (int) $wpdb->get_var($wpdb->prepare("SELECT breach_count FROM {$table} WHERE email=%s ORDER BY created_at DESC LIMIT 1", sanitize_email($email)));

        $wpdb->insert($table, [
            'email' => sanitize_email($email),
            'found' => $found ? 1 : 0,
            'breach_count' => max(0, intval($count)),
            'response_ms' => max(0, intval($ms)),
            'http_status' => max(0, intval($http_status)),
            'error_code' => $error_code ? sanitize_text_field($error_code) : null,
            'scanned_by' => get_current_user_id() ?: null,
            'created_at' => current_time('mysql'),
        ]);

        // Delta notification
        $opts = get_option(self::OPTION_NAME);
        $enabled = !empty($opts['notifications_enabled']);
        $threshold = max(1, intval($opts['notification_delta_threshold'] ?? 1));
        $delta = intval($count) - $prev_count;
        if ($enabled && $delta >= $threshold && intval($count) > 0) {
            $this->notify_admin_delta($email, $prev_count, intval($count), $delta);
        }
    }

    private function notify_admin_delta($email, $prev, $curr, $delta) {
        $site_name = get_bloginfo('name');
        $admin_email = get_option('admin_email');
        if (!$admin_email || !is_email($admin_email)) return;
        $isTr = $this->is_tr();
        $subject = $isTr ? (
            'Veri Sızıntısı: Artış tespit edildi (' . $email . ')'
        ) : (
            'Data Breach: Increase detected (' . $email . ')'
        );
        $dashboardUrl = 'https://verisizintisi.com/dashboard';
        $body = $isTr ? (
            "Merhaba,\n\n$site_name üzerinde $email için sızıntı sayısı $prev → $curr (+$delta) oldu.\nDetaylı içerik kullanıcıya özeldir: yalnızca sızıntıya maruz kalan kullanıcı $dashboardUrl adresine kendi e‑posta adresiyle giriş yaparak görebilir.\n\nİletmeniz için örnek mesaj:\n\"Merhaba, hesabınız için veri sızıntısı tespit edilmiştir. $dashboardUrl adresinden e‑posta adresinizle giriş yaparak detayları görebilir ve önerileri uygulayabilirsiniz.\"\n\nYönetici önerisi: Kullanıcıyı bilgilendirin, şifre sıfırlamayı ve 2FA'yı önerin.\n\n— Veri Sızıntısı WordPress Eklentisi"
        ) : (
            "Hello,\n\nOn $site_name, breaches for $email increased $prev → $curr (+$delta).\nDetails are user‑private: only the affected user can sign in at $dashboardUrl with their own email to view details.\n\nSuggested message to forward:\n\"Hello, a data breach was detected for your account. Please sign in at $dashboardUrl with your email to view details and follow the recommendations.\"\n\nAdmin tip: Notify the user, recommend password reset and 2FA.\n\n— Veri Sızıntısı WordPress Plugin"
        );
        wp_mail($admin_email, $subject, $body);
    }

    public function handle_scan_now() {
        if (!current_user_can('manage_options')) wp_die('forbidden');
        check_admin_referer('vs_scan_now');
        $count = $this->run_scan_batch(50);
        $msg = $this->L('Tarama başlatıldı. İşlenen kullanıcı: ', 'Scan started. Processed: ') . intval($count);
        wp_safe_redirect( add_query_arg(['vs_notice' => rawurlencode($msg)], admin_url('admin.php?page=verisizintisi')) );
        exit;
    }

    public function handle_notify_user() {
        if (!current_user_can('manage_options')) wp_die('forbidden');
        check_admin_referer('vs_notify_user');
        $email = isset($_POST['vs_email']) ? sanitize_email($_POST['vs_email']) : '';
        if (!$email || !is_email($email)) {
            wp_safe_redirect( add_query_arg(['vs_notice' => rawurlencode($this->L('Geçersiz e‑posta.', 'Invalid email.'))], admin_url('admin.php?page=verisizintisi_logs')) );
            exit;
        }
        $site_name = get_bloginfo('name');
        $dashUrl = 'https://verisizintisi.com/dashboard';
        $subject = $this->is_tr() ? ('[' . $site_name . '] ' . 'Hesabınız için veri sızıntısı uyarısı') : ('[' . $site_name . '] ' . 'Data breach alert for your account');
        $body = $this->is_tr()
            ? ("Merhaba,\n\nHesabınızla ilişkili olası bir veri sızıntısı tespit edilmiştir. Lütfen aşağıdaki adımları izleyin:\n\n1) " . $dashUrl . " adresine e‑posta adresinizle giriş yapın ve detayları görüntüleyin.\n2) Şifrenizi güçlü ve benzersiz bir şifreyle güncelleyin (mümkünse parola yöneticisi kullanın).\n3) 2 Adımlı Doğrulamayı (2FA) etkinleştirin.\n4) Aynı şifreyi kullanmış olabileceğiniz diğer servislerde de şifre değiştirin.\n\nTeşekkürler.\n")
            : ("Hello,\n\nA potential data breach related to your account was detected. Please take the following steps:\n\n1) Sign in at " . $dashUrl . " with your email to view details.\n2) Update your password to a strong, unique one (ideally via a password manager).\n3) Enable Two‑Factor Authentication (2FA).\n4) Change passwords on any other services where you reused the same password.\n\nThank you.\n");
        $sent = wp_mail($email, $subject, $body);
        $msg = $sent ? $this->L('Kullanıcı bilgilendirildi.', 'User has been notified.') : $this->L('E‑posta gönderilemedi.', 'Failed to send email.');
        wp_safe_redirect( add_query_arg(['vs_notice' => rawurlencode($msg)], admin_url('admin.php?page=verisizintisi_logs')) );
        exit;
    }

    public function register_wp_dashboard_widget() {
        wp_add_dashboard_widget('verisizintisi_widget', $this->L('Breach Radar Özeti', 'Breach Radar Summary'), [$this, 'render_wp_dashboard_widget']);
    }

    public function render_wp_dashboard_widget() {
        $this->apply_locale();
        global $wpdb;
        $table = self::table();
        $total = 0; $last = null;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $last = $wpdb->get_row("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 1");
            $total = (int) $wpdb->get_var("SELECT COALESCE(SUM(breach_count),0) FROM {$table}");
        }
        $labels = []; $counts = []; $prevCounts = [];
        for ($i = 6; $i >= 0; $i--) { $labels[] = date_i18n('d.m', strtotime('-'.$i.' days')); $counts[] = 0; $prevCounts[] = 0; }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $ck_7d = $table . ':sum7d';
            $rows = self::cache_get($ck_7d);
            if ($rows === false) {
                $rows = $wpdb->get_results("SELECT DATE(created_at) d, SUM(breach_count) c FROM {$table} WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(created_at)");
                self::cache_set($ck_7d, $rows, 300);
            }
            $map = [];
            foreach ($rows as $r) { $map[$r->d] = (int)$r->c; }
            for ($i = 6; $i >= 0; $i--) { $d = gmdate('Y-m-d', strtotime('-'.$i.' days')); $idx = 6 - $i; $counts[$idx] = $map[$d] ?? 0; }
            $ck_prev = $table . ':sumPrev';
            $rowsPrev = self::cache_get($ck_prev);
            if ($rowsPrev === false) {
                $rowsPrev = $wpdb->get_results("SELECT DATE(created_at) d, SUM(breach_count) c FROM {$table} WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) AND created_at < DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(created_at)");
                self::cache_set($ck_prev, $rowsPrev, 300);
            }
            $mapPrev = [];
            foreach ($rowsPrev as $r) { $mapPrev[$r->d] = (int)$r->c; }
            for ($i = 13; $i >= 7; $i--) { $d = gmdate('Y-m-d', strtotime('-'.$i.' days')); $idx = 13 - $i; if ($idx>=0 && $idx<7) $prevCounts[$idx] = $mapPrev[$d] ?? 0; }
        }
        echo '<p><strong>' . esc_html( $this->L('Son Tarama', 'Last Scan') ) . ':</strong> ' . esc_html( $last ? $last->created_at : '—' ) . '</p>';
        echo '<p><strong>' . esc_html( $this->L('Toplam Tespit', 'Total Found') ) . ':</strong> ' . esc_html( number_format_i18n($total) ) . '</p>';
        // WP Dashboard widget grafiği geçici olarak kaldırıldı
        echo '<form method="post" action="' . esc_url( admin_url('admin-post.php') ) . '" style="margin-top:8px">';
        wp_nonce_field('vs_scan_now');
        echo '<input type="hidden" name="action" value="verisizintisi_scan_now" />';
        echo '<button class="button button-secondary">' . esc_html( $this->L('Şimdi Tara', 'Scan Now') ) . '</button>';
        echo '</form>';

        // Risk Summary + Insights under the chart
        $increaseCountToday = 0; $topIncreases = [];
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $todayLatest = $wpdb->get_results("SELECT l1.* FROM {$table} l1 INNER JOIN (SELECT email, MAX(created_at) mx FROM {$table} WHERE DATE(created_at)=CURDATE() GROUP BY email) t ON l1.email=t.email AND l1.created_at=t.mx ORDER BY l1.created_at DESC LIMIT 200");
            foreach ($todayLatest as $row) {
                $prev = (int) $wpdb->get_var($wpdb->prepare("SELECT breach_count FROM {$table} WHERE email=%s AND created_at < %s ORDER BY created_at DESC LIMIT 1", $row->email, $row->created_at));
                $delta = intval($row->breach_count) - $prev;
                if ($delta > 0) {
                    $increaseCountToday++;
                    $topIncreases[] = [ 'email' => $row->email, 'prev' => $prev, 'curr' => intval($row->breach_count), 'delta' => $delta ];
                }
            }
            usort($topIncreases, function($a,$b){ return $b['delta'] <=> $a['delta']; });
        }
        $logsBase = admin_url('admin.php?page=verisizintisi_logs');
        $todayDate = gmdate('Y-m-d');
        $logsLink = add_query_arg(['vs_found' => 1, 'vs_from' => $todayDate, 'vs_to' => $todayDate], $logsBase);
        $insight = ($increaseCountToday > 0)
            ? sprintf( $this->L('Bugün %d kullanıcıda sızıntı sayısı arttı. ', '%d users show increased breach counts today. '), $increaseCountToday )
            : $this->L('Bugün artış yok. ', 'No increases today. ');
        $insight .= $this->L('Detaylar', 'Details');
        echo '<div style="margin-top:10px">';
        echo '<p>' . esc_html($insight) . ': <a href="' . esc_url($logsLink) . '">' . esc_html($this->L('Kayıtlar', 'Logs')) . '</a></p>';
        if (!empty($topIncreases)) {
            echo '<ul style="margin:0;padding-left:18px">';
            foreach (array_slice($topIncreases, 0, 3) as $it) {
                echo '<li>' . esc_html($it['email']) . ' — ' . esc_html( sprintf($this->L('%1$d → %2$d (+%3$d)', '%1$d → %2$d (+%3$d)'), $it['prev'], $it['curr'], $it['delta']) ) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
        $this->restore_locale();
    }

    // Customizer settings to place badge in footer
    public function customizer_register($wp_customize) {
        $section = 'verisizintisi_badge';
        $wp_customize->add_section($section, [
            'title' => $this->L('Breach Radar Rozeti', 'Breach Radar Badge'),
            'priority' => 160,
        ]);
        $wp_customize->add_setting('vs_badge_enabled', ['default' => false, 'transport' => 'refresh']);
        $wp_customize->add_control('vs_badge_enabled', [
            'type' => 'checkbox',
            'section' => $section,
            'label' => $this->L('Alt bilgiye rozeti ekle', 'Add badge to footer'),
        ]);
        $wp_customize->add_setting('vs_badge_theme', ['default' => 'light', 'transport' => 'refresh']);
        $wp_customize->add_control('vs_badge_theme', [
            'type' => 'select', 'section' => $section, 'label' => $this->L('Tema', 'Theme'),
            'choices' => ['light' => 'light', 'dark' => 'dark']
        ]);
        $wp_customize->add_setting('vs_badge_size', ['default' => 'medium', 'transport' => 'refresh']);
        $wp_customize->add_control('vs_badge_size', [
            'type' => 'select', 'section' => $section, 'label' => $this->L('Boyut', 'Size'),
            'choices' => ['small' => 'small', 'medium' => 'medium', 'large' => 'large']
        ]);
        $wp_customize->add_setting('vs_badge_align', ['default' => 'left', 'transport' => 'refresh']);
        $wp_customize->add_control('vs_badge_align', [
            'type' => 'select', 'section' => $section, 'label' => $this->L('Hizalama', 'Align'),
            'choices' => ['left' => 'left', 'center' => 'center', 'right' => 'right']
        ]);
        // Nofollow toggle
        $wp_customize->add_setting('vs_badge_nofollow', ['default' => false, 'transport' => 'refresh']);
        $wp_customize->add_control('vs_badge_nofollow', [
            'type' => 'checkbox', 'section' => $section, 'label' => $this->L('Bağlantıya nofollow ekle', 'Add nofollow to link'),
        ]);
        // Aria label text (accessibility)
        $wp_customize->add_setting('vs_badge_aria_label', ['default' => '', 'transport' => 'refresh']);
        $wp_customize->add_control('vs_badge_aria_label', [
            'type' => 'text', 'section' => $section, 'label' => $this->L('Aria etiketi (isteğe bağlı)', 'Aria label (optional)'),
            'description' => $this->L('Erişilebilirlik için bağlantı açıklaması.', 'Accessibility description for the link.'),
        ]);
    }

    public function render_badge_in_footer() {
        if (!get_theme_mod('vs_badge_enabled', false)) return;
        $nofollow = get_theme_mod('vs_badge_nofollow', false) ? '1' : '0';
        $aria = (string) get_theme_mod('vs_badge_aria_label', '');
        $shortcode = sprintf('[verisizintisi_badge size="%s" theme="%s" align="%s" lang="auto" nofollow="%s" aria_label="%s"]',
            esc_attr( get_theme_mod('vs_badge_size', 'medium') ),
            esc_attr( get_theme_mod('vs_badge_theme', 'light') ),
            esc_attr( get_theme_mod('vs_badge_align', 'left') ),
            esc_attr( $nofollow ),
            esc_attr( $aria )
        );
        echo do_shortcode($shortcode); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    public function register_shortcodes() {
        add_shortcode('verisizintisi_badge', function($atts){
            $atts = shortcode_atts([
                'size' => 'medium',      // small|medium|large
                'theme' => 'light',      // light|dark
                'align' => 'left',       // left|center|right
                'lang' => 'auto',        // auto|tr|en
                'nofollow' => '0',       // 0|1
                'aria_label' => '',      // optional text
            ], $atts, 'verisizintisi_badge');

            $size = in_array($atts['size'], ['small','medium','large'], true) ? $atts['size'] : 'medium';
            $theme = in_array($atts['theme'], ['light','dark'], true) ? $atts['theme'] : 'light';
            $align = in_array($atts['align'], ['left','center','right'], true) ? $atts['align'] : 'left';

            // Dil tespiti
            $lang = strtolower($atts['lang']);
            if (!in_array($lang, ['tr','en'], true)) {
                $site_locale = function_exists('get_locale') ? get_locale() : 'en_US';
                $lang = (stripos($site_locale, 'tr') === 0) ? 'tr' : 'en';
            }

            $text = ($lang === 'tr')
                ? 'Bu site, Veri Sızıntısı Platformu tarafından veri sızıntılarına karşı korunuyor'
                : 'This site is protected against data breaches by Veri Sızıntısı Platform';

            // Doğrulama bağlantısı: scheme'i at, host + path formatında ilet
            $site_url = self::get_site_url_safe();
            $parts = wp_parse_url($site_url);
            $host = isset($parts['host']) ? $parts['host'] : '';
            $path = isset($parts['path']) ? $parts['path'] : '';
            // Son / karakterini kaldır
            if (function_exists('untrailingslashit')) { $path = untrailingslashit($path); }
            else { $path = rtrim($path, '/'); }
            $host_path = $host . $path; // ör: verisizintisi.com/blog
            // Backlink to main site (do-follow) which redirects to verification subdomain
            $verify_base = 'https://verisizintisi.com/verify-protection/url/';
            $href = $verify_base . $host_path;

            // rel and aria-label handling
            $nofollow = !empty($atts['nofollow']) && in_array(strtolower((string)$atts['nofollow']), ['1','true','yes'], true);
            $rel = 'noopener' . ($nofollow ? ' nofollow' : '');
            $aria_label = trim((string)$atts['aria_label']);
            if ($aria_label === '') {
                $aria_label = ($lang === 'tr') ? 'Koruma durumunu doğrula' : 'Verify protection status';
            }

            // Stil
            $fs = $size === 'small' ? '12px' : ($size === 'large' ? '16px' : '14px');
            $pad = $size === 'small' ? '6px 10px' : ($size === 'large' ? '10px 16px' : '8px 14px');
            $gap = $size === 'small' ? '6px' : '8px';
            // Green, trustful palette (light/dark variants)
            $bg = $theme === 'dark' ? '#064e3b' : '#dcfce7';
            $fg = $theme === 'dark' ? '#d1fae5' : '#166534';
            $bd = $theme === 'dark' ? '#065f46' : '#bbf7d0';

            // Inline SVG: green check icon
            $img = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle"><path d="M20 6 9 17l-5-5"/></svg>';
            $style = 'display:inline-flex;align-items:center;gap:' . esc_attr($gap) . ';padding:' . esc_attr($pad) . ';border-radius:999px;font-size:' . esc_attr($fs) . ';background:' . esc_attr($bg) . ';color:' . esc_attr($fg) . ';border:1px solid ' . esc_attr($bd) . ';text-decoration:none;';
            $wrap = 'text-align:' . esc_attr($align) . ';';

            // Main badge link (verification)
            $badgeLink = '<a href="' . esc_url($href) . '" class="vs-badge-link" style="' . $style . '" target="_blank" rel="' . esc_attr($rel) . '" aria-label="' . esc_attr($aria_label) . '">' . $img . '<span style="margin-left:6px">' . esc_html($text) . '</span></a>';

            $html = '<div class="vs-badge-wrap" style="' . $wrap . '">' . $badgeLink . '</div>';
            return $html;
        });
    }
    private function connectivity_check($token) {
        $base = rtrim(self::API_BASE, '/');
        // Try /health
        $resp = wp_remote_head($base . '/health', ['timeout' => 5, 'headers' => ['Authorization' => 'Bearer ' . $token]]);
        if (!is_wp_error($resp)) {
            $code = wp_remote_retrieve_response_code($resp);
            if ($code >= 200 && $code < 400) return true;
        }
        // Fallback HEAD base
        $resp2 = wp_remote_head($base, ['timeout' => 5]);
        if (!is_wp_error($resp2)) {
            $code = wp_remote_retrieve_response_code($resp2);
            if ($code >= 200 && $code < 400) return true;
        }
        return false;
    }

    public function render_badge_page() {
        $this->apply_locale();
        if (!current_user_can('manage_options')) return;
        // Build verification URL for current site
        $site_url = self::get_site_url_safe();
        $parts = wp_parse_url($site_url);
        $host = isset($parts['host']) ? $parts['host'] : '';
        $path = isset($parts['path']) ? $parts['path'] : '';
        if (function_exists('untrailingslashit')) { $path = untrailingslashit($path); } else { $path = rtrim($path, '/'); }
        $host_path = $host . $path;
        $verify_url = 'https://verisizintisi.com/verify-protection/url/' . $host_path;

        echo '<div class="wrap">';
        echo '<h1>' . esc_html( $this->L('Breach Radar • Rozet', 'Breach Radar • Badge') ) . '</h1>';
        echo '<div class="vs-card">';
        echo '<h2>' . esc_html( $this->L('Rozet Nedir?', 'What is the Badge?') ) . '</h2>';
        echo '<p class="vs-muted">' . esc_html( $this->L('Ziyaretçilere sitenizin Veri Sızıntısı Platformu tarafından korunduğunu bildirir ve doğrulama sayfasına bağlantı verir.', 'Informs visitors that your site is protected by Veri Sızıntısı Platform and links to a verification page.') ) . '</p>';
        echo '<div style="margin-top:8px">' . do_shortcode('[verisizintisi_badge]') . '</div>';
        echo '</div>';

        echo '<div class="vs-card" style="margin-top:12px">';
        echo '<h2>' . esc_html( $this->L('Hızlı Kullanım (Shortcode)', 'Quick Use (Shortcode)') ) . '</h2>';
        echo '<p><code>[verisizintisi_badge size="medium" theme="light" align="left" nofollow="0" aria_label=""]</code></p>';
        echo '<ul class="vs-muted" style="padding-left:18px">';
        echo '<li>' . esc_html( $this->L('size: small | medium | large', 'size: small | medium | large') ) . '</li>';
        echo '<li>' . esc_html( $this->L('theme: light | dark', 'theme: light | dark') ) . '</li>';
        echo '<li>' . esc_html( $this->L('align: left | center | right', 'align: left | center | right') ) . '</li>';
        echo '<li>' . esc_html( $this->L('lang: auto | tr | en', 'lang: auto | tr | en') ) . '</li>';
        echo '<li>' . esc_html( $this->L('nofollow: 0 | 1 (SEO tercihi)', 'nofollow: 0 | 1 (SEO preference)') ) . '</li>';
        echo '<li>' . esc_html( $this->L('aria_label: erişilebilirlik için açıklama', 'aria_label: description for accessibility') ) . '</li>';
        echo '</ul>';
        // Preview grid
        echo '<div style="margin-top:10px">';
        echo '<h3>' . esc_html( $this->L('Önizleme ve Hızlı Kopyala', 'Preview & Quick Copy') ) . '</h3>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px">';
        $themes = ['light','dark'];
        $sizes  = ['small','medium','large'];
        foreach ($themes as $th) {
            foreach ($sizes as $sz) {
                $sc = '[verisizintisi_badge size="' . $sz . '" theme="' . $th . '" align="left" lang="auto"]';
                echo '<div class="vs-card" style="padding:12px">';
                echo '<div class="vs-muted" style="margin-bottom:6px">' . esc_html( ucfirst($th) . ' • ' . ucfirst($sz) ) . '</div>';
                echo do_shortcode($sc);
                echo '<div style="margin-top:8px;display:flex;gap:6px">';
                echo '<input type="text" readonly value="' . esc_attr($sc) . '" onclick="this.select()" style="flex:1;padding:6px 8px;border:1px solid #e5e7eb;border-radius:6px" />';
                echo '<button type="button" class="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)">' . esc_html( $this->L('Kopyala', 'Copy') ) . '</button>';
                echo '</div>';
                echo '</div>';
            }
        }
        echo '</div>';
        echo '</div>';

        echo '<div class="vs-card" style="margin-top:12px">';
        echo '<h2>' . esc_html( $this->L('Blok/Düzenleyici ve Şablonda Kullanım', 'Use in Block Editor and Templates') ) . '</h2>';
        echo '<p class="vs-muted">' . esc_html( $this->L('Gutenberg\'te "Kısa Kod" bloğuna shortcode\'u yapıştırın. Tema dosyalarında:', 'In Gutenberg, paste the shortcode into the "Shortcode" block. In theme files:') ) . '</p>';
        echo '<pre><code>&lt;?php echo do_shortcode(&#39;[verisizintisi_badge size="small" theme="dark" align="center"]&#39;); ?&gt;</code></pre>';
        echo '</div>';

        echo '<div class="vs-card" style="margin-top:12px">';
        echo '<h2>' . esc_html( $this->L('Tema Özelleştirici', 'Theme Customizer') ) . '</h2>';
        echo '<p class="vs-muted">' . esc_html( $this->L('Görünüm → Özelleştir → Breach Radar Rozeti menüsünden alt bilgiye otomatik ekleyebilirsiniz.', 'Appearance → Customize → Breach Radar Badge to add badge to footer automatically.') ) . '</p>';
        echo '</div>';

        echo '<div class="vs-card" style="margin-top:12px">';
        echo '<h2>' . esc_html( $this->L('Rozet Tıklandığında Ne Olur?', 'What Happens on Click?') ) . '</h2>';
        echo '<p class="vs-muted">' . sprintf( esc_html( $this->L('Ziyaretçi doğrulama sayfasına yönlendirilir: %s. Bu sayfada koruma durumu (korunuyor/korunmuyor), son tarama bilgisi, 7 gün uyarısı ve TR/EN dil desteği gösterilir.', 'Visitors go to the verification page: %s. It shows protection status (protected/not), last scan info, 7‑day warning, and TR/EN language support.') ), '<a href="' . esc_url($verify_url) . '" target="_blank" rel="noopener">' . esc_html($verify_url) . '</a>' ) . '</p>';
        echo '</div>';

        echo '<div class="vs-card" style="margin-top:12px">';
        echo '<h2>' . esc_html( $this->L('Öneriler', 'Best Practices') ) . '</h2>';
        echo '<ul class="vs-muted" style="padding-left:18px">';
        echo '<li>' . esc_html( $this->L('Rozeti görünür bir alana yerleştirin (alt bilgi, kenar çubuğu).', 'Place the badge in a visible area (footer, sidebar).') ) . '</li>';
        echo '<li>' . esc_html( $this->L('Tema renkleriyle uyum için theme ve size parametrelerini ayarlayın.', 'Tune theme and size parameters to match your site.') ) . '</li>';
        echo '<li>' . esc_html( $this->L('Çok dilli sitelerde lang=auto kullanın.', 'Use lang=auto for multilingual sites.') ) . '</li>';
        echo '</ul>';
        echo '</div>';
        echo '</div>';
        $this->restore_locale();
    }

    public function plugin_action_links($links) {
        $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=verisizintisi_settings')) . '">' . esc_html($this->L('Ayarlar', 'Settings')) . '</a>';
        $badge_link = '<a href="' . esc_url(admin_url('admin.php?page=verisizintisi_badge')) . '">' . esc_html($this->L('Rozet', 'Badge')) . '</a>';
        array_unshift($links, $settings_link, $badge_link);
        return $links;
    }
}

VeriSizintisi_Plugin::instance();
// Register activation/deactivation hooks
register_activation_hook(__FILE__, ['VeriSizintisi_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['VeriSizintisi_Plugin', 'deactivate']);


