# 🎯 Zoom Meeting Management System

**🇹🇷 Türkçe** | **🇺🇸 English**

---

## 🇹🇷 Türkçe

Modern, güvenli ve kullanıcı dostu Zoom toplantı yönetim sistemi. Kurumsal kullanım için tasarlanmış, tam özellikli web uygulaması.

### ✨ Özellikler

#### 🔐 Güvenlik
- **Güvenli Authentication**: Session tabanlı güvenli giriş sistemi
- **CSRF Protection**: Tüm formlarda CSRF token koruması
- **Role Based Access**: Admin ve kullanıcı yetki seviyeleri
- **Secure Install**: Gelişmiş veritabanı doğrulama sistemi

#### 🎪 Toplantı Yönetimi
- **Otomatik Zoom Entegrasyonu**: Zoom API ile tam entegrasyon
- **Akıllı Onay Sistemi**: Admin onaylı toplantı oluşturma
- **Çakışma Kontrolü**: Otomatik toplantı çakışma tespiti
- **Birim Bazlı Yetkilendirme**: Departman bazlı erişim kontrolü

#### 📊 Admin Paneli
- **Kapsamlı Dashboard**: Gerçek zamanlı istatistikler
- **Kullanıcı Yönetimi**: Kullanıcı ve departman yönetimi
- **Zoom Hesap Yönetimi**: Çoklu Zoom hesabı desteği
- **Detaylı Raporlama**: Toplantı ve kullanım raporları

#### 🎨 Modern Tasarım
- **Responsive Design**: Tüm cihazlarda mükemmel görünüm
- **Glass Morphism**: Modern tasarım dili
- **Dark/Light Theme**: Tema desteği
- **Smooth Animations**: Akıcı animasyonlar

### 🚀 Kurulum

#### Gereksinimler
- PHP 8.0+
- MySQL 5.7+ / SQLite 3.0+
- Apache/Nginx
- Zoom API Hesabı

#### Kurulum Adımları
1. **Dosyaları İndirin**
   ```bash
   git clone https://github.com/fyildirim-debug/zoom-meeting-management.git
   cd zoom-meeting-management
   ```

2. **Web Sunucuya Yükleyin**
   Dosyaları web sunucunuzun root klasörüne yükleyin.

3. **Kurulum Sihirbazını Çalıştırın**
   ```
   http://yourdomain.com/install/
   ```

4. **Kurulum Adımları**
   - **Hoş Geldiniz**: Sistem gereksinimleri kontrol edilir
   - **Veritabanı**: MySQL/SQLite yapılandırması
   - **Admin Hesabı**: Yönetici hesabı oluşturulur
   - **Sistem Ayarları**: Site ayarları ve zaman dilimi
   - **Tamamlama**: Otomatik kurulum tamamlanır

### 🛠️ Zoom API Yapılandırması

