# Zoom Meeting Management System

**Türkçe** | **English**

---

## Türkçe

Zoom Meeting Management System, kurumsal ortamlar için geliştirilmiş kapsamlı bir toplantı yönetim platformudur. Sistem, Zoom Server-to-Server OAuth entegrasyonu üzerinden toplantı süreçlerini otomatikleştirir; kullanıcı talep, yönetici onay, çoklu Zoom hesabı yönetimi, raporlama ve modüler eklentilerle genişletilebilirlik sağlar.

![Zoom Meeting Management System](githubimg/zoomsystem.png)

## Sistem Özellikleri

### Güvenlik
- Oturum tabanlı kimlik doğrulama (PHP session, hijacking koruması, oturum imzası)
- CSRF token koruması (her form ve POST API'de)
- Rol tabanlı erişim kontrolü (admin / user)
- bcrypt ile parola karması; rate limiting (5 deneme / 5 dakika)
- Hassas dosyalara `.htaccess` ile erişim engeli (config/, logs/, includes/, data/)

### Toplantı Yönetimi
- Zoom REST API v2 ile tam entegrasyon (Server-to-Server OAuth)
- Yönetici onay mekanizması (tekil veya toplu)
- Otomatik çakışma tespiti (kullanıcı, birim, Zoom hesabı bazında)
- Birim bazlı haftalık toplantı kotaları
- Sistem kapatma dönemleri (belirli tarihlerde talep alınmaz)
- Tekrarlı toplantı desteği (occurrence bazlı)

### Yönetim Paneli
- Gerçek zamanlı sistem istatistikleri
- Kullanıcı, birim ve davet sistemi yönetimi
- Çoklu Zoom hesabı (otomatik en uygun hesap seçimi)
- Detaylı raporlama (CSV export)
- Zoom Cloud kayıtlarına erişim

### Veritabanı Yedekleme / Geri Yükleme
- JSON + ZIP formatında **dialect-bağımsız** yedek (MySQL ↔ SQLite arası taşınabilir)
- Admin panelinden tek tıkla yedek alma / yükleme
- Kurulum sihirbazında "yedekten geri yükle" modu

### Modül Sistemi (Eklentiler)
- WordPress benzeri **hook/event mekanizması** (action / filter)
- Modüller `modules/<id>/` klasörü olarak yerleştirilir, admin panelden **aktif/pasif** toggle ile yönetilir
- Modüller kendi tablolarını, sayfalarını, API endpoint'lerini ve sidebar menü öğelerini ekleyebilir
- Çekirdek hook noktaları: `meeting.after_create`, `meeting.after_approve`, `sidebar.admin_menu`, `sidebar.user_menu` vb.

#### Dahili Modül: SMS Bildirimleri
- Yeni toplantı talebi geldiğinde yönetici telefon numarasına SMS gönderir
- SMS içindeki tek kullanımlık link, yöneticiyi **oturum açmadan** onay sayfasına götürür
- Onay sayfasında o saat için **çakışmasız aktif Zoom hesapları** listelenir; yönetici hesap seçip onaylar
- Generic form-POST SMS sağlayıcılarıyla uyumludur (endpoint ve API anahtarı admin panelden girilir; depo içinde hassas bilgi yoktur)
- Token tek kullanımlık + zaman aşımı + meeting durumu kontrolü
- Tüm SMS gönderimleri loglanır

### Kullanıcı Arayüzü
- Responsive tasarım (TailwindCSS)
- Açık/koyu tema desteği (`localStorage` + CSS değişkenleri)
- Font Awesome 6 ikonografi

## Teknik Gereksinimler

### Minimum
- PHP 8.0+
- MySQL 5.7+ veya SQLite 3
- Apache/Nginx (mod_rewrite ile)
- Zoom Server-to-Server OAuth uygulaması

### Önerilen
- PHP 8.2+
- MySQL 8.0+
- HTTPS sertifikası (SMS link akışı için zorunlu)
- `ZipArchive` PHP eklentisi (yedekleme ve modül sistemi için)
- `curl` PHP eklentisi (Zoom API ve SMS için)

## Kurulum

```bash
git clone https://github.com/fyildirim-debug/Zoom-Meeting-Management.git
cd Zoom-Meeting-Management
# Dosyaları web sunucusu kök dizinine yükleyin
```

Tarayıcıdan `http://yourdomain.com/install/` adresini açın. Sihirbaz iki mod sunar:

1. **Yeni temiz kurulum**: Veritabanı bilgileri → admin kullanıcı → sistem ayarları
2. **Yedekten geri yükle**: Veritabanı bilgileri → ZIP dosyası yükle → otomatik şema + veri import

Kurulum tamamlandığında `install/` klasörü otomatik kilitlenir.

### İzin Ayarları (Linux production)

```bash
chown -R <user>:<group> .
chmod -R 755 .
chmod -R 775 data data/backups logs modules
```

Apache farklı bir grupta ise ACL ile yazma izni:
```bash
setfacl -R -m u:apache:rwx data logs modules
setfacl -R -d -m u:apache:rwx data logs modules
```

## Zoom API Yapılandırması

1. [Zoom Marketplace](https://marketplace.zoom.us/) üzerinden hesap oluşturun
2. **Server-to-Server OAuth** uygulaması geliştirin
3. Gerekli scope'ları tanımlayın: `meeting:write:admin`, `user:read:admin`, `account:read:admin`
4. Admin panel → **Zoom Hesapları** → Client ID, Client Secret, Account ID girin
5. **Bağlantı testi** ile doğrulayın

## Modül Geliştirme

Bir modül en az şu yapıda olmalıdır:

```
modules/<modul-id>/
├── module.json         # id, name, version, description, settings_url, hooks
├── install.php         # tablolar, varsayılan ayarlar
├── uninstall.php       # temizlik (opsiyonel keepData)
├── boot.php            # her request başında: hook'ları register et
├── pages/              # admin sayfaları
├── api/                # JSON endpoint'ler (opsiyonel)
└── lib/                # PHP sınıfları (opsiyonel)
```

`module.json` örneği:
```json
{
    "id": "my-module",
    "name": "Modülüm",
    "version": "1.0.0",
    "description": "...",
    "author": "...",
    "settings_url": "pages/settings.php",
    "hooks": ["meeting.after_create"]
}
```

`boot.php` örneği:
```php
HookEngine::addAction('meeting.after_create', function ($meetingId, array $meeting) {
    // Toplantı talebi geldiğinde tetiklenir
});

HookEngine::addFilter('sidebar.admin_menu', function (array $items) {
    $items[] = ['title' => 'Ayarlar', 'icon' => 'fas fa-cog', 'url' => url('modules/my-module/pages/settings.php')];
    return $items;
});
```

`install.php` örneği:
```php
return [
    'install' => function (ModuleAPI $api) {
        $api->createTable('module_my_data', [
            'mysql'  => 'CREATE TABLE ...',
            'sqlite' => 'CREATE TABLE ...',
        ]);
    },
];
```

Modülü `modules/<id>/` olarak sunucuya yerleştirin → **Admin → Modüller** → **Aktifleştir**.

## Proje Yapısı

```
├── admin/           # Yönetim paneli sayfaları
├── api/             # JSON API endpoint'leri
├── config/          # Yapılandırma (her kuruluma özel, gitignore'da)
├── data/            # Yedekler, runtime state (gitignore'da)
├── includes/        # Çekirdek PHP bileşenleri (ZoomAPI, MeetingService, BackupManager, ...)
├── install/         # Kurulum sihirbazı
├── logs/            # Uygulama log'ları (gitignore'da)
├── modules/         # Modül sistemi (_core/ çekirdek + her modülü kendi klasörü)
└── *.php            # Ana kullanıcı sayfaları
```

## Katkıda Bulunma

1. Projeyi fork edin
2. Özellik dalı oluşturun (`git checkout -b feature/yeni-ozellik`)
3. Değişikliklerinizi commit edin
4. Dalınızı push edin (`git push origin feature/yeni-ozellik`)
5. Pull Request açın

---

## English

Zoom Meeting Management System is a comprehensive meeting management platform for corporate environments. It automates meeting workflows through Zoom Server-to-Server OAuth integration with user request, admin approval, multi-account management, reporting, and a modular plugin system.

## Features

### Security
- Session-based authentication with hijacking protection
- CSRF protection on all forms and POST endpoints
- Role-based access (admin / user)
- bcrypt password hashing; login rate limiting
- `.htaccess` access blocks on sensitive directories

### Meeting Management
- Full Zoom REST API v2 integration (Server-to-Server OAuth)
- Single or bulk approval workflow
- Conflict detection (user, department, Zoom account)
- Department-based weekly meeting quotas
- System closure periods
- Recurring meeting support

### Admin Panel
- Real-time statistics
- User, department, and invitation management
- Multi-Zoom-account with best-account auto-selection
- Detailed reporting with CSV export
- Zoom Cloud recording access

### Database Backup / Restore
- Dialect-agnostic JSON + ZIP backup (portable between MySQL ↔ SQLite)
- One-click backup/restore from admin panel
- "Restore from backup" mode in install wizard

### Module System (Plugins)
- WordPress-like hook/event mechanism (actions/filters)
- Modules placed as `modules/<id>/`, managed via admin UI activate/deactivate toggle
- Modules can add tables, pages, APIs, sidebar menus
- Core hook points: `meeting.after_create`, `meeting.after_approve`, `sidebar.admin_menu`, etc.

#### Built-in Module: SMS Notifications
- Sends SMS to admin's phone when a meeting request arrives
- One-time link in SMS opens an approval page **without login**
- Approval page lists conflict-free active Zoom accounts; admin picks one and approves
- Works with any generic form-POST SMS provider (endpoint & API key entered via admin panel — no secrets in repo)
- Tokens are single-use with expiry and meeting-state validation
- All SMS deliveries are logged

### UI
- Responsive design (TailwindCSS)
- Light/dark theme support
- Font Awesome 6 icons

## Requirements

- PHP 8.0+ (8.2+ recommended)
- MySQL 5.7+ or SQLite 3
- Apache/Nginx with mod_rewrite
- `ZipArchive` and `curl` PHP extensions
- Zoom Server-to-Server OAuth app
- HTTPS (required for SMS link flow in production)

## Installation

```bash
git clone https://github.com/fyildirim-debug/Zoom-Meeting-Management.git
cd Zoom-Meeting-Management
```

Upload to your web root, navigate to `http://yourdomain.com/install/`. Wizard offers:
1. **Fresh install**
2. **Restore from backup**

## License

MIT License.

**Usage:** Free for non-commercial projects. Commercial use requires licensing.

## Developer

**Furkan Yıldırım** — [furkanyildirim.com](https://furkanyildirim.com)

## Support

Open an issue on GitHub Issues for questions and bug reports.
