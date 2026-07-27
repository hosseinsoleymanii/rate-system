jQuery(function ($) {
  "use strict";

  const $page = $("[data-hst-term-transfer]");
  if (!$page.length) return;

  const $source = $("#hst-tt-source");
  const $target = $("#hst-tt-target");
  const $mappingCard = $("#hst-tt-mapping-card");
  const $mapping = $("#hst-tt-mapping");
  const $resultCard = $("#hst-tt-result-card");

  let allClasses = [];
  let transferClassStudents = {};
  let excludedStudentsByClass = {};
  let activeStudentClassId = 0;

  function faNum(n) {
    return HST.escapeHtml(String(n)).replace(/\d/g, (d) => "۰۱۲۳۴۵۶۷۸۹"[d]);
  }

  const GRADE_ORDER = ["هفتم", "هشتم", "نهم", "دهم", "یازدهم", "دوازدهم"];
  const GRADE_DETECT_ORDER = ["دوازدهم", "یازدهم", "دهم", "نهم", "هشتم", "هفتم"];

  function normalizeClassName(value) {
    return String(value || "")
      .replace(/ي/g, "ی")
      .replace(/ك/g, "ک")
      .replace(/[‌ـ]/g, " ")
      .replace(/\s+/g, " ")
      .trim();
  }

  function compactClassKey(value) {
    return normalizeClassName(value).replace(/\s+/g, "");
  }

  function removeGrade(value) {
    let normalized = normalizeClassName(value);
    GRADE_DETECT_ORDER.forEach((grade) => {
      normalized = normalized.replace(new RegExp(grade, "g"), "");
    });
    return compactClassKey(normalized);
  }

  function gradeOf(value) {
    const normalized = normalizeClassName(value);
    return GRADE_DETECT_ORDER.find((grade) => normalized.indexOf(grade) !== -1) || "";
  }

  function classNameOf(item) {
    return normalizeClassName(item && (item.name || item.class_name || item.title || ""));
  }

  function classIdOf(item) {
    return item && (item.id || item.class_id || item.value || "");
  }

  function majorOf(value) {
    const normalized = normalizeClassName(value);
    const majors = [
      "ریاضی فیزیک",
      "علوم تجربی",
      "تجربی",
      "علوم انسانی",
      "انسانی",
      "معارف",
      "فنی",
      "کارودانش",
      "هنرستان",
    ];

    const found = majors.find((major) => normalized.indexOf(major) !== -1) || "";
    if (found === "علوم انسانی") return "انسانی";
    if (found === "علوم تجربی") return "تجربی";
    return found;
  }

  function normalizedTargetClasses() {
    return allClasses.map((c) => ({
      raw: c,
      id: classIdOf(c),
      name: classNameOf(c),
      grade: gradeOf(classNameOf(c)),
      base: removeGrade(classNameOf(c)),
      major: majorOf(classNameOf(c)),
      key: compactClassKey(classNameOf(c)),
    })).filter((c) => c.id && c.name);
  }

  function isTwelfth(value) {
    return normalizeClassName(value).indexOf("دوازدهم") !== -1;
  }

  function nextGrade(sourceName) {
    const grade = gradeOf(sourceName);
    const index = GRADE_ORDER.indexOf(grade);
    if (index === -1 || index >= GRADE_ORDER.length - 1) {
      return "";
    }

    return GRADE_ORDER[index + 1];
  }

  function guessDestination(sourceName) {
    const next = nextGrade(sourceName);
    if (!next) return "";

    const sourceNormalized = normalizeClassName(sourceName);
    const currentGrade = gradeOf(sourceName);
    const directPromoted = currentGrade ? sourceNormalized.replace(currentGrade, next) : "";
    const directKey = compactClassKey(directPromoted);
    const sourceBase = removeGrade(sourceName);
    const sourceMajor = majorOf(sourceName);
    const targets = normalizedTargetClasses();

    // Explicit high-priority rule: یازدهم هر رشته باید به دوازدهم همان رشته برود.
    // This is intentionally before exact/base matching to avoid grade-word
    // overlaps or minor naming differences from blocking the automatic match.
    if (currentGrade === "یازدهم" && next === "دوازدهم" && sourceMajor) {
      const directMajorMatch = targets.find((c) => c.grade === "دوازدهم" && c.major === sourceMajor);
      if (directMajorMatch) return String(directMajorMatch.id);
    }

    let match = targets.find((c) => c.key === directKey);
    if (match) return String(match.id);

    match = targets.find((c) => c.grade === next && c.base === sourceBase);
    if (match) return String(match.id);

    // For یازدهم → دوازدهم, the class name usually keeps the same major
    // even if the exact base text is not equal.
    match = targets.find((c) => c.grade === next && sourceMajor && c.major === sourceMajor);
    if (match) return String(match.id);

    match = targets.find((c) => {
      return c.grade === next && sourceBase && c.base && (c.base.indexOf(sourceBase) !== -1 || sourceBase.indexOf(c.base) !== -1);
    });

    if (match) return String(match.id);

    // Last textual fallback for cases like "یازدهم انسانی" -> "دوازدهم انسانی"
    // even when the major detector fails due to unexpected wording.
    if (currentGrade === "یازدهم" && next === "دوازدهم") {
      const sourceTextWithoutGrade = sourceBase;
      const textMatch = targets.find((c) => c.grade === "دوازدهم" && sourceTextWithoutGrade && c.base.indexOf(sourceTextWithoutGrade) !== -1);
      if (textMatch) return String(textMatch.id);
    }

    const nextClasses = targets.filter((c) => c.grade === next);
    return nextClasses.length === 1 ? String(nextClasses[0].id) : "";
  }

  function destOptions(selected) {
    let html = '<option value="">— انتخاب مقصد —</option>';
    normalizedTargetClasses().forEach((c) => {
      html += '<option value="' + c.id + '"' + (String(selected) === String(c.id) ? " selected" : "") + ">" + HST.escapeHtml(c.name) + "</option>";
    });
    return html;
  }

  function rowHtml(cls) {
    const guess = guessDestination(cls.class_name);
    return (
      '<div class="hst-tt-row" data-source-class="' + cls.class_id + '">' +
        '<div class="hst-tt-row__source">' +
          '<span class="hst-tt-row__name">' + HST.escapeHtml(cls.class_name) + "</span>" +
          '<span class="hst-tt-row__count" data-tt-row-count="' + cls.class_id + '">' + faNum(cls.student_count) + " دانش‌آموز انتخاب شده</span>" +
        "</div>" +
        '<div class="hst-tt-row__students">' +
          '<button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-tt-students-btn" data-class-id="' + cls.class_id + '" data-class-name="' + HST.escapeHtml(cls.class_name) + '">انتخاب دانش‌آموزان</button>' +
        "</div>" +
        '<span class="hst-tt-row__arrow">←</span>' +
        '<div class="hst-tt-row__dest">' +
          '<select class="hst-select hst-tt-dest">' + destOptions(guess) + "</select>" +
        "</div>" +
      "</div>"
    );
  }

  let lastLoadedPair = "";

  function resetMapping() {
    $mapping.empty();
    $mappingCard.prop("hidden", true);
    $resultCard.prop("hidden", true);
    allClasses = [];
    transferClassStudents = {};
    excludedStudentsByClass = {};
    activeStudentClassId = 0;
    lastLoadedPair = "";
  }

  function loadSourceClasses(trigger) {
    const sourceYear = $source.val();
    const targetYear = $target.val();

    if (!sourceYear || !targetYear) {
      resetMapping();
      return;
    }

    if (targetYear === sourceYear) {
      resetMapping();
      HST.toast("سال تحصیلی مقصد باید با سال تحصیلی مبدأ متفاوت باشد.", "error");
      return;
    }

    const pairKey = sourceYear + ":" + targetYear;
    if (pairKey === lastLoadedPair) {
      return;
    }

    lastLoadedPair = pairKey;

    HST.request({
      action: "hst_transfer_source_classes",
      data: { term_id: sourceYear },
      trigger: trigger || null,
      onSuccess(res) {
        const d = (res && res.data) || {};
        allClasses = d.all_classes || [];
        transferClassStudents = {};
        excludedStudentsByClass = {};
        const sourceClasses = (d.source_classes || []).filter((cls) => !isTwelfth(cls.class_name));

        $resultCard.prop("hidden", true);

        if (!sourceClasses.length) {
          $mapping.html('<p class="hst-notice">در سال تحصیلی مبدأ، کلاس قابل انتقالی با دانش‌آموز ثبت‌شده پیدا نشد.</p>');
          $mappingCard.prop("hidden", false);
          return;
        }

        $mapping.html(sourceClasses.map(rowHtml).join(""));
        $mappingCard.prop("hidden", false);
      },
      onError() {
        lastLoadedPair = "";
      },
    });
  }

  $source.add($target).on("change", function () {
    loadSourceClasses(this);
  });


  function selectedIdsForClass(classId) {
    const students = transferClassStudents[classId] || [];
    const excluded = excludedStudentsByClass[classId] || [];
    const excludedMap = {};
    excluded.forEach((id) => { excludedMap[String(id)] = true; });

    return students
      .map((s) => String(s.id))
      .filter((id) => !excludedMap[id]);
  }

  function updateRowCount(classId) {
    const students = transferClassStudents[classId] || [];
    const selectedCount = students.length ? selectedIdsForClass(classId).length : null;
    const $count = $('[data-tt-row-count="' + classId + '"]');

    if (!$count.length || selectedCount === null) return;

    $count.text(faNum(selectedCount) + " از " + faNum(students.length) + " دانش‌آموز انتخاب شده");
  }

  function closeStudentsModal() {
    const $modal = $("#hst-tt-students-modal");
    HST.modalLoading.hide($modal.find(".hst-modal__body"));
    $modal.removeClass("is-active").attr("aria-hidden", "true");
    activeStudentClassId = 0;
  }

  function openStudentsModal(classId, className) {
    activeStudentClassId = parseInt(classId, 10) || 0;
    $("#hst-tt-students-modal-title").text("انتخاب دانش‌آموزان " + className);
    $("#hst-tt-students-modal-subtitle").text("دانش‌آموزانی که انتخاب باشند به سال تحصیلی مقصد منتقل می‌شوند.");
    $("#hst-tt-students-modal").addClass("is-active").attr("aria-hidden", "false");
  }

  function renderStudentsModal(classId) {
    const students = transferClassStudents[classId] || [];
    const excluded = excludedStudentsByClass[classId] || [];
    const excludedMap = {};
    excluded.forEach((id) => { excludedMap[String(id)] = true; });

    if (!students.length) {
      $("#hst-tt-students-list").html('<p class="hst-muted">دانش‌آموزی برای این کلاس پیدا نشد.</p>');
      $("#hst-tt-students-selected-count").text("۰ انتخاب");
      $("#hst-tt-students-select-all").prop("checked", false);
      return;
    }

    const html = students.map((student) => {
      const checked = !excludedMap[String(student.id)];
      const code = student.national_code ? '<small>' + HST.escapeHtml(student.national_code) + '</small>' : "";
      const name = HST.escapeHtml(student.name || "دانش‌آموز بدون نام");
      const initials = HST.escapeHtml(
        student.initials || HST.initials(student.name || "", student.first_name || "", student.last_name || "")
      );
      const avatar = student.avatar_url
        ? '<span class="hst-user-avatar" style="--hst-avatar-size:36px"><img src="' + HST.escapeHtml(student.avatar_url) + '" alt="' + name + '" loading="lazy"></span>'
        : '<span class="hst-user-avatar hst-user-avatar--placeholder" style="--hst-avatar-size:36px" aria-label="بدون تصویر پروفایل؛ حروف اول نام ' + name + '"><span class="hst-user-avatar__placeholder">' + initials + '</span></span>';
      return (
        '<label class="hst-tt-student-item">' +
          '<input type="checkbox" class="hst-tt-student-check" value="' + student.id + '"' + (checked ? " checked" : "") + '>' +
          '<span class="hst-user-id">' + avatar + '<span class="hst-user-id__meta"><strong>' + name + '</strong>' + code + '</span></span>' +
        '</label>'
      );
    }).join("");

    $("#hst-tt-students-list").html(html);
    syncStudentsModalCount();
  }

  function syncStudentsModalCount() {
    const $checks = $("#hst-tt-students-list .hst-tt-student-check");
    const total = $checks.length;
    const selected = $checks.filter(":checked").length;

    $("#hst-tt-students-selected-count").text(faNum(selected) + " از " + faNum(total) + " انتخاب");
    $("#hst-tt-students-select-all").prop("checked", total > 0 && selected === total);
  }

  async function loadClassStudents(classId, className, trigger) {
    openStudentsModal(classId, className);

    if (transferClassStudents[classId]) {
      renderStudentsModal(classId);
      return;
    }

    const $modalBody = $("#hst-tt-students-modal .hst-modal__body");
    HST.modalLoading.show($modalBody);

    const res = await HST.request({
      action: "hst_transfer_class_students",
      data: { term_id: $source.val(), class_id: classId },
      trigger: trigger,
      showLoader: false,
    });

    HST.modalLoading.hide($modalBody);

    if (!res || !res.success) {
      $("#hst-tt-students-list").html('<p class="hst-alert hst-alert--error">دریافت فهرست دانش‌آموزان انجام نشد.</p>');
      return;
    }

    const d = res.data || {};
    transferClassStudents[classId] = d.students || [];
    excludedStudentsByClass[classId] = excludedStudentsByClass[classId] || [];
    updateRowCount(classId);
    renderStudentsModal(classId);
  }

  $(document).on("click", ".hst-tt-students-btn", function () {
    const classId = $(this).data("class-id");
    const className = $(this).data("class-name") || "کلاس";
    loadClassStudents(classId, className, this);
  });

  $(document).on("change", ".hst-tt-student-check", syncStudentsModalCount);

  $("#hst-tt-students-select-all").on("change", function () {
    $("#hst-tt-students-list .hst-tt-student-check").prop("checked", $(this).is(":checked"));
    syncStudentsModalCount();
  });

  $("#hst-tt-students-apply").on("click", function () {
    const classId = activeStudentClassId;
    if (!classId) {
      closeStudentsModal();
      return;
    }

    const selected = {};
    $("#hst-tt-students-list .hst-tt-student-check:checked").each(function () {
      selected[String($(this).val())] = true;
    });

    excludedStudentsByClass[classId] = (transferClassStudents[classId] || [])
      .map((s) => String(s.id))
      .filter((id) => !selected[id]);

    updateRowCount(classId);
    closeStudentsModal();
  });

  $(document).on("click", "[data-tt-students-close]", closeStudentsModal);

  $(document).on("keydown", function (event) {
    if (event.key === "Escape" && $("#hst-tt-students-modal").hasClass("is-active")) {
      closeStudentsModal();
    }
  });

  $("#hst-tt-execute").on("click", function () {
    const sourceYear = $source.val();
    const targetYear = $target.val();
    const map = {};
    let chosen = 0;

    $mapping.find(".hst-tt-row").each(function () {
      const sc = $(this).data("source-class");
      const dest = $(this).find(".hst-tt-dest").val();
      if (!dest) return;
      map[sc] = dest;
      chosen++;
    });

    if (!sourceYear) {
      HST.toast("سال تحصیلی مبدأ را انتخاب کنید.", "error");
      return;
    }

    if (!targetYear) {
      HST.toast("سال تحصیلی مقصد را انتخاب کنید.", "error");
      return;
    }

    if (targetYear === sourceYear) {
      HST.toast("سال تحصیلی مقصد باید با سال تحصیلی مبدأ متفاوت باشد.", "error");
      return;
    }

    if (!chosen) {
      HST.toast("برای حداقل یک کلاس، مقصد را تعیین کنید.", "error");
      return;
    }

    HST.request({
      action: "hst_transfer_execute",
      data: { source_term_id: sourceYear, target_term_id: targetYear, map: map, excluded_students: excludedStudentsByClass },
      trigger: this,
      confirm: {
        title: "تأیید انتقال",
        text: "آیا از انجام انتقال همگانی مطمئن هستید؟ تخصیص جدید برای دانش‌آموزان ایجاد می‌شود.",
      },
      onSuccess(res) {
        const d = (res && res.data) || {};
        $resultCard.find('[data-tt="transferred"]').text(faNum(d.transferred || 0));
        $resultCard.find('[data-tt="skipped"]').text(faNum(d.skipped || 0));

        const details = (d.details || []).map((x) => "<p>" + HST.escapeHtml(x) + "</p>").join("");
        $("#hst-tt-result-details").html(details);
        $resultCard.prop("hidden", false);

        HST.toast(d.message || "انتقال انجام شد.", "success");
      },
    });
  });
});
