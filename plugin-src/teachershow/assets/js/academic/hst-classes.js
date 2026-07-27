jQuery(function ($) {
  "use strict";

  const selectors = {
    addButton: "#hst-classes-add",
    defaultsButton: "#hst-classes-add-high-theory",
    deleteButton: ".hst-delete",
    editButton: ".hst-edit",
  };

  $(document).on("click", selectors.defaultsButton, function () {
    HST.request({
      action: "hst_import_classes_by_file",
      data: { file: "high_theory" },
      confirm: {
        title: "افزودن کلاس‌های متوسطه دوم؟",
        text: "کلاس‌های دهم، یازدهم و دوازدهم اضافه می‌شوند و موارد موجود تکرار نخواهند شد.",
        confirmText: "افزودن کلاس‌ها",
      },
      trigger: this,
      successMessage: true,
      reload: true,
    });
  });

  // ---- Add class (modal) -------------------------------------------------
  $(document).on("click", selectors.addButton, async function () {
    const result = await HSTModal.open({
      title: "افزودن کلاس",
      text: "نام کلاس جدید را وارد کنید.",
      html: `<input type="text" name="class_name" placeholder="مثلاً دهم انسانی" autocomplete="off">`,
      confirmText: "افزودن",
    });

    if (!result.isConfirmed) return;

    const className = $.trim(result.data.class_name);

    if (!className) {
      HST.toast("نام کلاس الزامی است", "error");
      return;
    }
    if (className.length > 80) {
      HST.toast("نام کلاس نباید بیشتر از ۸۰ کاراکتر باشد", "error");
      return;
    }

    HST.request({
      action: "hst_add_class",
      data: { class_name: className },
      successMessage: true,
      reload: true,
    });
  });

  // ---- Delete class ------------------------------------------------------
  $(document).on("click", selectors.deleteButton, function () {
    if ($(this).is(":disabled")) return;
    const id = $(this).data("id");
    const $row = $(this).closest("tr");

    HST.request({
      action: "hst_delete_class",
      data: { id },
      confirm: {
        title: "حذف کلاس؟",
        text: "این عملیات قابل بازگشت نیست.",
        html: "<p>آیا مطمئن هستید؟</p>",
      },
      successMessage: true,
      onSuccess: function () {
        HST.removeRowOrReload($row);
      },
    });
  });

  // ---- Edit class (modal) ------------------------------------------------
  $(document).on("click", selectors.editButton, async function () {
    const $button = $(this);
    const $row = $button.closest("tr");
    const id = $button.data("id");
    const currentName = $button.data("name") || "";

    const result = await HSTModal.open({
      title: "ویرایش کلاس",
      text: "نام جدید کلاس را وارد کنید.",
      html: `<input type="text" name="class_name" value="${HST.escapeHtml(currentName)}" autocomplete="off">`,
    });

    if (!result.isConfirmed) return;

    const className = $.trim(result.data.class_name);

    if (!className) {
      HST.toast("نام کلاس الزامی است", "error");
      return;
    }
    if (className.length > 80) {
      HST.toast("نام کلاس نباید بیشتر از ۸۰ کاراکتر باشد", "error");
      return;
    }

    HST.request({
      action: "hst_update_class",
      data: { id, class_name: className },
      successMessage: true,
      onSuccess: function () {
        $row.find("td:nth-child(2)").text(className);
        $button.data("name", className);
      },
    });
  });
});
