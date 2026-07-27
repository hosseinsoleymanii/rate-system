jQuery(function ($) {
  "use strict";

  const MAX_AMOUNT = 9999999999;

  function notifyError(message) {
    if (window.HST && typeof HST.toast === "function") {
      HST.toast(message, "error");
      return;
    }
    alert(message);
  }

  function faProgressText(value) {
    const fa = "۰۱۲۳۴۵۶۷۸۹";
    return String(value == null ? "" : value).replace(/[0-9]/g, function (digit) {
      return fa[Number(digit)] || digit;
    });
  }

  function ensureExportProgressModal() {
    let $modal = $("#hst-tuition-export-progress-modal");
    if ($modal.length) return $modal;
    $modal = $(`
      <div class="hst-modal" data-hst-progress-modal data-hst-modal-size="md" id="hst-tuition-export-progress-modal" role="dialog" aria-modal="true" aria-labelledby="hst-tuition-export-progress-title" aria-hidden="true">
        <div class="hst-modal__backdrop"></div>
        <div class="hst-modal__panel">
          <div class="hst-modal__header">
            <div>
              <h3 id="hst-tuition-export-progress-title">در حال ساخت خروجی</h3>
              <p>تا پایان آماده‌سازی فایل، صفحه را نبندید.</p>
            </div>
            <button type="button" class="hst-modal__close hst-tuition-export-progress-close" data-hst-progress-close aria-label="بستن">×</button>
          </div>
          <div class="hst-modal__body">
            <div class="hst-operation-progress hst-schedule-global-progress" aria-live="polite">
              <div class="hst-operation-progress__head">
                <strong class="hst-operation-progress__title">در حال آماده‌سازی</strong>
                <span class="hst-operation-progress__percent">۰٪</span>
              </div>
              <div class="hst-operation-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <span class="hst-operation-progress__bar"></span>
              </div>
              <p class="hst-operation-progress__hint">در حال شروع عملیات...</p>
            </div>
          </div>
          <div class="hst-modal__footer">
            <button type="button" class="hst-btn hst-btn--ghost hst-tuition-export-progress-close" data-hst-progress-close>بستن</button>
          </div>
        </div>
      </div>
    `);

    $modal.find(".hst-tuition-export-progress-close").on("click", function () {
      if (isExportProgressRunning) {
        HST.toast("تا پایان آماده‌سازی خروجی، صفحه را نبندید", "error");
        return;
      }
      $modal.removeClass("is-open").attr("aria-hidden", "true");
      $("body").removeClass("hst-modal-open");
    });

    $("body").append($modal);
    return $modal;
  }


  function updateExportProgress(percent, text) {
    const safePercent = Math.max(0, Math.min(100, parseInt(percent, 10) || 0));
    const $modal = ensureExportProgressModal();
    $modal.find(".hst-operation-progress__bar").css("width", safePercent + "%");
    $modal.find(".hst-operation-progress__percent").text(faProgressText(safePercent + "%"));
    $modal.find(".hst-operation-progress__track").attr("aria-valuenow", String(safePercent));
    if (text) {
      $modal.find(".hst-operation-progress__hint").text(faProgressText(text));
    }
  }

  function showExportLoader(title, text) {
    const $modal = ensureExportProgressModal();
    isExportProgressRunning = true;
    $modal.find("#hst-tuition-export-progress-title").text(title || "در حال ساخت خروجی");
    $modal.find(".hst-modal__header p").text("تا پایان آماده‌سازی فایل، صفحه را نبندید.");
    HST.setProgressModalLocked(
      $modal,
      true,
      "تا پایان آماده‌سازی خروجی، صفحه را نبندید یا ترک نکنید."
    );
    $modal.find(".hst-operation-progress__bar").css("width", "0%");
    $modal.find(".hst-operation-progress__percent").text("۰٪");
    $modal.find(".hst-operation-progress__track").attr("aria-valuenow", "0");
    updateExportProgress(1, text || "در حال آماده‌سازی فایل...");
    $modal.addClass("is-open").attr("aria-hidden", "false");
    $("body").addClass("hst-modal-open");
  }


  function hideExportLoader() {
    updateExportProgress(100, "خروجی آماده شد");
    isExportProgressRunning = false;
    const $modal = $("#hst-tuition-export-progress-modal");
    HST.setProgressModalLocked($modal, false);
    window.setTimeout(function () {
      $modal.removeClass("is-open").attr("aria-hidden", "true");
      $("body").removeClass("hst-modal-open");
    }, 420);
  }


  function normalizeDigits(value) {
    const persian = "۰۱۲۳۴۵۶۷۸۹";
    const arabic = "٠١٢٣٤٥٦٧٨٩";

    return String(value || "")
      .replace(/[۰-۹]/g, (digit) => persian.indexOf(digit))
      .replace(/[٠-٩]/g, (digit) => arabic.indexOf(digit));
  }

  function cleanToman(value) {
    return normalizeDigits(value).replace(/[^\d]/g, "");
  }

  function formatToman(value) {
    const clean = cleanToman(value);
    return clean ? clean.replace(/\B(?=(\d{3})+(?!\d))/g, ",") : "";
  }

  function modalAmount(value) {
    return formatToman(String(value == null ? "" : value));
  }

  const $tuitionPlanModal = $("#hst-tuition-plan-modal");
  const $tuitionPlanForm = $("#hst-tuition-plan-form");
  const $tuitionPlanTitle = $("#hst-tuition-plan-modal-title");
  const $tuitionPlanHelp = $tuitionPlanModal.find("[data-hst-tuition-plan-help]");
  const $tuitionPlanSubmit = $tuitionPlanModal.find("[data-hst-tuition-plan-submit]");
  const $tuitionPaidNote = $tuitionPlanModal.find("[data-hst-tuition-paid-note]");
  const $tuitionLockableFields = $tuitionPlanModal.find("[data-hst-tuition-lockable]");

  let tuitionPlanMode = "add";
  let tuitionEditingValues = {};
  let tuitionHasPaid = false;

  function initModalDatepickers() {
    if (window.HSTJalaliDatepicker && typeof window.HSTJalaliDatepicker.init === "function") {
      window.HSTJalaliDatepicker.init($tuitionPlanModal.get(0) || document);
    }
  }

  function setTuitionField(name, value) {
    $tuitionPlanForm.find('[name="' + name + '"]').val(value == null ? "" : String(value));
  }

  function getTuitionField(name) {
    return $tuitionPlanForm.find('[name="' + name + '"]').val();
  }

  function resetTuitionPlanForm() {
    const form = $tuitionPlanForm.get(0);
    if (form) form.reset();

    setTuitionField("plan_id", "0");
    setTuitionField("paid_count", "0");
    setTuitionField("class_id", "0");

    $tuitionPaidNote.prop("hidden", true);
    $tuitionLockableFields.prop("hidden", false);
    $tuitionLockableFields.find("input, select, textarea").prop("disabled", false);
  }

  function openTuitionPlanModal(values = {}) {
    tuitionEditingValues = values || {};
    tuitionPlanMode = values && values.id ? "edit" : "add";
    tuitionHasPaid = Number(values.paid_count || 0) > 0;

    resetTuitionPlanForm();

    if (tuitionPlanMode === "edit") {
      $tuitionPlanTitle.text("ویرایش شهریه");
      $tuitionPlanHelp.text(tuitionHasPaid ? "برای شهریه دارای پرداخت، نام شهریه، توضیحات و تاریخ سررسید قابل ویرایش است." : "تا قبل از ثبت پرداخت موفق، اطلاعات کامل شهریه قابل ویرایش است.");
      $tuitionPlanSubmit.text("ذخیره تغییرات");
    } else {
      $tuitionPlanTitle.text("افزودن شهریه");
      $tuitionPlanHelp.text("اطلاعات شهریه جدید را وارد کنید. شهریه پس از ثبت به صورت پیش‌فرض غیرفعال است.");
      $tuitionPlanSubmit.text("ثبت شهریه");
    }

    setTuitionField("plan_id", values.id || 0);
    setTuitionField("paid_count", values.paid_count || 0);
    setTuitionField("title", values.title || "");
    setTuitionField("amount", modalAmount(values.amount || ""));
    setTuitionField("class_id", values.class_id || 0);
    setTuitionField("due_date", values.due_date || "");
    setTuitionField("description", values.description || "");

    if (tuitionHasPaid) {
      $tuitionPaidNote.prop("hidden", false);
      $tuitionLockableFields.prop("hidden", true);
      $tuitionLockableFields.find("input, select, textarea").prop("disabled", true);
    }

    $tuitionPlanModal.addClass("is-active").attr("aria-hidden", "false");
    $("body").addClass("hst-modal-open");

    window.setTimeout(function () {
      initModalDatepickers();
      $tuitionPlanForm.find("input, select, textarea").filter(":visible:not(:disabled)").first().trigger("focus");
    }, 80);
  }

  function closeTuitionPlanModal() {
    $tuitionPlanModal.removeClass("is-active").attr("aria-hidden", "true");
    $("body").removeClass("hst-modal-open");
  }

  function tuitionPlanData() {
    const amount = tuitionHasPaid
      ? String(tuitionEditingValues.amount || "")
      : cleanToman(getTuitionField("amount") || "");

    const classId = tuitionHasPaid
      ? Number(tuitionEditingValues.class_id || 0) || 0
      : Number(getTuitionField("class_id") || 0) || 0;

    return {
      plan_id: Number(getTuitionField("plan_id") || 0) || 0,
      title: $.trim(getTuitionField("title") || "").slice(0, 120),
      amount: amount,
      class_id: classId,
      due_date: $.trim(getTuitionField("due_date") || ""),
      description: $.trim(getTuitionField("description") || "").slice(0, 800),
    };
  }

  function validateTuitionPlanData(data) {
    if (!data.title) {
      notifyError("عنوان شهریه الزامی است.");
      return false;
    }

    if (!data.amount || Number(data.amount) <= 0 || Number(data.amount) > MAX_AMOUNT) {
      notifyError("مبلغ شهریه معتبر نیست.");
      return false;
    }

    return true;
  }

  $(document).on("input", ".hst-toman-input", function () {
    const cursorToEnd = this.selectionStart === this.value.length;
    this.value = formatToman(this.value);
    if (cursorToEnd) {
      this.setSelectionRange(this.value.length, this.value.length);
    }
  });

  $(document).on("click", "#hst-tuition-add", function () {
    openTuitionPlanModal();
  });

  $(document).on("click", ".hst-edit-tuition", function () {
    const $button = $(this);
    openTuitionPlanModal({
      id: Number($button.data("id")) || 0,
      title: String($button.data("title") || ""),
      amount: String($button.data("amount") || ""),
      class_id: Number($button.data("class-id")) || 0,
      due_date: String($button.data("due-date") || ""),
      description: String($button.data("description") || ""),
      paid_count: Number($button.data("paid-count")) || 0,
    });
  });

  $(document).on("click", "[data-hst-tuition-plan-close]", closeTuitionPlanModal);

  $(document).on("keydown", function (event) {
    if (event.key === "Escape" && $tuitionPlanModal.hasClass("is-active")) {
      closeTuitionPlanModal();
    }
  });

  $tuitionPlanForm.on("submit", function (event) {
    event.preventDefault();

    const data = tuitionPlanData();
    if (!validateTuitionPlanData(data)) return;

    const requestOptions = data.plan_id
      ? {
          action: "hst_update_tuition_plan",
          data,
          trigger: $tuitionPlanSubmit.get(0),
          successMessage: true,
          reload: true,
        }
      : {
          action: "hst_add_tuition_plan",
          data,
          trigger: $tuitionPlanSubmit.get(0),
          successMessage: true,
          reload: true,
        };

    HST.request(requestOptions);
  });


  function updatePlanRowStatus($checkbox, isActive) {
    const $row = $checkbox.closest("tr");
    $row.attr("data-hst-status", isActive ? "active" : "inactive");
  }

  $(document).on("change", ".hst-tuition-status", async function () {
    const $checkbox = $(this);
    const id = Number($checkbox.data("id")) || 0;
    const isActive = $checkbox.is(":checked") ? 1 : 0;
    const previousState = !isActive;

    $checkbox.prop("disabled", true);

    const requestOptions = {
      action: "hst_toggle_tuition_plan_status",
      data: { plan_id: id, is_active: isActive },
      successMessage: true,
      errorMessage: "تغییر وضعیت شهریه انجام نشد",
      onSuccess: function () {
        updatePlanRowStatus($checkbox, !!isActive);
      },
      reload: !!isActive,
    };

    if (isActive) {
      requestOptions.confirm = {
        title: "فعال‌سازی شهریه",
        text: "با فعال‌سازی این شهریه، صورتحساب دانش‌آموزان مرتبط هم ایجاد می‌شود. ادامه می‌دهید؟",
        confirmText: "فعال‌سازی و ایجاد صورتحساب",
      };
    }

    const response = await HST.request(requestOptions);

    if (!response?.success) {
      $checkbox.prop("checked", previousState);
      updatePlanRowStatus($checkbox, previousState);
    }

    $checkbox.prop("disabled", false);
  });

  function tuitionSmsSentLabelHtml() {
    return '<span class="hst-status hst-status--success hst-sms-sent-label">پیامک ارسال شده</span>';
  }

  const tuitionSmsDefaultTemplate = String($("#hst-tuition-sms-message").val() || "");

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

  let currentTuitionSmsRow = null;
  let currentTuitionSmsCheckbox = null;
  let tuitionSmsModalConfirmed = false;

  function tuitionSmsIsReady($checkbox) {
    const checkboxReady = $checkbox && $checkbox.length
      ? String($checkbox.attr("data-sms-ready") || "") === "1"
      : false;
    const modalReady = String($("#hst-tuition-sms-modal .hst-modal__body").attr("data-sms-ready") || "") === "1";
    return checkboxReady && modalReady;
  }

  function tuitionSmsPreviewContext($row) {
    const $body = $("#hst-tuition-sms-modal .hst-modal__body");
    return {
      name: String($body.data("sms-preview-name") || "دانش‌آموز نمونه"),
      school: String($body.data("sms-preview-school") || ""),
      date: String($body.data("sms-preview-date") || ""),
      title: String($row && $row.length ? ($row.data("title") || "") : ($body.data("sms-preview-title") || "")),
      amount: String($row && $row.length ? ($row.data("amount-text") || "") : ($body.data("sms-preview-amount") || "")),
      due_date: String($row && $row.length ? ($row.data("due-date-text") || "") : ($body.data("sms-preview-due-date") || "")),
    };
  }

  function renderTuitionSmsPreview() {
    const $row = currentTuitionSmsRow && currentTuitionSmsRow.length ? currentTuitionSmsRow : $();
    const context = tuitionSmsPreviewContext($row);
    const template = String($("#hst-tuition-sms-message").val() || "");
    $("#hst-tuition-sms-preview").text(renderSmsTemplate(template, context) || "—");
    const planId = Number($row.data("id")) || 0;
    if (planId && $.trim(template) && HST.smsUsage) {
      HST.smsUsage.schedule({
        target: "#hst-tuition-sms-usage",
        action: "hst_tuition_sms_estimate",
        data: { plan_id: planId, message: template },
      });
    } else if (HST.smsUsage) {
      HST.smsUsage.clear("#hst-tuition-sms-usage");
    }
  }

  function restoreTuitionSmsToggle() {
    if (!currentTuitionSmsCheckbox || !currentTuitionSmsCheckbox.length) {
      return;
    }

    const isEnabled = currentTuitionSmsRow && currentTuitionSmsRow.length
      ? String(currentTuitionSmsRow.attr("data-sms-enabled") || "0") === "1"
      : false;

    currentTuitionSmsCheckbox
      .prop("checked", isEnabled)
      .prop("disabled", false);
  }

  function openTuitionSmsModal($row, $checkbox) {
    const $modal = $("#hst-tuition-sms-modal");
    if (!$modal.length) {
      $checkbox.prop("checked", false).prop("disabled", false);
      notifyError("مدال تنظیم پیامک شهریه پیدا نشد. صفحه را بازخوانی کنید.");
      return;
    }

    currentTuitionSmsRow = $row && $row.length ? $row : $();
    currentTuitionSmsCheckbox = $checkbox && $checkbox.length ? $checkbox : $();
    tuitionSmsModalConfirmed = false;

    // Checking the switch only opens the confirmation modal. The switch is
    // committed after the server confirms the request.
    currentTuitionSmsCheckbox.prop("checked", false).prop("disabled", false);
    $("#hst-tuition-sms-test-phone").val("");
    $("#hst-tuition-sms-message").val(editableSmsTemplate(currentTuitionSmsRow.attr("data-sms-message"), tuitionSmsDefaultTemplate));
    renderTuitionSmsPreview();

    $modal.addClass("is-active").attr("aria-hidden", "false");
    $("body").addClass("hst-modal-open");

    window.setTimeout(function () {
      const $focusTarget = tuitionSmsIsReady(currentTuitionSmsCheckbox)
        ? $("#hst-tuition-sms-message")
        : $modal.find("[data-hst-tuition-sms-close]").last();
      $focusTarget.trigger("focus");
    }, 80);
  }

  function closeTuitionSmsModal() {
    const $modal = $("#hst-tuition-sms-modal");
    $modal.removeClass("is-active").attr("aria-hidden", "true");

    if (!$(".hst-modal.is-active, .hst-modal.is-open, .hst-modal[data-open]").not($modal).length) {
      $("body").removeClass("hst-modal-open");
    }

    if (!tuitionSmsModalConfirmed) {
      restoreTuitionSmsToggle();
    }

    currentTuitionSmsRow = null;
    currentTuitionSmsCheckbox = null;
    tuitionSmsModalConfirmed = false;
  }

  $(document).on("click", "[data-hst-tuition-sms-close]", closeTuitionSmsModal);
  $(document).on("input", "#hst-tuition-sms-message", renderTuitionSmsPreview);

  $(document).on("keydown.hstTuitionSms", function (event) {
    if (event.key === "Escape" && $("#hst-tuition-sms-modal").hasClass("is-active")) {
      closeTuitionSmsModal();
    }
  });

  $(document).on("click", "#hst-tuition-sms-test-send", function () {
    if (!currentTuitionSmsRow || !currentTuitionSmsRow.length) {
      notifyError("ابتدا یک شهریه را انتخاب کنید.");
      return;
    }

    if (!tuitionSmsIsReady(currentTuitionSmsCheckbox)) {
      notifyError("تنظیمات پیامک شهریه کامل نیست.");
      return;
    }

    const phone = $.trim($("#hst-tuition-sms-test-phone").val() || "");
    const message = $.trim($("#hst-tuition-sms-message").val() || "");
    const planId = Number(currentTuitionSmsRow.data("id")) || 0;

    if (!phone) {
      notifyError("شماره موبایل تست را وارد کنید.");
      return;
    }
    if (!message) {
      notifyError("متن پیامک را وارد کنید.");
      return;
    }

    HST.request({
      action: "hst_tuition_sms_test",
      data: { plan_id: planId, phone, message },
      trigger: this,
      successMessage: true,
      reload: false,
    });
  });

  $(document).on("click", "#hst-tuition-sms-confirm", async function () {
    if (!currentTuitionSmsRow || !currentTuitionSmsRow.length || !currentTuitionSmsCheckbox || !currentTuitionSmsCheckbox.length) {
      closeTuitionSmsModal();
      return;
    }

    if (!tuitionSmsIsReady(currentTuitionSmsCheckbox)) {
      notifyError("تنظیمات پیامک شهریه کامل نیست.");
      return;
    }

    const $row = currentTuitionSmsRow;
    const $checkbox = currentTuitionSmsCheckbox;
    const id = Number($row.data("id")) || 0;

    if (!id) {
      notifyError("شناسه شهریه نامعتبر است.");
      return;
    }

    const message = $.trim($("#hst-tuition-sms-message").val() || "");
    if (!message) {
      notifyError("متن پیامک را وارد کنید.");
      return;
    }

    $checkbox.prop("disabled", true);

    const response = await HST.request({
      action: "hst_update_tuition_sms",
      data: { plan_id: id, enabled: 1, message },
      trigger: this,
      successMessage: true,
      reload: false,
      dedupe: "hst_update_tuition_sms_" + id,
    });

    if (!response || !response.success) {
      $row.attr("data-sms-enabled", "0");
      $checkbox.prop("checked", false).prop("disabled", false);
      return;
    }

    const data = response.data || {};
    tuitionSmsModalConfirmed = true;
    $row.attr("data-sms-enabled", "1").attr("data-sms-message", message);

    if (data.sms_sent) {
      $row.attr("data-sms-sent", "1");
      $checkbox.closest("td").html(tuitionSmsSentLabelHtml());
    } else {
      $checkbox.prop("checked", true).prop("disabled", false);
    }

    closeTuitionSmsModal();
  });

  $(document).on("change", ".hst-toggle-tuition-sms", async function () {
    const $checkbox = $(this);
    const $row = $checkbox.closest("tr");
    const id = Number($checkbox.data("id") || $row.data("id")) || 0;
    const enabled = $checkbox.is(":checked") ? 1 : 0;
    const previousState = String($row.attr("data-sms-enabled") || "0") === "1";

    if (!id) {
      notifyError("شناسه شهریه نامعتبر است.");
      $checkbox.prop("checked", previousState).prop("disabled", false);
      return;
    }

    if (enabled) {
      openTuitionSmsModal($row, $checkbox);
      return;
    }

    $checkbox.prop("disabled", true);

    const response = await HST.request({
      action: "hst_update_tuition_sms",
      data: { plan_id: id, enabled: 0 },
      successMessage: true,
      reload: false,
      dedupe: "hst_update_tuition_sms_" + id,
    });

    if (!response || !response.success) {
      $checkbox.prop("checked", previousState);
      $row.attr("data-sms-enabled", previousState ? "1" : "0");
    } else {
      $row.attr("data-sms-enabled", "0");
      $checkbox.prop("checked", false);
    }

    $checkbox.prop("disabled", false);
  });

  $(document).on("click", ".hst-delete-tuition", function () {
    const $button = $(this);
    if ($button.is(":disabled")) {
      return;
    }

    HST.request({
      action: "hst_delete_tuition_plan",
      data: { plan_id: Number($button.data("id")) || 0 },
      trigger: this,
      confirm: {
        title: "حذف شهریه؟",
        text: "این عملیات قابل بازگشت نیست.",
      },
      successMessage: true,
      reload: true,
    });
  });


  // ---- Tuition invoice report modal --------------------------------------
  const $reportModal = $("[data-hst-tuition-report-modal]");
  let activePlanId = 0;
  let activePlanTitle = "";
  let activePlanClassId = 0;
  let invoiceRows = [];
  let currentClassOptions = [];
  let classOptionsRenderedForPlan = 0;
  let searchTimer = null;
  let reportRequestId = 0;
  let isExportProgressRunning = false;

  function setReportExportAction(selector, enabled, enabledLabel, disabledLabel) {
    $reportModal.find(selector)
      .prop("disabled", !enabled)
      .attr("title", enabled ? enabledLabel : disabledLabel)
      .attr("aria-label", enabled ? enabledLabel : disabledLabel);
  }

  function syncReportExportActions() {
    const hasRows = invoiceRows.length > 0;
    const hasPaidRows = invoiceRows.some(function (row) { return row.status === "paid"; });
    const hasUnpaidRows = invoiceRows.some(function (row) { return row.status !== "paid"; });

    setReportExportAction("[data-hst-tuition-export-excel]", hasRows, "خروجی Excel گزارش", "داده‌ای برای خروجی Excel وجود ندارد.");
    setReportExportAction("[data-hst-tuition-invoices-pdf]", hasRows, "خروجی فاکتورها", "صورتحسابی برای خروجی وجود ندارد.");
    setReportExportAction("[data-hst-tuition-unpaid-list-pdf]", hasUnpaidRows, "خروجی فهرست پرداخت‌نشده‌ها", "صورتحساب پرداخت‌نشده‌ای وجود ندارد.");
    setReportExportAction("[data-hst-tuition-paid-list-pdf]", hasPaidRows, "خروجی فهرست پرداخت‌شده‌ها", "صورتحساب پرداخت‌شده‌ای وجود ندارد.");
  }

  function faText(value) {
    return HST.escapeHtml(String(value == null || value === "" ? "—" : value));
  }
  function normalizePersianSortText(value) {
    return String(value || "")
      .replace(/ي/g, "ی")
      .replace(/ك/g, "ک")
      .replace(/آ/g, "ا")
      .replace(/[ًٌٍَُِّْ]/g, "")
      .trim();
  }

  function rowClassSortValue(row) {
    return normalizePersianSortText(row.class_name || "");
  }

  function rowLastNameSortValue(row) {
    return normalizePersianSortText(row.student_last_name || row.student_name || "");
  }

  function sortRowsForExport(rows) {
    return (rows || []).slice().sort(function (a, b) {
      const classCompare = window.HST && typeof HST.compareClassNames === "function"
        ? HST.compareClassNames(rowClassSortValue(a), rowClassSortValue(b))
        : rowClassSortValue(a).localeCompare(rowClassSortValue(b), "fa");
      return classCompare ||
        rowLastNameSortValue(a).localeCompare(rowLastNameSortValue(b), "fa") ||
        normalizePersianSortText(a.student_name).localeCompare(normalizePersianSortText(b.student_name), "fa");
    });
  }

  function userAvatarHtml(row) {
    if (row && row.avatar_url) {
      return '<span class="hst-user-avatar"><img src="' + HST.escapeHtml(row.avatar_url) + '" alt="' + faText(row.student_name || 'دانش‌آموز') + '"></span>';
    }

    const name = String((row && row.student_name) || "دانش‌آموز");
    const initials = String((row && row.initials) || HST.initials(name, (row && row.first_name) || "", (row && row.last_name) || ""));
    return '<span class="hst-user-avatar hst-user-avatar--placeholder" aria-label="بدون تصویر پروفایل؛ حروف اول نام ' + HST.escapeHtml(name) + '"><span class="hst-user-avatar__placeholder">' + HST.escapeHtml(initials) + '</span></span>';
  }

  function studentCellHtml(row) {
    return '<div class="hst-user-id hst-report-user-id">' +
      userAvatarHtml(row) +
      '<span class="hst-user-id__name">' +
        '<strong>' + faText(row.student_name) + '</strong>' +
        '<small>' + faText(row.national_code || row.student_login || '') + '</small>' +
      '</span>' +
    '</div>';
  }

  function actionIcon(type) {
    if (type === 'cash') {
      return '<span class="hst-btn-icon-svg" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 7c0-2 1.5-3.5 4-3.5S16 5 16 7l-1.5 2h-5L8 7Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9.5 9C6.5 11 5 14 5 17c0 2.2 1.8 3.5 7 3.5s7-1.3 7-3.5c0-3-1.5-6-4.5-8h-5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M10 15h4M12 13v4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>';
    }
    if (type === 'reset') {
      return '<span class="hst-btn-icon-svg" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M20 6v5h-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 18v-5h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M18.5 9a7 7 0 0 0-11.8-2.5L4 9M5.5 15a7 7 0 0 0 11.8 2.5L20 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
    }
    if (type === 'excel') {
      return '<span class="hst-btn-icon-svg" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 3H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7l-4-4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M14 3v4h4" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="m9 10 6 6M15 10l-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>';
    }
    return '<span class="hst-btn-icon-svg" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6 3h9l3 3v15H6z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M14 3v4h4" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 17v-3M12 17v-6M15 17v-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>';
  }

  function manualPaymentControlHtml(row) {
    if (row.status === "paid" && row.can_reset_cash) {
      return '<button type="button" class="hst-btn hst-btn--soft hst-btn--sm" data-invoice-cash-reset title="بازنشانی ثبت نقدی" aria-label="بازنشانی ثبت نقدی">' + actionIcon('reset') + '<span>بازنشانی ثبت نقدی</span></button>';
    }

    if (row.status !== "pending" && row.status !== "overdue") {
      return '';
    }

    return '<button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--cash" data-invoice-cash-pay title="ثبت نقدی" aria-label="ثبت نقدی">' + actionIcon('cash') + '<span>ثبت نقدی</span></button>';
  }


  function renderReportSummary(summary) {
    summary = summary || {};
    $reportModal.find("[data-hst-tuition-report-summary]").html(
      '<div class="hst-report-stats">' +
        '<div class="hst-report-stat hst-report-stat--total"><b>' + faText(summary.total || 0) + '</b><span>کل</span></div>' +
        '<div class="hst-report-stat hst-report-stat--new"><b>' + faText(summary.paid || 0) + '</b><span>پرداخت‌شده</span></div>' +
        '<div class="hst-report-stat hst-report-stat--warning"><b>' + faText(summary.unpaid || 0) + '</b><span>پرداخت‌نشده</span></div>' +
        '<div class="hst-report-stat hst-report-stat--upd"><b>' + faText(summary.cash || 0) + '</b><span>نقدی</span></div>' +
        '<div class="hst-report-stat hst-report-stat--photo"><b>' + faText(summary.online || 0) + '</b><span>آنلاین</span></div>' +
      '</div>'
    );
  }

  function statusClass(status) {
    switch (String(status || "")) {
      case "paid":
        return "hst-status--success";
      case "overdue":
        return "hst-status--warning";
      case "cancelled":
        return "hst-status--muted";
      case "pending":
      default:
        return "hst-status--warning";
    }
  }

  function renderInvoiceRows(rows) {
    invoiceRows = Array.isArray(rows) ? rows : [];
    syncReportExportActions();
    const $body = $reportModal.find("[data-hst-tuition-report-body]");

    if (!invoiceRows.length) {
      $body.html('<p class="hst-alert">صورتحسابی با این فیلتر پیدا نشد.</p>');
      return;
    }

    let html = '<div class="hst-table-wrap hst-data-table-wrap hst-report-table-wrap"><table class="hst-table hst-data-table hst-tuition-invoice-table" dir="rtl" data-hst-no-pagination="1">';
    html += '<thead><tr>' +
      '<th>ردیف</th>' +
      '<th class="hst-col-fill">دانش‌آموز</th>' +
      '<th>کلاس</th>' +
      '<th>مبلغ</th>' +
      '<th>وضعیت</th>' +
      '<th>روش پرداخت</th>' +
      '<th>تاریخ پرداخت</th>' +
      '<th>عملیات</th>' +
    '</tr></thead><tbody>';

    invoiceRows.forEach(function (row, index) {
      const paymentControl = manualPaymentControlHtml(row);
      html += '<tr data-invoice-id="' + faText(row.id) + '">' +
        '<td>' + faText(index + 1) + '</td>' +
        '<td class="hst-col-fill">' + studentCellHtml(row) + '</td>' +
        '<td>' + faText(row.class_name) + '</td>' +
        '<td>' + faText(row.amount_text) + '</td>' +
        '<td><span class="hst-status ' + statusClass(row.status) + '">' + faText(row.status_label) + '</span></td>' +
        '<td>' + faText(row.payment_method_label) + '</td>' +
        '<td>' + faText(row.paid_at) + '</td>' +
        '<td class="hst-actions"><div class="hst-btn-group">' +
          paymentControl +
          '<button type="button" class="hst-btn hst-btn--soft hst-btn--sm" data-row-invoice title="فاکتور PDF" aria-label="فاکتور PDF">' + actionIcon('invoice') + '<span>فاکتور PDF</span></button>' +
        '</div></td>' +
      '</tr>';
    });

    html += '</tbody></table></div>';
    $body.html(html);
  }


  function renderClassFilter(classOptions) {
    const $wrap = $reportModal.find("[data-hst-tuition-class-filter-wrap]");
    const $select = $reportModal.find("[data-hst-tuition-filter-class]");

    if (activePlanClassId > 0) {
      $wrap.prop("hidden", true);
      $select.val("");
      return;
    }

    const options = Array.isArray(classOptions) ? classOptions : [];
    currentClassOptions = options;
    if (!options.length) {
      $wrap.prop("hidden", true);
      $select.val("");
      return;
    }

    if (classOptionsRenderedForPlan !== activePlanId) {
      let html = '<option value="">همه کلاس‌ها</option>';
      options.forEach(function (item) {
        html += '<option value="' + faText(item.id) + '">' + faText(item.name) + '</option>';
      });
      $select.html(html).val("");
      classOptionsRenderedForPlan = activePlanId;
    }

    $wrap.prop("hidden", false);
  }

  async function loadInvoiceReport() {
    if (!activePlanId) return;

    const requestId = ++reportRequestId;
    const $body = $reportModal.find(".hst-modal__body");
    invoiceRows = [];
    syncReportExportActions();
    HST.modalLoading.show($body);

    const response = await HST.request({
      action: "hst_tuition_plan_invoices",
      data: {
        plan_id: activePlanId,
        status: $reportModal.find("[data-hst-tuition-filter-status]").val() || "",
        method: $reportModal.find("[data-hst-tuition-filter-method]").val() || "",
        class_id: $reportModal.find("[data-hst-tuition-filter-class]").val() || "",
        search: $reportModal.find("[data-hst-tuition-search]").val() || "",
      },
      showLoader: false,
    });

    if (requestId !== reportRequestId) return;
    HST.modalLoading.hide($body);

    if (!response || !response.success) {
      invoiceRows = [];
      syncReportExportActions();
      $reportModal.find("[data-hst-tuition-report-body]").html('<p class="hst-alert hst-alert--error">دریافت صورتحساب‌ها انجام نشد.</p>');
      return;
    }

    const data = response.data || {};
    renderClassFilter(data.class_options || []);
    renderReportSummary(data.summary || {});
    renderInvoiceRows(data.items || []);
  }

  function openReportModal() {
    closeExportScopePanel();
    $reportModal.addClass("is-open").attr("aria-hidden", "false");
    $("body").addClass("hst-modal-open");
  }

  function closeReportModal() {
    closeExportScopePanel();
    reportRequestId += 1;
    HST.modalLoading.hide($reportModal.find(".hst-modal__body"));
    $reportModal.removeClass("is-open").removeAttr("data-open").attr("aria-hidden", "true");
    $("body").removeClass("hst-modal-open");
  }

  $(document).on("click", ".hst-tuition-report", function () {
    activePlanId = Number($(this).data("id")) || 0;
    activePlanTitle = String($(this).data("title") || "شهریه");
    activePlanClassId = Number($(this).data("class-id")) || 0;
    classOptionsRenderedForPlan = 0;
    $reportModal.find("[data-hst-tuition-filter-status]").val("");
    $reportModal.find("[data-hst-tuition-filter-method]").val("");
    $reportModal.find("[data-hst-tuition-search]").val("");
    currentClassOptions = [];
    invoiceRows = [];
    syncReportExportActions();
    $reportModal.find("[data-hst-tuition-filter-class]").html('<option value="">همه کلاس‌ها</option>').val("");
    $reportModal.find("[data-hst-tuition-class-filter-wrap]").prop("hidden", activePlanClassId > 0);
    $reportModal.find("#hst-tuition-report-title").text("گزارش صورتحساب‌های " + activePlanTitle);
    openReportModal();
    loadInvoiceReport();
  });

  $(document).on("click", "[data-hst-tuition-report-close]", closeReportModal);
  $(document).on("keydown", function (event) {
    if (event.key === "Escape" && $reportModal.hasClass("is-open")) {
      closeReportModal();
    }
  });

  $(document).on("change", "[data-hst-tuition-filter-status], [data-hst-tuition-filter-method], [data-hst-tuition-filter-class]", loadInvoiceReport);
  $(document).on("input", "[data-hst-tuition-search]", function () {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(loadInvoiceReport, 280);
  });

  function rowByButton(button) {
    const invoiceId = Number($(button).closest("[data-invoice-id]").data("invoice-id")) || 0;
    return invoiceRows.find((row) => Number(row.id) === invoiceId);
  }

  $(document).on("click", "[data-invoice-cash-pay]", function () {
    const $row = $(this).closest("[data-invoice-id]");
    const invoiceId = Number($row.data("invoice-id")) || 0;

    if (!invoiceId) {
      notifyError("صورتحساب برای ثبت پرداخت نقدی پیدا نشد.");
      return;
    }

    HST.request({
      action: "hst_update_tuition_invoice_status",
      data: {
        invoice_id: invoiceId,
        status: "paid",
      },
      trigger: this,
      successMessage: true,
      onSuccess: loadInvoiceReport,
    });
  });

  $(document).on("click", "[data-invoice-cash-reset]", function () {
    const $row = $(this).closest("[data-invoice-id]");
    const invoiceId = Number($row.data("invoice-id")) || 0;

    if (!invoiceId) {
      notifyError("صورتحساب برای بازنشانی پرداخت نقدی پیدا نشد.");
      return;
    }

    HST.request({
      action: "hst_reset_tuition_cash_payment",
      data: { invoice_id: invoiceId },
      trigger: this,
      confirm: {
        title: "بازنشانی ثبت نقدی؟",
        text: "وضعیت این صورتحساب دوباره به پرداخت‌نشده برمی‌گردد.",
      },
      successMessage: true,
      onSuccess: loadInvoiceReport,
    });
  });

  function exportExcel(rows, filename) {
    rows = rows && rows.length ? rows : invoiceRows;
    if (!rows.length) {
      notifyError("داده‌ای برای خروجی وجود ندارد.");
      return;
    }
    showExportLoader("در حال ساخت خروجی Excel", "در حال آماده‌سازی فایل CSV...");

    const head = ["دانش‌آموز", "کلاس", "شهریه", "مبلغ", "وضعیت", "روش پرداخت", "تاریخ پرداخت", "کد ملی", "موبایل ولی"];
    const body = rows.map(function (row) {
      return [
        row.student_name || "",
        row.class_name || "",
        row.plan_title || activePlanTitle,
        row.amount_text || "",
        row.status_label || "",
        row.payment_method_label || "",
        row.paid_at || "",
        row.national_code || "",
        row.parent_phone || "",
      ];
    });

    const csv = [head].concat(body).map(function (line) {
      return line.map(function (cell) {
        return '"' + String(cell == null ? "" : cell).replace(/"/g, '""') + '"';
      }).join(",");
    }).join("\n");

    const blob = new Blob(["\ufeff" + csv], { type: "text/csv;charset=utf-8;" });
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = (filename || "tuition-report") + ".csv";
    document.body.appendChild(a);
    a.click();
    window.setTimeout(function () {
      URL.revokeObjectURL(a.href);
      a.remove();
      hideExportLoader();
    }, 1000);
  }

  function pdfRows(rows, mode) {
    rows = rows && rows.length ? rows : invoiceRows;
    if (!rows.length) {
      notifyError("داده‌ای برای PDF وجود ندارد.");
      return;
    }
    showExportLoader("در حال ساخت فاکتورهای شهریه", "در حال آماده‌سازی فاکتورها...");

    if (window.HSTPrint && typeof HSTPrint.tuitionPdf === "function") {
      HSTPrint.tuitionPdf({
        title: "فاکتور شهریه",
        subtitle: activePlanTitle,
        rows: rows,
        mode: "invoice",
        filename: "فاکتور-شهریه-" + activePlanId + ".pdf",
        onProgress: updateExportProgress,
        onDone: hideExportLoader,
      });
      return;
    }

    if (window.HSTPrint && typeof HSTPrint.tablePdf === "function") {
      HSTPrint.tablePdf({
        title: "فاکتور شهریه",
        subtitle: activePlanTitle,
        head: ["دانش‌آموز", "کلاس", "مبلغ", "وضعیت", "روش", "تاریخ"],
        rows: rows.map(function (row) {
          return [
            row.student_name || "",
            row.class_name || "",
            amountForPdfList(row.amount_text),
            row.status_label || "",
            row.payment_method_label || "",
            row.paid_at || (mode === "invoice" ? "پرداخت نشده" : ""),
          ];
        }),
        filename: "invoices-" + activePlanId + ".pdf",
        orientation: "landscape",
        onProgress: updateExportProgress,
        onDone: hideExportLoader,
      });
      return;
    }

    hideExportLoader();
    exportExcel(rows, "invoices");
  }

  function printInvoiceDocument(row, mode) {
    if (!row) {
      notifyError("صورتحساب انتخاب‌شده پیدا نشد.");
      return;
    }
    showExportLoader("در حال ساخت فاکتور شهریه", "در حال آماده‌سازی فاکتور...");

    if (window.HSTPrint && typeof HSTPrint.tuitionPdf === "function") {
      HSTPrint.tuitionPdf({
        title: "فاکتور شهریه",
        subtitle: row.plan_title || activePlanTitle,
        rows: [row],
        mode: "invoice",
        filename: "فاکتور-شهریه-" + row.id + ".pdf",
        onProgress: updateExportProgress,
        onDone: hideExportLoader,
      });
      return;
    }

    hideExportLoader();
    pdfRows([row], "invoice");
  }

  function rowMatchesClassIds(row, ids) {
    if (!ids.length) return true;
    const rowIds = Array.isArray(row.class_ids) ? row.class_ids.map(function (id) { return Number(id); }) : [];
    return ids.some(function (id) { return rowIds.indexOf(Number(id)) !== -1; });
  }

  function selectedRowsByClass(rows, ids) {
    return (rows || []).filter(function (row) {
      return rowMatchesClassIds(row, ids);
    });
  }

  function closeExportScopePanel() {
  }

  function bulkClassScopeHtml() {
    let html = '<div class="hst-stack">';
    html += '<label class="hst-field"><span><input type="radio" name="tuition_export_scope" value="all" checked> همه کلاس‌ها با هم</span></label>';
    html += '<label class="hst-field"><span><input type="radio" name="tuition_export_scope" value="selected"> کلاس‌های انتخابی</span></label>';
    html += '<div class="hst-stack" data-hst-export-class-list hidden>';
    currentClassOptions.forEach(function (item) {
      html += '<label class="hst-field"><span><input type="checkbox" name="tuition_export_class" value="' + faText(item.id) + '"> ' + faText(item.name) + '</span></label>';
    });
    html += '</div></div>';
    return html;
  }


  function chooseRowsForBulkExport(rows, callback) {
    rows = Array.isArray(rows) ? rows : [];
    const selectedFilterClass = Number($reportModal.find("[data-hst-tuition-filter-class]").val()) || 0;

    if (activePlanClassId > 0 || selectedFilterClass > 0 || currentClassOptions.length <= 1) {
      callback(sortRowsForExport(rows));
      return;
    }

    HSTModal.open({
      title: "انتخاب کلاس برای خروجی",
      text: "این شهریه برای چند کلاس ثبت شده است. خروجی را برای همه کلاس‌ها می‌خواهید یا کلاس‌های مشخص؟",
      html: bulkClassScopeHtml(),
      confirmText: "دریافت خروجی",
      cancelText: "بستن",
    }).then(function (result) {
      if (!result || result.isConfirmed !== true) return;

      const $box = $("[data-hst-dialog-content]");
      const scope = $box.find('input[name="tuition_export_scope"]:checked').val() || "all";

      if (scope === "all") {
        callback(sortRowsForExport(rows));
        return;
      }

      const ids = $box.find('input[name="tuition_export_class"]:checked').map(function () {
        return Number(this.value) || 0;
      }).get().filter(Boolean);

      if (!ids.length) {
        notifyError("حداقل یک کلاس را انتخاب کنید.");
        return;
      }

      const scopedRows = selectedRowsByClass(rows, ids);
      if (!scopedRows.length) {
        notifyError("برای کلاس‌های انتخاب‌شده داده‌ای پیدا نشد.");
        return;
      }

      callback(sortRowsForExport(scopedRows));
    });
  }


  function amountForPdfList(value) {
    const raw = String(value || "").replace(/\s*تومان\s*/g, "").trim();
    return raw ? ("تومان " + raw) : "";
  }


  function exportInvoiceListPdf(rows, title, filename) {
    rows = Array.isArray(rows) ? rows : [];
    if (!rows.length) {
      notifyError("داده‌ای برای خروجی PDF وجود ندارد.");
      return;
    }

    if (!(window.HSTPrint && typeof HSTPrint.tablePdf === "function")) {
      notifyError("امکان ساخت PDF در این صفحه بارگذاری نشده است.");
      return;
    }
    showExportLoader("در حال ساخت لیست شهریه", "در حال آماده‌سازی لیست PDF...");

    rows = sortRowsForExport(rows);

    HSTPrint.tablePdf({
      title: title,
      subtitle: activePlanTitle,
      head: ["ردیف", "دانش‌آموز", "کلاس", "مبلغ", "وضعیت", "روش پرداخت", "تاریخ پرداخت", "سررسید"],
      rows: rows.map(function (row, index) {
        return [
          index + 1,
          row.student_name || "",
          row.class_name || "",
          amountForPdfList(row.amount_text),
          row.status_label || "",
          row.payment_method_label || "",
          row.paid_at || "",
          row.due_date || "",
        ];
      }),
      filename: filename,
      orientation: "landscape",
      onProgress: updateExportProgress,
      onDone: hideExportLoader,
    });
  }



  $(document).on("change", '[data-hst-dialog-content] input[name="tuition_export_scope"]', function () {
    const isSelected = $(this).val() === "selected";
    $("[data-hst-dialog-content] [data-hst-export-class-list]").prop("hidden", !isSelected);
  });

  $(document).on("click", "[data-hst-tuition-export-excel]", function () {
    chooseRowsForBulkExport(invoiceRows, function (rows) {
      exportExcel(rows, "tuition-report-" + activePlanId);
    });
  });


  $(document).on("click", "[data-hst-tuition-unpaid-list-pdf]", function () {
    chooseRowsForBulkExport(invoiceRows.filter(function (row) { return row.status !== "paid"; }), function (rows) {
      exportInvoiceListPdf(rows, "لیست پرداخت‌نشده‌های شهریه", "لیست-پرداخت-نشده-شهریه-" + activePlanId + ".pdf");
    });
  });

  $(document).on("click", "[data-hst-tuition-paid-list-pdf]", function () {
    chooseRowsForBulkExport(invoiceRows.filter(function (row) { return row.status === "paid"; }), function (rows) {
      exportInvoiceListPdf(rows, "لیست پرداخت‌شده‌های شهریه", "لیست-پرداخت-شده-شهریه-" + activePlanId + ".pdf");
    });
  });

  $(document).on("click", "[data-hst-tuition-invoices-pdf]", function () {
    chooseRowsForBulkExport(invoiceRows, function (rows) {
      pdfRows(rows, "invoice");
    });
  });


  $(document).on("click", "[data-row-invoice]", function () {
    printInvoiceDocument(rowByButton(this), "invoice");
  });


  // ---- Custom payment modal: pick an enabled WooCommerce gateway, then pay
  // directly through it (no WooCommerce checkout page). ---------------------
  const $payModal = $("[data-hst-pay-modal]");
  let payInvoiceId = 0;
  let paySelectedGateway = "";

  function closePayModal() {
    $payModal.removeClass("is-open").attr("aria-hidden", "true");
    $("body").removeClass("hst-modal-open");
    paySelectedGateway = "";
    $payModal.find("[data-hst-pay-confirm]").prop("disabled", true);
  }

  function renderGateways(gateways) {
    const $box = $payModal.find("[data-hst-pay-gateways]");
    if (!gateways || !gateways.length) {
      $box.html('<p class="hst-empty-note">هیچ روش پرداخت فعالی یافت نشد.</p>');
      return;
    }
    let html = "";
    gateways.forEach((g, i) => {
      const id = "hst-gw-" + i;
      html +=
        '<label class="hst-pay-gateway" for="' + id + '">' +
        '<input type="radio" name="hst-pay-gateway" id="' + id + '" value="' + HST.escapeHtml(g.id) + '">' +
        '<span class="hst-pay-gateway__body">' +
        '<span class="hst-pay-gateway__title">' + HST.escapeHtml(g.title) + "</span>" +
        (g.description ? '<span class="hst-pay-gateway__desc">' + HST.escapeHtml(g.description) + "</span>" : "") +
        "</span></label>";
    });
    $box.html(html);
  }

  $(document).on("click", ".hst-pay-tuition", async function () {
    payInvoiceId = Number($(this).data("id")) || 0;
    if (!payInvoiceId) return;

    // Show the modal with a loading state, then fetch enabled gateways.
    const $article = $(this).closest(".hst-invoice");
    const amountText = $article.find(".hst-invoice__meta span").filter(function () {
      return $(this).text().indexOf("مبلغ") !== -1;
    }).text();
    $payModal.find("[data-hst-pay-amount]").text(amountText || "");
    $payModal.find("[data-hst-pay-gateways]").html('<p class="hst-empty-note">' + HST.loadingMarkup() + '</p>');
    $payModal.find("[data-hst-pay-confirm]").prop("disabled", true).text("پرداخت");
    $payModal.addClass("is-open").attr("aria-hidden", "false");
    $("body").addClass("hst-modal-open");

    const response = await HST.request({
      action: "hst_tuition_gateways",
      data: {},
      showLoader: false,
      successMessage: false,
    });

    if (response?.success && response.data?.gateways) {
      renderGateways(response.data.gateways);
    } else {
      $payModal.find("[data-hst-pay-gateways]").html(
        '<p class="hst-alert hst-alert--danger">' + HST.escapeHtml(HST.getMessage(response, "روش پرداختی یافت نشد.")) + "</p>"
      );
    }
  });

  $(document).on("change", 'input[name="hst-pay-gateway"]', function () {
    paySelectedGateway = $(this).val();
    $payModal.find("[data-hst-pay-confirm]").prop("disabled", !paySelectedGateway);
  });

  $(document).on("click", "[data-hst-pay-close]", closePayModal);

  // Click on the backdrop (outside the panel) closes the modal.
  $payModal.on("click", function (e) {
    if (e.target === this) closePayModal();
  });

  $(document).on("click", "[data-hst-pay-confirm]", async function () {
    if (!payInvoiceId || !paySelectedGateway) return;
    const $btn = $(this);
    $btn.prop("disabled", true).text("در حال انتقال...");

    const response = await HST.request({
      action: "hst_create_tuition_order",
      data: { invoice_id: payInvoiceId, gateway: paySelectedGateway },
      successMessage: false,
    });

    if (response?.success && response.data?.redirect) {
      window.location.href = response.data.redirect;
    } else {
      $btn.prop("disabled", false).text("پرداخت");
      HST.toast(HST.getMessage(response, "پرداخت انجام نشد."), "error");
    }
  });
});
