<?php
$pageTitle = 'Birim Yönetimi';
require_once '../config/config.php';
require_once '../config/auth.php';

requireLogin();
if (!isAdmin()) {
    redirect('../dashboard.php');
}

$currentUser = getCurrentUser();

// Birim işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token kontrolü
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $message = 'Güvenlik token hatası. Sayfayı yenileyin ve tekrar deneyin.';
        $messageType = 'error';
    } elseif (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        switch ($action) {
            case 'add_department':
                $result = addDepartment($_POST);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            case 'edit_department':
                $result = editDepartment($_POST);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            case 'delete_department':
                $result = deleteDepartment($_POST['department_id']);
                $message = $result['message'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            default:
                $message = 'Geçersiz işlem.';
                $messageType = 'error';
                break;
        }
    }
}

// Birimleri listele
try {
    $stmt = $pdo->query("
        SELECT d.*,
               (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id) as user_count,
               (SELECT COUNT(*) FROM meetings m
                WHERE m.department_id = d.id
                AND m.status = 'approved'
                AND m.date >= CURDATE() - INTERVAL 7 DAY) as weekly_meetings
        FROM departments d
        ORDER BY d.name
    ");
    $departments = $stmt->fetchAll();
    
} catch (Exception $e) {
    writeLog("Departments page error: " . $e->getMessage(), 'error');
    $departments = [];
}

// Helper functions
function addDepartment($data) {
    global $pdo;
    
    try {
        // İsim kontrolü
        $stmt = $pdo->prepare("SELECT id FROM departments WHERE name = ?");
        $stmt->execute([$data['name']]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Bu birim adı zaten kullanılıyor.'];
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO departments (name, weekly_limit) 
            VALUES (?, ?)
        ");
        
        $result = $stmt->execute([
            $data['name'],
            $data['weekly_limit']
        ]);
        
        if ($result) {
            writeLog("New department added: " . $data['name'], 'info');
            return ['success' => true, 'message' => 'Birim başarıyla eklendi.'];
        }
        
    } catch (Exception $e) {
        writeLog("Add department error: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => 'Birim eklenirken hata oluştu.'];
    }
}

function editDepartment($data) {
    global $pdo;
    
    try {
        // İsim kontrolü (mevcut birim dışında)
        $stmt = $pdo->prepare("SELECT id FROM departments WHERE name = ? AND id != ?");
        $stmt->execute([$data['name'], $data['department_id']]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Bu birim adı zaten kullanılıyor.'];
        }
        
        $stmt = $pdo->prepare("
            UPDATE departments 
            SET name = ?, weekly_limit = ?
            WHERE id = ?
        ");
        $result = $stmt->execute([
            $data['name'],
            $data['weekly_limit'],
            $data['department_id']
        ]);
        
        if ($result) {
            writeLog("Department updated: " . $data['name'], 'info');
            return ['success' => true, 'message' => 'Birim başarıyla güncellendi.'];
        }
        
    } catch (Exception $e) {
        writeLog("Edit department error: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => 'Birim güncellenirken hata oluştu.'];
    }
}

function deleteDepartment($departmentId) {
    global $pdo;
    
    try {
        // Birime bağlı kullanıcı kontrolü
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE department_id = ?");
        $stmt->execute([$departmentId]);
        $userCount = $stmt->fetchColumn();
        
        if ($userCount > 0) {
            return ['success' => false, 'message' => 'Bu birime bağlı kullanıcılar var. Önce kullanıcıları başka birime taşıyın.'];
        }
        
        $stmt = $pdo->prepare("DELETE FROM departments WHERE id = ?");
        $result = $stmt->execute([$departmentId]);
        
        if ($result) {
            writeLog("Department deleted: ID " . $departmentId, 'info');
            return ['success' => true, 'message' => 'Birim başarıyla silindi.'];
        }
        
    } catch (Exception $e) {
        writeLog("Delete department error: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => 'Birim silinirken hata oluştu.'];
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Content -->
<div class="main-content flex-1 p-6">
    <div class="max-w-7xl mx-auto">
        <!-- Page Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Birim Yönetimi</h1>
                <p class="mt-2 text-gray-600">Sistem birimlerini ve haftalık limitlerini yönetin</p>
            </div>
            <div class="mt-4 sm:mt-0">
                <button onclick="openAddDepartmentModal()" class="btn-primary">
                    <i class="fas fa-plus mr-2"></i>
                    Yeni Birim
                </button>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?> mb-6">
                <?php
                $icons = [
                    'success' => 'fas fa-check-circle',
                    'error' => 'fas fa-exclamation-circle',
                    'warning' => 'fas fa-exclamation-triangle',
                    'info' => 'fas fa-info-circle'
                ];
                ?>
                <i class="<?php echo $icons[$messageType] ?? $icons['info']; ?>"></i>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Toplam Birim</p>
                        <p class="text-3xl font-bold text-gray-900"><?php echo count($departments); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-building text-blue-600"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Toplam Kullanıcı</p>
                        <p class="text-3xl font-bold text-green-600">
                            <?php echo array_sum(array_column($departments, 'user_count')); ?>
                        </p>
                    </div>
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-users text-green-600"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Haftalık Toplantı</p>
                        <p class="text-3xl font-bold text-purple-600">
                            <?php echo array_sum(array_column($departments, 'weekly_meetings')); ?>
                        </p>
                    </div>
                    <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-calendar-week text-purple-600"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Ortalama Limit</p>
                        <p class="text-3xl font-bold text-orange-600">
                            <?php 
                            $limits = array_column($departments, 'weekly_limit');
                            echo count($limits) > 0 ? round(array_sum($limits) / count($limits)) : 0; 
                            ?>
                        </p>
                    </div>
                    <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-chart-line text-orange-600"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Departments Table -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Birim Listesi</h3>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Birim Adı</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Açıklama</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kullanıcı Sayısı</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Haftalık Limit</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Bu Hafta</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Yönetici</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($departments as $dept): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                                            <i class="fas fa-building text-blue-600"></i>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($dept['name']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    <div class="max-w-xs truncate">
                                        <?php echo htmlspecialchars($dept['description']); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                        <?php echo $dept['user_count']; ?> kişi
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <div class="flex items-center">
                                        <span class="font-medium"><?php echo $dept['weekly_limit']; ?></span>
                                        <span class="text-gray-500 ml-1">toplantı/hafta</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php 
                                    $percentage = $dept['weekly_limit'] > 0 ? ($dept['weekly_meetings'] / $dept['weekly_limit']) * 100 : 0;
                                    $colorClass = $percentage >= 90 ? 'text-red-600' : ($percentage >= 70 ? 'text-yellow-600' : 'text-green-600');
                                    ?>
                                    <div class="flex items-center">
                                        <span class="<?php echo $colorClass; ?> font-medium">
                                            <?php echo $dept['weekly_meetings']; ?>
                                        </span>
                                        <span class="text-gray-500 ml-1">/ <?php echo $dept['weekly_limit']; ?></span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-1 mt-1">
                                        <div class="h-1 rounded-full <?php echo $percentage >= 90 ? 'bg-red-500' : ($percentage >= 70 ? 'bg-yellow-500' : 'bg-green-500'); ?>" 
                                             style="width: <?php echo min(100, $percentage); ?>%"></div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    <?php if ($dept['manager_name']): ?>
                                        <div>
                                            <div class="font-medium"><?php echo htmlspecialchars($dept['manager_name']); ?></div>
                                            <div class="text-gray-500"><?php echo htmlspecialchars($dept['manager_email']); ?></div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex space-x-2">
                                        <button onclick="editDepartment(<?php echo htmlspecialchars(json_encode($dept)); ?>)" 
                                                class="text-blue-600 hover:text-blue-900">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        
                                        <?php if ($dept['user_count'] == 0): ?>
                                            <button onclick="confirmDeleteDepartment(<?php echo $dept['id']; ?>)"
                                                    class="text-red-600 hover:text-red-900">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-gray-400" title="Birime bağlı kullanıcılar var">
                                                <i class="fas fa-trash"></i>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    // Debug: Check if functions exist
    console.log('createFormModal exists:', typeof createFormModal !== 'undefined');
    console.log('confirmAction exists:', typeof confirmAction !== 'undefined');
    
    function openAddDepartmentModal() {
        if (typeof createFormModal === 'undefined') {
            alert('Modal sistemi yüklenemedi. Sayfayı yenileyin.');
            return;
        }
        
        createFormModal({
            id: 'add-department-modal',
            title: 'Yeni Birim Ekle',
            fields: [
                {
                    name: 'name',
                    label: 'Birim Adı',
                    type: 'text',
                    required: true,
                    placeholder: 'Birim adını girin'
                },
                {
                    name: 'weekly_limit',
                    label: 'Haftalık Toplantı Limiti',
                    type: 'number',
                    required: true,
                    value: '10',
                    placeholder: 'Haftalık maksimum toplantı sayısı'
                }
            ],
            submitText: 'Birim Ekle',
            onSubmit: function(data, form) {
                const formElement = document.createElement('form');
                formElement.method = 'POST';
                
                // Add CSRF token
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = window.APP_CONFIG.csrf_token;
                formElement.appendChild(csrfInput);
                
                // Add form data
                const fields = ['action', 'name', 'weekly_limit'];
                const values = {action: 'add_department', ...data};
                
                fields.forEach(field => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = field;
                    input.value = values[field] || '';
                    formElement.appendChild(input);
                });
                
                document.body.appendChild(formElement);
                formElement.submit();
            }
        });
    }
    
    function editDepartment(dept) {
        createFormModal({
            id: 'edit-department-modal',
            title: 'Birim Düzenle',
            data: dept,
            fields: [
                {
                    name: 'name',
                    label: 'Birim Adı',
                    type: 'text',
                    required: true,
                    placeholder: 'Birim adını girin'
                },
                {
                    name: 'weekly_limit',
                    label: 'Haftalık Toplantı Limiti',
                    type: 'number',
                    required: true,
                    placeholder: 'Haftalık maksimum toplantı sayısı'
                }
            ],
            submitText: 'Güncelle',
            onSubmit: function(data, form) {
                const formElement = document.createElement('form');
                formElement.method = 'POST';
                
                // Add CSRF token
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = window.APP_CONFIG.csrf_token;
                formElement.appendChild(csrfInput);
                
                // Add form data
                const fields = ['action', 'department_id', 'name', 'weekly_limit'];
                const values = {action: 'edit_department', department_id: dept.id, ...data};
                
                fields.forEach(field => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = field;
                    input.value = values[field] || '';
                    formElement.appendChild(input);
                });
                
                document.body.appendChild(formElement);
                formElement.submit();
            }
        });
    }
    
    function confirmDeleteDepartment(departmentId) {
        if (typeof confirmAction === 'undefined') {
            if (confirm('Bu birimi kalıcı olarak silmek istediğinizden emin misiniz? Bu işlem geri alınamaz.')) {
                // Fallback to direct form submission
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="${window.APP_CONFIG?.csrf_token || ''}">
                    <input type="hidden" name="action" value="delete_department">
                    <input type="hidden" name="department_id" value="${departmentId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
            return;
        }
        
        confirmAction({
            title: 'Birimi Sil',
            message: 'Bu birimi kalıcı olarak silmek istediğinizden emin misiniz? Bu işlem geri alınamaz.',
            type: 'danger',
            confirmText: 'Evet, Sil',
            cancelText: 'İptal',
            onConfirm: function() {
                const form = document.createElement('form');
                form.method = 'POST';
                
                // Add CSRF token
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = window.APP_CONFIG.csrf_token;
                form.appendChild(csrfInput);
                
                // Add action
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_department';
                form.appendChild(actionInput);
                
                // Add department_id
                const deptIdInput = document.createElement('input');
                deptIdInput.type = 'hidden';
                deptIdInput.name = 'department_id';
                deptIdInput.value = departmentId;
                form.appendChild(deptIdInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        });
    }
</script>

<?php include '../includes/footer.php'; ?>