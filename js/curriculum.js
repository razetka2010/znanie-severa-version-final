// Скрипт для управления учебными планами
class CurriculumManager {
    constructor() {
        this.currentSubjects = [];
        this.init();
    }

    init() {
        console.log('Curriculum Manager initialized');
        this.bindEvents();
        this.loadInitialData();
    }

    bindEvents() {
        // Кнопка добавления предмета
        const addSubjectBtn = document.getElementById('add-subject');
        if (addSubjectBtn) {
            addSubjectBtn.addEventListener('click', () => this.addSubject());
        }

        // Кнопка добавления учебного плана
        const addCurriculumBtn = document.getElementById('add-curriculum');
        if (addCurriculumBtn) {
            addCurriculumBtn.addEventListener('click', () => this.showAddForm());
        }

        // Фильтры
        const filterForm = document.getElementById('curriculum-filters');
        if (filterForm) {
            filterForm.addEventListener('submit', (e) => this.handleFilter(e));
        }

        // Кнопка сброса фильтров
        const resetFiltersBtn = document.getElementById('reset-filters');
        if (resetFiltersBtn) {
            resetFiltersBtn.addEventListener('click', () => this.resetFilters());
        }

        // Обработчики для действий в таблице
        this.bindTableActions();
    }

    bindTableActions() {
        // Редактирование
        document.querySelectorAll('.btn-edit').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const curriculumId = e.target.closest('tr').dataset.id;
                this.editCurriculum(curriculumId);
            });
        });

        // Просмотр
        document.querySelectorAll('.btn-view').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const curriculumId = e.target.closest('tr').dataset.id;
                this.viewCurriculum(curriculumId);
            });
        });

        // Копирование
        document.querySelectorAll('.btn-copy').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const curriculumId = e.target.closest('tr').dataset.id;
                this.copyCurriculum(curriculumId);
            });
        });

    }

    loadInitialData() {
        // Загрузка начальных данных, если нужно
        console.log('Loading initial curriculum data...');
    }

    addSubject() {
        const subjectsContainer = document.getElementById('subjects-container');
        if (!subjectsContainer) return;

        const subjectCount = subjectsContainer.children.length;
        const subjectHtml = `
            <div class="subject-row" data-index="${subjectCount}">
                <input type="text" 
                       name="subjects[${subjectCount}][name]" 
                       class="subject-input" 
                       placeholder="Название предмета" 
                       required>
                <input type="number" 
                       name="subjects[${subjectCount}][hours]" 
                       class="hours-input" 
                       placeholder="Часы" 
                       min="1" 
                       max="100" 
                       required>
                <button type="button" class="btn-remove-subject" onclick="curriculumManager.removeSubject(${subjectCount})">
                    ✕ Удалить
                </button>
            </div>
        `;

        subjectsContainer.insertAdjacentHTML('beforeend', subjectHtml);
    }

    removeSubject(index) {
        const subjectRow = document.querySelector(`.subject-row[data-index="${index}"]`);
        if (subjectRow) {
            subjectRow.remove();
            this.renumberSubjects();
        }
    }

    renumberSubjects() {
        const subjectsContainer = document.getElementById('subjects-container');
        if (!subjectsContainer) return;

        const rows = subjectsContainer.querySelectorAll('.subject-row');
        rows.forEach((row, index) => {
            row.dataset.index = index;

            const nameInput = row.querySelector('input[name^="subjects"]');
            const hoursInput = row.querySelector('input[name$="[hours]"]');

            if (nameInput) {
                nameInput.name = `subjects[${index}][name]`;
            }
            if (hoursInput) {
                hoursInput.name = `subjects[${index}][hours]`;
            }

            const removeBtn = row.querySelector('.btn-remove-subject');
            if (removeBtn) {
                removeBtn.onclick = () => this.removeSubject(index);
            }
        });
    }

    showAddForm() {
        window.location.href = 'curriculum.php?action=add';
    }

    editCurriculum(id) {
        window.location.href = `curriculum.php?action=edit&id=${id}`;
    }

    viewCurriculum(id) {
        window.location.href = `curriculum.php?action=view&id=${id}`;
    }

    copyCurriculum(id) {
        if (confirm('Создать копию этого учебного плана?')) {
            // Здесь будет AJAX запрос для копирования
            this.showNotification('Учебный план скопирован', 'success');
            // Обновляем страницу через секунду
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        }
    }

    confirmDelete(id) {
        if (confirm('Вы уверены, что хотите удалить этот учебный план? Это действие нельзя отменить.')) {
            this.deleteCurriculum(id);
        }
    }

    deleteCurriculum(id) {
        // Показываем индикатор загрузки
        const deleteBtn = document.querySelector(`.btn-delete[onclick*="${id}"]`);
        if (deleteBtn) {
            const originalHTML = deleteBtn.innerHTML;
            deleteBtn.innerHTML = '⏳';
            deleteBtn.disabled = true;
        }

        // Отправляем запрос на удаление
        const actionForm = Array.from(document.querySelectorAll('.csrf-action-form')).find(form =>
            form.querySelector('[name="action"]').value === 'delete'
            && form.querySelector('[name="id"]').value === String(id)
        );
        if (!actionForm) return;

        fetch(actionForm.action, {
            method: 'POST',
            body: new FormData(actionForm)
        })
            .then(response => {
                if (response.redirected) {
                    // Если произошел редирект, просто переходим по нему
                    window.location.href = response.url;
                    return;
                }
                return response.text();
            })
            .then(data => {
                // Восстанавливаем кнопку
                if (deleteBtn) {
                    deleteBtn.innerHTML = '🗑️';
                    deleteBtn.disabled = false;
                }

                // Проверяем, является ли ответ HTML (что означает ошибку)
                if (data && data.trim().startsWith('<!DOCTYPE')) {
                    // Это HTML страница, вероятно страница входа или ошибка
                    console.error('Received HTML instead of JSON response');
                    this.showNotification('Ошибка при удалении. Проверьте авторизацию.', 'error');
                    return;
                }

                try {
                    // Пытаемся разобрать JSON
                    const result = JSON.parse(data);
                    if (result.success) {
                        this.showNotification('Учебный план удален', 'success');
                        // Удаляем строку из таблицы
                        const row = document.querySelector(`tr[data-id="${id}"]`);
                        if (row) {
                            row.style.opacity = '0.5';
                            setTimeout(() => row.remove(), 500);
                        }
                        // Обновляем страницу через 2 секунды
                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
                    } else {
                        this.showNotification('Ошибка при удалении: ' + (result.message || 'Неизвестная ошибка'), 'error');
                    }
                } catch (e) {
                    // Если не JSON, предполагаем успешное удаление и перезагружаем страницу
                    console.log('Non-JSON response, assuming success and reloading');
                    window.location.reload();
                }
            })
            .catch(error => {
                console.error('Error:', error);

                // Восстанавливаем кнопку
                if (deleteBtn) {
                    deleteBtn.innerHTML = '🗑️';
                    deleteBtn.disabled = false;
                }

                this.showNotification('Ошибка при удалении: ' + error.message, 'error');
            });
    }

    handleFilter(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        const filters = Object.fromEntries(formData);

        console.log('Applying filters:', filters);
        this.applyFilters(filters);
    }

    applyFilters(filters) {
        const rows = document.querySelectorAll('.curriculum-data-table tbody tr');
        let visibleCount = 0;

        rows.forEach(row => {
            // Пропускаем строку с пустым состоянием
            if (row.classList.contains('empty-state')) {
                return;
            }

            let showRow = true;

            // Фильтр по школе
            if (filters.school && filters.school !== 'all') {
                const schoolId = row.dataset.schoolId;
                if (schoolId !== filters.school) {
                    showRow = false;
                }
            }

            // Фильтр по статусу
            if (filters.status && filters.status !== 'all') {
                const statusElement = row.querySelector('.status-badge');
                if (statusElement) {
                    const status = statusElement.textContent.trim().toLowerCase();
                    const statusMap = {
                        'активный': 'active',
                        'неактивный': 'inactive'
                    };
                    if (statusMap[status] !== filters.status) {
                        showRow = false;
                    }
                }
            }

            // Поиск по названию
            if (filters.search) {
                const nameElement = row.querySelector('.curriculum-name');
                if (nameElement) {
                    const name = nameElement.textContent.toLowerCase();
                    if (!name.includes(filters.search.toLowerCase())) {
                        showRow = false;
                    }
                }
            }

            row.style.display = showRow ? '' : 'none';
            if (showRow) visibleCount++;
        });

        // Показываем сообщение, если ничего не найдено
        this.showNoResultsMessage(visibleCount === 0);
    }

    showNoResultsMessage(show) {
        let noResultsRow = document.getElementById('no-results-message');

        if (show && !noResultsRow) {
            noResultsRow = document.createElement('tr');
            noResultsRow.id = 'no-results-message';
            noResultsRow.innerHTML = `
                <td colspan="7" style="text-align: center; padding: 40px; color: #7f8c8d;">
                    <div style="font-size: 48px; margin-bottom: 10px;">🔍</div>
                    <h3 style="margin-bottom: 10px;">Ничего не найдено</h3>
                    <p>Попробуйте изменить параметры фильтрации</p>
                </td>
            `;
            document.querySelector('.curriculum-data-table tbody').appendChild(noResultsRow);
        } else if (!show && noResultsRow) {
            noResultsRow.remove();
        }
    }

    resetFilters() {
        const filterForm = document.getElementById('curriculum-filters');
        if (filterForm) {
            filterForm.reset();
            this.applyFilters({});
        }
    }

    validateCurriculumForm() {
        const form = document.getElementById('curriculum-form');
        if (!form) return true;

        const requiredFields = form.querySelectorAll('[required]');
        let isValid = true;

        // Очищаем предыдущие ошибки
        this.clearAllFieldErrors();

        // Проверка обязательных полей
        requiredFields.forEach(field => {
            if (!field.value.trim()) {
                this.showFieldError(field, 'Это поле обязательно для заполнения');
                isValid = false;
            }
        });

        // Проверка выбора классов
        const selectedGrades = form.querySelectorAll('input[name="grades[]"]:checked');
        if (selectedGrades.length === 0) {
            this.showNotification('Выберите хотя бы один класс', 'error');
            isValid = false;
        }

        // Проверка предметов
        const subjectRows = document.querySelectorAll('.subject-row');
        if (subjectRows.length === 0) {
            this.showNotification('Добавьте хотя бы один предмет', 'error');
            isValid = false;
        } else {
            // Проверка каждого предмета
            subjectRows.forEach((row, index) => {
                const nameInput = row.querySelector('input[placeholder="Название предмета"]');
                const hoursInput = row.querySelector('input[placeholder="Часы"]');

                if (nameInput && !nameInput.value.trim()) {
                    this.showFieldError(nameInput, 'Введите название предмета');
                    isValid = false;
                }

                if (hoursInput && (!hoursInput.value || hoursInput.value < 1)) {
                    this.showFieldError(hoursInput, 'Введите количество часов (минимум 1)');
                    isValid = false;
                }
            });
        }

        if (!isValid) {
            this.showNotification('Исправьте ошибки в форме', 'error');
            // Прокручиваем к первой ошибке
            const firstError = form.querySelector('.field-error');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        return isValid;
    }

    showFieldError(field, message) {
        this.clearFieldError(field);

        field.style.borderColor = '#dc3545';
        field.style.boxShadow = '0 0 0 2px rgba(220, 53, 69, 0.1)';

        const errorDiv = document.createElement('div');
        errorDiv.className = 'field-error';
        errorDiv.style.cssText = `
            color: #dc3545;
            font-size: 12px;
            margin-top: 5px;
            font-weight: 500;
        `;
        errorDiv.textContent = message;

        field.parentNode.appendChild(errorDiv);
    }

    clearFieldError(field) {
        field.style.borderColor = '';
        field.style.boxShadow = '';

        const existingError = field.parentNode.querySelector('.field-error');
        if (existingError) {
            existingError.remove();
        }
    }

    clearAllFieldErrors() {
        const errorFields = document.querySelectorAll('.field-error');
        errorFields.forEach(error => error.remove());

        const fields = document.querySelectorAll('input, select, textarea');
        fields.forEach(field => {
            field.style.borderColor = '';
            field.style.boxShadow = '';
        });
    }

    showNotification(message, type = 'info') {
        // Удаляем существующие уведомления
        const existingNotifications = document.querySelectorAll('.notification');
        existingNotifications.forEach(notification => notification.remove());

        // Создаем уведомление
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;

        const styles = {
            position: 'fixed',
            top: '20px',
            right: '20px',
            padding: '15px 20px',
            borderRadius: '8px',
            color: 'white',
            fontWeight: '600',
            zIndex: '10000',
            boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
            maxWidth: '400px',
            animation: 'slideInRight 0.3s ease-out'
        };

        Object.assign(notification.style, styles);

        if (type === 'success') {
            notification.style.background = 'linear-gradient(135deg, #28a745, #20c997)';
        } else if (type === 'error') {
            notification.style.background = 'linear-gradient(135deg, #dc3545, #e83e8c)';
        } else {
            notification.style.background = 'linear-gradient(135deg, #17a2b8, #6f42c1)';
        }

        notification.textContent = message;
        document.body.appendChild(notification);

        // Добавляем CSS анимацию если её нет
        if (!document.querySelector('#notification-styles')) {
            const style = document.createElement('style');
            style.id = 'notification-styles';
            style.textContent = `
                @keyframes slideInRight {
                    from {
                        opacity: 0;
                        transform: translateX(100%);
                    }
                    to {
                        opacity: 1;
                        transform: translateX(0);
                    }
                }
                @keyframes slideOutRight {
                    from {
                        opacity: 1;
                        transform: translateX(0);
                    }
                    to {
                        opacity: 0;
                        transform: translateX(100%);
                    }
                }
            `;
            document.head.appendChild(style);
        }

        // Удаляем уведомление через 4 секунды
        setTimeout(() => {
            notification.style.animation = 'slideOutRight 0.3s ease-out';
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 300);
        }, 4000);
    }

    // Метод для экспорта учебных планов
    exportCurriculum(format = 'csv') {
        console.log(`Exporting curriculum in ${format} format`);
        this.showNotification('Функция экспорта в разработке', 'info');
    }

    // Метод для массовых действий
    handleBulkAction(action) {
        const selectedRows = document.querySelectorAll('input[name="selected_curriculum[]"]:checked');

        if (selectedRows.length === 0) {
            this.showNotification('Выберите хотя бы один учебный план', 'error');
            return;
        }

        const selectedIds = Array.from(selectedRows).map(checkbox => checkbox.value);

        switch (action) {
            case 'activate':
                this.bulkActivate(selectedIds);
                break;
            case 'deactivate':
                this.bulkDeactivate(selectedIds);
                break;
            case 'delete':
                this.bulkDelete(selectedIds);
                break;
        }
    }

    bulkActivate(ids) {
        if (confirm(`Активировать ${ids.length} учебных планов?`)) {
            // AJAX запрос для активации
            this.showNotification(`Активировано ${ids.length} учебных планов`, 'success');
        }
    }

    bulkDeactivate(ids) {
        if (confirm(`Деактивировать ${ids.length} учебных планов?`)) {
            // AJAX запрос для деактивации
            this.showNotification(`Деактивировано ${ids.length} учебных планов`, 'success');
        }
    }

    bulkDelete(ids) {
        if (confirm(`Удалить ${ids.length} учебных планов? Это действие нельзя отменить.`)) {
            ids.forEach(id => this.deleteCurriculum(id));
        }
    }
}

