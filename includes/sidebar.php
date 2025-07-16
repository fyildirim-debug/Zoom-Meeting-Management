<?php
// Sidebar menü tanımları

// Admin sayfasında mıyız kontrol et
$isAdminPage = strpos($_SERVER['PHP_SELF'], '/admin/') !== false;
$adminPrefix = $isAdminPage ? '' : 'admin/';
$userPrefix = $isAdminPage ? '../' : '';

// Admin menü öğeleri
$adminMenuItems = [
    [
        'title' => 'Kullanıcı Yönetimi',
        'icon' => 'fas fa-users',
        'url' => $adminPrefix . 'users.php',
        'badge' => null
    ],
    [
        'title' => 'Birim Yönetimi',
        'icon' => 'fas fa-building',
        'url' => $adminPrefix . 'departments.php',
        'badge' => null
    ],
    [
        'title' => 'Toplantı Onayları',
        'icon' => 'fas fa-check-circle',
        'url' => $adminPrefix . 'meeting-approvals.php',
        'badge' => getPendingMeetingsCount()
    ],
    [
        'title' => 'Zoom Hesapları',
        'icon' => 'fas fa-camera',
        'url' => $adminPrefix . 'zoom-accounts.php',
        'badge' => null
    ],
    [
        'title' => 'Zoom Toplantı İmport',
        'icon' => 'fas fa-cloud-download-alt',
        'url' => $adminPrefix . 'import-zoom-meetings.php',
        'badge' => null
    ],
    [
        'title' => 'Sistem Ayarları',
        'icon' => 'fas fa-cogs',
        'url' => $adminPrefix . 'settings.php',
        'badge' => null
    ],
    [
        'title' => 'Raporlar ve İstatistikler',
        'icon' => 'fas fa-chart-bar',
        'url' => $adminPrefix . 'reports.php',
        'badge' => null
    ]
];

// Kullanıcı menü öğeleri
$userMenuItems = [
    [
        'title' => 'Dashboard',
        'icon' => 'fas fa-tachometer-alt',
        'url' => $userPrefix . 'dashboard.php',
        'badge' => null
    ],
    [
        'title' => 'Toplantılarım',
        'icon' => 'fas fa-video',
        'url' => $userPrefix . 'my-meetings.php',
        'badge' => null
    ],
    [
        'title' => 'Yeni Toplantı Talebi',
        'icon' => 'fas fa-plus-circle',
        'url' => $userPrefix . 'new-meeting.php',
        'badge' => null
    ],
    [
        'title' => 'Takvim Görünümü',
        'icon' => 'fas fa-calendar-alt',
        'url' => $userPrefix . 'calendar.php',
        'badge' => null
    ],
    [
        'title' => 'Profil Ayarları',
        'icon' => 'fas fa-user-cog',
        'url' => $userPrefix . 'profile.php',
        'badge' => null
    ]
];

// Bekleyen toplantı sayısını al
function getPendingMeetingsCount() {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM meetings WHERE status = 'pending'");
        $stmt->execute();
        $count = $stmt->fetchColumn();
        return $count > 0 ? $count : null;
    } catch (Exception $e) {
        return null;
    }
}



// Aktif menü kontrolü
function isActiveMenu($url) {
    $currentPage = basename($_SERVER['PHP_SELF']);
    $currentDir = dirname($_SERVER['PHP_SELF']);
    
    // Tam URL kontrolü
    if ($url === $currentPage) {
        return true;
    }
    
    // Admin sayfaları için
    if (strpos($url, 'admin/') === 0 && strpos($currentDir, 'admin') !== false) {
        $adminPage = str_replace('admin/', '', $url);
        return $adminPage === $currentPage;
    }
    
    return false;
}
?>

