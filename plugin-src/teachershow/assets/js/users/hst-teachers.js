jQuery(function ($) {
  "use strict";

  const $modal = $("#hst-teacher-modal");
  const $viewModal = $("#hst-teacher-view-modal");
  const $viewBody = $("#hst-teacher-view-body");
  let editingId = 0;

  function openTeacherModal() {
    $modal.addClass("is-active").attr("aria-hidden", "false");
    $("body").addClass("hst-modal-open");
  }

  function closeTeacherModal() {
    HST.modalLoading.hide($modal.find(".hst-modal__body"));
    $modal.removeClass("is-active").attr("aria-hidden", "true");
    $("body").removeClass("hst-modal-open");
  }

  function setModalMode(mode) {
    const $title = $("#hst-teacher-modal-title");
    const $submit = $("#define-teacher-form button[type='submit'], button[form='define-teacher-form'][type='submit']");

    if (mode === "edit") {
      $title.text("ویرایش معلم");
      $submit.text("ذخیره تغییرات");
      return;
    }

    $title.text("افزودن معلم");
    $submit.text("افزودن معلم");
  }

  function resetTeacherForm() {
    const form = document.getElementById("define-teacher-form");
    if (form) form.reset();
  }

  function fillTeacherForm(data) {
    $('[name="teacher_name"]').val(data.first_name || "");
    $('[name="teacher_last_name"]').val(data.last_name || "");
    $('[name="teacher_phone"]').val(data.phone || "");
    $('[name="teacher_national_code"]').val(data.national_code || "");
    $('[name="teacher_personnel_code"]').val(data.personnel_code || "");
    $('[name="teacher_birthdate"]').val(data.birthdate || "");
  }

  function chips(items, emptyText) {
    if (!items || !items.length) {
      return '<span class="hst-muted">' + HST.escapeHtml(emptyText) + "</span>";
    }

    const visibleCount = 2;
    if (items.length <= visibleCount) {
      return (
        '<span class="hst-chip-list">' +
        items.map(function (item) {
          return '<span class="hst-chip">' + HST.escapeHtml(item) + "</span>";
        }).join("") +
        "</span>"
      );
    }

    const visible = items.slice(0, visibleCount);
    const hidden = items.slice(visibleCount);
    let html = '<span class="hst-chip-list">';
    html += visible.map(function (item) {
      return '<span class="hst-chip">' + HST.escapeHtml(item) + "</span>";
    }).join("");
    html += hidden.map(function (item) {
      return '<span class="hst-chip hst-chip-extra" hidden>' + HST.escapeHtml(item) + "</span>";
    }).join("");
    html +=
      '<button type="button" class="hst-chip hst-chip-more" data-count="' +
      hidden.length +
      '">+' +
      hidden.length +
      " بیشتر</button>";
    html += "</span>";
    return html;
  }

  function row(label, value) {
    return (
      '<div class="hst-view-row"><span class="hst-view-row__label">' +
      HST.escapeHtml(label) +
      '</span><span class="hst-view-row__value">' +
      (value || '<span class="hst-muted">—</span>') +
      "</span></div>"
    );
  }

  $(document).on("click", "#hst-teacher-add", function () {
    editingId = 0;
    setModalMode("add");
    resetTeacherForm();
    openTeacherModal();
  });

  $(document).on("click", "[data-hst-modal-close]", function () {
    if ($(this).closest("#hst-teacher-modal").length || $(this).is("[data-hst-modal-close]")) {
      closeTeacherModal();
    }
  });

  $(document).on("keydown", function (event) {
    if (event.key === "Escape" && $modal.hasClass("is-active")) closeTeacherModal();
  });

  $(document).on("click", ".hst-chip-more", function () {
    const $button = $(this);
    const $list = $button.closest(".hst-chip-list");
    const opening = !$button.hasClass("is-open");

    $list.find(".hst-chip-extra").each(function () {
      const $chip = $(this);
      if (opening) {
        $chip.prop("hidden", false).addClass("hst-chip--reveal");
      } else {
        $chip.prop("hidden", true).removeClass("hst-chip--reveal");
      }
    });

    $button.toggleClass("is-open", opening);
    $button.text(opening ? "بستن" : "+" + $button.data("count") + " بیشتر");
  });

  $(document).on("click", ".hst-teacher-view", async function () {
    const id = $(this).data("id");
    $viewModal.addClass("is-active").attr("aria-hidden", "false");
    $("body").addClass("hst-modal-open");
    HST.modalLoading.show($viewBody);

    const response = await HST.request({
      action: "hst_get_teacher_details",
      data: { teacher_id: id },
      showLoader: false,
    });

    HST.modalLoading.hide($viewBody);
    if (!response || !response.success) {
      $viewBody.html('<p class="hst-alert">دریافت اطلاعات معلم ناموفق بود.</p>');
      return;
    }

    const data = response.data;
    const avatar = data.avatar_url
      ? '<img src="' + HST.escapeHtml(data.avatar_url) + '" alt="تصویر معلم">'
      : '<span class="hst-view-avatar__ph">' + HST.escapeHtml(HST.initials(data.display_name || "", data.first_name || "", data.last_name || "")) + "</span>";

    $viewBody.html(
      '<div class="hst-view-head">' +
        '<div class="hst-view-avatar">' + avatar + "</div>" +
        '<div><strong class="hst-view-name">' + HST.escapeHtml(data.display_name) + "</strong></div>" +
      "</div>" +
      '<div class="hst-view-grid">' +
        row("کد ملی", data.national_code ? HST.escapeHtml(data.national_code) : "") +
        row("کد پرسنلی", data.personnel_code ? HST.escapeHtml(data.personnel_code) : "") +
        row("شماره موبایل", data.phone ? HST.escapeHtml(data.phone) : "") +
        row("تاریخ تولد", data.birthdate ? HST.escapeHtml(data.birthdate) : "") +
        row("کلاس‌ها", chips(data.classes, "بدون تخصیص")) +
        row("درس‌ها", chips(data.lessons, "بدون تخصیص")) +
      "</div>"
    );
  });

  $(document).on("click", "[data-hst-view-close]", function () {
    HST.modalLoading.hide($viewBody);
    $viewModal.removeClass("is-active").attr("aria-hidden", "true");
    $("body").removeClass("hst-modal-open");
  });

  $(document).on("click", ".hst-teacher-edit", async function () {
    const id = $(this).data("id");
    editingId = id;
    setModalMode("edit");
    resetTeacherForm();
    openTeacherModal();
    HST.modalLoading.show($modal.find(".hst-modal__body"));

    const response = await HST.request({
      action: "hst_get_teacher_details",
      data: { teacher_id: id },
      showLoader: false,
    });

    if (!response || !response.success) {
      HST.modalLoading.hide($modal.find(".hst-modal__body"));
      HST.toast("دریافت اطلاعات معلم ناموفق بود", "error");
      closeTeacherModal();
      return;
    }

    fillTeacherForm(response.data);
    HST.modalLoading.hide($modal.find(".hst-modal__body"));
  });

  $("#define-teacher-form").on("submit", function (event) {
    event.preventDefault();

    if (!$.trim($('[name="teacher_birthdate"]').val())) {
      HST.toast("تاریخ تولد الزامی است", "error");
      return;
    }

    const data = {
      teacher_name: $.trim($('[name="teacher_name"]').val()),
      teacher_last_name: $.trim($('[name="teacher_last_name"]').val()),
      teacher_phone: $.trim($('[name="teacher_phone"]').val()),
      teacher_birthdate: $.trim($('[name="teacher_birthdate"]').val() || ""),
      teacher_national_code: $.trim($('[name="teacher_national_code"]').val() || ""),
      teacher_personnel_code: $.trim($('[name="teacher_personnel_code"]').val() || ""),
    };

    if (editingId) {
      data.id = editingId;
      HST.request({
        action: "hst_update_teacher",
        data: data,
        successMessage: true,
        reload: true,
      });
      return;
    }

    HST.request({
      action: "hst_add_teacher",
      data: data,
      successMessage: true,
      reload: true,
    });
  });

  $(document).on("click", ".hst-delete", function () {
    if ($(this).is(":disabled")) return;

    const id = $(this).data("id");
    const $row = $(this).closest("tr");

    HST.request({
      action: "hst_delete_teacher",
      data: { id: id },
      confirm: {
        title: "حذف معلم",
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