// Инициализация менеджера учебных планов
const curriculumManager = new CurriculumManager();

// Глобальные функции для использования в HTML
function validateCurriculumForm() {
    return curriculumManager.validateCurriculumForm();
}

function addSubject() {
    curriculumManager.addSubject();
}

function removeSubject(index) {
    curriculumManager.removeSubject(index);
}

function confirmDelete(id) {
    curriculumManager.confirmDelete(id);
}

function editCurriculum(id) {
    curriculumManager.editCurriculum(id);
}

function viewCurriculum(id) {
    curriculumManager.viewCurriculum(id);
}

function resetFilters() {
    curriculumManager.resetFilters();
}

// Обработчики для массовых действий
document.addEventListener('DOMContentLoaded', function() {
    // Выделение всех чекбоксов
    const selectAllCheckbox = document.getElementById('select-all');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('input[name="selected_curriculum[]"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });
        });
    }

    // Обработчик для выпадающего списка массовых действий
    const bulkActionSelect = document.getElementById('bulk-action');
    if (bulkActionSelect) {
        bulkActionSelect.addEventListener('change', function() {
            if (this.value) {
                curriculumManager.handleBulkAction(this.value);
                this.value = ''; // Сбрасываем значение
            }
        });
    }

    // Подтверждение формы
    const curriculumForm = document.getElementById('curriculum-form');
    if (curriculumForm) {
        curriculumForm.addEventListener('submit', function(e) {
            if (!validateCurriculumForm()) {
                e.preventDefault();
            }
        });
    }

    // Автоматическое применение фильтров при изменении
    const filterInputs = document.querySelectorAll('#curriculum-filters input, #curriculum-filters select');
    filterInputs.forEach(input => {
        input.addEventListener('change', () => {
            const form = document.getElementById('curriculum-filters');
            if (form) {
                const formData = new FormData(form);
                const filters = Object.fromEntries(formData);
                curriculumManager.applyFilters(filters);
            }
        });
    });

    // Enter в поле поиска
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const form = document.getElementById('curriculum-filters');
                if (form) {
                    const formData = new FormData(form);
                    const filters = Object.fromEntries(formData);
                    curriculumManager.applyFilters(filters);
                }
            }
        });
    }
});

// Глобальные обработчики ошибок
window.addEventListener('error', function(e) {
    console.error('Global error:', e.error);
});

window.addEventListener('unhandledrejection', function(e) {
    console.error('Unhandled promise rejection:', e.reason);
});