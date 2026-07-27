jQuery(function ($) {
  "use strict";

  const $page = $("[data-hst-report-cards]").first();
  if (!$page.length) return;

  const $tiles = $page.find("[data-hst-report-card-section]");
  const $panels = $page.find("[data-hst-report-card-panel]");
  const validSections = $panels
    .map(function () {
      return String($(this).attr("data-hst-report-card-panel") || "");
    })
    .get();

  function normalizeSection(value) {
    const section = String(value || "");
    return validSections.indexOf(section) !== -1 ? section : "";
  }

  function sectionFromLocation() {
    try {
      return normalizeSection(
        new URL(window.location.href).searchParams.get("report_card_section")
      );
    } catch (error) {
      return "";
    }
  }

  function updateAddress(section, replace) {
    if (!window.history || !window.history.pushState) return;

    const url = new URL(window.location.href);
    if (section) {
      url.searchParams.set("report_card_section", section);
    } else {
      url.searchParams.delete("report_card_section");
    }

    window.history[replace ? "replaceState" : "pushState"](
      { hstReportCardSection: section },
      "",
      url.toString()
    );
  }

  function showSection(section, options) {
    const settings = Object.assign(
      { updateHistory: false, replaceHistory: false, scroll: false },
      options || {}
    );
    section = normalizeSection(section);

    $panels.each(function () {
      const $panel = $(this);
      const active = String($panel.attr("data-hst-report-card-panel") || "") === section;
      $panel.prop("hidden", !active);
    });

    $tiles.each(function () {
      const $tile = $(this);
      const active = String($tile.attr("data-hst-report-card-section") || "") === section;
      $tile.attr("aria-expanded", active ? "true" : "false");
      if (active) {
        $tile.attr("aria-current", "page");
      } else {
        $tile.removeAttr("aria-current");
      }
    });

    $page.attr("data-hst-active-section", section);

    if (settings.updateHistory) {
      updateAddress(section, settings.replaceHistory);
    }

    if (settings.scroll && section) {
      const panel = $panels
        .filter(`[data-hst-report-card-panel="${section}"]`)
        .get(0);
      if (panel) {
        window.requestAnimationFrame(function () {
          panel.scrollIntoView({ behavior: "smooth", block: "start" });
        });
      }
    }
  }

  $tiles.on("click", function (event) {
    event.preventDefault();
    showSection($(this).attr("data-hst-report-card-section"), {
      updateHistory: true,
      scroll: true,
    });
  });

  $(window).on("popstate.hstReportCards", function () {
    showSection(sectionFromLocation());
  });

  const initialSection = normalizeSection(
    $page.attr("data-hst-initial-section") || sectionFromLocation()
  );
  showSection(initialSection, {
    updateHistory: true,
    replaceHistory: true,
  });

  function normalizeSearchText(value) {
    return String(value || "")
      .replace(/[يكۀة]/g, function (character) {
        return { ي: "ی", ك: "ک", ۀ: "ه", ة: "ه" }[character] || character;
      })
      .replace(/[\u064B-\u065F\u0670]/g, "")
      .replace(/[\u200c\u200e\u200f]/g, " ")
      .replace(/\s+/g, " ")
      .trim()
      .toLocaleLowerCase("fa");
  }

  const reportAccordionMotion = {
    duration: 480,
    directDuration: 560,
    easing: "cubic-bezier(0.22, 1, 0.36, 1)",
  };

  function prefersReducedAccordionMotion() {
    return Boolean(
      window.matchMedia &&
      window.matchMedia("(prefers-reduced-motion: reduce)").matches
    );
  }

  function directAccordionChild(details, selector) {
    if (!details) return null;
    const children = Array.prototype.slice.call(details.children || []);
    return children.find(function (child) {
      return child.matches && child.matches(selector);
    }) || null;
  }

  function clearAccordionMotion(details) {
    if (!details) return;
    details.style.removeProperty("height");
    details.style.removeProperty("overflow");
    details.style.removeProperty("will-change");
    details.classList.remove("is-accordion-opening", "is-accordion-closing");
    details._hstReportAccordionAnimation = null;
  }

  function animateReportAccordion($details, shouldOpen, options) {
    const details = $details && $details.get ? $details.get(0) : null;
    if (!details) return Promise.resolve();

    const summary = directAccordionChild(details, "summary");
    const body = directAccordionChild(details, ".hst-config-accordion__body");
    if (!summary || !body) {
      details.open = Boolean(shouldOpen);
      return Promise.resolve();
    }

    const activeAnimation = details._hstReportAccordionAnimation;
    if (activeAnimation) {
      try {
        activeAnimation.finish();
      } catch (error) {
        try {
          activeAnimation.cancel();
        } catch (cancelError) {
          // The browser may already have released the animation.
        }
      }
    }

    shouldOpen = Boolean(shouldOpen);
    if (details.open === shouldOpen) {
      clearAccordionMotion(details);
      return Promise.resolve();
    }

    if (prefersReducedAccordionMotion() || typeof details.animate !== "function") {
      details.open = shouldOpen;
      clearAccordionMotion(details);
      return Promise.resolve();
    }

    const settings = Object.assign(
      { duration: reportAccordionMotion.duration },
      options || {}
    );
    const startHeight = details.getBoundingClientRect().height;
    const computed = window.getComputedStyle(details);
    const borderHeight =
      (parseFloat(computed.borderTopWidth) || 0) +
      (parseFloat(computed.borderBottomWidth) || 0);

    if (shouldOpen) {
      details.open = true;
      details.classList.add("is-accordion-opening");
      details.classList.remove("is-accordion-closing");
    } else {
      details.classList.add("is-accordion-closing");
      details.classList.remove("is-accordion-opening");
    }

    const endHeight = shouldOpen
      ? details.scrollHeight
      : summary.getBoundingClientRect().height + borderHeight;

    details.style.height = startHeight + "px";
    details.style.overflow = "hidden";
    details.style.willChange = "height";

    const bodyAnimation = body.animate(
      shouldOpen
        ? [
            { opacity: 0, transform: "translateY(-8px)" },
            { opacity: 1, transform: "translateY(0)" },
          ]
        : [
            { opacity: 1, transform: "translateY(0)" },
            { opacity: 0, transform: "translateY(-6px)" },
          ],
      {
        duration: Math.max(260, Number(settings.duration) - 80),
        easing: reportAccordionMotion.easing,
        fill: "both",
      }
    );

    const heightAnimation = details.animate(
      [
        { height: startHeight + "px" },
        { height: endHeight + "px" },
      ],
      {
        duration: Number(settings.duration),
        easing: reportAccordionMotion.easing,
        fill: "both",
      }
    );
    details._hstReportAccordionAnimation = heightAnimation;

    return new Promise(function (resolve) {
      let settled = false;
      function finish() {
        if (settled) return;
        settled = true;
        if (!shouldOpen) details.open = false;
        try {
          bodyAnimation.cancel();
        } catch (error) {
          // Animation cleanup only.
        }
        clearAccordionMotion(details);
        resolve();
      }
      heightAnimation.addEventListener("finish", finish, { once: true });
      heightAnimation.addEventListener("cancel", finish, { once: true });
    });
  }

  $page.on(
    "click.hstReportAccordion",
    "[data-hst-report-period-item] > summary",
    function (event) {
      event.preventDefault();
      const $details = $(this).parent("details");
      animateReportAccordion($details, !$details.prop("open"));
    }
  );

  function focusRequestedPeriod() {
    const requestedKey = String($page.attr("data-hst-initial-period") || "").trim();
    if (!requestedKey) return;

    const $target = $page.find("[data-hst-report-period-item]").filter(function () {
      return String($(this).attr("data-period-key") || "") === requestedKey;
    }).first();
    if (!$target.length) return;

    const $panel = $target.closest("[data-hst-report-card-panel]");
    const section = String($panel.attr("data-hst-report-card-panel") || "");
    if (section) {
      showSection(section, {
        updateHistory: true,
        replaceHistory: true,
      });
    }

    const $filters = $panel.find("[data-hst-report-period-filters]").first();
    $filters.find("[data-hst-report-period-search-filter]").val("");
    $filters.find("[data-hst-report-period-type-filter]").val("");
    $filters.find("[data-hst-report-period-search-filter]").trigger("input");

    $target.prop("hidden", false);
    window.requestAnimationFrame(function () {
      const target = $target.get(0);
      if (!target) return;
      target.scrollIntoView({ behavior: "smooth", block: "center" });
      window.setTimeout(function () {
        animateReportAccordion($target, true, {
          duration: reportAccordionMotion.directDuration,
        });
      }, prefersReducedAccordionMotion() ? 0 : 180);
    });
  }

  $page.find("[data-hst-report-period-filters]").each(function () {
    const $filters = $(this);
    const $scope = $filters.closest("[data-hst-report-card-panel]");
    const $type = $filters.find("[data-hst-report-period-type-filter]").first();
    const $search = $filters.find("[data-hst-report-period-search-filter]").first();
    if (!$type.length || !$search.length || !$scope.length) return;

    function applyPeriodFilters() {
      const periodType = String($type.val() || "");
      const searchQuery = normalizeSearchText($search.val());
      const $items = $scope.find("[data-hst-report-period-item]");
      let visibleCount = 0;

      $items.each(function () {
        const $item = $(this);
        const itemType = String($item.attr("data-period-type") || "");
        const searchableText = normalizeSearchText(
          $item.attr("data-hst-period-search") || $item.text()
        );
        const matchesType = !periodType || itemType === periodType;
        const matchesSearch = !searchQuery || searchableText.indexOf(searchQuery) !== -1;
        const isVisible = matchesType && matchesSearch;

        $item.prop("hidden", !isVisible);
        if (isVisible) visibleCount += 1;
      });

      $scope
        .attr("data-hst-period-type-filter", periodType)
        .attr("data-hst-period-search-filter", searchQuery);

      $scope.find("[data-hst-report-period-count]").text(visibleCount);
      $scope.find("[data-hst-report-period-empty]").prop(
        "hidden",
        !$items.length || visibleCount > 0
      );

      const filterState = {
        type: periodType,
        query: searchQuery,
        visibleCount: visibleCount,
        totalCount: $items.length,
      };

      if (typeof window.CustomEvent === "function") {
        $scope.get(0).dispatchEvent(
          new CustomEvent("hst:report-period-filter", { detail: filterState })
        );
      } else {
        $scope.trigger("hst:report-period-filter", [filterState]);
      }
    }

    $type.on("change", applyPeriodFilters);
    $search.on("input", applyPeriodFilters);
    applyPeriodFilters();
  });

  window.setTimeout(focusRequestedPeriod, 0);

  const defaultManagerMessage = $.trim(
    String($page.attr("data-hst-default-manager-message") ||
      "دانش‌آموز عزیز، تلاش مستمر و مسئولیت‌پذیری تو ارزشمند است. با همین پشتکار مسیر پیشرفت را ادامه بده.")
  );
  const $managerMessageModal = $("#hst-report-manager-message-modal");
  const $managerMessageText = $managerMessageModal.find("[data-hst-manager-message-text]");
  const $managerMessageCount = $managerMessageModal.find("[data-hst-manager-message-count]");
  let $activePeriodItem = $();
  let managerMessageTrigger = null;

  function localizedCount(value) {
    try {
      return Number(value || 0).toLocaleString("fa-IR");
    } catch (error) {
      return String(value || 0);
    }
  }

  function managerMessageOrDefault(message) {
    const normalizedMessage = $.trim(String(message || ""));
    return normalizedMessage || defaultManagerMessage;
  }

  function updateManagerMessageCount() {
    const count = Array.from(String($managerMessageText.val() || "")).length;
    $managerMessageCount.text(localizedCount(count));
  }

  function openManagerMessageModal($periodItem, trigger) {
    if (!$managerMessageModal.length || !$periodItem.length) return;

    $activePeriodItem = $periodItem;
    managerMessageTrigger = trigger || null;

    const currentMessage = managerMessageOrDefault(
      $periodItem.find("[data-hst-manager-message-value]").val()
    );

    $managerMessageText.val(currentMessage);
    updateManagerMessageCount();

    $managerMessageModal
      .prop("hidden", false)
      .addClass("is-active")
      .attr("aria-hidden", "false");
    $("body").addClass("hst-modal-open");
    $reportPreviewModal.find(".hst-modal__body").scrollTop(0).scrollLeft(0);

    window.setTimeout(function () {
      $managerMessageText.trigger("focus");
      const element = $managerMessageText.get(0);
      if (element && typeof element.setSelectionRange === "function") {
        const end = String(element.value || "").length;
        element.setSelectionRange(end, end);
      }
    }, 60);
  }

  function closeManagerMessageModal() {
    if (!$managerMessageModal.length) return;

    $managerMessageModal
      .removeClass("is-active")
      .attr("aria-hidden", "true")
      .prop("hidden", true);

    if (!$(".hst-modal.is-active, .hst-modal.is-open, .hst-modal[data-open]").length) {
      $("body").removeClass("hst-modal-open");
    }

    if (managerMessageTrigger) {
      $(managerMessageTrigger).trigger("focus");
    }

    $activePeriodItem = $();
    managerMessageTrigger = null;
  }

  function syncManagerMessageState($periodItem, message) {
    const normalizedMessage = managerMessageOrDefault(message);
    const isDefaultMessage = normalizedMessage === defaultManagerMessage;
    const $status = $periodItem.find("[data-hst-manager-message-status]").first();
    const $buttonText = $periodItem
      .find("[data-hst-manager-message-button-text]")
      .first();

    $periodItem
      .find("[data-hst-manager-message-value]")
      .val(normalizedMessage);

    $status
      .removeClass("hst-status--danger")
      .addClass("hst-status--success")
      .text(isDefaultMessage ? "پیام پیش‌فرض مدیر فعال است" : "پیام مدیر ثبت شده");

    $buttonText.text("ویرایش پیام مدیر");
  }

  $page.on("click", "[data-hst-manager-message-open]", function () {
    openManagerMessageModal($(this).closest("[data-hst-report-period-item]"), this);
  });

  $managerMessageModal.on("click", "[data-hst-manager-message-close]", function () {
    closeManagerMessageModal();
  });

  $managerMessageText.on("input", updateManagerMessageCount);

  $managerMessageModal.on("click", "[data-hst-manager-message-save]", function () {
    if (!$activePeriodItem.length) {
      closeManagerMessageModal();
      return;
    }

    syncManagerMessageState($activePeriodItem, $managerMessageText.val());
    closeManagerMessageModal();
  });

  $(document).on("keydown.hstReportManagerMessage", function (event) {
    if (!$managerMessageModal.hasClass("is-active")) return;
    if (event.key === "Escape") {
      event.preventDefault();
      closeManagerMessageModal();
    }
  });

  function syncDuplexAvailability($periodItem) {
    const $chartToggle = $periodItem
      .find('input[name$="_comparison_chart"]')
      .first();
    const $duplexToggle = $periodItem
      .find('[data-hst-duplex-toggle]')
      .first();
    const $duplexOption = $periodItem
      .find('[data-hst-duplex-option]')
      .first();

    if (!$duplexToggle.length) return;

    const chartEnabled = $chartToggle.is(":checked");
    const disabledMessage =
      "برای چاپ دوتایی، ابتدا نمودار مقایسه‌ای را غیرفعال کنید.";

    if (chartEnabled) {
      $duplexToggle.prop("checked", false).prop("disabled", true);
      $duplexOption
        .attr("aria-disabled", "true")
        .attr("title", disabledMessage)
        .attr("data-hst-disabled-message", disabledMessage)
        .addClass("is-disabled");
      return;
    }

    $duplexToggle.prop("disabled", false);
    $duplexOption
      .removeAttr("aria-disabled title data-hst-disabled-message")
      .removeClass("is-disabled");
  }

  // Visual report-card options start enabled for periods that do not yet have
  // persisted settings. Duplex is the single exception while the comparison
  // chart is active because both layouts cannot fit on one A4 sheet.
  $page.find("[data-hst-report-period-item]").each(function () {
    const $periodItem = $(this);
    // Report-card accordions always start closed, including after browser
    // back/forward restoration. Users open only the period they need.
    this.open = false;
    $periodItem.removeAttr("open");
    $periodItem.find("[data-hst-report-default-on]").each(function () {
      this.defaultChecked = true;
      $(this).prop("checked", true);
    });
    $periodItem.find("[data-hst-comparison-status]").prop("hidden", false);
    syncManagerMessageState(
      $periodItem,
      $periodItem.find("[data-hst-manager-message-value]").val()
    );
    syncDuplexAvailability($periodItem);
  });

  $page.on("change", "[data-hst-comparison-toggle]", function () {
    const $periodItem = $(this).closest("[data-hst-report-period-item]");
    $periodItem
      .find("[data-hst-comparison-status]")
      .prop("hidden", !$(this).is(":checked"));
    syncDuplexAvailability($periodItem);
  });


  function reportPeriodMeta($periodItem) {
    const type = String($periodItem.attr("data-period-type") || "weekly");
    const supported = type === "weekly" || type === "monthly" || type === "custom";
    const labelFromDom = $.trim(String($periodItem.attr("data-period-type-label") || ""));
    const label = labelFromDom || (type === "monthly" ? "ماهانه" : (type === "custom" ? "اختصاصی" : "هفتگی"));
    const name = $.trim(String($periodItem.attr("data-period-name") || ""));
    return { type: type, label: label, name: name, supported: supported };
  }

  function reportModalTitle(prefix, $periodItem) {
    const meta = reportPeriodMeta($periodItem);
    return prefix + " " + meta.label + (meta.name ? " — " + meta.name : "");
  }

  const $reportPreviewModal = $("#hst-report-card-preview-modal");
  const $reportPreviewHost = $reportPreviewModal.find("[data-hst-report-preview-host]");
  let reportPreviewTrigger = null;

  function formatPreviewScore(value) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return "—";
    return numeric.toLocaleString("fa-IR", {
      minimumFractionDigits: Number.isInteger(numeric) ? 0 : 2,
      maximumFractionDigits: 2,
    });
  }

  function reportPerformanceStatus(value) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return "ثبت نشده";
    if (numeric >= 18) return "خیلی خوب";
    if (numeric >= 15) return "خوب";
    if (numeric >= 10) return "درحدانتظار";
    return "نیاز به تلاش بیشتر";
  }

  function readReportSettings($periodItem) {
    const threshold = Number(
      $periodItem.find('input[name$="_red_below"]').first().val()
    );
    return {
      redBelow: Number.isFinite(threshold) ? threshold : 10,
      showChart: $periodItem
        .find('input[name$="_comparison_chart"]')
        .first()
        .is(":checked"),
      showClassTop: $periodItem
        .find('input[name$="_class_top_scores"]')
        .first()
        .is(":checked"),
      showSchoolTop: $periodItem
        .find('input[name$="_school_top_scores"]')
        .first()
        .is(":checked"),
      managerMessage: managerMessageOrDefault(
        $periodItem.find("[data-hst-manager-message-value]").val()
      ),
    };
  }

  function renderReportQrs($root) {
    $root.find("[data-hst-report-preview-qr]").each(function () {
      const target = this;
      const payload = String(target.getAttribute("data-qr-payload") || "");
      const hasFallbackImage = Boolean(target.querySelector("img"));

      if (
        !payload ||
        typeof window.HSTQRCode !== "function" ||
        !window.HSTQRErrorCorrectLevel
      ) {
        if (!hasFallbackImage) {
          target.innerHTML = '<span class="hst-report-preview-qr__fallback" aria-hidden="true">QR</span>';
        }
        return;
      }

      try {
        const qr = new window.HSTQRCode(
          -1,
          window.HSTQRErrorCorrectLevel.M
        );
        qr.addData(payload);
        qr.make();

        const moduleCount = qr.getModuleCount();
        const quietZone = 4;
        const canvasSize = 320;
        const totalModules = moduleCount + quietZone * 2;
        const moduleSize = canvasSize / totalModules;
        const canvas = document.createElement("canvas");
        canvas.width = canvasSize;
        canvas.height = canvasSize;
        canvas.setAttribute("aria-label", "کد دریافت نسخه دیجیتال");
        const context = canvas.getContext("2d");

        if (!context) throw new Error("Canvas context is unavailable.");

        context.fillStyle = "#ffffff";
        context.fillRect(0, 0, canvasSize, canvasSize);
        context.fillStyle = "#000000";

        for (let row = 0; row < moduleCount; row += 1) {
          for (let column = 0; column < moduleCount; column += 1) {
            if (!qr.isDark(row, column)) continue;
            const x1 = Math.round((column + quietZone) * moduleSize);
            const y1 = Math.round((row + quietZone) * moduleSize);
            const x2 = Math.round((column + quietZone + 1) * moduleSize);
            const y2 = Math.round((row + quietZone + 1) * moduleSize);
            context.fillRect(x1, y1, x2 - x1, y2 - y1);
          }
        }

        target.replaceChildren(canvas);
      } catch (error) {
        console.error("Report-card QR generation failed:", error);
        if (!hasFallbackImage) {
          target.innerHTML = '<span class="hst-report-preview-qr__fallback" aria-hidden="true">QR</span>';
        }
      }
    });
  }

  function applyReportCardSettings($root, settings) {
    settings = settings || {};
    const threshold = Number.isFinite(Number(settings.redBelow))
      ? Number(settings.redBelow)
      : 10;
    const showClassTop = settings.showClassTop !== false;
    const showSchoolTop = settings.showSchoolTop !== false;
    const showChart = settings.showChart !== false;
    const managerMessage = managerMessageOrDefault(settings.managerMessage);

    const $classTop = $root.find("[data-hst-report-preview-class-top]");
    const $schoolTop = $root.find("[data-hst-report-preview-school-top]");
    const $message = $root.find("[data-hst-report-preview-manager-message]");

    const classTopHasRows = $classTop.find("tbody tr").length > 0;
    const schoolTopHasRows = $schoolTop.find("tbody tr").length > 0;

    $classTop.prop("hidden", !showClassTop || !classTopHasRows);
    $schoolTop.prop("hidden", !showSchoolTop || !schoolTopHasRows);
    $message.prop("hidden", false);
    $message.find("[data-hst-report-preview-manager-message-text]").text(managerMessage);

    // Apply the chart option to every card, not merely the first chart inside
    // each A4 page. This is essential for two-up output where a page contains
    // two independent report sheets.
    $root.find("[data-hst-report-preview-chart]").each(function () {
      const $chart = $(this);
      const chartHasContent = $chart.find("svg").length > 0;
      const chartVisible = showChart && chartHasContent;
      $chart.prop("hidden", !chartVisible);
      $chart
        .closest("[data-hst-report-preview-sheet]")
        .toggleClass("is-chart-hidden", !chartVisible);
    });

    $root.find("[data-hst-report-preview-page]").each(function () {
      const $reportPage = $(this);
      const hasVisibleChart = $reportPage
        .find("[data-hst-report-preview-chart]")
        .filter(function () { return !this.hidden; })
        .length > 0;
      $reportPage.toggleClass("is-chart-hidden", !hasVisibleChart);
    });

    $root.find("[data-hst-report-preview-aside]").each(function () {
      const $aside = $(this);
      const hasVisibleBlock = $aside.children().filter(function () {
        return !this.hidden;
      }).length > 0;
      $aside.prop("hidden", !hasVisibleBlock);
      $aside
        .closest("[data-hst-report-preview-content]")
        .toggleClass("is-aside-empty", !hasVisibleBlock);
    });

    $root.find("[data-hst-report-preview-average-row]").each(function () {
      const $row = $(this);
      const rawAverage = String($row.attr("data-average") || "").trim();
      const average = rawAverage === "" ? NaN : Number(rawAverage);
      const averageIsLow = Number.isFinite(average) && average < threshold;

      $row.toggleClass("is-low", averageIsLow);
      $row.find("[data-hst-report-preview-component-average-score]").each(function () {
        const $cell = $(this);
        const rawComponentAverage = String($cell.attr("data-average") || "").trim();
        if (rawComponentAverage === "") {
          $cell.removeClass("is-low").text("محاسبه نمی‌شود");
          return;
        }

        const componentAverage = Number(rawComponentAverage);
        $cell
          .toggleClass("is-low", Number.isFinite(componentAverage) && componentAverage < threshold)
          .text(formatPreviewScore(componentAverage));
      });

      $row
        .find("[data-hst-report-preview-average-status]")
        .text(Number.isFinite(average) ? reportPerformanceStatus(average) : "تعیین نشده");
    });

    $root.find("[data-hst-report-preview-score-row]").each(function () {
      const $row = $(this);
      const absence = String($row.attr("data-absence") || "").trim();
      const rawScore = String($row.attr("data-score") || "").trim();
      const score = rawScore === "" ? NaN : Number(rawScore);

      $row.find("[data-hst-report-preview-component-score]").each(function () {
        const $cell = $(this);
        const componentAbsence = String($cell.attr("data-absence") || "").trim();
        const rawComponentScore = String($cell.attr("data-score") || "").trim();

        if (componentAbsence === "excused") {
          $cell.removeClass("is-low").text("غیبت موجه");
          return;
        }
        if (componentAbsence === "unexcused") {
          $cell.addClass("is-low").text(formatPreviewScore(0));
          return;
        }
        if (rawComponentScore === "") {
          $cell.removeClass("is-low").text("—");
          return;
        }

        const componentScore = Number(rawComponentScore);
        $cell
          .toggleClass("is-low", Number.isFinite(componentScore) && componentScore < threshold)
          .text(formatPreviewScore(componentScore));
      });

      if (absence === "excused") {
        $row.removeClass("is-low");
        $row.find("[data-hst-report-preview-status]").text("محاسبه نمی‌شود");
        return;
      }
      if (absence === "unexcused") {
        $row.addClass("is-low");
        $row.find("[data-hst-report-preview-status]").text(reportPerformanceStatus(0));
        return;
      }
      if (!Number.isFinite(score)) {
        $row.removeClass("is-low");
        $row.find("[data-hst-report-preview-status]").text("ثبت نشده");
        return;
      }

      $row.toggleClass("is-low", score < threshold);
      $row.find("[data-hst-report-preview-status]").text(reportPerformanceStatus(score));
    });

    renderReportQrs($root);
  }

  function applyReportPreviewSettings($periodItem) {
    applyReportCardSettings($reportPreviewModal, readReportSettings($periodItem));
  }

  function showReportPreviewModal() {
    $reportPreviewModal
      .prop("hidden", false)
      .addClass("is-active")
      .attr("aria-hidden", "false");
    $("body").addClass("hst-modal-open");

    window.setTimeout(function () {
      $reportPreviewModal.find("[data-hst-report-preview-close]").last().trigger("focus");
    }, 60);
  }

  async function openReportPreview($periodItem, trigger) {
    if (!$reportPreviewModal.length || !$periodItem.length) return;

    const periodMeta = reportPeriodMeta($periodItem);
    if (!periodMeta.supported) {
      if (window.HST && typeof HST.toast === "function") {
        HST.toast("پیش‌نمایش کارنامه فقط برای دوره‌های هفتگی، ماهانه و اختصاصی فعال است.", "info");
      }
      return;
    }

    const periodId = Number($periodItem.attr("data-period-id") || 0);
    const showChart = $periodItem
      .find('input[name$="_comparison_chart"]')
      .first()
      .is(":checked");
    const duplex = !showChart && $periodItem
      .find('input[name$="_duplex_print"]')
      .first()
      .is(":checked");
    if (!periodId || !window.HST || typeof HST.request !== "function") {
      if (window.HST && typeof HST.toast === "function") {
        HST.toast("اطلاعات دوره برای پیش‌نمایش کامل نیست.", "error");
      }
      return;
    }

    reportPreviewTrigger = trigger || null;
    const response = await HST.request({
      action: "hst_get_report_card_preview",
      data: {
        period_id: periodId,
        duplex: duplex ? 1 : 0,
        show_chart: showChart ? 1 : 0,
      },
      showLoader: true,
      trigger: trigger,
      dedupe: "hst_get_report_card_preview_" + periodId + "_" + (duplex ? "2" : "1"),
    });

    if (!response?.success || !response?.data?.html) {
      reportPreviewTrigger = null;
      return;
    }

    $reportPreviewHost.html(response.data.html);
    $reportPreviewModal
      .find("#hst-report-card-preview-title")
      .text(reportModalTitle("پیش‌نمایش کارنامه", $periodItem));
    applyReportPreviewSettings($periodItem);
    showReportPreviewModal();
  }

  function closeReportPreview() {
    if (!$reportPreviewModal.length) return;

    $reportPreviewModal
      .removeClass("is-active")
      .attr("aria-hidden", "true")
      .prop("hidden", true);

    if (!$('.hst-modal.is-active, .hst-modal.is-open, .hst-modal[data-open]').length) {
      $("body").removeClass("hst-modal-open");
    }

    if (reportPreviewTrigger) {
      $(reportPreviewTrigger).trigger("focus");
    }
    reportPreviewTrigger = null;
  }

  $page.on("click", "[data-hst-report-period-preview]", function () {
    openReportPreview($(this).closest("[data-hst-report-period-item]"), this);
  });

  $reportPreviewModal.on("click", "[data-hst-report-preview-close]", function () {
    closeReportPreview();
  });

  $(document).on("keydown.hstReportPreview", function (event) {
    if (!$reportPreviewModal.hasClass("is-active")) return;
    if (event.key === "Escape") {
      event.preventDefault();
      closeReportPreview();
    }
  });

  const $reportPrintModal = $("#hst-report-card-print-modal");
  const $reportIndividualModal = $("#hst-report-card-individual-modal");
  const $reportPrintGrade = $reportPrintModal.find("[data-hst-report-print-grade]");
  const $reportPrintMajor = $reportPrintModal.find("[data-hst-report-print-major]");
  const $reportPrintClass = $reportPrintModal.find("[data-hst-report-print-class]");
  const $reportPrintCount = $reportPrintModal.find("[data-hst-report-print-count]");
  const $individualSearch = $reportIndividualModal.find("[data-hst-report-individual-search]");
  const $individualBody = $reportIndividualModal.find("[data-hst-report-individual-body]");
  let $printPeriodItem = $();
  let reportPrintTrigger = null;
  let reportReadinessRequest = 0;
  let isReportDownloadRunning = false;
  let reportDownloadCloseTimer = null;

  function readReportPrintClasses() {
    const source = document.getElementById("hst-report-print-classes-data");
    if (!source) return [];
    try {
      const parsed = JSON.parse(source.textContent || "[]");
      return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
      console.error("Report-card class data is invalid:", error);
      return [];
    }
  }

  const reportPrintClasses = readReportPrintClasses();

  function modalOpen($modal, focusSelector) {
    if (!$modal.length) return;
    $modal.prop("hidden", false).addClass("is-active").attr("aria-hidden", "false");
    $("body").addClass("hst-modal-open");
    window.setTimeout(function () {
      const $focus = focusSelector ? $modal.find(focusSelector).first() : $modal.find(".hst-modal__close").first();
      $focus.trigger("focus");
    }, 60);
  }

  function modalClose($modal) {
    if (!$modal.length) return;
    $modal.removeClass("is-active").attr("aria-hidden", "true").prop("hidden", true);
    if (!$(".hst-modal.is-active, .hst-modal.is-open, .hst-modal[data-open]").length) {
      $("body").removeClass("hst-modal-open");
    }
  }

  function ensureReportDownloadProgressModal() {
    let $modal = $("#hst-report-download-progress-modal");
    if ($modal.length) return $modal;

    $modal = $(`
      <div class="hst-modal" data-hst-progress-modal data-hst-modal-size="md" id="hst-report-download-progress-modal" role="dialog" aria-modal="true" aria-labelledby="hst-report-download-progress-title" aria-hidden="true" hidden>
        <div class="hst-modal__backdrop" data-hst-report-progress-close></div>
        <div class="hst-modal__panel">
          <div class="hst-modal__header">
            <div>
              <h3 id="hst-report-download-progress-title">در حال ساخت کارنامه‌های کلاس</h3>
              <p>تولید فایل PDF با کیفیت بالا ممکن است کمی زمان ببرد.</p>
            </div>
            <button type="button" class="hst-modal__close" data-hst-progress-close data-hst-report-progress-close aria-label="بستن">×</button>
          </div>
          <div class="hst-modal__body">
            <div class="hst-operation-progress" id="hst-report-download-progress" aria-live="polite">
              <div class="hst-operation-progress__head">
                <strong class="hst-operation-progress__title">در حال آماده‌سازی</strong>
                <span class="hst-operation-progress__percent">۰٪</span>
              </div>
              <div class="hst-operation-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <span class="hst-operation-progress__bar"></span>
              </div>
              <p class="hst-operation-progress__hint">لطفاً تا شروع دانلود، این صفحه را نبندید.</p>
            </div>
          </div>
          <div class="hst-modal__footer">
            <button type="button" class="hst-btn hst-btn--ghost" data-hst-progress-close data-hst-report-progress-close>بستن</button>
          </div>
        </div>
      </div>
    `);

    $modal.on("click", "[data-hst-report-progress-close]", function () {
      if (isReportDownloadRunning) return;
      modalClose($modal);
    });

    $("body").append($modal);
    return $modal;
  }

  function updateReportDownloadProgress(percent, text, title) {
    const $modal = ensureReportDownloadProgressModal();
    const safePercent = Math.max(0, Math.min(100, parseInt(percent, 10) || 0));
    const localizedPercent = localizedCount(safePercent) + "٪";

    if (title) {
      $modal.find(".hst-operation-progress__title").text(title);
    }
    if (text) {
      $modal.find(".hst-operation-progress__hint").text(text);
    }
    $modal.find(".hst-operation-progress__bar").css("width", safePercent + "%");
    $modal.find(".hst-operation-progress__percent").text(localizedPercent);
    $modal.find(".hst-operation-progress__track").attr("aria-valuenow", String(safePercent));
  }

  function openReportDownloadProgress(mode) {
    const $modal = ensureReportDownloadProgressModal();
    if (reportDownloadCloseTimer) {
      window.clearTimeout(reportDownloadCloseTimer);
      reportDownloadCloseTimer = null;
    }
    const isClass = mode === "class";
    $modal.find("#hst-report-download-progress-title").text(isClass ? "در حال ساخت کارنامه‌های کلاس" : "در حال ساخت کارنامه دانش‌آموز");
    $modal.find(".hst-modal__header p").text("تولید فایل PDF با کیفیت بالا ممکن است کمی زمان ببرد.");
    updateReportDownloadProgress(0, "در حال شروع عملیات...", "در حال آماده‌سازی");
    modalOpen($modal);
    HST.setProgressModalLocked(
      $modal,
      true,
      "ساخت فایل کارنامه هنوز کامل نشده است؛ لطفاً تا شروع دانلود صبر کنید."
    );
    return $modal;
  }

  function finishReportDownloadProgress(success) {
    const $modal = ensureReportDownloadProgressModal();
    HST.setProgressModalLocked($modal, false);

    if (success) {
      updateReportDownloadProgress(100, "فایل آماده شد و دانلود آغاز شد.", "ساخت فایل کامل شد");
      reportDownloadCloseTimer = window.setTimeout(function () {
        modalClose($modal);
        reportDownloadCloseTimer = null;
      }, 700);
      return;
    }

    modalClose($modal);
  }

  function appendOption($select, value, label) {
    $select.append($("<option>", { value: String(value), text: String(label) }));
  }

  function uniqueBy(items, key) {
    const seen = new Set();
    return items.filter(function (item) {
      const value = String(item && item[key] ? item[key] : "");
      if (!value || seen.has(value)) return false;
      seen.add(value);
      return true;
    });
  }

  function availableClasses() {
    const grade = String($reportPrintGrade.val() || "");
    const major = String($reportPrintMajor.val() || "");
    return reportPrintClasses.filter(function (item) {
      return (!grade || String(item.grade) === grade) && (!major || String(item.major) === major);
    });
  }

  function selectedClassIds() {
    const value = Number($reportPrintClass.val() || 0);
    if (value > 0) return [value];
    return availableClasses().map(function (item) { return Number(item.id || 0); }).filter(Boolean);
  }

  async function refreshPrintReadiness(classIds, expectedCount) {
    const requestId = ++reportReadinessRequest;
    const $classButton = $reportPrintModal.find("[data-hst-report-print-class-pdf]");
    const $individualButton = $reportPrintModal.find("[data-hst-report-print-individual-open]");

    $classButton
      .prop("disabled", true)
      .attr("aria-busy", expectedCount > 0 ? "true" : "false")
      .attr("title", expectedCount > 0 ? "در حال بررسی تکمیل نمرات..." : "دانش‌آموزی برای دریافت کارنامه وجود ندارد.");
    $individualButton.prop("disabled", expectedCount < 1);

    if (!$printPeriodItem.length || expectedCount < 1 || !classIds.length) return;

    const response = await HST.request({
      action: "hst_report_card_print_students",
      data: {
        period_id: Number($printPeriodItem.attr("data-period-id") || 0),
        class_ids: classIds,
      },
      showLoader: false,
      dedupe: false,
    });

    if (requestId !== reportReadinessRequest) return;
    $classButton.attr("aria-busy", "false");
    if (!response?.success) {
      $classButton
        .prop("disabled", true)
        .attr("title", "بررسی تکمیل نمرات انجام نشد.");
      return;
    }

    const readyCount = Number(response.data?.ready_count || 0);
    const allReady = response.data?.all_ready === true;
    $classButton
      .prop("disabled", !allReady)
      .attr(
        "title",
        allReady
          ? "دریافت کارنامه کلاس"
          : "تا تعیین تکلیف همه نمرات دانش‌آموزان، دریافت کارنامه کلاس امکان‌پذیر نیست."
      );
    $individualButton
      .prop("disabled", expectedCount < 1)
      .attr(
        "title",
        readyCount > 0
          ? "مشاهده و دریافت کارنامه‌های آماده"
          : "هنوز هیچ کارنامه‌ای آماده دریافت نیست؛ وضعیت نمرات در فهرست نمایش داده می‌شود."
      );
  }

  function updatePrintCount() {
    const classIds = selectedClassIds();
    const lookup = new Set(classIds.map(String));
    const count = reportPrintClasses.reduce(function (total, item) {
      return lookup.has(String(item.id)) ? total + Number(item.student_count || 0) : total;
    }, 0);
    const classCount = classIds.length;
    $reportPrintCount.text(
      count > 0
        ? `${localizedCount(count)} دانش‌آموز از ${localizedCount(classCount)} کلاس بر اساس فیلترهای بالا آماده چاپ گروهی هستند.`
        : "دانش‌آموزی برای فیلترهای انتخاب‌شده وجود ندارد."
    );
    refreshPrintReadiness(classIds, count);
  }

  function refreshClassOptions() {
    const classes = availableClasses();
    const gradeLabel = $reportPrintGrade.find("option:selected").text();
    const majorLabel = $reportPrintMajor.find("option:selected").text();
    $reportPrintClass.empty();
    if (classes.length) {
      appendOption($reportPrintClass, "0", "همه");
      classes.forEach(function (item) {
        appendOption($reportPrintClass, item.id, item.name);
      });
    } else {
      appendOption($reportPrintClass, "", "کلاس مرتبطی یافت نشد");
    }
    updatePrintCount();
  }

  function refreshMajorOptions() {
    const grade = String($reportPrintGrade.val() || "");
    const majors = uniqueBy(
      reportPrintClasses.filter(function (item) { return !grade || String(item.grade) === grade; }),
      "major"
    );
    $reportPrintMajor.empty();
    majors.forEach(function (item) {
      appendOption($reportPrintMajor, item.major, item.major_label);
    });
    refreshClassOptions();
  }

  function initializePrintFilters() {
    const grades = uniqueBy(reportPrintClasses, "grade");
    $reportPrintGrade.empty();
    grades.forEach(function (item) {
      appendOption($reportPrintGrade, item.grade, item.grade_label);
    });
    refreshMajorOptions();
  }

  function collectReportSettings() {
    return readReportSettings($printPeriodItem);
  }

  function periodPrintPayload(mode, extra) {
    const settings = collectReportSettings();
    return Object.assign({
      period_id: Number($printPeriodItem.attr("data-period-id") || 0),
      mode: mode,
      class_ids: selectedClassIds(),
      duplex:
        mode === "individual" || settings.showChart
          ? 0
          : ($printPeriodItem.find('input[name$="_duplex_print"]').first().is(":checked") ? 1 : 0),
      red_below: settings.redBelow,
      show_chart: settings.showChart ? 1 : 0,
      show_class_top: settings.showClassTop ? 1 : 0,
      show_school_top: settings.showSchoolTop ? 1 : 0,
      manager_message: settings.managerMessage,
    }, extra || {});
  }

  async function downloadReportCards(mode, trigger, extra) {
    if (!$printPeriodItem.length || !window.HSTPrint || typeof HSTPrint.reportCardPdf !== "function") {
      HST.toast("سامانه ساخت PDF کارنامه در دسترس نیست.", "error");
      return;
    }
    if (isReportDownloadRunning) {
      HST.toast("ساخت فایل کارنامه در حال انجام است؛ لطفاً تا شروع دانلود صبر کنید.", "info");
      return;
    }

    const isClassDownload = mode === "class";
    const restoreTrigger = HST.setBusy(trigger);
    let $stage = $();
    let completed = false;

    isReportDownloadRunning = true;
    openReportDownloadProgress(mode);
    updateReportDownloadProgress(
      2,
      isClassDownload ? "در حال دریافت اطلاعات کارنامه‌های کلاس..." : "در حال دریافت اطلاعات کارنامه دانش‌آموز...",
      "دریافت اطلاعات"
    );

    try {
      const response = await HST.request({
        action: "hst_report_card_print_data",
        data: periodPrintPayload(mode, extra),
        showLoader: false,
        trigger: null,
        dedupe: false,
      });
      if (!response?.success || !response?.data?.html) return;

      updateReportDownloadProgress(8, "اطلاعات دریافت شد؛ در حال آماده‌سازی صفحات...", "آماده‌سازی صفحات");

      const settings = collectReportSettings();
      $stage = $("<div>", {
        class: "hst-shell hst-report-pdf-stage",
        "aria-hidden": "true",
      }).html(response.data.html).appendTo(document.body);

      applyReportCardSettings($stage, settings);
      updateReportDownloadProgress(10, "در حال آماده‌سازی تصاویر، فونت‌ها و چیدمان کارنامه‌ها...", "آماده‌سازی فایل PDF");

      await HSTPrint.reportCardPdf({
        root: $stage[0],
        filename: response.data.filename || ("کارنامه-" + reportPeriodMeta($printPeriodItem).label + ".pdf"),
        onProgress: function (percent, text) {
          const normalized = Math.max(0, Math.min(100, Number(percent) || 0));
          const mapped = Math.min(99, 10 + Math.round(normalized * 0.89));
          updateReportDownloadProgress(mapped, text || "در حال ساخت صفحات کارنامه...", "در حال ساخت فایل PDF");
        },
      });

      completed = true;
      HST.toast("فایل PDF کارنامه‌ها ایجاد شد.", "success");
    } catch (error) {
      console.error("Report-card PDF generation failed:", error);
      HST.toast("ساخت فایل PDF کارنامه انجام نشد.", "error");
    } finally {
      $stage.remove();
      finishReportDownloadProgress(completed);
      isReportDownloadRunning = false;
      restoreTrigger();
    }
  }

  function openReportPrintModal($periodItem, trigger) {
    if (!$periodItem.length) return;
    const periodMeta = reportPeriodMeta($periodItem);
    if (!periodMeta.supported) {
      HST.toast("چاپ کارنامه فقط برای دوره‌های هفتگی، ماهانه و اختصاصی فعال است.", "info");
      return;
    }
    if (!reportPrintClasses.length) {
      HST.toast("در سال تحصیلی فعال کلاس دارای دانش‌آموزی وجود ندارد.", "warning");
      return;
    }

    $printPeriodItem = $periodItem;
    reportPrintTrigger = trigger || null;
    initializePrintFilters();
    $reportPrintModal
      .find("[data-hst-report-print-title]")
      .text(reportModalTitle("امکانات پیشرفته چاپ کارنامه", $periodItem));
    modalOpen($reportPrintModal, "[data-hst-report-print-grade]");
  }

  function closeReportPrintModal() {
    reportReadinessRequest++;
    modalClose($reportPrintModal);
    if (reportPrintTrigger) $(reportPrintTrigger).trigger("focus");
  }

  function individualStudentIdentity(student) {
    const safeName = HST.escapeHtml(student.name || "دانش‌آموز");
    const safeNationalCode = HST.escapeHtml(student.national_code || "");
    const initials = HST.escapeHtml(
      student.initials || HST.initials(student.name || "", student.first_name || "", student.last_name || "")
    );
    const avatar = student.avatar_url
      ? '<span class="hst-user-avatar"><img src="' + HST.escapeHtml(student.avatar_url) + '" alt="' + safeName + '" loading="lazy"></span>'
      : '<span class="hst-user-avatar hst-user-avatar--placeholder" aria-label="بدون تصویر پروفایل؛ حروف اول نام ' + safeName + '"><span class="hst-user-avatar__placeholder">' + initials + '</span></span>';

    return '<div class="hst-user-id hst-report-user-id">' +
      avatar +
      '<span class="hst-user-id__name">' +
        '<strong>' + safeName + '</strong>' +
        '<small>' + safeNationalCode + '</small>' +
      '</span>' +
    '</div>';
  }

  function renderIndividualStudents(students) {
    const individualStudents = Array.isArray(students) ? students : [];
    $individualBody.empty();
    individualStudents.forEach(function (student, index) {
      const search = normalizeSearchText([
        student.name,
        student.father_name,
        student.national_code,
        student.class_name,
      ].join(" "));
      const $row = $("<tr>").attr("data-hst-search", search);
      $("<td>").text(localizedCount(index + 1)).appendTo($row);
      $("<td>").addClass("hst-col-fill").html(individualStudentIdentity(student)).appendTo($row);
      $("<td>").text(student.father_name || "—").appendTo($row);
      $("<td>").text(student.class_name || "—").appendTo($row);
      const reportReady = student.report_ready === true || Number(student.report_ready || 0) === 1;
      const readinessMessage = String(student.readiness_message || "").trim() ||
        "تا تعیین تکلیف همه نمرات، دریافت کارنامه امکان‌پذیر نیست.";
      const $download = $("<button>", {
        type: "button",
        class: "hst-btn hst-btn--ghost hst-btn--sm hst-btn--icon",
        title: reportReady ? "دریافت کارنامه" : readinessMessage,
        "aria-label": reportReady
          ? `دریافت کارنامه ${student.name || "دانش‌آموز"}`
          : `کارنامه ${student.name || "دانش‌آموز"} هنوز آماده دریافت نیست`,
        disabled: !reportReady,
      })
        .attr("data-hst-report-individual-download", "1")
        .attr("data-student-id", String(student.id || 0))
        .attr("data-class-id", String(student.class_id || 0))
        .attr("aria-disabled", reportReady ? "false" : "true")
        .html('<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="M8 11l4 4 4-4"/><path d="M4 19h16"/></svg>');
      $("<td>").addClass("hst-actions").append($download).appendTo($row);
      $individualBody.append($row);
    });

    if (window.HST && typeof HST.refreshInlineFilter === "function") {
      HST.refreshInlineFilter("hst-report-individual-table");
    }
  }

  async function openIndividualPrintModal(trigger) {
    const classIds = selectedClassIds();
    if (!classIds.length) {
      HST.toast("ابتدا کلاس موردنظر را انتخاب کنید.", "warning");
      return;
    }
    const response = await HST.request({
      action: "hst_report_card_print_students",
      data: {
        period_id: Number($printPeriodItem.attr("data-period-id") || 0),
        class_ids: classIds,
      },
      showLoader: true,
      trigger: trigger,
      dedupe: false,
    });
    if (!response?.success) return;

    $individualSearch.val("");
    renderIndividualStudents(response.data?.students || []);
    modalClose($reportPrintModal);
    $reportIndividualModal
      .find("[data-hst-report-individual-title]")
      .text(reportModalTitle("چاپ کارنامه انفرادی", $printPeriodItem));
    modalOpen($reportIndividualModal, "[data-hst-report-individual-search]");
  }

  function closeIndividualPrintModal() {
    modalClose($reportIndividualModal);
    if (reportPrintTrigger) $(reportPrintTrigger).trigger("focus");
  }

  $(window).off("beforeunload.hstReportCardDownload").on("beforeunload.hstReportCardDownload", function () {
    if (isReportDownloadRunning) {
      return "ساخت فایل کارنامه هنوز کامل نشده است.";
    }
  });

  $reportPrintGrade.on("change", refreshMajorOptions);
  $reportPrintMajor.on("change", refreshClassOptions);
  $reportPrintClass.on("change", updatePrintCount);

  $page.on("click", "[data-hst-report-period-print]", function () {
    openReportPrintModal($(this).closest("[data-hst-report-period-item]"), this);
  });
  $reportPrintModal.on("click", "[data-hst-report-print-close]", closeReportPrintModal);
  $reportPrintModal.on("click", "[data-hst-report-print-class-pdf]", function () {
    downloadReportCards("class", this);
  });
  $reportPrintModal.on("click", "[data-hst-report-print-individual-open]", function () {
    openIndividualPrintModal(this);
  });
  $reportIndividualModal.on("click", "[data-hst-report-individual-close]", closeIndividualPrintModal);
  $reportIndividualModal.on("click", "[data-hst-report-individual-download]", function () {
    downloadReportCards("individual", this, {
      student_id: Number($(this).attr("data-student-id") || 0),
      student_class_id: Number($(this).attr("data-class-id") || 0),
    });
  });

  $(document).on("keydown.hstReportPrint", function (event) {
    if (event.key !== "Escape") return;
    if ($reportIndividualModal.hasClass("is-active")) {
      event.preventDefault();
      closeIndividualPrintModal();
    } else if ($reportPrintModal.hasClass("is-active")) {
      event.preventDefault();
      closeReportPrintModal();
    }
  });

});
