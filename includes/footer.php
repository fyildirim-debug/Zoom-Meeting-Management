    </div> <!-- End of flex wrapper from header -->

    <!-- Footer -->
    <footer class="bg-white border-t border-gray-200 mt-auto">
        <div class="max-w-7xl mx-auto px-4 py-4">
            <div class="flex flex-col md:flex-row justify-between items-center text-sm text-gray-500">
                <div>
                    © <?php echo date('Y'); ?> Zoom Meeting Management System
                </div>
                
                <div class="flex items-center space-x-4 mt-2 md:mt-0">
                    <!-- Version Info -->
                    <div>
                        v1.0.0
                    </div>
                    
                    <!-- Server Time -->
                    <div id="server-time">
                        <?php echo date('d.m.Y H:i'); ?>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <!-- Back to Top Button -->
    <button 
        id="back-to-top" 
        class="fixed bottom-6 right-6 w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 text-white rounded-full shadow-lg hover:shadow-xl transform hover:scale-110 transition-all duration-300 opacity-0 invisible z-50"
        onclick="scrollToTop()"
    >
        <i class="fas fa-arrow-up"></i>
    </button>

    <!-- Toast Container -->
    <div id="toast-container" class="fixed top-20 right-4 z-[9999] space-y-2"></div>

    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-[9998] hidden items-center justify-center">
        <div class="bg-white rounded-lg p-8 flex flex-col items-center space-y-4">
            <div class="loading-spinner w-8 h-8"></div>
            <p class="text-gray-700">Yükleniyor...</p>
        </div>
    </div>

    <!-- Global Modal Container -->
    <div id="modal-container"></div>

    <!-- Core JavaScript -->
    <script>
        // Global Variables
        window.APP_CONFIG = {
            name: '<?php echo APP_NAME; ?>',
            user: <?php echo json_encode(getCurrentUser()); ?>,
            csrf_token: '<?php echo generateCSRFToken(); ?>',
            timezone: '<?php echo APP_TIMEZONE; ?>',
            work_hours: {
                start: '<?php echo WORK_START; ?>',
                end: '<?php echo WORK_END; ?>'
            }
        };

        // Light theme only - no theme management needed

        // Dropdown Management
        function toggleDropdown(dropdownId) {
            const dropdown = document.querySelector(`#${dropdownId}-dropdown`).parentElement;
            const allDropdowns = document.querySelectorAll('.dropdown');
            
            // Close all other dropdowns
            allDropdowns.forEach(d => {
                if (d !== dropdown) {
                    d.classList.remove('active');
                }
            });
            
            // Toggle current dropdown
            dropdown.classList.toggle('active');
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.dropdown')) {
                document.querySelectorAll('.dropdown').forEach(d => {
                    d.classList.remove('active');
                });
            }
        });

        // Toast Notifications
        function showToast(message, type = 'info', duration = 5000) {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            
            const icons = {
                success: 'fas fa-check-circle',
                error: 'fas fa-exclamation-circle',
                warning: 'fas fa-exclamation-triangle',
                info: 'fas fa-info-circle'
            };
            
            toast.className = `toast toast-${type} flex items-center p-4 rounded-lg shadow-lg transform translate-x-full transition-all duration-300`;
            toast.innerHTML = `
                <i class="${icons[type]} mr-3"></i>
                <span class="flex-1">${message}</span>
                <button onclick="this.parentElement.remove()" class="ml-3 hover:opacity-70">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            container.appendChild(toast);
            
            // Trigger animation
            setTimeout(() => {
                toast.classList.remove('translate-x-full');
            }, 100);
            
            // Auto remove
            setTimeout(() => {
                toast.classList.add('translate-x-full');
                setTimeout(() => {
                    if (toast.parentElement) {
                        toast.remove();
                    }
                }, 300);
            }, duration);
        }

        // Loading Overlay
        function showLoading(show = true) {
            const overlay = document.getElementById('loading-overlay');
            if (show) {
                overlay.classList.remove('hidden');
                overlay.classList.add('flex');
            } else {
                overlay.classList.add('hidden');
                overlay.classList.remove('flex');
            }
        }

        // Back to Top Button
        function scrollToTop() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }

        // Show/hide back to top button based on scroll
        window.addEventListener('scroll', function() {
            const button = document.getElementById('back-to-top');
            if (window.pageYOffset > 300) {
                button.classList.remove('opacity-0', 'invisible');
                button.classList.add('opacity-100', 'visible');
            } else {
                button.classList.add('opacity-0', 'invisible');
                button.classList.remove('opacity-100', 'visible');
            }
        });

        // Real-time Server Time Update
        function updateServerTime() {
            const timeElement = document.getElementById('server-time');
            if (timeElement) {
                const now = new Date();
                const options = {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    timeZone: window.APP_CONFIG.timezone
                };
                timeElement.textContent = now.toLocaleDateString('tr-TR', options);
            }
        }

        // AJAX Helper Functions
        function makeRequest(url, options = {}) {
            const defaultOptions = {
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.APP_CONFIG.csrf_token
                }
            };
            
            return fetch(url, { ...defaultOptions, ...options })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                });
        }

        // Form Validation Helpers
        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }

        function validateTime(time) {
            const re = /^([01]?[0-9]|2[0-3]):[0-5][0-9]$/;
            return re.test(time);
        }

        function validateDate(date) {
            const d = new Date(date);
            return d instanceof Date && !isNaN(d);
        }

        // Global Search
        function initializeGlobalSearch() {
            const searchInput = document.getElementById('global-search');
            if (searchInput) {
                let searchTimeout;
                
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    const query = this.value.trim();
                    
                    if (query.length >= 2) {
                        searchTimeout = setTimeout(() => {
                            performGlobalSearch(query);
                        }, 500);
                    }
                });
            }
        }

        function performGlobalSearch(query) {
            // Admin sayfalarından tam URL kullan
            const baseUrl = window.location.protocol + '//' + window.location.host;
            const currentPath = window.location.pathname;
            let apiPath;
            
            if (currentPath.includes('/admin/')) {
                // Admin sayfasından ana dizine git
                const pathParts = currentPath.split('/');
                const basePath = pathParts.slice(0, -2).join('/'); // admin/ dan önceki kısım
                apiPath = baseUrl + basePath + '/api/';
            } else {
                apiPath = 'api/';
            }
            
            fetch(`${apiPath}search-meetings.php?q=${encodeURIComponent(query)}`)
            .then(data => {
                if (data.success) {
                    displaySearchResults(data.results);
                }
            })
            .catch(error => {
                console.error('Search error:', error);
            });
        }

        // Keyboard Shortcuts
        function initializeKeyboardShortcuts() {
            document.addEventListener('keydown', function(e) {
                // Ctrl+K for global search
                if (e.ctrlKey && e.key === 'k') {
                    e.preventDefault();
                    const searchInput = document.getElementById('global-search');
                    if (searchInput) {
                        searchInput.focus();
                    }
                }
                
                // Ctrl+N for new meeting
                if (e.ctrlKey && e.key === 'n') {
                    e.preventDefault();
                    window.location.href = 'new-meeting.php';
                }
                
                // Ctrl+D for dashboard
                if (e.ctrlKey && e.key === 'd') {
                    e.preventDefault();
                    window.location.href = 'dashboard.php';
                }
                
                // Escape to close modals and dropdowns
                if (e.key === 'Escape') {
                    document.querySelectorAll('.dropdown').forEach(d => {
                        d.classList.remove('active');
                    });
                    
                    const modals = document.querySelectorAll('.modal');
                    modals.forEach(modal => {
                        if (modal.style.display !== 'none') {
                            closeModal(modal.id);
                        }
                    });
                }
            });
        }

        // Initialize everything when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            initializeGlobalSearch();
            initializeKeyboardShortcuts();
            
            // Update server time every minute
            setInterval(updateServerTime, 60000);
            
            // Check for updates every 5 minutes
            setInterval(checkForUpdates, 5 * 60 * 1000);
        });

        // Check for system updates
        function checkForUpdates() {
            makeRequest('api/check-updates.php')
                .then(data => {
                    if (data.hasUpdates) {
                        showToast('Sistem güncellemesi mevcut!', 'info');
                    }
                })
                .catch(error => {
                    console.error('Update check error:', error);
                });
        }

        // Error Handler
        window.addEventListener('error', function(e) {
            console.error('Global error:', e.error);
            
            // Send error to server for logging
            makeRequest('api/log-error.php', {
                method: 'POST',
                body: JSON.stringify({
                    message: e.error.message,
                    filename: e.filename,
                    lineno: e.lineno,
                    url: window.location.href
                })
            }).catch(console.error);
        });

        // Unhandled Promise Rejection Handler
        window.addEventListener('unhandledrejection', function(e) {
            console.error('Unhandled promise rejection:', e.reason);
            
            makeRequest('api/log-error.php', {
                method: 'POST',
                body: JSON.stringify({
                    message: 'Unhandled promise rejection: ' + e.reason,
                    url: window.location.href
                })
            }).catch(console.error);
        });

        // Simple and Reliable Modal System (like my-meetings.php)
        function createModal(config) {
            const {
                id,
                title,
                content,
                size = 'md'
            } = config;

            const sizeClasses = {
                sm: 'max-w-sm',
                md: 'max-w-md',
                lg: 'max-w-2xl',
                xl: 'max-w-4xl',
                full: 'max-w-6xl'
            };

            const modalHTML = `
                <div id="${id}" class="hidden fixed inset-0 bg-black bg-opacity-50 z-[9999] flex items-center justify-center p-4">
                    <div class="bg-white rounded-xl shadow-2xl ${sizeClasses[size]} w-full max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
                        <div class="p-6 border-b border-gray-200 flex items-center justify-between">
                            <h3 class="text-xl font-semibold text-gray-900">${title}</h3>
                            <button type="button" onclick="closeModal('${id}')" class="text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times text-xl"></i>
                            </button>
                        </div>
                        <div class="p-6">
                            ${content}
                        </div>
                    </div>
                </div>
            `;

            const container = document.getElementById('modal-container') || document.body;
            container.insertAdjacentHTML('beforeend', modalHTML);

            // Add event listeners
            const modal = document.getElementById(id);
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeModal(id);
                }
            });

            return modal;
        }

        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
                
                // Focus management
                const firstInput = modal.querySelector('input, textarea, select, button');
                if (firstInput) {
                    setTimeout(() => firstInput.focus(), 100);
                }
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('hidden');
                document.body.style.overflow = '';
                
                // Remove from DOM after a short delay
                setTimeout(() => {
                    if (modal && modal.parentNode) {
                        modal.remove();
                    }
                }, 100);
            }
        }

        // Simple Confirm Dialog (like my-meetings.php)
        function confirmAction(config) {
            const {
                title = 'Emin misiniz?',
                message = 'Bu işlemi gerçekleştirmek istediğinizden emin misiniz?',
                type = 'warning',
                confirmText = 'Evet',
                cancelText = 'İptal',
                onConfirm = () => {},
                onCancel = () => {}
            } = config;

            const icons = {
                warning: 'fas fa-exclamation-triangle',
                danger: 'fas fa-exclamation-triangle',
                success: 'fas fa-check-circle',
                info: 'fas fa-info-circle'
            };

            const colors = {
                warning: 'bg-yellow-100 text-yellow-600',
                danger: 'bg-red-100 text-red-600',
                success: 'bg-green-100 text-green-600',
                info: 'bg-blue-100 text-blue-600'
            };

            const buttonColors = {
                warning: 'bg-yellow-600 hover:bg-yellow-700',
                danger: 'bg-red-600 hover:bg-red-700',
                success: 'bg-green-600 hover:bg-green-700',
                info: 'bg-blue-600 hover:bg-blue-700'
            };

            const modalId = 'confirm-modal-' + Date.now();
            
            const modalHTML = `
                <div id="${modalId}" class="fixed inset-0 bg-black bg-opacity-50 z-[9999] flex items-center justify-center p-4">
                    <div class="bg-white rounded-xl shadow-2xl max-w-md w-full">
                        <div class="p-6">
                            <div class="flex items-center mb-4">
                                <div class="w-12 h-12 ${colors[type]} rounded-full flex items-center justify-center mr-4">
                                    <i class="${icons[type]} text-xl"></i>
                                </div>
                                <h3 class="text-lg font-semibold text-gray-900">${title}</h3>
                            </div>
                            <p class="text-gray-600 mb-6">${message}</p>
                            <div class="flex space-x-3 justify-end">
                                <button id="confirm-cancel-${modalId}" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300 transition-colors">
                                    ${cancelText}
                                </button>
                                <button id="confirm-ok-${modalId}" class="px-4 py-2 text-white ${buttonColors[type]} rounded-lg transition-colors">
                                    ${confirmText}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', modalHTML);
            
            const modal = document.getElementById(modalId);
            const cancelBtn = document.getElementById(`confirm-cancel-${modalId}`);
            const okBtn = document.getElementById(`confirm-ok-${modalId}`);
            
            function closeConfirmModal() {
                modal.remove();
            }
            
            cancelBtn.addEventListener('click', function() {
                closeConfirmModal();
                onCancel();
            });
            
            okBtn.addEventListener('click', function() {
                closeConfirmModal();
                onConfirm();
            });
            
            // ESC tuşu ile kapat
            modal.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeConfirmModal();
                    onCancel();
                }
            });
            
            // Modal dışına tıklayınca kapat
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeConfirmModal();
                    onCancel();
                }
            });
            
            // Focus confirm button
            okBtn.focus();
        }

        // Form Modal Helper
        function createFormModal(config) {
            const {
                id,
                title,
                fields = [],
                submitText = 'Kaydet',
                cancelText = 'İptal',
                onSubmit = () => {},
                onCancel = () => {},
                data = {}
            } = config;

            let formHTML = '<form id="' + id + '-form" class="space-y-4">';
            
            fields.forEach(field => {
                const {
                    name,
                    label,
                    type = 'text',
                    required = false,
                    options = [],
                    placeholder = '',
                    value = data[name] || ''
                } = field;

                formHTML += `<div>`;
                formHTML += `<label class="form-label">${escapeHtml(label)} ${required ? '<span class="text-red-500">*</span>' : ''}</label>`;
                
                if (type === 'select') {
                    formHTML += `<select name="${escapeHtml(name)}" class="form-select" ${required ? 'required' : ''}>`;
                    if (placeholder) {
                        formHTML += `<option value="">${escapeHtml(placeholder)}</option>`;
                    }
                    options.forEach(option => {
                        const selected = option.value == value ? 'selected' : '';
                        formHTML += `<option value="${escapeHtml(option.value)}" ${selected}>${escapeHtml(option.text)}</option>`;
                    });
                    formHTML += `</select>`;
                } else if (type === 'textarea') {
                    formHTML += `<textarea name="${escapeHtml(name)}" class="form-textarea" placeholder="${escapeHtml(placeholder)}" ${required ? 'required' : ''}>${escapeHtml(value)}</textarea>`;
                } else {
                    formHTML += `<input type="${escapeHtml(type)}" name="${escapeHtml(name)}" class="form-input" placeholder="${escapeHtml(placeholder)}" value="${escapeHtml(value)}" ${required ? 'required' : ''}>`;
                }
                
                formHTML += `</div>`;
            });

            formHTML += `
                <div class="modal-footer">
                    <button type="button" class="btn-outline" onclick="closeModal('${id}')">
                        ${escapeHtml(cancelText)}
                    </button>
                    <button type="submit" class="btn-primary">
                        ${escapeHtml(submitText)}
                    </button>
                </div>
            `;
            formHTML += '</form>';

            const modal = createModal({
                id: id,
                title: title,
                content: formHTML,
                size: 'md'
            });

            // Handle form submission
            const form = document.getElementById(id + '-form');
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(form);
                const data = Object.fromEntries(formData.entries());
                
                onSubmit(data, form);
            });

            openModal(id);
            return modal;
        }

        // HTML Escape Function
        function escapeHtml(unsafe) {
            if (typeof unsafe !== 'string') return unsafe;
            return unsafe
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        // Update existing confirm calls to use new system
        window.confirm = function(message) {
            return new Promise((resolve) => {
                confirmAction({
                    message: message,
                    onConfirm: () => resolve(true),
                    onCancel: () => resolve(false)
                });
            });
        };

        // Replace alert with toast
        window.alert = function(message) {
            showToast(message, 'info');
        };

        // Global Search Functionality
        let searchTimeout;
        let mobileSearchTimeout;
        const searchInput = document.getElementById('global-search');
        const searchResults = document.getElementById('search-results');
        const searchLoading = document.getElementById('search-loading');
        const searchNoResults = document.getElementById('search-no-results');
        const searchResultsList = document.getElementById('search-results-list');
        
        // Mobile search elements
        const mobileSearchInput = document.getElementById('mobile-global-search');
        const mobileSearchResults = document.getElementById('mobile-search-results');
        const mobileSearchLoading = document.getElementById('mobile-search-loading');
        const mobileSearchNoResults = document.getElementById('mobile-search-no-results');
        const mobileSearchResultsList = document.getElementById('mobile-search-results-list');

        if (searchInput) {
            // Search input event listener
            searchInput.addEventListener('input', function() {
                const query = this.value.trim();
                
                clearTimeout(searchTimeout);
                
                if (query.length < 2) {
                    hideSearchResults();
                    return;
                }
                
                showSearchLoading();
                
                searchTimeout = setTimeout(() => {
                    performSearch(query);
                }, 300); // 300ms debounce
            });

            // Hide results when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('#global-search') && !e.target.closest('#search-results')) {
                    hideSearchResults();
                }
            });

            // Handle keyboard navigation
            searchInput.addEventListener('keydown', function(e) {
                const items = searchResults.querySelectorAll('.search-result-item');
                let currentIndex = Array.from(items).findIndex(item => 
                    item.classList.contains('bg-gray-50')
                );

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    currentIndex = currentIndex < items.length - 1 ? currentIndex + 1 : 0;
                    highlightSearchItem(items, currentIndex);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    currentIndex = currentIndex > 0 ? currentIndex - 1 : items.length - 1;
                    highlightSearchItem(items, currentIndex);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (currentIndex >= 0 && items[currentIndex]) {
                        items[currentIndex].click();
                    }
                } else if (e.key === 'Escape') {
                    hideSearchResults();
                    searchInput.blur();
                }
            });
        }

        // Mobile search functionality
        if (mobileSearchInput) {
            // Mobile search input event listener
            mobileSearchInput.addEventListener('input', function() {
                const query = this.value.trim();
                
                clearTimeout(mobileSearchTimeout);
                
                if (query.length < 2) {
                    hideMobileSearchResults();
                    return;
                }
                
                showMobileSearchLoading();
                
                mobileSearchTimeout = setTimeout(() => {
                    performMobileSearch(query);
                }, 300); // 300ms debounce
            });

            // Hide results when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('#mobile-global-search') && !e.target.closest('#mobile-search-results')) {
                    hideMobileSearchResults();
                }
            });

            // Handle keyboard navigation for mobile
            mobileSearchInput.addEventListener('keydown', function(e) {
                const items = mobileSearchResults.querySelectorAll('.search-result-item');
                let currentIndex = Array.from(items).findIndex(item => 
                    item.classList.contains('bg-gray-50')
                );

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    currentIndex = currentIndex < items.length - 1 ? currentIndex + 1 : 0;
                    highlightSearchItem(items, currentIndex);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    currentIndex = currentIndex > 0 ? currentIndex - 1 : items.length - 1;
                    highlightSearchItem(items, currentIndex);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (currentIndex >= 0 && items[currentIndex]) {
                        items[currentIndex].click();
                    }
                } else if (e.key === 'Escape') {
                    hideMobileSearchResults();
                    mobileSearchInput.blur();
                }
            });
        }

        // Toggle mobile search
        function toggleMobileSearch() {
            const mobileSearch = document.getElementById('mobile-search');
            mobileSearch.classList.toggle('hidden');
            
            if (!mobileSearch.classList.contains('hidden')) {
                mobileSearchInput.focus();
            } else {
                hideMobileSearchResults();
                mobileSearchInput.value = '';
            }
        }

        function performSearch(query) {
            // Admin sayfalarından tam URL kullan
            const baseUrl = window.location.protocol + '//' + window.location.host;
            const currentPath = window.location.pathname;
            let apiPath;
            
            if (currentPath.includes('/admin/')) {
                // Admin sayfasından ana dizine git
                const pathParts = currentPath.split('/');
                const basePath = pathParts.slice(0, -2).join('/'); // admin/ dan önceki kısım
                apiPath = baseUrl + basePath + '/api/';
            } else {
                apiPath = 'api/';
            }
            
            fetch(`${apiPath}search-meetings.php?q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    searchLoading.classList.add('hidden');
                    
                    if (data.success) {
                        displaySearchResults(data.meetings, data.query);
                    } else {
                        showToast(data.message || 'Arama sırasında hata oluştu', 'error');
                        hideSearchResults();
                    }
                })
                .catch(error => {
                    searchLoading.classList.add('hidden');
                    showToast('Arama sırasında hata oluştu', 'error');
                    console.error('Search error:', error);
                });
        }

        function displaySearchResults(meetings, query) {
            if (meetings.length === 0) {
                showSearchNoResults();
                return;
            }

            searchResultsList.innerHTML = '';
            
            meetings.forEach((meeting, index) => {
                const resultItem = document.createElement('div');
                resultItem.className = 'search-result-item p-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0';
                
                // Status badge color
                const statusColors = {
                    'green': 'bg-green-100 text-green-800',
                    'orange': 'bg-orange-100 text-orange-800',
                    'red': 'bg-red-100 text-red-800',
                    'gray': 'bg-gray-100 text-gray-800'
                };
                
                resultItem.innerHTML = `
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <h4 class="font-medium text-gray-900 text-sm line-clamp-1">${escapeHtml(meeting.title)}</h4>
                            <div class="flex items-center space-x-2 mt-1">
                                <span class="text-xs text-gray-500">
                                    <i class="far fa-calendar mr-1"></i>
                                    ${meeting.formatted_date}
                                </span>
                                <span class="text-xs text-gray-500">
                                    <i class="far fa-clock mr-1"></i>
                                    ${meeting.formatted_start_time} - ${meeting.formatted_end_time}
                                </span>
                            </div>
                            ${meeting.department_name ? `
                                <div class="text-xs text-gray-400 mt-1">
                                    <i class="fas fa-building mr-1"></i>
                                    ${escapeHtml(meeting.department_name)}
                                </div>
                            ` : ''}
                        </div>
                        <div class="ml-3 flex-shrink-0">
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${statusColors[meeting.status_color] || 'bg-gray-100 text-gray-800'}">
                                ${meeting.status_text}
                            </span>
                        </div>
                    </div>
                `;
                
                resultItem.addEventListener('click', function() {
                    // Admin sayfasından ana dizine çık
                    const currentPath = window.location.pathname;
                    let detailsUrl;
                    
                    if (currentPath.includes('/admin/')) {
                        // Admin sayfasından ana dizine git
                        const pathParts = currentPath.split('/');
                        const basePath = pathParts.slice(0, -2).join('/'); // admin/ dan önceki kısım
                        detailsUrl = basePath + '/meeting-details.php?id=' + meeting.id;
                    } else {
                        detailsUrl = 'meeting-details.php?id=' + meeting.id;
                    }
                    
                    window.location.href = detailsUrl;
                });
                
                searchResultsList.appendChild(resultItem);
            });
            
            showSearchResults();
        }

        function highlightSearchItem(items, index) {
            items.forEach((item, i) => {
                if (i === index) {
                    item.classList.add('bg-gray-50');
                } else {
                    item.classList.remove('bg-gray-50');
                }
            });
        }

        function showSearchResults() {
            searchResults.classList.remove('hidden');
            searchLoading.classList.add('hidden');
            searchNoResults.classList.add('hidden');
        }

        function hideSearchResults() {
            searchResults.classList.add('hidden');
            searchLoading.classList.add('hidden');
            searchNoResults.classList.add('hidden');
        }

        function showSearchLoading() {
            searchResults.classList.remove('hidden');
            searchLoading.classList.remove('hidden');
            searchNoResults.classList.add('hidden');
            searchResultsList.innerHTML = '';
        }

        function showSearchNoResults() {
            searchResults.classList.remove('hidden');
            searchLoading.classList.add('hidden');
            searchNoResults.classList.remove('hidden');
            searchResultsList.innerHTML = '';
        }

        // Mobile search functions
        function performMobileSearch(query) {
            // Admin sayfalarından tam URL kullan
            const baseUrl = window.location.protocol + '//' + window.location.host;
            const currentPath = window.location.pathname;
            let apiPath;
            
            if (currentPath.includes('/admin/')) {
                // Admin sayfasından ana dizine git
                const pathParts = currentPath.split('/');
                const basePath = pathParts.slice(0, -2).join('/'); // admin/ dan önceki kısım
                apiPath = baseUrl + basePath + '/api/';
            } else {
                apiPath = 'api/';
            }
            
            fetch(`${apiPath}search-meetings.php?q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    hideMobileSearchLoading();
                    
                    if (data.success) {
                        displayMobileSearchResults(data.meetings, data.query);
                    } else {
                        showToast(data.message || 'Arama sırasında hata oluştu', 'error');
                        hideMobileSearchResults();
                    }
                })
                .catch(error => {
                    hideMobileSearchLoading();
                    showToast('Arama sırasında hata oluştu', 'error');
                    console.error('Mobile search error:', error);
                });
        }

        function displayMobileSearchResults(meetings, query) {
            if (meetings.length === 0) {
                showMobileSearchNoResults();
                return;
            }

            mobileSearchResultsList.innerHTML = '';
            
            meetings.forEach((meeting, index) => {
                const resultItem = document.createElement('div');
                resultItem.className = 'search-result-item p-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0';
                
                // Status badge color
                const statusColors = {
                    'green': 'bg-green-100 text-green-800',
                    'orange': 'bg-orange-100 text-orange-800',
                    'red': 'bg-red-100 text-red-800',
                    'gray': 'bg-gray-100 text-gray-800'
                };
                
                resultItem.innerHTML = `
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <h4 class="font-medium text-gray-900 text-sm line-clamp-1">${escapeHtml(meeting.title)}</h4>
                            <div class="flex items-center space-x-2 mt-1">
                                <span class="text-xs text-gray-500">
                                    <i class="far fa-calendar mr-1"></i>
                                    ${meeting.formatted_date}
                                </span>
                                <span class="text-xs text-gray-500">
                                    <i class="far fa-clock mr-1"></i>
                                    ${meeting.formatted_start_time} - ${meeting.formatted_end_time}
                                </span>
                            </div>
                            ${meeting.department_name ? `
                                <div class="text-xs text-gray-400 mt-1">
                                    <i class="fas fa-building mr-1"></i>
                                    ${escapeHtml(meeting.department_name)}
                                </div>
                            ` : ''}
                        </div>
                        <div class="ml-3 flex-shrink-0">
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${statusColors[meeting.status_color] || 'bg-gray-100 text-gray-800'}">
                                ${meeting.status_text}
                            </span>
                        </div>
                    </div>
                `;
                
                resultItem.addEventListener('click', function() {
                    // Admin sayfasından ana dizine çık
                    const currentPath = window.location.pathname;
                    let detailsUrl;
                    
                    if (currentPath.includes('/admin/')) {
                        // Admin sayfasından ana dizine git
                        const pathParts = currentPath.split('/');
                        const basePath = pathParts.slice(0, -2).join('/'); // admin/ dan önceki kısım
                        detailsUrl = basePath + '/meeting-details.php?id=' + meeting.id;
                    } else {
                        detailsUrl = 'meeting-details.php?id=' + meeting.id;
                    }
                    
                    window.location.href = detailsUrl;
                });
                
                mobileSearchResultsList.appendChild(resultItem);
            });
            
            showMobileSearchResults();
        }

        function showMobileSearchResults() {
            mobileSearchResults.classList.remove('hidden');
            mobileSearchLoading.classList.add('hidden');
            mobileSearchNoResults.classList.add('hidden');
        }

        function hideMobileSearchResults() {
            mobileSearchResults.classList.add('hidden');
            mobileSearchLoading.classList.add('hidden');
            mobileSearchNoResults.classList.add('hidden');
        }

        function showMobileSearchLoading() {
            mobileSearchResults.classList.remove('hidden');
            mobileSearchLoading.classList.remove('hidden');
            mobileSearchNoResults.classList.add('hidden');
            mobileSearchResultsList.innerHTML = '';
        }

        function hideMobileSearchLoading() {
            mobileSearchLoading.classList.add('hidden');
        }

        function showMobileSearchNoResults() {
            mobileSearchResults.classList.remove('hidden');
            mobileSearchLoading.classList.add('hidden');
            mobileSearchNoResults.classList.remove('hidden');
            mobileSearchResultsList.innerHTML = '';
        }
    </script>

    <!-- Page specific scripts -->
    <?php if (isset($additionalScripts)): ?>
        <?php echo $additionalScripts; ?>
    <?php endif; ?>
</body>
</html>