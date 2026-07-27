(function ($) {
  'use strict';

  const MONTHS = [
    'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
    'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'
  ];
  const WEEKDAYS = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];


  function syncThemeVars(input, target) {
    if (!target) return;
    const source = ($(input).closest('.hst-shell')[0] || document.body || document.documentElement);
    const styles = window.getComputedStyle(source);
    [
      '--hst-accent', '--hst-accent-rgb', '--hst-accent-hover', '--hst-accent-press',
      '--hst-accent-soft', '--hst-accent-softer', '--hst-on-accent', '--hst-ring',
      '--hst-bg', '--hst-surface', '--hst-surface-2', '--hst-surface-3',
      '--hst-ink', '--hst-ink-2', '--hst-muted', '--hst-border', '--hst-border-strong'
    ].forEach(function (name) {
      const value = styles.getPropertyValue(name);
      if (value) target.style.setProperty(name, value.trim());
    });
  }

  function toEnglishDigits(value) {
    return String(value || '').replace(/[۰-۹٠-٩]/g, function (d) {
      return '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩'.indexOf(d) % 10;
    });
  }

  function toPersianDigits(value) {
    return String(value || '').replace(/\d/g, function (d) {
      return '۰۱۲۳۴۵۶۷۸۹'[d];
    });
  }

  function pad(value) {
    return String(value).padStart(2, '0');
  }

  function gregorianToJalali(gy, gm, gd) {
    const gdm = [0,31,59,90,120,151,181,212,243,273,304,334];
    gy = Number(gy); gm = Number(gm); gd = Number(gd);
    const gy2 = gm > 2 ? gy + 1 : gy;
    let days = 355666 + (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) + gd + gdm[gm - 1];
    let jy = -1595 + (33 * Math.floor(days / 12053));
    days %= 12053;
    jy += 4 * Math.floor(days / 1461);
    days %= 1461;
    if (days > 365) {
      jy += Math.floor((days - 1) / 365);
      days = (days - 1) % 365;
    }
    let jm, jd;
    if (days < 186) {
      jm = 1 + Math.floor(days / 31);
      jd = 1 + (days % 31);
    } else {
      jm = 7 + Math.floor((days - 186) / 30);
      jd = 1 + ((days - 186) % 30);
    }
    return [jy, jm, jd];
  }

  function jalaliToGregorian(jy, jm, jd) {
    jy = Number(jy) + 1595; jm = Number(jm); jd = Number(jd);
    let days = -355668 + (365 * jy) + (Math.floor(jy / 33) * 8) + Math.floor(((jy % 33) + 3) / 4) + jd;
    days += jm < 7 ? (jm - 1) * 31 : ((jm - 7) * 30) + 186;
    let gy = 400 * Math.floor(days / 146097);
    days %= 146097;
    if (days > 36524) {
      gy += 100 * Math.floor(--days / 36524);
      days %= 36524;
      if (days >= 365) days++;
    }
    gy += 4 * Math.floor(days / 1461);
    days %= 1461;
    if (days > 365) {
      gy += Math.floor((days - 1) / 365);
      days = (days - 1) % 365;
    }
    let gd = days + 1;
    const leap = (gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0);
    const salA = [0,31,leap ? 29 : 28,31,30,31,30,31,31,30,31,30,31];
    let gm;
    for (gm = 1; gm <= 12 && gd > salA[gm]; gm++) {
      gd -= salA[gm];
    }
    return [gy, gm, gd];
  }

  function isJalaliLeap(jy) {
    const breaks = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];
    let bl = breaks.length, gy = jy + 621, leapJ = -14, jp = breaks[0], jm, jump, leap, n, i;
    if (jy < jp || jy >= breaks[bl - 1]) return false;
    for (i = 1; i < bl; i += 1) {
      jm = breaks[i];
      jump = jm - jp;
      if (jy < jm) break;
      leapJ += Math.floor(jump / 33) * 8 + Math.floor((jump % 33) / 4);
      jp = jm;
    }
    n = jy - jp;
    leapJ += Math.floor(n / 33) * 8 + Math.floor(((n % 33) + 3) / 4);
    if ((jump % 33) === 4 && jump - n === 4) leapJ += 1;
    const leapG = Math.floor(gy / 4) - Math.floor((Math.floor(gy / 100) + 1) * 3 / 4) - 150;
    const march = 20 + leapJ - leapG;
    if (jump - n < 6) n = n - jump + Math.floor((jump + 4) / 33) * 33;
    leap = (((n + 1) % 33) - 1) % 4;
    if (leap === -1) leap = 4;
    return march && leap === 0;
  }

  function monthLength(jy, jm) {
    if (jm <= 6) return 31;
    if (jm <= 11) return 30;
    return isJalaliLeap(jy) ? 30 : 29;
  }

  function parseInput(value) {
    const clean = toEnglishDigits(value).trim().replace(/\./g, '/').replace(/-/g, '/');
    const match = clean.match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})(?:\s+(\d{1,2}):(\d{2}))?/);
    if (!match) return null;
    return {
      year: Number(match[1]),
      month: Number(match[2]),
      day: Number(match[3]),
      hour: match[4] !== undefined ? Number(match[4]) : 8,
      minute: match[5] !== undefined ? Number(match[5]) : 0
    };
  }

  function todayJalali() {
    const now = new Date();
    const j = gregorianToJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
    return { year: j[0], month: j[1], day: j[2], hour: now.getHours(), minute: now.getMinutes() };
  }

  function dateKey(value) {
    if (!value) return 0;
    return (Number(value.year) * 10000) + (Number(value.month) * 100) + Number(value.day);
  }

  function firstWeekdayIndex(jy, jm) {
    const g = jalaliToGregorian(jy, jm, 1);
    const date = new Date(g[0], g[1] - 1, g[2]);
    return (date.getDay() + 1) % 7; // Saturday = 0
  }

  const timePicker = {
    $modal: null,
    target: null,
    returnFocus: null,
    restoreFocus: true,
    combineDate: false,
    selectedHour: 0,
    selectedMinute: 0,

    init() {
      if (this.$modal) return;

      this.$modal = $(`
        <div class="hst-modal hst-time-picker-modal" data-hst-time-picker-modal data-hst-modal-size="sm" role="dialog" aria-modal="true" aria-label="انتخاب ساعت" aria-hidden="true">
          <div class="hst-modal__backdrop" data-hst-time-picker-close></div>
          <div class="hst-modal__panel">
            <div class="hst-modal__header">
              <h3 data-hst-time-picker-title>انتخاب ساعت</h3>
              <button type="button" class="hst-modal__close" data-hst-time-picker-close aria-label="بستن">×</button>
            </div>
            <div class="hst-modal__body">
              <div class="hst-time-picker">
                <div class="hst-time-picker__column">
                  <span>ساعت</span>
                  <div class="hst-time-picker__list" data-hst-time-hours></div>
                </div>
                <div class="hst-time-picker__column">
                  <span>دقیقه</span>
                  <div class="hst-time-picker__list" data-hst-time-minutes></div>
                </div>
              </div>
            </div>
            <div class="hst-modal__footer">
              <button type="button" class="hst-btn hst-btn--soft" data-hst-time-picker-close>بستن</button>
              <button type="button" class="hst-btn" data-hst-time-picker-apply>تأیید</button>
            </div>
          </div>
        </div>
      `).appendTo('body');

      this.$modal.on('click.hstTimePicker', '[data-hst-time-hour]', (event) => {
        this.selectedHour = Number($(event.currentTarget).attr('data-hst-time-hour'));
        this.render();
      });
      this.$modal.on('click.hstTimePicker', '[data-hst-time-minute]', (event) => {
        this.selectedMinute = Number($(event.currentTarget).attr('data-hst-time-minute'));
        this.render();
      });
      this.$modal.on('click.hstTimePicker', '[data-hst-time-picker-close]', () => this.close());
      this.$modal.on('click.hstTimePicker', '[data-hst-time-picker-apply]', () => this.apply());

      $(document).on('keydown.hstTimePicker', (event) => {
        if (event.key === 'Escape' && this.$modal && this.$modal.hasClass('is-active')) {
          this.close();
        }
      });
    },

    open(target, options) {
      if (!target) return;
      this.init();

      const settings = options || {};
      const now = new Date();
      const title = String(settings.title || $(target).attr('data-hst-time-title') || 'انتخاب ساعت');

      this.target = target;
      this.returnFocus = settings.trigger || target;
      this.restoreFocus = settings.restoreFocus !== false;
      this.combineDate = settings.combineDate === true;
      this.selectedHour = now.getHours();
      this.selectedMinute = now.getMinutes();

      syncThemeVars(target, this.$modal[0]);
      this.$modal.attr('aria-label', title);
      this.$modal.find('[data-hst-time-picker-title]').text(title);
      this.render();
      this.$modal.addClass('is-active').attr('aria-hidden', 'false');

      window.requestAnimationFrame(() => {
        const activeHour = this.$modal.find('[data-hst-time-hour][aria-current="true"]').get(0);
        if (activeHour && typeof activeHour.focus === 'function') {
          activeHour.focus({ preventScroll: true });
        }
      });
    },

    render() {
      if (!this.$modal) return;
      const hourHtml = [];
      const minuteHtml = [];

      for (let hour = 0; hour < 24; hour += 1) {
        const active = hour === this.selectedHour;
        hourHtml.push(
          `<button type="button" class="hst-btn hst-btn--sm${active ? '' : ' hst-btn--ghost'}" data-hst-time-hour="${hour}"${active ? ' aria-current="true"' : ''}>${toPersianDigits(pad(hour))}</button>`
        );
      }

      for (let minute = 0; minute < 60; minute += 1) {
        const active = minute === this.selectedMinute;
        minuteHtml.push(
          `<button type="button" class="hst-btn hst-btn--sm${active ? '' : ' hst-btn--ghost'}" data-hst-time-minute="${minute}"${active ? ' aria-current="true"' : ''}>${toPersianDigits(pad(minute))}</button>`
        );
      }

      this.$modal.find('[data-hst-time-hours]').html(hourHtml.join(''));
      this.$modal.find('[data-hst-time-minutes]').html(minuteHtml.join(''));

      window.requestAnimationFrame(() => {
        this.$modal.find('.hst-time-picker__list').each(function () {
          const active = this.querySelector('[aria-current="true"]');
          if (!active) return;
          const targetTop = active.offsetTop - (this.clientHeight - active.offsetHeight) / 2;
          this.scrollTop = Math.max(0, targetTop);
        });
      });
    },

    apply() {
      if (!this.target) return;

      const timeValue = `${pad(this.selectedHour)}:${pad(this.selectedMinute)}`;
      let value = timeValue;
      if (this.combineDate) {
        const current = toEnglishDigits($(this.target).val()).trim();
        const datePart = current.split(/\s+/)[0] || '';
        value = datePart ? `${datePart} ${timeValue}` : timeValue;
        value = toPersianDigits(value);
      }

      $(this.target).val(value).trigger('input').trigger('change');
      this.close();
    },

    close() {
      if (!this.$modal) return;
      const focusTarget = this.returnFocus;
      const shouldRestoreFocus = this.restoreFocus;

      this.$modal.removeClass('is-active').attr('aria-hidden', 'true');
      this.target = null;
      this.returnFocus = null;

      if (shouldRestoreFocus && focusTarget && typeof focusTarget.focus === 'function') {
        try {
          focusTarget.focus({ preventScroll: true });
        } catch (error) {
          focusTarget.focus();
        }
      }
    }
  };

  const picker = {
    $box: null,
    $overlay: null,
    $input: null,
    state: todayJalali(),
    withTime: false,
    minDate: null,

    init() {
      if (this.$box) return;
      this.$overlay = $('<div class="hst-modal" data-hst-jdp-modal data-hst-modal-size="sm" role="dialog" aria-modal="true" aria-label="تقویم شمسی" aria-hidden="true"></div>').appendTo('body');
      $('<div class="hst-modal__backdrop" data-hst-jdp-close></div>').appendTo(this.$overlay);
      this.$box = $('<div class="hst-modal__panel hst-jdp"></div>').appendTo(this.$overlay);
      this.$overlay.on('mousedown.hstJdp', '[data-hst-jdp-close]', () => this.hide());
      $(document).on('mousedown.hstJdp', (e) => {
        if (!this.$overlay || !this.$overlay.is(':visible')) return;
        if ($(e.target).closest('.hst-jdp, .hst-jalali-date, .hst-jalali-datetime').length) return;
        this.hide();
      });
      $(document).on('keydown.hstJdp', (e) => {
        if (e.key === 'Escape') this.hide();
      });
      $(window).on('resize.hstJdp scroll.hstJdp', () => {
        if (this.$box.is(':visible')) this.position();
      });
    },

    show(input) {
      this.init();
      this.$input = $(input);
      syncThemeVars(input, this.$overlay[0]);
      this.withTime = this.$input.hasClass('hst-jalali-datetime');
      this.birthMode = this.$input.hasClass('hst-jalali-birthdate');
      const minDateAttr = String(this.$input.attr('data-hst-min-date') || '').trim();
      this.minDate = minDateAttr === 'today' ? todayJalali() : parseInput(minDateAttr);
      // Optional weekday constraint: data-hst-allowed-weekdays="0,2,4"
      // (Saturday=0 … Wednesday=4). Empty/absent = all days allowed.
      var allowedAttr = this.$input.attr('data-hst-allowed-weekdays');
      if (allowedAttr !== undefined && allowedAttr !== null && String(allowedAttr).trim() !== '') {
        this.allowedWeekdays = String(allowedAttr)
          .split(',')
          .map(function (n) { return parseInt(n, 10); })
          .filter(function (n) { return !isNaN(n); });
      } else {
        this.allowedWeekdays = null;
      }
      this.state = parseInput(this.$input.val()) || todayJalali();
      if (this.birthMode && !parseInput(this.$input.val())) {
        // Default to a typical student birth year (≈12 years ago), month 1.
        this.state = { year: todayJalali().year - 12, month: 1, day: 1, hour: 8, minute: 0 };
      }
      if (!this.withTime) {
        this.state.hour = 8;
        this.state.minute = 0;
      }
      this.render();
      this.position();
      $('body').addClass('hst-jdp-modal-open');
      this.$overlay.addClass('is-open').attr('aria-hidden', 'false');
      this.$box.show();
      this.$box.find('button, input').filter(':visible').first().trigger('focus');
    },

    hide() {
      if (this.$box) this.$box.hide();
      if (this.$overlay) this.$overlay.removeClass('is-open').attr('aria-hidden', 'true');
      $('body').removeClass('hst-jdp-modal-open');
    },

    position() {
      if (!this.$box || !this.$box.length) return;
      this.$box.css({ top: '', left: '' });
    },

    changeMonth(delta) {
      this.state.month += delta;
      if (this.state.month < 1) { this.state.month = 12; this.state.year -= 1; }
      if (this.state.month > 12) { this.state.month = 1; this.state.year += 1; }
      this.state.day = Math.min(this.state.day, monthLength(this.state.year, this.state.month));
      this.render();
      this.position();
    },

    setToday() {
      this.state = todayJalali();
      if (!this.withTime) {
        this.state.hour = 8;
        this.state.minute = 0;
      }
      this.commit();
    },

    selectDay(day) {
      this.state.day = day;
      this.commit();
    },

    commit() {
      if (!this.$input) return;
      if (this.minDate && dateKey(this.state) < dateKey(this.minDate)) {
        return;
      }
      const y = this.state.year;
      const m = pad(this.state.month);
      const d = pad(this.state.day);
      const value = `${y}/${m}/${d}`;
      const target = this.$input.get(0);
      this.$input.val(toPersianDigits(value)).trigger('change');
      this.hide();

      if (this.withTime && target) {
        window.setTimeout(function () {
          timePicker.open(target, {
            combineDate: true,
            title: $(target).attr('data-hst-time-title') || 'انتخاب ساعت',
            restoreFocus: false
          });
        }, 0);
      }
    },

    render() {
      const daysInMonth = monthLength(this.state.year, this.state.month);
      const firstIndex = firstWeekdayIndex(this.state.year, this.state.month);
      const today = todayJalali();
      const selected = parseInput(this.$input ? this.$input.val() : '') || this.state;
      let cells = '';
      for (let i = 0; i < firstIndex; i++) cells += '<button type="button" class="hst-jdp-day is-empty" tabindex="-1"></button>';
      for (let day = 1; day <= daysInMonth; day++) {
        const isToday = today.year === this.state.year && today.month === this.state.month && today.day === day;
        const isSelected = selected.year === this.state.year && selected.month === this.state.month && selected.day === day;
        const weekday = (firstIndex + day - 1) % 7;
        const candidate = { year: this.state.year, month: this.state.month, day: day };
        const isBeforeMinimum = this.minDate && dateKey(candidate) < dateKey(this.minDate);
        const isDisabled = isBeforeMinimum || (this.allowedWeekdays && this.allowedWeekdays.indexOf(weekday) === -1);
        cells += `<button type="button" class="hst-jdp-day${isToday ? ' is-today' : ''}${isSelected ? ' is-selected' : ''}${isDisabled ? ' is-disabled' : ''}" data-day="${day}"${isDisabled ? ' disabled aria-disabled="true"' : ''}>${toPersianDigits(day)}</button>`;
      }

      const todayYear = todayJalali().year;
      let yearOpts = '';
      const startYear = this.birthMode ? todayYear : Math.max(todayYear + 10, this.state.year + 10);
      const endYear = this.birthMode ? (todayYear - 60) : Math.min(todayYear - 30, this.state.year - 30);
      for (let y = startYear; y >= endYear; y--) {
        yearOpts += `<option value="${y}"${y === this.state.year ? ' selected' : ''}>${toPersianDigits(y)}</option>`;
      }
      if (!yearOpts.includes(`value="${this.state.year}"`)) {
        yearOpts += `<option value="${this.state.year}" selected>${toPersianDigits(this.state.year)}</option>`;
      }
      let monthOpts = '';
      for (let m = 1; m <= 12; m++) {
        monthOpts += `<option value="${m}"${m === this.state.month ? ' selected' : ''}>${MONTHS[m - 1]}</option>`;
      }
      const headClass = this.birthMode ? 'hst-jdp-head hst-jdp-head--selects hst-jdp-head--birth' : 'hst-jdp-head hst-jdp-head--selects';
      const headHtml = `
        <div class="${headClass}">
          <select class="hst-jdp-sel-month" aria-label="ماه">${monthOpts}</select>
          <select class="hst-jdp-sel-year" aria-label="سال">${yearOpts}</select>
        </div>`;

      this.$box.html(`
        <div class="hst-modal__header">
          <h3>${this.birthMode ? 'انتخاب تاریخ تولد' : 'انتخاب تاریخ'}</h3>
          <button type="button" class="hst-modal__close" data-hst-jdp-close aria-label="بستن">×</button>
        </div>
        <div class="hst-modal__body">
        ${headHtml}
        <div class="hst-jdp-weekdays">${WEEKDAYS.map((d) => `<span>${d}</span>`).join('')}</div>
        <div class="hst-jdp-days">${cells}</div>
        </div>
        <div class="hst-modal__footer">
          ${this.birthMode ? '' : '<button type="button" class="hst-btn hst-jdp-today">امروز</button>'}
          <button type="button" class="hst-btn hst-btn--soft" data-hst-jdp-close>بستن</button>
        </div>
      `);

      this.$box.find('[data-hst-jdp-close]').on('click', () => this.hide());
      this.$box.find('.hst-jdp-sel-year').on('change', (e) => { this.state.year = Number(e.target.value); this.state.day = Math.min(this.state.day, monthLength(this.state.year, this.state.month)); this.render(); });
      this.$box.find('.hst-jdp-sel-month').on('change', (e) => { this.state.month = Number(e.target.value); this.state.day = Math.min(this.state.day, monthLength(this.state.year, this.state.month)); this.render(); });
      this.$box.find('.hst-jdp-day:not(.is-empty):not(.is-disabled)').on('click', (e) => this.selectDay(Number($(e.currentTarget).data('day'))));
      this.$box.find('.hst-jdp-today').on('click', () => this.setToday());
    }
  };

  function initDatepickers(context) {
    const $fields = $('.hst-jalali-date, .hst-jalali-datetime, .hst-jalali-birthdate', context || document);
    $fields.each(function () {
      const $field = $(this);
      if ($field.data('hst-jdp-ready')) return;
      $field.data('hst-jdp-ready', true)
        .attr('autocomplete', 'off')
        .attr('dir', 'ltr')
        .addClass('hst-jdp-input')
        .on('focus click', function () { picker.show(this); });
    });
  }

  $(function () {
    initDatepickers(document);
    $(document).on('click.hstTimePickerTrigger', '[data-hst-time-target]', function () {
      const targetName = String($(this).attr('data-hst-time-target') || '');
      const $scope = $(this).closest('form');
      const input = ($scope.length ? $scope : $(document))
        .find('[name]')
        .filter(function () { return String(this.name || '') === targetName; })
        .get(0);
      if (input) {
        timePicker.open(input, {
          trigger: this,
          title: $(this).attr('title') || $(input).attr('data-hst-time-title') || 'انتخاب ساعت'
        });
      }
    });
    $(document).on('click.hstTimePickerInput', '.hst-time-input', function () {
      timePicker.open(this, {
        trigger: this,
        title: $(this).attr('data-hst-time-title') || 'انتخاب ساعت'
      });
    });

    window.HSTTimePicker = {
      open: function (target, options) { timePicker.open(target, options); },
      close: function () { timePicker.close(); }
    };
    window.HSTJalaliDatepicker = {
      init: initDatepickers,
      toPersianDigits,
      toEnglishDigits,
      parse: parseInput,
      today: todayJalali
    };
  });
})(jQuery);
