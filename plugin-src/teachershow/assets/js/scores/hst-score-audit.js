jQuery(function ($) {
  "use strict";

  const $modal = $("#hst-score-audit-scores-modal");
  const $modalTitle = $modal.find("[data-hst-score-audit-modal-title]");
  const $modalSubtitle = $modal.find("[data-hst-score-audit-modal-subtitle]");
  const $modalContent = $modal.find(".hst-modal__body");
  const $modalHead = $modal.find("[data-hst-score-audit-modal-head]");
  const $modalBody = $modal.find("[data-hst-score-audit-modal-body]");
  const $modalEmpty = $modal.find("[data-hst-score-audit-modal-empty]");
  const $modalSave = $modal.find("[data-hst-score-audit-modal-save]");
  const $modalEdit = $modal.find("[data-hst-score-audit-modal-edit]");
  const $bulkEntry = $modal.find("[data-hst-score-bulk-entry]");
  const $bulkSlot = $bulkEntry.find("[data-hst-score-bulk-slot]");
  const $bulkValue = $bulkEntry.find("[data-hst-score-bulk-value]");
  const $bulkApply = $bulkEntry.find("[data-hst-score-bulk-apply]");
  let activeMode = "view";
  let viewEditEnabled = false;
  let activeRow = null;
  let activePayload = null;
  let lastScoreData = null;

  const $securityModal = $("#hst-score-audit-security-modal");
  const $securityBody = $securityModal.find("[data-hst-score-security-body]");

  function faNum(value) {
    return HST.escapeHtml(String(value ?? "")).replace(/\d/g, (d) => "۰۱۲۳۴۵۶۷۸۹"[d]);
  }

  function normalizeScore(value) {
    const normalized = String(value ?? "").trim().replace("،", ".").replace(",", ".");
    if (!normalized) return "";
    if (!/^\d{1,2}(?:\.\d{1,2})?$/.test(normalized)) return null;
    const n = Number(normalized);
    if (Number.isNaN(n) || n < 0 || n > 20) return null;
    return String(Math.round(n * 100) / 100);
  }

  function scoreForInput(value) {
    const normalized = normalizeScore(value);
    return normalized === null ? "" : normalized;
  }

  function rowPayload($row) {
    return {
      teacher_id: String($row.attr("data-teacher-id") ?? ""),
      class_id: String($row.attr("data-class-id") ?? ""),
      lesson_id: String($row.attr("data-lesson-id") ?? ""),
      period_key: String($row.attr("data-period-key") ?? ""),
      teacher_name: String($row.attr("data-teacher-name") ?? ""),
      class_name: String($row.attr("data-class-name") ?? ""),
      lesson_name: String($row.attr("data-lesson-name") ?? ""),
      period_label: String($row.attr("data-period-label") ?? ""),
      manager_only: String($row.attr("data-manager-only") ?? "0") === "1",
    };
  }

  function openModal(mode, $row) {
    activeMode = mode;
    viewEditEnabled = mode === "edit";
    activeRow = $row;
    activePayload = rowPayload($row);

    $modal.addClass("is-active").attr("aria-hidden", "false");
    if (mode === "view") {
      $modalEdit.prop("hidden", false).show();
    } else {
      $modalEdit.prop("hidden", true).hide();
    }
    $modalSave.prop("hidden", mode !== "edit");
    if (activePayload.manager_only) {
      $modalTitle.text("ثبت نمره انضباط");
      $modalSubtitle.text("کلاس: " + activePayload.class_name + " | دوره: " + activePayload.period_label + " | ثبت‌کننده: مدیر مدرسه");
    } else {
      $modalTitle.text(mode === "edit" ? "ثبت نمره توسط مدیر" : "نمرات ثبت شده توسط معلم");
      $modalSubtitle.text("دبیر: " + activePayload.teacher_name + " | کلاس: " + activePayload.class_name + " | درس: " + activePayload.lesson_name + " | دوره: " + activePayload.period_label);
    }
    $modalEmpty.prop("hidden", true);
    $bulkEntry.prop("hidden", true).hide();
    $bulkSlot.empty().prop("hidden", true).hide();
    $bulkValue.val("");
    renderHeader([]);
    $modalBody.empty();
    HST.modalLoading.show($modalContent);

    loadScores();
  }

  function closeModal() {
    $modal.removeClass("is-active").attr("aria-hidden", "true");
    activeMode = "view";
    viewEditEnabled = false;
    activeRow = null;
    activePayload = null;
    lastScoreData = null;
    HST.modalLoading.hide($modalContent);
    $modalEdit.prop("hidden", true).hide();
    $bulkEntry.prop("hidden", true).hide();
    $bulkSlot.empty().prop("hidden", true).hide();
    $bulkValue.val("");
    $modalBody.empty();
  }

  function studentCell(student) {
    const name = String(student.display_name || "");
    const national = String(student.national_code || "");
    const initial = HST.initials(name);
    const avatar = student.avatar_url
      ? '<span class="hst-user-avatar"><img src="' + HST.escapeHtml(student.avatar_url) + '" alt="' + HST.escapeHtml(name) + '" loading="lazy"></span>'
      : '<span class="hst-user-avatar hst-user-avatar--placeholder" aria-hidden="true">' + HST.escapeHtml(initial) + '</span>';

    return '<span class="hst-user-id">' +
      avatar +
      '<span class="hst-user-id__meta">' +
      '<span class="hst-user-id__name">' + HST.escapeHtml(name) + '</span>' +
      '<small>' + HST.escapeHtml(national || "بدون کد ملی") + '</small>' +
      '</span>' +
      '</span>';
  }

  function modalCanEdit() {
    return activeMode === "edit" || viewEditEnabled;
  }

  function editableSlots() {
    const slots = (lastScoreData && Array.isArray(lastScoreData.slots)) ? lastScoreData.slots : [];
    return slots.filter((slot) => !!slot && slot.editable !== false);
  }

  function syncBulkEntry() {
    const slots = editableSlots();
    const enabled = modalCanEdit() && slots.length > 0;

    if (!enabled) {
      $bulkEntry.prop("hidden", true).hide();
      $bulkSlot.empty().prop("hidden", true).hide();
      $bulkValue.val("");
      return;
    }

    const previous = String($bulkSlot.val() || "");
    $bulkSlot.html(slots.map((slot) => {
      const key = String(slot.key || "");
      const label = String(slot.label || "نمره دوره");
      return '<option value="' + HST.escapeHtml(key) + '">' + HST.escapeHtml(label) + '</option>';
    }).join(""));

    if (previous && slots.some((slot) => String(slot.key || "") === previous)) {
      $bulkSlot.val(previous);
    } else {
      $bulkSlot.val(String(slots[0].key || ""));
    }

    $bulkSlot.prop("hidden", slots.length < 2).toggle(slots.length > 1);
    $bulkEntry.prop("hidden", false).css("display", "flex");
  }

  function switchMarkup(attributes, checked, disabled, label) {
    return '<label class="hst-switch" aria-label="' + HST.escapeHtml(label) + '">' +
      '<input type="checkbox" ' + attributes + ' ' + (checked ? 'checked ' : '') + (disabled ? 'disabled' : '') + '>' +
      '<span class="hst-switch__slider"></span>' +
      '</label>';
  }

  function renderHeader(slots) {
    const items = Array.isArray(slots) && slots.length ? slots : [{ key: '', label: 'نمره دوره', editable: true }];
    let html = '<tr><th>ردیف</th><th>نام دانش‌آموز</th><th>نام پدر</th>';

    items.forEach((slot) => {
      const scoreLabel = String(slot.label || 'نمره دوره');
      const attendanceLabel = items.length === 1 ? 'حضور و غیاب' : 'حضور ' + String(slot.label || 'نمره');
      html += '<th>' + HST.escapeHtml(scoreLabel) + '</th>';
      html += '<th>' + HST.escapeHtml(attendanceLabel) + '</th>';
    });

    $modalHead.html(html + '</tr>');
  }

  function scoreCell(slot, item) {
    const present = Number(item.is_present ?? 1) !== 0;
    const excused = Number(item.absence_excused ?? 0) === 1;
    const disabled = !modalCanEdit() || !slot.editable;
    const slotKey = HST.escapeHtml(String(slot.key || ''));

    return '<td data-hst-score-cell data-slot-key="' + slotKey + '">' +
      '<input type="number" inputmode="decimal" class="hst-input hst-score-audit-score-input" data-hst-score-value min="0" max="20" step="0.25" value="' + HST.escapeHtml(scoreForInput(item.score)) + '" placeholder="نمره" ' + (present ? '' : 'hidden ') + (disabled ? 'disabled' : '') + '>' +
      '<span class="hst-score-audit-attendance" data-hst-score-excuse ' + (present ? 'hidden' : '') + '>' +
        switchMarkup('data-hst-score-excused', excused, disabled, 'نوع غیبت') +
        '<span data-hst-score-excused-label>' + (excused ? 'موجه' : 'غیرموجه') + '</span>' +
      '</span>' +
      '<input type="hidden" data-hst-score-description value="' + HST.escapeHtml(item.description || '') + '">' +
      '</td>';
  }

  function attendanceCell(slot, item) {
    const present = Number(item.is_present ?? 1) !== 0;
    const disabled = !modalCanEdit() || !slot.editable;

    return '<td data-hst-attendance-cell data-slot-key="' + HST.escapeHtml(String(slot.key || '')) + '">' +
      '<span class="hst-score-audit-attendance">' +
        switchMarkup('data-hst-score-present', present, disabled, 'حضور و غیاب') +
        '<span data-hst-score-present-label>' + (present ? 'حاضر' : 'غایب') + '</span>' +
      '</span>' +
      '</td>';
  }

  function renderRows(data) {
    lastScoreData = data || {};
    const students = lastScoreData.students || [];
    const scores = lastScoreData.scores || {};
    const slots = lastScoreData.slots || [];
    renderHeader(slots);

    if (!students.length) {
      $modalBody.empty();
      $modalEmpty.prop("hidden", false);
      syncBulkEntry();
      return;
    }

    if (!slots.length) {
      $modalEmpty.prop("hidden", true);
      $modalBody.html('<tr data-hst-no-pagination><td colspan="5">برای این دوره ساختار نمره‌ای تعریف نشده است.</td></tr>');
      syncBulkEntry();
      return;
    }

    $modalEmpty.prop("hidden", true);
    $modalBody.html(students.map((student, index) => {
      const sid = String(student.ID || "");
      const studentScores = scores[sid] || scores[student.ID] || {};
      const cells = slots.map((slot) => {
        const item = studentScores[String(slot.key)] || {};
        return scoreCell(slot, item) + attendanceCell(slot, item);
      }).join("");

      return '<tr data-student-id="' + HST.escapeHtml(sid) + '">' +
        '<td class="hst-row-num">' + faNum(index + 1) + '</td>' +
        '<td>' + studentCell(student) + '</td>' +
        '<td>' + HST.escapeHtml(student.father_name || "—") + '</td>' +
        cells +
      '</tr>';
    }).join(""));
    syncBulkEntry();
  }

  async function loadScores() {
    if (!activePayload) return;
    const res = await HST.request({
      action: "hst_score_audit_get_scores",
      data: activePayload,
      showLoader: false,
    });

    HST.modalLoading.hide($modalContent);

    if (res && res.success) {
      renderRows(res.data || {});
      return;
    }

    $modalEmpty.prop("hidden", true);
    $modalBody.html('<tr data-hst-no-pagination><td colspan="5"><p class="hst-alert hst-alert--error">اطلاعات نمرات دریافت نشد.</p></td></tr>');
  }

  function statusLabel(status) {
    if (status === "registered") return "ثبت شده";
    if (status === "remaining") return "مانده";
    if (status === "no_students") return "بدون دانش‌آموز";
    return "نامشخص";
  }

  function statusClass(status) {
    if (status === "registered") return "success";
    if (status === "remaining") return "warning";
    if (status === "no_students") return "muted";
    return "muted";
  }

  function updateReminderButtonState($row) {
    if (String($row.attr("data-manager-only") || "0") === "1") return;
    const status = String($row.attr("data-status") || $row.data("status") || "");
    const hasAccess = $row.find(".hst-score-entry-access-toggle").is(":checked");
    const $button = $row.find("[data-hst-score-reminder]");
    let label = "ارسال یادآوری ثبت نمره به دبیر";
    let enabled = status === "remaining" && hasAccess;

    if (status === "registered") {
      label = "تمام نمرات این مورد ثبت شده است";
    } else if (status === "no_students") {
      label = "برای این کلاس و درس دانش‌آموزی وجود ندارد";
    } else if (!hasAccess) {
      label = "ابتدا دسترسی ثبت نمره دبیر را فعال کنید";
    }

    $button.prop("disabled", !enabled).attr({ title: label, "aria-label": label });
  }

  function updateAuditRow(summary) {
    if (!activeRow || !summary) return;
    const status = String(summary.status || "");
    const pct = Number(summary.percent || 0);

    activeRow.attr("data-status", status).attr("data-hst-status", status);
    activeRow.find(".hst-score-audit-expected").text(faNum(summary.expected || 0));
    activeRow.find(".hst-score-audit-registered").text(faNum(summary.registered || 0));
    activeRow.find(".hst-score-audit-missing").text(faNum(summary.missing || 0));
    activeRow.find(".hst-progress").attr("data-status", status);
    activeRow.find(".hst-progress__bar").css("width", pct + "%");
    activeRow.find(".hst-score-audit-progress-cell small").text(faNum(pct) + "٪");
    activeRow.find(".hst-score-audit-status")
      .removeClass("hst-status--success hst-status--warning hst-status--danger hst-status--muted")
      .addClass("hst-status--" + statusClass(status))
      .text(statusLabel(status));

    updateReminderButtonState(activeRow);

    if (window.HST && typeof window.HST.refreshTables === "function") {
      window.HST.refreshTables();
    }
  }



  function securityValue(label, value, extraClass) {
    return '<div class="hst-score-security-row">' +
      '<span>' + HST.escapeHtml(label) + '</span>' +
      '<b class="' + (extraClass || '') + '">' + HST.escapeHtml(value || 'ثبت نشده') + '</b>' +
      '</div>';
  }

  function securityCard(icon, title, html) {
    return '<div class="hst-score-security-card">' +
      '<h4>' + icon + ' ' + HST.escapeHtml(title) + '</h4>' +
      '<div class="hst-score-security-list">' + html + '</div>' +
      '</div>';
  }

  function renderSecurityReport(data) {
    const meta = data.meta || {};
    const summary = data.summary || {};
    const teacher = data.teacher || {};
    const admin = data.admin || {};
    const lock = data.lock || {};
    const total = String(summary.expected ?? '0');
    const registered = String(summary.registered ?? '0');

    const managerOnly = Number(meta.manager_only || 0) === 1;
    const overview = '<div class="hst-score-security-overview">' +
      securityValue(managerOnly ? 'مسئول ثبت' : 'دبیر', managerOnly ? 'مدیر مدرسه' : (meta.teacher_name || '—')) +
      securityValue('کلاس و درس', (meta.class_name || '—') + ' - ' + (meta.lesson_name || '—')) +
      securityValue('تعداد نمره‌های مورد انتظار', total + ' مورد') +
      securityValue('نمرات ثبت‌شده', registered + ' نمره', 'hst-score-security-success') +
      '</div>';

    const teacherLog = managerOnly ? '' : securityCard('', 'گزارش ثبت و ویرایش دبیر',
      securityValue('زمان اولین ثبت نمره توسط دبیر', teacher.first_registered_at || 'ثبت نشده') +
      securityValue('زمان آخرین ویرایش نمره توسط دبیر', teacher.last_updated_at || 'بدون ویرایش')
    );

    const adminLog = securityCard('', managerOnly ? 'گزارش ثبت و ویرایش نمره انضباط' : 'گزارش ثبت و ویرایش مدیر',
      securityValue(managerOnly ? 'زمان اولین ثبت نمره توسط مدیر' : 'زمان ثبت نمره توسط مدیر (پشتیبان)', admin.first_registered_at || 'ثبت نشده') +
      securityValue('زمان آخرین ویرایش نمره توسط مدیر', admin.last_updated_at || 'بدون ویرایش')
    );

    const lockLog = managerOnly ? '' : securityCard('', 'گزارش وضعیت قفل نمرات',
      securityValue('زمان قفل شدن ثبت نمره (اتوماتیک/مدیریتی)', lock.locked_at || 'ثبت نشده', 'hst-score-security-danger') +
      securityValue('زمان باز شدن مجدد قفل ثبت نمره', lock.unlocked_at || 'ثبت نشده', 'hst-score-security-success')
    );

    $securityBody.html(overview + teacherLog + adminLog + lockLog);
  }

  async function openSecurityModal($row) {
    const payload = rowPayload($row);
    $securityModal.addClass("is-active").attr("aria-hidden", "false");
    $securityBody.empty();
    HST.modalLoading.show($securityBody);

    const res = await HST.request({
      action: "hst_score_audit_security_logs",
      data: payload,
      showLoader: false,
    });

    HST.modalLoading.hide($securityBody);

    if (!res || !res.success) {
      $securityBody.html('<p class="hst-alert hst-alert--error">گزارش امنیتی دریافت نشد.</p>');
      return;
    }

    renderSecurityReport(res.data || {});
  }

  $(document).on("change", ".hst-score-entry-access-toggle", async function () {
    const $toggle = $(this);
    const previousState = !$toggle.is(":checked");
    const enabled = $toggle.is(":checked") ? 1 : 0;

    const payload = {
      teacher_id: String($toggle.data("teacher-id") || ""),
      class_id: String($toggle.data("class-id") || ""),
      lesson_id: String($toggle.data("lesson-id") || ""),
      period_key: String($toggle.data("period-key") || ""),
      is_enabled: enabled,
      enabled: enabled,
    };

    if (!payload.teacher_id || !payload.class_id || !payload.lesson_id || !payload.period_key) {
      $toggle.prop("checked", previousState);
      HST.toast("اطلاعات دسترسی ثبت نمره ناقص است.", "error");
      return;
    }

    $toggle.prop("disabled", true);

    const res = await HST.request({
      action: "hst_toggle_score_entry_access",
      data: payload,
      showLoader: false,
    });

    $toggle.prop("disabled", false);

    if (!res || !res.success) {
      $toggle.prop("checked", previousState);
      HST.toast("تغییر دسترسی ثبت نمره ذخیره نشد.", "error");
      return;
    }

    const access = (res.data && res.data.access) || {};
    const isEnabled = !!(res.data && (
      res.data.enabled === true ||
      res.data.enabled === 1 ||
      res.data.enabled === "1" ||
      access.checked === true ||
      access.checked === 1 ||
      access.checked === "1"
    ));
    $toggle.prop("checked", isEnabled);
    updateReminderButtonState($toggle.closest("tr"));

    HST.toast(isEnabled ? "دسترسی ثبت نمره دبیر فعال شد." : "دسترسی ثبت نمره دبیر غیرفعال شد.", "success");
  });

  $(document).on("click", "[data-hst-score-reminder]", async function () {
    const $button = $(this);
    if ($button.prop("disabled")) return;

    const $row = $button.closest("tr");
    const payload = rowPayload($row);
    const dedupeKey = [
      "hst_score_audit_send_reminder",
      payload.teacher_id,
      payload.class_id,
      payload.lesson_id,
      payload.period_key,
    ].join(":");

    await HST.request({
      action: "hst_score_audit_send_reminder",
      data: payload,
      trigger: this,
      showLoader: false,
      successMessage: true,
      dedupe: dedupeKey,
    });
  });

  $(document).on("click", ".hst-score-audit-security", function () {
    openSecurityModal($(this).closest("tr"));
  });

  $(document).on("click", "[data-hst-score-security-close]", function () {
    HST.modalLoading.hide($securityBody);
    $securityModal.removeClass("is-active").attr("aria-hidden", "true");
  });

  $securityModal.on("click", function (e) {
    if (e.target === this) {
      HST.modalLoading.hide($securityBody);
      $securityModal.removeClass("is-active").attr("aria-hidden", "true");
    }
  });

  $(document).on("click", ".hst-score-audit-view-scores", function () {
    openModal("view", $(this).closest("tr"));
  });

  $(document).on("click", ".hst-score-audit-admin-scores", function () {
    openModal("edit", $(this).closest("tr"));
  });

  $modalEdit.on("click", function () {
    viewEditEnabled = true;
    activeMode = "edit";
    $modalEdit.prop("hidden", true).hide();
    $modalSave.prop("hidden", false);
    $modalTitle.text(activePayload && activePayload.manager_only ? "ویرایش نمره انضباط" : "ویرایش نمرات ثبت‌شده");
    if (lastScoreData) renderRows(lastScoreData);
    syncBulkEntry();
  });

  $(document).on("click", "[data-hst-score-audit-modal-close]", closeModal);
  $modal.on("click", function (e) {
    if (e.target === this) closeModal();
  });

  $modalBody.on("change", "[data-hst-score-present]", function () {
    if (!modalCanEdit()) return;

    const $attendanceCell = $(this).closest("[data-hst-attendance-cell]");
    const $row = $(this).closest("tr[data-student-id]");
    const slotKey = String($attendanceCell.data("slot-key") || "");
    const present = $(this).is(":checked");
    const $scoreCell = $row.find("[data-hst-score-cell]").filter(function () {
      return String($(this).data("slot-key") || "") === slotKey;
    }).first();

    $attendanceCell.find("[data-hst-score-present-label]").text(present ? "حاضر" : "غایب");
    $scoreCell.find("[data-hst-score-value]")
      .prop("hidden", !present)
      .toggle(present);
    $scoreCell.find("[data-hst-score-excuse]")
      .prop("hidden", present)
      .toggle(!present);
  });

  $modalBody.on("change", "[data-hst-score-excused]", function () {
    $(this).closest("[data-hst-score-excuse]").find("[data-hst-score-excused-label]").text($(this).is(":checked") ? "موجه" : "غیرموجه");
  });

  $modalBody.on("keydown", ".hst-score-audit-score-input", function (e) {
    if (!["Enter", "ArrowDown", "ArrowUp"].includes(e.key)) return;

    e.preventDefault();
    const inputs = $modalBody.find(".hst-score-audit-score-input:visible:not([disabled])").toArray();
    const currentIndex = inputs.indexOf(this);
    if (currentIndex === -1) return;

    const nextIndex = e.key === "ArrowUp" ? currentIndex - 1 : currentIndex + 1;
    if (nextIndex < 0 || nextIndex >= inputs.length) return;

    const nextInput = inputs[nextIndex];
    nextInput.focus();
    try { nextInput.select(); } catch (err) {}
  });

  $modalBody.on("blur", ".hst-score-audit-score-input", function () {
    const cleaned = scoreForInput($(this).val());
    if (cleaned !== "") {
      $(this).val(cleaned);
    }
  });

  function applyBulkScore() {
    if (!modalCanEdit()) return;

    const normalized = normalizeScore($bulkValue.val());
    if (normalized === null || normalized === "") {
      HST.toast("نمره مشترک باید عددی بین ۰ تا ۲۰ باشد.", "error");
      $bulkValue.trigger("focus");
      return;
    }

    const slots = editableSlots();
    const selectedKey = String($bulkSlot.val() || (slots[0] && slots[0].key) || "");
    const selectedSlot = slots.find((slot) => String(slot.key || "") === selectedKey);
    if (!selectedSlot) {
      HST.toast("ستون نمره برای اعمال گروهی مشخص نیست.", "error");
      return;
    }

    let applied = 0;
    $modalBody.find("tr[data-student-id]").each(function () {
      const $row = $(this);
      const $scoreCell = $row.find("[data-hst-score-cell]").filter(function () {
        return String($(this).data("slot-key") || "") === selectedKey;
      }).first();
      const $scoreInput = $scoreCell.find("[data-hst-score-value]");
      if (!$scoreInput.length || $scoreInput.prop("disabled")) return;

      const $attendanceCell = $row.find("[data-hst-attendance-cell]").filter(function () {
        return String($(this).data("slot-key") || "") === selectedKey;
      }).first();
      const $present = $attendanceCell.find("[data-hst-score-present]");

      $present.prop("checked", true);
      $attendanceCell.find("[data-hst-score-present-label]").text("حاضر");
      $scoreInput.prop("hidden", false).show().val(normalized).trigger("input");
      $scoreCell.find("[data-hst-score-excuse]").prop("hidden", true).hide();
      $scoreCell.find("[data-hst-score-excused]").prop("checked", false);
      $scoreCell.find("[data-hst-score-excused-label]").text("غیرموجه");
      applied += 1;
    });

    if (!applied) {
      HST.toast("دانش‌آموز قابل ویرایشی برای اعمال نمره پیدا نشد.", "error");
      return;
    }

    const label = String(selectedSlot.label || "نمره دوره");
    HST.toast(
      "نمره " + faNum(normalized) + " در «" + label + "» برای " + faNum(applied) + " دانش‌آموز قرار گرفت؛ برای ثبت نهایی دکمه ذخیره تغییرات را بزنید.",
      "success"
    );
  }

  $bulkApply.on("click", applyBulkScore);
  $bulkValue.on("keydown", function (event) {
    if (event.key !== "Enter") return;
    event.preventDefault();
    applyBulkScore();
  });

  $modalSave.on("click", async function () {
    if (!activePayload || !modalCanEdit()) return;
    const scores = {};
    const slots = (lastScoreData && lastScoreData.slots) || [];
    let invalid = false;

    $modalBody.find("tr[data-student-id]").each(function () {
      if (invalid) return;
      const $row = $(this);
      const studentId = String($row.data("student-id") || "");
      scores[studentId] = {};

      $row.find("[data-hst-score-cell]").each(function () {
        if (invalid) return;
        const $scoreCell = $(this);
        const slotKey = String($scoreCell.data("slot-key") || "");
        const slot = slots.find((item) => String(item.key) === slotKey);
        if (!slot || !slot.editable) return;

        const $attendanceCell = $row.find("[data-hst-attendance-cell]").filter(function () {
          return String($(this).data("slot-key") || "") === slotKey;
        }).first();
        const present = $attendanceCell.find("[data-hst-score-present]").is(":checked");
        const score = present ? normalizeScore($scoreCell.find("[data-hst-score-value]").val()) : "";

        if (present && score === null) {
          invalid = true;
          HST.toast(slot.label + ": نمره باید عددی بین ۰ تا ۲۰ باشد.", "error");
          $scoreCell.find("[data-hst-score-value]").focus();
          return;
        }

        scores[studentId][slotKey] = {
          present: present ? 1 : 0,
          absence_excused: !present && $scoreCell.find("[data-hst-score-excused]").is(":checked") ? 1 : 0,
          score: present ? score : "",
          description: String($scoreCell.find("[data-hst-score-description]").val() || "").slice(0, 500),
        };
      });
    });

    if (invalid) return;

    const res = await HST.request({
      action: "hst_score_audit_save_scores",
      data: Object.assign({}, activePayload, { scores: JSON.stringify(scores) }),
      trigger: this,
      successMessage: true,
    });

    if (res && res.success) {
      updateAuditRow(res.data && res.data.summary);
      await loadScores();
    }
  });


  let scoreAuditXlsxPromise = null;
  let scoreAuditExportBusy = false;

  function loadScoreAuditXlsx() {
    if (window.XLSX) return Promise.resolve(window.XLSX);
    if (scoreAuditXlsxPromise) return scoreAuditXlsxPromise;

    const sources = [
      "https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js",
      "https://unpkg.com/xlsx@0.18.5/dist/xlsx.full.min.js",
    ];

    scoreAuditXlsxPromise = new Promise((resolve, reject) => {
      let index = 0;

      const tryNext = function () {
        if (window.XLSX) {
          resolve(window.XLSX);
          return;
        }
        if (index >= sources.length) {
          reject(new Error("کتابخانه ساخت فایل Excel بارگذاری نشد."));
          return;
        }

        const script = document.createElement("script");
        script.src = sources[index++];
        script.async = true;
        script.crossOrigin = "anonymous";
        script.onload = function () {
          if (window.XLSX) resolve(window.XLSX);
          else tryNext();
        };
        script.onerror = function () {
          script.remove();
          tryNext();
        };
        document.head.appendChild(script);
      };

      tryNext();
    }).catch((error) => {
      scoreAuditXlsxPromise = null;
      throw error;
    });

    return scoreAuditXlsxPromise;
  }

  function scoreAuditSheetWidths(headers, rows) {
    return headers.map((header, columnIndex) => {
      let maxLength = String(header || "").length;
      rows.slice(0, 500).forEach((row) => {
        const value = row[columnIndex];
        const length = String(value === null || value === undefined ? "" : value).length;
        if (length > maxLength) maxLength = length;
      });
      return { wch: Math.max(10, Math.min(34, maxLength + 3)) };
    });
  }

  function buildScoreAuditSheet(XLSX, headers, rows) {
    const safeHeaders = Array.isArray(headers) ? headers : [];
    const safeRows = Array.isArray(rows) ? rows : [];
    const worksheet = XLSX.utils.aoa_to_sheet([safeHeaders].concat(safeRows));
    const lastColumn = Math.max(0, safeHeaders.length - 1);
    const lastRow = Math.max(0, safeRows.length);

    worksheet["!autofilter"] = {
      ref: XLSX.utils.encode_range({
        s: { r: 0, c: 0 },
        e: { r: lastRow, c: lastColumn },
      }),
    };
    worksheet["!cols"] = scoreAuditSheetWidths(safeHeaders, safeRows);
    worksheet["!views"] = [{ RTL: true }];
    worksheet["!freeze"] = { xSplit: 0, ySplit: 1, topLeftCell: "A2", activePane: "bottomLeft", state: "frozen" };

    return worksheet;
  }

  async function exportScoreAuditExcel(trigger) {
    if (scoreAuditExportBusy) return;

    const $button = $(trigger);
    const periodKey = String($button.data("period-key") || "");
    if (!periodKey) {
      HST.toast("دوره ثبت نمره مشخص نیست.", "error");
      return;
    }

    scoreAuditExportBusy = true;
    const restoreTrigger = HST.setBusy(trigger);
    const progress = HST.operationProgress
      ? HST.operationProgress.open({
          title: "در حال ساخت گزارش Excel ثبت نمره",
          subtitle: "دریافت داده‌ها و ساخت فایل ممکن است کمی زمان ببرد.",
          percent: 3,
          text: "در حال دریافت اطلاعات معلمان و دانش‌آموزان...",
          lockMessage: "ساخت گزارش Excel هنوز کامل نشده است؛ لطفاً صبر کنید.",
        })
      : null;
    if (progress) progress.startAuto({ ceiling: 38, interval: 700, text: "در حال دریافت و آماده‌سازی داده‌های گزارش..." });
    else HST.loader.show();

    try {
      const results = await Promise.all([
        HST.ajax({ action: "hst_score_audit_excel_report", period_key: periodKey }),
        loadScoreAuditXlsx(),
      ]);
      const response = results[0];
      const XLSX = results[1];

      if (!response || !response.success || !response.data) {
        throw new Error(HST.getMessage(response, "دریافت اطلاعات گزارش Excel ناموفق بود."));
      }

      if (progress) {
        progress.stopAuto();
        progress.update(52, "اطلاعات دریافت شد؛ در حال ساخت شیت معلمان...", "ساخت فایل Excel");
      }

      const payload = response.data;
      const workbook = XLSX.utils.book_new();
      workbook.Props = {
        Title: "گزارش ثبت نمره " + String(payload.period_label || ""),
        Subject: "گزارش معلمان و دانش‌آموزان",
        Author: "TeacherShow",
        Company: "TeacherShow",
      };

      const teachersSheet = buildScoreAuditSheet(
        XLSX,
        payload.teacher_headers || [],
        payload.teacher_rows || []
      );
      if (progress) progress.update(70, "شیت معلمان آماده شد؛ در حال ساخت شیت دانش‌آموزان...", "ساخت فایل Excel");
      const studentsSheet = buildScoreAuditSheet(
        XLSX,
        payload.student_headers || [],
        payload.student_rows || []
      );

      XLSX.utils.book_append_sheet(workbook, teachersSheet, "معلمان");
      XLSX.utils.book_append_sheet(workbook, studentsSheet, "دانش‌آموزان");

      let filename = String(payload.filename || "گزارش-ثبت-نمره.xlsx").trim();
      if (!/\.xlsx$/i.test(filename)) filename += ".xlsx";
      if (progress) progress.update(92, "در حال فشرده‌سازی و ذخیره فایل Excel...", "ذخیره فایل");
      XLSX.writeFile(workbook, filename, { compression: true });
      if (progress) progress.complete("فایل Excel گزارش ثبت نمره آماده شد و دانلود آغاز شد.");
      HST.toast("فایل Excel گزارش ثبت نمره آماده شد.", "success");
    } catch (error) {
      console.error("HST score audit Excel export failed:", error);
      if (progress) progress.fail("ساخت فایل Excel گزارش ثبت نمره ناموفق بود.");
      HST.toast(HST.getMessage(error, "ساخت فایل Excel ناموفق بود."), "error");
    } finally {
      if (!progress) HST.loader.hide();
      restoreTrigger();
      scoreAuditExportBusy = false;
    }
  }

  $(document).on("click", "[data-hst-score-audit-export-excel]", function () {
    exportScoreAuditExcel(this);
  });

  $(document).on("submit", ".hst-score-audit-page .hst-inline-filter", function (e) {
    e.preventDefault();
  });
});
