// Скрипт для управления ролями и правами
document.addEventListener('DOMContentLoaded', function() {
    console.log('Страница ролей загружена');

    // Инициализация управления разрешениями
    initPermissionsManagement();

    // Инициализация форм
    initForms();
});

function initPermissionsManagement() {
    // Обработка "Выбрать все"
    const selectAllCheckbox = document.getElementById('select-all-permissions');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            const allPermissions = document.querySelectorAll('input[name="permissions[]"]');
            allPermissions.forEach(permission => {
                permission.checked = this.checked;
            });

            // Также обновляем выбор категорий
            const categorySelects = document.querySelectorAll('.category-select');
            categorySelects.forEach(select => {
                select.checked = this.checked;
            });
        });
    }

    // Обработка выбора категорий
    const categorySelects = document.querySelectorAll('.category-select');
    categorySelects.forEach(select => {
        select.addEventListener('change', function() {
            const category = this.dataset.category;
            const categoryPermissions = document.querySelectorAll(`input[name="permissions[]"][data-category="${category}"]`);
            categoryPermissions.forEach(permission => {
                permission.checked = this.checked;
            });

            updateSelectAllState();
        });
    });

    // Обработка выбора отдельных разрешений
    const permissionInputs = document.querySelectorAll('input[name="permissions[]"]');
    permissionInputs.forEach(input => {
        input.addEventListener('change', function() {
            updateCategorySelectState(this.dataset.category);
            updateSelectAllState();
        });
    });

    // Инициализация состояний
    initializePermissionStates();
}

function initializePermissionStates() {
    // Обновляем состояние всех категорий
    const categories = new Set();
    document.querySelectorAll('input[name="permissions[]"]').forEach(input => {
        categories.add(input.dataset.category);
    });

    categories.forEach(category => {
        updateCategorySelectState(category);
    });

    updateSelectAllState();
}

function updateCategorySelectState(category) {
    const categoryPermissions = document.querySelectorAll(`input[name="permissions[]"][data-category="${category}"]`);
    const categorySelect = document.querySelector(`.category-select[data-category="${category}"]`);

    if (!categorySelect) return;

    const checkedCount = Array.from(categoryPermissions).filter(p => p.checked).length;
    const totalCount = categoryPermissions.length;

    if (checkedCount === 0) {
        categorySelect.checked = false;
        categorySelect.indeterminate = false;
    } else if (checkedCount === totalCount) {
        categorySelect.checked = true;
        categorySelect.indeterminate = false;
    } else {
        categorySelect.checked = false;
        categorySelect.indeterminate = true;
    }
}

function updateSelectAllState() {
    const selectAllCheckbox = document.getElementById('select-all-permissions');
    if (!selectAllCheckbox) return;

    const allPermissions = document.querySelectorAll('input[name="permissions[]"]');
    const checkedCount = Array.from(allPermissions).filter(p => p.checked).length;
    const totalCount = allPermissions.length;

    if (checkedCount === 0) {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = false;
    } else if (checkedCount === totalCount) {
        selectAllCheckbox.checked = true;
        selectAllCheckbox.indeterminate = false;
    } else {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = true;
    }
}

function initForms() {
    const forms = document.querySelectorAll('.role-form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            // Валидация формы перед отправкой
            if (!validateForm(this)) {
                e.preventDefault();
                return false;
            }

            // Показываем индикатор загрузки
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            submitBtn.textContent = 'Сохранение...';
            submitBtn.disabled = true;

            // Восстанавливаем кнопку через 3 секунды (на случай ошибки)
            setTimeout(() => {
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            }, 3000);
        });
    });
}

function validateForm(form) {
    const nameField = form.querySelector('input[name="name"]');
    let isValid = true;

    if (!nameField.value.trim()) {
        showFieldError(nameField, 'Название роли обязательно для заполнения');
        isValid = false;
    } else {
        clearFieldError(nameField);
    }

    // Проверяем что выбрано хотя бы одно разрешение
    const permissions = form.querySelectorAll('input[name="permissions[]"]:checked');
    if (permissions.length === 0) {
        showGeneralError('Выберите хотя бы одно разрешение для роли');
        isValid = false;
    } else {
        clearGeneralError();
    }

    return isValid;
}

