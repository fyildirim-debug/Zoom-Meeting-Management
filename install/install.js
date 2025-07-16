/**
 * Kurulum Sihirbazı JavaScript - Otomatik Veritabanı Kurulumu
 */

let currentStep = 1;
const totalSteps = 5;
let dbConnectionTested = false;

// Form validation rules
const validationRules = {
    step2: ['db_type'],
    step3: ['admin_name', 'admin_surname', 'admin_email', 'admin_password', 'admin_password_confirm'],
    step4: ['site_title', 'work_start', 'work_end', 'timezone']
};

// Modal functions for reinstall confirmation
function showReinstallModal() {
    const modal = document.getElementById('reinstall-modal');
    if (modal) {
        modal.style.display = 'flex'; // Display özelliğini ayarla
        modal.classList.remove('hidden');
        modal.style.opacity = '0';
        setTimeout(() => {
            modal.style.transition = 'opacity 0.3s ease';
            modal.style.opacity = '1';
        }, 10);
    }
}

function hideReinstallModal() {
    const modal = document.getElementById('reinstall-modal');
    if (modal) {
        modal.style.opacity = '0';
        setTimeout(() => {
            modal.style.display = 'none'; // Display özelliğini gizle
            modal.classList.add('hidden');
        }, 300);
    }
}

function confirmReinstall() {
    const confirmationCode = document.getElementById('confirmation-code').value.trim();
    if (confirmationCode === 'YENIDEN_KUR_ONAYI') {
        hideReinstallModal();
        startReinstall();
    } else {
        showToast('Onay kodu hatalı! "YENIDEN_KUR_ONAYI" yazın.', 'error');
        document.getElementById('confirmation-code').focus();
    }
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    updateStepIndicators();
    toggleDbFields();
    addSmoothAnimations();
    updateAutoDbName();
    
    // Add click event for modal close buttons
    const modalCloseButtons = document.querySelectorAll('[onclick="hideReinstallModal()"]');
    modalCloseButtons.forEach(btn => {
        btn.addEventListener('click', hideReinstallModal);
    });
    
    // Add enter key support for confirmation code
    const confirmationCodeInput = document.getElementById('confirmation-code');
    if (confirmationCodeInput) {
        confirmationCodeInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                confirmReinstall();
            }
        });
    }
    
    // Initialize sample data toggle
    toggleSampleDataInfo();
});

// Toggle sample data information display
function toggleSampleDataInfo() {
    const checkbox = document.getElementById('sample_data');
    const infoDiv = document.getElementById('sample-data-info');
    const disabledDiv = document.getElementById('sample-data-disabled');
    
    if (!checkbox || !infoDiv || !disabledDiv) return;
    
    if (checkbox.checked) {
        infoDiv.style.display = 'block';
        disabledDiv.style.display = 'none';
        
        // Smooth animation
        infoDiv.style.opacity = '0';
        infoDiv.style.transform = 'translateY(-10px)';
        setTimeout(() => {
            infoDiv.style.transition = 'all 0.4s ease';
            infoDiv.style.opacity = '1';
            infoDiv.style.transform = 'translateY(0)';
        }, 10);
    } else {
        infoDiv.style.display = 'none';
        disabledDiv.style.display = 'block';
        
        // Smooth animation
        disabledDiv.style.opacity = '0';
        disabledDiv.style.transform = 'translateY(-10px)';
        setTimeout(() => {
            disabledDiv.style.transition = 'all 0.4s ease';
            disabledDiv.style.opacity = '1';
            disabledDiv.style.transform = 'translateY(0)';
        }, 10);
    }
}

// Smooth animations helper
function addSmoothAnimations() {
    // Add entrance animations to elements
    const elements = document.querySelectorAll('.glass-card, .step-indicator');
    elements.forEach((el, index) => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        setTimeout(() => {
            el.style.transition = 'all 0.6s cubic-bezier(0.4, 0, 0.2, 1)';
            el.style.opacity = '1';
            el.style.transform = 'translateY(0)';
        }, index * 100);
    });
}

