jQuery(function ($) {
  "use strict";

  const esc = window.HST && HST.escapeHtml
    ? HST.escapeHtml
    : function (value) {
        return String(value || "")
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/>/g, "&gt;")
          .replace(/"/g, "&quot;")
          .replace(/'/g, "&#039;");
      };

  function waitForBrowserPaint() {
    return new Promise(function (resolve) {
      const raf = window.requestAnimationFrame || function (callback) { return window.setTimeout(callback, 16); };
      raf(function () {
        raf(resolve);
      });
    });
  }

  async function withExamDownloadLoader(task, options) {
    const progress = HST.operationProgress
      ? HST.operationProgress.open(Object.assign({
          title: "در حال ساخت فایل آزمون",
          subtitle: "صفحه‌بندی و تولید PDF با کیفیت بالا ممکن است کمی زمان ببرد.",
          percent: 3,
          text: "در حال آماده‌سازی سوالات آزمون...",
          lockMessage: "ساخت فایل آزمون هنوز کامل نشده است؛ لطفاً صبر کنید.",
        }, options || {}))
      : null;

    if (!progress && window.HST && HST.loader) HST.loader.show();
    await waitForBrowserPaint();
    try {
      const result = await task(progress);
      if (progress) progress.complete("فایل آزمون آماده شد و دانلود آغاز شد.");
      return result;
    } catch (error) {
      if (progress) progress.fail("ساخت فایل آزمون انجام نشد.");
      throw error;
    } finally {
      if (!progress && window.HST && HST.loader) {
        await waitForBrowserPaint();
        HST.loader.hide();
      }
    }
  }

  function removeLegacyUpcomingExamsCard() {
    const legacyTitles = [
      "آزمون‌های پیش‌روی من",
      "آزمون های پیش روی من",
      "آزمون‌های پیش روی من",
      "آزمون های پیش‌روی من",
    ];

    $(".hst-card").each(function () {
      const $card = $(this);
      const title = String(
        $card.find(".hst-card__header h3, .hst-card__header h2").first().text() || ""
      )
        .replace(/\s+/g, " ")
        .trim();

      if (legacyTitles.indexOf(title) !== -1) {
        $card.remove();
      }
    });
  }

  function initManagerHub() {
    const $page = $("[data-hst-exams-manager]");
    if (!$page.length) return;

    const $tiles = $page.find("[data-hst-exam-section]");
    const $panels = $page.find("[data-hst-exam-section-panel]");
    const sections = $panels
      .map(function () {
        return String($(this).attr("data-hst-exam-section-panel") || "");
      })
      .get();
    const staleSections = new Set();
    const staleSectionsByAction = {
      hst_exams_create_builder: ["management", "question-bank", "reports"],
      hst_exams_save: ["management", "question-bank", "reports"],
      hst_exams_delete: ["management", "question-bank", "reports"],
      hst_exam_question_save: ["question-bank"],
      hst_exam_question_delete: ["question-bank"],
      hst_exam_question_blueprint_save: ["question-bank"],
      hst_exam_questions_transfer: ["management", "question-bank", "reports"],
    };
    let currentSection = String($page.attr("data-hst-initial-section") || "");

    function normalizedSection(value) {
      value = String(value || "");
      return sections.indexOf(value) !== -1 ? value : "";
    }

    function sectionFromLocation() {
      try {
        return normalizedSection(new URL(window.location.href).searchParams.get("exam_section"));
      } catch (error) {
        return "";
      }
    }

    function updateAddress(section, replace) {
      if (!window.history || !window.history.pushState) return;
      const url = new URL(window.location.href);
      if (section) {
        url.searchParams.set("exam_section", section);
      } else {
        url.searchParams.delete("exam_section");
      }
      window.history[replace ? "replaceState" : "pushState"](
        { hstExamSection: section },
        "",
        url.toString()
      );
    }

    function refreshSection(section, href) {
      const targetUrl = href ? new URL(href, window.location.href) : new URL(window.location.href);
      if (section) {
        targetUrl.searchParams.set("exam_section", section);
      } else {
        targetUrl.searchParams.delete("exam_section");
      }
      if (window.HST && HST.loader) HST.loader.show();
      window.location.assign(targetUrl.toString());
    }

    function showSection(section, options) {
      const opts = Object.assign(
        { updateHistory: false, replaceHistory: false, scroll: false },
        options || {}
      );
      section = normalizedSection(section);
      currentSection = section;

      $panels.each(function () {
        const $panel = $(this);
        const isActive = String($panel.attr("data-hst-exam-section-panel")) === section;
        $panel.prop("hidden", !isActive);
      });

      $tiles.each(function () {
        const $tile = $(this);
        const isActive = String($tile.attr("data-hst-exam-section")) === section;
        if (isActive) {
          $tile.attr("aria-current", "page");
        } else {
          $tile.removeAttr("aria-current");
        }
      });

      $page.attr("data-hst-active-section", section);

      if (opts.updateHistory) {
        updateAddress(section, opts.replaceHistory);
      }

      if (opts.scroll && section) {
        const $target = $panels.filter(
          `[data-hst-exam-section-panel="${section}"]`
        );
        if ($target.length) {
          window.requestAnimationFrame(function () {
            $target.get(0).scrollIntoView({ behavior: "smooth", block: "start" });
          });
        }
      }
    }

    $tiles.on("click", function (event) {
      event.preventDefault();
      const section = normalizedSection($(this).attr("data-hst-exam-section"));
      if (section && staleSections.has(section)) {
        refreshSection(section, $(this).attr("href"));
        return;
      }
      showSection(section, {
        updateHistory: true,
        scroll: true,
      });
    });

    $(document).on("hst:request-success.hstExamsManager", function (_event, detail) {
      const action = String(detail && detail.action ? detail.action : "");
      const affectedSections = staleSectionsByAction[action] || [];
      affectedSections.forEach(function (section) {
        if (sections.indexOf(section) !== -1) staleSections.add(section);
      });
    });

    $page.on("click", "[data-hst-exams-back]", function (event) {
      event.preventDefault();
      event.stopImmediatePropagation();

      if (currentSection) {
        showSection("", { updateHistory: true });
        const hub = $page.find(".hst-dashboard").get(0);
        if (hub) hub.scrollIntoView({ behavior: "smooth", block: "center" });
        return;
      }

      const dashboardUrl = String($(this).attr("data-hst-dashboard-url") || "/dashboard");
      window.location.assign(dashboardUrl);
    });

    $(window).on("popstate.hstExams", function () {
      const section = sectionFromLocation();
      if (section && staleSections.has(section)) {
        refreshSection(section);
        return;
      }
      showSection(section);
    });

    showSection(normalizedSection(currentSection || sectionFromLocation()), {
      updateHistory: true,
      replaceHistory: true,
    });
  }

  function initExamGeneralSettings() {
    const $form = $("#hst-exam-general-settings-form");
    if (!$form.length) return;

    $form.on("submit", function (event) {
      event.preventDefault();

      const $button = $form.find("[data-hst-exam-settings-submit]");
      const maxAttempts = parseInt($form.find('[name="max_attempts"]').val(), 10);
      if (!Number.isFinite(maxAttempts) || maxAttempts < 1 || maxAttempts > 10) {
        HST.toast("حداکثر دفعات تلاش باید بین ۱ تا ۱۰ باشد.", "error");
        return;
      }
      const autoGrading = $form.find('[name="auto_grading"]').is(":checked");
      if (
        !autoGrading
        && (
          $form.find('[name="negative_marking"]').is(":checked")
          || $form.find('[name="instant_results"]').is(":checked")
        )
      ) {
        HST.toast("برای نمره منفی یا نمایش فوری نتیجه، ابتدا تصحیح خودکار را فعال کنید.", "error");
        return;
      }

      $button.prop("disabled", true);
      HST.loader.show();

      HST.ajax({
        action: "hst_exams_save_general_settings",
        negative_marking: $form.find('[name="negative_marking"]').is(":checked") ? 1 : 0,
        instant_results: $form.find('[name="instant_results"]').is(":checked") ? 1 : 0,
        strict_time_limit: $form.find('[name="strict_time_limit"]').is(":checked") ? 1 : 0,
        auto_grading: autoGrading ? 1 : 0,
        max_attempts: maxAttempts,
      })
        .done(function (response) {
          if (!response || !response.success) {
            HST.toast(HST.getMessage(response, "ذخیره تنظیمات آزمون انجام نشد."), "error");
            return;
          }
          const settings = response.data && response.data.settings && typeof response.data.settings === "object"
            ? response.data.settings
            : {};
          $(document).trigger("hst:exam-general-settings-updated", [settings]);
          HST.toast(HST.getMessage(response, "تنظیمات عمومی آزمون ذخیره شد."), "success");
        })
        .fail(function (xhr) {
          HST.toast(HST.getMessage(xhr, "ارتباط با سرور برقرار نشد."), "error");
        })
        .always(function () {
          HST.loader.hide();
          $button.prop("disabled", false);
        });
    });
  }

  function initBuilderForm() {
    const $form = $("#hst-exam-builder-form");
    if (!$form.length) return;

    const lessonsByClass = window.HST_EXAM_LESSONS || {};
    const $grade = $form.find('[name="grade"]');
    const $major = $form.find('[name="major"]');
    const $class = $form.find("#hst-exam-builder-class");
    const $lesson = $form.find("#hst-exam-builder-lesson");
    const classOptions = $class.find("option[data-grade][data-major]").map(function () {
      return {
        value: String($(this).val() || ""),
        label: String($(this).text() || ""),
        grade: String($(this).attr("data-grade") || ""),
        major: String($(this).attr("data-major") || ""),
      };
    }).get();
    const $steps = $form.find("[data-hst-exam-step]");
    let $editingRow = $();
    const defaultStartTime = String($form.attr("data-default-start-time") || "08:00");
    const defaultEndTime = String($form.attr("data-default-end-time") || "09:30");
    let defaultAttemptLimit = Math.max(1, Math.min(10, Number($form.attr("data-default-attempt-limit") || 1)));
    let defaultResultVisibility = String($form.attr("data-default-result-visibility") || "after_end");
    let autoGradingEnabled = String($form.attr("data-auto-grading") || "0") === "1";
    const $deliveryMode = $form.find('[name="delivery_mode"]');
    const $onlineOnly = $form.find("[data-hst-online-only]");
    const $resultVisibility = $form.find('[name="result_visibility"]');

    function syncResultVisibility() {
      const $afterSubmit = $resultVisibility.find('option[value="after_submit"]');
      $afterSubmit.prop("disabled", !autoGradingEnabled);
      if (!autoGradingEnabled && String($resultVisibility.val() || "") === "after_submit") {
        $resultVisibility.val("after_end");
      }
    }

    function setBuilderMode(isEditing) {
      $form.find("#hst-exam-builder-title").text(isEditing ? "ویرایش آزمون" : "ایجاد آزمون جدید");
      $form.find("[data-hst-exam-builder-submit]").text(isEditing ? "ذخیره تغییرات" : "ثبت آزمون");
    }

    function fillBuilderLessons(classId) {
      const lessons = lessonsByClass[classId] || [];
      let html = '<option value="">انتخاب درس</option>';
      lessons.forEach(function (lesson) {
        html += `<option value="${esc(lesson.id)}">${esc(lesson.lesson_name)}</option>`;
      });
      $lesson.html(html).prop("disabled", !lessons.length);
    }

    function filterBuilderClasses(selectedClassId = "") {
      const grade = String($grade.val() || "");
      const major = String($major.val() || "");
      let html = "";

      if (!grade || !major) {
        html = '<option value="">ابتدا پایه و رشته تحصیلی را انتخاب کنید</option>';
        $class.html(html).prop("disabled", true);
        fillBuilderLessons("");
        return;
      }

      const matches = classOptions.filter(function (item) {
        return item.grade === grade && item.major === major;
      });

      if (!matches.length) {
        html = '<option value="">کلاس مرتبطی یافت نشد</option>';
        $class.html(html).prop("disabled", true);
        fillBuilderLessons("");
        return;
      }

      html = '<option value="">انتخاب کلاس</option>';
      matches.forEach(function (item) {
        html += `<option value="${esc(item.value)}">${esc(item.label)}</option>`;
      });
      $class.html(html).prop("disabled", false);

      if (selectedClassId && matches.some(function (item) { return item.value === String(selectedClassId); })) {
        $class.val(String(selectedClassId));
      }
      fillBuilderLessons(String($class.val() || ""));
    }

    function toggleOnlineOptions() {
      const isOnline = String($deliveryMode.val() || "") === "online";
      $onlineOnly.each(function () {
        const $item = $(this);
        $item.prop("hidden", !isOnline);
        $item.find("input, select, textarea, button").prop("disabled", !isOnline);
      });
    }

    function jalaliComparable(value, timeValue) {
      if (!window.HSTJalaliDatepicker || typeof HSTJalaliDatepicker.parse !== "function") return null;
      const parsed = HSTJalaliDatepicker.parse(value);
      if (!parsed) return null;
      const timeParts = String(timeValue || "00:00").split(":");
      const hour = Math.max(0, Math.min(23, parseInt(timeParts[0], 10) || 0));
      const minute = Math.max(0, Math.min(59, parseInt(timeParts[1], 10) || 0));
      return (((parsed.year * 100 + parsed.month) * 100 + parsed.day) * 10000) + (hour * 100) + minute;
    }

    function validateSchedule() {
      if (!window.HSTJalaliDatepicker || typeof HSTJalaliDatepicker.today !== "function") return true;
      const startDate = String($form.find('[name="start_date"]').val() || "");
      const endDate = String($form.find('[name="end_date"]').val() || "");
      const startTime = String($form.find('[name="start_time"]').val() || "00:00");
      const endTime = String($form.find('[name="end_time"]').val() || "00:00");
      const now = HSTJalaliDatepicker.today();
      const nowKey =
        (((now.year * 100 + now.month) * 100 + now.day) * 10000) +
        ((Number(now.hour) || 0) * 100) +
        (Number(now.minute) || 0);
      const startKey = jalaliComparable(startDate, startTime);
      const endKey = jalaliComparable(endDate, endTime);
      if (startKey === null || endKey === null) return true;
      if (startKey < nowKey) {
        HST.toast("زمان شروع آزمون نمی‌تواند قبل از زمان فعلی باشد.", "error");
        $form.find('[name="start_time"]').trigger("focus");
        return false;
      }
      if (endKey <= startKey) {
        HST.toast("زمان پایان آزمون باید بعد از زمان شروع باشد.", "error");
        $form.find('[name="end_date"]').trigger("focus");
        return false;
      }
      return true;
    }

    function validateStep(step) {
      const $step = $steps.filter(`[data-hst-exam-step="${step}"]`);
      let firstInvalid = null;

      $step.find("[required]").each(function () {
        if (this.disabled) return;
        const value = String($(this).val() || "").trim();
        const number = this.type === "number" ? Number(value) : null;
        const invalidNumber =
          this.type === "number" &&
          (!Number.isFinite(number) ||
            (this.min !== "" && number < Number(this.min)) ||
            (this.max !== "" && number > Number(this.max)));

        if (!value || invalidNumber) {
          firstInvalid = firstInvalid || this;
        }
      });

      if (firstInvalid) {
        if (window.HST && typeof HST.toast === "function") {
          HST.toast("لطفاً همه فیلدهای الزامی این مرحله را به‌درستی تکمیل کنید.", "error");
        }
        firstInvalid.focus();
        return false;
      }

      if (Number(step) === 2 && !validateSchedule()) return false;
      return true;
    }

    function setStep(step) {
      $steps.each(function () {
        $(this).prop(
          "hidden",
          String($(this).attr("data-hst-exam-step")) !== String(step)
        );
      });

      const card = $form.closest(".hst-card").get(0);
      if (card) {
        window.requestAnimationFrame(function () {
          card.scrollIntoView({ behavior: "smooth", block: "start" });
        });
      }

    }

    $grade.add($major).on("change", function () {
      filterBuilderClasses("");
    });
    filterBuilderClasses("");

    $class.on("change", function () {
      fillBuilderLessons(String($(this).val() || ""));
    });

    $deliveryMode.on("change", toggleOnlineOptions);
    toggleOnlineOptions();
    syncResultVisibility();

    $(document).on("hst:exam-general-settings-updated.hstExamBuilder", function (_event, settings) {
      const values = settings && typeof settings === "object" ? settings : {};
      defaultAttemptLimit = Math.max(1, Math.min(10, Number(values.max_attempts || 1)));
      defaultResultVisibility = Number(values.instant_results || 0) === 1 ? "after_submit" : "after_end";
      autoGradingEnabled = Number(values.auto_grading || 0) === 1;
      $form.attr("data-default-attempt-limit", String(defaultAttemptLimit));
      $form.attr("data-default-result-visibility", defaultResultVisibility);
      $form.attr("data-auto-grading", autoGradingEnabled ? "1" : "0");
      syncResultVisibility();
      if (!$editingRow.length && !$form.find('[name="id"]').val()) {
        $form.find('[name="attempt_limit"]').val(String(defaultAttemptLimit));
        $form.find('[name="result_visibility"]').val(defaultResultVisibility);
      }
    });

    $form.on("click", "[data-hst-exam-next]", function () {
      if (validateStep(1)) setStep(2);
    });

    $form.on("click", "[data-hst-exam-prev]", function () {
      setStep(1);
    });

    $form.on("click", "[data-hst-date-target]", function () {
      const name = String($(this).attr("data-hst-date-target") || "");
      const $input = $form.find(`[name="${name}"]`);
      $input.trigger("focus").trigger("click");
    });

    $(document).on("click", '[data-hst-exam-management-action="edit"]', function () {
      const $row = $(this).closest("tr");
      if (!$row.length) return;

      $editingRow = $row;
      $form.find('[name="id"]').val(String($row.attr("data-id") || ""));
      $form.find('[name="title"]').val(String($row.attr("data-title") || ""));
      $grade.val(String($row.attr("data-grade") || ""));
      $major.val(String($row.attr("data-major") || ""));

      const classId = String($row.attr("data-class-id") || "");
      const lessonId = String($row.attr("data-lesson-id") || "");
      filterBuilderClasses(classId);
      $lesson.val(lessonId);

      $form.find('[name="exam_type"]').val(String($row.attr("data-exam-type") || ""));
      $form.find('[name="delivery_mode"]').val(String($row.attr("data-delivery-mode") || ""));
      toggleOnlineOptions();
      $form.find('[name="duration_minutes"]').val(String($row.attr("data-duration") || "90"));
      $form.find('[name="question_count"]').val(String($row.attr("data-question-count") || "20"));
      $form.find('[name="start_date"]').val(String($row.attr("data-start-date") || ""));
      $form.find('[name="end_date"]').val(String($row.attr("data-end-date") || ""));
      $form.find('[name="start_time"]').val(String($row.attr("data-start-time") || "08:00"));
      $form.find('[name="end_time"]').val(String($row.attr("data-end-time") || "10:00"));
      $form.find('[name="attempt_limit"]').val(String($row.attr("data-attempt-limit") || "1"));
      const storedResultVisibility = String($row.attr("data-result-visibility") || "after_end");
      $resultVisibility.val(storedResultVisibility === "after_submit" && autoGradingEnabled ? "after_submit" : "after_end");

      [
        "randomize_questions",
        "randomize_options",
        "record_exit_time",
        "ip_restriction",
      ].forEach(function (name) {
        $form
          .find(`[name="${name}"]`)
          .prop("checked", String($row.attr(`data-${name.replace(/_/g, "-")}`) || "0") === "1");
      });

      setBuilderMode(true);
      $('[data-hst-exam-section="builder"]').first().trigger("click");
      setStep(1);
    });

    $form.on("submit", async function (event) {
      event.preventDefault();
      if (!validateStep(1)) {
        setStep(1);
        return;
      }
      if (!validateStep(2)) {
        setStep(2);
        return;
      }

      const submitter = event.originalEvent && event.originalEvent.submitter
        ? event.originalEvent.submitter
        : $form.find('[type="submit"]').get(0);
      const data = {};
      $form.serializeArray().forEach(function (item) {
        data[item.name] = item.value;
      });
      const isOnlineSubmission = String($deliveryMode.val() || "") === "online";
      [
        "randomize_questions",
        "randomize_options",
        "record_exit_time",
        "ip_restriction",
      ].forEach(function (name) {
        data[name] = isOnlineSubmission && $form.find(`[name="${name}"]`).is(":checked") ? 1 : 0;
      });

      const response = await HST.request({
        action: "hst_exams_create_builder",
        data,
        trigger: submitter,
        successMessage: true,
        reload: false,
      });

      if (response && response.success) {
        if ($editingRow.length) {
          const title = String($form.find('[name="title"]').val() || "");
          const lesson = String($lesson.find("option:selected").text() || "—");
          const className = String($class.find("option:selected").text() || "—");
          const examType = String($form.find('[name="exam_type"] option:selected').text() || "—");
          const delivery = String($form.find('[name="delivery_mode"] option:selected').text() || "—");
          const date = String($form.find('[name="start_date"]').val() || "—");

          const examTypeValue = String($form.find('[name="exam_type"]').val() || "");
          const deliveryValue = String($form.find('[name="delivery_mode"]').val() || "");
          const checkboxValue = function (name) {
            return deliveryValue === "online" && $form.find(`[name="${name}"]`).is(":checked") ? "1" : "0";
          };
          const searchText = [title, lesson, className, examType, delivery, String($editingRow.attr("data-view-teacher") || "")].join(" ");

          $editingRow.attr({
            "data-title": title,
            "data-grade": String($form.find('[name="grade"]').val() || ""),
            "data-major": String($form.find('[name="major"]').val() || ""),
            "data-class-id": String($class.val() || ""),
            "data-lesson-id": String($lesson.val() || ""),
            "data-exam-type": examTypeValue,
            "data-delivery-mode": deliveryValue,
            "data-duration": String($form.find('[name="duration_minutes"]').val() || ""),
            "data-question-count": String($form.find('[name="question_count"]').val() || ""),
            "data-start-date": date,
            "data-end-date": String($form.find('[name="end_date"]').val() || ""),
            "data-start-time": String($form.find('[name="start_time"]').val() || ""),
            "data-end-time": String($form.find('[name="end_time"]').val() || ""),
            "data-attempt-limit": deliveryValue === "online" ? String($form.find('[name="attempt_limit"]').val() || "1") : "1",
            "data-result-visibility": deliveryValue === "online" ? String($form.find('[name="result_visibility"]').val() || "after_end") : "manual",
            "data-randomize-questions": checkboxValue("randomize_questions"),
            "data-randomize-options": checkboxValue("randomize_options"),
            "data-record-exit-time": checkboxValue("record_exit_time"),
            "data-ip-restriction": checkboxValue("ip_restriction"),
            "data-hst-search": searchText,
            "data-view-title": title,
            "data-view-lesson": lesson,
            "data-view-class": className,
            "data-view-date": date,
            "data-view-type": examType,
            "data-view-delivery": delivery,
          });
          $editingRow.find('[data-hst-exam-cell="title"]').text(title);
          $editingRow.find('[data-hst-exam-cell="lesson"]').text(lesson);
          $editingRow.find('[data-hst-exam-cell="class"]').text(className);
          $editingRow.find('[data-hst-exam-cell="date"]').text(date);
          $editingRow.find('[data-hst-exam-cell="delivery"]').text(delivery);
          $editingRow
            .find('[data-hst-exam-cell="type"]')
            .removeClass("hst-status--success hst-status--warning hst-status--info")
            .addClass(examTypeValue === "continuous" ? "hst-status--success" : (examTypeValue === "midterm" ? "hst-status--warning" : "hst-status--info"))
            .text(examType);
          const isInPerson = deliveryValue === "in_person";
          $editingRow.find('[data-hst-exam-management-action="preview"]')
            .prop("disabled", isInPerson)
            .attr("aria-disabled", isInPerson ? "true" : "false");
          if (isInPerson) {
            $editingRow.attr("data-view-participation", "۱۰۰٪");
            $editingRow.find(".hst-vstack small").text("۱۰۰٪");
            $editingRow.find(".hst-progress").attr("data-status", "complete").find(".hst-progress__bar").css("width", "100%");
          } else {
            const participants = Number($editingRow.attr("data-participants") || 0);
            const eligible = Number($editingRow.attr("data-eligible") || 0);
            const percent = eligible > 0 ? Math.min(100, Math.round((participants / eligible) * 100)) : 0;
            const participationText = `${faNumber(participants)} / ${faNumber(eligible)}`;
            $editingRow.attr("data-view-participation", participationText);
            $editingRow.find(".hst-vstack small").text(participationText);
            $editingRow.find(".hst-progress")
              .attr("data-status", percent >= 100 ? "complete" : (percent > 0 ? "partial" : "missing"))
              .find(".hst-progress__bar").css("width", `${percent}%`);
          }
          $('[data-hst-inline-filter="hst-exam-management-table"] [data-hst-inline-search]').trigger("input");
        }

        $editingRow = $();
        $form.get(0).reset();
        $form.find('[name="id"]').val("");
        $form.find('[name="start_time"]').val(defaultStartTime);
        $form.find('[name="end_time"]').val(defaultEndTime);
        $form.find('[name="attempt_limit"]').val(String(defaultAttemptLimit));
        $resultVisibility.val(defaultResultVisibility);
        syncResultVisibility();
        filterBuilderClasses("");
        toggleOnlineOptions();
        setBuilderMode(false);
        setStep(1);
      }
    });
  }

  function initManagerExamList() {
    const $table = $("#hst-exam-management-table");
    if (!$table.length) return;

    const $modal = $("#hst-exam-management-view-modal");
    const $empty = $("[data-hst-exam-management-empty]");
    const $wrap = $("[data-hst-exam-management-table-wrap]");
    const $filter = $('[data-hst-inline-filter="hst-exam-management-table"]');
    const $managerPage = $("[data-hst-exams-manager]");
    const $managerShell = $("[data-hst-exam-manager-shell]");
    const $managementOverview = $("[data-hst-exam-management-overview]");
    const $onlinePreview = $("[data-hst-exam-online-preview]");
    const $previewFinishModal = $("#hst-online-exam-preview-finish-modal");
    const previewTypeLabels = {
      multiple_choice: "تستی",
      fill_blank: "جای خالی",
      true_false: "صحیح / غلط",
      short_answer: "کوتاه پاسخ",
      essay: "تشریحی",
    };
    const previewDifficultyLabels = {
      easy: "آسان",
      medium: "متوسط",
      hard: "سخت",
      conceptual: "مفهومی",
    };
    let onlinePreviewState = null;
    let onlinePreviewTimer = 0;

    function faNumber(value) {
      return Number(value || 0).toLocaleString("fa-IR");
    }

    function faDigits(value) {
      return String(value == null ? "" : value).replace(/\d/g, (digit) => "۰۱۲۳۴۵۶۷۸۹"[Number(digit)]);
    }

    function faScore(value) {
      const rounded = Math.round((Number(value) || 0) * 100) / 100;
      return faDigits(String(rounded).replace(".", "٫"));
    }

    function shuffledCopy(items) {
      const result = Array.isArray(items) ? items.slice() : [];
      for (let index = result.length - 1; index > 0; index -= 1) {
        const target = Math.floor(Math.random() * (index + 1));
        const value = result[index];
        result[index] = result[target];
        result[target] = value;
      }
      return result;
    }

    function normalizedPreviewQuestions(payload) {
      const exam = payload && payload.exam && typeof payload.exam === "object" ? payload.exam : {};
      let rows = payload && Array.isArray(payload.questions) ? payload.questions.slice() : [];
      if (Number(exam.randomizeQuestions || 0) === 1) rows = shuffledCopy(rows);

      return rows.map(function (row, index) {
        const type = String(row.question_type || "");
        const data = row.answer_data && typeof row.answer_data === "object" ? row.answer_data : {};
        let choices = [];
        if (type === "multiple_choice") {
          choices = (Array.isArray(data.choices) ? data.choices : []).slice(0, 4).map(function (text, choiceIndex) {
            return { key: `choice-${choiceIndex}`, text: String(text || "") };
          });
          if (Number(exam.randomizeOptions || 0) === 1) choices = shuffledCopy(choices);
        }
        return {
          id: `preview-question-${index + 1}`,
          number: index + 1,
          type,
          difficulty: String(row.difficulty || "medium"),
          score: Number(row.score || 0),
          questionText: String(row.question_text || ""),
          answerData: data,
          choices,
          blankCount: type === "fill_blank" ? Math.max(1, Array.isArray(data.answers) ? data.answers.length : 1) : 0,
        };
      });
    }

    function previewAnswerIsComplete(question, answer) {
      if (!question) return false;
      if (question.type === "fill_blank") {
        return Array.isArray(answer)
          && answer.length >= question.blankCount
          && answer.slice(0, question.blankCount).every((value) => String(value || "").trim() !== "");
      }
      return String(answer == null ? "" : answer).trim() !== "";
    }

    function previewAnsweredCount() {
      if (!onlinePreviewState) return 0;
      return onlinePreviewState.questions.reduce(function (count, question) {
        return count + (previewAnswerIsComplete(question, onlinePreviewState.answers[question.id]) ? 1 : 0);
      }, 0);
    }

    function previewComparableText(value) {
      return String(value == null ? "" : value)
        .normalize("NFKC")
        .replace(/[يى]/g, "ی")
        .replace(/ك/g, "ک")
        .replace(/[ۀة]/g, "ه")
        .replace(/[\u200c\u200e\u200f]/g, " ")
        .replace(/\s+/g, " ")
        .trim()
        .toLocaleLowerCase("fa-IR");
    }

    function previewQuestionGrade(question, answer) {
      const score = Math.max(0, Number(question && question.score ? question.score : 0));
      const data = question && question.answerData && typeof question.answerData === "object"
        ? question.answerData
        : {};
      if (!question || question.type === "essay") {
        return { gradable: false, answered: previewAnswerIsComplete(question, answer), score, earned: 0 };
      }

      const answered = previewAnswerIsComplete(question, answer);
      let correct = false;
      if (answered && question.type === "multiple_choice") {
        correct = String(answer) === `choice-${Number(data.correct_index)}`;
      } else if (answered && question.type === "true_false") {
        correct = String(answer) === String(data.correct || "");
      } else if (answered && question.type === "fill_blank") {
        const expected = Array.isArray(data.answers) ? data.answers : [];
        const actual = Array.isArray(answer) ? answer : [];
        correct = expected.length > 0
          && expected.length === actual.length
          && expected.every(function (value, index) {
            return previewComparableText(value) === previewComparableText(actual[index]);
          });
      } else if (answered && question.type === "short_answer") {
        correct = previewComparableText(answer) === previewComparableText(data.answer);
      }

      let earned = correct ? score : 0;
      if (
        answered
        && !correct
        && question.type === "multiple_choice"
        && Number(onlinePreviewState && onlinePreviewState.exam ? onlinePreviewState.exam.negativeMarking : 0) === 1
      ) {
        earned = -(score / 3);
      }

      return { gradable: true, answered, correct, score, earned };
    }

    function previewAutomaticGrade(reason) {
      if (!onlinePreviewState || Number(onlinePreviewState.exam.autoGrading || 0) !== 1) return "";

      const visibility = String(onlinePreviewState.exam.resultVisibility || "after_end");
      const hasEnded = reason === "timeout" || Boolean(onlinePreviewState.overtime);
      const canShow = visibility === "after_submit" || (visibility === "after_end" && hasEnded);
      if (!canShow || visibility === "manual") return "نتیجه طبق شیوه انتشار انتخاب‌شده در این مرحله نمایش داده نمی‌شود.";

      let earned = 0;
      let gradableScore = 0;
      let manualCount = 0;
      onlinePreviewState.questions.forEach(function (question) {
        const result = previewQuestionGrade(question, onlinePreviewState.answers[question.id]);
        if (!result.gradable) {
          manualCount += 1;
          return;
        }
        gradableScore += result.score;
        earned += result.earned;
      });
      earned = Math.max(0, earned);
      const detail = `نمره خودکار: ${faScore(earned)} از ${faScore(gradableScore)}`;
      return manualCount > 0 ? `${detail}؛ ${faNumber(manualCount)} سؤال نیازمند تصحیح دبیر است.` : detail;
    }

    function previewTimerText(totalSeconds) {
      const seconds = Math.max(0, Number(totalSeconds || 0));
      const hours = Math.floor(seconds / 3600);
      const minutes = Math.floor((seconds % 3600) / 60);
      const remainder = seconds % 60;
      const parts = hours > 0
        ? [hours, minutes, remainder]
        : [Math.floor(seconds / 60), remainder];
      return faDigits(parts.map((value) => String(value).padStart(2, "0")).join(":"));
    }

    function updateOnlinePreviewTimer() {
      if (!onlinePreviewState) return;
      $onlinePreview.find("[data-hst-online-preview-timer]").text(previewTimerText(onlinePreviewState.secondsLeft));
    }

    function stopOnlinePreviewTimer() {
      if (onlinePreviewTimer) window.clearInterval(onlinePreviewTimer);
      onlinePreviewTimer = 0;
    }

    function openPreviewFinishModal(reason) {
      if (!onlinePreviewState || !$previewFinishModal.length) return;
      const total = onlinePreviewState.questions.length;
      const answered = previewAnsweredCount();
      const unanswered = Math.max(0, total - answered);
      const automaticGrade = previewAutomaticGrade(reason);
      $previewFinishModal.find("[data-hst-online-preview-finish-summary]").text(
        `تعداد کل سؤالات: ${faNumber(total)} | پاسخ داده شده: ${faNumber(answered)} | بدون پاسخ: ${faNumber(unanswered)}${automaticGrade ? ` | ${automaticGrade}` : ""}`
      );
      $previewFinishModal
        .find(".hst-modal__body > p:first-child")
        .text(reason === "timeout"
          ? "زمان شبیه‌سازی آزمون به پایان رسید. این بخش فقط پیش‌نمایش مدیریتی است و هیچ پاسخی برای دانش‌آموز ثبت نمی‌شود."
          : "این بخش فقط پیش‌نمایش مدیریتی است و هیچ پاسخی برای دانش‌آموز ثبت نمی‌شود. برای پایان‌دادن به پیش‌نمایش مطمئن هستید؟");
      $previewFinishModal.addClass("is-active").attr("aria-hidden", "false");
      $("body").addClass("hst-modal-open");
      $previewFinishModal.find("[data-hst-online-preview-finish-confirm]").trigger("focus");
    }

    function closePreviewFinishModal() {
      $previewFinishModal.removeClass("is-active").attr("aria-hidden", "true");
      if (!$(".hst-modal.is-active, .hst-modal.is-open, .hst-modal[data-open]").length) {
        $("body").removeClass("hst-modal-open");
      }
    }

    function startOnlinePreviewTimer() {
      stopOnlinePreviewTimer();
      updateOnlinePreviewTimer();
      onlinePreviewTimer = window.setInterval(function () {
        if (!onlinePreviewState) return;
        onlinePreviewState.secondsLeft = Math.max(0, onlinePreviewState.secondsLeft - 1);
        updateOnlinePreviewTimer();
        if (onlinePreviewState.secondsLeft === 0) {
          stopOnlinePreviewTimer();
          if (Number(onlinePreviewState.exam.strictTimeLimit || 0) === 1) {
            onlinePreviewState.expired = true;
            $onlinePreview.find("[data-hst-online-preview-answer] :input").prop("disabled", true);
            openPreviewFinishModal("timeout");
          } else {
            onlinePreviewState.overtime = true;
            HST.toast("زمان آزمون پایان یافت؛ به‌دلیل غیرفعال بودن محدودیت سخت‌گیرانه، پاسخ‌گویی همچنان ممکن است.", "warning");
          }
        }
      }, 1000);
    }

    function updateOnlinePreviewProgress() {
      if (!onlinePreviewState) return;
      const total = onlinePreviewState.questions.length;
      const answered = previewAnsweredCount();
      const percent = total > 0 ? Math.round((answered / total) * 100) : 0;
      $onlinePreview.find("[data-hst-online-preview-progress-text]").text(`${faNumber(answered)} از ${faNumber(total)} سؤال`);
      $onlinePreview.find("[data-hst-online-preview-progress-bar]").css("width", `${percent}%`).closest(".hst-progress")
        .attr("data-status", percent >= 100 ? "complete" : (percent > 0 ? "partial" : "missing"));
      $onlinePreview.find("[data-hst-online-preview-jump]").each(function () {
        const index = Number($(this).attr("data-hst-online-preview-jump") || 0);
        const question = onlinePreviewState.questions[index];
        $(this).toggleClass("is-answered", previewAnswerIsComplete(question, question ? onlinePreviewState.answers[question.id] : ""));
      });
    }

    function renderOnlinePreviewQuestionList() {
      if (!onlinePreviewState) return;
      const html = onlinePreviewState.questions.map(function (question, index) {
        return `<button type="button" class="hst-btn hst-btn--soft hst-btn--sm${index === onlinePreviewState.currentIndex ? " is-current" : ""}" data-hst-online-preview-jump="${index}" aria-label="رفتن به سؤال ${question.number}">${faNumber(question.number)}</button>`;
      }).join("");
      $onlinePreview.find("[data-hst-online-preview-question-list]").html(html);
      updateOnlinePreviewProgress();
    }

    function previewOptionMarkup(question, key, label, number, checked, wide) {
      const name = `hst-online-preview-answer-${question.id}`;
      return `<label class="hst-exam-online-preview__option${wide ? " is-wide" : ""}" dir="auto">`
        + `<input type="radio" name="${esc(name)}" value="${esc(key)}" data-hst-online-preview-answer-choice${checked ? " checked" : ""}>`
        + `<span class="hst-exam-online-preview__option-number">${faNumber(number)}</span>`
        + `<span>${esc(label)}</span>`
        + `</label>`;
    }

    function renderOnlinePreviewAnswer(question) {
      const answer = onlinePreviewState.answers[question.id];
      if (question.type === "multiple_choice") {
        const stackChoices = question.choices.some(function (choice) {
          return String(choice && choice.text ? choice.text : "").replace(/\s+/g, " ").trim().length > 42;
        });
        return `<div class="hst-exam-online-preview__options${stackChoices ? " is-stacked" : ""}">${question.choices.map(function (choice, index) {
          return previewOptionMarkup(question, choice.key, choice.text, index + 1, String(answer || "") === choice.key, stackChoices);
        }).join("")}</div>`;
      }
      if (question.type === "true_false") {
        return `<div class="hst-exam-online-preview__options">`
          + previewOptionMarkup(question, "true", "صحیح", 1, String(answer || "") === "true", false)
          + previewOptionMarkup(question, "false", "غلط", 2, String(answer || "") === "false", false)
          + `</div>`;
      }
      if (question.type === "fill_blank") {
        const values = Array.isArray(answer) ? answer : [];
        return `<div class="hst-exam-online-preview__blank-fields">${Array.from({ length: question.blankCount }, function (_, index) {
          const label = question.blankCount > 1 ? `پاسخ جای خالی ${faNumber(index + 1)}` : "پاسخ جای خالی";
          return `<label class="hst-field"><span>${label}</span><input type="text" dir="auto" autocomplete="off" value="${esc(values[index] || "")}" data-hst-online-preview-answer-blank="${index}" placeholder="پاسخ خود را وارد کنید"></label>`;
        }).join("")}</div>`;
      }
      if (question.type === "essay") {
        return `<label class="hst-field"><span>پاسخ تشریحی سؤال</span><textarea rows="7" dir="auto" data-hst-online-preview-answer-text placeholder="پاسخ تشریحی خود را بنویسید">${esc(answer || "")}</textarea></label>`;
      }
      return `<label class="hst-field"><span>پاسخ کوتاه سؤال</span><input type="text" dir="auto" autocomplete="off" value="${esc(answer || "")}" data-hst-online-preview-answer-text placeholder="پاسخ کوتاه خود را وارد کنید"></label>`;
    }

    function renderOnlinePreviewQuestion() {
      if (!onlinePreviewState) return;
      const total = onlinePreviewState.questions.length;
      const index = Math.max(0, Math.min(total - 1, onlinePreviewState.currentIndex));
      onlinePreviewState.currentIndex = index;
      const question = onlinePreviewState.questions[index];
      if (!question) return;

      $onlinePreview.find("[data-hst-online-preview-number]").text(`سؤال ${faNumber(index + 1)} از ${faNumber(total)}`);
      $onlinePreview.find("[data-hst-online-preview-type]").text(`نوع: ${previewTypeLabels[question.type] || "سؤال"}`);
      $onlinePreview.find("[data-hst-online-preview-difficulty]").text(`سطح: ${previewDifficultyLabels[question.difficulty] || "متوسط"}`);
      $onlinePreview.find("[data-hst-online-preview-score]").text(`بارم: ${faDigits(String(question.score).replace(".", "٫"))} نمره`);
      $onlinePreview.find("[data-hst-online-preview-question-text]").html(question.questionText || "—");
      $onlinePreview.find("[data-hst-online-preview-answer]").html(renderOnlinePreviewAnswer(question));
      if (onlinePreviewState.expired) {
        $onlinePreview.find("[data-hst-online-preview-answer] :input").prop("disabled", true);
      }
      $onlinePreview.find("[data-hst-online-preview-prev]").prop("disabled", index <= 0);
      $onlinePreview.find("[data-hst-online-preview-next]").prop("disabled", index >= total - 1);
      $onlinePreview.find("[data-hst-online-preview-jump]").removeClass("is-current").filter(`[data-hst-online-preview-jump="${index}"]`).addClass("is-current").attr("aria-current", "step").siblings().removeAttr("aria-current");
    }

    function showOnlinePreviewQuestion(index) {
      if (!onlinePreviewState) return;
      onlinePreviewState.currentIndex = Math.max(0, Math.min(onlinePreviewState.questions.length - 1, Number(index || 0)));
      renderOnlinePreviewQuestion();
      const card = $onlinePreview.find(".hst-exam-online-preview__question-card").get(0);
      if (card && window.matchMedia("(max-width: 760px)").matches) card.scrollIntoView({ behavior: "smooth", block: "start" });
    }

    function closeOnlineExamPreview(showMessage) {
      stopOnlinePreviewTimer();
      closePreviewFinishModal();
      onlinePreviewState = null;
      $onlinePreview.prop("hidden", true);
      $managementOverview.prop("hidden", false);
      $managerShell.prop("hidden", false);
      $managerPage.removeClass("is-online-exam-preview");
      if (showMessage) HST.toast("پیش‌نمایش آزمون بسته شد؛ هیچ پاسخی ذخیره نشد.", "info");
      const managementPanel = $("[data-hst-exam-section-panel=\"management\"]").get(0);
      if (managementPanel) managementPanel.scrollIntoView({ behavior: "smooth", block: "start" });
    }

    function openOnlineExamPreview(payload) {
      const exam = payload && payload.exam && typeof payload.exam === "object" ? payload.exam : {};
      if (String(exam.deliveryMode || "") !== "online") {
        HST.toast("پیش‌نمایش تعاملی فقط برای آزمون غیر حضوری در دسترس است.", "warning");
        return;
      }
      const questions = normalizedPreviewQuestions(payload);
      if (!questions.length) {
        HST.toast("برای این آزمون هنوز سؤالی ثبت نشده است.", "warning");
        return;
      }

      onlinePreviewState = {
        exam,
        questions,
        answers: {},
        currentIndex: 0,
        secondsLeft: Math.max(60, Number(exam.duration || 0) * 60),
        expired: false,
        overtime: false,
      };

      $managerShell.prop("hidden", true);
      $managementOverview.prop("hidden", true);
      $onlinePreview.prop("hidden", false);
      $managerPage.addClass("is-online-exam-preview");
      $onlinePreview.find("[data-hst-online-preview-title]").text(String(exam.title || "آزمون غیر حضوری"));
      $onlinePreview.find("[data-hst-online-preview-lesson]").text(String(exam.lessonName || "—"));
      $onlinePreview.find("[data-hst-online-preview-class]").text(String(exam.className || "—"));
      $onlinePreview.find("[data-hst-online-preview-duration]").text(faNumber(Number(exam.duration || 0)));
      $onlinePreview.find(":input").prop("disabled", false);
      renderOnlinePreviewQuestionList();
      renderOnlinePreviewQuestion();
      startOnlinePreviewTimer();
      const previewElement = $onlinePreview.get(0);
      if (previewElement) previewElement.scrollIntoView({ behavior: "smooth", block: "start" });
    }

    function paperAnswerText(question) {
      const type = String(question.question_type || "");
      const data = question.answer_data && typeof question.answer_data === "object" ? question.answer_data : {};
      if (type === "multiple_choice") {
        const choices = Array.isArray(data.choices) ? data.choices : [];
        const correct = Number(data.correct_index);
        return `گزینه ${faDigits(correct + 1)} (${String(choices[correct] || "—")})`;
      }
      if (type === "true_false") {
        return `گزینه ${String(data.correct || "true") === "false" ? "۲ (غلط)" : "۱ (صحیح)"}`;
      }
      if (type === "fill_blank") {
        const answers = Array.isArray(data.answers) ? data.answers.filter((item) => String(item || "").trim()) : [];
        return `جاهای خالی به ترتیب: ${answers.join("، ") || "—"}`;
      }
      if (type === "short_answer") return `پاسخ کوتاه مورد انتظار: ${String(data.answer || "—")}`;
      return `پاسخ تشریحی / راهنمای بارم‌بندی: ${String(data.guide || "—")}`;
    }

    function managementPaperPayload(data, kind) {
      const exam = data && data.exam && typeof data.exam === "object" ? data.exam : {};
      const rows = data && Array.isArray(data.questions) ? data.questions : [];
      const questions = rows.map(function (row, index) {
        const answerData = row.answer_data && typeof row.answer_data === "object" ? row.answer_data : {};
        return {
          number: Number(row.number || index + 1),
          score: Number(row.score || 0),
          type: String(row.question_type || ""),
          question: $("<div>").html(String(row.question_text || "")).text().replace(/\s+/g, " ").trim(),
          answer: paperAnswerText(row),
          choices: Array.isArray(answerData.choices) ? answerData.choices.slice(0, 4) : [],
        };
      });
      const title = String(exam.title || "آزمون").replace(/\s+/g, "-");
      return {
        kind,
        exam: Object.assign({}, exam, {
          schoolName: String((window.hstPrintConfig || {}).schoolName || document.title || "مدرسه"),
          managerName: String((window.hstPrintConfig || {}).managerName || "مدیر مدرسه"),
        }),
        questions,
        filename: `${kind === "answers" ? "راهنمای-تصحیح" : "نمونه-سوال"}-${title}.pdf`,
      };
    }

    function refreshStats() {
      const $rows = $table.find("tbody > tr").not(".hst-inline-filter-empty-row, .hst-filter-empty-row, .hst-table-empty-row");
      let active = 0;
      let waiting = 0;
      let done = 0;
      let participants = 0;
      let eligible = 0;

      $rows.each(function () {
        const $row = $(this);
        const runtime = String($row.attr("data-runtime") || "waiting");
        if (runtime === "active") active += 1;
        else if (runtime === "done") done += 1;
        else if (runtime !== "cancelled") waiting += 1;
        if (String($row.attr("data-delivery-mode") || "") === "in_person") {
          participants += 1;
          eligible += 1;
        } else {
          participants += Number($row.attr("data-participants") || 0);
          eligible += Number($row.attr("data-eligible") || 0);
        }
      });

      const average = eligible > 0 ? Math.min(100, Math.round((participants / eligible) * 100)) : 0;
      $('[data-hst-exam-stat="total"]').text(faNumber($rows.length));
      $('[data-hst-exam-stat="active"]').text(faNumber(active));
      $('[data-hst-exam-stat="waiting"]').text(faNumber(waiting));
      $('[data-hst-exam-stat="done"]').text(faNumber(done));
      $('[data-hst-exam-stat="average"]').text(`${faNumber(average)}٪`);

      const isEmpty = $rows.length === 0;
      $empty.prop("hidden", !isEmpty);
      $wrap.prop("hidden", isEmpty);
      if (!isEmpty && $filter.length) {
        $filter.find("[data-hst-inline-select]").trigger("change");
      }
    }

    function openView($row) {
      ["title", "lesson", "class", "teacher", "date", "delivery", "type", "participation", "exits", "status"].forEach(function (field) {
        $modal
          .find(`[data-hst-exam-view-field="${field}"]`)
          .text(String($row.attr(`data-view-${field}`) || "—"));
      });
      $modal.addClass("is-active").attr("aria-hidden", "false");
      $("body").addClass("hst-modal-open");
      $modal.find("[data-hst-exam-management-view-close]").last().trigger("focus");
    }

    function closeView() {
      $modal.removeClass("is-active").attr("aria-hidden", "true");
      if (!$(".hst-modal.is-active, .hst-modal.is-open, .hst-modal[data-open]").length) {
        $("body").removeClass("hst-modal-open");
      }
    }

    $(document).on("click", "[data-hst-exam-management-view-close]", closeView);
    $(document).on("keydown.hstExamManagementView", function (event) {
      if (event.key === "Escape" && $modal.hasClass("is-active")) closeView();
    });

    $(document).on("click", "[data-hst-exam-management-action]", async function () {
      const $button = $(this);
      const action = String($button.attr("data-hst-exam-management-action") || "");
      const $row = $button.closest("tr");
      const id = Number($row.attr("data-id") || 0);
      if (!id) return;

      if (action === "view") {
        openView($row);
        return;
      }

      if (action === "report") {
        openView($row);
        return;
      }

      if (action === "download") {
        if (!window.HSTPrint || typeof window.HSTPrint.examPaperPdf !== "function") {
          HST.toast("ابزار ساخت فایل آزمون در دسترس نیست.", "error");
          return;
        }

        const progress = HST.operationProgress
          ? HST.operationProgress.open({
              title: "در حال ساخت بسته آزمون",
              subtitle: "نمونه سؤال و راهنمای تصحیح به‌ترتیب ساخته و دانلود می‌شوند.",
              percent: 2,
              text: "در حال دریافت سوالات آزمون...",
              lockMessage: "ساخت بسته آزمون هنوز کامل نشده است؛ لطفاً صبر کنید.",
            })
          : null;
        if (!progress && HST.loader) HST.loader.show();
        let completed = false;

        const response = await HST.request({
          action: "hst_exam_questions_paper_data",
          data: { exam_id: id },
          trigger: this,
          showLoader: false,
          successMessage: false,
          reload: false,
          dedupe: `hst_exam_questions_paper_data_${id}`,
          async onSuccess(response) {
            const payload = response && response.data ? response.data : {};
            const questions = payload && Array.isArray(payload.questions) ? payload.questions : [];
            if (!questions.length) throw new Error("exam_questions_missing");
            if (progress) progress.update(10, "سوالات دریافت شد؛ در حال ساخت نمونه سؤال...", "ساخت نمونه سؤال");

            const questionsPayload = managementPaperPayload(payload, "questions");
            questionsPayload.onProgress = function (percent, text) {
              if (!progress) return;
              progress.update(10 + Math.round(Math.max(0, Math.min(100, Number(percent) || 0)) * 0.4), text || "در حال ساخت نمونه سؤال...", "ساخت نمونه سؤال");
            };
            await window.HSTPrint.examPaperPdf(questionsPayload);

            if (progress) progress.update(53, "نمونه سؤال آماده شد؛ در حال ساخت راهنمای تصحیح...", "ساخت راهنمای تصحیح");
            const answersPayload = managementPaperPayload(payload, "answers");
            answersPayload.onProgress = function (percent, text) {
              if (!progress) return;
              progress.update(53 + Math.round(Math.max(0, Math.min(100, Number(percent) || 0)) * 0.45), text || "در حال ساخت راهنمای تصحیح...", "ساخت راهنمای تصحیح");
            };
            await window.HSTPrint.examPaperPdf(answersPayload);
            completed = true;
          },
        });

        if (response && response.success && completed) {
          if (progress) progress.complete("نمونه سؤال و راهنمای تصحیح آماده شدند و دانلود آغاز شد.");
          HST.toast("نمونه سؤال و راهنمای تصحیح آزمون دانلود شد.", "success");
        } else if (progress) {
          progress.fail("ساخت بسته آزمون انجام نشد.");
        }
        if (!progress && HST.loader) HST.loader.hide();
        return;
      }

      if (action === "preview") {
        if (String($row.attr("data-delivery-mode") || "") === "in_person") {
          HST.toast("پیش‌نمایش تعاملی برای آزمون حضوری غیرفعال است.", "warning");
          return;
        }
        const response = await HST.request({
          action: "hst_exam_questions_paper_data",
          data: { exam_id: id },
          trigger: this,
          successMessage: false,
          reload: false,
          dedupe: `hst_exam_online_preview_${id}`,
        });
        if (response && response.success && response.data) openOnlineExamPreview(response.data);
        return;
      }

      if (action === "delete") {
        const response = await HST.request({
          action: "hst_exams_delete",
          data: { id },
          trigger: this,
          confirm: { title: "حذف آزمون؟", text: "این عملیات قابل بازگشت نیست." },
          successMessage: true,
          reload: false,
        });
        if (response && response.success) {
          $row.remove();
          refreshStats();
        }
      }
    });

    $onlinePreview.on("click", "[data-hst-online-preview-close]", function () {
      closeOnlineExamPreview(false);
    });

    $onlinePreview.on("click", "[data-hst-online-preview-jump]", function () {
      showOnlinePreviewQuestion(Number($(this).attr("data-hst-online-preview-jump") || 0));
    });

    $onlinePreview.on("click", "[data-hst-online-preview-prev]", function () {
      if (onlinePreviewState) showOnlinePreviewQuestion(onlinePreviewState.currentIndex - 1);
    });

    $onlinePreview.on("click", "[data-hst-online-preview-next]", function () {
      if (onlinePreviewState) showOnlinePreviewQuestion(onlinePreviewState.currentIndex + 1);
    });

    $onlinePreview.on("change", "[data-hst-online-preview-answer-choice]", function () {
      if (!onlinePreviewState) return;
      const question = onlinePreviewState.questions[onlinePreviewState.currentIndex];
      if (!question) return;
      onlinePreviewState.answers[question.id] = String($(this).val() || "");
      updateOnlinePreviewProgress();
    });

    $onlinePreview.on("input", "[data-hst-online-preview-answer-text]", function () {
      if (!onlinePreviewState) return;
      const question = onlinePreviewState.questions[onlinePreviewState.currentIndex];
      if (!question) return;
      onlinePreviewState.answers[question.id] = String($(this).val() || "");
      updateOnlinePreviewProgress();
    });

    $onlinePreview.on("input", "[data-hst-online-preview-answer-blank]", function () {
      if (!onlinePreviewState) return;
      const question = onlinePreviewState.questions[onlinePreviewState.currentIndex];
      if (!question) return;
      const blankIndex = Number($(this).attr("data-hst-online-preview-answer-blank") || 0);
      const values = Array.isArray(onlinePreviewState.answers[question.id])
        ? onlinePreviewState.answers[question.id].slice()
        : Array.from({ length: question.blankCount }, () => "");
      values[blankIndex] = String($(this).val() || "");
      onlinePreviewState.answers[question.id] = values;
      updateOnlinePreviewProgress();
    });

    $onlinePreview.on("click", "[data-hst-online-preview-finish]", function () {
      openPreviewFinishModal("manual");
    });

    $(document).on("click", "[data-hst-online-preview-finish-close]", closePreviewFinishModal);
    $(document).on("click", "[data-hst-online-preview-finish-confirm]", function () {
      closeOnlineExamPreview(true);
    });
    $(document).on("keydown.hstOnlineExamPreview", function (event) {
      if (event.key === "Escape" && $previewFinishModal.hasClass("is-active")) {
        closePreviewFinishModal();
      }
    });
  }

  function initStudentExamRunner() {
    const $list = $("[data-hst-student-exams-list]");
    const $runner = $("[data-hst-student-exam-runner]");
    const $finishModal = $("#hst-student-exam-finish-modal");
    if (!$list.length || !$runner.length) return;

    const typeLabels = {
      multiple_choice: "تستی",
      fill_blank: "جای خالی",
      true_false: "صحیح / غلط",
      short_answer: "کوتاه پاسخ",
      essay: "تشریحی",
    };
    const difficultyLabels = {
      easy: "آسان",
      medium: "متوسط",
      hard: "سخت",
      conceptual: "مفهومی",
    };
    let state = null;
    let timer = 0;
    let saveTimer = 0;
    let saving = null;

    function faNumber(value) {
      return Number(value || 0).toLocaleString("fa-IR");
    }

    function faDigits(value) {
      return String(value == null ? "" : value).replace(/\d/g, (digit) => "۰۱۲۳۴۵۶۷۸۹"[Number(digit)]);
    }

    function timerText(totalSeconds) {
      const seconds = Math.max(0, Number(totalSeconds || 0));
      const hours = Math.floor(seconds / 3600);
      const minutes = Math.floor((seconds % 3600) / 60);
      const remainder = seconds % 60;
      const values = hours > 0 ? [hours, minutes, remainder] : [Math.floor(seconds / 60), remainder];
      return faDigits(values.map((value) => String(value).padStart(2, "0")).join(":"));
    }

    function answerComplete(question, answer) {
      if (!question) return false;
      if (question.type === "fill_blank") {
        return Array.isArray(answer)
          && answer.length >= Number(question.blankCount || 1)
          && answer.slice(0, Number(question.blankCount || 1)).every((value) => String(value || "").trim() !== "");
      }
      return String(answer == null ? "" : answer).trim() !== "";
    }

    function answeredCount() {
      if (!state) return 0;
      return state.questions.reduce(function (count, question) {
        return count + (answerComplete(question, state.answers[String(question.id)]) ? 1 : 0);
      }, 0);
    }

    function updateProgress() {
      if (!state) return;
      const total = state.questions.length;
      const answered = answeredCount();
      const percent = total > 0 ? Math.round((answered / total) * 100) : 0;
      $runner.find("[data-hst-student-exam-progress-text]").text(`${faNumber(answered)} از ${faNumber(total)} سؤال`);
      $runner.find("[data-hst-student-exam-progress-bar]")
        .css("width", `${percent}%`)
        .closest(".hst-progress")
        .attr("data-status", percent >= 100 ? "complete" : (percent > 0 ? "partial" : "missing"));
      $runner.find("[data-hst-student-exam-jump]").each(function () {
        const index = Number($(this).attr("data-hst-student-exam-jump") || 0);
        const question = state.questions[index];
        $(this).toggleClass("is-answered", answerComplete(question, question ? state.answers[String(question.id)] : null));
      });
    }

    function renderQuestionList() {
      if (!state) return;
      $runner.find("[data-hst-student-exam-question-list]").html(
        state.questions.map(function (question, index) {
          return `<button type="button" class="hst-btn hst-btn--soft hst-btn--sm${index === state.currentIndex ? " is-current" : ""}" data-hst-student-exam-jump="${index}" aria-label="رفتن به سؤال ${faNumber(index + 1)}">${faNumber(index + 1)}</button>`;
        }).join("")
      );
      updateProgress();
    }

    function optionMarkup(question, key, label, number, checked, wide) {
      return `<label class="hst-exam-online-preview__option${wide ? " is-wide" : ""}" dir="auto">`
        + `<input type="radio" name="hst-student-answer-${question.id}" value="${esc(key)}" data-hst-student-answer-choice${checked ? " checked" : ""}>`
        + `<span class="hst-exam-online-preview__option-number">${faNumber(number)}</span>`
        + `<span>${esc(label)}</span>`
        + `</label>`;
    }

    function answerMarkup(question) {
      const answer = state.answers[String(question.id)];
      if (question.type === "multiple_choice") {
        const choices = Array.isArray(question.choices) ? question.choices : [];
        const stacked = choices.some((choice) => String(choice.text || "").replace(/\s+/g, " ").trim().length > 42);
        return `<div class="hst-exam-online-preview__options${stacked ? " is-stacked" : ""}">${choices.map(function (choice, index) {
          return optionMarkup(question, choice.key, choice.text, index + 1, String(answer) === String(choice.key), stacked);
        }).join("")}</div>`;
      }
      if (question.type === "true_false") {
        return `<div class="hst-exam-online-preview__options">`
          + optionMarkup(question, "true", "صحیح", 1, String(answer || "") === "true", false)
          + optionMarkup(question, "false", "غلط", 2, String(answer || "") === "false", false)
          + `</div>`;
      }
      if (question.type === "fill_blank") {
        const values = Array.isArray(answer) ? answer : [];
        return `<div class="hst-exam-online-preview__blank-fields">${Array.from({ length: Number(question.blankCount || 1) }, function (_, index) {
          const label = Number(question.blankCount || 1) > 1 ? `پاسخ جای خالی ${faNumber(index + 1)}` : "پاسخ جای خالی";
          return `<label class="hst-field"><span>${label}</span><input type="text" dir="auto" autocomplete="off" value="${esc(values[index] || "")}" data-hst-student-answer-blank="${index}" placeholder="پاسخ خود را وارد کنید"></label>`;
        }).join("")}</div>`;
      }
      if (question.type === "essay") {
        return `<label class="hst-field"><span>پاسخ تشریحی سؤال</span><textarea rows="7" dir="auto" data-hst-student-answer-text placeholder="پاسخ تشریحی خود را بنویسید">${esc(answer || "")}</textarea></label>`;
      }
      return `<label class="hst-field"><span>پاسخ کوتاه سؤال</span><input type="text" dir="auto" autocomplete="off" value="${esc(answer || "")}" data-hst-student-answer-text placeholder="پاسخ کوتاه خود را وارد کنید"></label>`;
    }

    function renderQuestion() {
      if (!state || !state.questions.length) return;
      state.currentIndex = Math.max(0, Math.min(state.questions.length - 1, state.currentIndex));
      const question = state.questions[state.currentIndex];
      $runner.find("[data-hst-student-exam-number]").text(`سؤال ${faNumber(state.currentIndex + 1)} از ${faNumber(state.questions.length)}`);
      $runner.find("[data-hst-student-exam-type]").text(`نوع: ${typeLabels[question.type] || "سؤال"}`);
      $runner.find("[data-hst-student-exam-difficulty]").text(`سطح: ${difficultyLabels[question.difficulty] || "متوسط"}`);
      $runner.find("[data-hst-student-exam-score]").text(`بارم: ${faDigits(String(question.score || 0).replace(".", "٫"))} نمره`);
      $runner.find("[data-hst-student-exam-question-text]").html(String(question.questionText || "—"));
      $runner.find("[data-hst-student-exam-answer]").html(answerMarkup(question));
      $runner.find("[data-hst-student-exam-prev]").prop("disabled", state.currentIndex <= 0);
      $runner.find("[data-hst-student-exam-next]").prop("disabled", state.currentIndex >= state.questions.length - 1);
      $runner.find("[data-hst-student-exam-jump]")
        .removeClass("is-current")
        .removeAttr("aria-current")
        .filter(`[data-hst-student-exam-jump="${state.currentIndex}"]`)
        .addClass("is-current")
        .attr("aria-current", "step");
      if (state.expired) $runner.find("[data-hst-student-exam-answer] :input").prop("disabled", true);
    }

    function showQuestion(index) {
      if (!state) return;
      state.currentIndex = Math.max(0, Math.min(state.questions.length - 1, Number(index || 0)));
      renderQuestion();
    }

    function silentSave() {
      if (!state || state.expired || state.submitting) return Promise.resolve();
      if (saving) return saving;
      saving = HST.ajax({
        action: "hst_exams_student_save",
        attempt_id: state.attemptId,
        answers: JSON.stringify(state.answers),
      }).then(function (response) {
        if (!response || !response.success) {
          throw new Error(HST.getMessage(response, "ذخیره پاسخ‌ها انجام نشد."));
        }
        return response;
      }).catch(function (error) {
        HST.toast(HST.getMessage(error, "ذخیره پاسخ‌ها انجام نشد."), "error");
        throw error;
      }).always(function () {
        saving = null;
      });
      return saving;
    }

    function scheduleSave() {
      if (saveTimer) window.clearTimeout(saveTimer);
      saveTimer = window.setTimeout(function () {
        silentSave().catch(function () {});
      }, 500);
    }

    function trackEvent(eventName, beacon) {
      if (!state || Number(state.exam.recordExitTime || 0) !== 1) return;
      const data = {
        action: "hst_exams_student_track",
        nonce: HST.nonce,
        attempt_id: state.attemptId,
        event: eventName,
      };
      if (beacon && navigator.sendBeacon && HST.ajaxUrl) {
        navigator.sendBeacon(HST.ajaxUrl, new URLSearchParams(data));
        return;
      }
      HST.ajax(data);
    }

    function stopTimer() {
      if (timer) window.clearInterval(timer);
      timer = 0;
    }

    async function submitAttempt(timedOut) {
      if (!state || state.submitting) return;
      state.submitting = true;
      stopTimer();
      if (saveTimer) window.clearTimeout(saveTimer);
      const response = await HST.request({
        action: "hst_exams_student_submit",
        data: {
          attempt_id: state.attemptId,
          answers: JSON.stringify(state.answers),
        },
        trigger: $runner.find("[data-hst-student-exam-finish]").get(0),
        successMessage: false,
        reload: false,
      });
      if (!response || !response.success) {
        state.submitting = false;
        if (!timedOut) startTimer();
        return;
      }

      const data = response.data || {};
      if (data.resultVisible && data.result) {
        const result = data.result;
        const pending = Number(result.manualPending || 0);
        HST.toast(
          `نمره بخش تصحیح‌شده: ${faDigits(String(result.score).replace(".", "٫"))} از ${faDigits(String(result.maxScore).replace(".", "٫"))}${pending ? `؛ ${faNumber(pending)} سؤال در انتظار تصحیح دبیر است.` : ""}`,
          "success"
        );
      } else {
        HST.toast("پاسخ‌ها ثبت شد؛ نتیجه مطابق زمان انتشار تعیین‌شده نمایش داده می‌شود.", "success");
      }
      window.setTimeout(function () { window.location.reload(); }, 900);
    }

    function startTimer() {
      stopTimer();
      $runner.find("[data-hst-student-exam-timer]").text(timerText(state ? state.secondsLeft : 0));
      timer = window.setInterval(function () {
        if (!state) return;
        state.secondsLeft = Math.max(0, state.secondsLeft - 1);
        $runner.find("[data-hst-student-exam-timer]").text(timerText(state.secondsLeft));
        if (state.secondsLeft > 0) return;
        stopTimer();
        if (Number(state.exam.strictTimeLimit || 0) === 1) {
          state.expired = true;
          $runner.find("[data-hst-student-exam-answer] :input").prop("disabled", true);
          HST.toast("زمان آزمون پایان یافت و پاسخ‌ها در حال ثبت نهایی هستند.", "warning");
          submitAttempt(true);
        } else {
          HST.toast("زمان آزمون پایان یافت؛ به‌دلیل غیرفعال بودن محدودیت سخت‌گیرانه می‌توانید پاسخ‌گویی را ادامه دهید.", "warning");
        }
      }, 1000);
    }

    function openFinishModal() {
      if (!state) return;
      const answered = answeredCount();
      $finishModal.find("[data-hst-student-exam-finish-summary]").text(
        `تعداد کل سؤالات: ${faNumber(state.questions.length)} | پاسخ داده شده: ${faNumber(answered)} | بدون پاسخ: ${faNumber(Math.max(0, state.questions.length - answered))}`
      );
      $finishModal.addClass("is-active").attr("aria-hidden", "false");
      $("body").addClass("hst-modal-open");
      $finishModal.find("[data-hst-student-exam-finish-confirm]").trigger("focus");
    }

    function closeFinishModal() {
      $finishModal.removeClass("is-active").attr("aria-hidden", "true");
      if (!$(".hst-modal.is-active, .hst-modal.is-open, .hst-modal[data-open]").length) {
        $("body").removeClass("hst-modal-open");
      }
    }

    async function startExam(examId, trigger) {
      const response = await HST.request({
        action: "hst_exams_student_start",
        data: { exam_id: examId },
        trigger,
        successMessage: false,
        reload: false,
      });
      if (!response || !response.success || !response.data) return;
      const payload = response.data;
      const answers = {};
      (Array.isArray(payload.questions) ? payload.questions : []).forEach(function (question) {
        if (question.answer !== null && typeof question.answer !== "undefined") {
          answers[String(question.id)] = question.answer;
        }
      });
      state = {
        attemptId: Number(payload.attemptId || 0),
        exam: payload.exam || {},
        questions: Array.isArray(payload.questions) ? payload.questions : [],
        answers,
        currentIndex: 0,
        secondsLeft: Math.max(0, Number(payload.remainingSeconds || 0)),
        expired: false,
        submitting: false,
      };
      if (!state.attemptId || !state.questions.length) {
        HST.toast("اطلاعات آزمون کامل دریافت نشد.", "error");
        state = null;
        return;
      }
      $runner.find("[data-hst-student-exam-title]").text(String(state.exam.title || "آزمون"));
      $runner.find("[data-hst-student-exam-lesson]").text(String(state.exam.lessonName || "—"));
      $runner.find("[data-hst-student-exam-class]").text(String(state.exam.className || "—"));
      $runner.find("[data-hst-student-exam-attempt]").text(`${faNumber(state.exam.attemptNumber)} از ${faNumber(state.exam.attemptLimit)}`);
      $list.prop("hidden", true);
      $runner.prop("hidden", false);
      renderQuestionList();
      renderQuestion();
      startTimer();
      $runner.get(0).scrollIntoView({ behavior: "smooth", block: "start" });
    }

    $list.on("click", "[data-hst-student-exam-start]", function () {
      startExam(Number($(this).attr("data-exam-id") || 0), this);
    });
    $runner.on("click", "[data-hst-student-exam-jump]", async function () {
      await silentSave().catch(function () {});
      showQuestion(Number($(this).attr("data-hst-student-exam-jump") || 0));
    });
    $runner.on("click", "[data-hst-student-exam-prev]", async function () {
      await silentSave().catch(function () {});
      if (state) showQuestion(state.currentIndex - 1);
    });
    $runner.on("click", "[data-hst-student-exam-next]", async function () {
      await silentSave().catch(function () {});
      if (state) showQuestion(state.currentIndex + 1);
    });
    $runner.on("change", "[data-hst-student-answer-choice]", function () {
      if (!state) return;
      const question = state.questions[state.currentIndex];
      state.answers[String(question.id)] = question.type === "multiple_choice" ? Number($(this).val()) : String($(this).val() || "");
      updateProgress();
      scheduleSave();
    });
    $runner.on("input", "[data-hst-student-answer-text]", function () {
      if (!state) return;
      state.answers[String(state.questions[state.currentIndex].id)] = String($(this).val() || "");
      updateProgress();
      scheduleSave();
    });
    $runner.on("input", "[data-hst-student-answer-blank]", function () {
      if (!state) return;
      const question = state.questions[state.currentIndex];
      const key = String(question.id);
      const index = Number($(this).attr("data-hst-student-answer-blank") || 0);
      const values = Array.isArray(state.answers[key])
        ? state.answers[key].slice()
        : Array.from({ length: Number(question.blankCount || 1) }, () => "");
      values[index] = String($(this).val() || "");
      state.answers[key] = values;
      updateProgress();
      scheduleSave();
    });
    $runner.on("click", "[data-hst-student-exam-finish]", openFinishModal);
    $(document).on("click", "[data-hst-student-exam-finish-close]", closeFinishModal);
    $(document).on("click", "[data-hst-student-exam-finish-confirm]", function () {
      closeFinishModal();
      submitAttempt(false);
    });
    $(document).on("visibilitychange.hstStudentExam", function () {
      if (!state) return;
      trackEvent(document.hidden ? "hidden" : "visible", false);
    });
    $(window).on("pagehide.hstStudentExam", function () {
      if (!state) return;
      trackEvent("pagehide", true);
    });
  }

  function initLegacyTeacherForm() {
    const $form = $("#hst-exam-form");
    if (!$form.length) return;

    const lessonsByClass = window.HST_EXAM_LESSONS || {};
    const $class = $("#hst-exam-class");
    const $lesson = $("#hst-exam-lesson");
    const $date = $("#hst-exam-date");
    const $shift = $("#hst-exam-shift");
    const $feedback = $("#hst-exam-feedback");

    function showFeedback(items, type) {
      if (!items || !items.length) {
        $feedback.prop("hidden", true).removeClass("is-error is-warning").html("");
        return;
      }

      const list = items.map((item) => `<p>${esc(item)}</p>`).join("");
      $feedback
        .prop("hidden", false)
        .removeClass("is-error is-warning")
        .addClass(type === "error" ? "is-error" : "is-warning")
        .html(list);
    }

    function resetShift(message) {
      $shift
        .html(`<option value="">${message || "ابتدا تاریخ معتبر انتخاب کنید"}</option>`)
        .prop("disabled", true);
    }

    function fillLessons(classId, selected) {
      const lessons = lessonsByClass[classId] || [];
      let html = '<option value="">انتخاب درس</option>';

      lessons.forEach((lesson) => {
        const isSelected = String(selected || "") === String(lesson.id) ? "selected" : "";
        html += `<option value="${esc(lesson.id)}" ${isSelected}>${esc(lesson.lesson_name)}</option>`;
      });

      $lesson.html(html).prop("disabled", !lessons.length);
      resetShift();
    }

    function validateDate() {
      const classId = $class.val();
      const lessonId = $lesson.val();
      const examDate = $date.val();

      resetShift();
      showFeedback([], "warning");

      if (!classId || !lessonId || !examDate) return;

      HST.loader.show();

      HST.ajax({
        action: "hst_exams_validate_date",
        class_id: classId,
        lesson_id: lessonId,
        exam_date: examDate,
      })
        .done(function (res) {
          if (!res || !res.success) {
            showFeedback([HST.getMessage(res, "تاریخ آزمون قابل بررسی نیست.")], "error");
            return;
          }

          const data = res.data || {};
          if (!data.is_valid) {
            showFeedback(data.errors || ["این تاریخ با برنامه هفتگی هماهنگ نیست."], "error");
            return;
          }

          let html = '<option value="">انتخاب زنگ</option>';
          (data.allowed_shifts || []).forEach((item) => {
            html += `<option value="${esc(item.shift)}">${esc(item.label)}</option>`;
          });

          $shift.html(html).prop("disabled", !(data.allowed_shifts || []).length);

          if (data.warnings && data.warnings.length) {
            showFeedback(data.warnings, "warning");
          } else {
            showFeedback(
              [`این تاریخ برای ${data.day_label || "روز انتخاب‌شده"} با برنامه هفتگی هماهنگ است.`],
              "warning"
            );
          }
        })
        .fail(function (xhr) {
          showFeedback([HST.getMessage(xhr, "ارتباط با سرور برقرار نشد.")], "error");
        })
        .always(function () {
          HST.loader.hide();
        });
    }

    $class.on("change", function () {
      fillLessons($(this).val());
    });

    $lesson.add($date).on("change blur", validateDate);

    $form.on("submit", function (event) {
      event.preventDefault();

      const title = String($form.find('[name="title"]').val() || "").trim();
      const duration = parseInt($form.find('[name="duration_minutes"]').val(), 10) || 45;

      if (!title || title.length > 120) {
        showFeedback(["عنوان آزمون الزامی است و باید حداکثر ۱۲۰ کاراکتر باشد."], "error");
        return;
      }

      if (!$class.val() || !$lesson.val() || !$date.val() || !$shift.val()) {
        showFeedback(["کلاس، درس، تاریخ و زنگ آزمون را کامل انتخاب کنید."], "error");
        return;
      }

      if (duration < 15 || duration > 240) {
        showFeedback(["مدت آزمون باید بین ۱۵ تا ۲۴۰ دقیقه باشد."], "error");
        return;
      }

      HST.request({
        action: "hst_exams_save",
        data: {
          id: $form.find('[name="id"]').val(),
          title,
          class_id: $class.val(),
          lesson_id: $lesson.val(),
          exam_date: $date.val(),
          school_shift: $shift.val(),
          duration_minutes: $form.find('[name="duration_minutes"]').val(),
          location: $form.find('[name="location"]').val(),
          description: $form.find('[name="description"]').val(),
          status: $form.find('[name="status"]').val(),
        },
        successMessage: true,
        reload: true,
      });
    });

    $("#hst-exam-reset").on("click", function () {
      $form.get(0).reset();
      $form.find('[name="id"]').val("");
      fillLessons("");
      showFeedback([], "warning");
    });

    $(document).on("click", ".hst-exam-edit", function () {
      const $row = $(this).closest("tr");
      const classId = $row.data("class-id");

      $form.find('[name="id"]').val($row.data("id"));
      $form.find('[name="title"]').val($row.data("title"));
      $class.val(classId);
      fillLessons(classId, $row.data("lesson-id"));
      $date.val($row.data("date"));
      $form.find('[name="duration_minutes"]').val($row.data("duration"));
      $form.find('[name="location"]').val($row.data("location"));
      $form.find('[name="description"]').val($row.data("description"));
      $form.find('[name="status"]').val($row.data("status"));

      validateDate();

      window.setTimeout(function () {
        $shift.val(String($row.data("shift")));
      }, 450);

      $("html, body").animate({ scrollTop: $form.offset().top - 80 }, 300);
    });

    $(document).on("click", ".hst-exam-delete", function () {
      const id = $(this).closest("tr").data("id");

      HST.request({
        action: "hst_exams_delete",
        data: { id },
        confirm: {
          title: "حذف آزمون؟",
          text: "این عملیات قابل بازگشت نیست.",
        },
        successMessage: true,
        reload: true,
      });
    });
  }

  function initQuestionBank() {
    const $root = $("[data-hst-question-bank-root]");
    if (!$root.length) return;

    const $rows = $root.find("[data-hst-question-row]");
    const $questionList = $root.find("[data-hst-question-list]");
    const $empty = $root.find("[data-hst-question-empty]");
    const $selectAll = $root.find("[data-hst-question-select-all]");
    const $transferButtons = $root.find("[data-hst-question-transfer-open]");
    const $questionNext = $root.find("[data-hst-question-next]");
    const $selectionStatus = $root.find("[data-hst-question-selection-status]");
    const $editorModal = $("#hst-question-editor-modal");
    const $transferModal = $("#hst-question-transfer-modal");
    const $form = $("#hst-question-editor-form");
    const $editor = $form.find("[data-hst-question-editor]");
    const $answerFields = $form.find("[data-hst-question-answer-fields]");
    const $grade = $form.find('[name="grade"]');
    const $major = $form.find('[name="major"]');
    const $lesson = $form.find('[name="lesson_id"]');
    const $type = $form.find('[name="question_type"]');
    const $stageOne = $root.find('[data-hst-question-stage="1"]');
    const $stageTwo = $root.find('[data-hst-question-stage="2"]');
    const $stageThree = $root.find('[data-hst-question-stage="3"]');
    const $stageFour = $root.find('[data-hst-question-stage="4"]');
    const $paperPreviewCard = $stageFour.find("[data-hst-exam-paper-preview-card]");
    const $paperPreviewBody = $paperPreviewCard.find("[data-hst-exam-paper-inline-preview]");
    const $paperPreviewTitle = $paperPreviewCard.find("[data-hst-exam-paper-inline-title]");
    const $paperPreviewSubtitle = $paperPreviewCard.find("[data-hst-exam-paper-inline-subtitle]");
    const $paperPreviewLoading = $paperPreviewCard.find("[data-hst-exam-paper-preview-loading]");
    const $paperPreviewPager = $paperPreviewCard.find("[data-hst-exam-paper-preview-pagination]");
    const $selectedQuestionList = $stageThree.find("[data-hst-selected-question-list]");
    const $selectedQuestionEmpty = $stageThree.find("[data-hst-selected-question-empty]");
    const $questionDesignNext = $stageThree.find("[data-hst-question-design-next]");
    const $questionAutoBuild = $stageThree.find("[data-hst-question-auto-build]");
    const $blueprintForm = $root.find("[data-hst-question-blueprint-form]");
    const $blueprintGrade = $blueprintForm.find("[data-hst-blueprint-grade]");
    const $blueprintMajor = $blueprintForm.find("[data-hst-blueprint-major]");
    const $blueprintSubject = $blueprintForm.find("[data-hst-blueprint-subject]");
    const $blueprintTree = $blueprintForm.find("[data-hst-blueprint-tree]");
    const $blueprintNext = $root.find("[data-hst-blueprint-next]");
    const $blueprintCount = $blueprintForm.find("[data-hst-blueprint-count]");
    const canCreateQuestion = !$root.find("[data-hst-question-open]").first().prop("disabled");
    const curriculumPayload = readEmbeddedJson("[data-hst-question-curriculum]", { grades: {} });
    const savedBlueprint = readEmbeddedJson("[data-hst-question-blueprint]", {});
    const curriculum = curriculumPayload && curriculumPayload.grades ? curriculumPayload.grades : {};
    let activeBlueprint = savedBlueprint && savedBlueprint.subject ? savedBlueprint : {};
    let answerSeed = {};
    let editingQuestionScope = null;
    let transferPurpose = "direct";
    let selectedExam = null;
    let paperPreviewRequest = 0;
    let paperPreviewPages = [];
    let paperPreviewPage = 1;
    const selectedQuestionScoresStorageKey = "hstQuestionBankScores";
    let selectedQuestionScores = {};

    try {
      const storedScores = JSON.parse(String(window.sessionStorage.getItem(selectedQuestionScoresStorageKey) || "{}"));
      if (storedScores && typeof storedScores === "object" && !Array.isArray(storedScores)) {
        Object.keys(storedScores).forEach((id) => {
          const score = Number(storedScores[id]);
          if (Number.isFinite(score) && score >= 0) selectedQuestionScores[String(id)] = score;
        });
      }
    } catch (error) {
      selectedQuestionScores = {};
    }

    function readEmbeddedJson(selector, fallback) {
      try {
        const value = JSON.parse(String($root.find(selector).first().text() || ""));
        return value && typeof value === "object" ? value : fallback;
      } catch (error) {
        return fallback;
      }
    }

    function toFa(value) {
      return String(value).replace(/\d/g, (digit) => "۰۱۲۳۴۵۶۷۸۹"[Number(digit)]);
    }

    function normalized(value) {
      return String(value || "")
        .toLowerCase()
        .replace(/ي/g, "ی")
        .replace(/ك/g, "ک")
        .replace(/[ۀة]/g, "ه")
        .replace(/[\u200c\u200d\u200e\u200f\u202a-\u202e]/g, " ")
        .replace(/\s+/g, " ")
        .trim();
    }

    function subjectStem(value) {
      return normalized(value)
        .replace(/[()（）\[\]۰-۹0-9]/g, " ")
        .replace(/[،,:؛\-–—]/g, " ")
        .replace(/\s+/g, " ")
        .trim();
    }

    function subjectCanonical(value) {
      const stem = subjectStem(value);
      if (!stem) return "";

      if (stem.includes("تعلیمات دینی") || stem.includes("دینی اخلاق و قرآن") || stem.includes("دین و زندگی")) {
        return "دین و زندگی";
      }
      if (stem.includes("زبان خارجی") || stem.includes("زبان انگلیسی") || stem.includes("انگلیسی")) {
        return "انگلیسی";
      }
      if (stem.includes("هویت اجتماعی") || stem.includes("علوم اجتماعی")) {
        return "هویت اجتماعی";
      }
      if (stem.includes("جغرافیای عمومی و استان شناسی") || stem === "استان شناسی") {
        return "استان شناسی";
      }
      if (stem === "درس انتخابی" || stem.includes("تفکر و سواد رسانه ای") || stem === "هنر" || stem.includes("کارگاه کارآفرینی و تولید")) {
        return "درس انتخابی";
      }

      return stem;
    }

    function subjectMatches(lessonName, subjectTitle) {
      const lesson = subjectCanonical(lessonName);
      const subject = subjectCanonical(subjectTitle);
      const lessonCompact = lesson.replace(/\s+/g, "");
      const subjectCompact = subject.replace(/\s+/g, "");
      return Boolean(lesson && subject && (
        lesson.includes(subject)
        || subject.includes(lesson)
        || lessonCompact.includes(subjectCompact)
        || subjectCompact.includes(lessonCompact)
      ));
    }

    function optionMarkup(value, label) {
      return `<option value="${esc(value)}">${esc(label)}</option>`;
    }

    function showQuestionStage(stage) {
      const current = Number(stage);
      $stageOne.prop("hidden", current !== 1);
      $stageTwo.prop("hidden", current !== 2);
      $stageThree.prop("hidden", current !== 3);
      $stageFour.prop("hidden", current !== 4);
      if (current === 4) syncExamPaperDownloadActions();
      $root.attr("data-hst-question-current-stage", String(current));
      const target = current === 1 ? $stageOne : (current === 2 ? $stageTwo : (current === 3 ? $stageThree : $stageFour));
      if (target.length && target.get(0).scrollIntoView) {
        target.get(0).scrollIntoView({ behavior: "smooth", block: "start" });
      }
    }

    function gradeEntry() {
      return curriculum[String($blueprintGrade.val() || "")] || null;
    }

    function majorEntry() {
      const grade = gradeEntry();
      return grade && grade.majors ? grade.majors[String($blueprintMajor.val() || "")] || null : null;
    }

    function subjectEntry() {
      const major = majorEntry();
      return major && major.subjects ? major.subjects[String($blueprintSubject.val() || "")] || null : null;
    }

    function populateMajors(selected) {
      const grade = gradeEntry();
      let html = '<option value="">انتخاب رشته</option>';
      if (grade && grade.majors) {
        Object.keys(grade.majors).forEach((key) => {
          html += optionMarkup(key, grade.majors[key].label || key);
        });
      }
      $blueprintMajor.html(grade ? html : '<option value="">ابتدا پایه را انتخاب کنید</option>').prop("disabled", !grade);
      if (selected && grade && grade.majors[selected]) $blueprintMajor.val(selected);
      populateSubjects("");
    }

    function populateSubjects(selected) {
      const major = majorEntry();
      let html = '<option value="">انتخاب درس</option>';
      if (major && major.subjects) {
        Object.keys(major.subjects).forEach((key) => {
          html += optionMarkup(key, major.subjects[key].title || key);
        });
      }
      $blueprintSubject.html(major ? html : '<option value="">ابتدا رشته را انتخاب کنید</option>').prop("disabled", !major);
      if (selected && major && major.subjects[selected]) $blueprintSubject.val(selected);
      renderBlueprintTree([], []);
    }

    function subjectUnits(subject) {
      if (!subject) return [];
      const chapters = Array.isArray(subject.chapters)
        ? subject.chapters.map((unit) => Object.assign({}, unit, { kind: "chapter" }))
        : [];
      const sections = Array.isArray(subject.sections)
        ? subject.sections.map((unit) => Object.assign({}, unit, { kind: "section" }))
        : [];
      return chapters.concat(sections);
    }

    function blueprintUnits(blueprint) {
      const units = [];
      ["units", "chapters", "sections"].forEach((key) => {
        if (!Array.isArray(blueprint && blueprint[key])) return;
        blueprint[key].forEach((unit) => {
          const value = String(unit || "");
          if (value && !units.includes(value)) units.push(value);
        });
      });
      const legacy = String((blueprint && blueprint.chapter) || "");
      if (legacy && !units.includes(legacy)) units.push(legacy);
      return units;
    }

    function normalizeActiveBlueprint(blueprint) {
      const normalizedBlueprint = blueprint && typeof blueprint === "object" ? blueprint : {};
      const units = blueprintUnits(normalizedBlueprint);
      normalizedBlueprint.units = units;
      normalizedBlueprint.chapters = units.filter((unit) => unit.startsWith("c"));
      normalizedBlueprint.sections = units.filter((unit) => unit.startsWith("s"));
      normalizedBlueprint.chapter = units[0] || "";
      normalizedBlueprint.topics = Array.isArray(normalizedBlueprint.topics)
        ? normalizedBlueprint.topics.map(String).filter(Boolean)
        : [];
      return normalizedBlueprint;
    }

    activeBlueprint = normalizeActiveBlueprint(activeBlueprint);

    function renderBlueprintTree(selectedTopics, selectedUnits) {
      const subject = subjectEntry();
      const units = subjectUnits(subject);
      const selected = Array.isArray(selectedTopics) ? selectedTopics.map(String) : [];
      const selectedUnitIds = Array.isArray(selectedUnits) ? selectedUnits.map(String) : [];
      if (!subject || !units.length) {
        $blueprintTree.removeClass("has-branch").html('<p class="hst-alert hst-empty-state">برای مشاهده بودجه‌بندی، پایه، رشته و درس را انتخاب کنید.</p>');
        updateBlueprintSelection();
        return;
      }

      const totalTopics = units.reduce((count, unit) => count + (Array.isArray(unit.topics) ? unit.topics.length : 0), 0);
      const allHead = `<div class="hst-question-blueprint__branch-head hst-question-blueprint__all"><label><input type="checkbox" data-hst-blueprint-all><strong>انتخاب کل کتاب</strong></label><span>${toFa(totalTopics)} درس و بخش</span></div>`;
      const branches = units.map((unit) => {
        const topics = Array.isArray(unit.topics) ? unit.topics : [];
        const unitSelected = selectedUnitIds.includes(String(unit.id));
        const topicMarkup = topics.map((topic) => {
          const checked = selected.includes(String(topic.id)) || (unitSelected && !selected.length);
          return `<label class="hst-question-blueprint__topic"><input type="checkbox" value="${esc(topic.id)}" data-unit-id="${esc(unit.id)}" data-hst-blueprint-topic ${checked ? "checked" : ""}><span>${esc(topic.title)}</span></label>`;
        }).join("");
        const unitLabel = unit.kind === "section" ? "بخش مستقل" : "فصل";
        return `<div class="hst-question-blueprint__branch is-open" data-unit-id="${esc(unit.id)}"><div class="hst-question-blueprint__branch-head"><label><input type="checkbox" value="${esc(unit.id)}" data-hst-blueprint-parent><strong>${esc(unit.title)}</strong></label><span>${unitLabel} · ${toFa(topics.length)} درس</span></div><div class="hst-question-blueprint__topics">${topicMarkup}</div></div>`;
      }).join("");
      $blueprintTree.addClass("has-branch").html(allHead + branches);
      updateBlueprintSelection();
    }

    function updateBlueprintSelection() {
      const $topics = $blueprintTree.find("[data-hst-blueprint-topic]");
      const checked = $topics.filter(":checked").length;
      $blueprintTree.find("[data-hst-blueprint-parent]").each(function () {
        const $parent = $(this);
        const $branchTopics = $parent.closest("[data-unit-id]").find("[data-hst-blueprint-topic]");
        const branchChecked = $branchTopics.filter(":checked").length;
        $parent.prop("checked", $branchTopics.length > 0 && branchChecked === $branchTopics.length);
        $parent.prop("indeterminate", branchChecked > 0 && branchChecked < $branchTopics.length);
      });
      const $all = $blueprintTree.find("[data-hst-blueprint-all]");
      $all.prop("checked", $topics.length > 0 && checked === $topics.length);
      $all.prop("indeterminate", checked > 0 && checked < $topics.length);
      const selectedUnits = $topics.filter(":checked").map(function () { return String($(this).attr("data-unit-id") || ""); }).get().filter((value, index, values) => value && values.indexOf(value) === index);
      $blueprintCount.text(`${toFa(checked)} درس یا بخش از ${toFa(selectedUnits.length)} فصل/بخش انتخاب شده`);
      $blueprintNext.prop("disabled", checked === 0);
    }

    function restoreBlueprint() {
      activeBlueprint = normalizeActiveBlueprint(activeBlueprint);
      if (!activeBlueprint.grade || !curriculum[activeBlueprint.grade]) return;
      $blueprintGrade.val(activeBlueprint.grade);
      populateMajors(activeBlueprint.major || "");
      populateSubjects(activeBlueprint.subject || "");
      renderBlueprintTree(activeBlueprint.topics || [], activeBlueprint.units || []);
    }

    function primaryBlueprintScope() {
      const subject = subjectEntry();
      const units = subjectUnits(subject);
      const topics = Array.isArray(activeBlueprint.topics) ? activeBlueprint.topics.map(String) : [];
      for (const unit of units) {
        const unitTopicIds = (Array.isArray(unit.topics) ? unit.topics : []).map((topic) => String(topic.id));
        const selected = topics.filter((topic) => unitTopicIds.includes(topic));
        if (selected.length) {
          return { chapter: String(unit.id), topics: [selected[0]] };
        }
      }
      return { chapter: String(activeBlueprint.chapter || ""), topics: topics.slice(0, 1) };
    }

    function applyBlueprintScope() {
      const title = String(activeBlueprint.subject_title || (subjectEntry() || {}).title || "");
      const gradeLabel = curriculum[activeBlueprint.grade] ? curriculum[activeBlueprint.grade].label : "";
      const majorData = curriculum[activeBlueprint.grade] && curriculum[activeBlueprint.grade].majors
        ? curriculum[activeBlueprint.grade].majors[activeBlueprint.major]
        : null;
      const majorLabel = majorData ? majorData.label : "";
      $root.find("[data-hst-question-scope-summary]").text(
        title ? `بودجه فعال: ${title}، پایه ${gradeLabel}، رشته ${majorLabel}` : "مدیریت سؤالات در محدوده بودجه‌بندی انتخاب‌شده"
      );

      let matched = "";
      $lesson.find("option[value]").each(function () {
        const $option = $(this);
        if (!$option.val() || matched) return;
        if (String($option.attr("data-grade") || "") === String(activeBlueprint.grade || "")
          && String($option.attr("data-major") || "") === String(activeBlueprint.major || "")
          && subjectMatches($option.attr("data-lesson-name"), title)) {
          matched = String($option.val());
        }
      });
      $root.find("[data-hst-question-open]").prop("disabled", !canCreateQuestion || !matched);
      if (title && !matched) {
        $root.find("[data-hst-question-scope-summary]").text(
          `بودجه فعال: ${title}، پایه ${gradeLabel}، رشته ${majorLabel} — برای افزودن سؤال، این درس باید برای یکی از کلاس‌های مدرسه تعریف شده باشد.`
        );
      }
    }

    function visibleRows() {
      return $rows.filter(function () {
        return !$(this).prop("hidden") && $(this).is(":visible");
      });
    }

    function ensureQuestionRowText() {
      $rows.each(function () {
        const $row = $(this);
        const $cell = $row.find("[data-hst-question-text-display]").first();
        let text = String($row.attr("data-question-text") || $row.attr("data-preview") || "")
          .replace(/[\u200b\u200e\u200f\u202a-\u202e\u2060\u2066-\u2069\ufeff]/g, "")
          .replace(/\s+/g, " ")
          .trim();
        const rawQuestion = $row.find("[data-hst-question-edit]").attr("data-question");
        if (rawQuestion) {
          try {
            const question = JSON.parse(String(rawQuestion));
            const questionHtml = String(question.question_text || "");
            const $content = $("<div>").html(questionHtml);
            $content.find("script, style, template").remove();
            const payloadText = String($content.text() || "")
              .replace(/[\u200b\u200e\u200f\u202a-\u202e\u2060\u2066-\u2069\ufeff]/g, "")
              .replace(/\s+/g, " ")
              .trim();
            if (payloadText) {
              text = payloadText;
            } else if ($content.find("img").length) {
              text = "سؤال تصویری";
            } else if ($content.find("table").length) {
              text = "سؤال دارای جدول";
            }
          } catch (error) {
            // Keep the sanitized server value when the edit payload is unreadable.
          }
        }

        if (!text) text = "متن سؤال در دسترس نیست";
        $cell.text(text).attr("title", text);
        $row.attr("data-question-text", text);
        $row.attr("data-preview", text);
        $row.attr("data-search", text);
        $row.attr("data-hst-search", text);
      });
    }

    function selectedIds() {
      return $rows
        .find("[data-hst-question-select]:checked")
        .map(function () { return parseInt($(this).val(), 10); })
        .get()
        .filter(Number.isFinite);
    }

    function syncExamPaperDownloadActions() {
      const hasPaperData = !!selectedExam && selectedIds().length > 0;
      $stageFour.find('[data-hst-exam-paper-download="questions"]')
        .prop("disabled", !hasPaperData)
        .attr("title", hasPaperData ? "دانلود نمونه سوال" : "هنوز سؤالی برای دریافت آماده نشده است.");
      $stageFour.find('[data-hst-exam-paper-download="answers"]')
        .prop("disabled", !hasPaperData)
        .attr("title", hasPaperData ? "دانلود راهنمای تصحیح" : "هنوز راهنمای تصحیحی برای دریافت آماده نشده است.");
    }

    function selectedQuestionRows() {
      return $rows.filter(function () {
        return $(this).find("[data-hst-question-select]").prop("checked");
      });
    }

    function faDecimal(value) {
      const number = Number(value || 0);
      return number.toLocaleString("fa-IR", {
        minimumFractionDigits: Number.isInteger(number) ? 0 : 1,
        maximumFractionDigits: 2,
      });
    }

    function setDesignRingSegment(name, percent, offset) {
      const safePercent = Math.max(0, Math.min(100, Number(percent) || 0));
      const safeOffset = Math.max(0, Math.min(100, Number(offset) || 0));
      $stageThree.find(`[data-hst-design-segment="${name}"]`).each(function () {
        this.style.strokeDasharray = `${safePercent} ${100 - safePercent}`;
        this.style.strokeDashoffset = String(-safeOffset);
        this.style.opacity = safePercent > 0 ? "1" : "0";
      });
    }

    function questionPayloadFromRow($row) {
      try {
        const raw = $row.find("[data-hst-question-edit]").attr("data-question");
        const question = JSON.parse(String(raw || "{}"));
        return question && typeof question === "object" ? question : {};
      } catch (error) {
        return {};
      }
    }

    function selectedQuestionScore(question) {
      const id = String(Number(question && question.id ? question.id : 0));
      if (id !== "0" && Object.prototype.hasOwnProperty.call(selectedQuestionScores, id)) {
        return Number(selectedQuestionScores[id]) || 0;
      }
      return Number(question && question.score ? question.score : 0) || 0;
    }

    function persistSelectedQuestionScores() {
      window.sessionStorage.setItem(selectedQuestionScoresStorageKey, JSON.stringify(selectedQuestionScores));
    }

    function selectedQuestionScoresFor(ids) {
      const scores = {};
      (Array.isArray(ids) ? ids : []).forEach((id) => {
        const $row = $rows.filter(`[data-question-id="${Number(id)}"]`).first();
        const question = questionPayloadFromRow($row);
        if (question.id) scores[String(Number(id))] = selectedQuestionScore(question);
      });
      return scores;
    }

    function selectedQuestionIdsInOrder() {
      const checkedIds = selectedIds();
      let savedIds = [];
      try {
        const parsed = JSON.parse(String(window.sessionStorage.getItem("hstQuestionBankSelection") || "[]"));
        if (Array.isArray(parsed)) savedIds = parsed.map(Number).filter(Number.isFinite);
      } catch (error) {
        savedIds = [];
      }
      const ordered = savedIds.filter((id, index) => checkedIds.includes(id) && savedIds.indexOf(id) === index);
      checkedIds.forEach((id) => { if (!ordered.includes(id)) ordered.push(id); });
      return ordered;
    }

    function selectedQuestionTypeLabel(type) {
      const labels = {
        multiple_choice: "تستی",
        true_false: "صحیح | غلط",
        fill_blank: "جای خالی",
        short_answer: "پاسخ کوتاه",
        essay: "تشریحی",
      };
      return labels[String(type || "")] || "سؤال";
    }

    function sortQuestionIdsByDefaultType(ids) {
      const priorities = {
        true_false: 1,
        fill_blank: 2,
        multiple_choice: 3,
        short_answer: 4,
        essay: 5,
      };
      return (Array.isArray(ids) ? ids : [])
        .map((id, index) => {
          const numericId = Number(id);
          const $row = $rows.filter(`[data-question-id="${numericId}"]`).first();
          const question = questionPayloadFromRow($row);
          const type = String($row.attr("data-question-type") || question.question_type || "");
          return {
            id: numericId,
            index,
            priority: Object.prototype.hasOwnProperty.call(priorities, type) ? priorities[type] : 99,
          };
        })
        .filter((item) => Number.isFinite(item.id))
        .sort((left, right) => (left.priority - right.priority) || (left.index - right.index))
        .map((item) => item.id);
    }

    function selectedQuestionDifficultyTone(difficulty) {
      if (difficulty === "easy") return "hst-status--success";
      if (difficulty === "medium") return "hst-status--warning";
      return "hst-status--danger";
    }

    function selectedQuestionAnswerMarkup(question) {
      const type = String(question.question_type || "");
      const data = question.answer_data && typeof question.answer_data === "object" ? question.answer_data : {};

      if (type === "multiple_choice") {
        const choices = Array.isArray(data.choices) ? data.choices.slice(0, 4) : [];
        const correct = Number(data.correct_index);
        return `<div class="hst-view-grid" data-hst-selected-answer-grid="multiple_choice">${choices.map((choice, index) => {
          const correctClass = index === correct ? " is-correct" : "";
          const numberTone = index === correct ? "hst-status--success" : "hst-status--muted";
          return `<div class="hst-view-row${correctClass}"><span class="hst-view-row__value"><span class="hst-status ${numberTone}">${toFa(index + 1)}</span>${esc(choice || "—")}</span></div>`;
        }).join("")}</div>`;
      }

      if (type === "true_false") {
        const correctFalse = String(data.correct || "true") === "false";
        return `<div class="hst-view-grid" data-hst-selected-answer-grid="true_false">
          <div class="hst-view-row${correctFalse ? "" : " is-correct"}"><span class="hst-view-row__value">درست${correctFalse ? "" : " ✓ (پاسخ صحیح)"}</span></div>
          <div class="hst-view-row${correctFalse ? " is-correct" : ""}"><span class="hst-view-row__value">غلط${correctFalse ? " ✓ (پاسخ صحیح)" : ""}</span></div>
        </div>`;
      }

      if (type === "fill_blank") {
        const answers = Array.isArray(data.answers) ? data.answers.filter((answer) => String(answer || "").trim()) : [];
        return `<div class="hst-view-grid" data-hst-selected-answer-grid="fill_blank"><div class="hst-view-row hst-view-row--wide"><span class="hst-view-row__value">پاسخ جای خالی: ${esc(answers.join("، ") || "—")}</span></div></div>`;
      }

      if (type === "short_answer") {
        return `<div class="hst-view-grid" data-hst-selected-answer-grid="short_answer"><div class="hst-view-row hst-view-row--wide"><span class="hst-view-row__value">پاسخ صحیح: ${esc(data.answer || "—")}</span></div></div>`;
      }

      return `<div class="hst-view-grid" data-hst-selected-answer-grid="essay"><div class="hst-view-row hst-view-row--wide"><span class="hst-view-row__value">پاسخ نمونه / راهنمای تصحیح: ${esc(data.guide || "—")}</span></div></div>`;
    }

    function renderQuestionBankAnswers() {
      $rows.each(function () {
        const $row = $(this);
        const question = questionPayloadFromRow($row);
        const $answer = $row.find("[data-hst-question-answer]").first();
        if ($answer.length) $answer.html(selectedQuestionAnswerMarkup(question));
      });
    }

    function selectedQuestionMarkup(question, index) {
      const id = Number(question.id || 0);
      const difficulty = String(question.difficulty || "");
      const difficultyLabel = String(question.difficulty_label || "—");
      const type = String(question.question_type || "");
      const score = selectedQuestionScore(question);
      const questionHtml = String(question.question_text || "").trim() || '<p class="hst-muted">صورت سؤال ثبت نشده است.</p>';
      return `<article class="hst-assignment-item" data-hst-selected-question data-question-id="${id}" data-score="${esc(score)}" draggable="true">
        <div class="hst-card__header--row">
          <div class="hst-btn-group">
            <button type="button" class="hst-btn hst-btn--ghost hst-btn--sm hst-btn--icon" data-hst-selected-question-handle title="جابجایی سؤال" aria-label="جابجایی سؤال">⠿</button>
            <span class="hst-chip">سوال ${toFa(index + 1)} (${esc(selectedQuestionTypeLabel(type))})</span>
            <span class="hst-status ${selectedQuestionDifficultyTone(difficulty)}">دشواری: ${esc(difficultyLabel)}</span>
          </div>
          <div class="hst-btn-group">
            <label class="hst-score-input-wrap">
              <input type="number" class="hst-score-input" min="0.25" max="100" step="0.25" value="${esc(score)}" inputmode="decimal" data-hst-selected-question-score aria-label="بارم سوال ${toFa(index + 1)}">
              <span class="hst-score-input__max">نمره</span>
            </label>
            <button type="button" class="hst-btn hst-btn--danger hst-btn--sm hst-btn--icon" data-hst-selected-question-remove title="حذف از سوالات انتخاب شده" aria-label="حذف از سوالات انتخاب شده">${$root.find("[data-hst-question-delete]").first().html() || "×"}</button>
          </div>
        </div>
        <div class="hst-question-editor__surface">${questionHtml}</div>
        ${selectedQuestionAnswerMarkup(question)}
      </article>`;
    }

    function persistSelectedQuestionOrder() {
      const ids = $selectedQuestionList.find("[data-hst-selected-question]").map(function () {
        return Number($(this).attr("data-question-id"));
      }).get().filter(Number.isFinite);
      window.sessionStorage.setItem("hstQuestionBankSelection", JSON.stringify(ids));
      $selectedQuestionList.find("[data-hst-selected-question]").each(function (index) {
        const $item = $(this);
        const question = questionPayloadFromRow($rows.filter(`[data-question-id="${$item.attr("data-question-id")}"]`).first());
        $item.find(".hst-chip").first().text(`سوال ${toFa(index + 1)} (${selectedQuestionTypeLabel(question.question_type)})`);
      });
      return ids;
    }

    function renderSelectedQuestions() {
      const orderedIds = selectedQuestionIdsInOrder();
      const items = [];
      orderedIds.forEach((id, index) => {
        const $row = $rows.filter(`[data-question-id="${id}"]`).first();
        if (!$row.length) return;
        const question = questionPayloadFromRow($row);
        if (!question.id) return;
        items.push(selectedQuestionMarkup(question, index));
      });
      $selectedQuestionList.html(items.join("")).prop("hidden", items.length === 0);
      $selectedQuestionEmpty.prop("hidden", items.length > 0);
      window.sessionStorage.setItem("hstQuestionBankSelection", JSON.stringify(orderedIds));
    }

    function renderQuestionDesignSummary() {
      const $selectedRows = selectedQuestionRows();
      const questionTarget = Number($stageThree.find('[data-hst-design-ring="count"]').attr("data-target") || 20);
      const scoreTarget = Number($stageThree.find('[data-hst-design-ring="score"]').attr("data-target") || 20);
      const questionCount = $selectedRows.length;
      let totalScore = 0;
      let easy = 0;
      let medium = 0;
      let hard = 0;

      $selectedRows.each(function () {
        const $row = $(this);
        totalScore += selectedQuestionScore(questionPayloadFromRow($row));
        const difficulty = String($row.attr("data-difficulty") || "");
        if (difficulty === "easy") easy += 1;
        else if (difficulty === "medium") medium += 1;
        else hard += 1;
      });

      const countPercent = questionTarget > 0 ? (questionCount / questionTarget) * 100 : 0;
      const scorePercent = scoreTarget > 0 ? (totalScore / scoreTarget) * 100 : 0;
      const easyPercent = questionCount > 0 ? (easy / questionCount) * 100 : 0;
      const mediumPercent = questionCount > 0 ? (medium / questionCount) * 100 : 0;
      const hardPercent = questionCount > 0 ? (hard / questionCount) * 100 : 0;

      setDesignRingSegment("count", countPercent, 0);
      setDesignRingSegment("score", scorePercent, 0);
      setDesignRingSegment("easy", easyPercent, 0);
      setDesignRingSegment("medium", mediumPercent, easyPercent);
      setDesignRingSegment("hard", hardPercent, easyPercent + mediumPercent);

      $stageThree.find('[data-hst-design-value="count"]').text(`${faDecimal(questionCount)} / ${faDecimal(questionTarget)}`);
      $stageThree.find('[data-hst-design-value="score"]').text(`${faDecimal(totalScore)} / ${faDecimal(scoreTarget)}`);
      $stageThree.find('[data-hst-design-value="easy"]').text(`آسان ${faDecimal(easy)}`);
      $stageThree.find('[data-hst-design-value="medium"]').text(`متوسط ${faDecimal(medium)}`);
      $stageThree.find('[data-hst-design-value="hard"]').text(`سخت ${faDecimal(hard)}`);
      $stageThree.find('[data-hst-design-ring="count"]').attr("aria-label", `تعداد سوالات انتخاب‌شده: ${faDecimal(questionCount)} از ${faDecimal(questionTarget)}`);
      $stageThree.find('[data-hst-design-ring="score"]').attr("aria-label", `بارم سوالات انتخاب‌شده: ${faDecimal(totalScore)} از ${faDecimal(scoreTarget)} نمره`);
      $stageThree.find('[data-hst-design-ring="difficulty"]').attr("aria-label", `آسان ${faDecimal(easy)}، متوسط ${faDecimal(medium)}، سخت ${faDecimal(hard)}`);
    }


    function examTypeLabel(value) {
      const labels = {
        continuous: "مستمر",
        midterm: "میان ترم",
        final_first: "پایانی اول",
        final_second: "پایانی دوم",
        final: "پایانی",
        quiz: "کوئیز",
        custom: "اختصاصی",
      };
      return labels[String(value || "")] || "آزمون";
    }

    function gradeDisplay(value) {
      const labels = { tenth: "دهم", eleventh: "یازدهم", twelfth: "دوازدهم" };
      return labels[String(value || "")] || String(value || "");
    }

    function majorDisplay(value) {
      const labels = {
        experimental: "علوم تجربی",
        math: "ریاضی و فیزیک",
        humanities: "ادبیات و علوم انسانی",
      };
      return labels[String(value || "")] || String(value || "");
    }

    function selectedExamFromOption($option) {
      if (!$option || !$option.length || !$option.val()) return null;
      return {
        id: Number($option.val()),
        title: String($option.attr("data-title") || $option.text() || "آزمون"),
        lessonId: Number($option.attr("data-lesson-id") || 0),
        classId: Number($option.attr("data-class-id") || 0),
        lessonName: String($option.attr("data-lesson-name") || ""),
        className: String($option.attr("data-class-name") || ""),
        examDate: String($option.attr("data-exam-date") || ""),
        duration: Number($option.attr("data-duration") || 0),
        teacherName: String($option.attr("data-teacher-name") || "مدیر سامانه"),
        grade: String($option.attr("data-grade") || ""),
        major: String($option.attr("data-major") || ""),
        examType: String($option.attr("data-exam-type") || ""),
      };
    }

    function selectedQuestionsForPaper() {
      let ids = $selectedQuestionList.find("[data-hst-selected-question]").map(function () {
        return Number($(this).attr("data-question-id"));
      }).get().filter(Number.isFinite);
      if (!ids.length) ids = selectedQuestionIdsInOrder();
      return ids.map((id, index) => {
        const question = questionPayloadFromRow($rows.filter(`[data-question-id="${id}"]`).first());
        if (!question.id) return null;
        return Object.assign({}, question, {
          number: index + 1,
          score: selectedQuestionScore(question),
        });
      }).filter(Boolean);
    }

    function answerTextForPaper(question) {
      const type = String(question.question_type || "");
      const data = question.answer_data && typeof question.answer_data === "object" ? question.answer_data : {};
      if (type === "multiple_choice") {
        const choices = Array.isArray(data.choices) ? data.choices : [];
        const correct = Number(data.correct_index);
        return `گزینه ${toFa(correct + 1)} (${String(choices[correct] || "—")})`;
      }
      if (type === "true_false") return `گزینه ${String(data.correct || "true") === "false" ? "۲ (غلط)" : "۱ (صحیح)"}`;
      if (type === "fill_blank") {
        const answers = Array.isArray(data.answers) ? data.answers.filter((item) => String(item || "").trim()) : [];
        return `جاهای خالی به ترتیب: ${answers.join("، ") || "—"}`;
      }
      if (type === "short_answer") return `پاسخ کوتاه مورد انتظار: ${String(data.answer || "—")}`;
      return `پاسخ تشریحی / راهنمای بارم‌بندی: ${String(data.guide || "—")}`;
    }

    function questionResponseMarkup(question) {
      const type = String(question.question_type || "");
      const data = question.answer_data && typeof question.answer_data === "object" ? question.answer_data : {};
      if (type === "multiple_choice") {
        const choices = Array.isArray(data.choices) ? data.choices.slice(0, 4) : [];
        const letters = ["الف", "ب", "ج", "د"];
        const stackChoices = choices.some((choice) => String(choice || "").replace(/\s+/g, " ").trim().length > 34);
        return `<div class="hst-exam-paper__choices${stackChoices ? " is-stacked" : ""}">${choices.map((choice, index) => `<span${stackChoices ? ' class="is-wide"' : ""}>${letters[index]}ـ ${esc(choice || "—")}</span>`).join("")}</div>`;
      }
      if (type === "true_false") {
        return '<div class="hst-exam-paper__true-false"><span>الف) صحیح <i></i></span><span>ب) غلط <i></i></span></div>';
      }
      if (type === "short_answer") return '<div class="hst-exam-paper__answer-line">پاسخ: ............................................................................................................................</div>';
      if (type === "essay") {
        const lines = Math.max(2, Math.min(6, Math.ceil(Number(question.score || 1))));
        return `<div class="hst-exam-paper__essay-space" style="--hst-paper-lines:${lines}"></div>`;
      }
      return "";
    }

    function examPaperHeaderMarkup(kind, totalScore) {
      const config = window.hstPrintConfig || {};
      const schoolName = String(config.schoolName || document.title || "مدرسه");
      const managerName = String(config.managerName || "مدیر مدرسه");
      const exam = selectedExam || {};
      const isAnswers = kind === "answers";
      const centerTitle = isAnswers ? "کلید و راهنمای تصحیح آزمون" : schoolName;
      return `<header class="hst-exam-paper__header">
        <div class="hst-exam-paper__header-grid">
          <div><b>وزارت آموزش و پرورش</b><span>${esc(schoolName)}</span>${isAnswers ? `<span>طراح آزمون: ${esc(exam.teacherName || managerName)}</span>` : ""}</div>
          <div class="hst-exam-paper__header-center"><b>بسمه تعالی</b><strong>${esc(centerTitle)}</strong>${isAnswers ? `<span>${esc(schoolName)}</span>` : ""}</div>
          <div><b>تاریخ آزمون: ${esc(exam.examDate || "................")}</b><span>مدت آزمون: ${toFa(exam.duration || 0)} دقیقه</span>${isAnswers ? `<span>کلاس: ${esc(exam.className || "................")}</span>` : ""}</div>
        </div>
        <div class="hst-exam-paper__identity">
          ${isAnswers
            ? `<span>نام درس: ${esc(exam.lessonName || "................")}</span><span>نوبت امتحانی: ${esc(examTypeLabel(exam.examType))}</span><span>بارم کل: ${faDecimal(totalScore)} نمره</span>`
            : `<span>نام و نام خانوادگی: ................................</span><span>نام پدر: ....................</span><span>کلاس: ${esc(exam.className || "................")}</span><span>رشته: ${esc(majorDisplay(exam.major) || "................")}</span><span>آزمون درس: ${esc(exam.lessonName || "................")}</span><span>نوبت امتحانی: ${esc(examTypeLabel(exam.examType))}</span><span>بارم: ${faDecimal(totalScore)} نمره</span>`}
        </div>
      </header>`;
    }

    function examPaperMarkup(kind) {
      const questions = selectedQuestionsForPaper();
      const totalScore = questions.reduce((sum, question) => sum + Number(question.score || 0), 0);
      const isAnswers = kind === "answers";
      const rows = questions.map((question) => {
        const body = isAnswers
          ? `<div class="hst-exam-paper__answer${String(question.question_type || "") === "essay" ? " hst-exam-paper__answer--essay" : ""}">${esc(answerTextForPaper(question))}</div>`
          : `<div class="hst-exam-paper__question"><div>${toFa(question.number)}ـ ${String(question.question_text || "")}</div>${questionResponseMarkup(question)}</div>`;
        return isAnswers
          ? `<tr><td class="hst-exam-paper__number">${toFa(question.number)}</td><td>${body}</td><td class="hst-exam-paper__score">${faDecimal(question.score)}</td></tr>`
          : `<tr><td>${body}</td><td class="hst-exam-paper__score">${faDecimal(question.score)}</td></tr>`;
      }).join("");
      return `<article class="hst-exam-paper" dir="rtl" data-hst-exam-paper-kind="${kind}">
        ${examPaperHeaderMarkup(kind, totalScore)}
        <table class="hst-exam-paper__table">
          <thead><tr>${isAnswers ? "<th>ردیف</th><th>پاسخ صحیح و راهنمای تصحیح</th><th>بارم</th>" : "<th>شرح سوالات</th><th>بارم</th>"}</tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </article>`;
    }

    function examPaperPdfPayload(kind) {
      const questions = selectedQuestionsForPaper().map((question) => ({
        number: question.number,
        score: Number(question.score || 0),
        type: String(question.question_type || ""),
        question: $("<div>").html(String(question.question_text || "")).text().replace(/\s+/g, " ").trim(),
        answer: answerTextForPaper(question),
        choices: question.answer_data && Array.isArray(question.answer_data.choices) ? question.answer_data.choices.slice(0, 4) : [],
      }));
      return {
        kind,
        exam: Object.assign({}, selectedExam || {}, {
          schoolName: String((window.hstPrintConfig || {}).schoolName || "مدرسه"),
          managerName: String((window.hstPrintConfig || {}).managerName || "مدیر مدرسه"),
        }),
        questions,
        fallbackHtml: examPaperMarkup(kind),
        filename: `${kind === "answers" ? "راهنمای-تصحیح" : "نمونه-سوال"}-${String((selectedExam || {}).title || "آزمون").replace(/\s+/g, "-")}.pdf`,
      };
    }

    function renderExamPaperPreviewPager() {
      const totalPages = paperPreviewPages.length;
      const $numbers = $paperPreviewPager.find(".hst-page-numbers");

      if (totalPages <= 1) {
        $paperPreviewPager.prop("hidden", true);
        $numbers.empty();
        return;
      }

      const buttons = [];
      const start = Math.max(1, paperPreviewPage - 2);
      const end = Math.min(totalPages, paperPreviewPage + 2);

      if (start > 1) {
        buttons.push('<button type="button" class="hst-page-number" data-page="1">1</button>');
        if (start > 2) buttons.push('<span class="hst-page-dots">…</span>');
      }
      for (let page = start; page <= end; page += 1) {
        buttons.push(
          '<button type="button" class="hst-page-number' + (page === paperPreviewPage ? ' is-active' : '') +
          '" data-page="' + page + '">' + page + '</button>'
        );
      }
      if (end < totalPages) {
        if (end < totalPages - 1) buttons.push('<span class="hst-page-dots">…</span>');
        buttons.push('<button type="button" class="hst-page-number" data-page="' + totalPages + '">' + totalPages + '</button>');
      }

      $numbers.html(buttons.join(""));
      $paperPreviewPager.prop("hidden", false);
      $paperPreviewPager.find(".hst-page-prev").prop("disabled", paperPreviewPage <= 1);
      $paperPreviewPager.find(".hst-page-next").prop("disabled", paperPreviewPage >= totalPages);
    }

    function showExamPaperPreviewPage(page, scrollToPreview) {
      const totalPages = paperPreviewPages.length;
      if (!totalPages) {
        paperPreviewPage = 1;
        renderExamPaperPreviewPager();
        return;
      }

      paperPreviewPage = Math.min(totalPages, Math.max(1, Number(page) || 1));
      $paperPreviewBody.find("[data-hst-exam-paper-preview-page]").each(function (index) {
        $(this).prop("hidden", index + 1 !== paperPreviewPage);
      });
      renderExamPaperPreviewPager();

      if (scrollToPreview && $paperPreviewCard.length && $paperPreviewCard.get(0).scrollIntoView) {
        $paperPreviewCard.get(0).scrollIntoView({ behavior: "smooth", block: "start" });
      }
    }

    function resetExamPaperPreview() {
      paperPreviewRequest += 1;
      paperPreviewPages = [];
      paperPreviewPage = 1;
      $paperPreviewCard.prop("hidden", true);
      $paperPreviewBody.empty();
      $paperPreviewLoading.prop("hidden", true);
      $paperPreviewPager.prop("hidden", true).find(".hst-page-numbers").empty();
      $stageFour.find("[data-hst-exam-paper-preview]")
        .attr("aria-pressed", "false")
        .removeClass("hst-btn--primary")
        .addClass("hst-btn--soft");
    }

    function renderExamPaperPreview(kind) {
      if (!selectedExam) {
        HST.toast("ابتدا آزمون مقصد را انتخاب کنید.", "warning");
        return;
      }
      if (!window.HSTPrint || typeof window.HSTPrint.examPaperPreview !== "function") {
        HST.toast("ابزار پیش‌نمایش فایل در دسترس نیست.", "error");
        return;
      }

      const previewKind = kind === "answers" ? "answers" : "questions";
      const requestId = ++paperPreviewRequest;
      paperPreviewPages = [];
      paperPreviewPage = 1;
      $stageFour.find("[data-hst-exam-paper-preview]")
        .attr("aria-pressed", "false")
        .removeClass("hst-btn--primary")
        .addClass("hst-btn--soft");
      $stageFour.find(`[data-hst-exam-paper-preview="${previewKind}"]`)
        .attr("aria-pressed", "true")
        .removeClass("hst-btn--soft")
        .addClass("hst-btn--primary");
      $paperPreviewTitle.text(previewKind === "answers" ? "پیش‌نمایش راهنمای تصحیح" : "پیش‌نمایش نمونه سوال");
      $paperPreviewSubtitle.text(`${selectedExam.title} — ${selectedExam.lessonName} — ${selectedExam.className}`);
      $paperPreviewBody.empty();
      $paperPreviewPager.prop("hidden", true).find(".hst-page-numbers").empty();
      $paperPreviewCard.prop("hidden", false);
      $paperPreviewLoading.prop("hidden", false);

      window.HSTPrint.examPaperPreview(examPaperPdfPayload(previewKind)).then(function (result) {
        if (requestId !== paperPreviewRequest) return;
        $paperPreviewLoading.prop("hidden", true);
        paperPreviewPages = result && Array.isArray(result.pages) ? result.pages : [];
        if (!paperPreviewPages.length) {
          $paperPreviewBody.html('<p class="hst-alert hst-empty-state">صفحه‌ای برای پیش‌نمایش تولید نشد.</p>');
          renderExamPaperPreviewPager();
          return;
        }
        paperPreviewPages.forEach(function (page, index) {
          const title = `${previewKind === "answers" ? "راهنمای تصحیح" : "نمونه سوال"} - صفحه ${toFa(page.number)} از ${toFa(page.total)}`;
          const $figure = $('<figure data-hst-exam-paper-preview-page></figure>').prop("hidden", index !== 0);
          const $image = $("<img>", {
            src: page.dataUrl,
            alt: title,
            loading: index === 0 ? "eager" : "lazy",
            decoding: "async",
          });
          $figure.append($image);
          $paperPreviewBody.append($figure);
        });
        showExamPaperPreviewPage(1, true);
      }).catch(function () {
        if (requestId !== paperPreviewRequest) return;
        $paperPreviewLoading.prop("hidden", true);
        paperPreviewPages = [];
        $paperPreviewPager.prop("hidden", true).find(".hst-page-numbers").empty();
        $paperPreviewBody.html('<p class="hst-alert hst-alert--danger">ساخت پیش‌نمایش با خطا روبه‌رو شد.</p>');
      });
    }

    async function downloadExamPaper(kind) {
      if (!selectedExam) {
        HST.toast("ابتدا آزمون مقصد را انتخاب کنید.", "warning");
        return;
      }

      await withExamDownloadLoader(async function (progress) {
        if (window.HSTPrint && typeof window.HSTPrint.examPaperPdf === "function") {
          const payload = examPaperPdfPayload(kind);
          payload.onProgress = function (percent, text) {
            if (!progress) return;
            progress.update(Math.min(99, 5 + Math.round((Math.max(0, Math.min(100, Number(percent) || 0)) / 100) * 94)), text || "در حال ساخت فایل آزمون...", "ساخت PDF آزمون");
          };
          await window.HSTPrint.examPaperPdf(payload);
          return;
        }
        if (window.HSTPrint && typeof window.HSTPrint.printHtml === "function") {
          window.HSTPrint.printHtml(examPaperMarkup(kind), { title: kind === "answers" ? "راهنمای تصحیح" : "نمونه سوال" });
          return;
        }
        throw new Error("exam_download_tool_missing");
      }).catch(function (error) {
        console.error("Exam paper download failed:", error);
        HST.toast("دریافت فایل آزمون انجام نشد.", "error");
      });
    }

    function prepareTransferModal(ids, purpose) {
      const selectedPairs = $rows.find("[data-hst-question-select]:checked").map(function () {
        const $row = $(this).closest("[data-hst-question-row]");
        const classId = String($row.attr("data-class-id") || "");
        const lessonId = String($row.attr("data-lesson-id") || "");
        return classId && lessonId ? `${classId}:${lessonId}` : "";
      }).get().filter((value, index, values) => value && values.indexOf(value) === index);
      const onePair = selectedPairs.length === 1 ? selectedPairs[0].split(":") : [];
      const classId = onePair[0] || "";
      const lessonId = onePair[1] || "";
      const $examSelect = $transferModal.find('[name="exam_id"]');
      const $examField = $transferModal.find('[data-hst-question-transfer-field]');
      const $transferSubmit = $transferModal.find('[data-hst-question-transfer-submit]');
      let compatibleExams = 0;
      transferPurpose = purpose === "final" ? "final" : "direct";
      $transferModal.find("#hst-question-transfer-title").text(transferPurpose === "final" ? "انتخاب آزمون جهت تزریق سوالات" : "انتقال سؤالات انتخابی به آزمون");
      $transferSubmit.text(transferPurpose === "final" ? "انتخاب آزمون و ادامه" : "انتقال به آزمون");
      $examSelect.val("").find("option").each(function () {
        const $option = $(this);
        if (!$option.val()) {
          $option.prop("hidden", false).prop("disabled", false);
          return;
        }

        let compatible = false;
        if (transferPurpose === "final") {
          const sameGrade = !activeBlueprint.grade
            || String($option.attr("data-grade") || "") === String(activeBlueprint.grade);
          const sameMajor = !activeBlueprint.major
            || String($option.attr("data-major") || "") === String(activeBlueprint.major);
          const sameSubject = !activeBlueprint.subject_title
            || subjectMatches($option.attr("data-lesson-name"), activeBlueprint.subject_title);
          compatible = sameGrade && sameMajor && sameSubject;
        } else {
          compatible = Boolean(classId && lessonId)
            && String($option.attr("data-class-id") || "") === classId
            && String($option.attr("data-lesson-id") || "") === lessonId;
        }

        $option.prop("hidden", !compatible).prop("disabled", !compatible);
        if (compatible) compatibleExams += 1;
      });
      const hasCompatibleExam = compatibleExams > 0;
      $examSelect.prop("disabled", !hasCompatibleExam).prop("required", hasCompatibleExam);
      $examField.prop("hidden", !hasCompatibleExam);
      $transferSubmit.prop("hidden", !hasCompatibleExam).prop("disabled", !hasCompatibleExam);

      let summary = "";
      if (transferPurpose === "final") {
        summary = compatibleExams > 0
          ? `${toFa(ids.length)} سؤال برای تزریق آماده است؛ آزمون مقصد هم‌پایه، هم‌رشته و هم‌درس را انتخاب کنید.`
          : "آزمون فعالی مطابق پایه، رشته و درس بودجه‌بندی‌شده پیدا نشد.";
      } else if (!onePair.length) {
        summary = "برای انتقال مستقیم، همه سؤال‌های انتخابی باید مربوط به یک درس و کلاس واحد باشند.";
      } else {
        summary = compatibleExams > 0
          ? `${toFa(ids.length)} سؤال برای انتقال به آزمون همان درس و کلاس آماده است.`
          : "آزمون فعالی برای درس و کلاس سؤال‌های انتخاب‌شده پیدا نشد.";
      }
      $transferModal.find("[data-hst-question-transfer-summary]").text(summary);
      openModal($transferModal);
    }

    function activeSubjectData() {
      const grade = curriculum[String(activeBlueprint.grade || "")] || {};
      const major = grade.majors && grade.majors[String(activeBlueprint.major || "")]
        ? grade.majors[String(activeBlueprint.major || "")]
        : {};
      return major.subjects && major.subjects[String(activeBlueprint.subject || "")]
        ? major.subjects[String(activeBlueprint.subject || "")]
        : null;
    }

    function activeBlueprintTopics() {
      const subject = activeSubjectData();
      const selected = Array.isArray(activeBlueprint.topics) ? activeBlueprint.topics.map(String) : [];
      const selectedSet = new Set(selected);
      const topics = [];
      subjectUnits(subject).forEach((unit) => {
        (Array.isArray(unit.topics) ? unit.topics : []).forEach((topic) => {
          const id = String(topic.id || "");
          if (!selectedSet.size || selectedSet.has(id)) {
            topics.push({
              id,
              title: String(topic.title || id),
              unitId: String(unit.id || ""),
            });
          }
        });
      });
      return topics;
    }

    function rowMatchesActiveBlueprint($row) {
      const scopedSubject = String(activeBlueprint.subject_title || "");
      const scopedTopics = Array.isArray(activeBlueprint.topics) ? activeBlueprint.topics.map(String) : [];
      const scopedUnits = blueprintUnits(activeBlueprint);
      const rowCurriculumSubject = String($row.attr("data-curriculum-subject") || "");
      const rowCurriculumChapter = String($row.attr("data-curriculum-chapter") || "");
      const rowCurriculumTopics = String($row.attr("data-curriculum-topics") || "").split(",").filter(Boolean);
      const topicMatches = !scopedTopics.length || rowCurriculumTopics.some((topic) => scopedTopics.includes(topic));
      const curriculumMatches = !rowCurriculumSubject || (
        rowCurriculumSubject === String(activeBlueprint.subject || "")
        && (!rowCurriculumChapter || !scopedUnits.length || scopedUnits.includes(rowCurriculumChapter))
        && topicMatches
      );
      return (!activeBlueprint.grade || String($row.attr("data-grade")) === String(activeBlueprint.grade))
        && (!activeBlueprint.major || String($row.attr("data-major")) === String(activeBlueprint.major))
        && (!scopedSubject || subjectMatches($row.attr("data-lesson-name"), scopedSubject))
        && curriculumMatches;
    }

    function shuffled(items) {
      const values = Array.isArray(items) ? items.slice() : [];
      for (let index = values.length - 1; index > 0; index -= 1) {
        const randomIndex = Math.floor(Math.random() * (index + 1));
        const current = values[index];
        values[index] = values[randomIndex];
        values[randomIndex] = current;
      }
      return values;
    }

    function latinDigits(value) {
      return String(value || "")
        .replace(/[۰-۹]/g, (digit) => String("۰۱۲۳۴۵۶۷۸۹".indexOf(digit)))
        .replace(/[٠-٩]/g, (digit) => String("٠١٢٣٤٥٦٧٨٩".indexOf(digit)));
    }

    function lessonNumber(value) {
      const text = latinDigits(value);
      const lessonMatch = text.match(/درس\s*(\d+)/i);
      if (lessonMatch) return Number(lessonMatch[1]);
      return null;
    }

    function assessmentWeightForTopic(topic, entries) {
      const topicNumber = lessonNumber(topic.title);
      let match = null;
      if (topicNumber !== null) {
        match = entries.find((entry) => lessonNumber(entry && entry.goal) === topicNumber) || null;
      }
      if (!match) {
        const topicTitle = normalized(topic.title).replace(/^درس\s+[^:：]+[:：]?\s*/u, "");
        match = entries.find((entry) => {
          const goal = normalized(entry && entry.goal);
          return topicTitle && goal && (goal.includes(topicTitle) || topicTitle.includes(goal));
        }) || null;
      }
      const score = Number(match && match.score);
      return Number.isFinite(score) && score > 0 ? score : null;
    }

    function automaticTopicWeights(topics) {
      const subject = activeSubjectData() || {};
      const assessment = subject.assessment && typeof subject.assessment === "object" ? subject.assessment : {};
      const first = Array.isArray(assessment.first_term) ? assessment.first_term : [];
      const second = Array.isArray(assessment.second_term) ? assessment.second_term : [];
      const firstWeights = topics.map((topic) => assessmentWeightForTopic(topic, first));
      const secondWeights = topics.map((topic) => assessmentWeightForTopic(topic, second));
      const firstMatched = firstWeights.filter((weight) => weight !== null).length;
      const secondMatched = secondWeights.filter((weight) => weight !== null).length;
      let weights = [];
      let term = "equal";

      if (topics.length && first.length && firstMatched === topics.length) {
        weights = firstWeights;
        term = "first";
      } else if (second.length && secondMatched > 0 && secondMatched >= firstMatched) {
        weights = secondWeights;
        term = "second";
      } else if (first.length && firstMatched > 0) {
        weights = firstWeights;
        term = "first";
      }

      const matchedValues = weights.filter((weight) => Number.isFinite(weight) && weight > 0);
      const fallback = matchedValues.length
        ? matchedValues.reduce((sum, weight) => sum + weight, 0) / matchedValues.length
        : 1;

      return {
        term,
        weights: topics.reduce((result, topic, index) => {
          const weight = Number(weights[index]);
          result[topic.id] = Number.isFinite(weight) && weight > 0 ? weight : fallback;
          return result;
        }, {}),
      };
    }

    function allocateWeightedIntegers(plans, target, minimumByPlan) {
      const activePlans = plans.filter((plan) => Number(plan.capacity || 0) > 0);
      const totalCapacity = activePlans.reduce((sum, plan) => sum + Number(plan.capacity || 0), 0);
      const safeTarget = Math.max(0, Math.min(Math.floor(Number(target) || 0), totalCapacity));
      const allocation = {};
      activePlans.forEach((plan) => { allocation[plan.id] = 0; });
      if (!safeTarget || !activePlans.length) return allocation;

      if (minimumByPlan && activePlans.length <= safeTarget) {
        activePlans.forEach((plan) => { allocation[plan.id] = 1; });
      }

      const totalWeight = activePlans.reduce((sum, plan) => sum + Math.max(0.0001, Number(plan.weight) || 0), 0);
      let assigned = Object.values(allocation).reduce((sum, value) => sum + value, 0);
      while (assigned < safeTarget) {
        const candidates = activePlans.filter((plan) => allocation[plan.id] < Number(plan.capacity || 0));
        if (!candidates.length) break;
        candidates.sort((left, right) => {
          const leftQuota = (Math.max(0.0001, Number(left.weight) || 0) / totalWeight) * safeTarget;
          const rightQuota = (Math.max(0.0001, Number(right.weight) || 0) / totalWeight) * safeTarget;
          const leftNeed = leftQuota - allocation[left.id];
          const rightNeed = rightQuota - allocation[right.id];
          if (rightNeed !== leftNeed) return rightNeed - leftNeed;
          return Math.random() - 0.5;
        });
        allocation[candidates[0].id] += 1;
        assigned += 1;
      }
      return allocation;
    }

    function automaticCandidatePriority($row, difficultyCounts, typeCounts) {
      const difficulty = String($row.attr("data-difficulty") || "hard");
      const type = String($row.attr("data-question-type") || "essay");
      const difficultyTargets = { easy: 0.25, medium: 0.35, hard: 0.25, conceptual: 0.15 };
      const typeTargets = { multiple_choice: 0.2, true_false: 0.2, fill_blank: 0.2, short_answer: 0.2, essay: 0.2 };
      const difficultyLoad = Number(difficultyCounts[difficulty] || 0) / Number(difficultyTargets[difficulty] || 0.2);
      const typeLoad = Number(typeCounts[type] || 0) / Number(typeTargets[type] || 0.2);
      return difficultyLoad + typeLoad + Math.random() * 0.35;
    }

    function distributeAutomaticScores(selected, plans, scoreTarget) {
      const quarterUnits = Math.max(selected.length, Math.round((Number(scoreTarget) || 20) * 4));
      const selectedByTopic = {};
      selected.forEach((item) => {
        if (!selectedByTopic[item.topicId]) selectedByTopic[item.topicId] = [];
        selectedByTopic[item.topicId].push(item);
      });
      const scorePlans = plans.filter((plan) => selectedByTopic[plan.id] && selectedByTopic[plan.id].length).map((plan) => ({
        id: plan.id,
        weight: plan.weight,
        capacity: quarterUnits,
      }));
      const unitsByTopic = {};
      scorePlans.forEach((plan) => { unitsByTopic[plan.id] = selectedByTopic[plan.id].length; });
      let assignedUnits = Object.values(unitsByTopic).reduce((sum, units) => sum + units, 0);
      const totalWeight = scorePlans.reduce((sum, plan) => sum + Math.max(0.0001, Number(plan.weight) || 0), 0) || 1;

      while (assignedUnits < quarterUnits && scorePlans.length) {
        const ranked = scorePlans.slice().sort((left, right) => {
          const leftQuota = (Math.max(0.0001, Number(left.weight) || 0) / totalWeight) * quarterUnits;
          const rightQuota = (Math.max(0.0001, Number(right.weight) || 0) / totalWeight) * quarterUnits;
          return (rightQuota - unitsByTopic[right.id]) - (leftQuota - unitsByTopic[left.id]);
        });
        unitsByTopic[ranked[0].id] += 1;
        assignedUnits += 1;
      }

      Object.keys(selectedByTopic).forEach((topicId) => {
        const questions = shuffled(selectedByTopic[topicId]);
        const units = Number(unitsByTopic[topicId] || questions.length);
        const base = Math.floor(units / questions.length);
        const remainder = units % questions.length;
        questions.forEach((item, index) => {
          selectedQuestionScores[String(item.id)] = (base + (index < remainder ? 1 : 0)) / 4;
        });
      });
    }

    function buildAutomaticExam() {
      const topicEntries = activeBlueprintTopics();
      const selectedTopicIds = new Set(topicEntries.map((topic) => topic.id));
      const allCandidateRows = $rows.filter(function () { return rowMatchesActiveBlueprint($(this)); }).get();
      if (!allCandidateRows.length) {
        HST.toast("در محدوده بودجه‌بندی انتخاب‌شده سؤالی برای ساخت آزمون اتوماتیک وجود ندارد.", "warning");
        return;
      }

      // Questions are stored per lesson/class. Build from one compatible pair
      // so the result can always be injected into a real destination exam.
      const examPairs = new Set($transferModal.find('[name="exam_id"] option[value]').map(function () {
        return `${String($(this).attr("data-class-id") || "")}:${String($(this).attr("data-lesson-id") || "")}`;
      }).get().filter(Boolean));
      const groupedCandidates = {};
      allCandidateRows.forEach((row) => {
        const $row = $(row);
        const pair = `${String($row.attr("data-class-id") || "")}:${String($row.attr("data-lesson-id") || "")}`;
        if (!groupedCandidates[pair]) groupedCandidates[pair] = [];
        groupedCandidates[pair].push(row);
      });
      const selectedPair = Object.keys(groupedCandidates).sort((left, right) => {
        const compatibility = Number(examPairs.has(right)) - Number(examPairs.has(left));
        return compatibility || groupedCandidates[right].length - groupedCandidates[left].length;
      })[0];
      const candidateRows = selectedPair ? groupedCandidates[selectedPair] : [];
      if (!candidateRows.length) {
        HST.toast("برای ساخت آزمون اتوماتیک، سؤال سازگار با یک درس و کلاس واحد پیدا نشد.", "warning");
        return;
      }

      const pools = {};
      topicEntries.forEach((topic) => { pools[topic.id] = []; });
      pools.__general = [];
      candidateRows.forEach((row) => {
        const $row = $(row);
        const rowTopics = String($row.attr("data-curriculum-topics") || "").split(",").filter(Boolean);
        const topicId = rowTopics.find((topic) => selectedTopicIds.has(topic)) || "__general";
        if (!pools[topicId]) pools[topicId] = [];
        pools[topicId].push($row);
      });

      const weightResult = automaticTopicWeights(topicEntries);
      const plans = topicEntries.map((topic) => ({
        id: topic.id,
        title: topic.title,
        weight: Number(weightResult.weights[topic.id] || 1),
        capacity: (pools[topic.id] || []).length,
      }));
      if (pools.__general.length) {
        const averageWeight = plans.length
          ? plans.reduce((sum, plan) => sum + plan.weight, 0) / plans.length
          : 1;
        plans.push({ id: "__general", title: "سؤالات عمومی", weight: averageWeight, capacity: pools.__general.length });
      }

      const targetCount = Number($stageThree.find('[data-hst-design-ring="count"]').attr("data-target") || 20);
      const targetScore = Number($stageThree.find('[data-hst-design-ring="score"]').attr("data-target") || 20);
      const countAllocation = allocateWeightedIntegers(plans, targetCount, true);
      const usedIds = new Set();
      const selected = [];
      const difficultyCounts = {};
      const typeCounts = {};

      plans.forEach((plan) => {
        const required = Number(countAllocation[plan.id] || 0);
        for (let index = 0; index < required; index += 1) {
          const available = (pools[plan.id] || []).filter(($row) => !usedIds.has(Number($row.attr("data-question-id"))));
          if (!available.length) break;
          available.sort((left, right) => automaticCandidatePriority(left, difficultyCounts, typeCounts) - automaticCandidatePriority(right, difficultyCounts, typeCounts));
          const $row = available[0];
          const id = Number($row.attr("data-question-id"));
          const difficulty = String($row.attr("data-difficulty") || "hard");
          const type = String($row.attr("data-question-type") || "essay");
          usedIds.add(id);
          difficultyCounts[difficulty] = Number(difficultyCounts[difficulty] || 0) + 1;
          typeCounts[type] = Number(typeCounts[type] || 0) + 1;
          selected.push({ id, topicId: plan.id });
        }
      });

      if (selected.length < Math.min(targetCount, candidateRows.length)) {
        const remaining = shuffled(candidateRows.map((row) => $(row))).filter(($row) => !usedIds.has(Number($row.attr("data-question-id"))));
        while (selected.length < Math.min(targetCount, candidateRows.length) && remaining.length) {
          remaining.sort((left, right) => automaticCandidatePriority(left, difficultyCounts, typeCounts) - automaticCandidatePriority(right, difficultyCounts, typeCounts));
          const $row = remaining.shift();
          const id = Number($row.attr("data-question-id"));
          const rowTopics = String($row.attr("data-curriculum-topics") || "").split(",").filter(Boolean);
          const topicId = rowTopics.find((topic) => selectedTopicIds.has(topic)) || "__general";
          const difficulty = String($row.attr("data-difficulty") || "hard");
          const type = String($row.attr("data-question-type") || "essay");
          usedIds.add(id);
          difficultyCounts[difficulty] = Number(difficultyCounts[difficulty] || 0) + 1;
          typeCounts[type] = Number(typeCounts[type] || 0) + 1;
          selected.push({ id, topicId });
        }
      }

      if (!selected.length) {
        HST.toast("ساخت آزمون اتوماتیک در این بودجه‌بندی امکان‌پذیر نیست.", "warning");
        return;
      }

      selectedQuestionScores = {};
      distributeAutomaticScores(selected, plans, targetScore);
      const selectedIdsList = sortQuestionIdsByDefaultType(selected.map((item) => item.id));
      $rows.find("[data-hst-question-select]").prop("checked", false);
      selectedIdsList.forEach((id) => {
        $rows.filter(`[data-question-id="${id}"]`).find("[data-hst-question-select]").prop("checked", true);
      });
      window.sessionStorage.setItem("hstQuestionBankSelection", JSON.stringify(selectedIdsList));
      persistSelectedQuestionScores();
      renderSelectedQuestions();
      renderQuestionDesignSummary();
      updateSelection();
      $selectedQuestionEmpty.prop("hidden", selectedIdsList.length > 0);

      const totalScore = selectedIdsList.reduce((sum, id) => sum + Number(selectedQuestionScores[String(id)] || 0), 0);
      const message = selectedIdsList.length < targetCount
        ? `آزمون اتوماتیک با ${faDecimal(selectedIdsList.length)} سؤال و مجموع بارم ${faDecimal(totalScore)} ساخته شد؛ تعداد سؤال کافی برای رسیدن به هدف ${faDecimal(targetCount)} سؤال وجود نداشت.`
        : `آزمون اتوماتیک با ${faDecimal(selectedIdsList.length)} سؤال و مجموع بارم ${faDecimal(totalScore)} بر اساس بودجه‌بندی فعال ساخته شد.`;
      HST.toast(message, selectedIdsList.length < targetCount ? "warning" : "success");
    }

    function updateSelection() {
      const ids = selectedIds();
      const $visibleChecks = visibleRows().find("[data-hst-question-select]");
      const visibleChecked = $visibleChecks.filter(":checked").length;
      $selectAll.prop("checked", $visibleChecks.length > 0 && visibleChecked === $visibleChecks.length);
      $selectAll.prop("indeterminate", visibleChecked > 0 && visibleChecked < $visibleChecks.length);
      $transferButtons.prop("disabled", ids.length === 0);
      // The selection stage may continue empty because the next stage can
      // build a complete exam automatically from the active blueprint.
      $questionNext.prop("disabled", false);
      $questionDesignNext.prop("disabled", ids.length === 0);
      $selectionStatus.text(
        ids.length ? `${toFa(ids.length)} سؤال برای انتقال انتخاب شده است.` : "هیچ سؤالی انتخاب نشده است."
      );
    }

    function applyFilters() {
      const query = normalized($root.find("[data-hst-question-search]").val());
      const difficulty = String($root.find("[data-hst-question-difficulty]").val() || "");
      const questionType = String($root.find("[data-hst-question-type]").val() || "");
      const scopedSubject = String(activeBlueprint.subject_title || "");
      const scopedTopics = Array.isArray(activeBlueprint.topics) ? activeBlueprint.topics.map(String) : [];
      const scopedUnits = blueprintUnits(activeBlueprint);
      let shown = 0;
      let easyMedium = 0;
      let advanced = 0;

      $rows.each(function () {
        const $row = $(this);
        const rowCurriculumSubject = String($row.attr("data-curriculum-subject") || "");
        const rowCurriculumChapter = String($row.attr("data-curriculum-chapter") || "");
        const rowCurriculumTopics = String($row.attr("data-curriculum-topics") || "").split(",").filter(Boolean);
        const topicMatches = !scopedTopics.length || rowCurriculumTopics.some((topic) => scopedTopics.includes(topic));
        const curriculumMatches = !rowCurriculumSubject || (
          rowCurriculumSubject === String(activeBlueprint.subject || "")
          && (!rowCurriculumChapter || !scopedUnits.length || scopedUnits.includes(rowCurriculumChapter))
          && topicMatches
        );
        const inBlueprint = (!activeBlueprint.grade || String($row.attr("data-grade")) === String(activeBlueprint.grade))
          && (!activeBlueprint.major || String($row.attr("data-major")) === String(activeBlueprint.major))
          && (!scopedSubject || subjectMatches($row.attr("data-lesson-name"), scopedSubject))
          && curriculumMatches;
        $row.attr("data-hst-inline-excluded", inBlueprint ? "0" : "1");
        const matches = inBlueprint
          && (!query || normalized($row.attr("data-search")).includes(query))
          && (!questionType || String($row.attr("data-question-type")) === questionType)
          && (!difficulty || String($row.attr("data-difficulty")) === difficulty);
        if (matches) {
          shown += 1;
          if (["easy", "medium"].includes(String($row.attr("data-difficulty")))) easyMedium += 1;
          else advanced += 1;
        }
      });

      $questionList.prop("hidden", shown === 0);
      $empty.prop("hidden", shown !== 0);
      $root.find('[data-hst-question-stat="total"]').text(toFa(shown));
      $root.find('[data-hst-question-stat="easy-medium"]').text(toFa(easyMedium));
      $root.find('[data-hst-question-stat="advanced"]').text(toFa(advanced));
      if (window.HST && typeof window.HST.refreshInlineFilter === "function") {
        window.HST.refreshInlineFilter("hst-question-bank-list");
      }
      window.requestAnimationFrame(updateSelection);
    }

    function openModal($modal) {
      $modal.removeAttr("hidden").attr("aria-hidden", "false").addClass("is-active");
      $("body").addClass("hst-modal-open");
    }

    function closeModal($modal) {
      $modal.removeClass("is-active").attr("aria-hidden", "true").prop("hidden", true);
      if (!$(".hst-modal.is-active").length) $("body").removeClass("hst-modal-open");
    }

    function filterLessonOptions(clearMismatch) {
      const grade = String($grade.val() || "");
      const major = String($major.val() || "");
      let selectedCompatible = false;

      $lesson.find("option").each(function () {
        const $option = $(this);
        if (!$option.val()) {
          $option.prop("hidden", false).prop("disabled", false);
          return;
        }
        const matches = (!grade || String($option.data("grade")) === grade)
          && (!major || String($option.data("major")) === major);
        $option.prop("hidden", !matches).prop("disabled", !matches);
        if ($option.is(":selected") && matches) selectedCompatible = true;
      });

      if (clearMismatch && $lesson.val() && !selectedCompatible) $lesson.val("");
      $lesson.prop("disabled", !(grade && major));
      $lesson.find("option").first().text(grade && major ? "انتخاب درس" : "ابتدا پایه و رشته را انتخاب کنید");
    }

    function syncProfileFromLesson() {
      const $option = $lesson.find("option:selected");
      if (!$option.val()) return;
      $grade.val(String($option.data("grade") || ""));
      $major.val(String($option.data("major") || ""));
      filterLessonOptions(false);
    }

    function answerMarkup(type, data) {
      data = data || {};
      if (type === "multiple_choice") {
        const choices = Array.isArray(data.choices) ? data.choices : ["", "", "", ""];
        const correct = Number.isInteger(Number(data.correct_index)) ? Number(data.correct_index) : 0;
        return `<fieldset class="hst-question-answer"><div class="hst-question-answer__options">${choices.slice(0, 4).map((choice, index) => `<label class="hst-question-answer__option" style="--hst-answer-ch:${answerWidth(choice)}ch"><input type="radio" name="correct_choice" value="${index}" ${correct === index ? "checked" : ""}><input type="text" data-hst-choice data-hst-fluid-answer value="${esc(choice)}" placeholder="متن گزینه ${["الف", "ب", "ج", "د"][index]}" required></label>`).join("")}</div></fieldset>`;
      }
      if (type === "true_false") {
        const correct = data.correct === "false" ? "false" : "true";
        return `<fieldset class="hst-question-answer"><div class="hst-question-answer__binary"><label class="${correct === "true" ? "is-correct" : ""}"><input type="radio" name="true_false" value="true" ${correct === "true" ? "checked" : ""}><span>درست</span></label><label class="${correct === "false" ? "is-correct" : ""}"><input type="radio" name="true_false" value="false" ${correct === "false" ? "checked" : ""}><span>نادرست</span></label></div></fieldset>`;
      }
      if (type === "fill_blank") {
        const answers = Array.isArray(data.answers) ? data.answers : [];
        return `<fieldset class="hst-question-answer hst-question-answer--blanks"><button type="button" class="hst-btn hst-btn--soft hst-btn--sm" data-hst-add-blank>درج جایگاه خالی جدید (+)</button><div class="hst-question-answer__blanks" data-hst-blank-list>${answers.map((answer, index) => blankRow(index, answer)).join("")}</div></fieldset>`;
      }
      if (type === "short_answer") {
        return `<label class="hst-field hst-question-answer"><input type="text" data-hst-short-answer value="${esc(data.answer || "")}" placeholder="پاسخ کوتاه دقیق و صحیح را وارد کنید…" required></label>`;
      }
      return `<label class="hst-field hst-question-answer"><textarea rows="4" data-hst-essay-guide placeholder="پاسخ نمونه تشریحی یا راهنمای تصحیح را وارد نمایید…" required>${esc(data.guide || "")}</textarea></label>`;
    }

    function answerWidth(value) {
      return Math.max(16, Math.min(42, String(value || "").trim().length + 5));
    }

    function blankRow(index, answer) {
      return `<label style="--hst-answer-ch:${answerWidth(answer)}ch"><input type="text" data-hst-blank-answer data-hst-fluid-answer value="${esc(answer || "")}" placeholder="پاسخ جای‌خالی ${toFa(index + 1)}" required><button type="button" data-hst-remove-blank aria-label="حذف جای‌خالی">×</button></label>`;
    }

    function renderAnswers(type, data) {
      $answerFields.html(answerMarkup(type, data));
    }

    function defaultQuestion() {
      editingQuestionScope = null;
      $form.get(0).reset();
      $form.find('[name="id"]').val("");
      $form.find('[name="score"]').val("1.5");
      $type.val("multiple_choice");
      $form.find('[name="difficulty"]').val("medium");
      $editor.empty();
      answerSeed = {};

      let $preferredLesson = $();
      if (activeBlueprint.grade && activeBlueprint.major && activeBlueprint.subject_title) {
        $lesson.find("option[value]:not([value=''])").each(function () {
          const $option = $(this);
          if (!$preferredLesson.length
            && String($option.data("grade") || "") === String(activeBlueprint.grade)
            && String($option.data("major") || "") === String(activeBlueprint.major)
            && subjectMatches($option.attr("data-lesson-name") || $option.text(), activeBlueprint.subject_title)) {
            $preferredLesson = $option;
          }
        });
      }
      const $firstLesson = $preferredLesson.length
        ? $preferredLesson
        : (activeBlueprint.subject ? $() : $lesson.find("option[value]:not([value=''])").first());
      if ($firstLesson.length) {
        $grade.val(String($firstLesson.data("grade") || ""));
        $major.val(String($firstLesson.data("major") || ""));
        filterLessonOptions(false);
        $lesson.val(String($firstLesson.val()));
      } else if (activeBlueprint.grade && activeBlueprint.major) {
        $grade.val(String(activeBlueprint.grade));
        $major.val(String(activeBlueprint.major));
        filterLessonOptions(true);
        $lesson.val("");
      }
      renderAnswers("multiple_choice", {});
      $editorModal.find("#hst-question-editor-title").text("طراحی و افزودن سؤال جدید به بانک سؤالات");
      $form.find("[data-hst-question-submit]").text("افزودن به بانک سؤالات");
    }

    function editQuestion(question) {
      defaultQuestion();
      editingQuestionScope = {
        chapter: String(question.curriculum_chapter || ""),
        topics: Array.isArray(question.curriculum_topics) ? question.curriculum_topics.map(String) : [],
      };
      $form.find('[name="id"]').val(question.id || "");
      $grade.val(question.grade || "");
      $major.val(question.major || "");
      filterLessonOptions(false);
      $lesson.val(String(question.lesson_id || ""));
      $type.val(question.question_type || "multiple_choice");
      $form.find('[name="difficulty"]').val(question.difficulty || "medium");
      $form.find('[name="score"]').val(question.score || "1.5");
      $editor.html(question.question_text || "");
      answerSeed = question.answer_data || {};
      renderAnswers($type.val(), answerSeed);
      $editorModal.find("#hst-question-editor-title").text("ویرایش سؤال بانک سؤالات");
      $form.find("[data-hst-question-submit]").text("ذخیره تغییرات");
    }

    function collectAnswerData(type) {
      if (type === "multiple_choice") {
        return {
          choices: $answerFields.find("[data-hst-choice]").map(function () { return $(this).val(); }).get(),
          correct_index: parseInt($answerFields.find('[name="correct_choice"]:checked').val(), 10),
        };
      }
      if (type === "true_false") return { correct: String($answerFields.find('[name="true_false"]:checked').val() || "") };
      if (type === "fill_blank") return { answers: $answerFields.find("[data-hst-blank-answer]").map(function () { return $(this).val(); }).get() };
      if (type === "short_answer") return { answer: String($answerFields.find("[data-hst-short-answer]").val() || "") };
      return { guide: String($answerFields.find("[data-hst-essay-guide]").val() || "") };
    }

    $root.on("input change", "[data-hst-question-search], [data-hst-question-type], [data-hst-question-difficulty]", applyFilters);
    $blueprintGrade.on("change", function () { populateMajors(""); });
    $blueprintMajor.on("change", function () { populateSubjects(""); });
    $blueprintSubject.on("change", function () { renderBlueprintTree([], []); });
    $blueprintTree.on("change", "[data-hst-blueprint-all]", function () {
      $blueprintTree.find("[data-hst-blueprint-topic]").prop("checked", this.checked);
      updateBlueprintSelection();
    });
    $blueprintTree.on("change", "[data-hst-blueprint-parent]", function () {
      $(this).closest("[data-unit-id]").find("[data-hst-blueprint-topic]").prop("checked", this.checked);
      updateBlueprintSelection();
    });
    $blueprintTree.on("change", "[data-hst-blueprint-topic]", updateBlueprintSelection);
    $blueprintForm.on("submit", function (event) {
      event.preventDefault();
      const submitter = event.originalEvent && event.originalEvent.submitter
        ? event.originalEvent.submitter
        : $blueprintNext.get(0);
      const $selectedTopics = $blueprintTree.find("[data-hst-blueprint-topic]:checked");
      const topics = $selectedTopics.map(function () { return String($(this).val()); }).get();
      const units = $selectedTopics.map(function () { return String($(this).attr("data-unit-id") || ""); }).get().filter((value, index, values) => value && values.indexOf(value) === index);
      if (!topics.length || !units.length) {
        HST.toast("حداقل یک درس یا بخش را برای بودجه‌بندی آزمون انتخاب کنید.", "warning");
        return;
      }
      HST.request({
        action: "hst_exam_question_blueprint_save",
        data: {
          grade: $blueprintGrade.val(),
          major: $blueprintMajor.val(),
          subject: $blueprintSubject.val(),
          units: JSON.stringify(units),
          topics: JSON.stringify(topics),
        },
        successMessage: true,
        trigger: submitter,
        onSuccess(response) {
          if (response && response.data && response.data.reload) {
            window.location.reload();
            return;
          }
          activeBlueprint = normalizeActiveBlueprint(response && response.data && response.data.blueprint
            ? response.data.blueprint
            : {
                grade: $blueprintGrade.val(),
                major: $blueprintMajor.val(),
                subject: $blueprintSubject.val(),
                subject_title: (subjectEntry() || {}).title || "",
                units,
                chapters: units.filter((unit) => unit.startsWith("c")),
                sections: units.filter((unit) => unit.startsWith("s")),
                chapter: units[0] || "",
                topics,
              });
          applyBlueprintScope();
          showQuestionStage(2);
          applyFilters();
        },
      });
    });
    $root.on("click", "[data-hst-question-blueprint-back]", function () { showQuestionStage(1); });
    $questionNext.on("click", function () {
      const ids = sortQuestionIdsByDefaultType(selectedIds());
      window.sessionStorage.setItem("hstQuestionBankSelection", JSON.stringify(ids));
      renderSelectedQuestions();
      renderQuestionDesignSummary();
      showQuestionStage(3);
      $(document).trigger("hst:question-bank-next", [{ questionIds: ids.slice() }]);
      HST.toast(
        ids.length
          ? "سؤالات انتخابی برای مرحله بعد ذخیره شدند."
          : "وارد مدیریت طراحی سؤالات شدید؛ می‌توانید آزمون را به‌صورت اتوماتیک بسازید.",
        ids.length ? "success" : "info"
      );
    });
    $stageThree.on("click", "[data-hst-question-auto-build]", function () {
      buildAutomaticExam();
    });
    $stageThree.on("click", "[data-hst-question-design-back]", function () {
      showQuestionStage(2);
    });
    $stageThree.on("click", "[data-hst-question-design-next]", function () {
      const ids = persistSelectedQuestionOrder();
      if (!ids.length) return;
      persistSelectedQuestionScores();
      $(document).trigger("hst:question-design-next", [{
        questionIds: ids.slice(),
        questionScores: selectedQuestionScoresFor(ids),
      }]);
      prepareTransferModal(ids, "final");
    });
    $root.on("change", "[data-hst-question-select]", updateSelection);
    $selectAll.on("change", function () {
      visibleRows().find("[data-hst-question-select]").prop("checked", this.checked);
      updateSelection();
    });
    $rows.children("summary").find("input").on("click", function (event) {
      event.stopPropagation();
    });
    $rows.children("summary").find("button").on("click", function (event) {
      event.preventDefault();
    });
    $root.on("click", '[data-hst-pagination-for="hst-question-bank-list"] button', function () {
      window.requestAnimationFrame(updateSelection);
    });

    $stageThree.on("input change", "[data-hst-selected-question-score]", function (event) {
      const $input = $(this);
      const $item = $input.closest("[data-hst-selected-question]");
      const id = Number($item.attr("data-question-id"));
      let score = Number($input.val());
      if (!Number.isFinite(score)) return;
      score = Math.max(0.25, Math.min(100, score));
      selectedQuestionScores[String(id)] = score;
      $item.attr("data-score", String(score));
      persistSelectedQuestionScores();
      renderQuestionDesignSummary();
      if (event.type === "change") $input.val(String(score));
    });

    $stageThree.on("click", "[data-hst-selected-question-remove]", function () {
      const $item = $(this).closest("[data-hst-selected-question]");
      const id = Number($item.attr("data-question-id"));
      $rows.filter(`[data-question-id="${id}"]`).find("[data-hst-question-select]").prop("checked", false);
      $item.remove();
      persistSelectedQuestionOrder();
      renderQuestionDesignSummary();
      updateSelection();
      const hasSelectedQuestions = $selectedQuestionList.find("[data-hst-selected-question]").length > 0;
      $selectedQuestionList.prop("hidden", !hasSelectedQuestions);
      $selectedQuestionEmpty.prop("hidden", hasSelectedQuestions);
    });

    let draggedSelectedQuestion = null;
    $stageThree.on("dragstart", "[data-hst-selected-question]", function (event) {
      if ($(event.target).is("input, textarea, select, button")) {
        event.preventDefault();
        return;
      }
      draggedSelectedQuestion = this;
      $(this).addClass("is-dragging");
      const transfer = event.originalEvent && event.originalEvent.dataTransfer;
      if (transfer) {
        transfer.effectAllowed = "move";
        transfer.setData("text/plain", String($(this).attr("data-question-id") || ""));
      }
    });
    $stageThree.on("dragover", "[data-hst-selected-question-list]", function (event) {
      if (!draggedSelectedQuestion) return;
      event.preventDefault();
      const transfer = event.originalEvent && event.originalEvent.dataTransfer;
      if (transfer) transfer.dropEffect = "move";
      const pointerY = event.originalEvent ? event.originalEvent.clientY : 0;
      const candidates = $(this).find("[data-hst-selected-question]").not(draggedSelectedQuestion).get();
      const next = candidates.find((candidate) => {
        const box = candidate.getBoundingClientRect();
        return pointerY < box.top + box.height / 2;
      });
      if (next) this.insertBefore(draggedSelectedQuestion, next);
      else this.appendChild(draggedSelectedQuestion);
    });
    $stageThree.on("drop", "[data-hst-selected-question-list]", function (event) {
      event.preventDefault();
      persistSelectedQuestionOrder();
    });
    $stageThree.on("dragend", "[data-hst-selected-question]", function () {
      $(this).removeClass("is-dragging");
      draggedSelectedQuestion = null;
      persistSelectedQuestionOrder();
    });

    $root.on("click", "[data-hst-question-open]", function () {
      defaultQuestion();
      openModal($editorModal);
      window.setTimeout(() => $editor.trigger("focus"), 80);
    });

    $root.on("click", "[data-hst-question-edit]", function () {
      try {
        editQuestion(JSON.parse(String($(this).attr("data-question") || "{}")));
        openModal($editorModal);
        window.setTimeout(() => $editor.trigger("focus"), 80);
      } catch (error) {
        HST.toast("اطلاعات سؤال برای ویرایش قابل خواندن نیست.", "error");
      }
    });

    $root.on("click", "[data-hst-question-delete]", function () {
      HST.request({
        action: "hst_exam_question_delete",
        data: { id: $(this).attr("data-id") },
        confirm: { title: "حذف سؤال؟", text: "این سؤال به‌طور دائمی از بانک سؤال حذف می‌شود." },
        successMessage: true,
        reload: true,
        trigger: this,
      });
    });

    $grade.add($major).on("change", function () { filterLessonOptions(true); });
    $lesson.on("change", syncProfileFromLesson);
    $type.on("change", function () {
      answerSeed = {};
      renderAnswers(String($(this).val()), {});
    });

    $answerFields.on("change", '[name="true_false"]', function () {
      $answerFields.find(".hst-question-answer__binary label").removeClass("is-correct");
      $(this).closest("label").addClass("is-correct");
    });

    $answerFields.on("input", "[data-hst-fluid-answer]", function () {
      this.closest("label").style.setProperty("--hst-answer-ch", `${answerWidth(this.value)}ch`);
    });

    $answerFields.on("click", "[data-hst-add-blank]", function () {
      const $list = $answerFields.find("[data-hst-blank-list]");
      const index = $list.find("[data-hst-blank-answer]").length;
      if (index >= 20) {
        HST.toast("حداکثر ۲۰ جای‌خالی قابل تعریف است.", "warning");
        return;
      }
      $list.append(blankRow(index, ""));
      $editor.trigger("focus");
      document.execCommand("insertText", false, ` [[${index + 1}]] `);
      $list.find("[data-hst-blank-answer]").last().trigger("focus");
    });

    $answerFields.on("click", "[data-hst-remove-blank]", function () {
      $(this).closest("label").remove();
      $answerFields.find("[data-hst-blank-list] > label").each(function (index) {
        $(this).find("[data-hst-blank-answer]").attr("placeholder", `پاسخ جای‌خالی ${toFa(index + 1)}`);
      });
    });

    $form.on("click", "[data-hst-editor-command]", function () {
      $editor.trigger("focus");
      document.execCommand(String($(this).attr("data-hst-editor-command")), false, null);
    });

    let formulaRange = null;
    const $formulaBuilder = $form.find("[data-hst-formula-builder]");
    const $formulaInput = $formulaBuilder.find("[data-hst-formula-input]");
    const $formulaPreview = $formulaBuilder.find("[data-hst-formula-preview]");

    function formulaPreview(latex) {
      const replacements = {
        "\\\\times": "×", "\\\\div": "÷", "\\\\pm": "±", "\\\\neq": "≠", "\\\\approx": "≈",
        "\\\\alpha": "α", "\\\\beta": "β", "\\\\gamma": "γ", "\\\\Delta": "Δ", "\\\\theta": "θ",
        "\\\\lambda": "λ", "\\\\mu": "μ", "\\\\pi": "π", "\\\\rho": "ρ", "\\\\sigma": "σ",
        "\\\\phi": "φ", "\\\\omega": "ω", "\\\\infty": "∞", "\\\\partial": "∂", "\\\\in": "∈",
        "\\\\notin": "∉", "\\\\subset": "⊂", "\\\\cup": "∪", "\\\\cap": "∩",
      };
      let result = String(latex || "");
      Object.keys(replacements).forEach((token) => { result = result.split(token).join(replacements[token]); });
      result = result.replace(/\\sqrt\{([^{}]+)\}/g, "√($1)").replace(/\\frac\{([^{}]+)\}\{([^{}]+)\}/g, "($1)/($2)");
      return result.replace(/[{}]/g, "") || "فرمول اینجا نمایش داده می‌شود";
    }

    $form.on("click", "[data-hst-editor-formula]", function () {
      const selection = window.getSelection();
      if (selection && selection.rangeCount && $editor.get(0).contains(selection.anchorNode)) formulaRange = selection.getRangeAt(0).cloneRange();
      $formulaBuilder.prop("hidden", false);
      $formulaInput.trigger("focus");
    });

    $formulaBuilder.on("click", "[data-hst-formula-close]", function () { $formulaBuilder.prop("hidden", true); });
    $formulaBuilder.on("click", "[data-hst-formula-tab]", function () {
      const tab = String($(this).data("hst-formula-tab"));
      $formulaBuilder.find("[data-hst-formula-tab]").removeClass("is-active");
      $(this).addClass("is-active");
      $formulaBuilder.find("[data-hst-formula-symbols]").prop("hidden", true).filter(`[data-hst-formula-symbols="${tab}"]`).prop("hidden", false);
    });
    $formulaBuilder.on("click", "[data-latex]", function () {
      const input = $formulaInput.get(0);
      const snippet = String($(this).data("latex") || "");
      const start = input.selectionStart || input.value.length;
      input.value = input.value.slice(0, start) + snippet + input.value.slice(input.selectionEnd || start);
      input.setSelectionRange(start + snippet.length, start + snippet.length);
      $formulaInput.trigger("input").trigger("focus");
    });
    $formulaInput.on("input", function () { $formulaPreview.text(formulaPreview(this.value)); });
    $formulaBuilder.on("click", "[data-hst-formula-insert]", function () {
      const latex = String($formulaInput.val() || "").trim();
      if (!latex) { HST.toast("ابتدا فرمول را وارد کنید.", "warning"); return; }
      $editor.trigger("focus");
      const selection = window.getSelection();
      if (formulaRange && selection) { selection.removeAllRanges(); selection.addRange(formulaRange); }
      document.execCommand("insertHTML", false, ` <span class="hst-math-formula" data-latex="${esc(latex)}" contenteditable="false">${esc(formulaPreview(latex))}</span>&nbsp;`);
      $formulaInput.val("");
      $formulaPreview.text("فرمول اینجا نمایش داده می‌شود");
      $formulaBuilder.prop("hidden", true);
    });

    const $tableTools = $form.find("[data-hst-question-table-tools]");
    let activeTableCell = null;
    let activeEditorElement = null;

    function positionElementTools() {
      return activeEditorElement;
    }

    function ensureResizableImage(image) {
      const $image = $(image);
      if ($image.parent().hasClass("hst-resizable-image")) return $image.parent().get(0);
      $image.wrap('<span class="hst-resizable-image" contenteditable="false"></span>');
      const $wrapper = $image.parent();
      ["nw", "ne", "sw", "se"].forEach((corner) => $wrapper.append(`<span class="hst-resize-handle" data-corner="${corner}" aria-hidden="true"></span>`));
      return $wrapper.get(0);
    }

    function selectEditorElement(element, cell) {
      $editor.find("td, th, img, table").removeClass("is-selected");
      activeTableCell = cell && $editor.get(0).contains(cell) ? cell : null;
      activeEditorElement = element && $editor.get(0).contains(element) ? element : null;
      if (activeTableCell) {
        $(activeTableCell).addClass("is-selected");
      }
      if (activeEditorElement) {
        $(activeEditorElement).addClass("is-selected");
        $tableTools.find("[data-hst-table-only]").prop("hidden", !$(activeEditorElement).is("table"));
        $tableTools.prop("hidden", false);
        window.requestAnimationFrame(positionElementTools);
      } else {
        $tableTools.prop("hidden", true);
      }
    }

    function selectTableCell(cell) {
      selectEditorElement(cell ? $(cell).closest("table").get(0) : null, cell);
    }

    $form.on("click", "[data-hst-editor-table]", function () {
      $editor.trigger("focus");
      document.execCommand("insertHTML", false, '<table><tbody><tr><td>سلول ۱</td><td>سلول ۲</td></tr><tr><td>سلول ۳</td><td>سلول ۴</td></tr></tbody></table><p><br></p>');
      selectTableCell($editor.find("table").last().find("td").first().get(0));
    });

    $editor.on("click", "td, th", function (event) {
      event.stopPropagation();
      selectTableCell(this);
    });

    $editor.on("click", "img", function (event) {
      event.stopPropagation();
      selectEditorElement(ensureResizableImage(this), null);
    });

    $editor.on("pointerdown", ".hst-resize-handle", function (event) {
      event.preventDefault();
      event.stopPropagation();
      const handle = this;
      const wrapper = handle.closest(".hst-resizable-image");
      const corner = String(handle.getAttribute("data-corner") || "se");
      const startX = event.clientX;
      const startWidth = wrapper.getBoundingClientRect().width;
      const maxWidth = $editor.innerWidth() - 16;
      handle.setPointerCapture(event.pointerId);
      const onMove = (moveEvent) => {
        const direction = corner.endsWith("e") ? 1 : -1;
        const width = Math.max(80, Math.min(maxWidth, startWidth + ((moveEvent.clientX - startX) * direction)));
        wrapper.style.width = `${Math.round(width)}px`;
        positionElementTools();
      };
      const onEnd = () => {
        handle.removeEventListener("pointermove", onMove);
        handle.removeEventListener("pointerup", onEnd);
        handle.removeEventListener("pointercancel", onEnd);
      };
      handle.addEventListener("pointermove", onMove);
      handle.addEventListener("pointerup", onEnd);
      handle.addEventListener("pointercancel", onEnd);
    });

    $editor.on("click", function (event) {
      if (!$(event.target).closest("table, img").length) selectEditorElement(null, null);
    });

    $tableTools.on("click", "[data-hst-table-action]", function () {
      if (!activeTableCell) return;
      const action = String($(this).data("hst-table-action") || "");
      const $cell = $(activeTableCell);
      const $row = $cell.closest("tr");
      const $table = $cell.closest("table");
      const columnIndex = $cell.index();
      if (action === "add-row") {
        const count = Math.max(1, $row.children("th, td").length);
        const cells = Array.from({ length: count }, () => "<td>داده جدید</td>").join("");
        $row.after(`<tr>${cells}</tr>`);
        selectTableCell($row.next().children().first().get(0));
      } else if (action === "remove-row") {
        if ($table.find("tr").length <= 1) { HST.toast("جدول باید حداقل یک سطر داشته باشد.", "warning"); return; }
        const nextCell = $row.next().children().eq(columnIndex).get(0) || $row.prev().children().eq(columnIndex).get(0);
        $row.remove();
        selectTableCell(nextCell);
      } else if (action === "add-column") {
        $table.find("tr").each(function () { $(this).children("th, td").eq(columnIndex).after("<td>داده جدید</td>"); });
        selectTableCell($row.children().eq(columnIndex + 1).get(0));
      } else if (action === "remove-column") {
        if ($row.children("th, td").length <= 1) { HST.toast("جدول باید حداقل یک ستون داشته باشد.", "warning"); return; }
        $table.find("tr").each(function () { $(this).children("th, td").eq(columnIndex).remove(); });
        selectTableCell($row.children().eq(Math.max(0, columnIndex - 1)).get(0));
      } else if (action === "delete") {
        $table.remove();
        selectEditorElement(null, null);
        $editor.trigger("focus");
      }
    });

    $tableTools.on("click", "[data-hst-element-action]", function () {
      if (!activeEditorElement) return;
      const action = String($(this).data("hst-element-action") || "");
      const $element = $(activeEditorElement);
      if (action === "delete") {
        $element.remove();
        selectEditorElement(null, null);
        $editor.trigger("focus");
        return;
      }
      if (action.indexOf("align-") === 0) {
        $element.attr("data-hst-align", action.replace("align-", ""));
        window.requestAnimationFrame(positionElementTools);
        return;
      }
      if (action === "move-up" || action === "move-down") {
        const currentOffset = Number($element.attr("data-hst-offset-y") || 0);
        const nextOffset = Math.max(-120, Math.min(240, currentOffset + (action === "move-up" ? -12 : 12)));
        $element.attr("data-hst-offset-y", String(nextOffset)).css("transform", `translateY(${nextOffset}px)`);
        window.requestAnimationFrame(positionElementTools);
      }
    });

    $form.on("click", "[data-hst-editor-media]", function () {
      if (!window.wp || !wp.media) {
        HST.toast("انتخابگر تصویر در دسترس نیست.", "error");
        return;
      }
      const frame = wp.media({ title: "انتخاب تصویر سؤال", button: { text: "درج تصویر" }, multiple: false, library: { type: "image" } });
      frame.on("select", function () {
        const image = frame.state().get("selection").first().toJSON();
        $editor.trigger("focus");
        document.execCommand("insertHTML", false, `<img src="${esc(image.url)}" alt="${esc(image.alt || image.title || "تصویر سؤال")}">`);
        selectEditorElement(ensureResizableImage($editor.find("img").last().get(0)), null);
      });
      frame.open();
    });

    $form.on("submit", function (event) {
      event.preventDefault();
      const questionHtml = String($editor.html() || "").trim();
      const plain = String($editor.text() || "").trim();
      if (!plain && !$editor.find("img, table").length) {
        HST.toast("متن یا صورت سؤال را وارد کنید.", "error");
        $editor.trigger("focus");
        return;
      }
      const type = String($type.val() || "");
      const answerData = collectAnswerData(type);
      const questionScope = editingQuestionScope && editingQuestionScope.chapter
        ? editingQuestionScope
        : primaryBlueprintScope();
      $form.find('[name="question_text"]').val(questionHtml);
      HST.request({
        action: "hst_exam_question_save",
        data: {
          id: $form.find('[name="id"]').val(),
          lesson_id: $lesson.val(),
          grade: $grade.val(),
          major: $major.val(),
          question_type: type,
          difficulty: $form.find('[name="difficulty"]').val(),
          score: $form.find('[name="score"]').val(),
          question_text: questionHtml,
          answer_data: JSON.stringify(answerData),
          curriculum_subject: activeBlueprint.subject || "",
          curriculum_chapter: questionScope.chapter || "",
          curriculum_topics: JSON.stringify(questionScope.topics || []),
        },
        successMessage: true,
        reload: true,
        trigger: $form.find("[data-hst-question-submit]").get(0),
      });
    });

    $editorModal.on("click", "[data-hst-question-close]", function () { closeModal($editorModal); });

    $transferButtons.on("click", function () {
      const ids = selectedIds();
      if (!ids.length) return;
      prepareTransferModal(ids, "direct");
    });

    $transferModal.on("click", "[data-hst-question-transfer-close]", function () { closeModal($transferModal); });
    $("#hst-question-transfer-form").on("submit", function (event) {
      event.preventDefault();
      const $formTransfer = $(this);
      const $selectedOption = $formTransfer.find('[name="exam_id"] option:selected');
      const exam = selectedExamFromOption($selectedOption);
      if (!exam) {
        HST.toast("آزمون مقصد را انتخاب کنید.", "warning");
        return;
      }
      if (transferPurpose === "final") {
        selectedExam = exam;
        resetExamPaperPreview();
        closeModal($transferModal);
        $stageFour.find("[data-hst-question-final-submit]").prop("disabled", false).text("ثبت نهایی آزمون");
        showQuestionStage(4);
        HST.toast("آزمون مقصد انتخاب شد. نمونه سوال و راهنمای تصحیح آماده بررسی است.", "success");
        window.requestAnimationFrame(function () { renderExamPaperPreview("questions"); });
        return;
      }
      const ids = selectedIds();
      HST.request({
        action: "hst_exam_questions_transfer",
        data: {
          exam_id: exam.id,
          question_ids: JSON.stringify(ids),
          question_scores: JSON.stringify(selectedQuestionScoresFor(ids)),
        },
        successMessage: true,
        reload: true,
        trigger: $formTransfer.find('[type="submit"]').get(0),
      });
    });

    $stageFour.on("click", "[data-hst-question-final-back]", function () {
      showQuestionStage(3);
    });

    $stageFour.on("click", "[data-hst-exam-paper-preview]", function () {
      renderExamPaperPreview(String($(this).attr("data-hst-exam-paper-preview") || "questions"));
    });

    $stageFour.on("click", "[data-hst-exam-paper-preview-pagination] .hst-page-prev", function (event) {
      event.preventDefault();
      event.stopPropagation();
      showExamPaperPreviewPage(paperPreviewPage - 1, true);
    });

    $stageFour.on("click", "[data-hst-exam-paper-preview-pagination] .hst-page-next", function (event) {
      event.preventDefault();
      event.stopPropagation();
      showExamPaperPreviewPage(paperPreviewPage + 1, true);
    });

    $stageFour.on("click", "[data-hst-exam-paper-preview-pagination] .hst-page-number", function (event) {
      event.preventDefault();
      event.stopPropagation();
      showExamPaperPreviewPage(parseInt($(this).attr("data-page"), 10) || 1, true);
    });

    $stageFour.on("click", "[data-hst-exam-paper-download]", async function () {
      await downloadExamPaper(String($(this).attr("data-hst-exam-paper-download") || "questions"));
    });

    $stageFour.on("click", "[data-hst-question-final-submit]", function () {
      if (!selectedExam) {
        HST.toast("آزمون مقصد انتخاب نشده است.", "warning");
        return;
      }
      const ids = persistSelectedQuestionOrder();
      if (!ids.length) {
        HST.toast("حداقل یک سؤال برای ثبت نهایی لازم است.", "warning");
        return;
      }
      persistSelectedQuestionScores();
      const button = this;
      HST.request({
        action: "hst_exam_questions_transfer",
        data: {
          exam_id: selectedExam.id,
          question_ids: JSON.stringify(ids),
          question_scores: JSON.stringify(selectedQuestionScoresFor(ids)),
        },
        successMessage: true,
        trigger: button,
        onSuccess(response) {
          const $finalSubmitButtons = $stageFour.find("[data-hst-question-final-submit]");
          $finalSubmitButtons.text("آزمون ثبت نهایی شد");
          window.setTimeout(function () { $finalSubmitButtons.prop("disabled", true); }, 120);
          $(document).trigger("hst:question-exam-finalized", [response && response.data ? response.data : {}]);
        },
      });
    });

    $(document).on("keydown.hstQuestionBank", function (event) {
      if (event.key !== "Escape") return;
      if ($editorModal.hasClass("is-active")) closeModal($editorModal);
      if ($transferModal.hasClass("is-active")) closeModal($transferModal);
    });

    renderQuestionBankAnswers();
    ensureQuestionRowText();
    restoreBlueprint();
    const hasSavedBlueprint = Boolean(
      activeBlueprint.grade
      && activeBlueprint.major
      && activeBlueprint.subject
      && blueprintUnits(activeBlueprint).length
      && Array.isArray(activeBlueprint.topics)
      && activeBlueprint.topics.length
    );
    if (hasSavedBlueprint) applyBlueprintScope();
    applyFilters();
    showQuestionStage(hasSavedBlueprint ? 2 : 1);
  }

  removeLegacyUpcomingExamsCard();
  initManagerHub();
  initExamGeneralSettings();
  initBuilderForm();
  initManagerExamList();
  initQuestionBank();
  initStudentExamRunner();
  initLegacyTeacherForm();
});
