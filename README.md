# Zoom Meeting Management System

**Türkçe** | **English**

---

## Türkçe

Zoom Meeting Management System, kurumsal ortamlar için geliştirilmiş kapsamlı bir toplantı yönetim platformudur. Sistem, Zoom API entegrasyonu aracılığıyla toplantı süreçlerini otomatikleştirerek kurumlara verimli bir toplantı yönetim çözümü sunar.

## Sistem Özellikleri

### Güvenlik
- Session tabanlı kimlik doğrulama sistemi
- CSRF token koruması
- Rol tabanlı erişim kontrolü
- Gelişmiş veritabanı doğrulama mekanizmaları

### Toplantı Yönetimi
- Zoom API ile tam entegrasyon
- Yönetici onay mekanizması
- Otomatik çakışma tespiti
- Departman bazlı yetkilendirme sistemi

### Yönetim Paneli
- Gerçek zamanlı sistem istatistikleri
- Kullanıcı ve departman yönetim araçları
- Çoklu Zoom hesabı desteği
- Kapsamlı raporlama sistemleri

### Kullanıcı Arayüzü
- Responsive web tasarımı
- Modern kullanıcı deneyimi
- Çoklu tema desteği
- Erişilebilirlik standartlarına uyumluluk

## Teknik Gereksinimler

### Minimum Sistem Gereksinimleri
- PHP 8.0 veya üzeri
- MySQL 5.7+ / SQLite 3.0+
- Apache/Nginx web sunucusu
- Zoom API hesabı

### Önerilen Sistem Gereksinimleri
- PHP 8.2+
- MySQL 8.0+
- 1 GB RAM
- SSL sertifikası

## Kurulum

### Kurulum Adımları

1. **Kaynak Kod İndirme**
   ```bash
   git clone https://github.com/fyildirim-debug/zoom-meeting-management.git
   cd zoom-meeting-management
   ```

2. **Dosya Yükleme**
   Proje dosyalarını web sunucunuzun root dizinine yükleyin.

3. **Kurulum Sihirbazı**
   Tarayıcınızda `http://yourdomain.com/install/` adresine giderek kurulum işlemini başlatın.

4. **Kurulum Süreci**
   - Sistem gereksinimleri kontrolü
   - Veritabanı yapılandırması
   - Yönetici hesabı oluşturma
   - Sistem ayarları yapılandırması

## Zoom API Yapılandırması

### API Hesabı Oluşturma
1. [Zoom Marketplace](https://marketplace.zoom.us/) üzerinden hesap oluşturun
2. Server-to-Server OAuth uygulaması geliştirin
3. Gerekli yetkilendirme kapsamlarını tanımlayın

### Sistem Yapılandırması
1. Admin panelinden Zoom hesap yönetimi sayfasına erişin
2. Yeni hesap ekle seçeneğini kullanın
3. Client ID, Client Secret ve Account ID bilgilerini girin

## Sistem Kullanımı

### Kullanıcı Fonksiyonları
- Toplantı talep sistemi
- Kişisel toplantı yönetimi
- Profil bilgilerini güncelleme

### Yönetici Fonksiyonları
- Toplantı onay süreçleri
- Kullanıcı yetkilendirme
- Sistem yapılandırması
- Raporlama ve analiz

## Geliştirme Bilgileri

### Teknoloji Altyapısı
- **Backend**: PHP 8.0+, PDO
- **Frontend**: JavaScript, Tailwind CSS
- **Veritabanı**: MySQL/SQLite
- **API**: Zoom REST API v2

### Proje Yapısı
```
├── admin/           # Yönetim paneli
├── api/             # API uç noktaları
├── config/          # Yapılandırma dosyaları
├── includes/        # Ortak PHP bileşenleri
├── install/         # Kurulum sistemi
├── logs/            # Günlük dosyaları
└── *.php            # Ana uygulama dosyaları
```

## Katkıda Bulunma

1. Projeyi fork edin
2. Özellik dalı oluşturun (`git checkout -b feature/yeni-ozellik`)
3. Değişikliklerinizi commit edin (`git commit -m 'Yeni özellik eklendi'`)
4. Dalınızı push edin (`git push origin feature/yeni-ozellik`)
5. Pull Request oluşturun

---

## English

Zoom Meeting Management System is a comprehensive meeting management platform developed for corporate environments. The system provides efficient meeting management solutions through Zoom API integration, automating meeting processes for organizations.

## System Features

### Security
- Session-based authentication system
- CSRF token protection
- Role-based access control
- Advanced database validation mechanisms

### Meeting Management
- Full integration with Zoom API
- Administrative approval mechanism
- Automatic conflict detection
- Department-based authorization system

### Management Panel
- Real-time system statistics
- User and department management tools
- Multiple Zoom account support
- Comprehensive reporting systems

### User Interface
- Responsive web design
- Modern user experience
- Multi-theme support
- Accessibility standards compliance

## Technical Requirements

### Minimum System Requirements
- PHP 8.0 or higher
- MySQL 5.7+ / SQLite 3.0+
- Apache/Nginx web server
- Zoom API account

### Recommended System Requirements
- PHP 8.2+
- MySQL 8.0+
- 1 GB RAM
- SSL certificate

## Installation

### Installation Steps

1. **Source Code Download**
   ```bash
   git clone https://github.com/fyildirim-debug/zoom-meeting-management.git
   cd zoom-meeting-management
   ```

2. **File Upload**
   Upload project files to your web server's root directory.

3. **Installation Wizard**
   Navigate to `http://yourdomain.com/install/` in your browser to start the installation process.

4. **Installation Process**
   - System requirements check
   - Database configuration
   - Administrator account creation
   - System settings configuration

## Zoom API Configuration

### API Account Creation
1. Create an account on [Zoom Marketplace](https://marketplace.zoom.us/)
2. Develop a Server-to-Server OAuth application
3. Define required authorization scopes

### System Configuration
1. Access Zoom account management page from admin panel
2. Use add new account option
3. Enter Client ID, Client Secret, and Account ID information

## System Usage

### User Functions
- Meeting request system
- Personal meeting management
- Profile information updates

### Administrator Functions
- Meeting approval processes
- User authorization
- System configuration
- Reporting and analysis

## Development Information

### Technology Infrastructure
- **Backend**: PHP 8.0+, PDO
- **Frontend**: JavaScript, Tailwind CSS
- **Database**: MySQL/SQLite
- **API**: Zoom REST API v2

### Project Structure
```
├── admin/           # Management panel
├── api/             # API endpoints
├── config/          # Configuration files
├── includes/        # Common PHP components
├── install/         # Installation system
├── logs/            # Log files
└── *.php            # Main application files
```

## Contributing

1. Fork the project
2. Create a feature branch (`git checkout -b feature/new-feature`)
3. Commit your changes (`git commit -m 'Add new feature'`)
4. Push to the branch (`git push origin feature/new-feature`)
5. Create a Pull Request

## License

This project is licensed under the MIT License.

### Kullanım Şartları / Usage Terms

**Türkçe:**
📖 **Açık Kaynak** | Kodları ücretsiz projelerinizde kullanabilirsiniz. Eğer kod parçacıkları ücretli projelerde kullanılırsa lisansa tabidir.

**English:**
📖 **Open Source** | You can use the codes in your free projects. If code snippets are used in paid projects, they are subject to licensing.

## Developer

**Furkan Yıldırım**
[furkanyildirim.com](https://furkanyildirim.com)

## Support

For support and questions, please use the GitHub Issues section.
