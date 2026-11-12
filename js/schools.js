// Скрипт для управления учебными заведениями
document.addEventListener('DOMContentLoaded', function() {
    console.log('Страница учебных заведений загружена');

    // Инициализация таблицы
    initTable();

    // Инициализация форм
    initForms();
});

function initTable() {
    const table = document.getElementById('schools-table');
    if (!table) return;

    // Добавляем обработчики для строк таблицы
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        row.addEventListener('click', function(e) {
            // Обработка клика по строке (если нужно)
        });
    });

    console.log('Таблица инициализирована, записей: ' + rows.length);
}

function initForms() {
    const forms = document.querySelectorAll('.school-form');
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

    // Валидация ИНН (10 или 12 цифр)
    const innField = form.querySelector('input[name="inn"]');
    if (innField && innField.value) {
        const inn = innField.value.replace(/\D/g, '');
        if (inn.length !== 10 && inn.length !== 12) {
            showFieldError(innField, 'ИНН должен содержать 10 или 12 цифр');
            isValid = false;
        }
    }

    // Валидация email
    const emailField = form.querySelector('input[name="email"]');
    if (emailField && emailField.value) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(emailField.value)) {
            showFieldError(emailField, 'Введите корректный email адрес');
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

function editSchool(schoolId) {
    console.log('Редактирование школы ID: ' + schoolId);
    window.location.href = 'schools.php?action=edit&id=' + schoolId;
}

function viewSchool(schoolId) {
    console.log('Просмотр школы ID: ' + schoolId);
    window.location.href = 'schools.php?action=view&id=' + schoolId;
}

function confirmDelete(schoolId) {
    deleteSchool(schoolId);
}

function deleteSchool(schoolId) {
    const form = Array.from(document.querySelectorAll('.csrf-action-form')).find(candidate =>
        candidate.querySelector('[name="action"]').value === 'delete'
        && candidate.querySelector('[name="id"]').value === String(schoolId)
    );
    if (form) form.requestSubmit();
}

function viewUsers(schoolId) {
    console.log('Просмотр пользователей школы ID: ' + schoolId);
    // Переход к пользователям школы
    window.location.href = 'users.php?school_id=' + schoolId;
}

// Поиск и фильтрация
function initSearch() {
    const searchInput = document.getElementById('search-input');
    if (searchInput) {
        searchInput.addEventListener('input', searchSchools);
    }
}

function searchSchools() {
    const searchTerm = document.getElementById('search-input').value.toLowerCase();
    const rows = document.querySelectorAll('#schools-table tbody tr');
    let visibleCount = 0;

    rows.forEach(row => {
        if (row.classList.contains('no-data')) return;

        const schoolName = row.querySelector('.school-name strong').textContent.toLowerCase();
        const inn = row.cells[1].textContent.toLowerCase();
        const director = row.cells[4].textContent.toLowerCase();

        if (schoolName.includes(searchTerm) || inn.includes(searchTerm) || director.includes(searchTerm)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    // Обновляем счетчик в футере таблицы
    const tableInfo = document.querySelector('.table-info');
    if (tableInfo) {
        tableInfo.innerHTML = `Показано <strong>${visibleCount}</strong> учебных заведений`;
    }
}

// Экспорт данных
function exportToCSV() {
    console.log('Экспорт данных в CSV');

    const rows = document.querySelectorAll('#schools-table tbody tr');
    let csvContent = "data:text/csv;charset=utf-8,";

    // Заголовки
    const headers = ['Название', 'ИНН', 'Тип', 'Статус', 'Директор', 'Дата создания'];
    csvContent += headers.join(';') + '\r\n';

    // Данные
    rows.forEach(row => {
        if (row.style.display !== 'none' && !row.classList.contains('no-data')) {
            const cells = row.querySelectorAll('td');
            const rowData = [
                cells[0].querySelector('strong').textContent.trim(),
                cells[1].textContent.trim(),
                cells[2].textContent.trim(),
                cells[3].textContent.trim(),
                cells[4].textContent.trim(),
                cells[5].textContent.trim()
            ];
            csvContent += rowData.join(';') + '\r\n';
        }
    });

    // Создаем ссылку для скачивания
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', 'schools_export_' + new Date().toISOString().split('T')[0] + '.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
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