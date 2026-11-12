// Скрипт для управления пользователями
document.addEventListener('DOMContentLoaded', function() {
    console.log('Страница пользователей загружена');

    // Инициализация таблицы
    initTable();

    // Инициализация форм
    initForms();
});

function initTable() {
    const table = document.getElementById('users-table');
    if (!table) return;

    console.log('Таблица пользователей инициализирована');
}

function initForms() {
    const forms = document.querySelectorAll('.user-form');
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
    const requiredFields = form.querySelectorAll('[required]');
    let isValid = true;

    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            showFieldError(field, 'Это поле обязательно для заполнения');
            isValid = false;
        } else {
            clearFieldError(field);
        }
    });

    // Валидация email
    const emailField = form.querySelector('input[name="email"]');
    if (emailField && emailField.value) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(emailField.value)) {
            showFieldError(emailField, 'Введите корректный email адрес');
            isValid = false;
        }
    }

    // Валидация пароля при создании
    const passwordField = form.querySelector('input[name="password"]');
    const isAddForm = form.action.includes('action=add');

    if (isAddForm && passwordField && passwordField.value) {
        if (passwordField.value.length < 6) {
            showFieldError(passwordField, 'Пароль должен содержать минимум 6 символов');
            isValid = false;
        }
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

function refreshTable() {
    console.log('Обновление таблицы...');
    location.reload();
}

function filterBySchool(schoolId) {
    if (schoolId) {
        window.location.href = 'users.php?school_id=' + schoolId;
    } else {
        window.location.href = 'users.php';
    }
}

function editUser(userId) {
    console.log('Редактирование пользователя ID: ' + userId);
    window.location.href = 'users.php?action=edit&id=' + userId;
}

function viewUser(userId) {
    console.log('Просмотр пользователя ID: ' + userId);
    window.location.href = 'users.php?action=view&id=' + userId;
}

function confirmDelete(userId) {
    deleteUser(userId);
}

function deleteUser(userId) {
    const form = Array.from(document.querySelectorAll('.csrf-action-form')).find(candidate =>
        candidate.querySelector('[name="action"]').value === 'delete'
        && candidate.querySelector('[name="id"]').value === String(userId)
    );
    if (form) form.requestSubmit();
}

// Поиск пользователей
function initSearch() {
    const searchInput = document.getElementById('search-input');
    if (searchInput) {
        searchInput.addEventListener('input', searchUsers);
    }
}

function searchUsers() {
    const searchTerm = document.getElementById('search-input').value.toLowerCase();
    const rows = document.querySelectorAll('#users-table tbody tr');
    let visibleCount = 0;

    rows.forEach(row => {
        if (row.classList.contains('no-data')) return;

        const userName = row.querySelector('.user-name strong').textContent.toLowerCase();
        const login = row.cells[1].textContent.toLowerCase();
        const email = row.cells[2].textContent.toLowerCase();
        const role = row.cells[3].textContent.toLowerCase();

        if (userName.includes(searchTerm) || login.includes(searchTerm) || email.includes(searchTerm) || role.includes(searchTerm)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    // Обновляем счетчик в футере таблицы
    const tableInfo = document.querySelector('.table-info');
    if (tableInfo) {
        tableInfo.innerHTML = `Показано <strong>${visibleCount}</strong> пользователей`;
    }
}

// Генерация случайного пароля
function generatePassword() {
    const length = 8;
    const charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    let password = "";

    for (let i = 0; i < length; i++) {
        password += charset.charAt(Math.floor(Math.random() * charset.length));
    }

    const passwordField = document.querySelector('input[name="password"]');
    if (passwordField) {
        passwordField.value = password;
        passwordField.type = 'text';

        // Показываем уведомление
        setTimeout(() => {
            alert('Сгенерирован пароль: ' + password + '\nНе забудьте сохранить его!');
        }, 100);
    }
}

// Автоматическое форматирование телефона
function initPhoneFormatting() {
    const phoneInputs = document.querySelectorAll('input[name="phone"]');
    phoneInputs.forEach(input => {
        input.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');

            if (value.length > 0) {
                value = '+7 (' + value;

                if (value.length > 7) {
                    value = value.slice(0, 7) + ') ' + value.slice(7);
                }
                if (value.length > 12) {
                    value = value.slice(0, 12) + '-' + value.slice(12);
                }
                if (value.length > 15) {
                    value = value.slice(0, 15) + '-' + value.slice(15);
                }
            }

            e.target.value = value;
        });
    });
}

// Инициализация при загрузке
initSearch();
initPhoneFormatting();

// Добавляем кнопку генерации пароля если есть форма
document.addEventListener('DOMContentLoaded', function() {
    const passwordField = document.querySelector('input[name="password"]');
    if (passwordField) {
        const generateBtn = document.createElement('button');
        generateBtn.type = 'button';
        generateBtn.textContent = '🎲 Сгенерировать';
        generateBtn.className = 'btn btn-sm btn-secondary';
        generateBtn.style.marginTop = '5px';
        generateBtn.onclick = generatePassword;

        passwordField.parentNode.appendChild(generateBtn);
    }
});