<style>
    /* Sidebar Styles */
    .sidebar {
        position: fixed;
        left: 0;
        top: 64px; /* Header height */
        width: 280px;
        height: calc(100vh - 64px);
        background: var(--bg-secondary);
        border-right: 1px solid var(--border-color);
        transform: translateX(0);
        transition: all 0.3s ease;
        z-index: 998;
        overflow-y: auto;
        overflow-x: hidden;
    }
    
    .sidebar.collapsed {
        transform: translateX(-280px);
    }
    
    .sidebar-overlay {
        position: fixed;
        top: 64px;
        left: 0;
        width: 100vw;
        height: calc(100vh - 64px);
        background: rgba(0, 0, 0, 0.5);
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
        z-index: 998;
    }
    
    .sidebar-overlay.active {
        opacity: 1;
        visibility: visible;
    }
    
    /* Menu Items */
    .menu-section {
        padding: 1rem 0;
    }
    
    .menu-section:not(:last-child) {
        border-bottom: 1px solid var(--border-color);
    }
    
    .menu-section-title {
        padding: 0 1.5rem 0.5rem;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--text-muted);
    }
    
    .menu-item {
        display: flex;
        align-items: center;
        padding: 0.75rem 1.5rem;
        color: var(--text-secondary);
        text-decoration: none;
        transition: all 0.3s ease;
        position: relative;
        border: none;
        background: none;
        cursor: pointer;
    }
    
    .menu-item:hover {
        background: rgba(102, 126, 234, 0.1);
        color: var(--primary);
        transform: translateX(4px);
    }
    
    .menu-item.active {
        background: rgba(102, 126, 234, 0.15);
        color: var(--primary);
        border-right: 3px solid var(--primary);
    }
    
    .menu-item.active::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 3px;
        background: var(--primary);
    }
    
    .menu-item i {
        width: 20px;
        margin-right: 0.75rem;
        text-align: center;
        font-size: 1rem;
    }
    
    .menu-item span {
        flex: 1;
        font-weight: 500;
    }
    
    .menu-badge {
        background: var(--error);
        color: white;
        font-size: 0.75rem;
        font-weight: 600;
        padding: 0.125rem 0.5rem;
        border-radius: 9999px;
        min-width: 1.25rem;
        text-align: center;
    }
    
    /* Submenu */
    .menu-item.has-submenu .submenu-toggle {
        margin-left: auto;
        transition: transform 0.3s ease;
    }
    
    .menu-item.has-submenu.open .submenu-toggle {
        transform: rotate(180deg);
    }
    
    .submenu {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease;
        background: rgba(0, 0, 0, 0.05);
    }
    
    .submenu.open {
        max-height: 300px;
    }
    
    .submenu .menu-item {
        padding-left: 3.5rem;
        font-size: 0.875rem;
    }
    
    /* Profile Section */
    .sidebar-profile {
        padding: 1.5rem;
        border-top: 1px solid var(--border-color);
        background: rgba(102, 126, 234, 0.05);
    }
    
    .profile-info {
        display: flex;
        align-items: center;
        margin-bottom: 1rem;
    }
    
    .profile-avatar {
        width: 40px;
        height: 40px;
        background: var(--gradient-primary);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
        margin-right: 0.75rem;
    }
    
    .profile-details h4 {
        font-weight: 600;
        color: var(--text-primary);
        margin: 0;
        font-size: 0.875rem;
    }
    
    .profile-details p {
        color: var(--text-muted);
        margin: 0;
        font-size: 0.75rem;
    }
    
    .profile-stats {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
        margin-top: 1rem;
    }
    
    .stat-item {
        text-align: center;
        padding: 0.5rem;
        background: var(--bg-primary);
        border-radius: 0.5rem;
    }
    
    .stat-value {
        display: block;
        font-weight: bold;
        color: var(--primary);
        font-size: 1rem;
    }
    
    .stat-label {
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-top: 0.25rem;
    }
    
    /* Mobile Styles */
    @media (max-width: 768px) {
        .sidebar {
            transform: translateX(-280px);
            position: fixed;
            z-index: 999;
        }
        
        .sidebar.open {
            transform: translateX(0);
        }
        
        /* Mobilde main content margin'i sıfır olmalı */
        .main-content {
            margin-left: 0 !important;
            width: 100% !important;
        }
        
        /* Mobil overlay aktif olduğunda body scroll'u engelle */
        body.sidebar-open {
            overflow: hidden;
        }
    }
    
    /* Desktop Styles */
    @media (min-width: 769px) {
        .sidebar-overlay {
            display: none;
        }
        
        .main-content {
            margin-left: 280px;
            transition: margin-left 0.3s ease;
        }
        
        .main-content.sidebar-collapsed {
            margin-left: 0;
        }
        
        /* Desktop'ta sidebar her zaman görünür */
        .sidebar {
            position: fixed;
            transform: translateX(0);
        }
    }
