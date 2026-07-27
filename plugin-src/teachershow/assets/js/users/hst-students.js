jQuery(function ($) {
  "use strict";

  const $lessonContainer = $("#lesson-checkboxes");
  const $lessonSection = $("#student-lessons-section");
  const $modal = $("#hst-student-modal");
  const $viewModal = $("#hst-student-view-modal");
  const $viewBody = $("#hst-student-view-body");

  let editingId = 0;

  // ---- Lessons for the selected class -----------------------------------
  async function loadLessons(selectedLessons = []) {
    const classIds = HST.getCheckedValues(".class-item");

    if (!classIds.length) {
      $lessonContainer.empty();
      $lessonSection.prop("hidden", true).hide();
      return;
    }

    $lessonSection.prop("hidden", false).show();
    $lessonContainer.html(HST.loadingMarkup()).attr("aria-busy", "true");

    const response = await HST.request({
      action: "hst_get_lessons_by_classes",
      data: { class_ids: classIds, role: "student" },
      showLoader: false,
    });

    $lessonContainer.removeAttr("aria-busy");
    if (!response || !response.success) {
      $lessonContainer.html('<p class="hst-alert hst-alert--error">دریافت درس‌ها انجام نشد.</p>');
      return;
    }

    const lessons = response.data || [];
    let html = "";

    if (lessons.length) {
      html += `
        <label>
          <input type="checkbox" id="select-all-lessons">
          <p>همه درس‌ها</p>
        </label>
      `;

      lessons.forEach(function (lesson) {
        const lessonId = String(lesson.id);
        const checked = selectedLessons.map(String).includes(lessonId) ? "checked" : "";

        html += `
          <label>
            <input type="checkbox" name="lesson_ids[]" value="${HST.escapeHtml(lessonId)}" class="lesson-item" ${checked}>
            <p>${HST.escapeHtml(lesson.lesson_name)}</p>
          </label>
        `;
      });
    } else {
      html = "<label><p>درسی یافت نشد</p></label>";
    }

    $lessonContainer.html(html);
    $lessonSection.prop("hidden", false).show();
    HST.syncSelectAll(".lesson-item", "#select-all-lessons");
  }

  $(document).on("change", ".class-item", function () {
    HST.setSingleChecked(this, ".class-item");
    loadLessons();
  });

  $(document).on("change", "#select-all-lessons", function () {
    $(".lesson-item").prop("checked", $(this).is(":checked"));
  });

  $(document).on("change", ".lesson-item", function () {
    HST.syncSelectAll(".lesson-item", "#select-all-lessons");
  });

  // ---- Add / edit modal open / close ------------------------------------
  function openStudentModal() {
    $modal.addClass("is-active").attr("aria-hidden", "false");
    $("body").addClass("hst-modal-open");
  }
  function closeStudentModal() {
    HST.modalLoading.hide($modal.find(".hst-modal__body"));
    $modal.removeClass("is-active").attr("aria-hidden", "true");
    $("body").removeClass("hst-modal-open");
  }

  function setModalMode(mode) {
    const $title = $("#hst-student-modal-title");
    const $submit = $("#define-student-form button[type='submit'], button[form='define-student-form'][type='submit']");
    if (mode === "edit") {
      $title.text("ویرایش دانش‌آموز");
      $submit.text("ذخیره تغییرات");
    } else {
      $title.text("افزودن دانش‌آموز");
      $submit.text("افزودن دانش‌آموز");
    }
  }

  function resetStudentForm() {
    const form = document.getElementById("define-student-form");
    if (form) form.reset();
    $(".class-item, #select-all-lessons").prop("checked", false);
    $lessonContainer.empty();
    $lessonSection.prop("hidden", true).hide();
    // Mobile is stored separately from the national-code username.
    $('#define-student-form [name="student_phone"]').prop("disabled", false);
  }

  $(document).on("click", "#hst-student-add", function () {
    editingId = 0;
    setModalMode("add");
    resetStudentForm();
    openStudentModal();
  });

  $(document).on("click", "[data-hst-modal-close]", function () {
    if ($(this).closest("#hst-student-modal").length || $(this).is("[data-hst-modal-close]")) {
      closeStudentModal();
    }
  });

  $(document).on("keydown", function (e) {
    if (e.key === "Escape" && $modal.hasClass("is-active")) closeStudentModal();
  });

  // ---- Edit student: reuse the add modal, prefilled ---------------------
  function waitForLessons(timeout) {
    return new Promise(function (resolve) {
      const started = Date.now();
      (function check() {
        if ($(".lesson-item").length || Date.now() - started > (timeout || 4000)) {
          resolve();
        } else {
          setTimeout(check, 80);
        }
      })();
    });
  }

  async function prefillStudentModal(d) {
    resetStudentForm();

    $('[name="student_name"]').val(d.first_name || "");
    $('[name="student_last_name"]').val(d.last_name || "");
    $('[name="student_phone"]').val(d.phone || "").prop("disabled", false);
    $('[name="student_national_code"]').val(d.national_code || "");
    $('[name="student_father_name"]').val(d.father_name || "");
    $('[name="student_father_phone"]').val(d.father_phone || "");
    $('[name="student_mother_phone"]').val(d.mother_phone || "");
    $('[name="student_birthdate"]').val(d.birthdate || "");

    (d.class_ids || []).forEach(function (cid) {
      $('.class-item[value="' + cid + '"]').prop("checked", true);
    });

    if ((d.class_ids || []).length) {
      await loadLessons(d.lesson_ids || []);
      await waitForLessons();
      (d.lesson_ids || []).forEach(function (lid) {
        $('.lesson-item[value="' + lid + '"]').prop("checked", true);
      });
      HST.syncSelectAll(".lesson-item", "#select-all-lessons");
    }
  }

  $(document).on("click", ".hst-student-edit", async function () {
    const id = $(this).data("id");
    editingId = id;
    setModalMode("edit");
    openStudentModal();
    HST.modalLoading.show($modal.find(".hst-modal__body"));

    const res = await HST.request({
      action: "hst_get_student_details",
      data: { student_id: id },
      showLoader: false,
    });

    if (!res?.success) {
      HST.modalLoading.hide($modal.find(".hst-modal__body"));
      HST.toast("دریافت اطلاعات دانش‌آموز ناموفق بود", "error");
      closeStudentModal();
      return;
    }
    await prefillStudentModal(res.data);
    HST.modalLoading.hide($modal.find(".hst-modal__body"));
  });

  // ---- View student modal -----------------------------------------------
  function chips(arr, empty) {
    if (!arr || !arr.length) return '<span class="hst-muted">' + empty + "</span>";

    const visibleCount = 2;
    if (arr.length <= visibleCount) {
      return (
        '<span class="hst-chip-list">' +
        arr.map((x) => '<span class="hst-chip">' + HST.escapeHtml(x) + "</span>").join("") +
        "</span>"
      );
    }

    const visible = arr.slice(0, visibleCount);
    const hidden = arr.slice(visibleCount);
    let html = '<span class="hst-chip-list">';
    html += visible.map((x) => '<span class="hst-chip">' + HST.escapeHtml(x) + "</span>").join("");
    html += hidden
      .map((x) => '<span class="hst-chip hst-chip-extra" hidden>' + HST.escapeHtml(x) + "</span>")
      .join("");
    html +=
      '<button type="button" class="hst-chip hst-chip-more" data-count="' +
      hidden.length +
      '">+' +
      hidden.length +
      " بیشتر</button>";
    html += "</span>";
    return html;
  }

  // Toggle hidden chips with a soft reveal (lesson list in the view modal).
  $(document).on("click", ".hst-chip-more", function () {
    const $btn = $(this);
    const $list = $btn.closest(".hst-chip-list");
    const opening = !$btn.hasClass("is-open");
    $list.find(".hst-chip-extra").each(function () {
      const $c = $(this);
      if (opening) {
        $c.prop("hidden", false).addClass("hst-chip--reveal");
      } else {
        $c.prop("hidden", true).removeClass("hst-chip--reveal");
      }
    });
    $btn.toggleClass("is-open", opening);
    $btn.text(opening ? "بستن" : "+" + $btn.data("count") + " بیشتر");
  });

  function row(label, value, variant) {
    let cls = "hst-view-row";
    if (variant === "wide") cls += " hst-view-row--wide";
    return (
      '<div class="' + cls + '"><span class="hst-view-row__label">' +
      HST.escapeHtml(label) +
      '</span><span class="hst-view-row__value">' +
      (value || '<span class="hst-muted">—</span>') +
      "</span></div>"
    );
  }

  $(document).on("click", ".hst-student-view", async function () {
    const id = $(this).data("id");
    $viewModal.addClass("is-active").attr("aria-hidden", "false");
    $("body").addClass("hst-modal-open");
    HST.modalLoading.show($viewBody);

    const res = await HST.request({
      action: "hst_get_student_details",
      data: { student_id: id },
      showLoader: false,
    });

    HST.modalLoading.hide($viewBody);
    if (!res?.success) {
      $viewBody.html('<p class="hst-alert">دریافت اطلاعات دانش‌آموز ناموفق بود.</p>');
      return;
    }

    const d = res.data;
    const avatar = d.avatar_url
      ? '<img src="' + d.avatar_url + '" alt="تصویر دانش‌آموز">'
      : '<span class="hst-view-avatar__ph">' + HST.escapeHtml(HST.initials(d.display_name || "", d.first_name || "", d.last_name || "")) + "</span>";

    $viewBody.html(
      '<div class="hst-view-head">' +
        '<div class="hst-view-avatar">' + avatar + "</div>" +
        '<div><strong class="hst-view-name">' + HST.escapeHtml(d.display_name) + "</strong></div>" +
      "</div>" +
      '<div class="hst-view-grid">' +
        row("کد ملی", d.national_code ? HST.escapeHtml(d.national_code) : "") +
        row("نام پدر", d.father_name ? HST.escapeHtml(d.father_name) : "") +
        row("شماره موبایل", d.phone ? HST.escapeHtml(d.phone) : "") +
        row("شماره تماس پدر", d.father_phone ? HST.escapeHtml(d.father_phone) : "") +
        row("شماره تماس مادر", d.mother_phone ? HST.escapeHtml(d.mother_phone) : "") +
        row("تاریخ تولد", d.birthdate ? HST.escapeHtml(d.birthdate) : "") +
        row("کلاس", chips(d.classes, "—")) +
        row("درس‌ها", chips(d.lessons, "—")) +
      "</div>"
    );
  });

  $(document).on("click", "[data-hst-view-close]", function () {
    HST.modalLoading.hide($viewBody);
    $viewModal.removeClass("is-active").attr("aria-hidden", "true");
    $("body").removeClass("hst-modal-open");
  });

  $(document).on("keydown", function (e) {
    if (e.key === "Escape" && $viewModal.hasClass("is-active")) {
      $viewModal.removeClass("is-active").attr("aria-hidden", "true");
      $("body").removeClass("hst-modal-open");
    }
  });

  // ---- Submit (add / update) --------------------------------------------
  $("#define-student-form").on("submit", function (event) {
    event.preventDefault();

    const classIds = HST.getCheckedValues(".class-item");
    const lessonIds = HST.getCheckedValues(".lesson-item");

    if (!classIds.length) {
      HST.toast("لطفاً یک کلاس انتخاب کنید", "error");
      return;
    }

    if (!lessonIds.length) {
      HST.toast("حداقل یک درس انتخاب کنید", "error");
      return;
    }

    if (!$.trim($('[name="student_father_phone"]').val())) {
      HST.toast("شماره تماس پدر الزامی است", "error");
      return;
    }

    // The birthdate input is readonly, so native "required" does not catch it.
    if (!$.trim($('[name="student_birthdate"]').val())) {
      HST.toast("تاریخ تولد الزامی است", "error");
      return;
    }

    const data = {
      student_name: $.trim($('[name="student_name"]').val()),
      student_last_name: $.trim($('[name="student_last_name"]').val()),
      student_phone: $.trim($('[name="student_phone"]').val()),
      student_father_name: $.trim($('[name="student_father_name"]').val() || ""),
      student_father_phone: $.trim($('[name="student_father_phone"]').val() || ""),
      student_mother_phone: $.trim($('[name="student_mother_phone"]').val() || ""),
      student_birthdate: $.trim($('[name="student_birthdate"]').val() || ""),
      student_national_code: $.trim($('[name="student_national_code"]').val() || ""),
      class_ids: classIds,
      lesson_ids: lessonIds,
    };

    if (editingId) {
      data.id = editingId;
      HST.request({
        action: "hst_update_student",
        data,
        successMessage: true,
        reload: true,
      });
    } else {
      HST.request({
        action: "hst_add_student",
        data,
        successMessage: true,
        reload: true,
      });
    }
  });

  // ---- Delete ------------------------------------------------------------
  $(document).on("click", ".hst-delete", function () {
    if ($(this).is(":disabled")) return;
    const id = $(this).data("id");
    const $row = $(this).closest("tr");

    HST.request({
      action: "hst_delete_student",
      data: { id },
      confirm: {
        title: "حذف دانش‌آموز",
        text: "این عملیات قابل بازگشت نیست.",
        html: "<p>آیا مطمئن هستید؟</p>",
      },
      successMessage: true,
      onSuccess: function () {
        HST.removeRowOrReload($row);
      },
    });
  });
});
