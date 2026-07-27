window.HSTModal = (function ($, HST) {
  "use strict";

  const $modal = $("#hst-modal");
  const $title = $modal.find("[data-hst-dialog-title]");
  const $text = $modal.find("[data-hst-dialog-text]");
  const $confirm = $modal.find("[data-hst-dialog-confirm]");
  const $cancel = $modal.find("[data-hst-dialog-cancel]");
  const $close = $modal.find("[data-hst-dialog-close]");
  const $content = $modal.find("[data-hst-dialog-content]");

  let resolver = null;

  function resolve(payload) {
    if (typeof resolver === "function") resolver(payload);
    resolver = null;
  }

  function collectData() {
    const data = {};

    $content.find("input, select, textarea").each(function () {
      const $field = $(this);
      const name = $field.attr("name");
      if (!name || $field.is(":disabled")) return;

      if ($field.is(':checkbox')) {
        if (!data[name]) data[name] = [];
        if ($field.is(':checked')) data[name].push($field.val());
        return;
      }

      if ($field.is(':radio')) {
        if ($field.is(':checked')) data[name] = $field.val();
        return;
      }

      data[name] = $field.val();
    });

    return data;
  }

  function open(options = {}) {
    const settings = $.extend(
      {
        title: "",
        text: "",
        html: "",
        confirmText: "ذخیره تغییرات",
        cancelText: "بستن",
      },
      options
    );

    if (!$modal.length) {
      return Promise.resolve({ isConfirmed: false, data: {} });
    }

    $title.text(settings.title);
    $text.html(settings.text);
    $content.html(settings.html);
    $confirm.text(settings.confirmText);
    $cancel.text(settings.cancelText);

    $modal.addClass("is-active").attr("aria-hidden", "false");
    window.setTimeout(function () {
      const $first = $content.find("input, select, textarea").first();
      $first.trigger("focus");
      // Select existing text so the user can immediately type a replacement.
      const el = $first.get(0);
      if (el && typeof el.select === "function") {
        const selectableTypes = ["text", "search", "url", "tel", "password", "number"];
        const isText = el.tagName === "TEXTAREA"
          || (el.tagName === "INPUT" && selectableTypes.indexOf((el.type || "text").toLowerCase()) !== -1);
        if (isText && el.value) {
          try { el.select(); } catch (e) {}
        }
      }
    }, 50);

    return new Promise((resolvePromise) => {
      resolver = resolvePromise;
    });
  }

  function close() {
    if (!$modal.hasClass("is-active")) {
      $modal.attr("aria-hidden", "true");
      $content.empty();
      return;
    }

    $modal.addClass("is-closing");
    window.setTimeout(function () {
      $modal.removeClass("is-active is-closing").attr("aria-hidden", "true");
      $content.empty();
    }, 260);
  }

  $confirm.off("click.hstModal").on("click.hstModal", function () {
    const data = collectData();
    close();
    resolve({ isConfirmed: true, data });
  });

  $cancel.add($close).off("click.hstModal").on("click.hstModal", function () {
    close();
    resolve({ isConfirmed: false, data: {} });
  });

  $(document).off("keydown.hstModal").on("keydown.hstModal", function (event) {
    if (!$modal.hasClass("is-active")) return;

    if (event.key === "Escape") {
      close();
      resolve({ isConfirmed: false, data: {} });
      return;
    }

    // Enter confirms the modal (same as clicking the confirm button), so the
    // user can edit/delete/save without reaching for the mouse. We skip this
    // when focus is in a multi-line textarea (Enter = newline there).
    if (event.key === "Enter") {
      const el = document.activeElement;
      const inTextarea = el && el.tagName === "TEXTAREA";
      if (inTextarea) return;
      event.preventDefault();
      const data = collectData();
      close();
      resolve({ isConfirmed: true, data });
    }
  });

  return { open, close };
})(jQuery, window.HST);