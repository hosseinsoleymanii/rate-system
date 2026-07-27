jQuery(function ($) {
  "use strict";

  const $page = $("[data-hst-periods]");
  if (!$page.length) return;

  const $modal = $("#hst-period-modal");
  const $form = $("#hst-period-form");
  const $customScoreCount = $form.find("[data-hst-custom-score-count]");

  function syncScoreCountField() {
    const type = String($form.find('[name="period_type"]').val() || "");
    const isCustom = type === "custom";
    const hints = {
      weekly: "برای دوره هفتگی یک نمره ثبت می‌شود.",
      monthly: "برای دوره ماهانه یک نمره ثبت می‌شود.",
      first_shift: "دو نمره مستمر اول و پایانی اول ثبت می‌شود.",
      second_shift: "نمرات مستمر و پایانی اول فقط نمایش داده می‌شوند؛ مستمر و پایانی دوم قابل ثبت هستند.",
      custom: "تعداد نمره‌های موردنیاز را مشخص کنید.",
    };

    $customScoreCount.prop("hidden", !isCustom);
    $customScoreCount.find('[name="score_count"]').prop("required", isCustom);
    $form.find("[data-hst-period-score-hint]").text(hints[type] || "");
  }

  function openModal(values = {}) {
    $form[0]?.reset();
    $form.find('[name="id"]').val(values.id || "");
    $form.find('[name="period_name"]').val(values.period_name || "");
    const firstPeriodType = $form.find('[name="period_type"] option:first').val() || "";
    $form.find('[name="period_type"]').val(values.period_type || firstPeriodType);
    $form.find('[name="score_count"]').val(values.score_count || 1);
    syncScoreCountField();
    $form.find('[name="start_date"]').val(values.start_date || "");
    $form.find('[name="end_date"]').val(values.end_date || "");
    $form.find('[name="deadline_date"]').val(values.deadline_date || "");
    $form.find('[name="description"]').val(values.description || "");

    $("#hst-period-modal-title").text(values.id ? "ویرایش دوره" : "افزودن دوره جدید");
    $modal.addClass("is-active").attr("aria-hidden", "false");

    if (window.HSTJalaliDatepicker && typeof HSTJalaliDatepicker.init === "function") {
      HSTJalaliDatepicker.init($modal[0]);
    }

    window.setTimeout(function () {
      $form.find("input, select, textarea").filter(":visible").first().trigger("focus");
    }, 80);
  }

  function closeModal() {
    $modal.removeClass("is-active").attr("aria-hidden", "true");
  }

  $("#hst-period-add").on("click", function () {
    openModal();
  });

  $(document).on("click", "[data-hst-period-close]", closeModal);

  $(document).on("click", ".hst-period-edit", function () {
    const $row = $(this).closest("tr");
    openModal({
      id: $row.data("id"),
      period_name: $row.data("period-name"),
      period_type: $row.data("period-type"),
      score_count: $row.data("score-count"),
      start_date: $row.data("start-date"),
      end_date: $row.data("end-date"),
      deadline_date: $row.data("deadline-date"),
      description: $row.data("description"),
    });
  });


  function openViewModal($row) {
    const $modal = $("#hst-period-view-modal");
    if (!$modal.length || !$row.length) return;

    const setValue = function (field, value) {
      $modal.find(`[data-hst-period-view-field="${field}"]`).text(value || "—");
    };

    setValue("name", $row.data("period-name"));
    setValue("type", $row.find("td").eq(2).text().trim());
    setValue("start", $row.data("start-date"));
    setValue("end", $row.data("end-date"));
    setValue("deadline", $row.data("deadline-date"));
    setValue("status", $row.find(".hst-period-toggle").is(":checked") ? "فعال" : "غیرفعال");
    setValue("score_count", String($row.data("score-count") || 1));
    setValue("description", $row.data("description"));

    $modal.addClass("is-active").attr("aria-hidden", "false");
  }

  function closeViewModal() {
    $("#hst-period-view-modal").removeClass("is-active").attr("aria-hidden", "true");
  }

  $(document).on("click", ".hst-period-view", function () {
    openViewModal($(this).closest("tr"));
  });

  $(document).on("click", "[data-hst-period-view-close]", closeViewModal);


  $form.on("change", '[name="period_type"]', syncScoreCountField);

  $form.on("submit", function (e) {
    e.preventDefault();

    if ($form.find('[name="period_type"]').val() === "custom") {
      const count = Number($form.find('[name="score_count"]').val());
      if (!Number.isInteger(count) || count < 1 || count > 20) {
        HST.toast("تعداد نمره‌های دوره اختصاصی باید بین ۱ تا ۲۰ باشد.", "error");
        $form.find('[name="score_count"]').trigger("focus");
        return;
      }
    }

    const data = {};
    $(this).serializeArray().forEach(function (item) {
      data[item.name] = item.value;
    });

    HST.request({
      action: "hst_save_score_period",
      data,
      successMessage: true,
      reload: true,
      showLoader: true,
    });
  });

  $(document).on("click", ".hst-period-delete", function () {
    if ($(this).is(":disabled")) return;
    const id = $(this).closest("tr").data("id");

    HST.request({
      action: "hst_delete_score_period",
      data: { id },
      confirm: {
        title: "حذف دوره؟",
        text: "این عملیات قابل بازگشت نیست.",
      },
      successMessage: true,
      reload: true,
      showLoader: true,
    });
  });

  $(document).on("change", ".hst-period-toggle", async function () {
    const $input = $(this);
    const $row = $input.closest("tr");
    const isActive = $input.is(":checked") ? 1 : 0;
    const previous = !isActive;

    $input.prop("disabled", true);

    const res = await HST.request({
      action: "hst_toggle_score_period",
      data: {
        id: $row.data("id"),
        is_active: isActive,
      },
      successMessage: true,
      reload: false,
      showLoader: true,
    });

    if (!res?.success) {
      $input.prop("checked", previous);
    }

    const checked = $input.is(":checked");
    $row.attr("data-hst-status", checked ? "active" : "inactive");

    $input.prop("disabled", false);

    if (window.HST && typeof HST.refreshTables === "function") {
      HST.refreshTables();
    }
  });
});