</style>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <!-- User Menu Section -->
    <div class="menu-section">
        <div class="menu-section-title">Ana Menü</div>
        
        <?php foreach ($userMenuItems as $item): ?>
            <a href="<?php echo $item['url']; ?>" 
               class="menu-item <?php echo isActiveMenu($item['url']) ? 'active' : ''; ?>">
                <i class="<?php echo $item['icon']; ?>"></i>
                <span><?php echo $item['title']; ?></span>
                <?php if ($item['badge']): ?>
                    <span class="menu-badge"><?php echo $item['badge']; ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
    
    <!-- Admin Menu Section (only for admins) -->
    <?php if (isAdmin()): ?>
        <div class="menu-section">
            <div class="menu-section-title">Yönetim</div>
            
            <?php foreach ($adminMenuItems as $item): ?>
                <a href="<?php echo $item['url']; ?>" 
                   class="menu-item <?php echo isActiveMenu($item['url']) ? 'active' : ''; ?>">
                    <i class="<?php echo $item['icon']; ?>"></i>
                    <span><?php echo $item['title']; ?></span>
                    <?php if ($item['badge']): ?>
                        <span class="menu-badge"><?php echo $item['badge']; ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <!-- Quick Actions -->
    <div class="menu-section">
        <div class="menu-section-title">Hızlı İşlemler</div>
        
        <button class="menu-item" onclick="openNewMeetingModal()">
            <i class="fas fa-plus"></i>
            <span>Hızlı Toplantı</span>
        </button>
        
        
        <?php if (isAdmin()): ?>
            <button class="menu-item" onclick="openBulkApprovalModal()">
                <i class="fas fa-tasks"></i>
                <span>Toplu Onay</span>
            </button>
        <?php endif; ?>
    </div>
    
    <!-- Profile Section -->
    <div class="sidebar-profile">
        <div class="profile-info">
            <div class="profile-avatar">
                <?php echo strtoupper(substr($currentUser['name'], 0, 1)); ?>
            </div>
            <div class="profile-details">
                <h4><?php echo $currentUser['name'] . ' ' . $currentUser['surname']; ?></h4>
                <p><?php echo $currentUser['role'] === 'admin' ? 'Yönetici' : 'Kullanıcı'; ?></p>
            </div>
        </div>
        
        <div class="profile-stats">
            <div class="stat-item">
                <span class="stat-value" id="user-meetings-count">-</span>
                <div class="stat-label">Toplantılarım</div>
            </div>
            <div class="stat-item">
                <span class="stat-value" id="user-pending-count">-</span>
                <div class="stat-label">Bekleyen</div>
            </div>
        </div>
    </div>
</aside>

<!-- Sidebar Overlay for Mobile -->
<div class="sidebar-overlay" id="sidebar-overlay" onclick="closeSidebar()"></div>

<script>
    // Sidebar Toggle Functions
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        const mainContent = document.querySelector('.main-content');
        const body = document.body;
        
        if (window.innerWidth <= 768) {
            // Mobile
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
            
            // Body scroll kontrolü
            if (sidebar.classList.contains('open')) {
                body.classList.add('sidebar-open');
            } else {
                body.classList.remove('sidebar-open');
            }
        } else {
            // Desktop
            sidebar.classList.toggle('collapsed');
            if (mainContent) {
                mainContent.classList.toggle('sidebar-collapsed');
            }
        }
    }
    
    function closeSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        const body = document.body;
        
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        body.classList.remove('sidebar-open');
    }
    
    // Auto close sidebar on mobile when clicking menu items
    document.querySelectorAll('.menu-item').forEach(item => {
        item.addEventListener('click', function(e) {
            if (window.innerWidth <= 768 && this.tagName === 'A') {
                setTimeout(closeSidebar, 100);
            }
        });
    });
    
    // Handle window resize
    window.addEventListener('resize', function() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        const mainContent = document.querySelector('.main-content');
        
        if (window.innerWidth > 768) {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
        } else {
            sidebar.classList.remove('collapsed');
            if (mainContent) {
                mainContent.classList.remove('sidebar-collapsed');
            }
        }
    });
    
    // Load user statistics
    function loadUserStats() {
        fetch('<?php echo $userPrefix; ?>api/user-stats.php')
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data) {
                    document.getElementById('user-meetings-count').textContent = data.data.meetings_count || 0;
                    document.getElementById('user-pending-count').textContent = data.data.pending_count || 0;
                }
            })
            .catch(error => console.error('Stats loading error:', error));
    }
    
    // Quick action modals
    function openNewMeetingModal() {
        window.location.href = '<?php echo $userPrefix; ?>new-meeting.php';
    }
    
    
    function openBulkApprovalModal() {
        window.location.href = 'admin/meeting-approvals.php';
    }
    
    // Load stats on page load
    document.addEventListener('DOMContentLoaded', function() {
        loadUserStats();
        
        // Refresh stats every 30 seconds
        setInterval(loadUserStats, 30000);
    });
    
    // Submenu toggle functionality
    document.querySelectorAll('.menu-item.has-submenu').forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            
            this.classList.toggle('open');
            const submenu = this.nextElementSibling;
            if (submenu && submenu.classList.contains('submenu')) {
                submenu.classList.toggle('open');
            }
        });
    });
    
    // Active menu highlighting based on current page
    function highlightActiveMenu() {
        const currentPath = window.location.pathname;
        const menuItems = document.querySelectorAll('.menu-item[href]');
        
        menuItems.forEach(item => {
            const href = item.getAttribute('href');
            if (currentPath.includes(href.replace('.php', ''))) {
                item.classList.add('active');
            }
        });
    }
    
    // Initialize on page load
    document.addEventListener('DOMContentLoaded', highlightActiveMenu);
</script>