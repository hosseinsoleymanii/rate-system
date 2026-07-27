(function ($) {
  "use strict";

  const PAGE_SIZE = 10;

  const normalize = (value) =>
    String(value || "")
      .toLowerCase()
      .replace(/[ي]/g, "ی")
      .replace(/[ك]/g, "ک")
      .replace(/\s+/g, " ")
      .trim();

  const splitValues = (value) =>
    normalize(value)
      .split("|")
      .map((item) => item.trim())
      .filter(Boolean);

  function ensureEmptyRow($table, colSpan) {
    let $row = $table.find("tbody .hst-filter-empty-row");

    if (!$row.length) {
      $row = $(
        `<tr class="hst-filter-empty-row" hidden><td colspan="${colSpan}">موردی با این فیلترها پیدا نشد.</td></tr>`,
      );
      $table.find("tbody").append($row);
    }

    return $row;
  }

  function ensurePager($filters, $table) {
    const targetId = $filters.data("hst-filter-target");
    let $pager = $(`[data-hst-pagination-for="${targetId}"]`);

    if ($pager.length) {
      return $pager;
    }

    $pager = $(`
      <div class="hst-list-pagination" data-hst-pagination-for="${targetId}" hidden>
        <button type="button" class="hst-btn hst-page-prev">قبلی</button>
        <div class="hst-page-numbers" aria-live="polite"></div>
        <button type="button" class="hst-btn hst-page-next">بعدی</button>
      </div>
    `);

    const $wrap = $table.closest(".hst-table-wrap");

    if ($wrap.length) {
      $wrap.after($pager);
    } else {
      $table.after($pager);
    }

    return $pager;
  }

  function renderPager($pager, totalPages, currentPage) {
    const $numbers = $pager.find(".hst-page-numbers");

    if (totalPages <= 1) {
      $pager.prop("hidden", true);
      $numbers.empty();
      return;
    }

    const buttons = [];
    const radius = window.matchMedia("(max-width: 560px)").matches ? 1 : 2;
    const start = Math.max(1, currentPage - radius);
    const end = Math.min(totalPages, currentPage + radius);

    if (start > 1) {
      buttons.push(`<button type="button" class="hst-page-number" data-page="1">1</button>`);
      if (start > 2) {
        buttons.push(`<span class="hst-page-dots">…</span>`);
      }
    }

    for (let page = start; page <= end; page += 1) {
      buttons.push(
        `<button type="button" class="hst-page-number${page === currentPage ?" is-active" : ""}" data-page="${page}">${page}</button>`,
      );
    }

    if (end < totalPages) {
      if (end < totalPages - 1) {
        buttons.push(`<span class="hst-page-dots">…</span>`);
      }
      buttons.push(`<button type="button" class="hst-page-number" data-page="${totalPages}">${totalPages}</button>`);
    }

    $numbers.html(buttons.join(""));
    $pager.prop("hidden", false);
    $pager.find(".hst-page-prev").prop("disabled", currentPage <= 1);
    $pager.find(".hst-page-next").prop("disabled", currentPage >= totalPages);
  }

  function initFilterSet() {
    const $filters = $(this);
    const targetId = $filters.data("hst-filter-target");
    const $table = $(`#${targetId}`);

    if (!targetId || !$table.length) return;

    const $rows = $table.find("tbody tr").not(".hst-filter-empty-row");
    const colSpan = Math.max($table.find("thead th").length, 1);
    const $emptyRow = ensureEmptyRow($table, colSpan);
    const $count = $filters.find(".hst-filter-count");
    const $pager = ensurePager($filters, $table);

    let currentPage = 1;
    let matchedRows = $rows.toArray();

    function applyPage() {
      const total = matchedRows.length;
      const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));

      if (currentPage > totalPages) currentPage = totalPages;
      if (currentPage < 1) currentPage = 1;

      const start = (currentPage - 1) * PAGE_SIZE;
      const end = start + PAGE_SIZE;

      $rows.hide();
      $(matchedRows.slice(start, end)).show();

      $emptyRow.prop("hidden", total !== 0);
      renderPager($pager, totalPages, currentPage);

      if (total === 0) {
        $count.text(`۰ مورد از ${$rows.length} مورد`);
      } else {
        const shownStart = start + 1;
        const shownEnd = Math.min(end, total);
        $count.text(`نمایش ${shownStart} تا ${shownEnd} از ${total} مورد`);
      }
    }

    function applyFilters(resetPage = true) {
      const searchValue = normalize($filters.find('[data-hst-filter="search"]').val());
      const activeFilters = [];

      $filters.find("select[data-hst-filter]").each(function () {
        const key = $(this).data("hst-filter");
        const value = normalize($(this).val());

        if (key && value) {
          activeFilters.push({ key, value });
        }
      });

      matchedRows = $rows
        .filter(function () {
          const $row = $(this);
          const searchSource = normalize($row.data("hst-search") || $row.text());
          let isVisible = !searchValue || searchSource.includes(searchValue);

          if (isVisible) {
            activeFilters.forEach(({ key, value }) => {
              const rowValues = splitValues($row.data(`hst-${key}`));

              if (!rowValues.includes(value)) {
                isVisible = false;
              }
            });
          }

          return isVisible;
        })
        .toArray();

      if (resetPage) {
        currentPage = 1;
      }

      applyPage();
    }

    $filters.on("input change", "input, select", function () {
      applyFilters(true);
    });

    $filters.on("click", ".hst-filter-reset", function () {
      $filters.find("input").val("");
      $filters.find("select").prop("selectedIndex", 0);
      applyFilters(true);
    });

    $pager.on("click", ".hst-page-prev", function () {
      currentPage -= 1;
      applyPage();
    });

    $pager.on("click", ".hst-page-next", function () {
      currentPage += 1;
      applyPage();
    });

    $pager.on("click", ".hst-page-number", function () {
      currentPage = parseInt($(this).data("page"), 10) || 1;
      applyPage();
    });

    applyFilters(true);
  }

  $(function () {
    $(".hst-list-filters").each(initFilterSet);
  });
})(jQuery);