# Breach Radar (WordPress Eklentisi)

Türkçe | [English](README.md)

WordPress kullanıcılarınızı verisizintisi.com API’si ile bilinen veri sızıntılarına karşı kontrol edin. Breach Radar, yönetim panelinizde özet risk bilgisi sunar ve artışlarda aksiyon almanıza yardımcı olur.

- WordPress.org eklenti adı: Breach Radar via verisizintisi.com
- Metin Alanı (Text Domain): `breach-radar`
- Minimum WP: 5.6 • Tested up to: 6.8 • Requires PHP: 7.2+
- Lisans: GPL-2.0-or-later

## Özellikler
- Risk özeti ve öngörülerle yönetim paneli
- Manuel taramalar ve planlı günlük taramalar (kaçırılırsa self-healing)
- Filtreli günlükler (e‑posta, bulundu/yok, HTTP durumu, tarih aralığı)
- Sızıntı sayısı arttığında yöneticiye e‑posta bildirimi (eşik ayarlanabilir)
- Koruma rozeti kısa kodu + Tema Özelleştirici entegrasyonu
- i18n: Türkçe ve İngilizce dâhil; Azerbaijanca ve Rusça PO dosyalarıyla destek
- Güvenlik öncelikli: yetki kontrolleri, nonce’lar, temizleme/doğrulama, güvenli çıktı

## Nasıl çalışır?
1. `get.verisizintisi.com/wordpress` adresinden API anahtarını alın ve Ayarlar’a yapıştırın.
2. Manuel tarama başlatın veya günlük cron görevini etkinleştirin.
3. Eklenti, site alan adınızı ve seçtiğiniz e‑posta adreslerini güvenli şekilde API’ye gönderir.
4. API, isteği kimlik doğrular, oran sınırı uygular ve e‑posta bazında var/yok ve sayaç döner. İçerik dönmez.
5. Eklenti, özet günlükleri yerelde saklar ve panelde öngörüler gösterir.

## Güvenlik ve gizlilik
- Sitenize takip scriptleri eklenmez.
- Ziyaretçiler izlenmez. Taramalar yalnızca sizin başlattığınızda veya planlı görevle çalışır.
- Girdiler temizlenir/doğrulanır; çıktılar kaçırılır (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
- HTTP host bilgisi ham `$_SERVER` yerine güvenli yardımcı ile elde edilir.
- İnceleyin: `https://verisizintisi.com/privacy` • `https://verisizintisi.com/terms`

## Kurulum
### WordPress yönetiminden
1. Eklentiler → Yeni Ekle → Eklenti Yükle
2. ZIP dosyasını seç → Şimdi Kur → Etkinleştir
3. Breach Radar → Ayarlar’da API anahtarını yapıştırın

### Manuel (bu depodan)
- `wordpress/` klasörünün içeriğini WordPress sitenizde `wp-content/plugins/breach-radar/` dizinine kopyalayın (klasör adı `breach-radar` olabilir).
- Veya `wordpress/` klasörünü zipleyip “Eklenti Yükle” akışıyla yükleyin.

## Yapılandırma
- API anahtarı: `get.verisizintisi.com/wordpress`.
- WordPress: Breach Radar → Ayarlar → API anahtarını yapıştırın.
- İsteğe bağlı: tarama filtreleri (roller, hariç e‑postalar), günlük tarama, artış eşiği, dil seçimi.

## Kullanım
### Panel
- Son Tarama, Bugün Bulunan, bağlantı durumu, öngörüler ve 7 günlük özet.

### Manuel tarama
- Breach Radar → Tarama → “Kullanıcıları Tara” (nonce korumalı).

### Günlük tarama
- Varsayılan olarak etkindir; planlanan görev kaçarsa eklenti kendini onarır.
- Ayarlar’dan açıp kapatabilirsiniz.

### Günlükler
- Breach Radar → Kayıtlar: e‑posta, bulundu/yok, HTTP, tarih ile filtreleme. Yer yer kısa ömürlü cache ve hazırlıklı (prepared) sorgular kullanılır.

### Koruma rozeti
- Kısa kod:

```shortcode
[verisizintisi_badge size="medium" theme="light" align="left" lang="auto"]
```

- Tema özelleştirici: Görünüm → Özelleştir → Breach Radar Rozeti
- PHP şablonlarında:

```php
<?php echo do_shortcode('[verisizintisi_badge size="small" theme="dark" align="center"]'); ?>
```

## Uluslararasılaştırma (i18n)
- Text Domain: `breach-radar` (WordPress.org çevirileri otomatik yüklenir)
- Paketli: Türkçe ve İngilizce. `az_AZ` ve `ru_RU` için PO dosyaları `wordpress/languages/` altında.
- Eklenti arayüz dilini Ayarlar → Dil bölümünden zorlayabilirsiniz. Varsayılan Otomatik (site dilini izler).

## Ekran Görüntüleri (öneri)
- Panel özeti ve öngörüler
- Filtreli günlükler
- Rozet örnekleri

## Değişiklikler
Yetkili değişiklik geçmişi için `wordpress/readme.txt` dosyasına bakın (WordPress.org ile aynıdır). Son sürümden notlar:
- 1.0.1: Uyum/güvenlik iyileştirmeleri (kaçış, sanitizasyon, hazırlıklı sorgular, `wp_rand`, `gmdate`, `wp_parse_url`), self-healing günlük tarama, i18n düzeltmeleri.

## Geliştirme
- Ana eklenti dosyası: `wordpress/verisizintisi-plugin.php`
- Diller: `wordpress/languages/`
- Derleme adımı yok; eklenti düz PHP + WordPress API’leriyle çalışır.

### Kod standartları
- WordPress Kod Standartları. Durum değiştiren işlemlerde nonce, yönetim sayfalarında `current_user_can('manage_options')`.
- Erken temizle, her zaman doğrula, çıktıyı kaçır.

## Katkıda Bulunma
Issue ve PR’lar memnuniyetle karşılanır. Lütfen:
- Değişiklikleri küçük ve odaklı tutun
- WordPress kod standartlarına uyun
- Öncesi/sonrası bağlamı ve test notlarını ekleyin

## Lisans
GPL-2.0-or-later. LICENSE dosyasına veya `verisizintisi-plugin.php` içindeki lisans başlığına bakın.

## Bağlantılar
- Web sitesi: `https://verisizintisi.com`
- API anahtarları: `https://get.verisizintisi.com/wordpress`
- Doğrulama sayfası formatı (rozet): `https://verisizintisi.com/verify-protection/url/{host[/path]}`
