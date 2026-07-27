/**
 * Schedule PDF — generates a real, device-independent PDF via the shared
 * HSTPrint component. Long exports use the shared operation-progress modal so
 * users always know the browser is still building the file.
 */
jQuery(function ($) {
  "use strict";

  $(document).on("click", "[data-hst-schedule-pdf]", async function () {
    const $btn = $(this);
    const type = $btn.data("type") || "class";

    let classId = $btn.data("class-id");
    let teacherId = $btn.data("teacher-id");
    let termId = $btn.data("term-id");

    if (type === "class" && !classId) {
      classId = $("#hst-schedule-class").val() || $("#hst-class-filter").val();
    }
    if (!termId) {
      termId = $("#hst-schedule-term").val();
    }

    if (type === "class" && !classId) {
      HST.toast("ابتدا یک کلاس را انتخاب کنید.", "error");
      return;
    }
    if (type === "teacher" && !teacherId) {
      HST.toast("معلم مشخص نیست.", "error");
      return;
    }

    const isBulk = type === "all_teachers" || type === "all_classes";
    const progress = HST.operationProgress
      ? HST.operationProgress.open({
          title: isBulk ? "در حال ساخت برنامه‌های هفتگی" : "در حال ساخت برنامه هفتگی",
          subtitle: "دریافت اطلاعات و ساخت PDF ممکن است کمی زمان ببرد.",
          text: "در حال دریافت اطلاعات برنامه...",
          percent: 2,
          lockMessage: "ساخت فایل برنامه هفتگی هنوز کامل نشده است؛ لطفاً صبر کنید.",
        })
      : null;

    if (!progress && HST.loader) HST.loader.show();
    let completed = false;

    try {
      const response = await HST.request({
        action: "hst_schedule_pdf",
        data: {
          schedule_type: type,
          class_id: classId || 0,
          teacher_id: teacherId || 0,
          term_id: termId || 0,
        },
        trigger: this,
        showLoader: false,
        dedupe: `hst_schedule_pdf_${type}_${classId || 0}_${teacherId || 0}_${termId || 0}`,
        async onSuccess(res) {
          const d = res && res.data ? res.data : {};
          if (progress) progress.update(10, "اطلاعات دریافت شد؛ در حال آماده‌سازی صفحات...", "آماده‌سازی PDF");

          if (d.blocks && d.blocks.length && window.HSTPrint && window.HSTPrint.isPdfAvailable()) {
            await window.HSTPrint.gridPdf({
              blocks: d.blocks,
              title: d.title || "",
              fallbackHtml: d.html || "",
              onProgress(percent, text) {
                if (!progress) return;
                const mapped = Math.min(99, 10 + Math.round((Math.max(0, Math.min(100, Number(percent) || 0)) / 100) * 89));
                progress.update(mapped, text || "در حال ساخت صفحات برنامه هفتگی...", "ساخت فایل PDF");
              },
            });
            completed = true;
            return;
          }

          if (d.html && window.HSTPrint) {
            window.HSTPrint.printHtml(d.html, { title: d.title || "" });
            completed = true;
            return;
          }

          throw new Error("schedule_pdf_content_missing");
        },
      });

      if (!response || !response.success || !completed) {
        throw new Error("schedule_pdf_failed");
      }

      if (progress) progress.complete("فایل برنامه هفتگی آماده شد و دانلود آغاز شد.");
      HST.toast("فایل برنامه هفتگی آماده شد.", "success");
    } catch (error) {
      console.error("Schedule PDF generation failed:", error);
      if (progress) progress.fail("ساخت فایل برنامه هفتگی انجام نشد.");
      if (String(error && error.message || "") === "schedule_pdf_content_missing") {
        HST.toast("محتوای برنامه دریافت نشد.", "error");
      }
    } finally {
      if (!progress && HST.loader) HST.loader.hide();
    }
  });
});