function showFieldError(field, message) {
    clearFieldError(field);

    field.style.borderColor = '#dc3545';

    const errorDiv = document.createElement('div');
    errorDiv.className = 'field-error';
    errorDiv.style.color = '#dc3545';
    errorDiv.style.fontSize = '12px';
    errorDiv.style.marginTop = '5px';
    errorDiv.textContent = message;

    field.parentNode.appendChild(errorDiv);
}

function clearFieldError(field) {
    field.style.borderColor = '';

    const existingError = field.parentNode.querySelector('.field-error');
    if (existingError) {
        existingError.remove();
    }
}

function showGeneralError(message) {
    clearGeneralError();

    const errorDiv = document.createElement('div');
    errorDiv.className = 'alert alert-error';
    errorDiv.textContent = message;

    const form = document.querySelector('.role-form');
    if (form) {
        form.parentNode.insertBefore(errorDiv, form);
    }
}

function clearGeneralError() {
    const existingError = document.querySelector('.alert-error');
    if (existingError && !existingError.classList.contains('php-error')) {
        existingError.remove();
    }
}

function refreshTable() {
    console.log('Обновление таблицы...');
    location.reload();
}

function editRole(roleId) {
    console.log('Редактирование роли ID: ' + roleId);
    window.location.href = 'roles.php?action=edit&id=' + roleId;
}

function viewRole(roleId) {
    console.log('Просмотр роли ID: ' + roleId);
    window.location.href = 'roles.php?action=view&id=' + roleId;
}

function confirmDelete(roleId) {
    deleteRole(roleId);
}

function deleteRole(roleId) {
    const form = Array.from(document.querySelectorAll('.csrf-action-form')).find(candidate =>
        candidate.querySelector('[name="action"]').value === 'delete'
        && candidate.querySelector('[name="id"]').value === String(roleId)
    );
    if (form) form.requestSubmit();
}

// Быстрый выбор типовых наборов прав
function selectPermissionPreset(preset) {
    const presets = {
        'admin': [
            'view_dashboard', 'manage_profile',
            'view_schools', 'manage_schools',
            'view_users', 'manage_users', 'reset_passwords',
            'view_roles', 'manage_roles',
            'view_curriculum', 'manage_curriculum',
            'view_academic_periods', 'manage_academic_periods',
            'view_reports', 'generate_reports', 'export_data'
        ],
        'teacher': [
            'view_dashboard', 'manage_profile',
            'view_students', 'manage_grades', 'manage_homework',
            'view_attendance', 'manage_attendance'
        ],
        'student': [
            'view_dashboard', 'manage_profile'
        ]
    };

    if (presets[preset]) {
        const allPermissions = document.querySelectorAll('input[name="permissions[]"]');
        allPermissions.forEach(permission => {
            permission.checked = presets[preset].includes(permission.value);
        });

        initializePermissionStates();

        // Показываем уведомление
        alert('Применен набор прав для: ' + preset);
    }
}

// Добавляем кнопки быстрого выбора если есть форма
document.addEventListener('DOMContentLoaded', function() {
    const permissionsSection = document.querySelector('.permissions-section');
    if (permissionsSection) {
        const presetContainer = document.createElement('div');
        presetContainer.className = 'preset-buttons';
        presetContainer.style.marginBottom = '15px';
        presetContainer.style.padding = '15px';
        presetContainer.style.background = '#e7f3ff';
        presetContainer.style.borderRadius = '6px';
        presetContainer.style.border = '1px solid #b3d7ff';

        presetContainer.innerHTML = `
            <strong style="display: block; margin-bottom: 8px; color: #0066cc;">Быстрый выбор:</strong>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="button" class="btn btn-sm btn-outline" onclick="selectPermissionPreset('admin')">👑 Администратор</button>
                <button type="button" class="btn btn-sm btn-outline" onclick="selectPermissionPreset('teacher')">👨‍🏫 Учитель</button>
                <button type="button" class="btn btn-sm btn-outline" onclick="selectPermissionPreset('student')">🎓 Ученик</button>
            </div>
        `;

        permissionsSection.parentNode.insertBefore(presetContainer, permissionsSection);
    }
});

// Добавляем стили для кнопок быстрого выбора
const style = document.createElement('style');
style.textContent = `
    .btn-outline {
        background: white;
        border: 1px solid #667eea;
        color: #667eea;
        padding: 6px 12px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 12px;
        transition: all 0.3s ease;
    }
    
    .btn-outline:hover {
        background: #667eea;
        color: white;
    }
    
    .btn-sm {
        padding: 6px 12px;
        font-size: 12px;
    }
`;
document.head.appendChild(style);