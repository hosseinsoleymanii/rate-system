window.HST = window.HST || {};

(function ($, HST) {
  "use strict";

  const defaults = {
    errorMessage: "خطا در انجام عملیات",
    networkErrorMessage: "ارتباط با سرور برقرار نشد",
  };

  HST.ajaxUrl = window.hst_ajax_obj?.ajax_url || "";
  HST.nonce = window.hst_ajax_obj?.nonce || "";

  HST.escapeHtml = function (value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  };

  HST.formatScore = function (value) {
    if (value === null || value === undefined || String(value).trim() === "") return "";
    const number = Number(String(value).replace("،", ".").replace(",", "."));
    if (!Number.isFinite(number)) return String(value);
    return String(Math.round(number * 100) / 100);
  };


  HST.initials = function (name, firstName = "", lastName = "") {
    const firstChar = (value) => Array.from(String(value || "").trim())[0] || "";
    const parts = String(name || "").trim().split(/\s+/u).filter(Boolean);
    const firstInitial = firstChar(firstName) || firstChar(parts[0] || "");
    const lastInitial = firstChar(lastName) || (parts.length >= 2 ? firstChar(parts[parts.length - 1]) : "");
    return [firstInitial, lastInitial].filter(Boolean).join("\u00A0") || "؟";
  };


  HST.loadingMarkup = function () {
    return (
      '<span class="hst-inline-loading" role="status" aria-live="polite">' +
        '<span class="hst-inline-loading__spinner" aria-hidden="true"></span>' +
        '<span class="hst-inline-loading__text">در حال بارگذاری...</span>' +
      '</span>'
    );
  };


  HST.modalLoading = {
    show(target) {
      const $body = target && target.jquery ? target : $(target);
      if (!$body.length) return;
      $body.find("[data-hst-modal-loading-state]").remove();
      $body
        .addClass("is-loading")
        .attr("aria-busy", "true")
        .append(
          '<div class="hst-modal-loading-state" data-hst-modal-loading-state>' +
            HST.loadingMarkup() +
          '</div>'
        );
    },
    hide(target) {
      const $body = target && target.jquery ? target : $(target);
      if (!$body.length) return;
      $body
        .removeClass("is-loading")
        .removeAttr("aria-busy")
        .find("[data-hst-modal-loading-state]")
        .remove();
    },
  };

  HST.toInt = function (value, fallback = 0) {
    const number = Number.parseInt(value, 10);
    return Number.isFinite(number) ? number : fallback;
  };

  HST.classSortInfo = function (value) {
    const digitMap = {
      "۰": "0", "۱": "1", "۲": "2", "۳": "3", "۴": "4",
      "۵": "5", "۶": "6", "۷": "7", "۸": "8", "۹": "9",
      "٠": "0", "١": "1", "٢": "2", "٣": "3", "٤": "4",
      "٥": "5", "٦": "6", "٧": "7", "٨": "8", "٩": "9",
    };
    const name = String(value || "")
      .replace(/[يكۀة]/g, (char) => ({ "ي": "ی", "ك": "ک", "ۀ": "ه", "ة": "ه" }[char] || char))
      .replace(/[۰-۹٠-٩]/g, (digit) => digitMap[digit] || digit)
      .replace(/[‌‎‏‪-‮]/g, " ")
      .replace(/\s+/g, " ")
      .trim();

    let grade = 99;
    if (name.includes("دوازدهم")) grade = 12;
    else if (name.includes("یازدهم")) grade = 11;
    else if (name.includes("دهم")) grade = 10;
    else {
      const match = name.match(/(?:^|[^0-9])(12|11|10)[0-9]?(?:[^0-9]|$)/);
      if (match) grade = Number.parseInt(match[1], 10);
    }

    let major = 99;
    if (name.includes("ریاضی") || name.includes("فیزیک")) major = 1;
    else if (name.includes("تجربی")) major = 2;
    else if (name.includes("انسانی") || name.includes("ادبیات")) major = 3;

    return { name, grade, major };
  };

  HST.compareClassNames = function (left, right) {
    const a = HST.classSortInfo(left);
    const b = HST.classSortInfo(right);
    if (a.grade !== b.grade) return a.grade - b.grade;
    if (a.major !== b.major) return a.major - b.major;
    return a.name.localeCompare(b.name, "fa", { numeric: true, sensitivity: "base" });
  };

  HST.sortClassItems = function (items, nameKey) {
    const key = nameKey || "class_name";
    return (Array.isArray(items) ? items.slice() : []).sort(function (left, right) {
      const leftName = left && typeof left === "object" ? left[key] : left;
      const rightName = right && typeof right === "object" ? right[key] : right;
      return HST.compareClassNames(leftName, rightName);
    });
  };

  HST.getCheckedValues = function (selector) {
    return $(selector)
      .filter(":checked")
      .map(function () {
        return String($(this).val());
      })
      .get();
  };

  HST.syncSelectAll = function (itemSelector, selectAllSelector) {
    const total = $(itemSelector).length;
    const checked = $(itemSelector).filter(":checked").length;
    $(selectAllSelector).prop("checked", total > 0 && total === checked);
  };

  HST.setSingleChecked = function (changed, selector) {
    $(selector).not(changed).prop("checked", false);
  };

  HST.removeRowOrReload = function ($row, tableSelector = "table") {
    $row.fadeOut(250, function () {
      $(this).remove();

      if ($(`${tableSelector} tbody tr`).length === 0) {
        window.location.reload();
      }
    });
  };

  HST.loader = {
    get $el() {
      return $(".hst-loader");
    },
    show() {
      this.$el.addClass("show-loader").attr("aria-hidden", "false");
    },
    hide() {
      this.$el.removeClass("show-loader").attr("aria-hidden", "true");
    },
  };


  HST.getServerMessage = HST.getMessage = function (source, fallback = "") {
    const isUsefulText = function (text) {
      if (!text) return false;
      const value = String(text).trim();
      if (!value) return false;
      // Avoid showing full HTML/PHP error pages inside toast.
      if (/^\s*</.test(value) && /<\/?(?:html|body|div|script|style|br|p)\b/i.test(value)) return false;
      return true;
    };

    const tryParseJson = function (value) {
      if (typeof value !== "string") return null;
      const text = value.trim();
      if (!text || !/^[\[{]/.test(text)) return null;
      try {
        return JSON.parse(text);
      } catch (e) {
        return null;
      }
    };

    const pick = function (value) {
      if (value === null || value === undefined) return "";

      if (typeof value === "string" || typeof value === "number" || typeof value === "boolean") {
        const parsed = tryParseJson(String(value));
        if (parsed) return pick(parsed);
        const text = String(value).trim();
        return isUsefulText(text) ? text : "";
      }

      if (Array.isArray(value)) {
        return value.map(pick).filter(Boolean).join(" ").trim();
      }

      if (typeof value === "object") {
        // WordPress wp_send_json_error('message') => { success:false, data:'message' }
        // WordPress wp_send_json_error(['message'=>'...']) => { success:false, data:{message:'...'} }
        // jQuery jqXHR => { responseJSON:{...}, responseText:'...' }
        return (
          pick(value.message) ||
          pick(value.msg) ||
          pick(value.error_message) ||
          pick(value.error) ||
          pick(value.errors) ||
          pick(value.notice) ||
          pick(value.data) ||
          pick(value.responseJSON) ||
          pick(value.responseText)
        );
      }

      return "";
    };

    return pick(source) || pick(fallback) || defaults.errorMessage;
  };

  HST.toast = function (message, type = "success", duration = 3800) {
    const safeType = ["success", "error", "warning", "info"].includes(type) ? type : "success";
    const safeMessage = HST.getMessage(message, defaults.errorMessage);

    // Never stack the same live toast more than once. The Set is stored on the
    // shared HST object so it also survives accidental bundle re-evaluation.
    HST.__activeToastKeys = HST.__activeToastKeys || new Set();
    const toastKey = safeType + "\u0000" + safeMessage;
    if (HST.__activeToastKeys.has(toastKey)) return;
    HST.__activeToastKeys.add(toastKey);

    let $container = $(".hst-toast-container");

    if (!$container.length) {
      $container = $('<div class="hst-toast-container" role="region" aria-live="polite" aria-label="اعلان‌ها"></div>');
      $("body").append($container);
    }

    // Themed line icon per type (currentColor, inherits the accent strip color).
    const icons = {
      success: '<path d="M20 6 9 17l-5-5"/>',
      error: '<path d="M18 6 6 18M6 6l12 12"/>',
      warning: '<path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>',
      info: '<path d="M12 16v-4M12 8h.01"/><circle cx="12" cy="12" r="10"/>',
    };

    const $toast = $(
      `<div class="hst-toast hst-toast-${safeType}" role="status">
        <span class="hst-toast__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${icons[safeType]}</svg></span>
        <span class="hst-toast__msg"></span>
        <button type="button" class="hst-toast-close" aria-label="بستن">&times;</button>
      </div>`
    );

    $toast.find(".hst-toast__msg").text(safeMessage);
    $container.append($toast);

    const close = function () {
      if ($toast.hasClass("is-leaving")) return;
      $toast.addClass("is-leaving");
      window.setTimeout(function () {
        $toast.remove();
        HST.__activeToastKeys.delete(toastKey);
      }, 280);
    };

    $toast.find(".hst-toast-close").on("click", close);
    window.setTimeout(close, duration);
  };


  // ---- Shared feedback for disabled actions -------------------------------
  // Native disabled buttons do not dispatch a normal click event in every
  // browser. Pointer feedback plus a click/keyboard guard makes the behaviour
  // consistent for native disabled controls, aria-disabled links/buttons and
  // disabled management tiles across the whole plugin.
  const disabledActionToastTimes = new WeakMap();
  const disabledActionSelector = [
    "button:disabled",
    'input[type="button"]:disabled',
    'input[type="submit"]:disabled',
    'input[type="reset"]:disabled',
    'button[aria-disabled="true"]',
    'a[aria-disabled="true"]',
    '[role="button"][aria-disabled="true"]',
    '.hst-btn.is-disabled',
    '.hst-tile[aria-disabled="true"]'
  ].join(", ");

  const disabledActionMessages = [
    [".hst-login__otp-resend", "برای ارسال مجدد کد، تا پایان زمان انتظار صبر کنید."],
    ["[data-hst-dashboard-sms-balance]", "موجودی پیامک در حال بروزرسانی است؛ لطفاً منتظر بمانید."],
    [".hst-page-prev", "صفحه قبلی دیگری وجود ندارد."],
    [".hst-page-next", "صفحه بعدی دیگری وجود ندارد."],
    ["[data-hst-online-preview-prev], [data-hst-student-exam-prev]", "سؤال قبلی دیگری وجود ندارد."],
    ["[data-hst-online-preview-next], [data-hst-student-exam-next]", "سؤال بعدی دیگری وجود ندارد."],
    [".hst-jdp-day", "این تاریخ قابل انتخاب نیست."],
    ["#hst-backup-restore", "ابتدا فایل پشتیبان را انتخاب کنید."],
    ["#hst-attendance-save", "ابتدا کلاس، درس، تاریخ و زنگ را کامل انتخاب کنید."],
    ["[data-hst-pay-confirm]", "ابتدا روش پرداخت را انتخاب کنید."],
    ["[data-hst-blueprint-next]", "برای ادامه، حداقل یک درس یا بخش را انتخاب کنید."],
    ["[data-hst-question-transfer-submit]", "ابتدا یک آزمون مقصد سازگار انتخاب کنید."],
    ["[data-hst-question-open]", "ابتدا سال تحصیلی فعال و درس‌های موردنیاز را تعریف کنید."],
    ['[data-hst-exam-management-action="preview"]', "پیش‌نمایش تعاملی برای آزمون حضوری در دسترس نیست."],
    [".hst-user-picker-result.is-selected", "این کاربر قبلاً انتخاب شده است."],
    [".hst-tile[aria-disabled=\"true\"]", "این بخش هنوز آماده استفاده نیست."],
    ["#hst-schedule-save-teacher-assignment, #hst-schedule-clear-teacher-form", "ابتدا یک دبیر را انتخاب کنید."],
    ["[data-hst-schedule-blocked-confirm]", "ابتدا یک سال تحصیلی فعال تعریف کنید."],
    ["#hst-student-add, #hst-teacher-add, #hst-period-add, #hst-tuition-add, #hst-disc-add, #hst-import-preview, #hst-import-student-photos", "ابتدا یک سال تحصیلی فعال تعریف کنید."],
    ["#hst-tuition-sms-test-send, #hst-discipline-sms-test-send, #hst-notification-sms-test-send", "ابتدا تنظیمات پیامک را کامل کنید."],
    ["#hst-mark-all-notifications-read, [data-hst-mark-all-header-notifications]", "اعلان خوانده‌نشده‌ای وجود ندارد."],
    ["[data-hst-avatar-save]", "ابتدا یک تصویر معتبر انتخاب کنید."],
    [".hst-gb-add, .hst-gb-del", "دفتر نمره در وضعیت فعلی قابل ویرایش نیست."]
  ];

  function disabledActionElement(target) {
    const node = target && target.nodeType === 1 ? target : target && target.parentElement;
    if (!node || typeof node.closest !== "function") return null;

    const control = node.closest(disabledActionSelector);
    if (!control) return null;

    // Some sections intentionally show unavailable tiles as static roadmap
    // items. They remain aria-disabled for accessibility, but must never
    // trigger the shared warning toast.
    if (control.getAttribute("data-hst-disabled-silent") === "true") return null;

    // Progress modals have a dedicated, operation-specific close guard.
    const lockedProgress = control.closest('[data-hst-progress-modal][data-hst-progress-locked="true"]');
    if (
      lockedProgress &&
      (control.matches("[data-hst-progress-close], .hst-modal__close") ||
        control.closest("[data-hst-progress-close], .hst-modal__close"))
    ) {
      return null;
    }

    return control;
  }

  function disabledReasonText(value) {
    const text = String(value || "").replace(/\s+/g, " ").trim();
    if (!text) return "";
    return /(?:ابتدا|هنوز|وجود ندارد|نیست|نشد|نشده|در دسترس نیست|امکان|نمی(?:‌| )?توان|نمی(?:‌| )?شود|غیرفعال|قابل.+نیست|به دلیل|تا پایان|تا تعیین|تمام.+ثبت|فقط.+در دسترس|آماده نشده|پایان یافته|مجاز نیست|پیدا نشد|استفاده شده)/u.test(text)
      ? text
      : "";
  }

  function disabledActionLabel(control) {
    const candidates = [
      control.getAttribute("aria-label"),
      control.textContent,
      control.getAttribute("title")
    ];

    for (const candidate of candidates) {
      const label = String(candidate || "").replace(/\s+/g, " ").trim();
      if (!label || disabledReasonText(label)) continue;
      return label.length > 72 ? label.slice(0, 69) + "…" : label;
    }
    return "";
  }

  HST.getDisabledActionMessage = function (control) {
    if (!control || control.nodeType !== 1) {
      return "این گزینه در شرایط فعلی در دسترس نیست.";
    }

    const explicit = String(
      control.getAttribute("data-hst-disabled-message") ||
      control.getAttribute("data-disabled-message") ||
      ""
    ).replace(/\s+/g, " ").trim();
    if (explicit) return explicit;

    if (
      control.getAttribute("aria-busy") === "true" ||
      control.classList.contains("is-loading") ||
      control.classList.contains("is-busy")
    ) {
      return "عملیات در حال انجام است؛ لطفاً تا پایان آن منتظر بمانید.";
    }

    for (const item of disabledActionMessages) {
      try {
        if (control.matches(item[0])) return item[1];
      } catch (error) {
        // Ignore an unsupported selector without breaking the global guard.
      }
    }

    const describedBy = String(control.getAttribute("aria-describedby") || "").trim();
    if (describedBy) {
      const description = describedBy
        .split(/\s+/)
        .map(function (id) {
          const element = document.getElementById(id);
          return element ? String(element.textContent || "").trim() : "";
        })
        .filter(Boolean)
        .join(" ");
      const describedReason = disabledReasonText(description);
      if (describedReason) return describedReason;
    }

    const titleReason = disabledReasonText(control.getAttribute("title"));
    if (titleReason) return titleReason;

    const ariaReason = disabledReasonText(control.getAttribute("aria-label"));
    if (ariaReason) return ariaReason;

    const label = disabledActionLabel(control);
    return label
      ? `امکان «${label}» در شرایط فعلی وجود ندارد.`
      : "این گزینه در شرایط فعلی در دسترس نیست.";
  };

  HST.notifyDisabledAction = function (control) {
    if (!control) return false;
    const now = Date.now();
    const last = disabledActionToastTimes.get(control) || 0;
    if (now - last < 750) return true;

    disabledActionToastTimes.set(control, now);
    HST.toast(HST.getDisabledActionMessage(control), "warning");
    return true;
  };

  function isTrustedDisabledActionEvent(event) {
    // Page initialisers and third-party scripts may dispatch synthetic click or
    // pointer events while hydrating controls. Disabled feedback is intended
    // only for a real user interaction, never for those scripted events.
    return Boolean(event && event.isTrusted === true);
  }

  // Bind the capture guards only once. This also protects installations that
  // re-evaluate the core bundle during partial navigation or cached fragment
  // hydration; without the singleton, one real interaction could create the
  // same toast several times.
  if (!window.__hstDisabledActionGuardsBound) {
    window.__hstDisabledActionGuardsBound = true;

    // Native disabled form controls may suppress `click`, but modern browsers
    // still expose the pointer press. This gives touch and mouse users feedback.
    document.addEventListener("pointerdown", function (event) {
      if (!isTrustedDisabledActionEvent(event)) return;
      if (typeof event.button === "number" && event.button !== 0) return;

      const control = disabledActionElement(event.target);
      if (!control) return;
      HST.notifyDisabledAction(control);
    }, true);

    // aria-disabled controls are not natively blocked, so stop their action too.
    document.addEventListener("click", function (event) {
      if (!isTrustedDisabledActionEvent(event)) return;

      const control = disabledActionElement(event.target);
      if (!control) return;

      event.preventDefault();
      event.stopPropagation();
      if (typeof event.stopImmediatePropagation === "function") event.stopImmediatePropagation();
      HST.notifyDisabledAction(control);
    }, true);

    document.addEventListener("keydown", function (event) {
      if (!isTrustedDisabledActionEvent(event)) return;
      if (event.key !== "Enter" && event.key !== " ") return;

      const control = disabledActionElement(event.target);
      if (!control) return;

      event.preventDefault();
      event.stopPropagation();
      if (typeof event.stopImmediatePropagation === "function") event.stopImmediatePropagation();
      HST.notifyDisabledAction(control);
    }, true);
  }

  // ---- Locked progress-modal close feedback ------------------------------
  // Long-running operations keep their progress modal open. A shared capture-
  // phase guard makes every close path consistent: backdrop, close button,
  // header × and Escape all show the operation-specific toast instead of
  // silently doing nothing or relying on page-specific handlers.
  const progressModalToastTimes = new WeakMap();

  function progressModalElement(target) {
    if (!target) return null;
    const element = target.jquery ? target.get(0) : (typeof target === "string" ? document.querySelector(target) : target);
    if (!element || element.nodeType !== 1) return null;
    return element.matches("[data-hst-progress-modal]") ? element : element.closest("[data-hst-progress-modal]");
  }

  HST.setProgressModalLocked = function (target, locked, message) {
    const modal = progressModalElement(target);
    if (!modal) return;

    if (locked) {
      modal.setAttribute("data-hst-progress-locked", "true");
      modal.setAttribute(
        "data-hst-progress-lock-message",
        String(message || "تا پایان عملیات، صفحه را نبندید یا ترک نکنید.")
      );
    } else {
      modal.removeAttribute("data-hst-progress-locked");
      modal.removeAttribute("data-hst-progress-lock-message");
    }

    $(modal)
      .find("[data-hst-progress-close], .hst-modal__close")
      .prop("disabled", false)
      .removeAttr("hidden")
      .attr("aria-disabled", locked ? "true" : "false");
  };

  HST.isProgressModalLocked = function (target) {
    const modal = progressModalElement(target);
    return Boolean(modal && modal.getAttribute("data-hst-progress-locked") === "true");
  };

  HST.notifyProgressModalLocked = function (target) {
    const modal = progressModalElement(target);
    if (!modal || !HST.isProgressModalLocked(modal)) return false;

    const now = Date.now();
    const last = progressModalToastTimes.get(modal) || 0;
    if (now - last > 650) {
      progressModalToastTimes.set(modal, now);
      HST.toast(
        modal.getAttribute("data-hst-progress-lock-message") || "تا پایان عملیات، صفحه را نبندید یا ترک نکنید.",
        "error"
      );
    }
    return true;
  };

  document.addEventListener("click", function (event) {
    const rawTarget = event.target;
    const target = rawTarget && rawTarget.nodeType === 1 ? rawTarget : rawTarget && rawTarget.parentElement;
    if (!target || typeof target.closest !== "function") return;

    const closeTrigger = target.closest(
      "[data-hst-progress-modal] .hst-modal__backdrop, " +
      "[data-hst-progress-modal] [data-hst-progress-close], " +
      "[data-hst-progress-modal] .hst-modal__close"
    );
    if (!closeTrigger) return;

    const modal = closeTrigger.closest("[data-hst-progress-modal]");
    if (!modal || !HST.isProgressModalLocked(modal)) return;

    event.preventDefault();
    event.stopPropagation();
    if (typeof event.stopImmediatePropagation === "function") event.stopImmediatePropagation();
    HST.notifyProgressModalLocked(modal);
  }, true);

  document.addEventListener("keydown", function (event) {
    if (event.key !== "Escape") return;

    const locked = Array.from(document.querySelectorAll('[data-hst-progress-modal][data-hst-progress-locked="true"]'))
      .filter(function (modal) {
        return modal.getAttribute("aria-hidden") !== "true" &&
          (modal.classList.contains("is-open") || modal.classList.contains("is-active"));
      })
      .pop();

    if (!locked) return;
    event.preventDefault();
    event.stopPropagation();
    if (typeof event.stopImmediatePropagation === "function") event.stopImmediatePropagation();
    HST.notifyProgressModalLocked(locked);
  }, true);


  window.addEventListener("beforeunload", function (event) {
    const hasLockedOperation = Array.from(
      document.querySelectorAll('[data-hst-progress-modal][data-hst-progress-locked="true"]')
    ).some(function (modal) {
      return modal.getAttribute("aria-hidden") !== "true" &&
        (modal.classList.contains("is-open") || modal.classList.contains("is-active"));
    });
    if (!hasLockedOperation) return;
    event.preventDefault();
    event.returnValue = "";
  });


  // ---- Shared long-operation progress modal ------------------------------
  // Downloads and exports that can take more than a moment should use this
  // single modal instead of a silent full-screen loader. The service owns the
  // lock, Persian percentage, duplicate-operation protection and auto-close.
  let globalOperationProgressSerial = 0;
  let globalOperationProgressTimer = null;
  let globalOperationPulseTimer = null;

  function progressFaDigits(value) {
    const digits = ["۰", "۱", "۲", "۳", "۴", "۵", "۶", "۷", "۸", "۹"];
    return String(value == null ? "" : value).replace(/[0-9]/g, function (digit) {
      return digits[Number(digit)] || digit;
    });
  }

  function ensureGlobalOperationProgressModal() {
    let $modal = $("#hst-global-operation-progress-modal");
    if ($modal.length) return $modal;

    $modal = $(`
      <div class="hst-modal" data-hst-progress-modal data-hst-modal-size="md" id="hst-global-operation-progress-modal" role="dialog" aria-modal="true" aria-labelledby="hst-global-operation-progress-title" aria-hidden="true" hidden>
        <div class="hst-modal__backdrop" data-hst-global-progress-close></div>
        <div class="hst-modal__panel">
          <div class="hst-modal__header">
            <div>
              <h3 id="hst-global-operation-progress-title">در حال انجام عملیات</h3>
              <p data-hst-global-progress-subtitle>لطفاً تا پایان عملیات، صفحه را نبندید.</p>
            </div>
            <button type="button" class="hst-modal__close" data-hst-progress-close data-hst-global-progress-close aria-label="بستن">×</button>
          </div>
          <div class="hst-modal__body">
            <div class="hst-operation-progress" aria-live="polite">
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
            <button type="button" class="hst-btn hst-btn--ghost" data-hst-progress-close data-hst-global-progress-close>بستن</button>
          </div>
        </div>
      </div>
    `);

    $("body").append($modal);
    $modal.on("click", "[data-hst-global-progress-close]", function () {
      if (HST.isProgressModalLocked($modal)) {
        HST.notifyProgressModalLocked($modal);
        return;
      }
      $modal.removeClass("is-active is-open").attr("aria-hidden", "true").prop("hidden", true);
      if (!$(".hst-modal.is-active, .hst-modal.is-open, .hst-modal[data-open]").length) {
        $("body").removeClass("hst-modal-open");
      }
    });
    return $modal;
  }

  function clearGlobalOperationTimers() {
    if (globalOperationProgressTimer) {
      window.clearTimeout(globalOperationProgressTimer);
      globalOperationProgressTimer = null;
    }
    if (globalOperationPulseTimer) {
      window.clearInterval(globalOperationPulseTimer);
      globalOperationPulseTimer = null;
    }
  }

  HST.operationProgress = {
    open(options = {}) {
      const serial = ++globalOperationProgressSerial;
      const $modal = ensureGlobalOperationProgressModal();
      let currentPercent = 0;
      let finished = false;
      clearGlobalOperationTimers();

      function isCurrent() {
        return serial === globalOperationProgressSerial && !finished;
      }

      function update(percent, text, stageTitle) {
        if (!isCurrent()) return;
        const safe = Math.max(0, Math.min(100, Math.round(Number(percent) || 0)));
        currentPercent = safe;
        if (stageTitle) $modal.find(".hst-operation-progress__title").text(String(stageTitle));
        if (text) $modal.find(".hst-operation-progress__hint").text(String(text));
        $modal.find(".hst-operation-progress__bar").css("width", safe + "%");
        $modal.find(".hst-operation-progress__percent").text(progressFaDigits(safe) + "٪");
        $modal.find(".hst-operation-progress__track").attr("aria-valuenow", String(safe));
      }

      $modal.find("#hst-global-operation-progress-title").text(options.title || "در حال انجام عملیات");
      $modal.find("[data-hst-global-progress-subtitle]").text(
        options.subtitle || "این عملیات ممکن است کمی زمان ببرد؛ لطفاً صفحه را نبندید."
      );
      $modal.prop("hidden", false).addClass("is-active").attr("aria-hidden", "false");
      $("body").addClass("hst-modal-open");
      HST.setProgressModalLocked(
        $modal,
        true,
        options.lockMessage || "عملیات هنوز کامل نشده است؛ لطفاً تا پایان آن صبر کنید."
      );
      update(options.percent || 0, options.text || "در حال شروع عملیات...", options.stageTitle || "در حال آماده‌سازی");

      const handle = {
        modal: $modal,
        update,
        startAuto(config = {}) {
          if (!isCurrent()) return handle;
          if (globalOperationPulseTimer) window.clearInterval(globalOperationPulseTimer);
          const ceiling = Math.max(currentPercent, Math.min(95, Number(config.ceiling) || 82));
          const interval = Math.max(250, Number(config.interval) || 850);
          const step = Math.max(1, Number(config.step) || 1);
          globalOperationPulseTimer = window.setInterval(function () {
            if (!isCurrent() || currentPercent >= ceiling) {
              window.clearInterval(globalOperationPulseTimer);
              globalOperationPulseTimer = null;
              return;
            }
            update(Math.min(ceiling, currentPercent + step), config.text || "در حال پردازش اطلاعات...");
          }, interval);
          return handle;
        },
        stopAuto() {
          if (globalOperationPulseTimer) {
            window.clearInterval(globalOperationPulseTimer);
            globalOperationPulseTimer = null;
          }
          return handle;
        },
        complete(text, delay = 850) {
          if (!isCurrent()) return;
          handle.stopAuto();
          update(100, text || "فایل آماده شد و دانلود آغاز شد.", "عملیات کامل شد");
          HST.setProgressModalLocked($modal, false);
          finished = true;
          globalOperationProgressTimer = window.setTimeout(function () {
            $modal.removeClass("is-active is-open").attr("aria-hidden", "true").prop("hidden", true);
            if (!$(".hst-modal.is-active, .hst-modal.is-open, .hst-modal[data-open]").length) {
              $("body").removeClass("hst-modal-open");
            }
            globalOperationProgressTimer = null;
          }, Math.max(0, Number(delay) || 0));
        },
        fail(text) {
          if (!isCurrent()) return;
          handle.stopAuto();
          update(currentPercent, text || "عملیات با خطا متوقف شد.", "عملیات انجام نشد");
          HST.setProgressModalLocked($modal, false);
          finished = true;
        },
        close() {
          if (!isCurrent() && $modal.attr("aria-hidden") === "true") return;
          handle.stopAuto();
          HST.setProgressModalLocked($modal, false);
          finished = true;
          $modal.removeClass("is-active is-open").attr("aria-hidden", "true").prop("hidden", true);
          if (!$(".hst-modal.is-active, .hst-modal.is-open, .hst-modal[data-open]").length) {
            $("body").removeClass("hst-modal-open");
          }
        },
      };

      return handle;
    },
  };

  // ---- Live SMS consumption badges ----------------------------------------
  // Panelchi returns the authenticated tariff and coefficients server-side.
  // Feature scripts only provide the current template and record id; the
  // estimate endpoint renders the real recipient messages before calculating
  // parts, valid recipients and estimated SMS-unit consumption.
  HST.smsUsage = HST.smsUsage || (function () {
    const states = new Map();
    const numberFormat = new Intl.NumberFormat("fa-IR", { maximumFractionDigits: 2 });

    function number(value) {
      const parsed = Number(value);
      return numberFormat.format(Number.isFinite(parsed) ? parsed : 0);
    }

    function getTarget(target) {
      return target && target.jquery ? target : $(target);
    }

    function setState($target, state, message) {
      if (!$target.length) return;
      $target
        .removeClass("is-loading is-error is-ready")
        .addClass("is-" + state)
        .attr("aria-busy", state === "loading" ? "true" : "false");
      if (message) {
        $target.html('<span class="hst-sms-usage__badge hst-sms-usage__badge--muted">' + HST.escapeHtml(message) + '</span>');
      }
    }

    function render($target, estimate) {
      if (!$target.length) return;
      const valid = Number(estimate?.recipient_count || 0);
      const targets = Number(estimate?.target_count || valid);
      const skipped = Number(estimate?.skipped_count || 0);
      const rawParts = Number(estimate?.raw_parts || 0);
      const units = Number(estimate?.estimated_units || rawParts || 0);

      const badges = [];
      badges.push('<span class="hst-sms-usage__badge">' + number(valid) + " گیرنده معتبر" + '</span>');
      badges.push(
        '<span class="hst-sms-usage__badge hst-sms-usage__badge--accent">مصرف با ضرایب پنلچی: ' +
          number(units) +
          " پیامک" +
        '</span>'
      );

      if (skipped > 0 || targets > valid) {
        badges.push('<span class="hst-sms-usage__badge hst-sms-usage__badge--warning">' + number(Math.max(skipped, targets - valid)) + " گیرنده بدون شماره معتبر" + '</span>');
      }

      $target
        .removeClass("is-loading is-error")
        .addClass("is-ready")
        .removeAttr("aria-busy")
        .html(badges.join(""));
    }

    function schedule(options = {}) {
      const $target = getTarget(options.target);
      if (!$target.length || !options.action) return;

      const key = $target.attr("id") || options.action;
      const previous = states.get(key) || { serial: 0, timer: null };
      if (previous.timer) window.clearTimeout(previous.timer);
      previous.serial += 1;
      const serial = previous.serial;
      setState($target, "loading", "در حال محاسبه مصرف پیامک...");

      previous.timer = window.setTimeout(async function () {
        try {
          const response = await HST.ajax({ action: options.action, ...(options.data || {}) });
          const latest = states.get(key);
          if (!latest || latest.serial !== serial) return;
          if (response?.success && response?.data?.estimate) {
            render($target, response.data.estimate);
          } else {
            setState($target, "error", HST.getMessage(response, "محاسبه مصرف پیامک انجام نشد."));
          }
        } catch (error) {
          const latest = states.get(key);
          if (!latest || latest.serial !== serial) return;
          setState($target, "error", "محاسبه زنده مصرف پیامک در دسترس نیست.");
        }
      }, Math.max(100, Number(options.delay || 450)));

      states.set(key, previous);
    }

    function clear(target) {
      const $target = getTarget(target);
      if (!$target.length) return;
      const key = $target.attr("id") || "";
      const state = states.get(key);
      if (state?.timer) window.clearTimeout(state.timer);
      states.delete(key);
      setState($target, "loading", "متن پیامک را وارد کنید.");
    }

    return { schedule, render, clear };
  })();

  HST.ajax = function (payload = {}) {
    if (!HST.ajaxUrl) {
      return $.Deferred().reject(new Error("HST ajax_url is not defined.")).promise();
    }

    return $.post(HST.ajaxUrl, {
      nonce: HST.nonce,
      ...payload,
    });
  };

  // Tracks in-flight AJAX actions to prevent duplicate concurrent requests.
  HST._inFlight = HST._inFlight || {};

  /**
   * Disable a trigger element (button/link) while an async operation runs, to
   * prevent double submits. No separate visual spinner is added — the standard
   * full-screen HST.loader overlay is the single, consistent loading indicator
   * across the whole plugin. Returns a restore() function.
   */
  HST.setBusy = function (trigger) {
    const el = trigger && trigger.jquery ? trigger.get(0) : trigger;
    if (!el) return function () {};
    const wasDisabled = el.disabled === true;
    el.disabled = true;
    el.setAttribute("aria-busy", "true");
    return function restore() {
      el.removeAttribute("aria-busy");
      if (!wasDisabled) el.disabled = false;
    };
  };

  HST.request = async function ({
    action,
    data = {},
    confirm = null,
    onSuccess = null,
    reload = false,
    successMessage = false,
    errorMessage = null,
    showLoader = true,
    trigger = null,
    dedupe = true,
  } = {}) {
    if (!action) {
      HST.toast("اکشن درخواست مشخص نیست", "error");
      return null;
    }

    // Prevent duplicate concurrent requests for the same action.
    const dedupeKey = dedupe ? (typeof dedupe === "string" ? dedupe : action) : null;
    if (dedupeKey && HST._inFlight[dedupeKey]) {
      return null;
    }

    if (confirm) {
      const confirmOptions = typeof confirm === "string" ? { text: confirm } : confirm;
      const result = await window.HSTModal.open(confirmOptions);
      if (!result.isConfirmed) return null;
    }

    if (dedupeKey) HST._inFlight[dedupeKey] = true;
    const restoreTrigger = HST.setBusy(trigger);
    if (showLoader) HST.loader.show();

    try {
      const response = await HST.ajax({ action, ...data });

      if (response?.success) {
        if (typeof onSuccess === "function") {
          await onSuccess(response);
        }

        $(document).trigger("hst:request-success", [{
          action: String(action),
          data,
          response,
        }]);

        if (successMessage) {
          HST.toast(
            successMessage === true
              ? HST.getMessage(response, "عملیات با موفقیت انجام شد")
              : HST.getMessage(successMessage, "عملیات با موفقیت انجام شد"),
            "success"
          );
        }

        if (reload) window.location.reload();
        return response;
      }

      HST.toast(HST.getMessage(response, errorMessage || defaults.errorMessage), "error");
      return response;
    } catch (error) {
      console.error("HST AJAX request failed:", error);
      HST.toast(HST.getMessage(error, errorMessage || defaults.networkErrorMessage), "error");
      return null;
    } finally {
      if (showLoader) HST.loader.hide();
      restoreTrigger();
      if (dedupeKey) delete HST._inFlight[dedupeKey];
    }
  };

  // ---- Shared page help dialog --------------------------------------------
  (function initPageHelp() {
    const $modal = $("#hst-page-help-modal");
    const $content = $modal.find("[data-hst-page-help-content]");
    const $title = $modal.find("#hst-page-help-title");
    const template = document.getElementById("hst-page-help-button-template");
    const helpConfig =
      window.hst_ajax_obj && window.hst_ajax_obj.page_help
        ? window.hst_ajax_obj.page_help
        : {};
    let returnFocus = null;
    let frame = null;
    let videoLoadCycle = 0;
    let videoLoadTimer = null;
    let helpLoaderActive = false;

    function showVideoLoader() {
      if (!HST.modalLoading || helpLoaderActive || !$content.length) return;
      helpLoaderActive = true;
      HST.modalLoading.show($content);
    }

    function hideVideoLoader() {
      if (videoLoadTimer) {
        window.clearTimeout(videoLoadTimer);
        videoLoadTimer = null;
      }
      if (!helpLoaderActive || !HST.modalLoading || !$content.length) return;
      helpLoaderActive = false;
      HST.modalLoading.hide($content);
    }

    function buildHelpContent() {
      if (!$content.length || frame) return;

      if (helpConfig.embed_url) {
        frame = document.createElement("iframe");
        frame.setAttribute("data-hst-page-help-frame", "");
        frame.setAttribute("title", helpConfig.title || "ویدئوی راهنمای صفحه");
        frame.setAttribute("width", "100%");
        frame.setAttribute("frameborder", "0");
        frame.setAttribute("scrolling", "no");
        frame.setAttribute("loading", "eager");
        frame.setAttribute("referrerpolicy", "strict-origin-when-cross-origin");
        frame.setAttribute("allow", "autoplay; fullscreen; picture-in-picture; encrypted-media");
        frame.setAttribute("allowfullscreen", "true");
        frame.style.display = "block";
        frame.style.width = "100%";
        frame.style.height = "auto";
        frame.style.aspectRatio = "16 / 9";
        frame.style.border = "0";
      } else if (!$content.children().length) {
        const message = document.createElement("p");
        message.className = "hst-alert hst-empty-state";
        message.textContent = "ویدئوی راهنمای این صفحه هنوز تعیین نشده است.";
        $content.append(message);
      }
    }

    function startVideo() {
      buildHelpContent();
      if (!frame || !helpConfig.embed_url) return;

      const cycle = ++videoLoadCycle;
      showVideoLoader();
      frame.onload = function () {
        if (cycle !== videoLoadCycle) return;
        hideVideoLoader();
      };
      frame.setAttribute("src", helpConfig.embed_url);
      if (!frame.isConnected) {
        $content.append(frame);
      }

      // Prevent a blocked third-party embed from leaving the modal loading state open forever.
      videoLoadTimer = window.setTimeout(function () {
        if (cycle === videoLoadCycle) hideVideoLoader();
      }, 20000);
    }

    function stopVideo() {
      videoLoadCycle += 1;
      hideVideoLoader();
      if (!frame) return;
      frame.onload = null;
      frame.setAttribute("src", "about:blank");
      frame.remove();
    }

    function closeHelp() {
      if (!$modal.length) return;
      stopVideo();
      $modal.removeClass("is-active").prop("hidden", true).attr("aria-hidden", "true");
      if (!$(".hst-modal.is-active, .hst-modal.is-open, .hst-modal[data-open]").length) {
        $("body").removeClass("hst-modal-open");
      }
      if (returnFocus && typeof returnFocus.focus === "function") returnFocus.focus();
      returnFocus = null;
    }

    function openHelp(trigger) {
      if (!$modal.length) return;
      returnFocus = trigger || null;
      if (helpConfig.title) $title.text(helpConfig.title);
      startVideo();
      $modal.prop("hidden", false).addClass("is-active").attr("aria-hidden", "false");
      $("body").addClass("hst-modal-open");
      window.setTimeout(function () {
        $modal.find("[data-hst-page-help-close]").last().trigger("focus");
      }, 30);
    }

    function addHelpButton() {
      if (!template || $("[data-hst-page-help-open]").length) return;

      const $page = $(".hst-shell .hst-page").first();
      if (!$page.length) return;

      const $card = $page.find(".hst-management-card").first();
      let $host = $card.find(
        ".hst-card__body > .hst-stack, .hst-card__body > .hst-inline-filter, .hst-card__body > .hst-schedule-topbar, .hst-card__body > .hst-inline-filter__add"
      ).first();

      const fragment = template.content.cloneNode(true);
      const button = fragment.querySelector("[data-hst-page-help-open]");
      if (!button) return;

      if ($host.length) {
        const $back = $card.find(".hst-inline-filter__back").first();
        if ($back.length) {
          const $navigation = $("<div>", {
            class: "hst-btn-group",
            "data-hst-page-navigation": "",
          });
          $navigation.insertBefore($back);
          $navigation.append(button, $back);
        } else {
          $host.append(button);
        }
        return;
      }

      const $header = ($card.length ? $card : $page.find(".hst-card").first())
        .find(".hst-card__header")
        .first();
      if ($header.length) $header.append(button);
    }

    $(addHelpButton);
    $(document).on("click", "[data-hst-page-help-open]", function () {
      openHelp(this);
    });
    $(document).on("click", "[data-hst-page-help-close]", closeHelp);
    $(document).on("keydown.hstPageHelp", function (event) {
      if (event.key === "Escape" && $modal.hasClass("is-active")) closeHelp();
    });
  })();

  // ---- Reusable SMS-template variable insertion -------------------------
  // SMS modals expose their variables as shared chips. Clicking a chip inserts
  // its token at the current caret position and refreshes the live preview.
  $(document).on("click", "[data-hst-sms-variable][data-hst-sms-target]", function () {
    const variable = String($(this).attr("data-hst-sms-variable") || "");
    const targetSelector = String($(this).attr("data-hst-sms-target") || "");
    const textarea = targetSelector ? document.querySelector(targetSelector) : null;

    if (!variable || !textarea || textarea.tagName !== "TEXTAREA" || textarea.disabled) return;

    const start = Number.isInteger(textarea.selectionStart) ? textarea.selectionStart : textarea.value.length;
    const end = Number.isInteger(textarea.selectionEnd) ? textarea.selectionEnd : start;

    if (typeof textarea.setRangeText === "function") {
      textarea.setRangeText(variable, start, end, "end");
    } else {
      textarea.value = textarea.value.slice(0, start) + variable + textarea.value.slice(end);
      textarea.selectionStart = textarea.selectionEnd = start + variable.length;
    }

    try {
      textarea.focus({ preventScroll: true });
    } catch (error) {
      textarea.focus();
    }
    $(textarea).trigger("input");
  });

  // ---- Arrow-key navigation between marked form fields -------------------
  // Any input/textarea with class `hst-keynav` participates: Up/Down move to
  // the previous/next such field within the SAME form. Left/right are left to
  // the caret. Used by the add/edit student & teacher registration forms.
  jQuery(document).on("keydown", ".hst-keynav", function (e) {
    if (e.key !== "ArrowDown" && e.key !== "ArrowUp") return;
    const form = this.form || this.closest("form") || document;
    const fields = jQuery(form).find(".hst-keynav").filter(function () {
      return !this.disabled && this.type !== "hidden";
    }).toArray();
    const idx = fields.indexOf(this);
    if (idx === -1) return;
    const next = e.key === "ArrowDown" ? idx + 1 : idx - 1;
    if (next < 0 || next >= fields.length) return;
    e.preventDefault();
    const el = fields[next];
    el.focus();
    try { if (typeof el.select === "function") el.select(); } catch (err) {}
  });
})(jQuery, window.HST);
