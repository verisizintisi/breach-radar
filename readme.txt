=== Breach Radar via verisizintisi.com ===
Contributors: verisizintisi
Donate link: https://verisizintisi.com
Tags: security, data breach, privacy, breach, users
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Breach Radar checks your WordPress users’ emails against known data breaches via verisizintisi.com API and helps you act on risks.

== Description ==

Breach Radar helps WordPress site owners monitor whether their users’ email addresses appear in known data breaches.

Features:
- Dashboard overview with risk summary and insights
- Manual and scheduled scans (daily)
- Logs with filters (email, found, HTTP, date range)
- Admin notifications on breach count increases (configurable threshold)
- Protection badge shortcode and Theme Customizer integration
- Translatable; English and Turkish strings included

= How it works =
- You obtain an API key from get.verisizintisi.com/wordpress and paste it in Settings.
- When you run scans (manually or via daily cron), the plugin sends your site domain and the list of email addresses you chose to scan to the verisizintisi.com API over HTTPS.
- The API authenticates your request using the token, applies per‑day rate limits, queries a breach dataset, and returns counts per email (found/not found and total matches).
- The plugin shows aggregate results in your WordPress dashboard and stores scan logs locally so you can review recent activity. Detailed breach contents remain user‑private on verisizintisi.com.

= Data sent to the service =
- Site domain (host) to validate token usage.
- The email addresses you submit to be checked (transmitted for lookup, not persisted by the API).
- Usage metadata (e.g., request time, status, number of emails) for rate‑limiting and abuse prevention. The API does not store the submitted email list in usage logs.

= Privacy and Terms =
- No tracking scripts are added to your WordPress frontend or admin by this plugin.
- The service operates solely to perform breach lookups that you initiate. It does not track visitors.
- Please review our Privacy Policy and Terms of Service before use: https://verisizintisi.com/privacy and https://verisizintisi.com/terms

= Consent =
Depending on your local laws and policies, you may need to inform users and/or obtain consent before checking their email addresses against breach datasets. This plugin provides the tools, but responsibility for lawful use remains with the site owner.

== Installation ==

1. Install and activate the plugin
2. Get your API key at get.verisizintisi.com/wordpress
3. Go to Breach Radar → Settings and paste your API key
4. (Optional) Configure scan filters and notifications
5. Use Breach Radar → Scan or wait for daily scans

== Frequently Asked Questions ==

= Does this show breach contents inside WordPress? =
No. Detailed breach contents are user‑private on verisizintisi.com. Admins see found/none and counts in WordPress.

= How often can I call the API? =
Default daily limit is 10 requests per day per token (subject to change by your plan). See the dashboard usage card.

= How do I add the protection badge? =
Use the shortcode:
[verisizintisi_badge size="medium" theme="light" align="left" lang="auto"]
Or use Appearance → Customize → Breach Radar Badge.

== Screenshots ==
1. Dashboard overview and insights
2. Logs with filters
3. Badge examples

== Changelog ==
= 1.0.1 =
- Plugin Check uyumluluğu ve güvenlik iyileştirmeleri
- $_SERVER['HTTP_HOST'] doğrudan kullanımını kaldırıp güvenli get_site_url_safe() ile değiştirme
- İşaretlenen tüm çıktılara uygun kaçış uygulama (esc_html/esc_attr/esc_url/wp_kses_post)
- GET/POST verilerinde sanitizasyon/doğrulama teyidi
- Günlük tarama için self‑healing cron planlaması ve son çalıştırma zamanının izlenmesi
- rand() → wp_rand(); parse_url() → wp_parse_url(); date() → gmdate() düzeltmeleri
- Kayıtlar (Logs) sorgusunda her durumda $wpdb->prepare() kullanımı ve LIMIT %d
- i18n uyumu (Text Domain breach-radar), POT/PO düzenlemeleri
- Grafik kodlarının ve gereksiz varlıkların kaldırılması

= 1.0.0 =
- İlk kararlı sürüm: risk özeti, öngörüler, günlük tarama, kayıt filtreleri, bildirimler, rozet sayfası

== Upgrade Notice ==
= 1.0.0 =
Kararlı ilk sürüm.