// Update auto-generated database name
function updateAutoDbName() {
    const now = new Date();
    const timestamp = now.getFullYear() + 
                     String(now.getMonth() + 1).padStart(2, '0') + 
                     String(now.getDate()).padStart(2, '0') + '_' +
                     String(now.getHours()).padStart(2, '0') + 
                     String(now.getMinutes()).padStart(2, '0') + 
                     String(now.getSeconds()).padStart(2, '0');
    
    const autoDbName = document.getElementById('auto-db-name');
    if (autoDbName) {
        autoDbName.textContent = `zoom_meetings_${timestamp}`;
    }
}

// Step navigation
function nextStep() {
    if (currentStep < totalSteps) {
        if (validateStep(currentStep)) {
            // Special validation for step 2 (database)
            if (currentStep === 2) {
                const dbType = document.getElementById('db_type').value;
                if (dbType === 'sqlite') {
                    // SQLite doesn't need connection test
                    dbConnectionTested = true;
                } else if (!dbConnectionTested) {
                    showToast('Lütfen önce veritabanı sunucu bağlantısını test edin.', 'warning');
                    return;
                }
            }
            
            currentStep++;
            showStep(currentStep);
            updateStepIndicators();
        }
    }
}

function prevStep() {
    if (currentStep > 1) {
        currentStep--;
        showStep(currentStep);
        updateStepIndicators();
    }
}

function showStep(step) {
    // Hide all steps with fade out
    document.querySelectorAll('.form-step').forEach(el => {
        if (el.classList.contains('active')) {
            el.style.opacity = '0';
            el.style.transform = 'translateX(-20px)';
            setTimeout(() => {
                el.classList.remove('active');
            }, 200);
        }
    });
    
    // Show current step with fade in
    setTimeout(() => {
        const currentStepEl = document.querySelector(`[data-step="${step}"].form-step`);
        if (currentStepEl) {
            currentStepEl.classList.add('active');
            currentStepEl.style.opacity = '0';
            currentStepEl.style.transform = 'translateX(20px)';
            setTimeout(() => {
                currentStepEl.style.transition = 'all 0.4s cubic-bezier(0.4, 0, 0.2, 1)';
                currentStepEl.style.opacity = '1';
                currentStepEl.style.transform = 'translateX(0)';
            }, 50);
        }
    }, 200);
}

function updateStepIndicators() {
    document.querySelectorAll('.step-indicator').forEach((indicator, index) => {
        const stepNumber = index + 1;
        indicator.classList.remove('step-active', 'step-completed');
        
        if (stepNumber < currentStep) {
            indicator.classList.add('step-completed');
            indicator.innerHTML = '✓';
        } else if (stepNumber === currentStep) {
            indicator.classList.add('step-active');
            indicator.innerHTML = stepNumber;
        } else {
            indicator.innerHTML = stepNumber;
        }
    });
    
    // Update progress lines
    document.querySelectorAll('.step-indicator').forEach((indicator, index) => {
        const line = indicator.nextElementSibling;
        if (line && line.classList.contains('w-16')) {
            if (index < currentStep - 1) {
                line.style.background = 'linear-gradient(90deg, #4facfe 0%, #00f2fe 100%)';
            } else {
                line.style.background = 'rgba(255, 255, 255, 0.3)';
            }
        }
    });
}

// Database type toggle
function toggleDbFields() {
    const dbType = document.getElementById('db_type').value;
    const mysqlFields = document.getElementById('mysql_fields');
    const sqliteFields = document.getElementById('sqlite_fields');
    
    if (dbType === 'mysql') {
        mysqlFields.style.display = 'block';
        sqliteFields.style.display = 'none';
        
        // Make MySQL fields required
        setRequiredFields(['db_host', 'db_port', 'db_username'], true);
        
        // Reset connection test for MySQL
        dbConnectionTested = false;
        updateNextButton();
        updateAutoDbName();
        
        showToast('MySQL seçildi. Sunucu bağlantısını test edin.', 'info');
    } else {
        mysqlFields.style.display = 'none';
        sqliteFields.style.display = 'block';
        
        // Make MySQL fields not required
        setRequiredFields(['db_host', 'db_port', 'db_username'], false);
        
        // SQLite doesn't need connection test
        dbConnectionTested = true;
        updateNextButton();
        
        showToast('SQLite seçildi. Veritabanı otomatik oluşturulacak.', 'success');
    }
}

