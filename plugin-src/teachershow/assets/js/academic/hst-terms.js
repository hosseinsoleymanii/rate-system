jQuery(function ($) {
  "use strict";

  const selectors = {
    addButton: "#hst-terms-add",
    deleteButton: ".hst-delete",
    editButton: ".hst-edit",
    status: ".hst-term-status",
  };

  $(document).on("click", selectors.addButton, async function () {
    const result = await HSTModal.open({
      title: "افزودن سال تحصیلی",
      text: "عنوان سال تحصیلی جدید را وارد کنید.",
      html: `<input type="text" name="term_name" placeholder="مثلاً ۱۴۰۵ - ۱۴۰۶" autocomplete="off">`,
      confirmText: "افزودن",
    });

    if (!result.isConfirmed) return;

    const termName = $.trim(result.data.term_name);

    if (!termName) {
      HST.toast("نام سال تحصیلی الزامی است", "error");
      return;
    }
    if (termName.length > 80) {
      HST.toast("نام سال تحصیلی نباید بیشتر از ۸۰ کاراکتر باشد", "error");
      return;
    }

    HST.request({
      action: "hst_add_term",
      data: { term_name: termName },
      successMessage: true,
      reload: true,
    });
  });

  $(document).on("click", selectors.deleteButton, async function () {
    if ($(this).is(":disabled")) return;
    const id = $(this).data("id");
    const $row = $(this).closest("tr");

    HST.request({
      action: "hst_delete_term",
      data: { id },
      confirm: {
        title: "حذف سال تحصیلی؟",
        text: "این عملیات قابل بازگشت نیست.",
      },
      successMessage: true,
      onSuccess: function () {
        HST.removeRowOrReload($row);
      },
    });
  });

  $(document).on("click", selectors.editButton, async function () {
    const $button = $(this);
    const $row = $button.closest("tr");
    const id = $button.data("id");
    const currentName = $button.data("name") || "";

    const result = await HSTModal.open({
      title: "ویرایش سال تحصیلی",
      text: "نام سال تحصیلی را ویرایش کنید.",
      html: `<input type="text" name="term_name" value="${HST.escapeHtml(currentName)}" autocomplete="off">`,
    });

    if (!result.isConfirmed) return;

    const termName = $.trim(result.data.term_name);

    if (!termName) {
      HST.toast("نام سال تحصیلی الزامی است", "error");
      return;
    }

    if (termName.length > 80) {
      HST.toast("نام سال تحصیلی نباید بیشتر از ۸۰ کاراکتر باشد", "error");
      return;
    }

    HST.request({
      action: "hst_update_term",
      data: { id, term_name: termName },
      successMessage: true,
      onSuccess: function () {
        $row.find("td:nth-child(2)").text(termName);
        $button.data("name", termName);
      },
    });
  });

  function updateTermRowStatus($checkbox, isActive) {
    const $row = $checkbox.closest("tr");
    $row.attr("data-hst-status", isActive ? "active" : "inactive");
  }

  $(document).on("change", selectors.status, async function () {
    const $checkbox = $(this);
    const id = Number($checkbox.data("id"));
    const isActive = $checkbox.is(":checked") ? 1 : 0;
    const previousState = !isActive;

    $checkbox.prop("disabled", true);

    const response = await HST.request({
      action: "hst_toggle_term_status",
      data: { id, is_active: isActive },
      successMessage: true,
      errorMessage: "تغییر وضعیت انجام نشد",
      onSuccess: function (res) {
        const activeId = Number(res?.data?.active_id || 0);

        // Sync every switch to the server's authoritative active term. This
        // also handles the case where deactivating the last active term made
        // the server auto-activate the most recently created term instead.
        $(selectors.status).each(function () {
          const $item = $(this);
          const itemId = Number($item.data("id"));
          const shouldBeActive = itemId === activeId;
          $item.prop("checked", shouldBeActive);
          updateTermRowStatus($item, shouldBeActive);
        });
      },
    });

    if (!response?.success) {
      $checkbox.prop("checked", previousState);
      updateTermRowStatus($checkbox, previousState);
    }

    $checkbox.prop("disabled", false);
  });
});