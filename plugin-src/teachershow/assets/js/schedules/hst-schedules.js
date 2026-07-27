jQuery(function ($) {
  const $page = $("[data-hst-schedule]");
  if (!$page.length) return;

  const activeTermId = parseInt($page.data("active-term-id"), 10) || 0;
  const $warnings = $("#hst-schedule-warnings");

  const state = {
    activeTerm: null,
    teachers: [],
    selectedTeacherId: 0,
    selectedLessons: {},
    allLessons: [],
    generationStatus: {
      can_generate: String($page.data("can-generate")) === "1",
      message: String($page.data("generation-message") || "تولید برنامه"),
    },
  };

  function esc(value) {
    return HST.escapeHtml(String(value == null ? "" : value));
  }

  function faNum(value) {
    return esc(String(value)).replace(/\d/g, (d) => "۰۱۲۳۴۵۶۷۸۹"[d]);
  }

  function normalizeScheduleList(value) {
    if (Array.isArray(value)) {
      return value;
    }

    if (value == null || value === false || value === "") {
      return [];
    }

    if (typeof value === "string") {
      const trimmed = value.trim();
      if (!trimmed) {
        return [];
      }

      try {
        return normalizeScheduleList(JSON.parse(trimmed));
      } catch (e) {
        return trimmed.split(",").map(function (item) {
          return item.trim();
        }).filter(Boolean);
      }
    }

    if (typeof value === "object") {
      return Object.keys(value).filter(function (key) {
        return !!value[key];
      });
    }

    return [];
  }

  function selectedData() {
    const blockedSlots = $(".hst-schedule-blocked-shift:checked").map(function () {
      return $(this).val();
    }).get();

    return {
      term_id: activeTermId,
      schedule_options: {
        ignore_teacher_shift_availability: $("#hst-ignore-teacher-shifts").is(":checked") ? 1 : 0,
        prefer_early_shifts: $("#hst-prefer-early-shifts").is(":checked") ? 1 : 0,
        blocked_slots: blockedSlots,
      },
    };
  }

  function updateBlockedSummary() {
    const count = $(".hst-schedule-blocked-shift:checked").length;
    const text = count
      ? faNum(count) + " زنگ بسته انتخاب شده"
      : "زنگ‌هایی که نباید در تولید برنامه استفاده شوند";
    $("[data-hst-schedule-blocked-summary]").text(text);
  }

  function applyScheduleOptions(options) {
    options = options || {};

    $("#hst-ignore-teacher-shifts").prop("checked", !!options.ignore_teacher_shift_availability);
    $("#hst-prefer-early-shifts").prop("checked", !!options.prefer_early_shifts);

    const blocked = {};
    normalizeScheduleList(options.blocked_slots).forEach(function (slot) {
      blocked[String(slot)] = true;
    });

    $(".hst-schedule-blocked-shift").each(function () {
      $(this).prop("checked", !!blocked[String($(this).val())]);
    });

    updateBlockedSummary();
  }

  function updateScheduleReportAreaVisibility() {
    const $area = $(".hst-schedule-report-area");
    if (!$area.length) return;

    const hasVisibleReport = $area.find("#hst-schedule-results:not([hidden]), #hst-schedule-warnings:not([hidden])").length > 0;
    $area.prop("hidden", !hasVisibleReport);
  }

  function showWarnings(items) {
    if (!items || !items.length) {
      $warnings.prop("hidden", true).empty();
      updateScheduleReportAreaVisibility();
      return;
    }

    $warnings.prop("hidden", false).html(items.map((item) => `<p>${esc(item)}</p>`).join(""));
    updateScheduleReportAreaVisibility();
  }

  function showResults(summary, notices, message) {
    const $results = $("#hst-schedule-results");
    const rows = Array.isArray(summary) ? summary : [];
    const noteRows = Array.isArray(notices) ? notices.filter(Boolean) : [];

    if (!rows.length && !noteRows.length && !message) {
      $results.prop("hidden", true).empty();
      updateScheduleReportAreaVisibility();
      return;
    }

    const total = rows.length;
    const completeRows = rows.filter((s) => !!s.is_complete);
    const incompleteRows = rows.filter((s) => !s.is_complete);
    const plannedUnits = rows.reduce((sum, item) => sum + (parseInt(item.planned_units || 0, 10) || 0), 0);
    const totalUnits = rows.reduce((sum, item) => sum + (parseInt(item.total_units || 0, 10) || 0), 0);

    let html = '<div class="hst-card hst-schedule-result-card hst-import-result-card-like">';
    html += '<div class="hst-card__header hst-card__header--row"><div><h3>گزارش تولید برنامه سراسری</h3><p class="hst-muted">خلاصه نتیجه تولید برنامه برای کلاس‌ها</p></div></div>';
    html += '<div class="hst-card__body">';

    html += '<div class="hst-report-stats">';
    html += '<div class="hst-report-stat hst-report-stat--total"><b>' + faNum(total) + '</b><span>کل کلاس‌ها</span></div>';
    html += '<div class="hst-report-stat hst-report-stat--new"><b>' + faNum(completeRows.length) + '</b><span>کامل</span></div>';
    html += '<div class="hst-report-stat hst-report-stat--skip"><b>' + faNum(incompleteRows.length) + '</b><span>نیازمند بررسی</span></div>';
    html += '<div class="hst-report-stat"><b>' + faNum(plannedUnits) + ' / ' + faNum(totalUnits) + '</b><span>واحد چیده‌شده</span></div>';
    html += '</div>';

    if (message && (incompleteRows.length || noteRows.length)) {
      html += '<p class="hst-alert hst-alert--warning">' + esc(message) + '</p>';
    }

    if (incompleteRows.length) {
      html += '<div class="hst-import-result-issues hst-schedule-result-issues">';
      html += '<div class="hst-import-result-issues__head">';
      html += '<div class="hst-import-result-issues__title"><b>کلاس‌های ناقص</b><span>این کلاس‌ها بعد از تولید سراسری هنوز نیاز به اصلاح اطلاعات یا محدودیت‌ها دارند.</span></div>';
      html += '<span class="hst-import-result-issues__count">' + faNum(incompleteRows.length) + '</span>';
      html += '</div><ul class="hst-import-result-issues__list">';

      incompleteRows.forEach(function (item) {
        html += '<li class="hst-import-result-issue">' +
          '<span class="hst-import-result-issue__dot" aria-hidden="true"></span>' +
          '<span class="hst-import-result-issue__text">' +
            '<span class="hst-import-result-issue__name">' + esc(item.class_name || 'کلاس بدون نام') + '</span>' +
            '<span> — </span>' +
            '<span class="hst-import-result-issue__reason">' + faNum(item.planned_units || 0) + ' از ' + faNum(item.total_units || 0) + ' واحد چیده شده</span>' +
          '</span>' +
        '</li>';
      });

      html += '</ul></div>';
    }

    if (noteRows.length) {
      html += '<div class="hst-import-result-issues hst-schedule-result-issues">';
      html += '<div class="hst-import-result-issues__head">';
      html += '<div class="hst-import-result-issues__title"><b>هشدارها</b><span>مواردی که بعد از تولید برنامه باید بررسی شوند.</span></div>';
      html += '<span class="hst-import-result-issues__count">' + faNum(noteRows.length) + '</span>';
      html += '</div><ul class="hst-import-result-issues__list">';

      noteRows.forEach(function (notice) {
        html += '<li class="hst-import-result-issue">' +
          '<span class="hst-import-result-issue__dot" aria-hidden="true"></span>' +
          '<span class="hst-import-result-issue__text"><span class="hst-import-result-issue__reason">' + esc(notice) + '</span></span>' +
        '</li>';
      });

      html += '</ul></div>';
    }

    html += '</div></div>';

    $results.prop("hidden", false).html(html);
    updateScheduleReportAreaVisibility();
  }

  function teacherSearchText(teacher) {
    return [
      teacher.display_name,
      teacher.phone,
      teacher.national_code,
      teacher.personnel_code,
      teacher.classes,
      teacher.lessons,
    ].filter(Boolean).join(" ").toLowerCase();
  }

  function commaCount(value) {
    value = $.trim(String(value || ""));
    if (!value) return 0;

    return value.split("،").map(function (item) {
      return $.trim(item);
    }).filter(Boolean).length;
  }

  function teacherMetaLabel(teacher) {
    const lessonCount = parseInt(teacher.lesson_count || 0, 10) || commaCount(teacher.lessons);
    const classCount = parseInt(teacher.class_count || 0, 10) || commaCount(teacher.classes);

    if (!lessonCount && !classCount) {
      return "بدون تخصیص درس";
    }

    if (lessonCount && classCount) {
      return faNum(lessonCount) + " درس در " + faNum(classCount) + " کلاس";
    }

    if (lessonCount) {
      return faNum(lessonCount) + " درس تخصیص‌داده‌شده";
    }

    return faNum(classCount) + " کلاس تخصیص‌داده‌شده";
  }

  function toComparableDigits(value) {
    const map = {
      "۰": "0", "۱": "1", "۲": "2", "۳": "3", "۴": "4",
      "۵": "5", "۶": "6", "۷": "7", "۸": "8", "۹": "9",
      "٠": "0", "١": "1", "٢": "2", "٣": "3", "٤": "4",
      "٥": "5", "٦": "6", "٧": "7", "٨": "8", "٩": "9",
    };

    return String(value || "").replace(/[۰-۹٠-٩]/g, function (digit) {
      return map[digit] || digit;
    });
  }

  function lessonSortInfo(lesson) {
    const name = toComparableDigits(lesson && lesson.lesson_name);
    const match = name.match(/^(.*?)(?:\s*([0-9]+))?$/);
    const base = $.trim((match && match[1]) || name);
    const number = match && match[2] ? parseInt(match[2], 10) : 0;

    return {
      base: base,
      number: Number.isFinite(number) ? number : 0,
      className: toComparableDigits(lesson && lesson.class_name),
      name: name,
    };
  }

  function compareLessons(a, b) {
    const ak = lessonSortInfo(a);
    const bk = lessonSortInfo(b);
    const baseCompare = ak.base.localeCompare(bk.base, "fa", { numeric: true, sensitivity: "base" });
    if (baseCompare) return baseCompare;

    if (ak.number !== bk.number) {
      return ak.number - bk.number;
    }

    const classCompare = window.HST && typeof HST.compareClassNames === "function"
      ? HST.compareClassNames(ak.className, bk.className)
      : ak.className.localeCompare(bk.className, "fa", { numeric: true, sensitivity: "base" });
    if (classCompare) return classCompare;

    return ak.name.localeCompare(bk.name, "fa", { numeric: true, sensitivity: "base" });
  }

  function syncGenerateButtonState() {
    const status = state.generationStatus || {};
    const canGenerate = !!activeTermId && !!status.can_generate && !isGlobalScheduleRunning;
    const message = String(status.message || (canGenerate ? "تولید برنامه" : "ابتدا پیش‌نیازهای تولید برنامه را تکمیل کنید."));
    const $button = $("#hst-generate-all-schedules");

    $button
      .prop("disabled", !canGenerate)
      .attr("title", message)
      .attr("aria-label", message);

    $page
      .attr("data-can-generate", canGenerate ? "1" : "0")
      .attr("data-generation-message", message);
  }

  function renderTeachers() {
    const query = $.trim($("#hst-schedule-teacher-search").val() || "").toLowerCase();
    const $list = $("#hst-schedule-teacher-list");

    if (!state.teachers.length) {
      $list.html('<p class="hst-alert hst-empty-state">برای سال تحصیلی فعال هنوز دبیری تعریف نشده است.</p>');
      return;
    }

    const teachers = state.teachers.filter((teacher) => !query || teacherSearchText(teacher).includes(query));

    if (!teachers.length) {
      $list.html('<p class="hst-muted">دبیری با این جست‌وجو پیدا نشد.</p>');
      return;
    }

    $list.html(teachers.map((teacher) => {
      const active = parseInt(teacher.id, 10) === state.selectedTeacherId ? " is-active" : "";
      const meta = teacherMetaLabel(teacher);
      const assigned = (parseInt(teacher.lesson_count || 0, 10) || parseInt(teacher.class_count || 0, 10)) > 0;
      return `
        <button type="button" class="hst-schedule-choice${active}" data-teacher-id="${esc(teacher.id)}" title="${esc(meta)}">
          <span class="hst-schedule-choice__dot${assigned ? " is-assigned" : ""}" aria-hidden="true"></span>
          <strong>${esc(teacher.display_name)}</strong>
          <span>${esc(meta)}</span>
        </button>`;
    }).join(""));
  }

  function lessonSelected(lessonId) {
    return !!state.selectedLessons[String(lessonId)];
  }

  function renderLessons() {
    const query = $.trim($("#hst-schedule-lesson-search").val() || "").toLowerCase();
    const $list = $("#hst-schedule-lesson-list");
    const previousScrollTop = $list.scrollTop();

    if (!state.selectedTeacherId) {
      $list.html('<p class="hst-muted">ابتدا دبیر را انتخاب کنید.</p>');
      return;
    }

    const lessons = state.allLessons.filter((lesson) => {
      const search = [lesson.lesson_name, lesson.class_name].filter(Boolean).join(" ").toLowerCase();
      return !query || search.includes(query);
    }).sort(compareLessons);

    if (!lessons.length) {
      $list.html('<p class="hst-muted">درسی پیدا نشد.</p>');
      return;
    }

    $list.html(lessons.map((lesson) => {
      const id = String(lesson.id);
      const selected = lessonSelected(id);
      const maxUnit = Math.max(0, Math.min(4, parseInt(lesson.max_unit || lesson.unit || 0, 10)));
      const selectedUnit = selected ? parseInt(state.selectedLessons[id].unit, 10) : Math.max(1, maxUnit || parseInt(lesson.unit || 1, 10) || 1);
      const disabled = maxUnit <= 0 && !selected;
      const optionMax = selected ? Math.max(maxUnit, selectedUnit) : maxUnit;
      const unitOptions = [1, 2, 3, 4].filter((unit) => unit <= optionMax).map((unit) => {
        return `<option value="${unit}" ${unit === selectedUnit ? "selected" : ""}>${faNum(unit)} واحد</option>`;
      }).join("");

      return `
        <div class="hst-schedule-lesson-option${selected ? " is-selected" : ""}${disabled ? " is-disabled" : ""}">
          <div class="hst-schedule-lesson-option__main">
            <strong>${esc(lesson.lesson_name)}</strong>
            <small>${esc(lesson.class_name)} · قابل تخصیص: ${faNum(optionMax)} واحد</small>
          </div>
          <div class="hst-schedule-lesson-option__controls">
            <select class="hst-schedule-lesson-unit" data-lesson-id="${esc(id)}" ${selected ? "" : "disabled"} aria-label="انتخاب واحد ${esc(lesson.lesson_name)}">
              ${unitOptions}
            </select>
            <label class="hst-switch" aria-label="انتخاب ${esc(lesson.lesson_name)}">
              <input type="checkbox" class="hst-schedule-lesson-toggle" value="${esc(id)}" ${selected ? "checked" : ""} ${disabled ? "disabled" : ""}>
              <span class="hst-switch__slider"></span>
            </label>
          </div>
        </div>`;
    }).join(""));
    $list.scrollTop(previousScrollTop);
  }

  function renderSelectedLessons() {
    const lessons = Object.values(state.selectedLessons).sort(compareLessons);
    const text = lessons.length ? faNum(lessons.length) + " درس منتخب" : "۰ درس منتخب";
    $('[data-hst-schedule-selected-count]').text(text);
  }

  function syncAssignmentWorkspace() {
    const hasTeacher = state.selectedTeacherId > 0;
    const $workspace = $('[data-hst-schedule-assignment-workspace]');

    $workspace.prop('hidden', !hasTeacher).attr('aria-hidden', hasTeacher ? 'false' : 'true');
    $('#hst-schedule-save-teacher-assignment, #hst-schedule-clear-teacher-form').prop('disabled', !hasTeacher || !activeTermId);

    if (!hasTeacher) {
      $('[data-hst-schedule-selected-teacher]').text('دبیری انتخاب نشده');
    }
  }

  function renderAllPickers() {
    renderTeachers();
    renderLessons();
    renderSelectedLessons();
    syncAssignmentWorkspace();
    syncGenerateButtonState();
  }

  function setAvailability(values) {
    const map = {};
    (values || []).forEach((item) => { map[item] = true; });
    $('[name="schedule_availability[]"]').each(function () {
      $(this).prop("checked", !!map[$(this).val()]);
    });
  }

  function getAvailability() {
    return $('[name="schedule_availability[]"]:checked').map(function () {
      return $(this).val();
    }).get();
  }

  async function loadAssignmentContext() {
    if (!activeTermId) {
      state.teachers = [];
      state.generationStatus = {
        can_generate: false,
        message: "برای تولید برنامه، ابتدا یک سال تحصیلی فعال تعریف کنید.",
      };
      renderAllPickers();
      return;
    }

    HST.loader.show();
    try {
      const response = await HST.ajax({
        action: "hst_schedule_assignment_context",
        term_id: activeTermId,
      });

      if (!response.success) {
        HST.toast(HST.getMessage(response, "خطا در دریافت اطلاعات برنامه هفتگی"), "error");
        return;
      }

      state.activeTerm = response.data.active_term || null;
      state.teachers = response.data.teachers || [];
      state.generationStatus = response.data.generation_status || {
        can_generate: false,
        message: "ابتدا پیش‌نیازهای تولید برنامه را تکمیل کنید.",
      };
      applyScheduleOptions(response.data.schedule_options || {});
      renderAllPickers();
    } finally {
      HST.loader.hide();
    }
  }

  async function loadLessonsForTeacher(teacherId) {
    const response = await HST.ajax({
      action: "hst_schedule_lessons_for_teacher",
      term_id: activeTermId,
      teacher_id: teacherId,
    });

    if (!response.success) {
      HST.toast(HST.getMessage(response, "درس‌ها دریافت نشد"), "error");
      return [];
    }

    return response.data.lessons || [];
  }

  async function loadTeacherProfile(teacherId) {
    if (!teacherId) return;

    HST.loader.show();
    try {
      const response = await HST.ajax({
        action: "hst_schedule_teacher_profile",
        term_id: activeTermId,
        teacher_id: teacherId,
      });

      if (!response.success) {
        HST.toast(HST.getMessage(response, "اطلاعات معلم دریافت نشد"), "error");
        return;
      }

      const data = response.data || {};
      const teacher = data.teacher || {};
      state.selectedTeacherId = parseInt(teacherId, 10);
      state.selectedLessons = {};
      state.allLessons = (await loadLessonsForTeacher(teacherId)).sort(compareLessons);

      (data.lessons || []).forEach((lesson) => {
        state.selectedLessons[String(lesson.id)] = {
          id: parseInt(lesson.id, 10),
          lesson_name: lesson.lesson_name,
          class_id: parseInt(lesson.class_id, 10),
          class_name: lesson.class_name,
          unit: parseInt(lesson.selected_unit || lesson.unit || 1, 10),
          max_unit: parseInt(lesson.max_unit || lesson.unit || 1, 10),
        };
      });

      setAvailability(data.availability || []);
      $('[data-hst-schedule-selected-teacher]').text(teacher.display_name || "دبیر انتخاب‌شده");
      renderAllPickers();
    } finally {
      HST.loader.hide();
    }
  }

  function addOrUpdateLesson(lessonId, unit) {
    const lesson = state.allLessons.find((item) => String(item.id) === String(lessonId));
    if (!lesson) return;

    state.selectedLessons[String(lessonId)] = {
      id: parseInt(lesson.id, 10),
      lesson_name: lesson.lesson_name,
      class_id: parseInt(lesson.class_id, 10),
      class_name: lesson.class_name,
      unit: parseInt(unit || 1, 10),
      max_unit: parseInt(lesson.max_unit || lesson.unit || 1, 10),
    };
  }

  function removeLesson(lessonId) {
    delete state.selectedLessons[String(lessonId)];
  }

  async function saveTeacherAssignment() {
    if (!state.selectedTeacherId) {
      HST.toast("ابتدا دبیر را انتخاب کنید.", "error");
      return;
    }

    const lessonUnits = {};
    Object.values(state.selectedLessons).forEach((lesson) => {
      lessonUnits[lesson.id] = lesson.unit;
    });

    HST.request({
      action: "hst_schedule_save_teacher_assignment",
      data: {
        term_id: activeTermId,
        teacher_id: state.selectedTeacherId,
        lesson_units: lessonUnits,
        availability: getAvailability(),
      },
      confirm: {
        title: "ذخیره‌سازی",
        text: "تخصیص درس‌ها و برنامه حضور این دبیر ذخیره و اطلاعات صفحه به‌روزرسانی شود؟",
      },
      successMessage: true,
      onSuccess() {
        const teacherId = state.selectedTeacherId;
        loadAssignmentContext().then(function () {
          loadTeacherProfile(teacherId);
        });
      },
    });
  }

  let saveOptionsTimer = null;
  function saveScheduleOptions() {
    if (!activeTermId) return;

    clearTimeout(saveOptionsTimer);
    saveOptionsTimer = setTimeout(async function () {
      try {
        await HST.ajax({
          action: "hst_schedule_save_options",
          term_id: activeTermId,
          schedule_options: selectedData().schedule_options,
        });
      } catch (e) {
        console.warn("Schedule options could not be saved", e);
      }
    }, 350);
  }

  let isGlobalScheduleRunning = false;

  function ensureGlobalScheduleModal() {
    let $modal = $("#hst-schedule-global-modal");

    if ($modal.length) {
      return $modal;
    }
    $modal = $(`
      <div class="hst-modal" data-hst-progress-modal data-hst-modal-size="md" id="hst-schedule-global-modal" role="dialog" aria-modal="true" aria-labelledby="hst-schedule-global-title" aria-hidden="true">
        <div class="hst-modal__backdrop"></div>
        <div class="hst-modal__panel">
          <div class="hst-modal__header">
            <div>
              <h3 id="hst-schedule-global-title">تولید سراسری برنامه همه کلاس‌ها</h3>
              <p>برنامه همه کلاس‌ها براساس سال تحصیلی فعال، تخصیص درس‌ها و حضور دبیران ساخته می‌شود.</p>
            </div>
            <button type="button" class="hst-modal__close hst-schedule-global-close" data-hst-progress-close aria-label="بستن">×</button>
          </div>
          <div class="hst-modal__body">
            <div class="hst-operation-progress" id="hst-schedule-global-progress" hidden aria-live="polite">
              <div class="hst-operation-progress__head">
                <strong class="hst-operation-progress__title">در حال تولید برنامه</strong>
                <span class="hst-operation-progress__percent">۰٪</span>
              </div>
              <div class="hst-operation-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <span class="hst-operation-progress__bar"></span>
              </div>
              <p class="hst-operation-progress__hint">تا تکمیل عملیات، صفحه را نبندید و ترک نکنید.</p>
            </div>
          </div>
          <div class="hst-modal__footer">
            <button type="button" class="hst-btn hst-btn--ghost hst-schedule-global-close" data-hst-progress-close>بستن</button>
          </div>
        </div>
      </div>
    `);

    $("body").append($modal);
    return $modal;
  }

  function ensureGlobalProgressBox() {
    const $modal = ensureGlobalScheduleModal();
    return $modal.find("#hst-schedule-global-progress");
  }

  function openGlobalScheduleModal() {
    const $modal = ensureGlobalScheduleModal();
    $modal.find(".hst-operation-progress__bar").css("width", "0%");
    $modal.find(".hst-operation-progress__percent").text("۰٪");
    $modal.find(".hst-operation-progress__track").attr("aria-valuenow", "0");
    $modal.find("#hst-schedule-global-progress").attr("hidden", true);
    $modal.find(".hst-modal__header .hst-schedule-global-close").prop("disabled", false).removeAttr("hidden").html("×").attr("aria-label", "بستن");
    $modal.find(".hst-modal__footer .hst-schedule-global-close").prop("disabled", false).removeAttr("hidden").text("بستن");
    $modal.addClass("is-open").attr("aria-hidden", "false");
    return $modal;
  }

  function closeGlobalScheduleModal() {
    if (isGlobalScheduleRunning) {
      HST.toast("تا پایان تولید برنامه، صفحه را نبندید یا ترک نکنید", "error");
      return false;
    }
    $("#hst-schedule-global-modal").removeClass("is-open").attr("aria-hidden", "true");
    return true;
  }

  function updateGlobalProgress(percent, text) {
    const $box = ensureGlobalProgressBox();
    const safePercent = Math.max(0, Math.min(100, parseInt(percent, 10) || 0));

    $box.removeAttr("hidden");
    $box.find(".hst-operation-progress__bar").css("width", `${safePercent}%`);
    $box.find(".hst-operation-progress__percent").text(faNum(`${safePercent}%`));
    $box.find(".hst-operation-progress__track").attr("aria-valuenow", String(safePercent));
    if (text) {
      $box.find(".hst-operation-progress__hint").text(text);
    }
  }

  function hideGlobalProgress() {
    $("#hst-schedule-global-progress").attr("hidden", true);
  }

  async function runGlobalScheduleGenerator(data) {
    let token = "";
    let finalPayload = null;
    const $modal = ensureGlobalScheduleModal();

    isGlobalScheduleRunning = true;
    syncGenerateButtonState();
    HST.setProgressModalLocked(
      $modal,
      true,
      "تا پایان تولید برنامه، صفحه را نبندید یا ترک نکنید."
    );
    updateGlobalProgress(0, "در حال شروع تولید برنامه...");

    try {
      const start = await HST.ajax({
        action: "hst_schedule_generate_all_start",
        ...data,
      });

      if (!start.success) {
        if (start.data && start.data.generation_status) {
          state.generationStatus = start.data.generation_status;
          syncGenerateButtonState();
        }
        hideGlobalProgress();
        HST.toast(HST.getMessage(start, "شروع تولید برنامه انجام نشد"), "error");
        return;
      }

      token = start.data.token;
      updateGlobalProgress(1, "شروع بررسی حالت‌ها...");

      while (true) {
        const step = await HST.ajax({
          action: "hst_schedule_generate_all_step",
          token,
        });

        if (!step.success) {
          HST.toast(HST.getMessage(step, "تولید مرحله‌ای برنامه انجام نشد"), "error");
          break;
        }

        const payload = step.data || {};
        updateGlobalProgress(payload.progress || 0, payload.message || "در حال بررسی...");

        if (payload.is_done) {
          finalPayload = payload;
          break;
        }

        await new Promise((resolve) => setTimeout(resolve, 120));
      }

      if (!finalPayload) return;

      const scheduleWarnings = finalPayload.warnings || [];
      showResults(finalPayload.class_summary || [], scheduleWarnings, finalPayload.message || "");
      showWarnings([]);

      const hasSavedSchedule = (finalPayload.class_summary || []).some(function (item) {
        return (parseInt(item.planned_units || 0, 10) || 0) > 0;
      });
      const $schoolScheduleDownload = $('[data-hst-schedule-pdf][data-type="all_classes"]');
      $schoolScheduleDownload
        .prop("disabled", !hasSavedSchedule)
        .attr("title", hasSavedSchedule ? "دریافت برنامه هفتگی مدرسه" : "هنوز برنامه هفتگی ذخیره‌شده‌ای وجود ندارد.")
        .attr("aria-label", hasSavedSchedule ? "دریافت برنامه هفتگی مدرسه" : "هنوز برنامه هفتگی ذخیره‌شده‌ای وجود ندارد.");

      updateGlobalProgress(100, "برنامه‌ریزی کامل شد.");
      const message = HST.getMessage({ data: finalPayload }, "برنامه سراسری تولید و ذخیره شد");
      HST.toast(message, "success");
    } catch (e) {
      console.error("Global schedule generator failed", e);
      hideGlobalProgress();
      HST.toast("خطای سرور هنگام تولید برنامه. لطفاً دوباره تلاش کنید.", "error");
    } finally {
      isGlobalScheduleRunning = false;
      syncGenerateButtonState();
      HST.setProgressModalLocked($modal, false);
      $modal.find(".hst-modal__header .hst-schedule-global-close").html("×").attr("aria-label", "بستن");
      $modal.find(".hst-modal__footer .hst-schedule-global-close").text("بستن");
    }
  }

  $(window).off("beforeunload.hstScheduleGlobal").on("beforeunload.hstScheduleGlobal", function () {
    if (isGlobalScheduleRunning) {
      return "تولید سراسری برنامه هنوز کامل نشده است.";
    }
  });

  $("#hst-generate-all-schedules").on("click", function () {
    if (isGlobalScheduleRunning) return;

    if (!activeTermId) {
      HST.toast("سال تحصیلی فعالی وجود ندارد.", "error");
      return;
    }

    if (!state.generationStatus || !state.generationStatus.can_generate) {
      HST.toast(
        String((state.generationStatus && state.generationStatus.message) || "ابتدا پیش‌نیازهای تولید برنامه را تکمیل کنید."),
        "error"
      );
      syncGenerateButtonState();
      return;
    }

    const data = selectedData();
    const $modal = openGlobalScheduleModal();

    $modal.find(".hst-schedule-global-close")
      .off("click.hstGlobalSchedule")
      .on("click.hstGlobalSchedule", function () {
        closeGlobalScheduleModal();
      });

    runGlobalScheduleGenerator(data);
  });

  function openBlockedSlotsModal() {
    $("#hst-schedule-blocked-modal").addClass("is-open").attr("aria-hidden", "false");
  }

  function closeBlockedSlotsModal() {
    $("#hst-schedule-blocked-modal").removeClass("is-open").attr("aria-hidden", "true");
    updateBlockedSummary();
  }

  $("#hst-schedule-blocked-trigger").on("click", openBlockedSlotsModal);
  $(document).on("click", "[data-hst-schedule-blocked-close]", closeBlockedSlotsModal);
  $(document).on("click", "[data-hst-schedule-blocked-confirm]", function () {
    saveScheduleOptions();
    closeBlockedSlotsModal();
    HST.toast("زنگ‌های بسته ذخیره شد", "success");
  });
  $(document).on("keydown.hstScheduleBlocked", function (event) {
    if (event.key === "Escape" && $("#hst-schedule-blocked-modal").hasClass("is-open")) {
      closeBlockedSlotsModal();
    }
  });

  $(document).on("change", ".hst-schedule-option-input", function () {
    updateBlockedSummary();
    saveScheduleOptions();
  });
  $("#hst-schedule-teacher-search").on("input", renderTeachers);
  $("#hst-schedule-lesson-search").on("input", renderLessons);

  $(document).on("click", ".hst-schedule-choice[data-teacher-id]", function () {
    loadTeacherProfile(parseInt($(this).data("teacher-id"), 10));
  });

  let lessonScrollSnapshot = null;

  function rememberLessonScroll() {
    const list = document.getElementById("hst-schedule-lesson-list");
    const page = document.scrollingElement || document.documentElement;

    lessonScrollSnapshot = {
      listTop: list ? list.scrollTop : 0,
      pageTop: page ? page.scrollTop : window.pageYOffset,
      pageLeft: page ? page.scrollLeft : window.pageXOffset,
    };
  }

  function restoreLessonScroll() {
    if (!lessonScrollSnapshot) return;

    const snapshot = lessonScrollSnapshot;
    const restore = function () {
      const list = document.getElementById("hst-schedule-lesson-list");
      const page = document.scrollingElement || document.documentElement;

      if (list) list.scrollTop = snapshot.listTop;
      if (page) {
        page.scrollTop = snapshot.pageTop;
        page.scrollLeft = snapshot.pageLeft;
      }
    };

    restore();
    window.requestAnimationFrame(function () {
      restore();
      window.requestAnimationFrame(function () {
        restore();
        lessonScrollSnapshot = null;
      });
    });
  }

  $(document).on("pointerdown touchstart", ".hst-schedule-lesson-option .hst-switch", rememberLessonScroll);

  $(document).on("change", ".hst-schedule-lesson-toggle", function () {
    if (!lessonScrollSnapshot) rememberLessonScroll();

    const lessonId = String($(this).val());
    const $card = $(this).closest(".hst-schedule-lesson-option");
    const unit = parseInt($card.find(".hst-schedule-lesson-unit").val(), 10) || 1;

    if ($(this).is(":checked")) {
      addOrUpdateLesson(lessonId, unit);
      $card.addClass("is-selected");
      $card.find(".hst-schedule-lesson-unit").prop("disabled", false);
    } else {
      removeLesson(lessonId);
      $card.removeClass("is-selected");
      $card.find(".hst-schedule-lesson-unit").prop("disabled", true);
    }

    renderSelectedLessons();
    restoreLessonScroll();
  });

  $(document).on("change", ".hst-schedule-lesson-unit", function () {
    const lessonId = String($(this).data("lesson-id"));
    if (!lessonSelected(lessonId)) return;

    addOrUpdateLesson(lessonId, parseInt($(this).val(), 10) || 1);
    renderSelectedLessons();
  });


  $("#hst-schedule-clear-teacher-form").on("click", function () {
    if (!state.selectedTeacherId) return;

    state.selectedLessons = {};
    setAvailability([]);
    renderLessons();
    renderSelectedLessons();
  });

  $("#hst-schedule-save-teacher-assignment").on("click", saveTeacherAssignment);

  let teacherResizeTimer = null;
  $(window).on("resize.hstScheduleTeachers", function () {
    clearTimeout(teacherResizeTimer);
    teacherResizeTimer = setTimeout(renderTeachers, 120);
  });

  if (activeTermId) {
    loadAssignmentContext();
  }
});
