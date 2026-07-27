jQuery(function ($) {
  const HSTAssignments = {
    init() {
      this.cache();
      if (!this.$page.length) return;
      this.bind();
      this.filterLessons();
    },
    cache() {
      this.$page = $('[data-hst-assignments]');
      this.$class = $('#hst-assignment-class');
      this.$lesson = $('#hst-assignment-lesson');
    },
    bind() {
      const self = this;
      this.$class.on('change', function () { self.filterLessons(); });
      $('#hst-assignment-create-form').on('submit', function (e) { self.create(e, this); });
      $(document).on('click', '.hst-assignment-toggle-submissions', function () {
        $(this).closest('.hst-assignment-item').find('.hst-assignment-submissions').prop('hidden', function (_, v) { return !v; });
      });
      $(document).on('click', '.hst-assignment-status', function () { self.changeStatus(this); });
      $(document).on('click', '.hst-assignment-delete', function () { self.deleteAssignment(this); });
      $(document).on('submit', '.hst-assignment-submit-form', function (e) { self.submitAnswer(e, this); });
      $(document).on('submit', '.hst-assignment-review-form', function (e) { self.review(e, this); });
    },
    filterLessons() {
      const classId = this.$class.val();
      let first = '';
      this.$lesson.find('option').each(function () {
        const $option = $(this);
        if (!$option.val()) return;
        const visible = String($option.data('class')) === String(classId);
        $option.prop('hidden', !visible);
        if (visible && !first) first = $option.val();
      });
      this.$lesson.val(first || '');
    },
    create(e, form) {
      e.preventDefault();
      const data = $(form).serializeArray().reduce((acc, item) => { acc[item.name] = item.value; return acc; }, {});
      if (!data.class_id || !data.lesson_id || !$.trim(data.title || '')) {
        HST.toast('کلاس، درس و عنوان تکلیف الزامی است.', 'error');
        return;
      }
      if ($.trim(data.title || '').length > 160) {
        HST.toast('عنوان تکلیف نباید بیشتر از ۱۶۰ کاراکتر باشد.', 'error');
        return;
      }
      HST.request({
        action: 'hst_create_assignment',
        data,
        successMessage: true,
        reload: true,
      });
    },
    changeStatus(btn) {
      const $item = $(btn).closest('.hst-assignment-item');
      HST.request({
        action: 'hst_close_assignment',
        data: { assignment_id: $item.data('assignment'), status: $(btn).data('status') },
        successMessage: true,
        reload: true,
      });
    },
    deleteAssignment(btn) {
      const $item = $(btn).closest('.hst-assignment-item');
      HST.request({
        action: 'hst_delete_assignment',
        data: { assignment_id: $item.data('assignment') },
        confirm: { title: 'حذف تکلیف', text: 'آیا از حذف این تکلیف و ارسال‌های آن مطمئن هستید؟' },
        successMessage: true,
        reload: true,
      });
    },
    submitAnswer(e, form) {
      e.preventDefault();
      const input = form.querySelector('input[type="file"]');
      if (!input || !input.files || !input.files.length) {
        HST.toast('انتخاب فایل الزامی است.', 'error');
        return;
      }
      const file = input.files[0];
      if (file.size > 25 * 1024 * 1024) {
        HST.toast('حجم فایل بیش از حد مجاز است.', 'error');
        return;
      }
      const fd = new FormData(form);
      fd.append('action', 'hst_submit_assignment');
      fd.append('nonce', window.hst_ajax_obj?.nonce || '');
      fd.append('assignment_id', $(form).data('assignment'));
      HST.loader.show();
      $.ajax({
        url: window.hst_ajax_obj?.ajax_url || '',
        method: 'POST',
        data: fd,
        processData: false,
        contentType: false,
      }).done(function (res) {
        if (res && res.success) {
          HST.toast(HST.getMessage(res, 'ارسال شد'), 'success');
          window.location.reload();
        } else {
          HST.toast(HST.getMessage(res, 'ارسال انجام نشد'), 'error');
        }
      }).fail(function (xhr) {
        HST.toast(HST.getMessage(xhr, 'ارتباط با سرور برقرار نشد'), 'error');
      }).always(function () {
        HST.loader.hide();
      });
    },
    review(e, form) {
      e.preventDefault();
      const data = $(form).serializeArray().reduce((acc, item) => { acc[item.name] = item.value; return acc; }, {});
      if (data.score && (Number.isNaN(Number(String(data.score).replace(',', '.'))) || Number(String(data.score).replace(',', '.')) < 0 || Number(String(data.score).replace(',', '.')) > 20)) {
        HST.toast('نمره باید عددی بین ۰ تا ۲۰ باشد.', 'error');
        return;
      }
      data.submission_id = $(form).data('submission');
      HST.request({
        action: 'hst_review_assignment_submission',
        data,
        successMessage: true,
        reload: true,
      });
    },
  };

  // Reflect the chosen file name inside the styled uploader label.
  $(document).on('change', '[data-hst-file-input]', function () {
    var name = this.files && this.files.length ? this.files[0].name : 'هیچ فایلی انتخاب نشده است';
    $(this).closest('.hst-file-drop').find('[data-hst-file-name]').text(name);
    $(this).closest('.hst-file-drop').toggleClass('is-filled', !!(this.files && this.files.length));
  });

  HSTAssignments.init();
});