jQuery(function ($) {
  "use strict";

  const $page = $("[data-hst-discipline]");
  if (!$page.length) return;

  const esc = HST.escapeHtml;
  const faNum = (n) => String(n ?? "").replace(/\d/g, (d) => "۰۱۲۳۴۵۶۷۸۹"[d]);
  const canManageAvatars = $page.attr("data-hst-can-manage-avatars") === "1";
  const hasActiveStudents = $page.attr("data-hst-has-active-students") === "1";

  function studentAvatarHtml(student, editableListAvatar) {
    const name = String((student && student.name) || "");
    const initial = String((student && student.initial) || HST.initials(name) || "؟");
    const avatarUrl = String((student && student.avatar_url) || "");
    const userId = parseInt((student && (student.id || student.student_id)) || 0, 10) || 0;

    if (editableListAvatar) {
      const content = avatarUrl
        ? '<img src="' + esc(avatarUrl) + '" alt="تصویر پروفایل ' + esc(name) + '" loading="lazy" data-hst-avatar-img-for="' + userId + '">'
        : '<span class="hst-user-avatar__placeholder" data-hst-avatar-placeholder-for="' + userId + '">' + esc(initial) + "</span>";

      if (canManageAvatars && userId) {
        return '<button type="button" class="hst-user-avatar hst-user-avatar--editable' + (avatarUrl ? "" : " hst-user-avatar--placeholder") + '" data-hst-avatar-open-for="' + userId + '" title="ویرایش تصویر پروفایل" aria-label="ویرایش تصویر پروفایل ' + esc(name) + '">' + content + "</button>";
      }

      const staticContent = avatarUrl
        ? content.replace(/ data-hst-avatar-img-for="[^"]+"/, "")
        : '<span class="hst-user-avatar__placeholder">' + esc(initial) + "</span>";
      return '<span class="hst-user-avatar' + (avatarUrl ? "" : " hst-user-avatar--placeholder") + '">' + staticContent + "</span>";
    }

    if (avatarUrl) {
      return '<span class="hst-disc-picker-avatar"><img src="' + esc(avatarUrl) + '" alt=""></span>';
    }

    return '<span class="hst-disc-picker-avatar hst-disc-picker-avatar--initial">' + esc(initial) + "</span>";
  }

  function hstActionIcon(name) {
    const icons = {
      profile: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/>',
      view: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
      edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
      delete: '<path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/>',
      print: '<path d="M6 9V3h12v6"/><rect x="5" y="13" width="14" height="8" rx="1"/><path d="M7 13H5a3 3 0 0 1 0-6h14a3 3 0 0 1 0 6h-2"/><path d="M8 17h8M8 20h8"/>',
    };
    return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + (icons[name] || "") + "</svg>";
  }

  function disciplineStudentCell(record) {
    return (
      '<span class="hst-user-id hst-disc-student-id">' +
        studentAvatarHtml({
          id: record.student_id || 0,
          name: record.student_name || "",
          avatar_url: record.avatar_url || "",
          initial: record.initial || "",
        }, true) +
        '<span class="hst-user-id__name">' + esc(record.student_name || "—") + "</span>" +
      "</span>"
    );
  }

  const $modal = $("#hst-disc-modal");
  const $modalTitle = $("#hst-disc-modal-title");
  const $viewModal = $("#hst-disc-view-modal");
  const $viewBody = $("#hst-disc-view-body");
  const $list = $("#hst-disc-list");
  const $statbarCard = $("#hst-disc-statbar-card");
  const $statbar = $("#hst-disc-statbar");
  const $settingsModal = $("#hst-disc-settings-modal");

  let selectedStudent = null;
  let selectedStudents = [];
  let editingId = 0;
  let recordsById = {};
  const unavailableBookTitle = $("#hst-disc-print-book").attr("title")
    || "دانش‌آموزی در سال تحصیلی فعال ثبت نشده است.";

  function syncDisciplineBookButton() {
    const title = hasActiveStudents
      ? "دریافت دفتر انضباطی"
      : unavailableBookTitle;

    $("#hst-disc-print-book")
      .prop("disabled", !hasActiveStudents)
      .attr("title", title)
      .attr("aria-label", title);
  }

  function openModal(mode) {
    $modal.addClass("is-active").attr("aria-hidden", "false");
    $modalTitle.text(mode === "edit" ? "ویرایش مورد انضباطی" : "ثبت مورد انضباطی جدید");
    $("#hst-disc-save").text(mode === "edit" ? "ذخیره تغییرات" : "ثبت مورد");
    window.setTimeout(() => $modal.find("input, select, textarea").filter(":visible").first().trigger("focus"), 80);
  }

  function closeModal() {
    $modal.removeClass("is-active").attr("aria-hidden", "true");
  }

  function viewRow(label, value, variant) {
    let cls = "hst-view-row";
    if (variant === "wide") cls += " hst-view-row--wide";
    return (
      '<div class="' + cls + '"><span class="hst-view-row__label">' +
      esc(label) +
      '</span><span class="hst-view-row__value">' +
      (value || '<span class="hst-muted">—</span>') +
      "</span></div>"
    );
  }

  function openViewModal(record) {
    if (!record) {
      HST.toast("اطلاعات مورد برای مشاهده پیدا نشد.", "error");
      return;
    }

    const description = String(record.description || "").trim();
    const title = String(record.title || "").trim();
    const initial = String(record.initial || HST.initials(record.student_name || "") || "؟");
    const avatar = record.avatar_url
      ? '<img src="' + esc(record.avatar_url) + '" alt="تصویر دانش‌آموز">'
      : '<span class="hst-view-avatar__ph">' + esc(initial) + "</span>";
    const badge =
      '<span class="hst-disc-badge hst-disc-badge--' +
      esc(record.type || "") +
      '">' +
      esc(record.type_label || "—") +
      " · " +
      esc(record.severity_label || "—") +
      "</span>";

    $viewBody.html(
      '<div class="hst-view-head">' +
        '<div class="hst-view-avatar">' + avatar + "</div>" +
        '<div><strong class="hst-view-name">' + esc(record.student_name || "—") + "</strong></div>" +
      "</div>" +
      '<div class="hst-view-grid">' +
        viewRow("نوع مورد", badge) +
        viewRow("تاریخ ثبت", esc(record.incident_date || "—")) +
        viewRow("عنوان", esc(title || "—"), "wide") +
        viewRow("توضیحات", description ? esc(description) : '<span class="hst-muted">—</span>', "wide") +
      "</div>"
    );

    $viewModal.addClass("is-active").attr("aria-hidden", "false");
  }

  function closeViewModal() {
    $viewModal.removeClass("is-active").attr("aria-hidden", "true");
  }

  function normalizePickedStudent(student) {
    student = student || {};
    return {
      id: parseInt(student.id || student.student_id || 0, 10) || 0,
      name: student.name || student.student_name || "",
      avatar_url: student.avatar_url || "",
      initial: student.initial || "",
    };
  }

  function syncPrimarySelectedStudent() {
    selectedStudent = selectedStudents.length ? selectedStudents[0] : null;
  }

  function addSelectedStudent(student, replace) {
    const item = normalizePickedStudent(student);
    if (!item.id) return;

    if (replace) {
      selectedStudents = [item];
      syncPrimarySelectedStudent();
      return;
    }

    const exists = selectedStudents.some((s) => parseInt(s.id, 10) === item.id);
    if (!exists) {
      selectedStudents.push(item);
    }
    syncPrimarySelectedStudent();
  }

  function removeSelectedStudent(id) {
    id = parseInt(id, 10) || 0;
    selectedStudents = selectedStudents.filter((s) => parseInt(s.id, 10) !== id);
    syncPrimarySelectedStudent();
  }

  function selectedStudentIds() {
    return selectedStudents.map((s) => parseInt(s.id, 10)).filter(Boolean);
  }

  function renderSelected() {
    const $selected = $("[data-hst-disc-picker] .hst-user-picker-selected");

    if (!selectedStudents.length && selectedStudent) {
      addSelectedStudent(selectedStudent, true);
    }

    if (!selectedStudents.length) {
      $selected.empty();
      return;
    }

    $selected.html(
      selectedStudents.map((student) =>
        '<span class="hst-user-picker-chip hst-disc-picker-chip" data-student-id="' + esc(student.id) + '">' +
          studentAvatarHtml(student) +
          '<span class="hst-user-picker-chip-info"><b>' + esc(student.name) + "</b></span>" +
          '<button type="button" class="hst-user-picker-remove" data-student-id="' + esc(student.id) + '" aria-label="حذف">&times;</button></span>'
      ).join("")
    );

    $selected.find("button").on("click", function () {
      removeSelectedStudent($(this).data("student-id"));
      renderSelected();
    });
  }

  function resetForm() {
    editingId = 0;
    selectedStudent = null;
    selectedStudents = [];
    $("#hst-disc-id").val("0");
    $("#hst-disc-form").get(0)?.reset();
    $("#hst-disc-type").val("violation");
    $("#hst-disc-severity").val("medium");
    $("#hst-disc-title, #hst-disc-description, #hst-disc-date").val("");
    $("[data-hst-disc-picker] .hst-user-picker-search").val("");
    $("[data-hst-disc-picker] .hst-user-picker-results").prop("hidden", true).empty();
    renderSelected();
  }

  function fillForm(record) {
    editingId = parseInt(record.id, 10) || 0;
    selectedStudent = {
      id: record.student_id,
      name: record.student_name,
      avatar_url: record.avatar_url || "",
      initial: record.initial || "",
    };
    selectedStudents = [normalizePickedStudent(selectedStudent)];

    $("#hst-disc-id").val(editingId);
    $("#hst-disc-type").val(record.type || "violation");
    $("#hst-disc-severity").val(record.severity || "medium");
    $("#hst-disc-title").val(record.title || "");
    $("#hst-disc-description").val(record.description || "");
    $("#hst-disc-date").val(record.incident_date || "");
    renderSelected();
  }

  function openSettingsModal() {
    $settingsModal.addClass("is-active").attr("aria-hidden", "false");
  }

  function closeSettingsModal() {
    $settingsModal.removeClass("is-active").attr("aria-hidden", "true");
  }

  $("#hst-disc-add").on("click", function () {
    resetForm();
    openModal("add");
  });

  $("#hst-disc-settings").on("click", openSettingsModal);
  $(document).on("click", "[data-hst-disc-settings-close]", closeSettingsModal);

  $("#hst-disc-settings-save").on("click", function () {
    const effects = {};
    let valid = true;

    $settingsModal.find("[data-hst-disc-setting-type]").each(function () {
      const $row = $(this);
      const type = String($row.data("hst-disc-setting-type") || "");
      const conduct = Number($row.find('[data-hst-disc-setting="conduct"]').val());
      const attendance = Number($row.find('[data-hst-disc-setting="attendance"]').val());

      if (!type || !Number.isFinite(conduct) || !Number.isFinite(attendance)) {
        valid = false;
        return false;
      }
      effects[type] = { conduct, attendance };
    });

    if (!valid) {
      HST.toast("مقادیر اثرگذاری را به‌صورت عددی وارد کنید.", "error");
      return;
    }

    HST.request({
      action: "hst_discipline_calculation_settings_save",
      data: { effects },
      trigger: this,
      successMessage: true,
      reload: false,
      onSuccess() {
        closeSettingsModal();
      },
    });
  });

  async function printDisciplineBook(studentId, trigger) {
    const isBulk = !Number(studentId || 0);
    const progress = HST.operationProgress
      ? HST.operationProgress.open({
          title: isBulk ? "در حال ساخت دفتر انضباطی دانش‌آموزان" : "در حال ساخت دفتر انضباطی دانش‌آموز",
          subtitle: "آماده‌سازی اطلاعات و صفحات PDF ممکن است کمی زمان ببرد.",
          percent: 2,
          text: "در حال دریافت اطلاعات دفتر انضباطی...",
          lockMessage: "ساخت دفتر انضباطی هنوز کامل نشده است؛ لطفاً صبر کنید.",
        })
      : null;
    if (!progress && HST.loader) HST.loader.show();
    let completed = false;

    try {
      const response = await HST.request({
        action: "hst_discipline_print_book",
        data: {
          student_id: studentId || 0,
        },
        trigger: trigger,
        showLoader: false,
        dedupe: `hst_discipline_print_book_${studentId || 0}`,
        async onSuccess(res) {
          const data = (res && res.data) || {};
          if (progress) progress.update(10, "اطلاعات دریافت شد؛ در حال آماده‌سازی صفحات...", "آماده‌سازی PDF");

          if (data.blocks && data.blocks.length && window.HSTPrint && window.HSTPrint.disciplineBookPdf) {
            await window.HSTPrint.disciplineBookPdf({
              blocks: data.blocks,
              title: data.title || "دفتر انضباطی دانش‌آموزان",
              filename: data.filename || "دفتر-انضباطی.pdf",
              fallbackHtml: data.html || "",
              onProgress(percent, text) {
                if (!progress) return;
                const normalized = Math.max(0, Math.min(100, Number(percent) || 0));
                progress.update(Math.min(99, 10 + Math.round(normalized * 0.89)), text || "در حال ساخت دفتر انضباطی...", "ساخت فایل PDF");
              },
            });
            completed = true;
            return;
          }

          if (data.html && window.HSTPrint) {
            window.HSTPrint.printHtml(data.html, { title: data.title || "دفتر انضباطی دانش‌آموزان" });
            completed = true;
            return;
          }

          throw new Error("discipline_book_content_missing");
        },
      });

      if (!response || !response.success || !completed) throw new Error("discipline_book_failed");
      if (progress) progress.complete("دفتر انضباطی آماده شد و دانلود آغاز شد.");
      HST.toast("فایل دفتر انضباطی آماده شد.", "success");
    } catch (error) {
      console.error("Discipline book PDF generation failed:", error);
      if (progress) progress.fail("ساخت دفتر انضباطی انجام نشد.");
      if (String(error && error.message || "") === "discipline_book_content_missing") {
        HST.toast("محتوای دفتر انضباطی آماده نشد.", "error");
      }
    } finally {
      if (!progress && HST.loader) HST.loader.hide();
    }
  }

  $("#hst-disc-print-book").on("click", function () {
    printDisciplineBook(0, this);
  });

  $(document).on("click", "[data-hst-disc-modal-close]", closeModal);
  $(document).on("keydown", function (event) {
    if (event.key !== "Escape") return;
    if ($settingsModal.hasClass("is-active")) {
      closeSettingsModal();
      return;
    }
    if ($modal.hasClass("is-active")) {
      closeModal();
    }
  });

  function bindStudentPicker($picker, onSelect) {
    const $search = $picker.find(".hst-user-picker-search");
    const $results = $picker.find(".hst-user-picker-results");
    let timer = null;

    function doSearch(term) {
      HST.ajax({ action: "hst_discipline_search_students", query: term }).then((res) => {
        const items = (res && res.data && res.data.items) || [];
        $results.empty().prop("hidden", false);

        if (!items.length) {
          $results.html('<p class="hst-notice">دانش‌آموزی پیدا نشد.</p>');
          return;
        }

        items.forEach((u) => {
          const $row = $(
            '<button type="button" class="hst-user-picker-result hst-disc-picker-result">' +
              '<span class="hst-disc-picker-result__identity">' +
                studentAvatarHtml(u) +
                '<b class="hst-disc-picker-result__name">' + esc(u.name) + "</b>" +
              "</span>" +
              '<span class="hst-disc-picker-result__phone">' + esc(u.phone || "") + "</span>" +
            "</button>"
          );

          $row.on("click", function () {
            onSelect(u);
            $results.prop("hidden", true).empty();
            $search.val("");
          });

          $results.append($row);
        });
      });
    }

    $search.on("input", function () {
      const term = $.trim(this.value);
      clearTimeout(timer);

      if (term.length < 2) {
        $results.prop("hidden", true);
        return;
      }

      timer = setTimeout(() => doSearch(term), 120);
    });
  }

  $("[data-hst-disc-view-close]").on("click", closeViewModal);

  bindStudentPicker($("[data-hst-disc-picker]"), function (student) {
    addSelectedStudent(student, editingId > 0);
    renderSelected();
  });

  function updateStats(s) {
    if (!s) {
      return;
    }

    $statbar.find('[data-stat="total"]').text(faNum(s.total));
    $statbar.find('[data-stat="violations"]').text(faNum(s.violations));
    $statbar.find('[data-stat="warnings"]').text(faNum(s.warnings));
    $statbar.find('[data-stat="praises"]').text(faNum(s.praises));
    $statbar.find('[data-stat="absences"]').text(faNum(s.absences));
    $statbar.find('[data-stat="lates"]').text(faNum(s.lates));
    $statbar.find('[data-stat="notified"]').text(faNum(s.notified));
    $statbarCard.prop("hidden", false);
  }

  function typeClass(t) {
    return "is-" + String(t || "violation").replace(/[^a-z_-]/g, "");
  }

  function searchText(record) {
    return [
      record.student_name,
      record.title,
      record.description,
      record.type_label,
      record.severity_label,
      record.incident_date,
    ].filter(Boolean).join(" ");
  }

  function disciplineSmsSentLabelHtml() {
    return '<span class="hst-status hst-status--success hst-sms-sent-label">پیامک ارسال شده</span>';
  }

  function disciplineSmsControlHtml(record) {
    if (record && record.parent_notified) {
      return disciplineSmsSentLabelHtml();
    }

    return '<label class="hst-switch" title="فعال‌سازی پیامک مورد انضباطی" aria-label="فعال‌سازی پیامک مورد انضباطی"><input type="checkbox" class="hst-toggle-discipline-sms" data-id="' + (record && record.id ? record.id : "") + '" ' + (record && record.sms_enabled ? "checked" : "") + '><span class="hst-switch__slider"></span></label>';
  }

  function recordRow(record, index) {
    return (
      '<tr class="' + typeClass(record.type) + '" data-id="' + record.id + '" data-student-id="' + esc(record.student_id || "") + '" data-student-name="' + esc(record.student_name || "") + '" data-avatar-url="' + esc(record.avatar_url || "") + '" data-initial="' + esc(record.initial || "") + '" data-title="' + esc(record.title || "") + '" data-description="' + esc(record.description || "") + '" data-incident-date="' + esc(record.incident_date || "") + '" data-type-label="' + esc(record.type_label || "") + '" data-severity-label="' + esc(record.severity_label || "") + '" data-sms-enabled="' + (record.sms_enabled ? "1" : "0") + '" data-sms-sent="' + (record.parent_notified ? "1" : "0") + '" data-sms-message="' + esc(record.sms_message || "") + '" data-hst-search="' + esc(searchText(record)) + '" data-hst-type="' + esc(record.type || "") + '">' +
        '<td>' + faNum(index + 1) + "</td>" +
        '<td class="hst-col-fill">' + disciplineStudentCell(record) + "</td>" +
        '<td><span class="hst-disc-badge hst-disc-badge--' + esc(record.type ||"") + '">' + esc(record.type_label) + " · " + esc(record.severity_label) + "</span></td>" +
        '<td>' + esc(record.incident_date || "—") + "</td>" +
        '<td>' + disciplineSmsControlHtml(record) + '</td>' +
        '<td class="hst-actions">' +
          '<div class="hst-btn-group">' +
            '<button type="button" class="hst-btn hst-btn--ghost hst-btn--sm hst-btn--icon hst-disc-view" data-id="' + record.id + '" title="مشاهده" aria-label="مشاهده">' + hstActionIcon("view") + "</button>" +
            '<button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon hst-disc-edit" data-id="' + record.id + '" title="ویرایش" aria-label="ویرایش">' + hstActionIcon("edit") + "</button>" +
            '<button type="button" class="hst-btn hst-btn--ghost hst-btn--sm hst-disc-print-student" data-student-id="' + esc(record.student_id || "") + '" title="چاپ دفتر انضباطی دانش‌آموز" aria-label="چاپ دفتر انضباطی دانش‌آموز">' + hstActionIcon("print") + "<span>دفتر انضباطی</span></button>" +
            '<button type="button" class="hst-btn hst-btn--danger hst-btn--sm hst-btn--icon hst-disc-del" data-id="' + record.id + '" title="حذف" aria-label="حذف">' + hstActionIcon("delete") + "</button>" +
          "</div>" +
        "</td>" +
      "</tr>"
    );
  }

  function hydrateRecordsFromTable() {
    recordsById = {};
    $("#hst-disc-table tbody tr").each(function () {
      const $row = $(this);
      const id = String($row.data("id") || "");
      if (!id) return;

      const badgeText = $.trim($row.find(".hst-disc-badge").text() || "");
      const parts = badgeText.split("·").map((part) => $.trim(part));
      const typeLabel = parts[0] || "";
      const severityLabel = parts[1] || "";
      const type = String($row.attr("data-hst-type") || "");
      const severityMap = { "کم": "low", "متوسط": "medium", "زیاد": "high" };

      recordsById[id] = {
        id: parseInt(id, 10) || 0,
        student_id: parseInt($row.attr("data-student-id") || "0", 10) || 0,
        student_name: String($row.attr("data-student-name") || $.trim($row.find(".hst-user-id__name").text() || "")),
        avatar_url: String($row.attr("data-avatar-url") || ""),
        initial: String($row.attr("data-initial") || ""),
        type: type,
        type_label: typeLabel,
        severity: severityMap[severityLabel] || "medium",
        severity_label: severityLabel,
        title: String($row.attr("data-title") || ""),
        description: String($row.attr("data-description") || ""),
        incident_date: String($row.attr("data-incident-date") || $.trim($row.children("td").eq(3).text() || "")),
        sms_enabled: String($row.attr("data-sms-enabled") || "0") === "1",
        sms_message: String($row.attr("data-sms-message") || ""),
        parent_notified: String($row.attr("data-sms-sent") || "0") === "1",
      };
    });
    syncDisciplineBookButton();
  }

  function reapplyInlineFilter() {
    const $root = $('[data-hst-inline-filter="hst-disc-table"]');
    if (!$root.length) return;

    const $search = $root.find("[data-hst-inline-search]").first();
    const $select = $root.find("[data-hst-inline-select]").first();

    if ($search.length) {
      $search.trigger("input");
    } else if ($select.length) {
      $select.trigger("change");
    } else if (window.HST && typeof HST.refreshTablePagination === "function") {
      HST.refreshTablePagination("#hst-disc-table");
    }
  }

  function renderList(items) {
    recordsById = {};
    items.forEach((item) => {
      recordsById[String(item.id)] = item;
    });
    syncDisciplineBookButton();

    if (!items.length) {
      $list.html('<p class="hst-alert">هنوز مورد انضباطی ثبت نشده است.</p>');
      return;
    }

    $list.html(
      '<div class="hst-table-wrap hst-disc-table-wrap">' +
        '<table class="hst-table hst-disc-table" id="hst-disc-table">' +
          '<thead><tr>' +
            '<th>ردیف</th>' +
            '<th class="hst-col-fill">دانش‌آموز</th>' +
            '<th>نوع / شدت</th>' +
            '<th>تاریخ</th>' +
            '<th>پیامک</th>' +
            '<th>عملیات</th>' +
          '</tr></thead>' +
          '<tbody>' + items.map(recordRow).join("") + '</tbody>' +
        '</table>' +
      '</div>'
    );

    reapplyInlineFilter();
  }

  function loadList(done) {
    HST.ajax({
      action: "hst_discipline_list",
    })
      .then((res) => {
        const data = (res && res.data) || {};
        updateStats(data.stats);
        renderList(data.items || []);
        if (typeof done === "function") {
          done(data);
        }
      })
      .catch(() => $list.html('<p class="hst-alert">خطا در بارگذاری فهرست.</p>'));
  }

  const disciplineSmsDefaultTemplate = String($("#hst-discipline-sms-message").val() || "");

  function editableSmsTemplate(raw, fallback) {
    raw = String(raw || "").trim();
    if (!raw) return fallback;
    try {
      const parsed = JSON.parse(raw);
      if (parsed && parsed.vars) return fallback;
    } catch (e) {}
    return raw;
  }

  function renderSmsTemplate(template, context) {
    let output = String(template || "");
    Object.keys(context || {}).forEach(function (key) {
      const value = String(context[key] == null ? "" : context[key]);
      output = output.split("{" + key + "}").join(value);
      output = output.split("%" + key + "%").join(value);
    });
    return $.trim(output);
  }

  let currentDisciplineSmsRow = null;
  let currentDisciplineSmsCheckbox = null;

  function disciplineSmsPreviewContext($row) {
    const $body = $("#hst-discipline-sms-modal .hst-modal__body");
    return {
      name: String($row && $row.length ? ($row.data("student-name") || "") : ($body.data("sms-preview-name") || "")),
      school: String($body.data("sms-preview-school") || ""),
      date: String($body.data("sms-preview-date") || ""),
      title: String($row && $row.length ? ($row.data("title") || "") : ($body.data("sms-preview-title") || "")),
      type: String($row && $row.length ? ($row.data("type-label") || $.trim($row.find(".hst-disc-badge").text() || "").split("·")[0]) : ($body.data("sms-preview-type") || "")),
      severity: String($row && $row.length ? ($row.data("severity-label") || $.trim($row.find(".hst-disc-badge").text() || "").split("·")[1]) : ($body.data("sms-preview-severity") || "")),
      description: String($row && $row.length ? ($row.data("description") || "") : ($body.data("sms-preview-description") || "")),
      incident_date: String($row && $row.length ? ($row.data("incident-date") || "") : ($body.data("sms-preview-incident-date") || "")),
    };
  }

  function renderDisciplineSmsPreview() {
    const $row = currentDisciplineSmsRow && currentDisciplineSmsRow.length ? currentDisciplineSmsRow : $();
    const context = disciplineSmsPreviewContext($row);
    const template = String($("#hst-discipline-sms-message").val() || "");
    $("#hst-discipline-sms-preview").text(renderSmsTemplate(template, context) || "—");
    const id = Number($row.data("id")) || 0;
    if (id && $.trim(template) && HST.smsUsage) {
      HST.smsUsage.schedule({
        target: "#hst-discipline-sms-usage",
        action: "hst_discipline_sms_estimate",
        data: { id, message: template },
      });
    } else if (HST.smsUsage) {
      HST.smsUsage.clear("#hst-discipline-sms-usage");
    }
  }

  function openDisciplineSmsModal($row, $checkbox) {
    currentDisciplineSmsRow = $row;
    currentDisciplineSmsCheckbox = $checkbox;

    $("#hst-discipline-sms-test-phone").val("");
    $("#hst-discipline-sms-message").val(editableSmsTemplate(currentDisciplineSmsRow.attr("data-sms-message"), disciplineSmsDefaultTemplate));
    renderDisciplineSmsPreview();

    $("#hst-discipline-sms-modal").addClass("is-active").attr("aria-hidden", "false");
    $("body").addClass("hst-modal-open");
  }

  function closeDisciplineSmsModal() {
    $("#hst-discipline-sms-modal").removeClass("is-active").attr("aria-hidden", "true");
    $("body").removeClass("hst-modal-open");

    if (currentDisciplineSmsCheckbox && currentDisciplineSmsCheckbox.length && !currentDisciplineSmsCheckbox.data("hst-confirmed")) {
      currentDisciplineSmsCheckbox.prop("checked", false).prop("disabled", false);
    }

    if (currentDisciplineSmsCheckbox && currentDisciplineSmsCheckbox.length) {
      currentDisciplineSmsCheckbox.removeData("hst-confirmed");
    }

    currentDisciplineSmsRow = null;
    currentDisciplineSmsCheckbox = null;
  }
$(document).on("click", "[data-hst-discipline-sms-close]", closeDisciplineSmsModal);
  $(document).on("input", "#hst-discipline-sms-message", renderDisciplineSmsPreview);

  $(document).on("click", "#hst-discipline-sms-test-send", function () {
    if (!currentDisciplineSmsRow || !currentDisciplineSmsRow.length) {
      HST.toast("ابتدا یک مورد انضباطی را انتخاب کنید.", "error");
      return;
    }

    const phone = $.trim($("#hst-discipline-sms-test-phone").val() || "");
    const message = $.trim($("#hst-discipline-sms-message").val() || "");
    const id = Number(currentDisciplineSmsRow.data("id")) || 0;

    if (!phone) {
      HST.toast("شماره موبایل تست را وارد کنید.", "error");
      return;
    }
    if (!message) {
      HST.toast("متن پیامک را وارد کنید.", "error");
      return;
    }

    HST.request({
      action: "hst_discipline_sms_test",
      data: { id, phone, message },
      trigger: this,
      successMessage: true,
      reload: false,
    });
  });

  $(document).on("click", "#hst-discipline-sms-confirm", async function () {
    if (!currentDisciplineSmsRow || !currentDisciplineSmsRow.length || !currentDisciplineSmsCheckbox || !currentDisciplineSmsCheckbox.length) {
      closeDisciplineSmsModal();
      return;
    }

    const $row = currentDisciplineSmsRow;
    const $checkbox = currentDisciplineSmsCheckbox;
    const id = Number($row.data("id")) || 0;

    if (!id) {
      HST.toast("شناسه مورد انضباطی نامعتبر است.", "error");
      return;
    }

    const message = $.trim($("#hst-discipline-sms-message").val() || "");
    if (!message) {
      HST.toast("متن پیامک را وارد کنید.", "error");
      return;
    }

    $checkbox.prop("disabled", true).data("hst-confirmed", true);

    const response = await HST.request({
      action: "hst_update_discipline_sms",
      data: { id, enabled: 1, message },
      successMessage: true,
      reload: false,
      dedupe: "hst_update_discipline_sms_" + id,
      onSuccess: function (response) {
        const data = response && response.data ? response.data : {};
        $row.attr("data-sms-enabled", "1");
        $row.attr("data-sms-message", message);
        if (data.sms_sent) {
          $row.attr("data-sms-sent", "1");
          $checkbox.closest("td").html(disciplineSmsSentLabelHtml());
        } else {
          $checkbox.prop("checked", true);
        }
      },
    });

    if (!response || !response.success) {
      $checkbox.prop("checked", false);
      $row.attr("data-sms-enabled", "0");
    }

    $checkbox.prop("disabled", false);
    closeDisciplineSmsModal();
    loadList();
  });

  $list.on("change", ".hst-toggle-discipline-sms", async function () {
    const $checkbox = $(this);
    const $row = $checkbox.closest("tr");
    const id = Number($checkbox.data("id") || $row.data("id")) || 0;
    const enabled = $checkbox.is(":checked") ? 1 : 0;
    const previousState = !enabled;

    if (!id) {
      HST.toast("شناسه مورد انضباطی نامعتبر است.", "error");
      $checkbox.prop("checked", false);
      return;
    }

    if (enabled) {
      $checkbox.prop("disabled", true);
      openDisciplineSmsModal($row, $checkbox);
      return;
    }

    $checkbox.prop("disabled", true);

    const response = await HST.request({
      action: "hst_update_discipline_sms",
      data: { id, enabled: 0, sms_message: "" },
      successMessage: true,
      reload: false,
      dedupe: "hst_update_discipline_sms_" + id,
      onSuccess: function () {
        $row.attr("data-sms-enabled", "0");
        $checkbox.prop("checked", false);
      },
    });

    if (!response || !response.success) {
      $checkbox.prop("checked", previousState);
      $row.attr("data-sms-enabled", previousState ? "1" : "0");
    }

    $checkbox.prop("disabled", false);
  });

  $("#hst-disc-save").on("click", function () {
    const ids = selectedStudentIds();

    if (!ids.length) {
      HST.toast("ابتدا حداقل یک دانش‌آموز را انتخاب کنید.", "error");
      return;
    }

    const title = $.trim($("#hst-disc-title").val() || "");
    if (!title) {
      HST.toast("عنوان مورد را وارد کنید.", "error");
      return;
    }

    HST.request({
      action: "hst_discipline_save",
      data: {
        id: editingId,
        student_id: ids[0],
        student_ids: ids.join(","),
        type: $("#hst-disc-type").val(),
        severity: $("#hst-disc-severity").val(),
        title: title,
        description: $.trim($("#hst-disc-description").val() || ""),
        incident_date: $.trim($("#hst-disc-date").val() || ""),
      },
      trigger: this,
      onSuccess(res) {
        const data = (res && res.data) || {};
        HST.toast(data.message || (editingId ? "مورد انضباطی بروزرسانی شد." : "مورد انضباطی ثبت شد."), "success");
        closeModal();
        resetForm();
        loadList();
      },
    });
  });

  $list.on("click", ".hst-disc-view", function () {
    const id = String($(this).data("id") || "");
    const record = recordsById[id];

    if (record) {
      openViewModal(record);
      return;
    }

    loadList(function () {
      openViewModal(recordsById[id]);
    });
  });

  $list.on("click", ".hst-disc-edit", function () {
    const id = String($(this).data("id"));
    let record = recordsById[id];

    if (!record || !record.student_id) {
      HST.request({
        action: "hst_discipline_list",
        trigger: this,
        showLoader: true,
        onSuccess(res) {
          const data = (res && res.data) || {};
          renderList(data.items || []);
          record = recordsById[id];
          if (!record) {
            HST.toast("اطلاعات مورد برای ویرایش پیدا نشد.", "error");
            return;
          }
          resetForm();
          fillForm(record);
          openModal("edit");
        },
      });
      return;
    }

    resetForm();
    fillForm(record);
    openModal("edit");
  });

  $list.on("click", ".hst-disc-print-student", function () {
    const studentId = parseInt($(this).data("student-id"), 10) || 0;
    if (!studentId) {
      HST.toast("دانش‌آموز برای چاپ دفتر مشخص نیست.", "error");
      return;
    }
    printDisciplineBook(studentId, this);
  });

  $list.on("click", ".hst-disc-del", function () {
    const id = $(this).data("id");

    HST.request({
      action: "hst_discipline_delete",
      data: { id: id },
      trigger: this,
      confirm: { title: "حذف مورد", text: "این مورد انضباطی حذف شود؟" },
      onSuccess() {
        HST.toast("مورد حذف شد.", "success");
        loadList();
      },
    });
  });

  hydrateRecordsFromTable();
  if (!$("#hst-disc-table").length) {
    loadList();
  } else {
    reapplyInlineFilter();
  }
});
