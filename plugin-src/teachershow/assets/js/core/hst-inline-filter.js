(function ($) {
  "use strict";

  var PAGE_SIZE = 10;
  var autoTableIndex = 0;
  var refreshTimers = {};

  function normalize(str) {
    return (str || "")
      .toString()
      .replace(/\u064a/g, "\u06cc")
      .replace(/\u0643/g, "\u06a9")
      .replace(/[\u200c\u200f\u200e]/g, "")
      .replace(/\s+/g, " ")
      .trim()
      .toLowerCase();
  }


  function segmentedFilterLabel($select) {
    var label = String($select.attr("aria-label") || "فیلتر وضعیت")
      .replace(/^فیلتر\s*/u, "")
      .trim();
    return (label || "وضعیت") + ":";
  }

  function syncSegmentedFilter($select, $segmented) {
    var value = String($select.val() == null ? "" : $select.val());
    $segmented.find("[data-hst-segment-value]").each(function () {
      var $button = $(this);
      var active = String($button.attr("data-hst-segment-value") || "") === value;
      $button.toggleClass("is-active", active).attr("aria-pressed", active ? "true" : "false");
    });
  }

  function initSegmentedFilters(scope) {
    var $scope = scope ? $(scope) : $(document);

    $scope.find(".hst-inline-filter__main").addBack(".hst-inline-filter__main").each(function () {
      var $main = $(this);
      if ($main.attr("data-hst-segmented-ready") === "1") return;

      var $searches = $main.find("[data-hst-inline-search]");
      var $selects = $main.find("select[data-hst-inline-select]");
      if ($searches.length !== 1 || $selects.length !== 1) return;

      var $select = $selects.first();
      var $options = $select.children("option");
      if ($options.length !== 3) return;

      var label = segmentedFilterLabel($select);
      var showLabel = String($select.attr("data-hst-segmented-label") || "").toLowerCase() !== "none"
        && !$select.closest(".hst-module--terms").length;
      var ariaLabel = String($select.attr("aria-label") || label.replace(/:$/, ""));
      var buttons = [];

      $options.each(function () {
        var $option = $(this);
        var value = String($option.val() == null ? "" : $option.val());
        var optionText = value === "" ? "همه" : $.trim($option.text());
        buttons.push(
          '<button type="button" class="hst-segmented-filter__option" data-hst-segment-value="' +
            HST.escapeHtml(value) + '" aria-pressed="false">' + HST.escapeHtml(optionText) + "</button>"
        );
      });

      var visibleLabel = showLabel
        ? '<span class="hst-segmented-filter__label">' + HST.escapeHtml(label) + '</span>'
        : '';
      var $segmented = $(
        '<div class="hst-segmented-filter">' +
          visibleLabel +
          '<div class="hst-segmented-filter__options" role="group" aria-label="' + HST.escapeHtml(ariaLabel) + '">' +
            buttons.join("") +
          "</div>" +
        "</div>"
      );

      $select.addClass("hst-visually-hidden").attr({ "aria-hidden": "true", tabindex: "-1" });
      $select.after($segmented);
      $main.attr("data-hst-segmented-ready", "1");
      syncSegmentedFilter($select, $segmented);

      $segmented.on("click", "[data-hst-segment-value]", function () {
        var value = String($(this).attr("data-hst-segment-value") || "");
        if (String($select.val() == null ? "" : $select.val()) === value) return;
        $select.val(value).trigger("change");
      });

      $select.on("change.hstSegmented", function () {
        syncSegmentedFilter($select, $segmented);
      });
    });
  }

  // Per table/filter state keyed by target id.
  var state = {};

  function getState(targetId) {
    if (!state[targetId]) state[targetId] = { page: 1, matched: null };
    return state[targetId];
  }

  function ensureTargetId($target) {
    var id = $target.attr("id");
    if (id) return id;

    do {
      autoTableIndex += 1;
      id = "hst-data-table-auto-" + autoTableIndex;
    } while ($("#" + id).length);

    $target.attr("id", id);
    return id;
  }

  function rowCollection($target) {
    var $rows;
    if ($target.is("table")) {
      $rows = $target.children("tbody").children("tr");
    } else if ($target.is("tbody")) {
      $rows = $target.children("tr");
    } else {
      $rows = $target.children("[data-hst-inline-item]");
      if (!$rows.length) {
        $rows = $target.find("tbody > tr").add($target.children("tr"));
      }
    }

    return $rows.not(".hst-filter-empty-row, .hst-table-empty-row, .hst-inline-filter-empty-row, [data-hst-no-pagination]");
  }

  function filterEmptyMessage($root, $target) {
    var $scope = $target.closest(".hst-card__body, .hst-section-card__body, .hst-card, .hst-section-card, .hst-page");
    var $empty = $scope.length ? $scope.find("[data-hst-inline-empty]").first() : $root.closest(".hst-page, body").find("[data-hst-inline-empty]").first();
    var text = $empty.length ? $.trim($empty.text()) : "";
    return text || "موردی با این فیلتر پیدا نشد.";
  }

  function ensureTableEmptyRow($root, $target, targetId) {
    var $tbody = $target.is("tbody") ? $target : ($target.is("table") ? $target.children("tbody").first() : $target.find("tbody").first());
    if (!$tbody.length) return $();

    var $row = $tbody.children('.hst-inline-filter-empty-row[data-hst-filter-empty-for="' + targetId + '"]');
    var $table = $tbody.closest("table");
    var colspan = Math.max(1, $table.find("thead th").length || $tbody.closest("tr").children("td,th").length || 1);

    if (!$row.length) {
      $row = $('<tr class="hst-table-empty-row hst-inline-filter-empty-row" data-hst-no-pagination hidden></tr>')
        .attr("data-hst-filter-empty-for", targetId)
        .append('<td class="hst-table-empty"></td>');
      $tbody.append($row);
    }

    $row.children("td").attr("colspan", colspan).text(filterEmptyMessage($root, $target));
    return $row;
  }

  function updateFilterEmptyState($root, $target, targetId, total) {
    var $scope = $target.closest(".hst-card__body, .hst-section-card__body, .hst-card, .hst-section-card, .hst-page");
    var $empty = $scope.length ? $scope.find("[data-hst-inline-empty]") : $root.closest(".hst-page, body").find("[data-hst-inline-empty]");
    if ($empty.length) $empty.prop("hidden", true);

    var $row = ensureTableEmptyRow($root, $target, targetId);
    if ($row.length) {
      $row.prop("hidden", total !== 0);
    } else if ($empty.length) {
      $empty.prop("hidden", total !== 0);
    }
  }

  function ensurePager($target, targetId) {
    var $pager = $('[data-hst-pagination-for="' + targetId + '"]');
    if ($pager.length) return $pager;

    $pager = $(
      '<div class="hst-list-pagination" data-hst-pagination-for="' + targetId + '" hidden>' +
        '<button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-page-prev">قبلی</button>' +
        '<div class="hst-page-numbers" aria-live="polite"></div>' +
        '<button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-page-next">بعدی</button>' +
      "</div>"
    );

    var $wrap = $target.closest(".hst-table-wrap");
    if ($wrap.length) {
      $wrap.after($pager);
    } else {
      $target.after($pager);
    }
    return $pager;
  }

  function renderPager($pager, totalPages, currentPage) {
    var $numbers = $pager.find(".hst-page-numbers");
    if (totalPages <= 1) {
      $pager.prop("hidden", true);
      $numbers.empty();
      return;
    }

    var buttons = [];
    var radius = window.matchMedia("(max-width: 560px)").matches ? 1 : 2;
    var start = Math.max(1, currentPage - radius);
    var end = Math.min(totalPages, currentPage + radius);

    if (start > 1) {
      buttons.push('<button type="button" class="hst-page-number" data-page="1">1</button>');
      if (start > 2) buttons.push('<span class="hst-page-dots">…</span>');
    }
    for (var p = start; p <= end; p++) {
      buttons.push(
        '<button type="button" class="hst-page-number' + (p === currentPage ? " is-active" : "") +
          '" data-page="' + p + '">' + p + "</button>"
      );
    }
    if (end < totalPages) {
      if (end < totalPages - 1) buttons.push('<span class="hst-page-dots">…</span>');
      buttons.push('<button type="button" class="hst-page-number" data-page="' + totalPages + '">' + totalPages + "</button>");
    }

    $numbers.html(buttons.join(""));
    $pager.prop("hidden", false);
    $pager.find(".hst-page-prev").prop("disabled", currentPage <= 1);
    $pager.find(".hst-page-next").prop("disabled", currentPage >= totalPages);
  }

  function targetUsesRowNumbers($target) {
    var $table = $target.is("table") ? $target : $target.closest("table");
    if (!$table.length) return false;

    var firstHeader = normalize($table.find("thead th").first().text());
    return firstHeader === "ردیف" || firstHeader.indexOf("ردیف") === 0;
  }

  function renumberMatchedRows($target, matchedRows) {
    if (!targetUsesRowNumbers($target)) return;

    $(matchedRows || []).each(function (index) {
      var $cell = $(this).children("td,th").first();
      if ($cell.length) {
        $cell.text(index + 1);
      }
    });
  }

  function visibleRowsForTarget($target, matchedRows, start, end) {
    var $allRows = rowCollection($target);
    $allRows.hide();
    $(matchedRows.slice(start, end)).show();
  }

  function applyPageByTargetId(targetId) {
    var $root = $('[data-hst-inline-filter="' + targetId + '"]');
    if ($root.length) {
      applyPage($root);
      return;
    }

    applyAutoPage(targetId);
  }

  function applyPage($root) {
    var targetId = $root.attr("data-hst-inline-filter");
    var $target = $("#" + targetId);
    if (!$target.length) return;

    var st = getState(targetId);
    if (!Array.isArray(st.matched)) {
      st.matched = rowCollection($target).toArray();
    }

    var total = st.matched.length;
    var $table = $target.is("table") ? $target : $target.closest("table");
    var noPagination = $table.length && $table.attr("data-hst-no-pagination") === "1";

    renumberMatchedRows($target, st.matched);

    if (noPagination) {
      rowCollection($target).hide();
      $(st.matched).show();
      $('[data-hst-pagination-for="' + targetId + '"]').remove();
      updateFilterEmptyState($root, $target, targetId, total);
      return;
    }

    var totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));

    if (st.page > totalPages) st.page = totalPages;
    if (st.page < 1) st.page = 1;

    var start = (st.page - 1) * PAGE_SIZE;
    var end = start + PAGE_SIZE;

    visibleRowsForTarget($target, st.matched, start, end);

    var $pager = ensurePager($target, targetId);
    renderPager($pager, totalPages, st.page);

    updateFilterEmptyState($root, $target, targetId, total);
  }

  function rowMatchesFilters($row, term, selectFilters) {
    if (String($row.attr("data-hst-inline-excluded") || "") === "1") return false;

    var hay = normalize($row.attr("data-hst-search"));
    if (!hay) hay = normalize($row.text());

    var show = term === "" || hay.indexOf(term) !== -1;

    if (show) {
      for (var i = 0; i < selectFilters.length; i++) {
        var rowVals = normalize($row.attr("data-hst-" + selectFilters[i].key))
          .split("|")
          .map(function (s) { return s.trim(); });

        if (rowVals.indexOf(selectFilters[i].val) === -1) {
          show = false;
          break;
        }
      }
    }

    return show;
  }

  function applyFilter($root, resetPage) {
    var targetId = $root.attr("data-hst-inline-filter");
    var $target = targetId ? $("#" + targetId) : $();
    if (!$target.length) return;

    var term = normalize($root.find("[data-hst-inline-search]").val());

    var selectFilters = [];
    $root.find("[data-hst-inline-select]").each(function () {
      var key = $(this).attr("data-hst-inline-select");
      var val = $(this).val();
      if (key && val !== "" && val != null) {
        selectFilters.push({ key: key, val: normalize(val) });
      }
    });

    var matched = [];
    rowCollection($target).each(function () {
      if (rowMatchesFilters($(this), term, selectFilters)) matched.push(this);
    });

    var st = getState(targetId);
    st.matched = matched;
    if (resetPage !== false) st.page = 1;

    applyPage($root);
  }

  function inlineFilterRootsForTable($table) {
    return $(".hst-inline-filter[data-hst-inline-filter]").filter(function () {
      var targetId = $(this).attr("data-hst-inline-filter");
      var $target = targetId ? $("#" + targetId) : $();

      if (!$target.length) return false;

      return $target.is($table) || $target.closest("table").is($table);
    });
  }

  function initAutoTable($table) {
    if (!$table.length || $table.attr("data-hst-no-pagination") === "1") return;

    var $filterRoots = inlineFilterRootsForTable($table);

    // If a table, tbody, or any table descendant is already managed by an
    // inline-filter root, do not create a second automatic pager for the table.
    // The inline-filter pagination owns that table.
    if ($filterRoots.length) {
      $filterRoots.each(function () {
        var $root = $(this);
        var targetId = $root.attr("data-hst-inline-filter");
        var $target = $("#" + targetId);

        applyFilter($root, false);
        observeTable($target.length ? $target : $table, targetId);
      });
      return;
    }

    var targetId = ensureTargetId($table);
    var st = getState(targetId);
    st.matched = rowCollection($table).toArray();
    if (!st.page) st.page = 1;

    applyAutoPage(targetId);
    observeTable($table, targetId);
  }

  function applyAutoPage(targetId) {
    var $target = $("#" + targetId);
    if (!$target.length) return;

    var st = getState(targetId);
    st.matched = rowCollection($target).toArray();

    var total = st.matched.length;
    var totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));

    if (st.page > totalPages) st.page = totalPages;
    if (st.page < 1) st.page = 1;

    var start = (st.page - 1) * PAGE_SIZE;
    var end = start + PAGE_SIZE;

    renumberMatchedRows($target, st.matched);
    visibleRowsForTarget($target, st.matched, start, end);

    var $pager = ensurePager($target, targetId);
    renderPager($pager, totalPages, st.page);
  }

  function refreshTableSoon(targetId) {
    clearTimeout(refreshTimers[targetId]);
    refreshTimers[targetId] = setTimeout(function () {
      var $root = $('[data-hst-inline-filter="' + targetId + '"]');
      if ($root.length) {
        applyFilter($root, false);
        return;
      }

      var $target = $("#" + targetId);
      var $table = $target.is("table") ? $target : $target.closest("table");
      var $owner = $table.length ? inlineFilterRootsForTable($table).first() : $();
      if ($owner.length) {
        applyFilter($owner, false);
        return;
      }

      applyAutoPage(targetId);
    }, 60);
  }

  function observeTable($target, targetId) {
    if (!$target.length || $target.data("hstPaginationObserved")) return;
    $target.data("hstPaginationObserved", true);

    var node = $target.is("tbody") ? $target.get(0) : $target.children("tbody").get(0);
    if (!node || typeof MutationObserver === "undefined") return;

    var observer = new MutationObserver(function (mutations) {
      var shouldRefresh = mutations.some(function (mutation) {
        return mutation.type === "childList";
      });
      if (shouldRefresh) refreshTableSoon(targetId);
    });

    observer.observe(node, { childList: true });
  }

  function initAllDataTables() {
    $("table.hst-data-table").each(function () {
      initAutoTable($(this));
    });
  }

  $(document).on("input", "[data-hst-inline-search]", function () {
    applyFilter($(this).closest(".hst-inline-filter"), true);
  });

  $(document).on("change", "[data-hst-inline-select]", function () {
    applyFilter($(this).closest(".hst-inline-filter"), true);
  });

  var hstAutoSubmitTimers = new WeakMap();

  $(document).on("change", "[data-hst-auto-submit-filter] select", function () {
    this.form && this.form.submit();
  });

  $(document).on("input", "[data-hst-auto-submit-filter] input[type='search'], [data-hst-auto-submit-filter] input[type='text']", function () {
    var form = this.form;
    if (!form) return;

    clearTimeout(hstAutoSubmitTimers.get(form));
    hstAutoSubmitTimers.set(form, setTimeout(function () {
      form.submit();
    }, 450));
  });

  $(document).on("keydown", "[data-hst-inline-search]", function (e) {
    if (e.key === "Enter") {
      e.preventDefault();
      applyFilter($(this).closest(".hst-inline-filter"), true);
    }
  });

  // Pagination controls (delegated).
  $(document).on("click", ".hst-list-pagination .hst-page-prev", function () {
    var targetId = pagerTargetId($(this));
    if (!targetId) return;
    getState(targetId).page -= 1;
    applyPageByTargetId(targetId);
  });
  $(document).on("click", ".hst-list-pagination .hst-page-next", function () {
    var targetId = pagerTargetId($(this));
    if (!targetId) return;
    getState(targetId).page += 1;
    applyPageByTargetId(targetId);
  });
  $(document).on("click", ".hst-list-pagination .hst-page-number", function () {
    var targetId = pagerTargetId($(this));
    if (!targetId) return;
    getState(targetId).page = parseInt($(this).data("page"), 10) || 1;
    applyPageByTargetId(targetId);
  });

  function pagerTargetId($el) {
    return $el.closest(".hst-list-pagination").attr("data-hst-pagination-for") || "";
  }

  // Generic "back" button.
  $(document).on("click", "[data-hst-back]", function (e) {
    e.preventDefault();
    var fallback = $(this).attr("data-hst-fallback") || "/dashboard";
    var sameOriginReferrer =
      document.referrer && document.referrer.indexOf(window.location.origin) === 0;
    if (window.history.length > 1 && sameOriginReferrer) {
      window.history.back();
    } else {
      window.location.href = fallback;
    }
  });

  // Public hook for pages that rebuild tables manually.
  window.HST = window.HST || {};
  window.HST.refreshTables = initAllDataTables;
  window.HST.refreshInlineFilter = function (targetId) {
    var id = String(targetId || "").replace(/^#/, "");
    var $root = $('[data-hst-inline-filter="' + id + '"]');
    if ($root.length) applyFilter($root, true);
  };
  window.HST.refreshTablePagination = function (target) {
    var $target = $(target);
    if ($target.is("table.hst-data-table")) {
      initAutoTable($target);
      return;
    }
    $target.find("table.hst-data-table").each(function () {
      initAutoTable($(this));
    });
  };

  // Initialise pagination for every inline filter and every hst-data-table.
  $(function () {
    initSegmentedFilters(document);

    $(".hst-inline-filter[data-hst-inline-filter]").each(function () {
      applyFilter($(this), true);
    });

    initAllDataTables();
  });
})(jQuery);