1. **Zoom App Oluşturun**
   - [Zoom Marketplace](https://marketplace.zoom.us/) hesabı açın
   - **Server-to-Server OAuth** app oluşturun
   - Gerekli scope'ları seçin

2. **Admin Panelinde Yapılandırın**
   - **Admin Panel → Zoom Hesapları**
   - **Yeni Hesap Ekle**
   - Client ID, Client Secret, Account ID bilgilerini girin

### 📱 Kullanım

#### Kullanıcı İşlemleri
- **Toplantı Talebi**: Yeni toplantı oluşturma
- **Toplantılarım**: Kendi toplantılarını görüntüleme
- **Profil Yönetimi**: Kişisel bilgi düzenleme

#### Admin İşlemleri
- **Toplantı Onayları**: Bekleyen toplantıları onaylama/reddetme
- **Kullanıcı Yönetimi**: Kullanıcı ve departman yönetimi
- **Sistem Ayarları**: Genel sistem yapılandırması
- **Raporlama**: Detaylı kullanım raporları

### 🔧 Geliştirme

#### Teknoloji Stack
- **Backend**: PHP 8.0+, PDO
- **Frontend**: Vanilla JavaScript, Tailwind CSS
- **Database**: MySQL/SQLite
- **API**: Zoom REST API v2

#### Klasör Yapısı
```
├── admin/           # Admin paneli
├── api/             # API endpoint'leri
├── config/          # Yapılandırma dosyaları
├── includes/        # Ortak PHP dosyaları
├── install/         # Kurulum sistemi
├── logs/            # Log dosyaları
├── assets/          # CSS, JS, görsel dosyalar
└── *.php            # Ana sayfa dosyaları
```

### 🤝 Katkıda Bulunma

1. Fork edin
2. Feature branch oluşturun (`git checkout -b feature/amazing-feature`)
3. Commit yapın (`git commit -m 'Add amazing feature'`)
4. Push edin (`git push origin feature/amazing-feature`)
5. Pull Request oluşturun

### 📊 Sistem Gereksinimleri

#### Minimum
- PHP 8.0+
- MySQL 5.7+ / SQLite 3.0+
- 512 MB RAM
- 100 MB disk alanı

#### Önerilen
- PHP 8.2+
- MySQL 8.0+
- 1 GB RAM
- 500 MB disk alanı
- SSL sertifikası

---

## 🇺🇸 English

A modern, secure, and user-friendly Zoom meeting management system. A full-featured web application designed for corporate use.

### ✨ Features

#### 🔐 Security
- **Secure Authentication**: Session-based secure login system
- **CSRF Protection**: CSRF token protection on all forms
- **Role Based Access**: Admin and user authorization levels
- **Secure Install**: Advanced database validation system

#### 🎪 Meeting Management
- **Automatic Zoom Integration**: Full integration with Zoom API
- **Smart Approval System**: Admin-approved meeting creation
- **Conflict Detection**: Automatic meeting conflict detection
- **Department-based Authorization**: Department-based access control

#### 📊 Admin Panel
- **Comprehensive Dashboard**: Real-time statistics
- **User Management**: User and department management
- **Zoom Account Management**: Multiple Zoom account support
- **Detailed Reporting**: Meeting and usage reports

#### 🎨 Modern Design
- **Responsive Design**: Perfect appearance on all devices
- **Glass Morphism**: Modern design language
- **Dark/Light Theme**: Theme support
- **Smooth Animations**: Fluid animations

### 🚀 Installation

#### Requirements
- PHP 8.0+
- MySQL 5.7+ / SQLite 3.0+
- Apache/Nginx
- Zoom API Account

#### Installation Steps
1. **Download Files**
   ```bash
   git clone https://github.com/fyildirim-debug/zoom-meeting-management.git
   cd zoom-meeting-management
   ```

2. **Upload to Web Server**
   Upload files to your web server's root directory.

3. **Run Installation Wizard**
   ```
   http://yourdomain.com/install/
   ```

4. **Installation Steps**
   - **Welcome**: System requirements check
   - **Database**: MySQL/SQLite configuration
   - **Admin Account**: Administrator account creation
   - **System Settings**: Site settings and timezone
   - **Completion**: Automatic installation completion

### 🛠️ Zoom API Configuration

1. **Create Zoom App**
   - Open [Zoom Marketplace](https://marketplace.zoom.us/) account
   - Create **Server-to-Server OAuth** app
   - Select required scopes

2. **Configure in Admin Panel**
   - **Admin Panel → Zoom Accounts**
   - **Add New Account**
   - Enter Client ID, Client Secret, Account ID information

### 📱 Usage

#### User Operations
- **Meeting Request**: Create new meeting
- **My Meetings**: View own meetings
- **Profile Management**: Edit personal information

#### Admin Operations
- **Meeting Approvals**: Approve/reject pending meetings
- **User Management**: User and department management
- **System Settings**: General system configuration
- **Reporting**: Detailed usage reports

### 🔧 Development

#### Technology Stack
- **Backend**: PHP 8.0+, PDO
- **Frontend**: Vanilla JavaScript, Tailwind CSS
- **Database**: MySQL/SQLite
- **API**: Zoom REST API v2

#### Folder Structure
```
├── admin/           # Admin panel
├── api/             # API endpoints
├── config/          # Configuration files
├── includes/        # Common PHP files
├── install/         # Installation system
├── logs/            # Log files
├── assets/          # CSS, JS, image files
└── *.php            # Main page files
```

### 🤝 Contributing

1. Fork it
2. Create feature branch (`git checkout -b feature/amazing-feature`)
3. Commit changes (`git commit -m 'Add amazing feature'`)
4. Push to branch (`git push origin feature/amazing-feature`)
5. Create Pull Request

### 📊 System Requirements

#### Minimum
- PHP 8.0+
- MySQL 5.7+ / SQLite 3.0+
- 512 MB RAM
- 100 MB disk space

#### Recommended
- PHP 8.2+
- MySQL 8.0+
- 1 GB RAM
- 500 MB disk space
- SSL certificate

---

❤️ **Made with love for the community / Topluluk için sevgiyle yapıldı**