function setRequiredFields(fieldIds, required) {
    fieldIds.forEach(id => {
        const field = document.getElementById(id);
        if (field) {
            field.required = required;
        }
    });
}

function updateNextButton() {
    const nextBtn = document.getElementById('db-next-btn');
    const testBtn = document.getElementById('test-db-btn');
    
    if (dbConnectionTested) {
        nextBtn.disabled = false;
        nextBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        nextBtn.classList.add('btn-primary');
        
        if (document.getElementById('db_type').value === 'mysql') {
            testBtn.innerHTML = '<span>✅ Sunucu Bağlantısı Başarılı</span>';
            testBtn.disabled = true;
            testBtn.classList.add('opacity-75');
        }
    } else {
        nextBtn.disabled = true;
        nextBtn.classList.add('opacity-50', 'cursor-not-allowed');
        nextBtn.classList.remove('btn-primary');
    }
}

// Database connection test
async function testDbConnection() {
    const testBtn = document.getElementById('test-db-btn');
    const testBtnText = document.getElementById('test-db-text');
    const spinner = document.getElementById('test-db-spinner');
    
    // Show loading state
    testBtn.disabled = true;
    testBtnText.textContent = 'Sunucu test ediliyor...';
    spinner.style.display = 'inline-block';
    testBtn.classList.add('opacity-75');
    
    // Collect form data
    const formData = new FormData();
    formData.append('action', 'test_db');
    formData.append('db_type', document.getElementById('db_type').value);
    
    if (document.getElementById('db_type').value === 'mysql') {
        const requiredFields = ['db_host', 'db_port', 'db_username'];
        const missingFields = [];
        
        requiredFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            const value = field.value.trim();
            if (!value) {
                missingFields.push(fieldId);
            }
            formData.append(fieldId, value);
        });
        
        if (missingFields.length > 0) {
            showToast('Lütfen tüm zorunlu alanları doldurun.', 'error');
            resetTestButton();
            return;
        }
        
        formData.append('db_password', document.getElementById('db_password').value);
    }
    
    try {
        const response = await fetch('process.php', {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        // Response text'i önce kontrol et
        const responseText = await response.text();
        console.log('Test DB response:', responseText);
        
        let result;
        try {
            result = JSON.parse(responseText);
        } catch (parseError) {
            console.error('JSON Parse Error:', parseError);
            console.error('Response Text:', responseText.substring(0, 500));
            throw new Error('Sunucudan geçersiz JSON response alındı. Response: ' + responseText.substring(0, 100));
        }
        
        if (result.success) {
            showToast('MySQL sunucu bağlantısı başarılı! Veritabanı otomatik oluşturulacak. ✅', 'success');
            dbConnectionTested = true;
            updateNextButton();
            
            // Success animation
            testBtn.innerHTML = '<span>✅ Sunucu Bağlantısı Başarılı</span>';
            testBtn.classList.remove('btn-secondary');
            testBtn.classList.add('bg-green-500', 'hover:bg-green-600');
        } else {
            throw new Error(result.message || 'Bilinmeyen hata');
        }
    } catch (error) {
        console.error('Database test error:', error);
        showToast('MySQL sunucu bağlantısı başarısız: ' + error.message, 'error');
        dbConnectionTested = false;
        updateNextButton();
        resetTestButton();
    }
}

function resetTestButton() {
    const testBtn = document.getElementById('test-db-btn');
    const testBtnText = document.getElementById('test-db-text');
    const spinner = document.getElementById('test-db-spinner');
    
    testBtn.disabled = false;
    testBtnText.textContent = '🔍 Bağlantıyı Test Et';
    spinner.style.display = 'none';
    testBtn.classList.remove('opacity-75');
}

// Migration process
async function startMigration() {
    const migrateBtn = document.getElementById('migrate-btn');
    const migrateText = document.getElementById('migrate-text');
    const migrateSpinner = document.getElementById('migrate-spinner');
    
    if (!migrateBtn || !migrateText || !migrateSpinner) return;
    
    // Show loading state
    migrateBtn.disabled = true;
    migrateText.textContent = 'Migration işlemi başlatılıyor...';
    migrateSpinner.style.display = 'inline-block';
    migrateBtn.classList.add('opacity-75');
    
    const formData = new FormData();
    formData.append('action', 'migrate');
    
    try {
        showToast('Migration işlemi başlatıldı...', 'info');
        
        const response = await fetch('process.php', {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        // Response text'i önce kontrol et
        const responseText = await response.text();
        console.log('Migration response:', responseText);
        
        let result;
        try {
            result = JSON.parse(responseText);
        } catch (parseError) {
            console.error('JSON Parse Error:', parseError);
            console.error('Response Text:', responseText.substring(0, 500));
            throw new Error('Sunucudan geçersiz JSON response alındı. Response: ' + responseText.substring(0, 100));
        }
        
        if (result.success) {
            showToast('Migration başarıyla tamamlandı! Sistem güncellenmiştir. 🎉', 'success');
            
            // Success state
            migrateText.textContent = '✅ Migration Tamamlandı';
            migrateBtn.classList.remove('btn-primary');
            migrateBtn.classList.add('bg-green-500', 'hover:bg-green-600');
            
            // Show system link
            setTimeout(() => {
                window.location.href = '../dashboard.php';
            }, 2000);
        } else {
            throw new Error(result.message || 'Migration başarısız');
        }
    } catch (error) {
        console.error('Migration error:', error);
        showToast('Migration sırasında hata oluştu: ' + error.message, 'error');
        
        // Reset button
        migrateBtn.disabled = false;
        migrateText.textContent = '🔄 Migration Çalıştır';
        migrateSpinner.style.display = 'none';
        migrateBtn.classList.remove('opacity-75');
    }
}

// Reinstall process
async function startReinstall() {
    const reinstallBtn = document.getElementById('reinstall-btn');
    const reinstallText = document.getElementById('reinstall-text');
    const reinstallSpinner = document.getElementById('reinstall-spinner');
    
    if (!reinstallBtn || !reinstallText || !reinstallSpinner) return;
    
    // Show loading state
    reinstallBtn.disabled = true;
    reinstallText.textContent = 'Yeniden kurulum başlatılıyor...';
    reinstallSpinner.style.display = 'inline-block';
    reinstallBtn.classList.add('opacity-75');
    
    const formData = new FormData();
    formData.append('action', 'reinstall');
    formData.append('confirm_code', 'YENIDEN_KUR_ONAYI'); // Onay kodu eklendi
    
    try {
        showToast('Mevcut sistem silinip yeniden kuruluyor...', 'warning');
        
        const response = await fetch('process.php', {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        // Response text'i önce kontrol et
        const responseText = await response.text();
        console.log('Reinstall response:', responseText);
        
        let result;
        try {
            result = JSON.parse(responseText);
        } catch (parseError) {
            console.error('JSON Parse Error:', parseError);
            console.error('Response Text:', responseText.substring(0, 500));
            throw new Error('Sunucudan geçersiz JSON response alındı. Response: ' + responseText.substring(0, 100));
        }
        
        if (result.success) {
            showToast('Sistem başarıyla temizlendi! Kurulum sihirbazı yeniden başlatılıyor...', 'success');
            
            // Reload page to restart installation
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            throw new Error(result.message || 'Yeniden kurulum başarısız');
        }
    } catch (error) {
        console.error('Reinstall error:', error);
        showToast('Yeniden kurulum sırasında hata oluştu: ' + error.message, 'error');
        
        // Reset button
        reinstallBtn.disabled = false;
        reinstallText.textContent = '🔄 Yeniden Kur';
        reinstallSpinner.style.display = 'none';
        reinstallBtn.classList.remove('opacity-75');
    }
}

// Installation process
async function startInstallation() {
    const installBtn = document.getElementById('install-btn');
    const installText = document.getElementById('install-text');
    const installSpinner = document.getElementById('install-spinner');
    const prevBtn = document.getElementById('install-prev-btn');
    
    // Check if sample data is enabled and ask for final confirmation
    const sampleDataCheckbox = document.getElementById('sample_data');
    if (sampleDataCheckbox && sampleDataCheckbox.checked) {
        const confirmSampleData = await showSampleDataConfirmation();
        if (!confirmSampleData) {
            // User decided not to load sample data
            sampleDataCheckbox.checked = false;
            toggleSampleDataInfo();
        }
    }
    
    // Disable buttons
    installBtn.disabled = true;
    prevBtn.disabled = true;
    installText.textContent = 'Kuruluyor...';
    installSpinner.style.display = 'inline-block';
    installBtn.classList.add('opacity-75');
    
    // Collect all form data
    const formData = new FormData();
    formData.append('action', 'install');
    
    try {
        // Validate all required fields
        if (!validateAllSteps()) {
            throw new Error('Form validation failed');
        }
        
        // Database settings (no database name needed)
        formData.append('db_type', document.getElementById('db_type').value);
        if (document.getElementById('db_type').value === 'mysql') {
            formData.append('db_host', document.getElementById('db_host').value);
            formData.append('db_port', document.getElementById('db_port').value);
            formData.append('db_username', document.getElementById('db_username').value);
            formData.append('db_password', document.getElementById('db_password').value);
        }
        
        // Admin settings
        formData.append('admin_name', document.getElementById('admin_name').value);
        formData.append('admin_surname', document.getElementById('admin_surname').value);
        formData.append('admin_email', document.getElementById('admin_email').value);
        formData.append('admin_password', document.getElementById('admin_password').value);
        
        // System settings
        formData.append('site_title', document.getElementById('site_title').value);
        formData.append('work_start', document.getElementById('work_start').value);
        formData.append('work_end', document.getElementById('work_end').value);
        formData.append('timezone', document.getElementById('timezone').value);
        formData.append('sample_data', document.getElementById('sample_data').checked ? '1' : '0');
        
        // Simulate installation steps with better timing (HTML'deki sıraya göre)
        await installStep('config', 'Yapılandırma dosyaları oluşturuluyor...', 1500);
        await installStep('database', 'Veritabanı oluşturuluyor ve tabloları oluşturuluyor...', 2500);
        await installStep('admin', 'Yönetici hesabı oluşturuluyor...', 1000);
        
        if (document.getElementById('sample_data').checked) {
            await installStep('sample', 'Test verileri yükleniyor (kullanıcılar, birimler, toplantılar)...', 2000);
        } else {
            // Update the step text to show it's being skipped
            const stepEl = document.querySelector('[data-step="sample"]');
            if (stepEl) {
                const textEl = stepEl.querySelector('span');
                if (textEl) textEl.textContent = 'Test verileri atlanıyor (boş sistem)...';
            }
            await installStep('sample', 'Test verileri atlanıyor (boş sistem)...', 500);
        }
        
        await installStep('security', 'Güvenlik ayarları yapılandırılıyor...', 1000);
        
        // Real installation request
        const response = await fetch('process.php', {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        // Response text'i önce kontrol et
        const responseText = await response.text();
        console.log('Install response:', responseText);
        
        let result;
        try {
            result = JSON.parse(responseText);
        } catch (parseError) {
            console.error('JSON Parse Error:', parseError);
            console.error('Response Text:', responseText.substring(0, 500));
            throw new Error('Sunucudan geçersiz JSON response alındı. Response: ' + responseText.substring(0, 100));
        }
        
        if (result.success) {
            showInstallationResult(true, result.data.admin_email, result.data.database_name);
            showToast('Kurulum başarıyla tamamlandı! Veritabanı otomatik oluşturuldu. 🎉', 'success');
        } else {
            throw new Error(result.message || 'Kurulum başarısız');
        }
    } catch (error) {
        console.error('Installation error:', error);
        showToast('Kurulum sırasında hata oluştu: ' + error.message, 'error');
        showInstallationResult(false);
        
        // Re-enable buttons
        installBtn.disabled = false;
        prevBtn.disabled = false;
        installText.textContent = '🚀 Kurulumu Başlat';
        installSpinner.style.display = 'none';
        installBtn.classList.remove('opacity-75');
    }
}

async function installStep(stepName, message, duration = 1500) {
    const stepEl = document.querySelector(`[data-step="${stepName}"]`);
    if (!stepEl) {
        console.warn(`Installation step element not found: ${stepName}`);
        return;
    }
    
    const numberEl = stepEl.querySelector('.step-number');
    const checkEl = stepEl.querySelector('.step-check');
    const textEl = stepEl.querySelector('span');
    const w8El = stepEl.querySelector('.w-8');
    
    // Show as active with animation
    stepEl.classList.add('active');
    if (w8El) w8El.style.transform = 'scale(1.1)';
    if (textEl) textEl.textContent = message;
    
    // Simulate processing time
    await new Promise(resolve => setTimeout(resolve, duration));
    
    // Mark as completed with animation
    markStepCompleted(stepName);
}

function markStepCompleted(stepName) {
    const stepEl = document.querySelector(`[data-step="${stepName}"]`);
    if (!stepEl) {
        console.warn(`Installation step element not found: ${stepName}`);
        return;
    }
    
    const numberEl = stepEl.querySelector('.step-number');
    const checkEl = stepEl.querySelector('.step-check');
    const w8El = stepEl.querySelector('.w-8');
    
    stepEl.classList.remove('active');
    stepEl.classList.add('completed');
    if (w8El) w8El.style.transform = 'scale(1)';
    
    // Animate completion
    setTimeout(() => {
        if (numberEl) numberEl.style.display = 'none';
        if (checkEl) checkEl.classList.remove('hidden');
    }, 200);
}

function showInstallationResult(success, adminEmail = '', databaseName = '') {
    const progressEl = document.getElementById('installation-progress');
    const resultEl = document.getElementById('installation-result');
    const installBtn = document.getElementById('install-btn');
    const finishBtn = document.getElementById('finish-btn');
    
    // Fade out progress
    progressEl.style.transition = 'opacity 0.5s ease';
    progressEl.style.opacity = '0';
    
    setTimeout(() => {
        progressEl.style.display = 'none';
        
        if (success) {
            resultEl.classList.remove('hidden');
            document.getElementById('result-admin-email').textContent = adminEmail;
            if (databaseName) {
                document.getElementById('result-database-name').textContent = databaseName;
            }
            installBtn.style.display = 'none';
            finishBtn.classList.remove('hidden');
            
            // Success animation
            resultEl.style.opacity = '0';
            resultEl.style.transform = 'translateY(20px)';
            setTimeout(() => {
                resultEl.style.transition = 'all 0.6s cubic-bezier(0.4, 0, 0.2, 1)';
                resultEl.style.opacity = '1';
                resultEl.style.transform = 'translateY(0)';
            }, 100);
        }
    }, 500);
}

// Enhanced form validation
function validateStep(step) {
    const rules = validationRules[`step${step}`];
    if (!rules) return true;
    
    let isValid = true;
    const errors = [];
    
    rules.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field && field.required && !field.value.trim()) {
            showFieldError(field, 'Bu alan zorunludur.');
            errors.push(fieldId);
            isValid = false;
        } else {
            clearFieldError(field);
        }
    });
    
    // Special validations
    if (step === 2) {
        isValid = validateDatabaseStep() && isValid;
    } else if (step === 3) {
        isValid = validateAdminStep() && isValid;
    }
    
    if (!isValid && errors.length > 0) {
        const firstErrorField = document.getElementById(errors[0]);
        if (firstErrorField) {
            firstErrorField.focus();
            firstErrorField.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
    
    return isValid;
}

function validateDatabaseStep() {
    const dbType = document.getElementById('db_type').value;
    
    if (dbType === 'mysql') {
        const host = document.getElementById('db_host').value.trim();
        const port = document.getElementById('db_port').value.trim();
        const username = document.getElementById('db_username').value.trim();
        
        if (!host || !port || !username) {
            showToast('Lütfen MySQL sunucu bilgilerini girin.', 'error');
            return false;
        }
        
        if (isNaN(port) || port < 1 || port > 65535) {
            showFieldError(document.getElementById('db_port'), 'Geçerli bir port numarası girin (1-65535).');
            return false;
        }
    }
    
    return true;
}

function validateAdminStep() {
    const password = document.getElementById('admin_password').value;
    const confirmPassword = document.getElementById('admin_password_confirm').value;
    const email = document.getElementById('admin_email').value;
    
    let isValid = true;
    
    if (password !== confirmPassword) {
        showFieldError(document.getElementById('admin_password_confirm'), 'Şifreler eşleşmiyor.');
        isValid = false;
    }
    
    if (password.length < 6) {
        showFieldError(document.getElementById('admin_password'), 'Şifre en az 6 karakter olmalıdır.');
        isValid = false;
    }
    
    if (email && !isValidEmail(email)) {
        showFieldError(document.getElementById('admin_email'), 'Geçerli bir e-posta adresi girin.');
        isValid = false;
    }
    
    return isValid;
}

function validateAllSteps() {
    for (let step = 2; step <= 4; step++) {
        if (!validateStep(step)) {
            showToast(`Adım ${step}'de hata var. Lütfen kontrol edin.`, 'error');
            return false;
        }
    }
    return true;
}

function showFieldError(field, message) {
    clearFieldError(field);
    
    field.classList.add('border-red-400', 'bg-red-50', 'bg-opacity-20');
    field.classList.remove('border-white', 'border-opacity-20');
    
    const errorEl = document.createElement('div');
    errorEl.className = 'text-red-300 text-sm mt-2 field-error animate-pulse';
    errorEl.innerHTML = `<i class="fas fa-exclamation-circle mr-1"></i>${message}`;
    
    field.parentNode.appendChild(errorEl);
    
    // Shake animation
    field.style.animation = 'shake 0.5s ease-in-out';
    setTimeout(() => {
        field.style.animation = '';
    }, 500);
}

function clearFieldError(field) {
    if (field) {
        field.classList.remove('border-red-400', 'bg-red-50', 'bg-opacity-20');
        field.classList.add('border-white', 'border-opacity-20');
        
        const errorEl = field.parentNode.querySelector('.field-error');
        if (errorEl) {
            errorEl.remove();
        }
    }
}

function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

// Enhanced toast notifications
function showToast(message, type = 'info') {
    // Remove existing toasts
    const existingToasts = document.querySelectorAll('.toast');
    existingToasts.forEach(toast => {
        toast.style.transform = 'translateX(400px)';
        setTimeout(() => toast.remove(), 300);
    });
    
    const toast = document.createElement('div');
    toast.className = `toast fixed top-6 right-6 px-6 py-4 rounded-xl shadow-2xl z-50 ${getToastClasses(type)} transform translate-x-full transition-all duration-500`;
    
    const icons = {
        success: '✅',
        error: '❌',
        warning: '⚠️',
        info: 'ℹ️'
    };
    
    toast.innerHTML = `
        <div class="flex items-center">
            <span class="text-xl mr-3">${icons[type]}</span>
            <span class="font-semibold">${message}</span>
            <button onclick="this.parentElement.parentElement.remove()" class="ml-4 hover:opacity-70 text-xl">&times;</button>
        </div>
    `;
    
    document.body.appendChild(toast);
    
    // Trigger animation
    setTimeout(() => {
        toast.classList.remove('translate-x-full');
    }, 100);
    
    // Auto remove
    setTimeout(() => {
        if (toast.parentNode) {
            toast.style.transform = 'translateX(400px)';
            setTimeout(() => toast.remove(), 300);
        }
    }, 5000);
}

function getToastClasses(type) {
    const baseClasses = 'backdrop-blur-lg border border-opacity-20';
    switch (type) {
        case 'success':
            return baseClasses + ' bg-green-500 bg-opacity-90 text-white border-green-300';
        case 'error':
            return baseClasses + ' bg-red-500 bg-opacity-90 text-white border-red-300';
        case 'warning':
            return baseClasses + ' bg-yellow-500 bg-opacity-90 text-white border-yellow-300';
        default:
            return baseClasses + ' bg-blue-500 bg-opacity-90 text-white border-blue-300';
    }
}

// Real-time validation
document.addEventListener('input', function(e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT') {
        clearFieldError(e.target);
        
        // Auto-update database name when fields change
        if (e.target.id === 'db_host' || e.target.id === 'db_port') {
            updateAutoDbName();
        }
        
        // Real-time email validation
        if (e.target.type === 'email' && e.target.value) {
            if (!isValidEmail(e.target.value)) {
                showFieldError(e.target, 'Geçerli bir e-posta adresi girin.');
            }
        }
        
        // Real-time password confirmation
        if (e.target.id === 'admin_password_confirm') {
            const password = document.getElementById('admin_password').value;
            if (e.target.value && e.target.value !== password) {
                showFieldError(e.target, 'Şifreler eşleşmiyor.');
            }
        }
    }
});

// Prevent form submission
document.getElementById('installationForm').addEventListener('submit', function(e) {
    e.preventDefault();
});

// Add CSS animations
const style = document.createElement('style');
style.textContent = `
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        25% { transform: translateX(-5px); }
        75% { transform: translateX(5px); }
    }
`;
document.head.appendChild(style);

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        const activeStep = document.querySelector('.form-step.active');
        if (activeStep) {
            const nextButton = activeStep.querySelector('button[onclick="nextStep()"]');
            if (nextButton && !nextButton.disabled) {
                nextStep();
            }
        }
    }
});

// Update auto database name every second for real-time effect
setInterval(updateAutoDbName, 1000);

// Sample data confirmation modal
function showSampleDataConfirmation() {
    return new Promise((resolve) => {
        // Create modal HTML
        const modalHTML = `
            <div id="sample-data-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="backdrop-filter: blur(5px);">
                <div class="glass-card rounded-3xl p-8 max-w-lg w-full mx-4 relative">
                    <div class="text-center">
                        <div class="w-16 h-16 mx-auto mb-4 bg-blue-500 rounded-full flex items-center justify-center">
                            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-bold text-white mb-4">Test Verilerini Yükle?</h3>
                        <p class="text-white opacity-80 mb-6 leading-relaxed">
                            Sistemi hemen test etmek için örnek kullanıcılar, birimler ve toplantılar yüklensin mi?
                        </p>
                        
                        <div class="bg-white bg-opacity-10 rounded-2xl p-4 mb-6 text-left">
                            <div class="grid grid-cols-2 gap-4 text-sm">
                                <div class="text-center">
                                    <div class="text-green-300 font-semibold mb-1">✅ Evet Yükle</div>
                                    <ul class="text-white opacity-70 text-xs space-y-1">
                                        <li>• Hemen test edebilirsiniz</li>
                                        <li>• 4 test kullanıcısı</li>
                                        <li>• 4 birim, 27 toplantı</li>
                                        <li>• 3 Zoom hesabı</li>
                                    </ul>
                                </div>
                                <div class="text-center">
                                    <div class="text-orange-300 font-semibold mb-1">⭕ Hayır, Boş Başla</div>
                                    <ul class="text-white opacity-70 text-xs space-y-1">
                                        <li>• Temiz kurulum</li>
                                        <li>• Sadece admin hesabı</li>
                                        <li>• Kendi verilerinizi ekleyin</li>
                                        <li>• Daha sonra test verisi ekleyebilirsiniz</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex space-x-4">
                            <button type="button" class="bg-white bg-opacity-20 hover:bg-opacity-30 text-white px-6 py-3 rounded-xl font-semibold transition-all flex-1" onclick="resolveSampleDataModal(false)">
                                Hayır, Boş Başla
                            </button>
                            <button type="button" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-xl font-semibold transition-all flex-1" onclick="resolveSampleDataModal(true)">
                                Evet, Test Verileri Yükle
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Add modal to page
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        
        // Animate modal in
        const modal = document.getElementById('sample-data-modal');
        const modalContent = modal.querySelector('.glass-card');
        modal.style.opacity = '0';
        modalContent.style.transform = 'scale(0.9) translateY(20px)';
        
        setTimeout(() => {
            modal.style.transition = 'opacity 0.3s ease';
            modalContent.style.transition = 'all 0.3s ease';
            modal.style.opacity = '1';
            modalContent.style.transform = 'scale(1) translateY(0)';
        }, 10);
        
        // Store resolve function globally so onclick can access it
        window.resolveSampleDataModal = (choice) => {
            // Animate modal out
            modal.style.opacity = '0';
            modalContent.style.transform = 'scale(0.9) translateY(20px)';
            
            setTimeout(() => {
                document.body.removeChild(modal);
                delete window.resolveSampleDataModal;
                resolve(choice);
            }, 300);
        };
    });
}