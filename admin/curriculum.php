<?php
session_start();
require_once '../config/database.php';

// Проверка авторизации
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'super_admin') {
    header('Location: ../login.php');
    exit;
}

$pdo = getDatabaseConnection();
$action = isset($_GET['action']) ? $_GET['action'] : '';
$curriculum_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

function decodeCurriculumSubjects(?string $json): array
{
    $subjects = json_decode($json ?? '[]', true);

    if (!is_array($subjects)) {
        return [];
    }

    $normalized = [];
    foreach ($subjects as $subject) {
        if (is_array($subject)) {
            $normalized[] = [
                'name' => (string)($subject['name'] ?? ''),
                'hours' => (string)($subject['hours'] ?? '')
            ];
        } elseif (is_scalar($subject)) {
            $normalized[] = [
                'name' => (string)$subject,
                'hours' => ''
            ];
        }
    }

    return $normalized;
}

// Обработка добавления учебного плана
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add') {
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $school_id = isset($_POST['school_id']) ? intval($_POST['school_id']) : 0;
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $grades = isset($_POST['grades']) ? json_encode($_POST['grades']) : '[]';
        $subjects = isset($_POST['subjects']) ? json_encode($_POST['subjects']) : '[]';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $created_by = $_SESSION['user_id'];

        try {
            $stmt = $pdo->prepare("INSERT INTO curriculum (name, school_id, description, grades, subjects, is_active, created_by) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?)");

            $stmt->execute([
                    $name, $school_id, $description, $grades, $subjects, $is_active, $created_by
            ]);

            $_SESSION['success_message'] = "Учебный план успешно создан!";
            header('Location: curriculum.php');
            exit;
        } catch (PDOException $e) {
            $error = "Ошибка при создании учебного плана: " . $e->getMessage();
        }
    }
    // Обработка редактирования учебного плана
    elseif ($action === 'edit' && $curriculum_id > 0) {
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $school_id = isset($_POST['school_id']) ? intval($_POST['school_id']) : 0;
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $grades = isset($_POST['grades']) ? json_encode($_POST['grades']) : '[]';
        $subjects = isset($_POST['subjects']) ? json_encode($_POST['subjects']) : '[]';
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        try {
            $stmt = $pdo->prepare("UPDATE curriculum SET name = ?, school_id = ?, description = ?, grades = ?, subjects = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");

            $stmt->execute([
                    $name, $school_id, $description, $grades, $subjects, $is_active, $curriculum_id
            ]);

            $_SESSION['success_message'] = "Учебный план успешно обновлен!";
            header('Location: curriculum.php');
            exit;
        } catch (PDOException $e) {
            $error = "Ошибка при обновлении учебного плана: " . $e->getMessage();
        }
    }
}

// Обработка удаления учебного плана
if ($action === 'delete' && $curriculum_id > 0) {
    try {
        $stmt = $pdo->prepare("DELETE FROM curriculum WHERE id = ?");
        $stmt->execute([$curriculum_id]);

        $_SESSION['success_message'] = "Учебный план успешно удален!";
        header('Location: curriculum.php');
        exit;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Ошибка при удалении учебного плана: " . $e->getMessage();
        header('Location: curriculum.php');
        exit;
    }
}

