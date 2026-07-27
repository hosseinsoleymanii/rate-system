jQuery(function ($) {
  const $page = $('[data-hst-notifications]');
  if (!$page.length) return;

  function request(action, data, reload = true) {
    return HST.request({
      action,
      data,
      successMessage: true,
      reload,
    });
  }

  const selectedUsers = new Map();
  let userSearchTimer = null;
  let lastSearchTerm = '';

  function escapeHtml(value) {
    return $('<div>').text(value || '').html();
  }

  function renderSelectedUsers() {
    const $box = $('.hst-user-picker-selected');
    const $clear = $('.hst-user-picker-clear');

    $box.empty();

    if (!selectedUsers.size) {
      $box.append('<p class="hst-user-picker-empty">هنوز کاربری انتخاب نشده است.</p>');
      $clear.attr('hidden', true);
      return;
    }

    $clear.removeAttr('hidden');

    selectedUsers.forEach((user, id) => {
      $box.append(`
        <span class="hst-user-picker-chip" data-id="${id}">
          <span class="hst-user-picker-chip-info">
            <b>${escapeHtml(user.name)}</b>
            <small>${escapeHtml(user.roles || user.phone || '')}</small>
          </span>
          <button type="button" class="hst-user-picker-remove" aria-label="حذف ${escapeHtml(user.name)}">&times;</button>
          <input type="hidden" name="user_targets[]" value="${id}">
        </span>
      `);
    });
  }

  function renderUserResults(items, term) {
    const $results = $('.hst-user-picker-results');
    $results.empty().removeAttr('hidden');

    if (!term || term.length < 2) {
      $results.attr('hidden', true);
      return;
    }

    if (!items.length) {
      $results.append('<p class="hst-notice">کاربری با این جست‌وجو پیدا نشد.</p>');
      return;
    }

    items.forEach((user) => {
      const selected = selectedUsers.has(String(user.id));
      $results.append(`
        <button type="button" class="hst-user-picker-result ${selected ? 'is-selected' : ''}" data-id="${user.id}" data-name="${escapeHtml(user.name)}" data-phone="${escapeHtml(user.phone)}" data-roles="${escapeHtml(user.roles)}" ${selected ? 'disabled' : ''}>
          <span>
            <b>${escapeHtml(user.name)}</b>
            <small>${escapeHtml(user.roles || 'کاربر')}</small>
          </span>
          <em>${escapeHtml(user.phone || '')}</em>
        </button>
      `);
    });
  }

  function searchUsers(term) {
    lastSearchTerm = term;
    const $results = $('.hst-user-picker-results');

    if (!term || term.length < 2) {
      renderUserResults([], term);
      return;
    }

    $results.removeAttr('hidden').html('<p class="hst-notice">' + HST.loadingMarkup() + '</p>');

    HST.ajax({ action: 'hst_search_notification_users', query: term })
      .done((res) => {
        if (term !== lastSearchTerm) return;
        if (!res || !res.success) {
          $results.html(`<p class="hst-notice">${HST.escapeHtml(HST.getMessage(res, 'جست‌وجو انجام نشد.'))}</p>`);
          return;
        }
        renderUserResults(res.data?.items || [], term);
      })
      .fail((xhr) => {
        if (term !== lastSearchTerm) return;
        $results.html(`<p class="hst-notice">${HST.escapeHtml(HST.getMessage(xhr, 'ارتباط با سرور برقرار نشد.'))}</p>`);
      });
  }

  $(document).on('change', '#hst-notification-audience', function () {
    const value = $(this).val();
    $('.hst-notification-targets').attr('hidden', true);
    $(`.hst-notification-targets[data-target="${value}"]`).removeAttr('hidden');
  });

  $(document).on('input', '.hst-user-picker-search', function () {
    const term = $.trim($(this).val());
    clearTimeout(userSearchTimer);
    userSearchTimer = setTimeout(() => searchUsers(term), 300);
  });

  $(document).on('click', '.hst-user-picker-result', function () {
    const $btn = $(this);
    const id = String($btn.data('id'));

    if (selectedUsers.has(id)) return;

    selectedUsers.set(id, {
      id,
      name: $btn.data('name'),
      phone: $btn.data('phone'),
      roles: $btn.data('roles'),
    });

    renderSelectedUsers();
    $btn.addClass('is-selected').prop('disabled', true);
  });

  $(document).on('click', '.hst-user-picker-remove', function () {
    const id = String($(this).closest('.hst-user-picker-chip').data('id'));
    selectedUsers.delete(id);
    renderSelectedUsers();
    $(`.hst-user-picker-result[data-id="${id}"]`).removeClass('is-selected').prop('disabled', false);
  });

  $(document).on('click', '.hst-user-picker-clear', function () {
    selectedUsers.clear();
    renderSelectedUsers();
    $('.hst-user-picker-result').removeClass('is-selected').prop('disabled', false);
  });


  function openNotificationModal() {
    const $modal = $('#hst-notification-modal');
    if (!$modal.length) return;
    $modal.addClass('is-active').attr('aria-hidden', 'false');
    window.setTimeout(function () {
      $modal.find('input, select, textarea').filter(':visible').first().trigger('focus');
    }, 80);
  }

  function closeNotificationModal() {
    $('#hst-notification-modal').removeClass('is-active').attr('aria-hidden', 'true');
  }

  $('#hst-notification-add').on('click', openNotificationModal);
  $(document).on('click', '[data-hst-notification-modal-close]', closeNotificationModal);

  function smsSentLabelHtml() {
    return '<span class="hst-status hst-status--success hst-sms-sent-label">پیامک ارسال شده</span>';
  }

  const notificationSmsDefaultTemplate = String($('#hst-notification-sms-message').val() || '');

  function editableSmsTemplate(raw, fallback) {
    raw = String(raw || '').trim();
    if (!raw) return fallback;
    try {
      const parsed = JSON.parse(raw);
      if (parsed && parsed.vars) return fallback;
    } catch (e) {}
    return raw;
  }

  function renderSmsTemplate(template, context) {
    let output = String(template || '');
    Object.keys(context || {}).forEach(function (key) {
      const value = String(context[key] == null ? '' : context[key]);
      output = output.split('{' + key + '}').join(value);
      output = output.split('%' + key + '%').join(value);
    });
    return $.trim(output);
  }

  function openNotificationSmsModal(row, toggle) {
    const $modal = $('#hst-notification-sms-modal');
    if (!$modal.length) return;

    $activeSmsRow = row && row.length ? row : $();
    $activeSmsToggle = toggle && toggle.length ? toggle : $();
    activeSmsNoticeId = Number($activeSmsRow.data('id') || ($activeSmsToggle.data('id') || 0));
    smsModalConfirmed = false;

    $('#hst-notification-sms-test-phone').val('');
    $('#hst-notification-sms-message').val(editableSmsTemplate($activeSmsRow.attr('data-sms-message'), notificationSmsDefaultTemplate));
    updateNotificationSmsPreview();

    $modal.addClass('is-active').attr('aria-hidden', 'false');
    window.setTimeout(function () {
      $('#hst-notification-sms-message').trigger('focus');
    }, 80);
  }

  function closeNotificationSmsModal() {
    $('#hst-notification-sms-modal').removeClass('is-active').attr('aria-hidden', 'true');

    if (!smsModalConfirmed && activeSmsNoticeId && $activeSmsToggle.length) {
      $activeSmsToggle.prop('checked', false).prop('disabled', false);
    }

    if (!smsModalConfirmed) {
      activeSmsNoticeId = 0;
      $activeSmsRow = $();
      $activeSmsToggle = $();
    }
  }

  function notificationSmsPreviewContext() {
    const $body = $('#hst-notification-sms-modal .hst-modal__body');
    return {
      title: String($activeSmsRow && $activeSmsRow.length ? ($activeSmsRow.attr('data-title') || '') : 'عنوان نمونه اطلاعیه'),
      notice_type: String($activeSmsRow && $activeSmsRow.length ? ($activeSmsRow.attr('data-type-label') || '') : 'اطلاعیه'),
      school: String($body.data('sms-preview-school') || ''),
      date: String($body.data('sms-preview-date') || ''),
    };
  }

  function updateNotificationSmsPreview() {
    const ctx = notificationSmsPreviewContext();
    ctx.name = String($('#hst-notification-sms-modal .hst-modal__body').data('sms-preview-name') || 'کاربر نمونه');
    ctx.message = String($activeSmsRow && $activeSmsRow.length ? ($activeSmsRow.attr('data-message') || '') : 'متن نمونه اطلاعیه');
    ctx.type = String($activeSmsRow && $activeSmsRow.length ? ($activeSmsRow.attr('data-type-label') || '') : 'اطلاعیه');
    const template = String($('#hst-notification-sms-message').val() || '');
    $('#hst-notification-sms-preview').text(renderSmsTemplate(template, ctx) || '—');
    if (activeSmsNoticeId && $.trim(template) && HST.smsUsage) {
      HST.smsUsage.schedule({
        target: '#hst-notification-sms-usage',
        action: 'hst_notification_sms_estimate',
        data: { id: activeSmsNoticeId, message: template },
      });
    } else if (HST.smsUsage) {
      HST.smsUsage.clear('#hst-notification-sms-usage');
    }
  }


$(document).on('click', '[data-hst-notification-sms-close]', closeNotificationSmsModal);
$(document).on('input', '#hst-notification-sms-message', updateNotificationSmsPreview);
$(document).on('click', '#hst-notification-sms-test-send', function () {
    const phone = $.trim($('#hst-notification-sms-test-phone').val() || '');
    const message = $.trim($('#hst-notification-sms-message').val() || '');

    if (!phone) {
      HST.toast('شماره موبایل تست را وارد کنید.', 'error');
      return;
    }
    if (!message) {
      HST.toast('متن پیامک را وارد کنید.', 'error');
      return;
    }

    HST.request({
      action: 'hst_notification_sms_test',
      data: { id: activeSmsNoticeId, phone, message },
      successMessage: true,
      reload: false,
    });
  });

  $(document).on('click', '#hst-notification-sms-confirm', async function () {
    if (!activeSmsNoticeId) {
      HST.toast('اطلاعیه انتخاب‌شده معتبر نیست.', 'error');
      return;
    }

    const message = $.trim($('#hst-notification-sms-message').val() || '');
    if (!message) {
      HST.toast('متن پیامک را وارد کنید.', 'error');
      return;
    }

    const response = await HST.request({
      action: 'hst_update_notification_sms',
      data: { id: activeSmsNoticeId, enabled: 1, message },
      trigger: this,
      successMessage: true,
      reload: false,
      dedupe: 'hst_update_notification_sms_' + activeSmsNoticeId,
      onSuccess: function (res) {
        smsModalConfirmed = true;
        if ($activeSmsRow.length) {
          $activeSmsRow
            .attr('data-sms-enabled', '1')
            .attr('data-sms-message', message);
        }
        if ($activeSmsToggle.length) {
          $activeSmsToggle.prop('checked', true).prop('disabled', false);
          if (res && res.data && res.data.sms_sent) {
            $activeSmsToggle.closest('td').html(smsSentLabelHtml());
          }
        }
        closeNotificationSmsModal();
        activeSmsNoticeId = 0;
        $activeSmsRow = $();
        $activeSmsToggle = $();
      },
    });

    if (!response || !response.success) {
      if ($activeSmsToggle.length) {
        $activeSmsToggle.prop('checked', false).prop('disabled', false);
      }
    }
  });

$('#hst-notification-form').on('submit', function (e) {
    e.preventDefault();

    const audience = $('#hst-notification-audience').val();
    const title = $.trim($(this).find('[name="title"]').val() || '');
    const message = $.trim($(this).find('[name="message"]').val() || '');

    if (!title || !message) {
      HST.toast('عنوان و متن اطلاعیه الزامی است.', 'error');
      return;
    }

    if (title.length > 160) {
      HST.toast('عنوان اطلاعیه نباید بیشتر از ۱۶۰ کاراکتر باشد.', 'error');
      return;
    }

    if (audience === 'roles' && !$(this).find('[name="role_targets[]"]:checked').length) {
      HST.toast('حداقل یک نقش را انتخاب کنید.', 'error');
      return;
    }

    if (audience === 'classes' && !$(this).find('[name="class_targets[]"]:checked').length) {
      HST.toast('حداقل یک کلاس را انتخاب کنید.', 'error');
      return;
    }

    if (audience === 'users' && !selectedUsers.size) {
      HST.toast('لطفاً حداقل یک کاربر را جست‌وجو و انتخاب کنید.', 'error');
      return;
    }

    const formData = $(this).serializeArray().reduce((acc, item) => {
      if (item.name.endsWith('[]')) {
        const key = item.name.replace('[]', '');
        acc[key] = acc[key] || [];
        acc[key].push(item.value);
      } else {
        acc[item.name] = item.value;
      }
      return acc;
    }, {});

    request('hst_add_notification', formData);
  });

  $(document).on('click', '.hst-delete-notification', function () {
    const id = $(this).closest('.hst-notification-item').data('id');
    HST.request({
      action: 'hst_delete_notification',
      data: { id },
      confirm: { title: 'حذف اطلاعیه؟', text: 'این عملیات قابل بازگشت نیست.' },
      successMessage: true,
      reload: true,
    });
  });

  $(document).on('change', '.hst-toggle-notification', async function () {
    const $checkbox = $(this);
    const $row = $checkbox.closest('.hst-notification-item');
    const id = Number($checkbox.data('id') || $row.data('id'));
    const isActive = $checkbox.is(':checked') ? 1 : 0;
    const previousState = !isActive;

    $checkbox.prop('disabled', true);

    const response = await request('hst_toggle_notification', { id, is_active: isActive }, false);

    if (!response || !response.success) {
      $checkbox.prop('checked', previousState);
      $row.attr('data-status-label', previousState ? 'فعال' : 'غیرفعال');
    } else {
      $row.attr('data-status-label', isActive ? 'فعال' : 'غیرفعال');
    }

    $checkbox.prop('disabled', false);
  });



  $(document).on('change', '.hst-toggle-notification-sms', async function () {
    const $checkbox = $(this);
    const $row = $checkbox.closest('.hst-notification-item');
    const id = Number($checkbox.data('id') || $row.data('id'));
    const enabled = $checkbox.is(':checked') ? 1 : 0;

    if (!id) {
      HST.toast('شناسه اطلاعیه نامعتبر است.', 'error');
      $checkbox.prop('checked', false);
      return;
    }

    if (enabled) {
      $checkbox.prop('disabled', true);
      openNotificationSmsModal($row, $checkbox);
      return;
    }

    $checkbox.prop('disabled', true);
    const response = await HST.request({
      action: 'hst_update_notification_sms',
      data: { id, enabled: 0, sms_message: '' },
      successMessage: true,
      reload: false,
      dedupe: 'hst_update_notification_sms_' + id,
      onSuccess: function () {
        $row.attr('data-sms-enabled', '0');
        $checkbox.prop('checked', false);
      },
    });

    if (!response || !response.success) {
      $checkbox.prop('checked', true);
    }

    $checkbox.prop('disabled', false);
  });


  function setNotificationViewText(selector, value) {
    $(selector).text(value || '—');
  }

  function avatarReviewStatusClass(status) {
    if (status === 'approved') return 'hst-status--success';
    if (status === 'rejected') return 'hst-status--danger';
    if (status === 'superseded') return 'hst-status--muted';
    return 'hst-status--warning';
  }

  function setNotificationReviewVisibility($element, visible) {
    if (!$element || !$element.length) return;
    $element.prop('hidden', !visible);
  }

  function resetNotificationAvatarReview() {
    const $review = $('#hst-notification-view-avatar-review');
    const $avatar = $('#hst-notification-view-avatar');
    const $actions = $('#hst-notification-view-avatar-actions');

    setNotificationReviewVisibility($review, false);
    setNotificationReviewVisibility($avatar, false);
    setNotificationReviewVisibility($actions, false);
    $('#hst-notification-view-avatar-image').attr({ src: '', alt: '' });
    setNotificationViewText('#hst-notification-view-avatar-name', '');
    setNotificationViewText('#hst-notification-view-avatar-role', '');
    setNotificationViewText('#hst-notification-view-avatar-status', '');
    $actions.find('[data-hst-avatar-notification-action]').removeAttr('data-user-id data-notification-id');
  }

  function configureNotificationAvatarReview($row) {
    const isAvatarReview = $row.attr('data-avatar-review') === '1';
    const userId = Number($row.attr('data-avatar-review-user')) || 0;
    const notificationId = Number($row.data('id')) || 0;
    const name = String($row.attr('data-avatar-review-name') || '');
    const role = String($row.attr('data-avatar-review-role') || '');
    const image = String($row.attr('data-avatar-review-image') || '');
    const status = String($row.attr('data-avatar-review-status') || '');
    const statusLabel = String($row.attr('data-avatar-review-status-label') || '');
    const canReview = $row.attr('data-avatar-review-can') === '1';
    const $review = $('#hst-notification-view-avatar-review');
    const $avatar = $('#hst-notification-view-avatar');
    const $actions = $('#hst-notification-view-avatar-actions');

    resetNotificationAvatarReview();

    if (!isAvatarReview || !userId) {
      return;
    }

    setNotificationReviewVisibility($review, true);
    setNotificationViewText('#hst-notification-view-avatar-name', name);
    setNotificationViewText('#hst-notification-view-avatar-role', role);

    const $status = $('#hst-notification-view-avatar-status');
    $status
      .removeClass('hst-status--success hst-status--danger hst-status--warning hst-status--muted')
      .addClass(avatarReviewStatusClass(status))
      .text(statusLabel || 'بررسی شده');

    if (image) {
      $('#hst-notification-view-avatar-image').attr({ src: image, alt: name || 'تصویر پروفایل' });
      setNotificationReviewVisibility($avatar, true);
    } else {
      $('#hst-notification-view-avatar-image').attr({ src: '', alt: '' });
      setNotificationReviewVisibility($avatar, false);
    }

    setNotificationReviewVisibility($actions, canReview);
    $actions.find('[data-hst-avatar-notification-action]').attr({
      'data-user-id': userId,
      'data-notification-id': notificationId,
    });
  }

  function openNotificationViewModal($row) {
    const $modal = $('#hst-notification-view-modal');
    if (!$modal.length || !$row.length) return;

    setNotificationViewText('#hst-notification-view-field-title', $row.data('title'));
    setNotificationViewText('#hst-notification-view-field-message', $row.data('message'));
    setNotificationViewText('#hst-notification-view-field-audience', $row.data('audience-label'));
    setNotificationViewText('#hst-notification-view-field-type', $row.data('type-label'));
    setNotificationViewText('#hst-notification-view-field-source', $row.data('source-label'));
    setNotificationViewText('#hst-notification-view-field-status', $row.attr('data-status-label') || $row.data('status-label'));
    setNotificationViewText('#hst-notification-view-field-date', $row.data('created-label'));

    const linkUrl = String($row.data('link-url') || '');
    const $linkField = $('#hst-notification-view-field-link');

    if (linkUrl) {
      $linkField.html(
        '<a class="hst-link" href="' +
          HST.escapeHtml(linkUrl) +
          '" target="_blank" rel="noopener">لینک اطلاعیه</a>'
      );
    } else {
      $linkField.html('<span class="hst-muted">لینکی برای این اطلاعیه ثبت نشده است.</span>');
    }

    configureNotificationAvatarReview($row);
    $modal.attr('data-active-notification-id', Number($row.data('id')) || 0);
    $modal.addClass('is-active').attr('aria-hidden', 'false');
  }

  function closeNotificationViewModal() {
    $('#hst-notification-view-modal')
      .removeClass('is-active')
      .removeAttr('data-active-notification-id')
      .attr('aria-hidden', 'true');
    resetNotificationAvatarReview();
  }

  function applyAvatarReviewResult(data) {
    data = data || {};
    const notificationId = Number(data.notification_id) || 0;
    const userId = Number(data.user_id) || 0;
    const status = String(data.status || '');
    const statusLabel = String(data.status_label || (status === 'approved' ? 'تأیید شده' : 'رد شده'));
    let $rows = notificationId
      ? $('.hst-notification-item[data-id="' + notificationId + '"]')
      : $('.hst-notification-item[data-avatar-review-user="' + userId + '"]');

    $rows.each(function () {
      const $row = $(this);
      $row
        .attr('data-avatar-review-status', status)
        .attr('data-avatar-review-status-label', statusLabel)
        .attr('data-avatar-review-can', '0')
        .attr('data-status-label', 'غیرفعال');
      $row.find('[data-hst-avatar-notification-action]').remove();
      $row.find('.hst-toggle-notification').prop('checked', false);
    });

    const activeId = Number($('#hst-notification-view-modal').attr('data-active-notification-id')) || 0;
    if (activeId && (!notificationId || activeId === notificationId)) {
      const $activeRow = $('.hst-notification-item[data-id="' + activeId + '"]').first();
      if ($activeRow.length) {
        configureNotificationAvatarReview($activeRow);
        $('#hst-notification-view-field-status').text('غیرفعال');
      }
    }
  }

  async function reviewAvatarFromNotification($trigger) {
    const decision = String($trigger.attr('data-hst-avatar-notification-action') || '');
    const userId = Number($trigger.attr('data-user-id')) || 0;
    const notificationId = Number($trigger.attr('data-notification-id')) || 0;
    if (!userId || !notificationId || !['approve', 'reject'].includes(decision)) return;

    const response = await HST.request({
      action: 'hst_avatar_review',
      data: {
        user_id: userId,
        notification_id: notificationId,
        decision: decision,
      },
      confirm: decision === 'reject' ? 'تصویر ارسالی رد و حذف شود؟ تصویر قبلی کاربر حفظ خواهد شد.' : null,
      successMessage: true,
      reload: false,
      trigger: $trigger,
      dedupe: 'hst_avatar_review_' + userId,
    });

    if (response && response.success) {
      $(document).trigger('hst:avatar-reviewed', [response.data || {}]);
    }
  }

  $(document).on('click', '.hst-view-notification', function () {
    openNotificationViewModal($(this).closest('.hst-notification-item'));
  });

  $(document).on('click', '[data-hst-notification-view-close]', closeNotificationViewModal);

  $(document).on('click', '[data-hst-avatar-notification-action]', function () {
    reviewAvatarFromNotification($(this));
  });

  $(document).on('hst:avatar-reviewed', function (event, data) {
    applyAvatarReviewResult(data || {});
  });

  $(document).on('click', '.hst-mark-notification-read', function () {
    const $item = $(this).closest('.hst-notification-item');
    request('hst_mark_notification_read', { id: $item.data('id') });
  });

  // ---- Notification recipient report modal ------------------------------
  const $notificationReportModal = $('[data-hst-notification-report-modal]');
  const $notificationReportModalBody = $notificationReportModal.find('.hst-modal__body');
  let notificationReportRows = [];
  let notificationReportSearchTimer = null;

  function reportText(value) {
    return HST.escapeHtml(String(value == null || value === '' ? '—' : value));
  }

  function reportUserAvatarHtml(row) {
    if (row && row.avatar_url) {
      return '<span class="hst-user-avatar"><img src="' + HST.escapeHtml(row.avatar_url) + '" alt="' + reportText(row.name || 'کاربر') + '"></span>';
    }

    const name = String((row && row.name) || "کاربر");
    const initials = String((row && row.initials) || HST.initials(name, (row && row.first_name) || "", (row && row.last_name) || ""));
    return '<span class="hst-user-avatar hst-user-avatar--placeholder" aria-label="بدون تصویر پروفایل؛ حروف اول نام ' + HST.escapeHtml(name) + '"><span class="hst-user-avatar__placeholder">' + HST.escapeHtml(initials) + '</span></span>';
  }

  function reportUserCellHtml(row) {
    return '<div class="hst-user-id hst-report-user-id">' +
      reportUserAvatarHtml(row) +
      '<span class="hst-user-id__name">' +
        '<strong>' + reportText(row.name) + '</strong>' +
        '<small>' + reportText(row.phone || '') + '</small>' +
      '</span>' +
    '</div>';
  }

  function normalizeReportText(value) {
    return String(value || '')
      .replace(/ي/g, 'ی')
      .replace(/ك/g, 'ک')
      .replace(/[\u200c\u200f\u200e]/g, '')
      .replace(/\s+/g, ' ')
      .trim()
      .toLowerCase();
  }

  function renderReportSummary(summary) {
    summary = summary || {};
    $notificationReportModal.find('[data-hst-notification-report-summary]').html(
      '<div class="hst-report-stats">' +
        '<div class="hst-report-stat hst-report-stat--total"><b>' + reportText(summary.total || 0) + '</b><span>کل گیرندگان</span></div>' +
        '<div class="hst-report-stat hst-report-stat--new"><b>' + reportText(summary.read || 0) + '</b><span>خوانده‌شده</span></div>' +
        '<div class="hst-report-stat hst-report-stat--warning"><b>' + reportText(summary.unread || 0) + '</b><span>خوانده‌نشده</span></div>' +
      '</div>'
    );
  }

  function renderReportFilters(filters) {
    filters = filters || {};
    const $role = $notificationReportModal.find('[data-hst-notification-report-role-filter]');
    const $class = $notificationReportModal.find('[data-hst-notification-report-class-filter]');

    let roleHtml = '<option value="">همه نقش‌ها</option>';
    (filters.roles || []).forEach(function (item) {
      roleHtml += '<option value="' + reportText(item.id) + '">' + reportText(item.name) + '</option>';
    });
    $role.html(roleHtml).val('');

    let classHtml = '<option value="">همه کلاس‌ها</option>';
    HST.sortClassItems(filters.classes || [], "name").forEach(function (item) {
      classHtml += '<option value="' + reportText(item.id) + '">' + reportText(item.name) + '</option>';
    });
    $class.html(classHtml).val('');
  }

  function filteredNotificationReportRows() {
    const status = $notificationReportModal.find('[data-hst-notification-report-read-filter]').val() || '';
    const role = $notificationReportModal.find('[data-hst-notification-report-role-filter]').val() || '';
    const classId = String($notificationReportModal.find('[data-hst-notification-report-class-filter]').val() || '');
    const search = normalizeReportText($notificationReportModal.find('[data-hst-notification-report-search]').val() || '');

    return notificationReportRows.filter(function (row) {
      if (status === 'read' && !row.is_read) return false;
      if (status === 'unread' && row.is_read) return false;
      if (role && (row.roles || []).indexOf(role) === -1) return false;
      if (classId && !(row.classes || []).some(function (item) { return String(item.id) === classId; })) return false;

      if (search) {
        const hay = normalizeReportText([row.name, row.phone, row.role_label, row.class_label].join(' '));
        if (hay.indexOf(search) === -1) return false;
      }

      return true;
    });
  }

  function renderNotificationReportRows() {
    const rows = filteredNotificationReportRows();
    const $body = $notificationReportModal.find('[data-hst-notification-report-body]');

    if (!rows.length) {
      $body.html('<p class="hst-alert">موردی با این فیلتر پیدا نشد.</p>');
      return;
    }

    let html = '<div class="hst-table-wrap hst-data-table-wrap hst-report-table-wrap"><table class="hst-table hst-data-table" data-hst-no-pagination="1">';
    html += '<thead><tr>' +
      '<th>ردیف</th>' +
      '<th class="hst-col-fill">کاربر</th>' +
      '<th>نقش</th>' +
      '<th>کلاس</th>' +
      '<th>وضعیت خواندن</th>' +
      '<th>زمان مشاهده</th>' +
    '</tr></thead><tbody>';

    rows.forEach(function (row, index) {
      html += '<tr>' +
        '<td>' + reportText(index + 1) + '</td>' +
        '<td class="hst-col-fill">' + reportUserCellHtml(row) + '</td>' +
        '<td>' + reportText(row.role_label) + '</td>' +
        '<td>' + reportText(row.class_label) + '</td>' +
        '<td><span class="hst-status ' + (row.is_read ? 'hst-status--success' : 'hst-status--warning') + '">' + (row.is_read ? 'خوانده‌شده' : 'خوانده‌نشده') + '</span></td>' +
        '<td>' + reportText(row.read_at || '') + '</td>' +
      '</tr>';
    });

    html += '</tbody></table></div>';
    $body.html(html);
  }

  function openNotificationReportModal() {
    $notificationReportModal.addClass('is-open').attr('aria-hidden', 'false');
    $('body').addClass('hst-modal-open');
  }

  function closeNotificationReportModal() {
    HST.modalLoading.hide($notificationReportModalBody);
    $notificationReportModal.removeClass('is-open').attr('aria-hidden', 'true');
    $('body').removeClass('hst-modal-open');
  }

  $(document).on('click', '.hst-notification-report', async function () {
    const id = Number($(this).closest('.hst-notification-item').data('id')) || 0;
    if (!id) return;

    openNotificationReportModal();
    $notificationReportModal.find('[data-hst-notification-report-summary]').empty();
    $notificationReportModal.find('[data-hst-notification-report-body]').empty();
    HST.modalLoading.show($notificationReportModalBody);

    const response = await HST.request({
      action: 'hst_notification_report',
      data: { id: id },
      showLoader: false,
    });

    HST.modalLoading.hide($notificationReportModalBody);

    if (!response || !response.success) {
      $notificationReportModal.find('[data-hst-notification-report-body]').html('<p class="hst-alert hst-alert--error">گزارش اطلاعیه دریافت نشد.</p>');
      return;
    }

    const data = response.data || {};
    notificationReportRows = data.items || [];
    $notificationReportModal.find('#hst-notification-report-title').text('گزارش اطلاعیه: ' + (data.notice && data.notice.title ? data.notice.title : '—'));
    renderReportSummary(data.summary || {});
    renderReportFilters(data.filters || {});
    renderNotificationReportRows();
  });

  $(document).on('click', '[data-hst-notification-report-close]', closeNotificationReportModal);

  $(document).on('change', '[data-hst-notification-report-read-filter], [data-hst-notification-report-role-filter], [data-hst-notification-report-class-filter]', renderNotificationReportRows);
  $(document).on('input', '[data-hst-notification-report-search]', function () {
    clearTimeout(notificationReportSearchTimer);
    notificationReportSearchTimer = setTimeout(renderNotificationReportRows, 220);
  });

  $('#hst-mark-all-notifications-read').on('click', function () {
    request('hst_mark_all_notifications_read', {});
  });

  
    renderSelectedUsers();
});