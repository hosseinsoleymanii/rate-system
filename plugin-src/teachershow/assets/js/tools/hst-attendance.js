jQuery(function ($) {
  const data = window.hstAttendanceData || { classes: {}, statuses: {} };
  const $class = $('#hst-attendance-class');
  const $lesson = $('#hst-attendance-lesson');
  const $date = $('#hst-attendance-date');
  const $shift = $('#hst-attendance-shift');
  const $rows = $('#hst-attendance-rows');
  const $save = $('#hst-attendance-save');
  const $count = $('#hst-attendance-count');
  const $search = $('#hst-attendance-search');

  const statusOptions = Object.entries(data.statuses || {}).map(([value, label]) => ({ value, label }));

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>\"']/g, (char) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '\"': '&quot;',
      "'": '&#039;',
    }[char]));
  }

  function normalizeNote(value) {
    return String(value || '').slice(0, 300);
  }

  function classLessons(classId) {
    const item = (data.classes || {})[classId];
    return item && Array.isArray(item.lessons) ? item.lessons : [];
  }

  function fillLessons() {
    const classId = $class.val();
    const lessons = classLessons(classId);
    $lesson.empty();

    if (!lessons.length) {
      $lesson.append('<option value="">درسی برای این کلاس یافت نشد</option>').prop('disabled', true);
      return;
    }

    $lesson.append('<option value="">انتخاب درس</option>');
    lessons.forEach((lesson) => {
      $lesson.append(`<option value="${Number(lesson.id) || 0}">${escapeHtml(lesson.name)}</option>`);
    });
    $lesson.prop('disabled', false);
  }

  function rowTemplate(student, index) {
    const safeStatus = statusOptions.some((status) => status.value === student.status) ? student.status : 'present';
    const safeName = escapeHtml(student.name);
    const safePhone = escapeHtml(student.phone);
    const safeNote = escapeHtml(normalizeNote(student.note));
    const safeStudentId = Number(student.student_id) || 0;
    const safeLate = Math.max(0, Math.min(240, parseInt(student.late_minutes || 0, 10) || 0));
    const options = statusOptions.map((status) => (
      `<option value="${escapeHtml(status.value)}" ${safeStatus === status.value ? 'selected' : ''}>${escapeHtml(status.label)}</option>`
    )).join('');

    const initials = escapeHtml(
      student.initials || HST.initials(student.name || "", student.first_name || "", student.last_name || "")
    );
    const avatar = student.avatar
      ? `<span class="hst-user-avatar"><img src="${escapeHtml(student.avatar)}" alt="${safeName}" loading="lazy"></span>`
      : `<span class="hst-user-avatar hst-user-avatar--placeholder" aria-label="بدون تصویر پروفایل؛ حروف اول نام ${safeName}"><span class="hst-user-avatar__placeholder">${initials}</span></span>`;

    return `
      <tr class="hst-attendance-row" data-student-id="${safeStudentId}" data-name="${safeName.toLowerCase()}">
        <td class="hst-row-num">${index + 1}</td>
        <td>
          <div class="hst-attendance-student">
            ${avatar}
            <span class="hst-attendance-student__text">
              <strong>${safeName}</strong>
              ${safePhone ? `<small>${safePhone}</small>` : ''}
            </span>
          </div>
        </td>
        <td>
          <select class="hst-attendance-status">${options}</select>
        </td>
        <td>
          <input type="number" class="hst-attendance-late" min="0" max="240" value="${safeLate}" ${safeStatus === 'late' ? '' : 'disabled'}>
        </td>
        <td>
          <input type="text" class="hst-attendance-note" value="${safeNote}" maxlength="300" placeholder="توضیح کوتاه...">
        </td>
      </tr>
    `;
  }

  function validateFilters() {
    if (!$class.val() || !$lesson.val() || !$date.val() || !$shift.val()) {
      HST.toast('کلاس، درس، تاریخ و زنگ را کامل انتخاب کنید.', 'error');
      return false;
    }
    return true;
  }

  async function loadStudents() {
    if (!validateFilters()) return;

    $save.prop('disabled', true);
    $rows.html('<tr><td colspan="5" class="hst-attendance-empty">' + HST.loadingMarkup() + '</td></tr>');

    const response = await HST.request({
      action: 'hst_attendance_load_students',
      data: {
        class_id: $class.val(),
        lesson_id: $lesson.val(),
        attendance_date: $date.val(),
        school_shift: $shift.val(),
      },
      showLoader: false,
    });

    if (!response || !response.success) {
      $rows.html('<tr><td colspan="5" class="hst-attendance-empty">دریافت فهرست دانش‌آموزان انجام نشد.</td></tr>');
      return;
    }

    const students = response.data.students || [];
    $count.text(HST.getMessage(response, ''));

    if (!students.length) {
      $rows.html('<tr><td colspan="5" class="hst-attendance-empty">دانش‌آموزی یافت نشد.</td></tr>');
      return;
    }

    $rows.html(students.map(rowTemplate).join(''));
    $save.prop('disabled', false);
    filterRows();
  }

  function collectRows() {
    const rows = [];
    $rows.find('.hst-attendance-row').each(function () {
      const $row = $(this);
      rows.push({
        student_id: $row.data('student-id'),
        status: $row.find('.hst-attendance-status').val(),
        late_minutes: Math.max(0, Math.min(240, parseInt($row.find('.hst-attendance-late').val(), 10) || 0)),
        note: normalizeNote($row.find('.hst-attendance-note').val()),
      });
    });
    return rows;
  }

  function saveAttendance() {
    if (!validateFilters()) return;
    const rows = collectRows();
    if (!rows.length || rows.length > 120) {
      HST.toast('لیست حضور و غیاب خالی یا بیش از حد مجاز است.', 'error');
      return;
    }

    HST.request({
      action: 'hst_attendance_save',
      data: {
        class_id: $class.val(),
        lesson_id: $lesson.val(),
        attendance_date: $date.val(),
        school_shift: $shift.val(),
        rows,
      },
      successMessage: true,
    });
  }

  function filterRows() {
    const term = String($search.val() || '').trim().toLowerCase();
    $rows.find('.hst-attendance-row').each(function () {
      const $row = $(this);
      $row.toggle(!term || String($row.data('name')).includes(term));
    });
  }

  // Base shift option labels (before any per-day annotation).
  const baseShiftLabels = {};
  $shift.find('option').each(function () {
    baseShiftLabels[$(this).val()] = $(this).text();
  });

  // Fetch the weekdays/shifts the teacher actually has class for the chosen
  // class+lesson, then (a) restrict the date picker to those weekdays and
  // (b) annotate the shift dropdown with the lesson on each shift.
  function refreshTeachingSlots() {
    const classId = $class.val();
    const lessonId = $lesson.val();

    // Reset to unconstrained until we have an answer.
    $date.removeAttr('data-hst-allowed-weekdays');
    $shift.find('option').each(function () {
      const v = $(this).val();
      if (baseShiftLabels[v] !== undefined) $(this).text(baseShiftLabels[v]);
    });

    if (!classId || !lessonId) return;

    HST.request({
      action: 'hst_attendance_slots',
      data: { class_id: classId, lesson_id: lessonId },
      onSuccess(response) {
        const payload = (response && response.data) || {};
        const weekdays = Array.isArray(payload.weekdays) ? payload.weekdays : [];
        const slots = Array.isArray(payload.slots) ? payload.slots : [];

        if (weekdays.length) {
          $date.attr('data-hst-allowed-weekdays', weekdays.join(','));
        } else {
          $date.removeAttr('data-hst-allowed-weekdays');
        }

        // Annotate each shift with the lesson taught (helps the teacher pick).
        const byShift = {};
        slots.forEach((slot) => {
          if (slot && slot.shift) {
            byShift[slot.shift] = byShift[slot.shift] || slot.lesson;
          }
        });
        $shift.find('option').each(function () {
          const v = $(this).val();
          const base = baseShiftLabels[v] !== undefined ? baseShiftLabels[v] : $(this).text();
          $(this).text(byShift[v] ? `${base} — ${byShift[v]}` : base);
        });
      }
    });
  }

  $class.on('change', function () {
    fillLessons();
    refreshTeachingSlots();
    $rows.html('<tr><td colspan="5" class="hst-attendance-empty">برای شروع، درس را انتخاب و لیست را نمایش دهید.</td></tr>');
    $save.prop('disabled', true);
  });

  $lesson.on('change', refreshTeachingSlots);

  $(document).on('change', '.hst-attendance-status', function () {
    const $row = $(this).closest('tr');
    const isLate = $(this).val() === 'late';
    $row.find('.hst-attendance-late').prop('disabled', !isLate).val(isLate ? $row.find('.hst-attendance-late').val() : 0);
  });

  $('[data-hst-bulk-status]').on('click', function () {
    const status = $(this).data('hst-bulk-status');
    $rows.find('.hst-attendance-row:visible .hst-attendance-status').val(status).trigger('change');
  });

  $('#hst-attendance-load').on('click', loadStudents);
  $('#hst-attendance-save').on('click', saveAttendance);
  $search.on('input', filterRows);
});