window.HST = window.HST || {};

(function ($, HST) {
  "use strict";

  const rootSelector = "[data-hst-header-notifications]";
  const modalSelector = "[data-hst-notification-modal]";
  const listSelector = "[data-hst-header-notification-list]";

  function escapeHtml(value) {
    if (HST.escapeHtml) return HST.escapeHtml(value);
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }


  function iconSvg(name) {
    const paths = {
      "avatar-approve": '<g stroke-width="2.15"><circle cx="12" cy="12" r="8.5"/><path d="M8.2 12.2l2.5 2.5 5.2-5.4"/></g>',
      "avatar-reject": '<g stroke-width="2.15"><circle cx="12" cy="12" r="8.5"/><path d="M9 9l6 6M15 9l-6 6"/></g>',
      "notification-view": '<g stroke-width="2.15"><circle cx="10.5" cy="10.5" r="5.5"/><path d="M15 15l5 5"/></g>',
      "notification-read": '<g stroke-width="2.15"><path d="M3.5 12.5l3.2 3.2 6.1-7"/><path d="M10.5 15.5l2 2 7.5-9"/></g>'
    };

    return `<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false">${paths[name] || ''}</svg>`;
  }

  function renderItem(item) {
    const isUnread = !item.is_read;
    const review = item && item.avatar_review && typeof item.avatar_review === "object"
      ? item.avatar_review
      : null;
    const link = item.link_url
      ? `<a class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon"
            href="${escapeHtml(item.link_url)}"
            title="مشاهده"
            aria-label="مشاهده">${iconSvg("notification-view")}</a>`
      : "";
    const markButton = isUnread
      ? `<button type="button" class="hst-btn hst-btn--soft hst-btn--sm" data-hst-mark-header-notification-read title="خوانده شد" aria-label="خوانده شد">${iconSvg("notification-read")}<span>خوانده شد</span></button>`
      : "";

    let reviewInfo = "";
    let reviewActions = "";

    if (review && Number(review.user_id)) {
      const avatar = review.image_url
        ? `<span class="hst-user-avatar"><img src="${escapeHtml(review.image_url)}" alt="${escapeHtml(review.name || "تصویر پروفایل")}"></span>`
        : "";
      reviewInfo = `
        <div class="hst-user-id">
          ${avatar}
          <span class="hst-user-id__name">
            ${escapeHtml(review.name || "کاربر")}
            <small class="hst-muted">${escapeHtml(review.role || "")}</small>
          </span>
        </div>`;

      if (review.can_review) {
        reviewActions = `
          <button type="button"
                  class="hst-btn hst-btn--primary hst-btn--sm hst-btn--icon"
                  data-hst-avatar-header-action="approve"
                  data-user-id="${Number(review.user_id) || 0}"
                  data-notification-id="${Number(item.id) || 0}"
                  title="تأیید تصویر"
                  aria-label="تأیید تصویر">${iconSvg("avatar-approve")}</button>
          <button type="button"
                  class="hst-btn hst-btn--danger hst-btn--sm hst-btn--icon"
                  data-hst-avatar-header-action="reject"
                  data-user-id="${Number(review.user_id) || 0}"
                  data-notification-id="${Number(item.id) || 0}"
                  title="رد تصویر"
                  aria-label="رد تصویر">${iconSvg("avatar-reject")}</button>`;
      } else {
        const statusClass = review.status === "approved"
          ? "hst-status--success"
          : (review.status === "rejected"
            ? "hst-status--danger"
            : (review.status === "superseded" ? "hst-status--muted" : "hst-status--warning"));
        reviewActions = `<span class="hst-status ${statusClass}">${escapeHtml(review.status_label || "بررسی شده")}</span>`;
      }
    }

    return `
      <article class="hst-header-notification-item ${isUnread ?"is-unread" : "is-read"}" data-notification-id="${Number(item.id) || 0}">
        <div class="hst-header-notification-main">
          <div class="hst-header-notification-title-row">
            <strong>${escapeHtml(item.title)}</strong>
            <span>${isUnread ? "جدید" : "خوانده‌شده"}</span>
          </div>
          <p>${escapeHtml(item.message)}</p>
          ${reviewInfo}
        </div>
        <div class="hst-header-notification-item-actions">
          ${reviewActions}
          ${link}
          ${markButton}
        </div>
      </article>
    `;
  }

  function setCount(count) {
    const $count = $("[data-hst-notification-count]");
    const safeCount = Number.parseInt(count, 10) || 0;

    $count.text(safeCount);
    $count.prop("hidden", safeCount < 1);
    $("[data-hst-mark-all-header-notifications]").prop("disabled", safeCount < 1);
  }

  function renderList(items) {
    const $list = $(listSelector);

    if (!items || !items.length) {
      $list.html('<p class="hst-header-notification-empty">فعلاً اطلاعیه‌ای ندارید.</p>');
      return;
    }

    $list.html(items.map(renderItem).join(""));
  }

  async function refreshHeaderNotifications(showLoading = false) {
    const $list = $(listSelector);
    if (showLoading) {
      $list.html('<p class="hst-header-notification-empty">' + HST.loadingMarkup() + '</p>');
    }

    try {
      const res = await HST.ajax({ action: "hst_get_header_notifications" });
      if (!res?.success) {
        if (showLoading) $list.html('<p class="hst-header-notification-empty">دریافت اطلاعیه‌ها انجام نشد.</p>');
        return;
      }

      renderList(res.data?.items || []);
      const unread = res.data?.unread_count || 0;
      setCount(unread);
    } catch (error) {
      if (showLoading) $list.html('<p class="hst-header-notification-empty">دریافت اطلاعیه‌ها انجام نشد.</p>');
      console.error("HST header notifications refresh failed", error);
    }
  }

  function openNotificationModal() {
    const $modal = $(modalSelector);
    $modal.prop("hidden", false).addClass("is-active").attr("aria-hidden", "false");
    $("body").addClass("hst-modal-open");
    refreshHeaderNotifications(true);
  }

  function closeNotificationModal() {
    const $modal = $(modalSelector);
    $modal.removeClass("is-active").prop("hidden", true).attr("aria-hidden", "true");
    $("body").removeClass("hst-modal-open");
  }

  async function markRead(id) {
    if (!id) return;

    try {
      const res = await HST.ajax({
        action: "hst_mark_notification_read",
        id,
      });

      if (res?.success) refreshHeaderNotifications();
    } catch (error) {
      console.error("HST mark notification read failed", error);
    }
  }

  async function reviewAvatar($button) {
    const decision = String($button.attr("data-hst-avatar-header-action") || "");
    const userId = Number($button.attr("data-user-id")) || 0;
    const notificationId = Number($button.attr("data-notification-id")) || 0;
    if (!userId || !notificationId || !["approve", "reject"].includes(decision)) return;

    const response = await HST.request({
      action: "hst_avatar_review",
      data: {
        user_id: userId,
        notification_id: notificationId,
        decision: decision,
      },
      confirm: decision === "reject"
        ? "تصویر ارسالی رد و حذف شود؟ تصویر قبلی کاربر حفظ خواهد شد."
        : null,
      successMessage: true,
      reload: false,
      trigger: $button,
      dedupe: "hst_avatar_review_" + userId,
    });

    if (response && response.success) {
      $(document).trigger("hst:avatar-reviewed", [response.data || {}]);
    }
  }

  async function markAllRead() {
    if ($("[data-hst-mark-all-header-notifications]").prop("disabled")) return;
    try {
      const res = await HST.ajax({
        action: "hst_mark_all_notifications_read",
      });

      if (res?.success) {
        refreshHeaderNotifications();
        if (HST.toast) HST.toast("همه اطلاعیه‌ها خوانده شدند.", "success");
      }
    } catch (error) {
      console.error("HST mark all notifications read failed", error);
    }
  }

  $(document).on("click", ".hst-header-notification-toggle", function (event) {
    event.preventDefault();
    event.stopPropagation();
    openNotificationModal();
  });

  $(document).on("click", "[data-hst-close-notification-modal]", function () {
    closeNotificationModal();
  });

  $(document).on("keydown", function (event) {
    if (event.key === "Escape") closeNotificationModal();
  });

  $(document).on("click", "[data-hst-mark-header-notification-read]", function () {
    const id = $(this).closest("[data-notification-id]").data("notification-id");
    markRead(id);
  });

  $(document).on("click", "[data-hst-avatar-header-action]", function (event) {
    event.preventDefault();
    event.stopPropagation();
    reviewAvatar($(this));
  });

  $(document).on("click", "[data-hst-mark-all-header-notifications]", markAllRead);
  $(document).on("hst:avatar-reviewed", refreshHeaderNotifications);

  $(function () {
    if ($(rootSelector).length) refreshHeaderNotifications();
  });
})(jQuery, window.HST);