// Получение данных учебного плана для редактирования/просмотра
$curriculum_data = null;
if (($action === 'edit' || $action === 'view') && $curriculum_id > 0) {
    $stmt = $pdo->prepare("SELECT c.*, s.full_name as school_name 
                          FROM curriculum c 
                          LEFT JOIN schools s ON c.school_id = s.id 
                          WHERE c.id = ?");
    $stmt->execute([$curriculum_id]);
    $curriculum_data = $stmt->fetch();

    if (!$curriculum_data) {
        $_SESSION['error_message'] = "Учебный план не найден!";
        header('Location: curriculum.php');
        exit;
    }
}

// Получение списка школ для выпадающего списка
$schools = $pdo->query("SELECT id, full_name FROM schools WHERE status = 'активная' ORDER BY full_name")->fetchAll();

// Получение списка учебных планов из БД
$sql = "SELECT c.*, s.full_name as school_name 
        FROM curriculum c 
        LEFT JOIN schools s ON c.school_id = s.id 
        ORDER BY c.created_at DESC";
$curriculums = $pdo->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="../logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Учебные планы - Знание Севера</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/curriculum.css">
</head>
<body>
<div class="dashboard-container">
    <!-- Боковая панель -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <h1>Знание Севера</h1>
            <p>Электронный дневник</p>
        </div>

        <nav class="sidebar-nav">
            <div class="user-info">
                <strong><?php echo htmlspecialchars($_SESSION['user_name']); ?></strong>
                <span class="role-badge"><?php echo htmlspecialchars($_SESSION['user_role']); ?></span>
            </div>

            <ul class="nav-menu">
                <li><a href="super_dashboard.php" class="nav-link">📊 Обзор</a></li>
                <li class="nav-section">Администрирование</li>
                <li><a href="schools.php" class="nav-link">🏫 Учебные заведения</a></li>
                <li><a href="users.php" class="nav-link">👥 Пользователи</a></li>
                <li><a href="roles.php" class="nav-link">🔐 Роли и права</a></li>
                <li><a href="curriculum.php" class="nav-link active">📚 Учебные планы</a></li>
                <li><a href="academic_periods.php" class="nav-link">📅 Учебные периоды</a></li>
                <li><a href="reports.php" class="nav-link">📈 Отчеты</a></li>
                <li class="nav-section">Общее</li>
                <li><a href="../profile.php" class="nav-link">👤 Профиль</a></li>
                <li><a href="../logout.php" class="nav-link">🚪 Выход</a></li>
            </ul>
        </nav>
    </aside>

    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1>Учебные планы</h1>
                <p>Главный Администратор • <?php echo htmlspecialchars($_SESSION['user_login']); ?></p>
            </div>
            <div class="header-actions">
                <?php if ($action === 'add' || $action === 'edit' || $action === 'view'): ?>
                    <a href="curriculum.php" class="btn btn-secondary">← Назад к списку</a>
                <?php else: ?>
                    <a href="curriculum.php?action=add" class="btn btn-primary">➕ Создать учебный план</a>
                <?php endif; ?>
            </div>
        </header>

        <div class="content-body">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error"><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>

            <?php if ($action === 'add' || $action === 'edit'): ?>
                <!-- Форма добавления/редактирования учебного плана -->
                <div class="curriculum-container">
                    <div class="curriculum-form">
                        <h2><?php echo $action === 'add' ? 'Создание учебного плана' : 'Редактирование учебного плана'; ?></h2>
                        <form method="POST" id="curriculum-form" onsubmit="return validateCurriculumForm()">
                            <div class="form-section">
                                <h3>Основная информация</h3>
                                <div class="form-grid">
                                    <div class="form-group required">
                                        <label>Название учебного плана</label>
                                        <input type="text" name="name" value="<?php echo $curriculum_data ? htmlspecialchars($curriculum_data['name']) : ''; ?>" required>
                                    </div>
                                    <div class="form-group required">
                                        <label>Школа</label>
                                        <select name="school_id" required>
                                            <option value="">Выберите школу</option>
                                            <?php foreach ($schools as $school): ?>
                                                <option value="<?php echo $school['id']; ?>"
                                                        <?php echo ($curriculum_data && $curriculum_data['school_id'] == $school['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($school['full_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Статус</label>
                                        <label class="checkbox-label">
                                            <input type="checkbox" name="is_active" value="1"
                                                    <?php echo (!$curriculum_data || $curriculum_data['is_active']) ? 'checked' : ''; ?>>
                                            Активный
                                        </label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Описание</label>
                                    <textarea name="description" rows="3" placeholder="Описание учебного плана..."><?php echo $curriculum_data ? htmlspecialchars($curriculum_data['description']) : ''; ?></textarea>
                                </div>
                            </div>

                            <div class="form-section">
                                <h3>Классы</h3>
                                <p>Выберите классы, для которых предназначен этот учебный план:</p>
                                <div class="grades-selection" id="grades-selection">
                                    <?php
                                    $selected_grades = $curriculum_data ? json_decode($curriculum_data['grades'], true) : [];
                                    for ($grade = 1; $grade <= 11; $grade++):
                                        ?>
                                        <label class="grade-checkbox">
                                            <input type="checkbox" name="grades[]" value="<?php echo $grade; ?>"
                                                    <?php echo in_array($grade, $selected_grades ?: []) ? 'checked' : ''; ?>>
                                            <span class="grade-label"><?php echo $grade; ?> класс</span>
                                        </label>
                                    <?php endfor; ?>
                                </div>
                            </div>

                            <div class="form-section">
                                <h3>Предметы</h3>
                                <div id="subjects-container">
                                    <?php
                                    $subjects = $curriculum_data ? decodeCurriculumSubjects($curriculum_data['subjects']) : [];
                                    if (!$subjects) {
                                        $subjects = [['name' => '', 'hours' => '']];
                                    }
                                    foreach ($subjects as $index => $subject):
                                        ?>
                                        <div class="subject-row" data-index="<?php echo $index; ?>">
                                            <input type="text"
                                                   name="subjects[<?php echo $index; ?>][name]"
                                                   class="subject-input"
                                                   placeholder="Название предмета"
                                                   value="<?php echo htmlspecialchars($subject['name']); ?>"
                                                   required>
                                            <input type="number"
                                                   name="subjects[<?php echo $index; ?>][hours]"
                                                   class="hours-input"
                                                   placeholder="Часы в неделю"
                                                   value="<?php echo htmlspecialchars($subject['hours']); ?>"
                                                   min="1"
                                                   max="20"
                                                   required>
                                            <?php if ($index > 0): ?>
                                                <button type="button" class="btn-remove-subject" onclick="removeSubject(<?php echo $index; ?>)">
                                                    ✕ Удалить
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="btn-add-subject" onclick="addSubject()">
                                    ➕ Добавить предмет
                                </button>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <?php echo $action === 'add' ? 'Создать учебный план' : 'Сохранить изменения'; ?>
                                </button>
                                <a href="curriculum.php" class="btn btn-secondary">Отмена</a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php elseif ($action === 'view' && $curriculum_data): ?>
                <!-- Просмотр учебного плана -->
                <div class="curriculum-container">
                    <div class="curriculum-header">
                        <h2><?php echo htmlspecialchars($curriculum_data['name']); ?></h2>
                        <div class="curriculum-actions">
                            <a href="curriculum.php?action=edit&id=<?php echo $curriculum_data['id']; ?>" class="btn btn-primary">✏️ Редактировать</a>
                            <button onclick="confirmDelete(<?php echo $curriculum_data['id']; ?>)" class="btn btn-danger">🗑️ Удалить</button>
                        </div>
                    </div>

                    <div class="view-sections">
                        <div class="view-section">
                            <h3>Основная информация</h3>
                            <div class="info-grid">
                                <div class="info-item">
                                    <label>Название:</label>
                                    <span><?php echo htmlspecialchars($curriculum_data['name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Школа:</label>
                                    <span><?php echo htmlspecialchars($curriculum_data['school_name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Статус:</label>
                                    <span class="status-badge <?php echo $curriculum_data['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $curriculum_data['is_active'] ? 'Активный' : 'Неактивный'; ?>
                                        </span>
                                </div>
                                <?php if ($curriculum_data['description']): ?>
                                    <div class="info-item">
                                        <label>Описание:</label>
                                        <span><?php echo nl2br(htmlspecialchars($curriculum_data['description'])); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="view-section">
                            <h3>Классы</h3>
                            <div class="curriculum-grades">
                                <?php
                                $grades = json_decode($curriculum_data['grades'], true);
                                if ($grades && is_array($grades)):
                                    foreach ($grades as $grade):
                                        ?>
                                        <span class="grade-badge"><?php echo $grade; ?> класс</span>
                                    <?php
                                    endforeach;
                                else:
                                    echo '<p>Классы не указаны</p>';
                                endif;
                                ?>
                            </div>
                        </div>

                        <div class="view-section">
                            <h3>Предметы</h3>
                            <div class="subjects-list">
                                <?php
                                $subjects = decodeCurriculumSubjects($curriculum_data['subjects']);
                                if ($subjects && is_array($subjects)):
                                    foreach ($subjects as $subject):
                                        ?>
                                        <div class="subject-item">
                                            <span class="subject-name"><?php echo htmlspecialchars($subject['name']); ?></span>
                                            <span class="subject-hours"><?php echo $subject['hours'] !== '' ? htmlspecialchars($subject['hours']) . ' ч/нед' : 'Часы не указаны'; ?></span>
                                        </div>
                                    <?php
                                    endforeach;
                                else:
                                    echo '<p>Предметы не добавлены</p>';
                                endif;
                                ?>
                            </div>
                        </div>

                        <div class="view-section">
                            <h3>Системная информация</h3>
                            <div class="info-grid">
                                <div class="info-item">
                                    <label>Дата создания:</label>
                                    <span><?php echo date('d.m.Y H:i', strtotime($curriculum_data['created_at'])); ?></span>
                                </div>
                                <?php if ($curriculum_data['updated_at'] != $curriculum_data['created_at']): ?>
                                    <div class="info-item">
                                        <label>Последнее обновление:</label>
                                        <span><?php echo date('d.m.Y H:i', strtotime($curriculum_data['updated_at'])); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Список учебных планов -->
                <div class="curriculum-container">
                    <div class="curriculum-filters">
                        <form id="curriculum-filters">
                            <div class="filter-grid">
                                <div class="filter-group">
                                    <label>Школа</label>
                                    <select name="school">
                                        <option value="all">Все школы</option>
                                        <?php foreach ($schools as $school): ?>
                                            <option value="<?php echo $school['id']; ?>"><?php echo htmlspecialchars($school['full_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label>Статус</label>
                                    <select name="status">
                                        <option value="all">Все статусы</option>
                                        <option value="active">Активные</option>
                                        <option value="inactive">Неактивные</option>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label>Поиск</label>
                                    <input type="text" name="search" placeholder="Поиск по названию...">
                                </div>
                                <div class="filter-actions">
                                    <button type="submit" class="btn btn-primary">Применить</button>
                                    <button type="button" class="btn btn-secondary" onclick="resetFilters()">Сбросить</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="curriculum-table">
                        <div class="table-responsive">
                            <table class="curriculum-data-table" id="curriculum-table">
                                <thead>
                                <tr>
                                    <th>Название</th>
                                    <th>Школа</th>
                                    <th>Классы</th>
                                    <th>Предметы</th>
                                    <th>Статус</th>
                                    <th>Дата создания</th>
                                    <th>Действия</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($curriculums)): ?>
                                    <tr>
                                        <td colspan="7" class="empty-state">
                                            <div class="empty-state-icon">📚</div>
                                            <h3>Учебные планы не найдены</h3>
                                            <p>Создайте первый учебный план для вашей школы</p>
                                            <a href="curriculum.php?action=add" class="btn btn-primary">Создать учебный план</a>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($curriculums as $curriculum):
                                        $grades = json_decode($curriculum['grades'], true) ?: [];
                                        $subjects = decodeCurriculumSubjects($curriculum['subjects']);
                                        ?>
                                        <tr data-id="<?php echo $curriculum['id']; ?>" data-school-id="<?php echo $curriculum['school_id']; ?>" data-grades='<?php echo json_encode($grades); ?>'>
                                            <td>
                                                <div class="curriculum-name"><?php echo htmlspecialchars($curriculum['name']); ?></div>
                                                <?php if ($curriculum['description']): ?>
                                                    <div class="curriculum-description"><?php echo htmlspecialchars($curriculum['description']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($curriculum['school_name']); ?></td>
                                            <td>
                                                <div class="curriculum-grades">
                                                    <?php foreach (array_slice($grades, 0, 3) as $grade): ?>
                                                        <span class="grade-badge"><?php echo $grade; ?> кл</span>
                                                    <?php endforeach; ?>
                                                    <?php if (count($grades) > 3): ?>
                                                        <span class="grade-badge">+<?php echo count($grades) - 3; ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="curriculum-subjects">
                                                    <?php foreach (array_slice($subjects, 0, 2) as $subject): ?>
                                                        <div class="subject-item">
                                                            <span class="subject-name"><?php echo htmlspecialchars($subject['name']); ?></span>
                                                            <span class="subject-hours"><?php echo $subject['hours']; ?>ч</span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    <?php if (count($subjects) > 2): ?>
                                                        <div class="subject-more">+<?php echo count($subjects) - 2; ?> предметов</div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                        <span class="status-badge <?php echo $curriculum['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                            <?php echo $curriculum['is_active'] ? 'Активный' : 'Неактивный'; ?>
                                                        </span>
                                            </td>
                                            <td><?php echo date('d.m.Y', strtotime($curriculum['created_at'])); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn-action btn-edit" title="Редактировать" onclick="editCurriculum(<?php echo $curriculum['id']; ?>)">
                                                        ✏️
                                                    </button>
                                                    <button class="btn-action btn-view" title="Просмотреть" onclick="viewCurriculum(<?php echo $curriculum['id']; ?>)">
                                                        👁️
                                                    </button>
                                                    <button class="btn-action btn-delete" title="Удалить" onclick="confirmDelete(<?php echo $curriculum['id']; ?>)">
                                                        🗑️
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script src="../js/curriculum.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
// Создаем мобильное представление если на маленьком экране
if (window.innerWidth <= 768) {
createMobileCardsView();
}

// Обработчик изменения размера окна
window.addEventListener('resize', function() {
if (window.innerWidth <= 768) {
createMobileCardsView();
} else {
removeMobileCardsView();
}
});
});

function createMobileCardsView() {
// Если уже создано, выходим
if (document.querySelector('.mobile-cards-view')) {
return;
}

const table = document.querySelector('.curriculum-data-table');
if (!table) return;

const tbody = table.querySelector('tbody');
const rows = tbody.querySelectorAll('tr');

// Создаем контейнер для карточек
const cardsContainer = document.createElement('div');
cardsContainer.className = 'mobile-cards-view';

rows.forEach(row => {
if (row.querySelector('.empty-state')) {
// Если это строка с пустым состоянием, просто копируем её
cardsContainer.appendChild(row.cloneNode(true));
return;
}

// Создаем карточку
const card = document.createElement('div');
card.className = 'curriculum-card';

// Получаем данные из строки таблицы
const cells = row.querySelectorAll('td');
const nameCell = cells[0];
const schoolCell = cells[1];
const gradesCell = cells[2];
const subjectsCell = cells[3];
const statusCell = cells[4];
const dateCell = cells[5];
const actionsCell = cells[6];

// Заполняем карточку
card.innerHTML = `
<div class="curriculum-card-header">
    <div class="curriculum-card-title">${nameCell.querySelector('.curriculum-name').textContent}</div>
    <div class="curriculum-card-school">${schoolCell.textContent}</div>
    ${nameCell.querySelector('.curriculum-description') ?
    `<div class="curriculum-card-description">${nameCell.querySelector('.curriculum-description').textContent}</div>` : ''}
</div>

<div class="curriculum-card-grades">
    ${gradesCell.innerHTML}
</div>

<div class="curriculum-card-subjects">
    ${subjectsCell.innerHTML.replace(/curriculum-subjects/g, '')}
</div>

<div class="curriculum-card-footer">
                <span class="curriculum-card-status ${statusCell.querySelector('.status-badge').classList.contains('status-active') ? 'status-active' : 'status-inactive'}">
                    ${statusCell.textContent.trim()}
                </span>
    <div class="curriculum-card-actions">
        ${actionsCell.innerHTML.replace(/action-buttons/g, 'curriculum-card-actions').replace(/btn-action/g, 'btn-card-action')}
    </div>
</div>
`;

cardsContainer.appendChild(card);
});

// Заменяем таблицу карточками
const tableContainer = table.closest('.table-responsive');
tableContainer.style.display = 'none';
tableContainer.parentNode.insertBefore(cardsContainer, tableContainer);
}

function removeMobileCardsView() {
const cardsView = document.querySelector('.mobile-cards-view');
if (cardsView) {
cardsView.remove();
}

const tableContainer = document.querySelector('.table-responsive');
if (tableContainer) {
tableContainer.style.display = 'block';
}
}
</script>
</body>
</html>