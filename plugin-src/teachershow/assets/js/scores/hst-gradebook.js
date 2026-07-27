jQuery(function ($) {
  "use strict";

  const $page = $("[data-hst-gradebook]");
  if (!$page.length) return;

  const MAX = 8;
  const $class = $("#hst-gb-class");
  const $lesson = $("#hst-gb-lesson");
  const $month = $("#hst-gb-period");
  const $body = $("#hst-gb-body");
  const $list = $("#hst-gb-list");
  const $statbar = $("#hst-gb-statbar");
  const $empty = $("#hst-gb-empty");
  const $emptyText = $("#hst-gb-empty-text");
  const $save = $("#hst-gb-save");

  let students = [];
  let entries = {};
  let canEdit = true;

  function opt(v, t) { return `<option value="${HST.escapeHtml(v)}">${HST.escapeHtml(t)}</option>`; }
  function faNum(n) { return HST.escapeHtml(String(n)).replace(/\d/g, (d) => "۰۱۲۳۴۵۶۷۸۹"[d]); }

  function band(score) {
    const n = parseFloat(score);
    if (isNaN(n)) return "";
    if (n < 10) return "low";
    if (n < 15) return "mid";
    return "high";
  }

  function avg(list) {
    const nums = (list || []).map((e) => parseFloat(e.score)).filter((n) => !isNaN(n));
    if (!nums.length) return null;
    return Math.round((nums.reduce((a, b) => a + b, 0) / nums.length) * 100) / 100;
  }

  function famName(s) {
    return String(s.last_name || "").trim() || String(s.display_name || "").trim();
  }

  function loadContext(extra) {
    return HST.ajax(Object.assign({ action: "hst_get_teacher_score_context" }, extra || {}));
  }

  // ---- live class-wide stats --------------------------------------------
  function collectAll() {
    const all = [];
    $list.find(".hst-gb-student").each(function () {
      const list = [];
      $(this).find(".hst-gb-score-row").each(function () {
        const score = $.trim($(this).find(".hst-gb-score").val() || "");
        if (score !== "") list.push({ score });
      });
      all.push(list);
    });
    return all;
  }

  function updateStats() {
    const all = collectAll();
    const studentCount = students.length;
    let entryCount = 0;
    const avgs = [];
    all.forEach((list) => {
      entryCount += list.length;
      const a = avg(list);
      if (a !== null) avgs.push(a);
    });
    const classAvg = avgs.length ? Math.round((avgs.reduce((x, y) => x + y, 0) / avgs.length) * 100) / 100 : null;
    $statbar.find('[data-stat="students"]').text(faNum(studentCount));
    $statbar.find('[data-stat="entries"]').text(faNum(entryCount));
    $statbar.find('[data-stat="avg"]').text(classAvg === null ? "—" : faNum(classAvg));
    $statbar.find(".hst-scores-stat--success").attr("data-band", classAvg === null ? "" : band(classAvg));
    $statbar.prop("hidden", studentCount === 0);
  }

  function rowHtml(entry) {
    const b = band(entry.score);
    return `
      <div class="hst-gb-score-row" data-band="${b}">
        <input type="text" class="hst-gb-title" placeholder="عنوان (مثلاً کوییز ۱)" value="${HST.escapeHtml(entry.title || "")}">
        <div class="hst-gb-score-wrap">
          <input type="number" class="hst-gb-score" inputmode="decimal" min="0" max="20" step="0.25" placeholder="نمره" value="${HST.escapeHtml(HST.formatScore(entry.score))}">
        </div>
        <button type="button" class="hst-icon-btn hst-gb-del" aria-label="حذف نمره" title="حذف">&times;</button>
      </div>`;
  }

  function studentCard(student) {
    const sid = String(student.ID);
    const list = entries[sid] || entries[student.ID] || [];
    const a = avg(list);
    const rows = (list.length ? list : [{ title: "", score: "" }]).map(rowHtml).join("");
    const initial = HST.initials(student.display_name || "", student.first_name || "", student.last_name || "");
    const avatar = student.avatar_url
      ? `<img class="hst-score-avatar" src="${HST.escapeHtml(student.avatar_url)}" alt="" loading="lazy">`
      : `<span class="hst-score-avatar hst-score-avatar--placeholder">${HST.escapeHtml(initial)}</span>`;
    return `
      <div class="hst-card hst-gb-student" data-student-id="${HST.escapeHtml(sid)}">
        <div class="hst-gb-student__head">
          <span class="hst-gb-student__name">${avatar}<b>${HST.escapeHtml(student.display_name)}</b></span>
          <span class="hst-gb-avg" data-band="${a === null ? "" : band(a)}">
            <span class="hst-gb-avg__label">میانگین</span>
            <span class="hst-gb-avg__val">${a === null ? "—" : faNum(a)}</span>
          </span>
        </div>
        <div class="hst-gb-rows">${rows}</div>
        <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-gb-add">+ افزودن نمره</button>
      </div>`;
  }

  function renderList() {
    if (!students.length) {
      $body.prop("hidden", true);
      $statbar.prop("hidden", true);
      $empty.prop("hidden", false);
      $emptyText.text("دانش‌آموزی برای این کلاس و درس پیدا نشد.");
      return;
    }
    $empty.prop("hidden", true);
    const sorted = [...students].sort((a, b) => famName(a).localeCompare(famName(b), "fa"));
    $list.html(sorted.map(studentCard).join(""));
    $body.prop("hidden", false).toggleClass("is-readonly", !canEdit);
    $save.toggle(canEdit);
    if (!canEdit) $list.find("input, .hst-gb-add, .hst-gb-del").prop("disabled", true);
    $("#hst-gb-periodstate")
      .text(canEdit ? "دسترسی ثبت نمره فعال — قابل ویرایش" : "دسترسی ثبت نمره غیرفعال — فقط مشاهده")
      .attr("data-active", canEdit ? "1" : "0");
    updateStats();
  }

  function recalc($card) {
    const list = [];
    $card.find(".hst-gb-score-row").each(function () {
      list.push({ score: $(this).find(".hst-gb-score").val() });
    });
    const a = avg(list);
    const $avg = $card.find(".hst-gb-avg");
    $avg.find(".hst-gb-avg__val").text(a === null ? "—" : faNum(a));
    $avg.attr("data-band", a === null ? "" : band(a));
    updateStats();
  }

  function reset() {
    $body.prop("hidden", true);
    $statbar.prop("hidden", true);
    $empty.prop("hidden", false).removeAttr("aria-busy");
    $emptyText.text("برای نمایش فهرست، کلاس و درس را انتخاب کنید.");
  }

  function showLoadingState() {
    $body.prop("hidden", true);
    $statbar.prop("hidden", true);
    $empty.prop("hidden", false).attr("aria-busy", "true");
    $emptyText.html(HST.loadingMarkup());
  }

  function showLoadError() {
    $empty.prop("hidden", false).removeAttr("aria-busy");
    $emptyText.text("دریافت اطلاعات انجام نشد.");
  }

  async function maybeAutoLoad() {
    const classId = $class.val(), lessonId = $lesson.val(), periodKey = $month.val();
    if (!classId || !lessonId || !periodKey) { reset(); return; }
    const isActive = $month.find("option:selected").data("active") == 1;
    showLoadingState();
    try {
      const res = await HST.ajax({ action: "hst_get_gradebook", class_id: classId, lesson_id: lessonId, period_key: periodKey });
      const d = (res && res.data) || {};
      students = d.students || [];
      entries = d.entries || {};
      canEdit = !!(d.access_enabled ?? d.period_is_active ?? d.month_is_active);
      $empty.removeAttr("aria-busy");
      renderList();
    } catch (e) {
      showLoadError();
      HST.toast((e && e.message) || "خطا در بارگذاری", "error");
    }
  }

  $class.on("change", async function () {
    const classId = $(this).val();
    reset();
    $lesson.prop("disabled", true).html(opt("", classId ? "در حال بارگذاری..." : "ابتدا کلاس را انتخاب کنید"));
    if (!classId) return;
    const res = await loadContext({ class_id: classId });
    const d = (res && res.data) || {};
    const lessons = d.lessons || [];
    if (!lessons.length) { $lesson.prop("disabled", true).html(opt("", "درسی یافت نشد")); return; }
    $lesson.prop("disabled", false).html(opt("", "انتخاب درس") + lessons.map((l) => opt(l.id, l.lesson_name)).join(""));
  });

  function selectActiveMonth() {
    const $a = $month.find('option[data-active="1"]').first();
    if ($a.length) $month.val($a.val());
  }
  selectActiveMonth();

  $lesson.on("change", function () { if ($(this).val()) { selectActiveMonth(); maybeAutoLoad(); } else reset(); });
  $month.on("change", maybeAutoLoad);

  $list.on("click", ".hst-gb-add", function () {
    const $card = $(this).closest(".hst-gb-student");
    if ($card.find(".hst-gb-score-row").length >= MAX) {
      HST.toast("حداکثر " + MAX + " نمره برای هر دانش‌آموز.", "error");
      return;
    }
    $(this).before(rowHtml({ title: "", score: "" }));
  });

  $list.on("click", ".hst-gb-del", function () {
    const $card = $(this).closest(".hst-gb-student");
    $(this).closest(".hst-gb-score-row").remove();
    recalc($card);
  });

  $list.on("input", ".hst-gb-score", function () {
    $(this).closest(".hst-gb-score-row").attr("data-band", band($(this).val()));
    recalc($(this).closest(".hst-gb-student"));
  });

  $save.on("click", function () {
    const payload = {};
    $list.find(".hst-gb-student").each(function () {
      const sid = $(this).data("student-id");
      const list = [];
      $(this).find(".hst-gb-score-row").each(function () {
        const score = $.trim($(this).find(".hst-gb-score").val() || "");
        const title = $.trim($(this).find(".hst-gb-title").val() || "");
        if (score !== "") list.push({ title, score });
      });
      payload[sid] = list;
    });
    HST.request({
      action: "hst_save_gradebook",
      data: { class_id: $class.val(), lesson_id: $lesson.val(), period_key: $month.val(), entries: payload },
      trigger: this,
      successMessage: true,
    });
  });

  // boot
  loadContext().then((res) => {
    const d = (res && res.data) || {};
    const classes = HST.sortClassItems(d.classes || [], "class_name");
    $class.html(opt("", "انتخاب کلاس") + classes.map((c) => opt(c.id, c.class_name)).join(""));
  });
});
