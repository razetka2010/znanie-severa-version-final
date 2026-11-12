document.addEventListener('DOMContentLoaded', function () {
    const journal = document.querySelector('.journal-app');
    if (!journal) {
        return;
    }

    const csrfToken = journal.querySelector('.journal-csrf-token input[name="csrf_token"]').value;
    let activeCell = null;
    let activePopover = null;

    function popoverElement(cell, selector) {
        const popover = activeCell === cell && activePopover
            ? activePopover
            : cell.querySelector('.journal-popover');
        return popover ? popover.querySelector(selector) : null;
    }

    function showCellMessage(cell, message, isError) {
        const messageElement = popoverElement(cell, '.journal-cell-message');
        messageElement.textContent = message;
        messageElement.classList.toggle('is-error', Boolean(isError));
        messageElement.hidden = false;
    }

    function updateCellDisplay(cell, gradeValue, attendanceStatus) {
        cell.dataset.gradeValue = gradeValue || '';
        cell.dataset.attendanceStatus = attendanceStatus;

        const valueElement = cell.querySelector('.journal-cell-value');
        const attendanceElement = cell.querySelector('.journal-cell-attendance');
        const shownValue = gradeValue || (attendanceStatus === 'absent' ? 'Н' : attendanceStatus === 'late' ? 'О' : '+');

        valueElement.textContent = shownValue;
        valueElement.classList.toggle('is-empty', !gradeValue && attendanceStatus === 'present');
        valueElement.classList.toggle('is-absent', !gradeValue && attendanceStatus === 'absent');
        valueElement.classList.toggle('is-late', !gradeValue && attendanceStatus === 'late');
        attendanceElement.textContent = gradeValue && attendanceStatus === 'absent'
            ? 'Н'
            : gradeValue && attendanceStatus === 'late' ? 'О' : '';
        attendanceElement.classList.toggle('is-absent', gradeValue !== '' && attendanceStatus === 'absent');
        attendanceElement.classList.toggle('is-late', gradeValue !== '' && attendanceStatus === 'late');

        const warning = popoverElement(cell, '.journal-warning');
        warning.hidden = attendanceStatus !== 'absent';

        const extra = popoverElement(cell, '.journal-extra');
        extra.hidden = !gradeValue;
        if (gradeValue) {
            const column = cell.closest('tr').cells[cell.closest('td').cellIndex];
            const typeSelect = column.querySelector('.column-grade-type');
            const metaType = extra.querySelector('.grade-meta-type');
            const metaWeight = extra.querySelector('.grade-meta-weight');
            if (typeSelect && metaType && !cell.dataset.gradeTypeId) {
                metaType.value = typeSelect.value;
            }
            if (metaType) {
                cell.dataset.gradeTypeId = metaType.value;
            }
            if (metaWeight) {
                cell.dataset.gradeWeightId = metaWeight.value;
            }
        }
    }

    function updateAverage(cell, average) {
        const averageElement = cell.closest('tr').querySelector('.student-average');
        if (averageElement) {
            averageElement.textContent = average === null || average === undefined ? 'н/д' : String(average);
        }
    }

    async function sendAction(cell, action, values) {
        const messageElement = popoverElement(cell, '.journal-cell-message');
        const warning = popoverElement(cell, '.journal-warning');
        const previousTypeId = cell.dataset.gradeTypeId;
        const previousWeightId = cell.dataset.gradeWeightId;
        const payload = new FormData();
        const controls = Array.from(cell.querySelectorAll('button, select'));
        if (activeCell === cell && activePopover) {
            controls.push(...activePopover.querySelectorAll('button, select'));
        }
        controls.forEach(control => {
            control.disabled = true;
        });
        payload.append('csrf_token', csrfToken);
        payload.append('action', action);
        payload.append('class_id', journal.dataset.classId);
        payload.append('subject_id', journal.dataset.subjectId);
        payload.append('period_id', journal.dataset.periodId);
        payload.append('student_id', cell.dataset.studentId);
        payload.append('lesson_date', cell.dataset.lessonDate);

        Object.entries(values).forEach(([key, value]) => payload.append(key, value));
        messageElement.hidden = true;

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: payload,
                credentials: 'same-origin'
            });
            let result;
            try {
                result = await response.json();
            } catch (error) {
                throw new Error('Сервер вернул некорректный ответ. Обновите страницу и повторите действие.');
            }

            if (!response.ok || !result.ok) {
                restoreGradeMeta(cell, previousTypeId, previousWeightId);
                showCellMessage(cell, result.message || 'Не удалось сохранить изменения.', true);
                return false;
            }

            updateCellDisplay(cell, result.new_value || '', result.attendance_status || 'present');
            updateAverage(cell, result.average);
            if (Object.prototype.hasOwnProperty.call(result, 'grade_type_id')) {
                cell.dataset.gradeTypeId = result.grade_type_id || '';
                const metaType = popoverElement(cell, '.grade-meta-type');
                if (metaType && result.grade_type_id) {
                    metaType.value = String(result.grade_type_id);
                }
            }
            if (Object.prototype.hasOwnProperty.call(result, 'grade_weight_id')) {
                cell.dataset.gradeWeightId = result.grade_weight_id || '';
                const metaWeight = popoverElement(cell, '.grade-meta-weight');
                if (metaWeight) {
                    metaWeight.value = result.grade_weight_id ? String(result.grade_weight_id) : '';
                }
            }
            showCellMessage(cell, result.message || 'Изменения сохранены.', false);
            warning.hidden = result.attendance_status !== 'absent';
            return true;
        } catch (error) {
            restoreGradeMeta(cell, previousTypeId, previousWeightId);
            showCellMessage(cell, error.message || 'Ошибка соединения. Изменения не сохранены.', true);
            return false;
        } finally {
            controls.forEach(control => {
                control.disabled = false;
            });
        }
    }

    function restoreGradeMeta(cell, typeId, weightId) {
        const metaType = popoverElement(cell, '.grade-meta-type');
        const metaWeight = popoverElement(cell, '.grade-meta-weight');
        if (metaType && typeId) {
            metaType.value = String(typeId);
        }
        if (metaWeight) {
            metaWeight.value = weightId ? String(weightId) : '';
        }
    }

    function closePopovers(exceptCell) {
        journal.querySelectorAll('.journal-cell.is-open').forEach(cell => {
            if (cell === exceptCell) {
                return;
            }
            cell.classList.remove('is-open');
            cell.querySelector('.journal-cell-trigger').setAttribute('aria-expanded', 'false');
            const popover = activeCell === cell ? activePopover : cell.querySelector('.journal-popover');
            popover.hidden = true;
            popover.removeAttribute('style');
            cell.appendChild(popover);
            if (activeCell === cell) {
                activePopover = null;
            }
        });
        activeCell = exceptCell || null;
    }

    function positionPopover(cell) {
        const trigger = cell.querySelector('.journal-cell-trigger');
        const popover = activeCell === cell ? activePopover : cell.querySelector('.journal-popover');
        if (popover.parentElement !== document.body) {
            document.body.appendChild(popover);
        }

        const triggerRect = trigger.getBoundingClientRect();
        const popoverRect = popover.getBoundingClientRect();
        const margin = 12;
        const spaceBelow = window.innerHeight - triggerRect.bottom;
        const spaceAbove = triggerRect.top;
        let top = triggerRect.bottom + 8;

        if (spaceBelow < popoverRect.height + margin && spaceAbove > spaceBelow) {
            top = triggerRect.top - popoverRect.height - 8;
        }
        top = Math.max(margin, Math.min(top, window.innerHeight - popoverRect.height - margin));

        const left = Math.max(
            margin,
            Math.min(
                triggerRect.left + triggerRect.width / 2 - popoverRect.width / 2,
                window.innerWidth - popoverRect.width - margin
            )
        );
        popover.style.top = top + 'px';
        popover.style.left = left + 'px';
    }

    function openPopover(cell, toggle) {
        if (activeCell === cell && toggle !== false) {
            closePopovers(null);
            return;
        }
        let popover = activeCell === cell && activePopover
            ? activePopover
            : cell.querySelector('.journal-popover');
        closePopovers(cell);
        cell.classList.add('is-open');
        cell.querySelector('.journal-cell-trigger').setAttribute('aria-expanded', 'true');
        activePopover = popover;
        popover.hidden = false;
        positionPopover(cell);
        const warning = popoverElement(cell, '.journal-warning');
        warning.hidden = cell.dataset.attendanceStatus !== 'absent';
        const message = popoverElement(cell, '.journal-cell-message');
        message.hidden = true;
    }

    function columnGradeType(cell) {
        const td = cell.closest('td');
        const header = journal.querySelector('.journal-table thead tr:nth-child(2)').cells[td.cellIndex];
        const typeSelect = header ? header.querySelector('.column-grade-type') : null;
        return typeSelect ? typeSelect.value : '';
    }

    async function saveColumnGradeType(select) {
        const header = select.closest('.date-header');
        const status = header.querySelector('.column-grade-type-status');
        const previousValue = select.dataset.savedValue;
        const payload = new FormData();
        payload.append('csrf_token', csrfToken);
        payload.append('action', 'set_lesson_type');
        payload.append('class_id', journal.dataset.classId);
        payload.append('subject_id', journal.dataset.subjectId);
        payload.append('period_id', journal.dataset.periodId);
        payload.append('lesson_date', header.dataset.lessonDate);
        payload.append('grade_type_id', select.value);

        select.disabled = true;
        status.hidden = true;

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: payload,
                credentials: 'same-origin'
            });
            let result;
            try {
                result = await response.json();
            } catch (error) {
                throw new Error('Сервер вернул некорректный ответ. Обновите страницу и повторите действие.');
            }

            if (!response.ok || !result.ok) {
                throw new Error(result.message || 'Не удалось сохранить тип работы.');
            }
            select.dataset.savedValue = select.value;
            status.textContent = result.message || 'Тип работы сохранён.';
            status.classList.remove('is-error');
            status.hidden = false;
        } catch (error) {
            select.value = previousValue;
            status.textContent = error.message || 'Ошибка соединения. Тип работы не сохранён.';
            status.classList.add('is-error');
            status.hidden = false;
        } finally {
            select.disabled = false;
        }
    }

    journal.querySelectorAll('.column-grade-type').forEach(select => {
        select.dataset.savedValue = select.value;
        select.addEventListener('change', function () {
            saveColumnGradeType(this);
        });
    });

    document.addEventListener('click', async function (event) {
        const trigger = event.target.closest('.journal-cell-trigger');
        if (trigger) {
            openPopover(trigger.closest('.journal-cell'));
            return;
        }

        const cell = event.target.closest('.journal-cell') || (event.target.closest('.journal-popover') ? activeCell : null);
        if (!cell) {
            return;
        }

        const gradeButton = event.target.closest('.grade-action');
        if (gradeButton) {
            const gradeValue = gradeButton.dataset.gradeValue;
            const isAbsent = cell.dataset.attendanceStatus === 'absent';
            popoverElement(cell, '.journal-warning').hidden = !isAbsent;
            await sendAction(cell, 'set_grade', {
                grade_value: gradeValue,
                grade_type_id: columnGradeType(cell)
            });
            return;
        }

        const attendanceButton = event.target.closest('.attendance-action');
        if (attendanceButton) {
            const requestedStatus = attendanceButton.dataset.attendanceStatus;
            const nextStatus = requestedStatus !== 'present' && requestedStatus === cell.dataset.attendanceStatus
                ? 'present'
                : requestedStatus;
            await sendAction(cell, 'set_attendance', { attendance_status: nextStatus });
            return;
        }

        if (event.target.closest('.clear-action')) {
            await sendAction(cell, 'clear_grade', {});
            return;
        }

        if (event.target.closest('.save-meta-action')) {
            const metaType = popoverElement(cell, '.grade-meta-type');
            const metaWeight = popoverElement(cell, '.grade-meta-weight');
            if (!metaType || !metaWeight || !cell.dataset.gradeValue) {
                return;
            }
            await sendAction(cell, 'set_grade', {
                grade_value: cell.dataset.gradeValue,
                grade_type_id: metaType.value,
                grade_weight_id: metaWeight.value
            });
        }
    });

    document.addEventListener('click', function (event) {
        if (activeCell && !activeCell.contains(event.target) && !event.target.closest('.journal-popover')) {
            closePopovers(null);
        }
    });

    window.addEventListener('scroll', function () {
        if (activeCell) {
            positionPopover(activeCell);
        }
    }, true);

    window.addEventListener('resize', function () {
        if (activeCell) {
            positionPopover(activeCell);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closePopovers(null);
            return;
        }

        const trigger = event.target.closest && event.target.closest('.journal-cell-trigger');
        if (!trigger) {
            return;
        }
        if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) {
            event.preventDefault();
            const current = trigger.closest('.journal-cell');
            const currentRow = current.closest('tr');
            const cellIndex = current.closest('td').cellIndex;
            let targetRow = currentRow;
            let targetCellIndex = cellIndex;
            if (event.key === 'ArrowLeft') {
                targetCellIndex--;
            } else if (event.key === 'ArrowRight') {
                targetCellIndex++;
            } else {
                targetRow = event.key === 'ArrowUp' ? currentRow.previousElementSibling : currentRow.nextElementSibling;
            }
            const target = targetRow && targetRow.cells[targetCellIndex];
            const targetCell = target && target.querySelector('.journal-cell');
            if (targetCell) {
                targetCell.querySelector('.journal-cell-trigger').focus();
            }
            return;
        }

        const cell = trigger.closest('.journal-cell');
        if (['5', '4', '3', '2'].includes(event.key) || ['н', 'Н', 'n', 'N'].includes(event.key)) {
            event.preventDefault();
            openPopover(cell, false);
            if (['н', 'Н', 'n', 'N'].includes(event.key)) {
                const nextStatus = cell.dataset.attendanceStatus === 'absent' ? 'present' : 'absent';
                sendAction(cell, 'set_attendance', { attendance_status: nextStatus });
            } else {
                const isAbsent = cell.dataset.attendanceStatus === 'absent';
                popoverElement(cell, '.journal-warning').hidden = !isAbsent;
                sendAction(cell, 'set_grade', {
                    grade_value: event.key,
                    grade_type_id: columnGradeType(cell)
                });
            }
        } else if (event.key === 'Delete' || event.key === 'Backspace') {
            event.preventDefault();
            openPopover(cell);
            sendAction(cell, 'clear_grade', {});
        }
    });
});
