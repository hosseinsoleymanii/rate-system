jQuery(function ($) {
  "use strict";

  const selectors = {
    addButton: "#hst-lessons-add",
    defaultsButton: "#hst-lessons-add-high-theory",
    deleteButton: ".hst-delete",
    editButton: ".hst-edit",
  };

  $(document).on("click", selectors.defaultsButton, function () {
    HST.request({
      action: "hst_import_lessons_by_file",
      data: { file: "high_theory" },
      confirm: {
        title: "افزودن دروس متوسطه دوم؟",
        text: "کلاس‌های لازم و دروس دهم، یازدهم و دوازدهم اضافه می‌شوند و موارد موجود تکرار نخواهند شد.",
        confirmText: "افزودن دروس",
      },
      trigger: this,
      successMessage: true,
      reload: true,
    });
  });

  function classOptions(selectedId) {
    let classes = [];
    try {
      classes = HST.sortClassItems(JSON.parse($(selectors.addButton).attr("data-hst-classes") || "[]"), "name");
    } catch (e) {
      classes = [];
    }
    let opts = '<option value="">انتخاب کلاس</option>';
    classes.forEach(function (c) {
      const sel = String(c.id) === String(selectedId) ? " selected" : "";
      opts += '<option value="' + c.id + '"' + sel + ">" + HST.escapeHtml(c.name) + "</option>";
    });
    return opts;
  }

  function validate(data) {
    if (!data.lesson_name || !data.class_id || data.unit < 1) {
      HST.toast("نام درس، کلاس و تعداد واحد الزامی است", "error");
      return false;
    }
    if (data.lesson_name.length > 100) {
      HST.toast("نام درس نباید بیشتر از ۱۰۰ کاراکتر باشد", "error");
      return false;
    }
    if (data.unit > 10) {
      HST.toast("تعداد واحد باید بین ۱ تا ۱۰ باشد", "error");
      return false;
    }
    return true;
  }

  // ---- Add lesson (modal) ------------------------------------------------
  $(document).on("click", selectors.addButton, async function () {
    const result = await HSTModal.open({
      title: "افزودن درس",
      text: "اطلاعات درس جدید را وارد کنید.",
      html: `
        <select name="class_id">${classOptions("")}</select>
        <input type="text" name="lesson_name" placeholder="عنوان درس" autocomplete="off">
        <input type="number" name="unit" min="1" value="1" placeholder="تعداد واحد">
      `,
      confirmText: "افزودن",
    });

    if (!result.isConfirmed) return;

    const data = {
      class_id: result.data.class_id,
      lesson_name: $.trim(result.data.lesson_name),
      unit: HST.toInt(result.data.unit, 0),
    };

    if (!validate(data)) return;

    HST.request({
      action: "hst_add_lesson",
      data,
      successMessage: true,
      reload: true,
    });
  });

  // ---- Delete lesson -----------------------------------------------------
  $(document).on("click", selectors.deleteButton, function () {
    if ($(this).is(":disabled")) return;
    const id = $(this).data("id");
    const $row = $(this).closest("tr");

    HST.request({
      action: "hst_delete_lesson",
      data: { id },
      confirm: {
        title: "حذف درس؟",
        text: "این عملیات قابل بازگشت نیست.",
        html: "<p>آیا مطمئن هستید؟</p>",
      },
      successMessage: true,
      onSuccess: function () {
        HST.removeRowOrReload($row);
      },
    });
  });

  // ---- Edit lesson (modal) ----------------------------------------------
  $(document).on("click", selectors.editButton, async function () {
    const $button = $(this);
    const $row = $button.closest("tr");
    const id = $button.data("id");
    const currentName = $button.data("name") || "";
    const currentUnit = HST.toInt($button.data("unit"), 1);

    const result = await HSTModal.open({
      title: "ویرایش درس",
      text: "اطلاعات درس را ویرایش کنید.",
      html: `
        <input type="text" name="lesson_name" value="${HST.escapeHtml(currentName)}" autocomplete="off">
        <input type="number" name="unit" min="1" value="${currentUnit}" placeholder="واحد">
      `,
    });

    if (!result.isConfirmed) return;

    const data = {
      id,
      lesson_name: $.trim(result.data.lesson_name),
      unit: HST.toInt(result.data.unit, 0),
    };

    if (!data.lesson_name || data.unit < 1) {
      HST.toast("نام درس و تعداد واحد الزامی است", "error");
      return;
    }
    if (data.lesson_name.length > 100 || data.unit > 10) {
      HST.toast("نام درس حداکثر ۱۰۰ کاراکتر و واحد باید بین ۱ تا ۱۰ باشد", "error");
      return;
    }

    HST.request({
      action: "hst_update_lesson",
      data,
      successMessage: true,
      onSuccess: function () {
        $row.find("td:nth-child(3)").text(data.lesson_name);
        $row.find("td:nth-child(4)").text(data.unit);
        $button.data("name", data.lesson_name).data("unit", data.unit);
      },
    });
  });
});
