jQuery(function ($) {
  "use strict";

  const $page = $("[data-hst-enter-scores]");
  if (!$page.length) return;

  const $class = $("#hst-score-class");
  const $lesson = $("#hst-score-lesson");
  const $period = $("#hst-score-period");
  const $sort = $("#hst-score-sort");
  const $form = $("#hst-score-form");
  const $list = $("#hst-score-students");
  const $result = $("#hst-score-result");
  const $empty = $("#hst-score-empty");
  const $emptyText = $("#hst-score-empty-text");
  const $statbar = $("#hst-score-statbar");
  const $save = $("#hst-save-scores");
  const $formNote = $("#hst-score-form-note");

  const state = {
    classes: [],
    students: [],
    scores: {},
    slots: [],
    suggestions: {},
    canEdit: false,
  };

  function opt(value, text) {
    return `<option value="${HST.escapeHtml(value)}">${HST.escapeHtml(text)}</option>`;
  }

  function faNum(value) {
    return HST.escapeHtml(String(value ?? "")).replace(/\d/g, (digit) => "۰۱۲۳۴۵۶۷۸۹"[digit]);
  }

  function normalizeScore(value) {
    const normalized = String(value ?? "").trim().replace("،", ".").replace(",", ".");
    if (!normalized) return "";
    if (!/^\d{1,2}(?:\.\d{1,2})?$/.test(normalized)) return null;
    const number = Number(normalized);
    if (Number.isNaN(number) || number < 0 || number > 20) return null;
    return String(Math.round(number * 100) / 100);
  }

  function band(score) {
    if (score === "" || score === null || score === undefined) return "";
    const number = Number(score);
    if (!Number.isFinite(number)) return "";
    if (number < 10) return "low";
    if (number < 15) return "mid";
    return "high";
  }

  function studentScores(studentId) {
    return state.scores[String(studentId)] || state.scores[studentId] || {};
  }

  function slotItem(studentId, slotKey) {
    const items = studentScores(studentId);
    return items[String(slotKey)] || {};
  }

  function editableSlots() {
    return (state.slots || []).filter((slot) => !!slot.editable);
  }

  function itemRegistered(item) {
    if (Number(item.is_present ?? 1) === 0) return true;
    const score = normalizeScore(item.score);
    return score !== "" && score !== null;
  }

  function studentAverage(student) {
    const values = [];
    (state.slots || []).forEach((slot) => {
      const item = slotItem(student.ID, slot.key);
      if (Number(item.is_present ?? 1) === 0) return;
      const score = normalizeScore(item.score);
      if (score !== "" && score !== null) values.push(Number(score));
    });
    if (!values.length) return null;
    return values.reduce((sum, value) => sum + value, 0) / values.length;
  }

  function familyName(student) {
    return String(student.last_name || "").trim() || String(student.display_name || "").trim();
  }

  function sortedStudents() {
    const students = [...(state.students || [])];
    const mode = $sort.val();

    if (mode === "score-desc" || mode === "score-asc") {
      return students.sort((a, b) => {
        const aValue = studentAverage(a);
        const bValue = studentAverage(b);
        if (aValue === null && bValue === null) return familyName(a).localeCompare(familyName(b), "fa");
        if (aValue === null) return 1;
        if (bValue === null) return -1;
        return mode === "score-desc" ? bValue - aValue : aValue - bValue;
      });
    }

    if (mode === "empty") {
      return students.sort((a, b) => {
        const aDone = editableSlots().every((slot) => itemRegistered(slotItem(a.ID, slot.key))) ? 1 : 0;
        const bDone = editableSlots().every((slot) => itemRegistered(slotItem(b.ID, slot.key))) ? 1 : 0;
        if (aDone !== bDone) return aDone - bDone;
        return familyName(a).localeCompare(familyName(b), "fa");
      });
    }

    return students.sort((a, b) => familyName(a).localeCompare(familyName(b), "fa"));
  }

  function updateStats() {
    const slots = editableSlots();
    const total = state.students.length * slots.length;
    let done = 0;
    let scoreSum = 0;
    let scored = 0;

    state.students.forEach((student) => {
      slots.forEach((slot) => {
        const item = slotItem(student.ID, slot.key);
        if (itemRegistered(item)) done++;
        if (Number(item.is_present ?? 1) === 0) return;
        const score = normalizeScore(item.score);
        if (score !== "" && score !== null) {
          scoreSum += Number(score);
          scored++;
        }
      });
    });

    const average = scored ? Math.round((scoreSum / scored) * 100) / 100 : null;
    $statbar.find('[data-stat="done"]').text(faNum(done));
    $statbar.find('[data-stat="total"]').text(faNum(total));
    $statbar.find('[data-stat="avg"]').text(average === null ? "—" : faNum(average));
    $("#hst-score-progress").css("width", (total ? Math.round((done / total) * 100) : 0) + "%");
    $statbar.find(".hst-scores-stat--success").attr("data-band", average === null ? "" : band(average));
    $statbar.prop("hidden", total === 0);
  }

  function switchMarkup(className, checked, disabled, label) {
    return `<label class="hst-switch" aria-label="${HST.escapeHtml(label)}">
      <input type="checkbox" class="${className}" ${checked ? "checked" : ""} ${disabled ? "disabled" : ""}>
      <span class="hst-switch__slider"></span>
    </label>`;
  }

  function readonlySlot(slot, item) {
    let value = "ثبت نشده";
    if (Number(item.is_present ?? 1) === 0) {
      value = Number(item.absence_excused ?? 0) === 1 ? "غایب موجه" : "غایب غیرموجه";
    } else {
      const score = normalizeScore(item.score);
      if (score !== "" && score !== null) value = `${faNum(score)} از ۲۰`;
    }

    return `<div class="hst-score-slot hst-score-slot--readonly" data-slot-key="${HST.escapeHtml(slot.key)}">
      <div class="hst-score-slot__head">
        <strong>${HST.escapeHtml(slot.label)}</strong>
        <span class="hst-status hst-status--muted">فقط مشاهده</span>
      </div>
      <div class="hst-score-slot__readonly">${value}</div>
      ${item.description ? `<small class="hst-muted">${HST.escapeHtml(item.description)}</small>` : ""}
    </div>`;
  }

  function editableSlot(student, slot, item, suggestion, suggestionAllowed) {
    const absent = Number(item.is_present ?? 1) === 0;
    const excused = Number(item.absence_excused ?? 0) === 1;
    const disabled = !state.canEdit;
    const suggestionHtml = suggestionAllowed && suggestion !== undefined && suggestion !== null && state.canEdit
      ? `<button type="button" class="hst-score-suggest" data-value="${HST.escapeHtml(suggestion)}" title="میانگین دفتر نمره">دفتر نمره: ${faNum(suggestion)}</button>`
      : "";

    return `<div class="hst-score-slot" data-slot-key="${HST.escapeHtml(slot.key)}" data-band="${band(item.score)}">
      <div class="hst-score-slot__head">
        <strong>${HST.escapeHtml(slot.label)}</strong>
        <span class="hst-score-slot__absence">
          ${switchMarkup("hst-score-absent", absent, disabled, "غیبت")}
          <span>غایب</span>
        </span>
      </div>
      <div class="hst-score-slot__controls">
        <div class="hst-score-slot__score" ${absent ? "hidden" : ""}>
          <div class="hst-score-input-wrap">
            <input type="number" inputmode="decimal" class="hst-score-input" min="0" max="20" step="0.25"
              value="${HST.escapeHtml(HST.formatScore(item.score))}" placeholder="نمره" ${disabled ? "disabled" : ""}>
            <span class="hst-score-input__max">از ۲۰</span>
          </div>
          ${suggestionHtml}
        </div>
        <div class="hst-score-slot__excuse" ${absent ? "" : "hidden"}>
          ${switchMarkup("hst-score-excused", excused, disabled, "نوع غیبت")}
          <span class="hst-score-excused-label">${excused ? "موجه" : "غیرموجه"}</span>
        </div>
      </div>
      <input type="text" class="hst-score-description" value="${HST.escapeHtml(item.description ?? "")}"
        placeholder="توضیح کوتاه (اختیاری)" ${disabled ? "disabled" : ""}>
    </div>`;
  }

  function studentCard(student) {
    const suggestion = state.suggestions[String(student.ID)] ?? state.suggestions[student.ID];
    const initial = HST.initials(student.display_name || "", student.first_name || "", student.last_name || "");
    const avatar = student.avatar_url
      ? `<img class="hst-score-avatar" src="${HST.escapeHtml(student.avatar_url)}" alt="" loading="lazy">`
      : `<span class="hst-score-avatar hst-score-avatar--placeholder" aria-hidden="true">${HST.escapeHtml(initial)}</span>`;

    let suggestionUsed = false;
    const slotsHtml = (state.slots || []).map((slot) => {
      const item = slotItem(student.ID, slot.key);
      if (!slot.editable) return readonlySlot(slot, item);
      const html = editableSlot(student, slot, item, suggestion, !suggestionUsed);
      suggestionUsed = true;
      return html;
    }).join("");

    const average = studentAverage(student);
    return `<div class="hst-score-row" data-student-id="${HST.escapeHtml(student.ID)}" data-band="${average === null ? "" : band(average)}">
      <div class="hst-score-row__who">
        ${avatar}
        <span class="hst-score-row__name">${HST.escapeHtml(student.display_name)}</span>
      </div>
      <div class="hst-score-row__entry">${slotsHtml}</div>
    </div>`;
  }

  function renderStudents() {
    if (!state.students.length) {
      $result.prop("hidden", true);
      $empty.prop("hidden", false);
      $emptyText.text("دانش‌آموز مشترکی برای این کلاس و درس پیدا نشد.");
      $statbar.prop("hidden", true);
      return;
    }

    $empty.prop("hidden", true);
    $list.html(sortedStudents().map(studentCard).join(""));
    $result.prop("hidden", false).toggleClass("is-readonly", !state.canEdit);
    $save.toggle(state.canEdit && editableSlots().length > 0);
    $formNote.text(state.canEdit
      ? "هر نمره و وضعیت غیبت به‌صورت مستقل ذخیره می‌شود. برای حذف یک نمره، فیلد آن را خالی بگذارید و ذخیره کنید."
      : "دسترسی ثبت نمره غیرفعال است؛ نمرات فقط قابل مشاهده هستند.");
    $("#hst-score-periodstate")
      .text(state.canEdit ? "دسترسی ثبت نمره فعال — قابل ویرایش" : "دسترسی ثبت نمره غیرفعال — فقط مشاهده")
      .attr("data-active", state.canEdit ? "1" : "0");
    updateStats();
  }

  async function loadContext(extra = {}) {
    const response = await HST.request({ action: "hst_get_teacher_score_context", data: extra, showLoader: false });
    return response?.success ? response.data : null;
  }

  function reset() {
    $result.prop("hidden", true);
    $statbar.prop("hidden", true);
    $empty.prop("hidden", false).removeAttr("aria-busy");
    $emptyText.text("برای نمایش فهرست، کلاس و درس را انتخاب کنید.");
  }

  function showLoadingState() {
    $result.prop("hidden", true);
    $statbar.prop("hidden", true);
    $empty.prop("hidden", false).attr("aria-busy", "true");
    $emptyText.html(HST.loadingMarkup());
  }

  function showLoadError() {
    $empty.prop("hidden", false).removeAttr("aria-busy");
    $emptyText.text("دریافت اطلاعات انجام نشد.");
  }

  async function maybeAutoLoad() {
    const classId = $class.val();
    const lessonId = $lesson.val();
    const periodKey = $period.val();
    if (!classId || !lessonId || !periodKey) {
      reset();
      return;
    }

    showLoadingState();
    const context = await loadContext({ class_id: classId, lesson_id: lessonId });
    if (!context) {
      showLoadError();
      return;
    }

    const response = await HST.request({
      action: "hst_get_monthly_scores",
      data: { class_id: classId, lesson_id: lessonId, period_key: periodKey },
      showLoader: false,
    });
    if (!response?.success) {
      showLoadError();
      return;
    }

    $empty.removeAttr("aria-busy");
    state.students = response.data.students || context.students || [];
    state.scores = response.data.scores || {};
    state.slots = response.data.slots || [];
    state.suggestions = response.data.suggestions || {};
    state.canEdit = !!(response.data.access_enabled ?? response.data.period_is_active ?? response.data.month_is_active);
    renderStudents();
  }

  $class.on("change", async function () {
    const classId = $(this).val();
    reset();
    $lesson.prop("disabled", true).html(opt("", classId ? "در حال بارگذاری..." : "ابتدا کلاس را انتخاب کنید"));
    if (!classId) return;

    const data = await loadContext({ class_id: classId });
    if (!data) return;
    const lessons = data.lessons || [];
    if (!lessons.length) {
      $lesson.prop("disabled", true).html(opt("", "درسی یافت نشد"));
      return;
    }
    $lesson.prop("disabled", false).html(opt("", "انتخاب درس") + lessons.map((lesson) => opt(lesson.id, lesson.lesson_name)).join(""));
  });

  function selectActivePeriod() {
    const $active = $period.find('option[data-active="1"]').first();
    if ($active.length) $period.val($active.val());
  }

  selectActivePeriod();
  $lesson.on("change", function () {
    if ($(this).val()) {
      selectActivePeriod();
      maybeAutoLoad();
    } else {
      reset();
    }
  });
  $period.on("change", maybeAutoLoad);
  $sort.on("change", function () {
    if (state.students.length) renderStudents();
  });

  $list.on("input", ".hst-score-input", function () {
    const $slot = $(this).closest(".hst-score-slot");
    const $row = $(this).closest(".hst-score-row");
    const studentId = String($row.data("student-id"));
    const slotKey = String($slot.data("slot-key"));
    const score = normalizeScore($(this).val());
    state.scores[studentId] = state.scores[studentId] || {};
    state.scores[studentId][slotKey] = state.scores[studentId][slotKey] || {};
    state.scores[studentId][slotKey].score = score === null ? $(this).val() : score;
    $slot.attr("data-band", score === null || score === "" ? "" : band(score));
    const student = state.students.find((item) => String(item.ID) === studentId);
    const average = student ? studentAverage(student) : null;
    $row.attr("data-band", average === null ? "" : band(average));
    updateStats();
  });

  $list.on("input", ".hst-score-description", function () {
    const $slot = $(this).closest(".hst-score-slot");
    const studentId = String($(this).closest(".hst-score-row").data("student-id"));
    const slotKey = String($slot.data("slot-key"));
    state.scores[studentId] = state.scores[studentId] || {};
    state.scores[studentId][slotKey] = state.scores[studentId][slotKey] || {};
    state.scores[studentId][slotKey].description = $(this).val();
  });

  $list.on("change", ".hst-score-absent", function () {
    const $slot = $(this).closest(".hst-score-slot");
    const $row = $(this).closest(".hst-score-row");
    const studentId = String($row.data("student-id"));
    const slotKey = String($slot.data("slot-key"));
    const absent = $(this).is(":checked");

    state.scores[studentId] = state.scores[studentId] || {};
    state.scores[studentId][slotKey] = state.scores[studentId][slotKey] || {};
    state.scores[studentId][slotKey].is_present = absent ? 0 : 1;
    if (absent) state.scores[studentId][slotKey].score = "";

    $slot.find(".hst-score-slot__score").prop("hidden", absent);
    $slot.find(".hst-score-slot__excuse").prop("hidden", !absent);
    if (absent) $slot.find(".hst-score-input").val("");
    $slot.attr("data-band", "");
    const student = state.students.find((item) => String(item.ID) === studentId);
    const average = student ? studentAverage(student) : null;
    $row.attr("data-band", average === null ? "" : band(average));
    updateStats();
  });

  $list.on("change", ".hst-score-excused", function () {
    const $slot = $(this).closest(".hst-score-slot");
    const studentId = String($(this).closest(".hst-score-row").data("student-id"));
    const slotKey = String($slot.data("slot-key"));
    const excused = $(this).is(":checked");
    state.scores[studentId] = state.scores[studentId] || {};
    state.scores[studentId][slotKey] = state.scores[studentId][slotKey] || {};
    state.scores[studentId][slotKey].absence_excused = excused ? 1 : 0;
    $slot.find(".hst-score-excused-label").text(excused ? "موجه" : "غیرموجه");
  });

  $list.on("keydown", ".hst-score-input", function (event) {
    if (!["Enter", "ArrowDown", "ArrowUp"].includes(event.key)) return;
    event.preventDefault();
    const inputs = $list.find(".hst-score-input:visible:not([disabled])").toArray();
    const index = inputs.indexOf(this);
    const nextIndex = event.key === "ArrowUp" ? index - 1 : index + 1;
    if (nextIndex < 0 || nextIndex >= inputs.length) return;
    inputs[nextIndex].focus();
    try { inputs[nextIndex].select(); } catch (error) {}
  });

  $list.on("click", ".hst-score-suggest", function () {
    const $slot = $(this).closest(".hst-score-slot");
    $slot.find(".hst-score-input").val($(this).data("value")).trigger("input").trigger("focus");
  });

  $form.on("submit", async function (event) {
    event.preventDefault();
    if (!state.canEdit) {
      HST.toast("دسترسی ثبت یا ویرایش نمره برای شما فعال نیست", "error");
      return;
    }

    const scores = {};
    let invalid = false;
    $list.find(".hst-score-row").each(function () {
      if (invalid) return;
      const $row = $(this);
      const studentId = String($row.data("student-id"));
      scores[studentId] = {};

      $row.find(".hst-score-slot").each(function () {
        if (invalid) return;
        const $slot = $(this);
        const slotKey = String($slot.data("slot-key"));
        const slot = (state.slots || []).find((candidate) => String(candidate.key) === slotKey);
        if (!slot || !slot.editable) return;

        const absent = $slot.find(".hst-score-absent").is(":checked");
        const score = absent ? "" : normalizeScore($slot.find(".hst-score-input").val());
        if (!absent && score === null) {
          invalid = true;
          HST.toast(`${slot.label}: نمره باید عددی بین ۰ تا ۲۰ باشد.`, "error");
          $slot.find(".hst-score-input").trigger("focus");
          return;
        }

        scores[studentId][slotKey] = {
          present: absent ? 0 : 1,
          absence_excused: absent && $slot.find(".hst-score-excused").is(":checked") ? 1 : 0,
          score: absent ? "" : score,
          description: String($slot.find(".hst-score-description").val() || "").trim().slice(0, 500),
        };
      });
    });

    if (invalid) return;
    const response = await HST.request({
      action: "hst_save_monthly_scores",
      data: { class_id: $class.val(), lesson_id: $lesson.val(), period_key: $period.val(), scores: JSON.stringify(scores) },
      successMessage: true,
    });
    if (response?.success) maybeAutoLoad();
  });

  (async function boot() {
    const data = await loadContext();
    if (!data) return;
    state.classes = HST.sortClassItems(data.classes || [], "class_name");
    $class.html(opt("", "انتخاب کلاس") + state.classes.map((item) => opt(item.id, item.class_name)).join(""));
  })();
});
