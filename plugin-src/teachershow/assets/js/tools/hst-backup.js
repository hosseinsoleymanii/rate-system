jQuery(function ($) {
  "use strict";

  const $page = $("[data-hst-backup]");
  if (!$page.length) return;

  const $list = $("#hst-backup-list");
  const $week = $("#hst-backup-week-filter");
  const $day = $("#hst-backup-day-filter");
  const $file = $("#hst-backup-restore-file");
  const $restore = $("#hst-backup-restore");
  const $drop = $("[data-hst-backup-drop]");
  const $fileName = $("[data-hst-backup-file-name]");
  let backupItems = [];
  let restoreProgressValue = 0;

  function esc(value) {
    return HST.escapeHtml(String(value == null ? "" : value));
  }

  function faNum(value) {
    const fa = "۰۱۲۳۴۵۶۷۸۹";
    return String(value == null ? "" : value).replace(/[0-9]/g, function (digit) {
      return fa[Number(digit)] || digit;
    });
  }

  function typeLabel(item) {
    return Number(item.is_auto) === 1 ? "خودکار" : "دستی";
  }

  function weekLabel(value) {
    const labels = {
      1: "هفته اول",
      2: "هفته دوم",
      3: "هفته سوم",
      4: "هفته چهارم",
      5: "هفته پنجم",
    };
    return labels[Number(value)] || "—";
  }

  function tableStateRow(message, isLoading) {
    const content = isLoading ? HST.loadingMarkup() : esc(message);
    return '<tr class="hst-table-empty-row" data-hst-no-pagination><td colspan="8" class="hst-table-empty">' + content + '</td></tr>';
  }

  function renderLoading() {
    $list.html(tableStateRow("", true));
  }

  function renderDayOptions(days) {
    const max = Math.max(29, Math.min(31, parseInt(days, 10) || 31));
    const current = $day.val() || "";
    let html = '<option value="">همه روزها</option>';

    for (let day = 1; day <= max; day++) {
      html += '<option value="' + day + '">روز ' + faNum(day) + '</option>';
    }

    $day.html(html);
    if (current && Number(current) <= max) {
      $day.val(current);
    }
  }

  function actionIcon() {
    return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3v12"/><path d="M8 11l4 4 4-4"/><path d="M4 19h16"/></svg>';
  }

  function filteredItems() {
    const week = Number($week.val()) || 0;
    const day = Number($day.val()) || 0;
    return backupItems.filter(function (item) {
      if (week && Number(item.week) !== week) return false;
      if (day && Number(item.day) !== day) return false;
      return true;
    });
  }

  function renderList() {
    const items = filteredItems();

    if (!items.length) {
      $list.html(tableStateRow("موردی با این فیلتر پیدا نشد."));
      return;
    }

    const html = items.map(function (item, index) {
      const downloadUrl = String(item.download_url || "").trim();
      const downloadAction = downloadUrl
        ? '<a class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon" href="' + esc(downloadUrl) + '" title="دانلود فایل" aria-label="دانلود فایل">' + actionIcon() + '</a>'
        : '<button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon" disabled title="فایل پشتیبان برای دانلود در دسترس نیست." aria-label="فایل پشتیبان برای دانلود در دسترس نیست.">' + actionIcon() + '</button>';
      return (
        '<tr data-name="' + esc(item.name) + '">' +
          '<td>' + faNum(index + 1) + '</td>' +
          '<td>' + esc(item.created) + '</td>' +
          '<td>' + esc(weekLabel(item.week)) + '</td>' +
          '<td>روز ' + faNum(item.day || "—") + '</td>' +
          '<td><span class="hst-status ' + (Number(item.is_auto) === 1 ? 'hst-status--success' : 'hst-status--muted') + '">' + esc(typeLabel(item)) + '</span></td>' +
          '<td>' + esc(item.size) + '</td>' +
          '<td class="hst-col-fill"><small>' + esc(item.name) + '</small></td>' +
          '<td class="hst-actions"><div class="hst-btn-group">' +
            downloadAction +
          '</div></td>' +
        '</tr>'
      );
    }).join("");

    $list.html(html);
  }

  function loadList() {
    renderLoading();

    HST.ajax({ action: "hst_backup_list" }).then(function (res) {
      const data = (res && res.data) || {};
      renderDayOptions(data.current_month_days || 31);
      backupItems = data.items || [];
      renderList();
    }).catch(function () {
      $list.html(tableStateRow("خطا در بارگذاری فهرست پشتیبان‌ها."));
    });
  }

  function ensureProgressModal() {
    let $modal = $("#hst-backup-restore-progress-modal");
    if ($modal.length) return $modal;
    $modal = $(`
      <div class="hst-modal" data-hst-progress-modal data-hst-modal-size="md" id="hst-backup-restore-progress-modal" role="dialog" aria-modal="true" aria-labelledby="hst-backup-restore-progress-title" aria-hidden="true">
        <div class="hst-modal__backdrop"></div>
        <div class="hst-modal__panel">
          <div class="hst-modal__header">
            <div>
              <h3 id="hst-backup-restore-progress-title">در حال اعمال پشتیبان</h3>
              <p>تا پایان عملیات، صفحه را نبندید.</p>
            </div>
            <button type="button" class="hst-modal__close" data-hst-backup-progress-close data-hst-progress-close aria-label="بستن">×</button>
          </div>
          <div class="hst-modal__body">
            <div class="hst-operation-progress hst-schedule-global-progress" aria-live="polite">
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
            <button type="button" class="hst-btn hst-btn--soft" data-hst-backup-progress-close data-hst-progress-close>بستن</button>
          </div>
        </div>
      </div>
    `);

    $("body").append($modal);
    $modal.on("click", "[data-hst-backup-progress-close]", function () {
      if (HST.isProgressModalLocked($modal)) {
        HST.notifyProgressModalLocked($modal);
        return;
      }
      $modal.removeClass("is-open").attr("aria-hidden", "true");
      $("body").removeClass("hst-modal-open");
    });
    return $modal;
  }

  function updateProgress(percent, text) {
    const safe = Math.max(0, Math.min(100, parseInt(percent, 10) || 0));
    restoreProgressValue = safe;
    const $modal = ensureProgressModal();
    $modal.find(".hst-operation-progress__bar").css("width", safe + "%");
    $modal.find(".hst-operation-progress__percent").text(faNum(safe + "%"));
    $modal.find(".hst-operation-progress__track").attr("aria-valuenow", String(safe));
    if (text) {
      $modal.find(".hst-operation-progress__hint").text(text);
    }
  }

  function delay(ms) {
    return new Promise(function (resolve) {
      window.setTimeout(resolve, ms);
    });
  }

  function openProgress(text) {
    const $modal = ensureProgressModal();
    updateProgress(1, text || "در حال آماده‌سازی فایل...");
    HST.setProgressModalLocked(
      $modal,
      true,
      "تا پایان اعمال پشتیبان، صفحه را نبندید یا ترک نکنید."
    );
    $modal.addClass("is-open").attr("aria-hidden", "false");
    $("body").addClass("hst-modal-open");
  }

  function completeProgress(text, delayMs) {
    updateProgress(100, text || "عملیات کامل شد");
    const $modal = $("#hst-backup-restore-progress-modal");
    HST.setProgressModalLocked($modal, false);
    window.setTimeout(function () {
      $modal.removeClass("is-open").attr("aria-hidden", "true");
      $("body").removeClass("hst-modal-open");
    }, delayMs || 900);
  }

  function failProgress(text) {
    const $modal = ensureProgressModal();
    updateProgress(restoreProgressValue, text || "عملیات با خطا متوقف شد.");
    HST.setProgressModalLocked($modal, false);
  }

  async function runRestoreJob(jobId) {
    let failures = 0;

    while (true) {
      try {
        const res = await $.ajax({
          url: HST.ajaxUrl,
          method: "POST",
          dataType: "json",
          data: {
            action: "hst_backup_restore_step",
            nonce: HST.nonce,
            job_id: jobId,
          },
        });

        if (!res || !res.success) {
          const error = new Error(HST.getMessage(res, "ادامه عملیات بازیابی ناموفق بود."));
          error.responseJSON = res;
          throw error;
        }

        const data = res.data || {};
        updateProgress(data.progress || restoreProgressValue, data.message || "در حال بازیابی اطلاعات...");
        failures = 0;

        if (data.done) {
          return data;
        }

        await delay(70);
      } catch (error) {
        failures += 1;
        if (failures >= 3) {
          throw error;
        }
        updateProgress(
          restoreProgressValue,
          "ارتباط موقت با سرور قطع شد؛ تلاش مجدد " + faNum(failures) + " از ۲..."
        );
        await delay(900 * failures);
      }
    }
  }

  function selectedFile() {
    return $file.get(0) && $file.get(0).files && $file.get(0).files[0] ? $file.get(0).files[0] : null;
  }

  function setSelectedFile(file) {
    if (!file) {
      $drop.removeClass("is-filled");
      $fileName.text("فقط فایل JSON پشتیبان TeacherShow پذیرفته می‌شود.");
      $restore.prop("disabled", true);
      return;
    }

    $drop.addClass("is-filled");
    $fileName.text(file.name);
    $restore.prop("disabled", false);
  }

  $("#hst-backup-create").on("click", async function () {
    const progress = HST.operationProgress
      ? HST.operationProgress.open({
          title: "در حال ساخت فایل پشتیبان",
          subtitle: "جمع‌آوری و فشرده‌سازی اطلاعات ممکن است کمی زمان ببرد.",
          percent: 3,
          text: "در حال جمع‌آوری اطلاعات سامانه...",
          lockMessage: "ساخت فایل پشتیبان هنوز کامل نشده است؛ لطفاً صبر کنید.",
        })
      : null;
    if (progress) progress.startAuto({ ceiling: 78, interval: 750, text: "در حال ساخت و فشرده‌سازی فایل پشتیبان..." });
    else HST.loader.show();
    let completed = false;

    const response = await HST.request({
      action: "hst_backup_create",
      trigger: this,
      showLoader: false,
      onSuccess: function (res) {
        const data = (res && res.data) || {};
        if (progress) {
          progress.stopAuto();
          progress.update(92, "فایل ساخته شد؛ در حال شروع دانلود...", "آماده‌سازی دانلود");
        }
        if (data.download_url) {
          const a = document.createElement("a");
          a.href = data.download_url;
          document.body.appendChild(a);
          a.click();
          a.remove();
        }
        completed = true;
        loadList();
      },
    });

    if (response && response.success && completed) {
      if (progress) progress.complete("فایل پشتیبان آماده شد و دانلود آغاز شد.");
      HST.toast("پشتیبان ساخته شد.", "success");
    } else if (progress) {
      progress.fail("ساخت فایل پشتیبان انجام نشد.");
    }
    if (!progress) HST.loader.hide();
  });

  $("#hst-backup-week-filter, #hst-backup-day-filter").on("change", renderList);

  $file.on("change", function () {
    setSelectedFile(selectedFile());
  });

  $(document).on("dragenter dragover", "[data-hst-backup-drop]", function (event) {
    event.preventDefault();
    event.stopPropagation();
    $(this).addClass("is-filled");
  });

  $(document).on("dragleave dragend drop", "[data-hst-backup-drop]", function (event) {
    event.preventDefault();
    event.stopPropagation();
    if (!selectedFile()) $(this).removeClass("is-filled");
  });

  $(document).on("drop", "[data-hst-backup-drop]", function (event) {
    const files = event.originalEvent && event.originalEvent.dataTransfer ? event.originalEvent.dataTransfer.files : null;
    if (!files || !files.length) return;
    const input = $file.get(0);
    if (input) input.files = files;
    setSelectedFile(files[0]);
  });

  $restore.on("click", async function () {
    const file = selectedFile();
    if (!file) {
      HST.toast("ابتدا فایل پشتیبان را انتخاب کنید.", "error");
      return;
    }

    const result = await HSTModal.open({
      title: "اعمال پشتیبان",
      text: "اطلاعات فعلی سامانه با اطلاعات فایل پشتیبان جایگزین شود؟",
      confirmText: "اعمال پشتیبان",
      cancelText: "بستن",
    });

    if (!result || result.isConfirmed !== true) return;

    const formData = new FormData();
    formData.append("action", "hst_backup_restore");
    formData.append("nonce", HST.nonce);
    formData.append("backup_file", file);

    const restoreBusy = HST.setBusy(this);
    openProgress("در حال بارگذاری و بررسی فایل پشتیبان...");

    try {
      const startResponse = await $.ajax({
        url: HST.ajaxUrl,
        method: "POST",
        data: formData,
        processData: false,
        contentType: false,
        dataType: "json",
      });

      if (!startResponse || !startResponse.success) {
        const error = new Error(HST.getMessage(startResponse, "آماده‌سازی فایل پشتیبان ناموفق بود."));
        error.responseJSON = startResponse;
        throw error;
      }

      const startData = startResponse.data || {};
      if (!startData.job_id) {
        throw new Error("شناسه عملیات بازیابی از سرور دریافت نشد.");
      }

      updateProgress(startData.progress || 1, startData.message || "بازیابی مرحله‌ای آغاز شد...");
      const finalData = await runRestoreJob(startData.job_id);

      HST.toast(finalData.message || "پشتیبان با موفقیت اعمال شد.", "success");
      $file.val("");
      setSelectedFile(null);
      loadList();
      completeProgress(finalData.message || "پشتیبان با موفقیت اعمال شد", 1000);
    } catch (error) {
      const message = HST.getMessage(error, "ارتباط با سرور برقرار نشد.");
      failProgress(message);
      HST.toast(message, "error");
    } finally {
      restoreBusy();
    }
  });

  loadList();
});
