/**
 * HSTPrint — TeacherShow's reusable print / PDF-export component.
 * ---------------------------------------------------------------------------
 * Modelled on the Polonayze print/PDF system (jsPDF + embedded Vazir font +
 * Persian shaper), generalised into a single component so any feature in the
 * plugin can print a clean document or export a PDF without re-implementing
 * the plumbing.
 *
 * Public API (window.HSTPrint):
 *
 *   HSTPrint.printHtml(html, { title })
 *       Print a ready-made HTML document (or fragment) via a hidden iframe.
 *       If `html` is a full document (starts with <!doctype/<html) it is used
 *       as-is; otherwise it is wrapped in a styled, RTL print document.
 *
 *   HSTPrint.printDocument({ title, subtitle, bodyHtml })
 *       Build + print a standard TeacherShow document (school header, title,
 *       date) wrapping the given bodyHtml. Returns nothing.
 *
 *   HSTPrint.tablePdf({ title, subtitle, head, rows, filename, orientation })
 *       Export a data table to PDF using jsPDF. Persian text is shaped and
 *       rendered with the embedded Vazir font. `head` is an array of column
 *       labels; `rows` is an array of row arrays. Falls back gracefully if
 *       jsPDF is unavailable (prints an HTML table instead).
 *
 *   HSTPrint.isPdfAvailable()
 *       Whether the jsPDF library + font are loaded (so callers can decide
 *       whether to offer a real PDF or fall back to browser print).
 *
 * Theming, school header text and logo come from window.hstPrintConfig
 * (localised by PHP). Everything degrades sensibly if that object is absent.
 */
(function () {
  "use strict";

  function cfg() {
    return window.hstPrintConfig || {};
  }

  function escapeHtml(str) {
    return String(str == null ? "" : str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function initialsOf(name, firstName, lastName, provided) {
    var ready = String(provided || "").trim();
    if (ready) {
      return ready.split(/\s+/u).filter(Boolean).join("\u00A0") || "؟";
    }
    if (window.HST && typeof window.HST.initials === "function") {
      return window.HST.initials(name || "", firstName || "", lastName || "");
    }
    function firstChar(value) {
      return Array.from(String(value || "").trim())[0] || "";
    }
    var parts = String(name || "").trim().split(/\s+/u).filter(Boolean);
    var first = firstChar(firstName) || firstChar(parts[0] || "");
    var last = firstChar(lastName) || (parts.length > 1 ? firstChar(parts[parts.length - 1]) : "");
    return [first, last].filter(Boolean).join("\u00A0") || "؟";
  }

  function todayStr() {
    var c = cfg();
    if (c.today) return c.today; // PHP can pass a Jalali date.
    try {
      return new Date().toLocaleDateString("fa-IR");
    } catch (e) {
      return "";
    }
  }

  // ---- Shared print stylesheet (RTL, A4/A5, Vazir web-font) --------------
  function fontFaceCss() {
    var c = cfg();
    if (c.fontUrl) {
      return (
        "@font-face{font-family:'Vazirmatn';" +
        "src:url('" + c.fontUrl + "') format('woff2');" +
        "font-weight:normal;font-style:normal;font-display:swap;}"
      );
    }
    return "";
  }

  function isFullDoc(html) {
    return /^\s*<(?:!doctype|html)/i.test(String(html || ""));
  }

  // ---- Build a complete, standalone print document ----------------------
  function buildPrintDocument(opts) {
    opts = opts || {};
    var title = escapeHtml(opts.title || "");
    var subtitle = escapeHtml(opts.subtitle || "");
    var body = opts.bodyHtml || "";
    var c = cfg();
    var accent = c.accent || "#334155";
    var school = escapeHtml(c.schoolName || "");
    var logo = c.logoUrl
      ? '<img class="hst-print-logo" src="' + escapeHtml(c.logoUrl) + '" alt="">'
      : "";
    var orientation = (c.orientation === "P" ? "portrait" : "landscape");
    var paper = c.paper || "A4";

    return (
      '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8">' +
      '<meta name="viewport" content="width=device-width, initial-scale=1">' +
      "<title>" + (title || "چاپ") + "</title><style>" +
      fontFaceCss() +
      "@page{size:" + paper + " " + orientation + ";margin:10mm;}" +
      ":root{--hst-print-accent:" + accent + ";--hst-print-ink:#1b2733;--hst-print-muted:#64748b;--hst-print-faint:#94a3b8;--hst-print-border:#cbd5e1;--hst-print-row:#f6f8fa;--hst-print-surface:#fff;}" +
      "*{box-sizing:border-box;}" +
      "html,body{margin:0;padding:0;font-family:'Vazirmatn',Tahoma,Arial,sans-serif;" +
      "direction:rtl;color:var(--hst-print-ink);-webkit-print-color-adjust:exact;print-color-adjust:exact;}" +
      "body{padding:14px;}" +
      ".hst-print-hdr{width:100%;border-bottom:2px solid var(--hst-print-accent);padding-bottom:6px;margin-bottom:12px;" +
      "display:flex;align-items:center;justify-content:space-between;gap:12px;}" +
      ".hst-print-hdr .school{font-size:13pt;font-weight:bold;color:var(--hst-print-accent);}" +
      ".hst-print-hdr .meta{font-size:9pt;color:var(--hst-print-muted);}" +
      ".hst-print-logo{max-height:54px;max-width:160px;object-fit:contain;}" +
      ".hst-print-title{font-size:13pt;font-weight:bold;text-align:center;margin:4px 0;}" +
      ".hst-print-sub{font-size:9pt;color:var(--hst-print-muted);text-align:center;margin-bottom:10px;}" +
      "table{width:100%;border-collapse:collapse;font-size:9pt;}" +
      "th,td{border:0.5pt solid var(--hst-print-border);padding:6px 5px;text-align:center;}" +
      "thead th{background:var(--hst-print-accent);color:var(--hst-print-surface);font-size:9.5pt;}" +
      "tbody tr:nth-child(even){background:var(--hst-print-row);}" +
      ".hst-print-foot{margin-top:12px;font-size:7.5pt;color:var(--hst-print-faint);text-align:center;}" +
      "@media print{body{padding:0;}}" +
      "</style></head><body>" +
      '<div class="hst-print-hdr">' +
      '<div><span class="school">' + school + "</span>" +
      (school ? "<br>" : "") +
      '</div>' +
      "<div>" + logo + "</div>" +
      "</div>" +
      (title ? '<div class="hst-print-title">' + title + "</div>" : "") +
      (subtitle ? '<div class="hst-print-sub">' + subtitle + "</div>" : "") +
      body +
      "<script>(function(){function p(){window.focus();window.print();}" +
      "if(document.fonts&&document.fonts.ready){document.fonts.ready.then(p);}" +
      "else{window.addEventListener('load',p);}})();<\/script>" +
      "</body></html>"
    );
  }

  // ---- Print via a hidden iframe (no popup, no extra tab) ----------------
  function printViaIframe(html) {
    var old = document.getElementById("hst-print-frame");
    if (old && old.parentNode) old.parentNode.removeChild(old);

    var iframe = document.createElement("iframe");
    iframe.id = "hst-print-frame";
    iframe.className = "hst-print-frame";
    iframe.setAttribute("aria-hidden", "true");
    iframe.tabIndex = -1;
    document.body.appendChild(iframe);

    var doc = iframe.contentWindow.document;
    doc.open();
    doc.write(html);
    doc.close();

    // The document carries its own auto-print script. We only clean up here.
    var cleanup = function () {
      window.setTimeout(function () {
        var f = document.getElementById("hst-print-frame");
        if (f && f.parentNode) f.parentNode.removeChild(f);
      }, 1000);
    };
    try {
      iframe.contentWindow.onafterprint = cleanup;
    } catch (e) {}
    window.setTimeout(cleanup, 60000);
  }

  // ---- jsPDF helpers (Persian-aware) ------------------------------------
  function jsPDFCtor() {
    return (window.jspdf && window.jspdf.jsPDF) || window.jsPDF || null;
  }

  function isPdfAvailable() {
    return !!jsPDFCtor() && typeof window.PN_VAZIR_FONT_B64 !== "undefined";
  }

  function ensureVazir(doc) {
    if (typeof window.PN_VAZIR_FONT_B64 === "undefined") return false;
    try {
      doc.addFileToVFS("Vazirmatn-Regular.ttf", window.PN_VAZIR_FONT_B64);
      doc.addFont("Vazirmatn-Regular.ttf", "Vazirmatn", "normal");
      return true;
    } catch (e) {
      return false;
    }
  }

  // Shape Persian/Arabic text into presentation forms (jsPDF does no shaping).
  function shapeFa(value) {
    var str = String(value == null ? "" : value);
    if (!/[\u0600-\u06FF]/.test(str)) return str;
    // Normalise punctuation that breaks in RTL: Latin comma -> Arabic comma,
    // and swap mirrored brackets so they read correctly once shaped.
    str = str
      .replace(/,/g, "\u060C")
      .replace(/[()]/g, function (ch) { return ch === "(" ? ")" : "("; })
      .replace(/[\[\]]/g, function (ch) { return ch === "[" ? "]" : "["; });
    try {
      if (window.PnPersianShaper && typeof window.PnPersianShaper.convertArabic === "function") {
        return window.PnPersianShaper.convertArabic(str);
      }
    } catch (e) {}
    return str;
  }

  function shapeCells(arr) {
    return (arr || []).map(shapeFa);
  }

  // Exam papers are laid out manually from right to left. Unlike some older
  // table exports, their text must keep brackets in their natural order;
  // mirroring them before drawing produces broken strings such as
  // ")گزینه ۱ (صحیح". Keep this helper local to exam documents so existing
  // report exports retain their established rendering behaviour.
  function shapeFaExam(value) {
    var str = String(value == null ? "" : value);
    if (!/[\u0600-\u06FF]/.test(str)) return str;
    str = str.replace(/,/g, "\u060C");
    try {
      if (window.PnPersianShaper && typeof window.PnPersianShaper.convertArabic === "function") {
        return window.PnPersianShaper.convertArabic(str);
      }
    } catch (e) {}
    return str;
  }

  function hexToRgb(hex) {
    hex = (hex || "#334155").replace("#", "");
    if (hex.length === 3) {
      hex = hex.split("").map(function (c) { return c + c; }).join("");
    }
    var num = parseInt(hex, 16);
    return [(num >> 16) & 255, (num >> 8) & 255, num & 255];
  }

  /**
   * Export a data table to PDF.
   * @param {Object} o
   *   o.title       Document title (Persian ok)
   *   o.subtitle    Optional subtitle
   *   o.head        Array of column labels
   *   o.rows        Array of row arrays
   *   o.filename    Download filename (default hst-export.pdf)
   *   o.orientation 'landscape' | 'portrait' (default from config)
   */

  function loadPdfImage(url, cb) {
    if (!url) {
      cb(null);
      return;
    }
    var im = new Image();
    im.crossOrigin = "anonymous";
    im.onload = function () { cb(im); };
    im.onerror = function () { cb(null); };
    im.src = url;
  }

  function loadPdfImages(urls, cb) {
    var out = {};
    urls = (urls || []).filter(function (url, idx, arr) { return url && arr.indexOf(url) === idx; });
    if (!urls.length) {
      cb(out);
      return;
    }
    var pending = urls.length;
    urls.forEach(function (url) {
      loadPdfImage(url, function (img) {
        if (img) out[url] = img;
        if (--pending === 0) cb(out);
      });
    });
  }

  function drawStandardPdfHeader(doc, opts) {
    opts = opts || {};
    var c = cfg();
    var pageWidth = doc.internal.pageSize.getWidth();
    var marginX = opts.marginX || 32;
    var x = marginX;
    var y = opts.y || 32;
    var w = pageWidth - marginX * 2;
    var h = opts.height || 78;
    var accent = opts.accent || hexToRgb(c.accent || "#334155");
    var borderCol = [214, 221, 229];
    var centerX = x + w / 2;
    var logoImg = opts.logoImg || null;
    var school = c.schoolName || "";

    doc.setFillColor(248, 250, 252);
    doc.setDrawColor(borderCol[0], borderCol[1], borderCol[2]);
    rrect(doc, x, y, w, h, 8, "FD");

    if (logoImg) {
      try {
        var logoH = 22;
        var ratio = (logoImg.width && logoImg.height) ? (logoImg.width / logoImg.height) : 1;
        var logoW = logoH * ratio;
        if (logoW > 40) { logoW = 40; logoH = logoW / ratio; }
        doc.addImage(logoImg, centerX - logoW / 2, y + 7, logoW, logoH);
      } catch (e0) {}
    }

    doc.setTextColor(accent[0], accent[1], accent[2]);
    doc.setFontSize(10.5);
    if (school) doc.text(shapeFa(toFa(school)), centerX, y + 40, { align: "center", maxWidth: w - 160 });

    doc.setTextColor(30, 41, 59);
    doc.setFontSize(9.2);
    doc.text(shapeFa(toFa(opts.title || "")), centerX, y + 56, { align: "center", maxWidth: w - 150 });

    if (opts.subtitle) {
      doc.setTextColor(100, 116, 139);
      doc.setFontSize(7);
      doc.text(shapeFa(toFa(opts.subtitle)), centerX, y + 70, { align: "center", maxWidth: w - 150 });
    }

    if (opts.leftText) {
      doc.setTextColor(100, 116, 139);
      doc.setFontSize(7);
      doc.text(shapeFa(toFa(opts.leftText)), x + 14, y + 42, { align: "left", maxWidth: 110 });
    }

    if (opts.rightText) {
      doc.setTextColor(100, 116, 139);
      doc.setFontSize(7);
      doc.text(shapeFa(toFa(opts.rightText)), x + w - 14, y + 42, { align: "right", maxWidth: 110 });
    }

    return y + h + 16;
  }

  function tablePdf(o) {
    o = o || {};
    var Ctor = jsPDFCtor();
    var c = cfg();

    if (!Ctor) {
      var rowsHtml = (o.rows || []).map(function (r) {
        return "<tr>" + (r || []).map(function (cell) {
          return "<td>" + escapeHtml(cell) + "</td>";
        }).join("") + "</tr>";
      }).join("");
      var headHtml = "<tr>" + (o.head || []).map(function (h) {
        return "<th>" + escapeHtml(h) + "</th>";
      }).join("") + "</tr>";
      printDocument({
        title: o.title || "",
        subtitle: o.subtitle || "",
        bodyHtml: "<table dir=\"rtl\"><thead>" + headHtml + "</thead><tbody>" + rowsHtml + "</tbody></table>",
      });
      if (typeof o.onProgress === "function") o.onProgress(100, "خروجی آماده شد.");
      if (typeof o.onDone === "function") o.onDone();
      return;
    }

    var orientation = o.orientation || (c.orientation === "P" ? "portrait" : "landscape");
    loadPdfImage(c.logoUrl || "", function (logoImg) {
      var doc = new Ctor({ orientation: orientation, unit: "pt", format: (c.paper || "a4").toLowerCase() });
      var hasFa = ensureVazir(doc);
      var fontName = hasFa ? "Vazirmatn" : "helvetica";
      if (hasFa) doc.setFont("Vazirmatn", "normal");

      var accent = hexToRgb(c.accent || "#334155");
      var pageWidth = doc.internal.pageSize.getWidth();
      var pageHeight = doc.internal.pageSize.getHeight();
      var marginX = 32;

      function drawTablePageNumbers() {
        if (!doc.internal || typeof doc.internal.getNumberOfPages !== "function") return;
        var total = doc.internal.getNumberOfPages();
        var oldPage = doc.internal.getCurrentPageInfo ? doc.internal.getCurrentPageInfo().pageNumber : total;
        doc.setFont(fontName, "normal");
        doc.setFontSize(7);
        doc.setTextColor(100, 116, 139);
        for (var pn = 1; pn <= total; pn++) {
          if (typeof doc.setPage === "function") doc.setPage(pn);
          doc.text(shapeFa(toFa("صفحه " + pn + " از " + total)), pageWidth / 2, pageHeight - 14, { align: "center" });
        }
        if (typeof doc.setPage === "function") doc.setPage(oldPage);
      }

      var y = drawStandardPdfHeader(doc, {
        title: o.title || "",
        subtitle: o.subtitle || "",
        leftText: todayStr(),
        rightText: "گزارش",
        accent: accent,
        logoImg: logoImg,
        marginX: marginX
      });

      function tableProgress(percent, text) {
        if (typeof o.onProgress === "function") o.onProgress(percent, text || "");
      }

      tableProgress(15, "در حال آماده‌سازی جدول PDF...");

      var head = (o.head || []).slice().reverse();
      var rows = (o.rows || []).map(function (row) { return (row || []).slice().reverse(); });

      if (typeof doc.autoTable === "function") {
        doc.autoTable({
          head: [shapeCells(head)],
          body: rows.map(shapeCells),
          startY: y,
          styles: { font: fontName, fontSize: 9, halign: "center", valign: "middle", cellPadding: 5 },
          headStyles: { fillColor: accent, textColor: 255, font: fontName, halign: "center" },
          alternateRowStyles: { fillColor: [246, 248, 250] },
          margin: { left: marginX, right: marginX, top: 126 },
          theme: "grid",
          didDrawPage: function (data) {
            if (data.pageNumber > 1) {
              drawStandardPdfHeader(doc, {
                title: o.title || "",
                subtitle: o.subtitle || "",
                leftText: todayStr(),
                rightText: "گزارش",
                accent: accent,
                logoImg: logoImg,
                marginX: marginX
              });
            }
          }
        });
      } else {
        drawManualTable(doc, shapeCells(head), rows.map(shapeCells), {
          startY: y,
          marginX: marginX,
          pageWidth: pageWidth,
          pageHeight: pageHeight,
          accent: accent,
          fontName: fontName,
          headerFn: function () {
            return drawStandardPdfHeader(doc, {
              title: o.title || "",
              subtitle: o.subtitle || "",
              leftText: todayStr(),
              rightText: "گزارش",
              accent: accent,
              logoImg: logoImg,
              marginX: marginX
            });
          }
        });
      }

      tableProgress(96, "در حال ذخیره فایل PDF...");
      drawTablePageNumbers();
      doc.save(o.filename || "hst-export.pdf");
      if (typeof o.onDone === "function") o.onDone();
    });
  }

  // Minimal grid renderer used when jsPDF-autoTable isn't bundled.
  function drawManualTable(doc, head, body, opt) {
    var cols = head.length || (body[0] ? body[0].length : 0);
    if (!cols) return;
    var usableW = opt.pageWidth - opt.marginX * 2;
    var colW = usableW / cols;
    var rowH = 20;
    var y = opt.startY;
    var startX = opt.pageWidth - opt.marginX; // RTL: start from the right edge

    function drawRow(cells, isHead) {
      if (y + rowH > opt.pageHeight - 30) {
        doc.addPage();
        y = opt.headerFn ? opt.headerFn() : 40;
      }
      if (isHead) {
        doc.setFillColor(opt.accent[0], opt.accent[1], opt.accent[2]);
        doc.setTextColor(255);
        doc.rect(opt.marginX, y, usableW, rowH, "F");
      } else {
        doc.setTextColor(20);
      }
      doc.setFontSize(9);
      for (var i = 0; i < cols; i++) {
        // RTL: first column on the right.
        var cellRight = startX - i * colW;
        var cellCenter = cellRight - colW / 2;
        var txt = cells[i] == null ? "" : String(cells[i]);
        doc.text(txt, cellCenter, y + rowH / 2 + 3, { align: "center", maxWidth: colW - 6 });
        doc.setDrawColor(203, 213, 225);
        doc.rect(cellRight - colW, y, colW, rowH);
      }
      y += rowH;
    }

    drawRow(head, true);
    doc.setTextColor(20);
    for (var r = 0; r < body.length; r++) {
      if (r % 2 === 1) {
        doc.setFillColor(246, 248, 250);
        doc.rect(opt.marginX, y, usableW, rowH, "F");
      }
      drawRow(body[r], false);
    }
  }

  // ---- Public wrappers ---------------------------------------------------
  /**
   * Render one or more schedule-style grids to a single PDF using jsPDF.
   * Each block: { title, subtitle, head:[...], rows:[[cell,...],...] }.
   * Cells may contain "\n" for multi-line content (auto-sized row height).
   * Falls back to printing the provided HTML if jsPDF is unavailable.
   *
   * @param {Object} o  { blocks, filename, fallbackHtml, title }
   */
  /**
   * Render schedule grids to a portrait PDF, styled like the school's weekly
   * plan: per-teacher header (avatar + "آقای ..." + academic year), a themed
   * day/shift grid, single-week cells highlighted, two teachers per page.
   *
   * @param {Object} o { blocks, filename, fallbackHtml, title }
   *   block = { teacher_name, avatar_url, initial, subtitle, days:[...],
   *             shifts:[ { label, cells:[ [ {lesson,sub,week} ... ] ... ] } ] }
   */
  // Convert ASCII digits to Persian digits (numbers everywhere must be Farsi).
  function toFa(str) {
    var fa = ["\u06F0","\u06F1","\u06F2","\u06F3","\u06F4","\u06F5","\u06F6","\u06F7","\u06F8","\u06F9"];
    return String(str == null ? "" : str).replace(/[0-9]/g, function (d) { return fa[+d]; });
  }

  // Mix two rgb arrays: ratio 0 => a, 1 => b.
  function mix(a, b, ratio) {
    return [
      Math.round(a[0] + (b[0] - a[0]) * ratio),
      Math.round(a[1] + (b[1] - a[1]) * ratio),
      Math.round(a[2] + (b[2] - a[2]) * ratio),
    ];
  }

  // Round-rect that tolerates a zero radius.
  function rrect(doc, x, y, w, h, r, style) {
    if (r > 0 && typeof doc.roundedRect === "function") {
      doc.roundedRect(x, y, w, h, r, r, style);
    } else {
      doc.rect(x, y, w, h, style);
    }
  }

  /**
   * Render schedule grids to a portrait PDF, styled like the school's weekly
   * plan: per-teacher header (avatar + "آقای ..." + academic year), a themed
   * day/shift grid with rounded corners, single-week cells highlighted, two
   * teachers per page separated by a dashed cut line.
   */
  function gridPdf(o) {
    o = o || {};
    var Ctor = jsPDFCtor();
    var blocks = o.blocks || [];

    if (!Ctor || !blocks.length) {
      if (o.fallbackHtml) printHtml(o.fallbackHtml, { title: o.title || "" });
      return Promise.resolve({ fallback: true, pages: 0 });
    }

    var c = cfg();
    var accent = hexToRgb(c.accent || "#334155");
    var accentSoft = mix(accent, [255, 255, 255], 0.80);
    var accentSofter = mix(accent, [255, 255, 255], 0.90);
    var warm = [180, 83, 9];
    var warmSoft = [252, 239, 217];
    var borderCol = [214, 221, 229];

    var doc = new Ctor({ orientation: "portrait", unit: "pt", format: (c.paper || "a4").toLowerCase() });
    var hasFa = ensureVazir(doc);
    if (hasFa) doc.setFont("Vazirmatn", "normal");

    var pageW = doc.internal.pageSize.getWidth();
    var pageH = doc.internal.pageSize.getHeight();
    var marginX = 32;
    var usableW = pageW - marginX * 2;
    var R = 7; // shared corner radius (matches the app's --hst-r-xs/sm scale)

    var imgCache = {};
    var urls = [];
    var resolveGridPdf;
    var rejectGridPdf;
    var gridPdfStarted = false;
    var gridPdfPromise = new Promise(function (resolve, reject) {
      resolveGridPdf = resolve;
      rejectGridPdf = reject;
    });

    function gridProgress(percent, text) {
      if (typeof o.onProgress === "function") o.onProgress(percent, text || "");
    }
    blocks.forEach(function (b) {
      if (b.avatar_url) urls.push(b.avatar_url);
    });

    function scheduleYearLabel(value) {
      var textValue = String(value || "")
        .replace(/^سال\s+تحصیلی\s*[:：]?\s*/u, "")
        .replace(/^سال\s+تحصیلی\s+/u, "")
        .trim();

      return textValue ? ("سال تحصیلی " + textValue) : "";
    }

    var logoUrl = c.logoUrl || "";
    if (logoUrl) urls.push(logoUrl);
    urls = urls.filter(function (url, idx, arr) { return url && arr.indexOf(url) === idx; });

    function renderAll() {
      if (gridPdfStarted) return;
      gridPdfStarted = true;
      var topY = 34;
      var blockGap = 26;
      var blockH = (pageH - topY - 24 - blockGap) / 2;
      var renderIndex = 0;
      var chunkSize = blocks.length > 20 ? 2 : 4;

      gridProgress(12, "تصاویر آماده شد؛ در حال ساخت صفحات برنامه هفتگی...");

      function renderChunk() {
        try {
          var end = Math.min(blocks.length, renderIndex + chunkSize);
          for (; renderIndex < end; renderIndex++) {
            var block = blocks[renderIndex];
            var slot = renderIndex % 2;
            if (renderIndex > 0 && slot === 0) doc.addPage();
            var y = topY + slot * (blockH + blockGap);
            drawBlock(block, y, blockH);

            // Dashed cut line between the two blocks on a page.
            if (slot === 0 && renderIndex < blocks.length - 1) {
              var cutY = topY + blockH + blockGap / 2;
              doc.setDrawColor(170, 178, 188);
              doc.setLineWidth(0.6);
              if (doc.setLineDashPattern) doc.setLineDashPattern([4, 3], 0);
              doc.line(marginX, cutY, pageW - marginX, cutY);
              if (doc.setLineDashPattern) doc.setLineDashPattern([], 0);
            }
          }

          gridProgress(
            12 + Math.round((renderIndex / blocks.length) * 84),
            "در حال ساخت برنامه " + renderIndex + " از " + blocks.length + "..."
          );

          if (renderIndex < blocks.length) {
            window.setTimeout(renderChunk, 0);
            return;
          }

          gridProgress(98, "در حال ذخیره فایل برنامه هفتگی...");
          doc.save(o.filename || downloadName());
          if (typeof o.onDone === "function") o.onDone();
          resolveGridPdf({ fallback: false, blocks: blocks.length, pages: Math.ceil(blocks.length / 2) });
        } catch (error) {
          rejectGridPdf(error);
        }
      }

      window.setTimeout(renderChunk, 0);
    }

    // Build a friendly download filename: single teacher -> their name,
    // otherwise the general document title.
    function scheduleFileNamePart(value) {
      return String(value == null ? "" : value)
        .replace(/[\\/:*?"<>|]+/g, " ")
        .replace(/\s+/g, "-")
        .replace(/-+/g, "-")
        .replace(/^-|-$/g, "")
        .trim();
    }

    function downloadName() {
      var subject = "";
      if (blocks.length === 1 && blocks[0].teacher_name) {
        subject = blocks[0].teacher_name;
      } else if (blocks.length === 1 && blocks[0].mode === "class") {
        subject = blocks[0].title || "";
      } else {
        subject = o.title || "";
      }

      subject = String(subject || "")
        .replace(/^برنامه\s+هفتگی\s*/u, "")
        .replace(/^کلاس\s*/u, "کلاس-")
        .trim();

      var suffix = scheduleFileNamePart(subject);
      return "برنامه-هفتگی" + (suffix ? "-" + suffix : "") + ".pdf";
    }

    function drawBlock(block, top, maxH) {
      var y = top;

      // Outer schedule frame, matching the discipline-book structure.
      doc.setDrawColor(borderCol[0], borderCol[1], borderCol[2]);
      doc.setLineWidth(0.7);
      rrect(doc, marginX, top, usableW, maxH, R + 2, "S");

      var innerPad = 10;
      var frameX = marginX + innerPad;
      var frameW = usableW - innerPad * 2;

      // ---- Header coordinated with the discipline-book header ------------
      var logoImg = logoUrl ? imgCache[logoUrl] : null;
      var school = c.schoolName || "";
      var hideIdentity = !!block.hide_identity || block.mode === "class";
      var headX = frameX;
      var headY = y + innerPad;
      var headW = frameW;

      if (hideIdentity) {
        var classHeadH = 78;
        doc.setFillColor(248, 250, 252);
        doc.setDrawColor(borderCol[0], borderCol[1], borderCol[2]);
        rrect(doc, headX, headY, headW, classHeadH, 8, "FD");

        // Left QR column; size and label baseline are kept inside the header frame.
        var classQrSize = 50;
        var classQrX = headX + 10;
        var classQrY = headY + 8;
        if (block.download_url || block.qr_url) {
          drawPdfQrImage(block.qr_url || "", block.download_url || "", classQrX, classQrY, classQrSize, "دریافت نسخه دیجیتال");
        }

        // Center school/title column, aligned with teacher and discipline headers.
        var cCenterX = headX + headW / 2;
        if (logoImg) {
          var clh = 22;
          var cratio = (logoImg.width && logoImg.height) ? (logoImg.width / logoImg.height) : 1;
          var clw = clh * cratio;
          if (clw > 38) {
            clw = 38;
            clh = clw / cratio;
          }
          try { doc.addImage(logoImg, cCenterX - clw / 2, headY + 8, clw, clh); } catch (e0) {}
        }

        doc.setTextColor(accent[0], accent[1], accent[2]);
        doc.setFontSize(10.5);
        if (school) doc.text(shapeFa(toFa(school)), cCenterX, headY + 42, { align: "center", maxWidth: 190 });

        doc.setTextColor(30, 41, 59);
        doc.setFontSize(8);
        doc.text(shapeFa("برنامه هفتگی کلاس"), cCenterX, headY + 56, { align: "center", maxWidth: 170 });

        var classYearText = scheduleYearLabel(block.subtitle || "");
        if (classYearText) {
          doc.setTextColor(100, 116, 139);
          doc.setFontSize(6.8);
          doc.text(shapeFa(toFa(classYearText)), cCenterX, headY + 70, { align: "center", maxWidth: 170 });
        }

        // Right class identity text only; no portrait/avatar-style box for class schedules.
        var classNameText = String(block.title || "").replace(/^برنامه\s+هفتگی\s*/u, "").trim();
        if (!classNameText) classNameText = "کلاس";
        var classTextX = headX + headW - 14;
        var classTextY = headY + 26;

        doc.setTextColor(30, 41, 59);
        doc.setFontSize(10.2);
        doc.text(shapeFa(toFa(classNameText)), classTextX, classTextY, { align: "right", maxWidth: 160 });
        doc.setTextColor(100, 116, 139);
        doc.setFontSize(6.8);
        doc.text(shapeFa("برنامه اختصاصی کلاس"), classTextX, classTextY + 17, { align: "right", maxWidth: 160 });

        y = headY + classHeadH + 12;
      } else {
        var teacherHeadH = 78;
        doc.setFillColor(248, 250, 252);
        doc.setDrawColor(borderCol[0], borderCol[1], borderCol[2]);
        rrect(doc, headX, headY, headW, teacherHeadH, 8, "FD");

        // Left QR column for direct PDF download of this teacher schedule.
        var qrSize = 50;
        var qrX = headX + 10;
        var qrY = headY + 8;
        drawPdfQrImage(block.qr_url || "", block.download_url || "", qrX, qrY, qrSize, "دریافت نسخه دیجیتال");

        // Center school/title column.
        var centerX = headX + headW / 2;
        if (logoImg) {
          var logoH = 22;
          var ratio = (logoImg.width && logoImg.height) ? (logoImg.width / logoImg.height) : 1;
          var logoW = logoH * ratio;
          if (logoW > 38) {
            logoW = 38;
            logoH = logoW / ratio;
          }
          try { doc.addImage(logoImg, centerX - logoW / 2, headY + 8, logoW, logoH); } catch (e1) {}
        }

        doc.setTextColor(accent[0], accent[1], accent[2]);
        doc.setFontSize(10.5);
        if (school) doc.text(shapeFa(toFa(school)), centerX, headY + 42, { align: "center", maxWidth: 190 });
        doc.setTextColor(30, 41, 59);
        doc.setFontSize(8);
        doc.text(shapeFa("برنامه هفتگی معلم"), centerX, headY + 56, { align: "center", maxWidth: 170 });

        var scheduleYearText = scheduleYearLabel(block.subtitle || "");
        if (scheduleYearText) {
          doc.setTextColor(100, 116, 139);
          doc.setFontSize(6.8);
          doc.text(shapeFa(toFa(scheduleYearText)), centerX, headY + 70, { align: "center", maxWidth: 170 });
        }

        // Right teacher identity column.
        var avW = 42;
        var avH = 56;
        var avX = headX + headW - 12 - avW;
        var avY = headY + 12;
        drawSchedulePortrait(block, avX, avY, avW, avH);

        doc.setTextColor(30, 41, 59);
        doc.setFontSize(10.2);
        doc.text(shapeFa(toFa(block.teacher_name || "")), avX - 8, avY + 17, { align: "right", maxWidth: 150 });
        doc.setTextColor(100, 116, 139);
        doc.setFontSize(6.8);
        doc.text(shapeFa("برنامه اختصاصی تدریس"), avX - 8, avY + 34, { align: "right", maxWidth: 150 });
        doc.text(shapeFa(toFa(block.title || "برنامه هفتگی")), avX - 8, avY + 48, { align: "right", maxWidth: 150 });

        y = headY + teacherHeadH + 12;
      }

      // ---- Grid ---------------------------------------------------------
      var days = block.days || [];
      var shifts = block.shifts || [];
      var firstW = 52;
      var gridX = frameX;
      var gridW = frameW;
      var dayW = (gridW - firstW) / days.length;
      var colW = [firstW];
      for (var d = 0; d < days.length; d++) colW.push(dayW);

      function colRightX(idx) {
        var x = gridX + gridW;
        for (var k = 0; k < idx; k++) x -= colW[k];
        return x;
      }

      // Header row (rounded top via a rounded rect behind the band).
      var headH = 18;
      doc.setFillColor(accent[0], accent[1], accent[2]);
      rrect(doc, gridX, y, gridW, headH, R, "F");
      // Square off the bottom of the header so it meets the body cleanly.
      doc.rect(gridX, y + headH - R, gridW, R, "F");
      doc.setTextColor(255);
      doc.setFontSize(8.5);
      doc.text(shapeFa("روز / زنگ"), colRightX(0) - colW[0] / 2, y + headH / 2 + 3, { align: "center", maxWidth: colW[0] - 4 });
      for (var di = 0; di < days.length; di++) {
        doc.text(shapeFa(days[di]), colRightX(di + 1) - colW[di + 1] / 2, y + headH / 2 + 3, { align: "center", maxWidth: colW[di + 1] - 4 });
      }
      y += headH;

      // Rows sized to fill the remaining height.
      var bodyBottom = top + maxH - innerPad;
      var remaining = bodyBottom - y;
      var rowH = Math.max(38, remaining / Math.max(1, shifts.length));

      var lineH = 8.5;

      shifts.forEach(function (shiftRow, sIdx) {
        var isLastRow = sIdx === shifts.length - 1;

        // Shift label cell.
        doc.setFillColor(accentSofter[0], accentSofter[1], accentSofter[2]);
        doc.rect(colRightX(0) - colW[0], y, colW[0], rowH, "F");
        doc.setTextColor(accent[0], accent[1], accent[2]);
        doc.setFontSize(8.5);
        doc.text(shapeFa(toFa(shiftRow.label)), colRightX(0) - colW[0] / 2, y + rowH / 2 + 3, { align: "center" });
        doc.setDrawColor(borderCol[0], borderCol[1], borderCol[2]);
        doc.setLineWidth(0.5);
        doc.rect(colRightX(0) - colW[0], y, colW[0], rowH);

        (shiftRow.cells || []).forEach(function (cell, di2) {
          var w = colW[di2 + 1];
          var cellLeft = colRightX(di2 + 1) - w;
          var cellCx = colRightX(di2 + 1) - w / 2;

          // Cell border (always).
          doc.setDrawColor(borderCol[0], borderCol[1], borderCol[2]);
          doc.setLineWidth(0.5);
          doc.rect(cellLeft, y, w, rowH);

          if (!cell.length) {
            doc.setTextColor(185);
            doc.setFontSize(9);
            doc.text("—", cellCx, y + rowH / 2 + 3, { align: "center" });
            return;
          }

          // Does this cell carry week-specific entries (odd / even)?
          var hasWeek = cell.some(function (e) { return e.week; });

          // Inner padding for the rounded card(s).
          var pad = 2;
          var innerX = cellLeft + pad;
          var innerW = w - pad * 2;

          if (hasWeek && cell.length > 1) {
            // Split the cell into one stacked section per entry; each section
            // gets its own background colour by week type (odd vs even).
            var n = cell.length;
            var segH = (rowH - pad * 2) / n;
            cell.forEach(function (e, idx) {
              var segTop = y + pad + idx * segH;
              var pal = weekPalette(e.week); // {bg, fg}
              doc.setFillColor(pal.bg[0], pal.bg[1], pal.bg[2]);
              rrect(doc, innerX, segTop + 1, innerW, segH - 2, R - 4, "F");
              drawCellEntry(e, innerX, segTop, innerW, segH, pal.fg, true);
            });
          } else {
            // Single entry (or plain lesson with no week split).
            var e0 = cell[0];
            var hasW0 = !!(e0 && e0.week);
            var pal0 = hasW0 ? weekPalette(e0.week) : { bg: accentSoft, fg: accent };
            doc.setFillColor(pal0.bg[0], pal0.bg[1], pal0.bg[2]);
            rrect(doc, innerX, y + pad, innerW, rowH - pad * 2, R - 3, "F");
            // If several entries share one (no-week) cell, stack them; else one.
            if (cell.length > 1) {
              var segH2 = (rowH - pad * 2) / cell.length;
              cell.forEach(function (e, idx) {
                drawCellEntry(e, innerX, y + pad + idx * segH2, innerW, segH2, accent, false);
              });
            } else {
              drawCellEntry(e0, innerX, y + pad, innerW, rowH - pad * 2, pal0.fg, hasW0);
            }
          }
        });

        y += rowH;
      });

    }

    // Palette for a week label: odd vs even get distinct, theme-harmonised tints.
    function weekPalette(week) {
      var w = String(week || "");
      if (w.indexOf("فرد") !== -1) {
        // Odd week — warm amber.
        return { bg: [252, 239, 217], fg: [180, 83, 9] };
      }
      if (w.indexOf("زوج") !== -1) {
        // Even week — cool blue/violet harmonised with the theme accent.
        return { bg: mix(accent, [255, 255, 255], 0.84), fg: mix(accent, [0, 0, 0], 0.15) };
      }
      return { bg: accentSoft, fg: accent };
    }

    // Draw one lesson entry inside a box (lesson + sub + week), auto-fitting
    // the text so it never spills outside the section.
    function drawCellEntry(e, bx, by, bw, bh, fg, withWeek) {
      var maxTextW = bw - 6;
      // Build the text lines.
      var fsLesson = 7.2, fsSub = 6.3, fsWeek = 6.2, lh = 8;
      var lines = [];
      lines.push({ t: shapeFa(toFa(e.lesson || "")), fs: fsLesson, col: fg, wrap: true });
      if (e.sub) lines.push({ t: shapeFa(toFa(e.sub)), fs: fsSub, col: [115, 115, 115], wrap: true });
      if (withWeek && e.week) lines.push({ t: shapeFa(toFa(e.week)), fs: fsWeek, col: fg, wrap: false });

      // Expand wrapped lines and shrink the line-height/font if it won't fit.
      function layout(scale) {
        var out = [];
        lines.forEach(function (L) {
          var fs = L.fs * scale;
          doc.setFontSize(fs);
          var parts = L.wrap ? doc.splitTextToSize(L.t, maxTextW) : [L.t];
          parts.forEach(function (p) { out.push({ t: p, fs: fs, col: L.col }); });
        });
        return out;
      }
      var scale = 1;
      var laid = layout(scale);
      var lineH2 = lh;
      // Shrink until it fits the section height.
      while (laid.length * lineH2 > bh - 4 && scale > 0.62) {
        scale -= 0.08;
        lineH2 = lh * (scale < 1 ? scale + 0.08 : 1);
        laid = layout(scale);
      }

      var totalH = laid.length * lineH2;
      var ty = by + (bh - totalH) / 2 + lineH2 - 2;
      var cx = bx + bw / 2;
      laid.forEach(function (L) {
        doc.setFontSize(L.fs);
        doc.setTextColor(L.col[0], L.col[1], L.col[2]);
        doc.text(L.t, cx, ty, { align: "center", maxWidth: maxTextW });
        ty += lineH2;
      });
    }

    // Shared QR renderer for schedule headers. Prefer the bundled QR generator
    // so schedule PDFs do not depend on a remote image or cross-origin loading.
    function drawPdfQrImage(qrUrl, payload, x, y, size, label) {
      var qrImg = qrUrl ? imgCache[qrUrl] : null;
      var rendered = false;

      doc.setFillColor(255, 255, 255);
      doc.setDrawColor(borderCol[0], borderCol[1], borderCol[2]);
      rrect(doc, x, y, size, size, 5, "FD");

      if (payload && window.HSTQRCode && window.HSTQRErrorCorrectLevel) {
        try {
          var qr = new window.HSTQRCode(-1, window.HSTQRErrorCorrectLevel.M);
          qr.addData(String(payload));
          qr.make();

          var count = qr.getModuleCount();
          var quiet = 4;
          var available = size - 8;
          var moduleSize = available / (count + quiet * 2);
          var originX = x + 4 + quiet * moduleSize;
          var originY = y + 4 + quiet * moduleSize;

          doc.setFillColor(15, 23, 42);
          for (var row = 0; row < count; row++) {
            for (var col = 0; col < count; col++) {
              if (qr.isDark(row, col)) {
                doc.rect(
                  originX + col * moduleSize,
                  originY + row * moduleSize,
                  moduleSize + 0.06,
                  moduleSize + 0.06,
                  "F"
                );
              }
            }
          }
          rendered = true;
        } catch (qrError) {}
      }

      if (!rendered && qrImg) {
        try {
          doc.addImage(qrImg, "PNG", x + 4, y + 4, size - 8, size - 8);
          rendered = true;
        } catch (e1) {
          try {
            doc.addImage(qrImg, "JPEG", x + 4, y + 4, size - 8, size - 8);
            rendered = true;
          } catch (e2) {}
        }
      }

      if (!rendered) {
        doc.setFontSize(5.7);
        doc.setTextColor(100, 116, 139);
        doc.text(shapeFa("QR بارگذاری نشد"), x + size / 2, y + size / 2 + 2, { align: "center", maxWidth: size - 8 });
      }

      if (label) {
        doc.setFontSize(5.8);
        doc.setTextColor(100, 116, 139);
        doc.text(shapeFa(label), x + size / 2, y + size + 12, { align: "center", maxWidth: size + 18 });
      }
    }

    // Draw a portrait photo in a 3x4-style rounded frame without aggressive cropping.
    function drawSchedulePortrait(block, x, y, w, h) {
      h = h || Math.round(w * 4 / 3);
      var img = block.avatar_url ? imgCache[block.avatar_url] : null;

      doc.setFillColor(255, 255, 255);
      doc.setDrawColor(borderCol[0], borderCol[1], borderCol[2]);
      rrect(doc, x, y, w, h, 7, "FD");

      if (img) {
        try {
          var scale = 3;
          var cnv = document.createElement("canvas");
          cnv.width = Math.round(w * scale);
          cnv.height = Math.round(h * scale);
          var ctx = cnv.getContext("2d");
          var rr = 7 * scale;

          ctx.save();
          ctx.beginPath();
          ctx.moveTo(rr, 0);
          ctx.lineTo(cnv.width - rr, 0);
          ctx.quadraticCurveTo(cnv.width, 0, cnv.width, rr);
          ctx.lineTo(cnv.width, cnv.height - rr);
          ctx.quadraticCurveTo(cnv.width, cnv.height, cnv.width - rr, cnv.height);
          ctx.lineTo(rr, cnv.height);
          ctx.quadraticCurveTo(0, cnv.height, 0, cnv.height - rr);
          ctx.lineTo(0, rr);
          ctx.quadraticCurveTo(0, 0, rr, 0);
          ctx.closePath();
          ctx.clip();

          var iw = img.width || w;
          var ih = img.height || h;
          var sourceRatio = iw / ih;
          var inset = 2 * scale;
          var safeW = cnv.width - inset * 2;
          var safeH = cnv.height - inset * 2;
          var safeRatio = safeW / safeH;
          var dx = inset;
          var dy = inset;
          var dw = safeW;
          var dh = safeH;

          if (sourceRatio > safeRatio) {
            dh = safeW / sourceRatio;
            dy = inset + (safeH - dh) / 2;
          } else if (sourceRatio < safeRatio) {
            dw = safeH * sourceRatio;
            dx = inset + (safeW - dw) / 2;
          }

          ctx.fillStyle = "#ffffff";
          ctx.fillRect(0, 0, cnv.width, cnv.height);
          ctx.drawImage(img, 0, 0, iw, ih, dx, dy, dw, dh);
          ctx.restore();

          doc.addImage(cnv.toDataURL("image/png"), "PNG", x + 0.7, y + 0.7, w - 1.4, h - 1.4);
        } catch (e) {
          try { doc.addImage(img, "JPEG", x + 1, y + 1, w - 2, h - 2); } catch (ignore) {}
        }

        doc.setDrawColor(borderCol[0], borderCol[1], borderCol[2]);
        rrect(doc, x, y, w, h, 7, "S");
        return;
      }

      doc.setFillColor(accentSofter[0], accentSofter[1], accentSofter[2]);
      rrect(doc, x + 2, y + 2, w - 4, h - 4, 6, "F");
      doc.setTextColor(accent[0], accent[1], accent[2]);
      doc.setFontSize(14);
      doc.text(
        shapeFa(initialsOf(block.teacher_name || block.name || "", block.first_name || "", block.last_name || "", block.initials || block.initial || "")),
        x + w / 2,
        y + h / 2 + 5,
        { align: "center" }
      );
    }

    // Draw an image clipped to a circle (true round avatar, not a dot).
    function drawCircularAvatar(img, x, y, size) {
      var cx = x + size / 2;
      var cy = y + size / 2;
      var r = size / 2;
      var dataUrl = null;
      try {
        // Mask the image into a circle on a high-res canvas, then embed that.
        // Canvas-side masking works on every jsPDF version (no clip support
        // needed) and guarantees a truly round avatar.
        var scale = 3;
        var cnv = document.createElement("canvas");
        cnv.width = size * scale;
        cnv.height = size * scale;
        var ctx = cnv.getContext("2d");
        ctx.save();
        ctx.beginPath();
        ctx.arc((size * scale) / 2, (size * scale) / 2, (size * scale) / 2, 0, Math.PI * 2);
        ctx.closePath();
        ctx.clip();
        // cover-fit the source image into the square canvas
        var iw = img.width || size;
        var ih = img.height || size;
        var side = Math.min(iw, ih);
        var sx = (iw - side) / 2;
        var sy = (ih - side) / 2;
        ctx.drawImage(img, sx, sy, side, side, 0, 0, size * scale, size * scale);
        ctx.restore();
        dataUrl = cnv.toDataURL("image/png");
      } catch (e) {
        dataUrl = null;
      }

      try {
        if (dataUrl) {
          doc.addImage(dataUrl, "PNG", x, y, size, size);
        } else {
          doc.addImage(img, x, y, size, size);
        }
      } catch (e2) {}

      // Accent ring around the avatar.
      doc.setDrawColor(accent[0], accent[1], accent[2]);
      doc.setLineWidth(0.9);
      doc.circle(cx, cy, r);
    }

    gridProgress(5, "در حال آماده‌سازی تصاویر و اطلاعات برنامه هفتگی...");
    if (!urls.length) {
      renderAll();
      return gridPdfPromise;
    }
    var pending = urls.length;
    urls.forEach(function (url) {
      var im = new Image();
      im.crossOrigin = "anonymous";
      im.onload = function () { imgCache[url] = im; if (--pending === 0) renderAll(); };
      im.onerror = function () { if (--pending === 0) renderAll(); };
      im.src = url;
    });
    window.setTimeout(function () { if (pending > 0) { pending = 0; renderAll(); } }, 4000);
    return gridPdfPromise;
  }

  function printHtml(html, opts) {
    opts = opts || {};
    if (isFullDoc(html)) {
      printViaIframe(html);
    } else {
      printViaIframe(buildPrintDocument({ title: opts.title, subtitle: opts.subtitle, bodyHtml: html }));
    }
  }

  function printDocument(opts) {
    printViaIframe(buildPrintDocument(opts || {}));
  }

  /**
   * Download a black-and-white exam question sheet or correction guide.
   * The payload is intentionally data-oriented so the same ordered questions
   * and edited scores shown in the builder are persisted in the PDF.
   */
  function renderExamPaperPages(o) {
    o = o || {};
    var questions = Array.isArray(o.questions) ? o.questions : [];

    return new Promise(function (resolve, reject) {
      if (!questions.length) {
        resolve({ pages: [], pageWidth: 595.28, pageHeight: 841.89 });
        return;
      }

      var started = false;
      function renderPages() {
        if (started) return;
        started = true;

        try {
          var exam = o.exam || {};
          var isAnswers = o.kind === "answers";
          var pageW = 595.28;
          var pageH = 841.89;
          var margin = 24;
          var width = pageW - margin * 2;
          var rowNumberW = isAnswers ? 42 : 0;
          var scoreW = 48;
          var bodyW = width - rowNumberW - scoreW;
          var scoreX = margin;
          var bodyX = margin + scoreW;
          var numberX = bodyX + bodyW;
          var bodyRightX = bodyX + bodyW;
          var totalScore = questions.reduce(function (sum, question) { return sum + (Number(question.score) || 0); }, 0);
          // Raster-based exam PDFs use a near-300 DPI canvas so Persian
          // text, table borders and diagrams remain sharp when zoomed.
          var canvasScale = 4;
          var fontFamily = '"Vazirmatn", Tahoma, Arial, sans-serif';
          var canvas = null;
          var ctx = null;
          var y = 24;
          var pages = [];

          function examType(value) {
            return ({ continuous: "مستمر", midterm: "میان ترم", final_first: "پایانی اول", final_second: "پایانی دوم", final: "پایانی", quiz: "کوئیز", custom: "اختصاصی" })[String(value || "")] || "آزمون";
          }
          function major(value) {
            return ({ experimental: "علوم تجربی", math: "ریاضی و فیزیک", humanities: "ادبیات و علوم انسانی" })[String(value || "")] || String(value || "");
          }
          function text(value) {
            return toFa(String(value == null ? "" : value))
              .replace(/\r/g, "")
              .replace(/[\u200e\u200f\u202a-\u202e\u2066-\u2069]/g, "");
          }
          function setFont(size, weight) {
            ctx.font = String(weight || 400) + " " + String(size || 9) + "px " + fontFamily;
            ctx.fillStyle = "#0f172a";
            ctx.textBaseline = "top";
          }
          function splitLongToken(token, maxWidth) {
            var parts = [];
            var current = "";
            Array.from(token).forEach(function (char) {
              var next = current + char;
              if (current && ctx.measureText(next).width > maxWidth) {
                parts.push(current);
                current = char;
              } else {
                current = next;
              }
            });
            if (current) parts.push(current);
            return parts.length ? parts : [token];
          }
          function wrapText(value, maxWidth, size, weight) {
            setFont(size, weight);
            var raw = text(value).trim();
            if (!raw) return ["—"];
            var output = [];
            raw.split(/\n/).forEach(function (paragraph) {
              var words = paragraph.trim().split(/\s+/).filter(Boolean);
              if (!words.length) {
                output.push("");
                return;
              }
              var line = "";
              words.forEach(function (word) {
                var pieces = ctx.measureText(word).width > maxWidth ? splitLongToken(word, maxWidth) : [word];
                pieces.forEach(function (piece) {
                  var candidate = line ? line + " " + piece : piece;
                  if (line && ctx.measureText(candidate).width > maxWidth) {
                    output.push(line);
                    line = piece;
                  } else {
                    line = candidate;
                  }
                });
              });
              if (line) output.push(line);
            });
            return output.length ? output : ["—"];
          }
          function choiceLayout(choices, maxWidth, size, weight) {
            var letters = ["الف", "ب", "ج", "د"];
            var entries = (choices || []).slice(0, 4).map(function (choice, index) {
              return letters[index] + ") " + text(choice);
            });
            var columnGap = 24;
            var columnWidth = Math.max(60, (maxWidth - columnGap) / 2);
            setFont(size, weight);
            var shouldStack = entries.some(function (entry) {
              return entry.length > 34 || ctx.measureText(entry).width > columnWidth;
            });
            var rows = [];
            if (shouldStack) {
              entries.forEach(function (entry) {
                rows.push({ right: wrapText(entry, maxWidth, size, weight), left: [] });
              });
              return { stacked: true, rows: rows, gap: columnGap };
            }
            for (var i = 0; i < entries.length; i += 2) {
              rows.push({
                right: wrapText(entries[i] || "", columnWidth, size, weight),
                left: entries[i + 1] ? wrapText(entries[i + 1], columnWidth, size, weight) : [],
              });
            }
            return { stacked: false, rows: rows, gap: columnGap };
          }
          function trueFalseLayout(maxWidth, size, weight) {
            var columnGap = 24;
            var columnWidth = Math.max(60, (maxWidth - columnGap) / 2);
            return {
              stacked: false,
              gap: columnGap,
              rows: [{
                right: wrapText("الف) صحیح □", columnWidth, size, weight),
                left: wrapText("ب) غلط □", columnWidth, size, weight),
              }],
            };
          }
          function optionLayoutLineCount(layout) {
            return (layout && layout.rows ? layout.rows : []).reduce(function (count, row) {
              return count + Math.max((row.right || []).length, (row.left || []).length, 1);
            }, 0);
          }
          function drawOptionLayout(layout, leftX, rightX, topY, size, lineHeight, weight) {
            if (!layout || !layout.rows || !layout.rows.length) return topY;
            var gap = Number(layout.gap || 24);
            var fullWidth = Math.max(0, rightX - leftX);
            var columnWidth = Math.max(60, (fullWidth - gap) / 2);
            var leftColumnRight = leftX + columnWidth;
            var currentY = topY;
            layout.rows.forEach(function (row) {
              var rightLines = row.right || [];
              var leftLines = row.left || [];
              drawLines(rightLines, rightX, currentY, size, lineHeight, weight);
              if (!layout.stacked && leftLines.length) {
                drawLines(leftLines, leftColumnRight, currentY, size, lineHeight, weight);
              }
              currentY += Math.max(rightLines.length, leftLines.length, 1) * lineHeight + 3;
            });
            return currentY;
          }
          function drawLines(lines, rightX, topY, size, lineHeight, weight) {
            setFont(size, weight);
            ctx.direction = "rtl";
            ctx.textAlign = "right";
            (lines || []).forEach(function (line, index) {
              ctx.fillText(String(line), rightX, topY + index * lineHeight);
            });
          }
          function drawText(value, x, topY, size, align, weight) {
            setFont(size, weight);
            ctx.direction = "rtl";
            ctx.textAlign = align || "right";
            ctx.fillText(text(value), x, topY);
          }
          function drawFittedText(value, x, topY, maxWidth, size, minSize, align, weight) {
            var current = size;
            var valueText = text(value);
            setFont(current, weight);
            while (current > (minSize || 7) && ctx.measureText(valueText).width > maxWidth) {
              current -= 0.25;
              setFont(current, weight);
            }
            ctx.direction = "rtl";
            ctx.textAlign = align || "right";
            ctx.fillText(valueText, x, topY);
          }
          function rect(x, top, w, h, fill) {
            if (fill) {
              ctx.fillStyle = fill;
              ctx.fillRect(x, top, w, h);
            }
            ctx.strokeStyle = "#0f172a";
            ctx.lineWidth = 0.8;
            ctx.strokeRect(x, top, w, h);
          }
          function newCanvas() {
            canvas = document.createElement("canvas");
            canvas.width = Math.round(pageW * canvasScale);
            canvas.height = Math.round(pageH * canvasScale);
            ctx = canvas.getContext("2d", { alpha: false });
            ctx.setTransform(canvasScale, 0, 0, canvasScale, 0, 0);
            ctx.fillStyle = "#ffffff";
            ctx.fillRect(0, 0, pageW, pageH);
            ctx.imageSmoothingEnabled = true;
            ctx.imageSmoothingQuality = "high";
            ctx.lineJoin = "miter";
            ctx.lineCap = "butt";
            y = 24;
          }
          function commitPage() {
            if (!canvas) return;
            pages.push(canvas);
            canvas = null;
            ctx = null;
          }
          function drawHeader() {
            var headerH = isAnswers ? 116 : 96;
            ctx.strokeStyle = "#0f172a";
            ctx.lineWidth = 1.2;
            ctx.strokeRect(margin, y, width, headerH);
            var third = width / 3;
            var top = y + 17;

            drawFittedText("وزارت آموزش و پرورش", margin + width - 10, top, third - 16, 9, 7.5, "right", 500);
            drawFittedText(exam.schoolName || "مدرسه", margin + width - 10, top + 17, third - 16, 8.5, 7, "right", 400);
            if (isAnswers) {
              drawFittedText("طراح آزمون: " + (exam.teacherName || exam.managerName || "مدیر مدرسه"), margin + width - 10, top + 34, third - 16, 8.5, 6.75, "right", 400);
            }

            drawText("بسمه تعالی", margin + width / 2, top, 10, "center", 500);
            drawFittedText(isAnswers ? "کلید و راهنمای تصحیح آزمون" : (exam.schoolName || "نمونه سوال"), margin + width / 2, top + 20, third - 12, 11, 8, "center", 500);
            if (isAnswers) drawFittedText(exam.schoolName || "", margin + width / 2, top + 38, third - 12, 8.5, 7, "center", 400);

            drawFittedText("تاریخ آزمون: " + (exam.examDate || "................"), margin + third - 10, top, third - 16, 8.5, 7, "right", 400);
            drawFittedText("مدت آزمون: " + (exam.duration || 0) + " دقیقه", margin + third - 10, top + 17, third - 16, 8.5, 7, "right", 400);
            if (isAnswers) drawFittedText("کلاس: " + (exam.className || "................"), margin + third - 10, top + 34, third - 16, 8.5, 7, "right", 400);

            var lineY = y + headerH - 34;
            ctx.strokeStyle = "#0f172a";
            ctx.lineWidth = 0.6;
            ctx.beginPath();
            ctx.moveTo(margin + 10, lineY);
            ctx.lineTo(margin + width - 10, lineY);
            ctx.stroke();

            if (isAnswers) {
              drawFittedText("نام درس: " + (exam.lessonName || "................"), margin + width - 10, lineY + 12, third - 16, 8.5, 7, "right", 400);
              drawFittedText("نوبت امتحانی: " + examType(exam.examType), margin + width / 2, lineY + 12, third - 16, 8.5, 7, "center", 400);
              drawFittedText("بارم کل: " + totalScore + " نمره", margin + third - 10, lineY + 12, third - 16, 8.5, 7, "right", 400);
            } else {
              var identity = "نام و نام خانوادگی: ........................ | نام پدر: ............ | کلاس: " + (exam.className || "........") + " | رشته: " + major(exam.major) + " | درس: " + (exam.lessonName || "........") + " | نوبت: " + examType(exam.examType) + " | بارم: " + totalScore;
              drawFittedText(identity, margin + width - 10, lineY + 12, width - 20, 8.5, 6.5, "right", 400);
            }
            y += headerH + 14;
          }
          function drawTableHeader() {
            var h = 30;
            rect(scoreX, y, scoreW, h, "#f1f5f9");
            rect(bodyX, y, bodyW, h, "#f1f5f9");
            if (isAnswers) rect(numberX, y, rowNumberW, h, "#f1f5f9");
            drawText("بارم", scoreX + scoreW / 2, y + 9, 9, "center", 500);
            drawText(isAnswers ? "پاسخ صحیح و راهنمای تصحیح" : "شرح سوالات", bodyX + bodyW / 2, y + 9, 9, "center", 500);
            if (isAnswers) drawText("ردیف", numberX + rowNumberW / 2, y + 9, 9, "center", 500);
            y += h;
          }
          function startPage() {
            newCanvas();
            drawHeader();
            drawTableHeader();
          }
          function addPage() {
            commitPage();
            startPage();
          }

          startPage();
          questions.forEach(function (question, index) {
            var main = isAnswers ? (question.answer || "—") : (question.question || "—");
            var bodyFont = 8.8;
            var bodyLineHeight = 14;
            var displayMain = isAnswers ? main : ((question.number || index + 1) + "- " + main);
            var mainLines = wrapText(displayMain, bodyW - 18, bodyFont, 400);
            var extraLines = [];
            var optionLayout = null;
            if (!isAnswers && Array.isArray(question.choices) && question.choices.length) {
              optionLayout = choiceLayout(question.choices, bodyW - 18, bodyFont, 400);
            } else if (!isAnswers && question.type === "true_false") {
              optionLayout = trueFalseLayout(bodyW - 18, bodyFont, 400);
            } else if (!isAnswers && question.type === "short_answer") {
              extraLines = ["پاسخ: ................................................................................"];
            }
            var optionLineCount = optionLayoutLineCount(optionLayout);
            var optionGapHeight = optionLayout && optionLayout.rows ? optionLayout.rows.length * 3 : 0;
            var lineCount = mainLines.length + optionLineCount + extraLines.length;
            var minHeight = question.type === "essay" && !isAnswers ? 86 : (question.type === "short_answer" && !isAnswers ? 62 : 48);
            var rowH = Math.max(minHeight, 17 + lineCount * bodyLineHeight + optionGapHeight + (extraLines.length ? 7 : 0));
            if (y + rowH > pageH - 46) addPage();

            rect(scoreX, y, scoreW, rowH);
            rect(bodyX, y, bodyW, rowH);
            if (isAnswers) rect(numberX, y, rowNumberW, rowH);
            drawText(question.score || 0, scoreX + scoreW / 2, y + rowH / 2 - 5, 9, "center", 400);
            if (isAnswers) drawText(question.number || index + 1, numberX + rowNumberW / 2, y + rowH / 2 - 5, 9, "center", 400);
            drawLines(mainLines, bodyRightX - 9, y + 11, bodyFont, bodyLineHeight, 400);
            var responseTop = y + 14 + mainLines.length * bodyLineHeight;
            if (optionLayout) {
              drawOptionLayout(optionLayout, bodyX + 9, bodyRightX - 9, responseTop, bodyFont, bodyLineHeight, 400);
            } else if (extraLines.length) {
              drawLines(extraLines, bodyRightX - 9, responseTop, bodyFont, bodyLineHeight, 400);
            }
            y += rowH;
          });

          commitPage();

          pages.forEach(function (page, index) {
            var pageCtx = page.getContext("2d", { alpha: false });
            pageCtx.setTransform(canvasScale, 0, 0, canvasScale, 0, 0);
            pageCtx.strokeStyle = "#cbd5e1";
            pageCtx.lineWidth = 0.5;
            pageCtx.beginPath();
            pageCtx.moveTo(margin, pageH - 29);
            pageCtx.lineTo(pageW - margin, pageH - 29);
            pageCtx.stroke();
            pageCtx.font = "400 7.5px " + fontFamily;
            pageCtx.fillStyle = "#475569";
            pageCtx.textBaseline = "top";
            pageCtx.direction = "rtl";
            pageCtx.textAlign = "center";
            pageCtx.fillText(text("صفحه " + (index + 1) + " از " + pages.length), pageW / 2, pageH - 22);
          });

          resolve({ pages: pages, pageWidth: pageW, pageHeight: pageH });
        } catch (error) {
          reject(error);
        }
      }

      if (document.fonts && typeof document.fonts.load === "function") {
        Promise.race([
          document.fonts.load('10px "Vazirmatn"'),
          new Promise(function (fontResolve) { window.setTimeout(fontResolve, 900); }),
        ]).then(renderPages, renderPages);
      } else {
        renderPages();
      }
    });
  }

  function examPaperPreview(o) {
    return renderExamPaperPages(o).then(function (result) {
      return {
        pageWidth: result.pageWidth,
        pageHeight: result.pageHeight,
        pages: result.pages.map(function (canvas, index) {
          return {
            number: index + 1,
            total: result.pages.length,
            dataUrl: canvas.toDataURL("image/png"),
          };
        }),
      };
    });
  }

  function examPaperPdf(o) {
    o = o || {};
    var Ctor = jsPDFCtor();
    var questions = Array.isArray(o.questions) ? o.questions : [];
    if (!Ctor || !questions.length) {
      if (o.fallbackHtml) printHtml(o.fallbackHtml, { title: o.kind === "answers" ? "راهنمای تصحیح" : "نمونه سوال" });
      return Promise.resolve({ fallback: true, pages: 0 });
    }

    function safeName(value) {
      var name = String(value || "آزمون").replace(/[\/:*?"<>|]+/g, " ").replace(/\s+/g, "-").replace(/-+/g, "-").replace(/^-|-$/g, "");
      return /\.pdf$/i.test(name) ? name : name + ".pdf";
    }

    if (typeof o.onProgress === "function") {
      o.onProgress(5, "در حال صفحه‌بندی محتوای آزمون...");
    }
    return renderExamPaperPages(o).then(function (result) {
      if (!result.pages.length) throw new Error("exam_paper_pages_empty");
      var pageCount = result.pages.length;
      var doc = new Ctor({ orientation: "portrait", unit: "pt", format: "a4", compress: true });
      result.pages.forEach(function (canvas, index) {
        if (typeof o.onProgress === "function") {
          o.onProgress(62 + Math.round((index / pageCount) * 32), "در حال افزودن صفحه " + (index + 1) + " از " + pageCount + " به PDF...");
        }
        if (index > 0) doc.addPage();
        doc.addImage(canvas.toDataURL("image/png"), "PNG", 0, 0, result.pageWidth, result.pageHeight, undefined, "SLOW");
        canvas.width = 1;
        canvas.height = 1;
      });
      if (typeof o.onProgress === "function") o.onProgress(98, "در حال ذخیره فایل آزمون...");
      doc.save(safeName(o.filename || (o.kind === "answers" ? "راهنمای-تصحیح" : "نمونه-سوال")));
      if (typeof o.onDone === "function") o.onDone();
      return { fallback: false, pages: pageCount };
    }).catch(function (error) {
      if (o.fallbackHtml) printHtml(o.fallbackHtml, { title: o.kind === "answers" ? "راهنمای تصحیح" : "نمونه سوال" });
      throw error;
    });
  }

  // ---- Discipline book PDF: two student cards per A4 page ----------------
  function disciplineBookPdf(o) {
    o = o || {};
    var Ctor = jsPDFCtor();
    var blocks = o.blocks || [];

    if (!Ctor || !blocks.length) {
      if (o.fallbackHtml) printHtml(o.fallbackHtml, { title: o.title || "" });
      return Promise.resolve({ fallback: true, pages: 0 });
    }

    var c = cfg();
    var doc = new Ctor({ orientation: "portrait", unit: "pt", format: (c.paper || "a4").toLowerCase() });
    var hasFa = ensureVazir(doc);
    if (hasFa) doc.setFont("Vazirmatn", "normal");

    var accent = hexToRgb(c.accent || "#334155");
    var accentSoft = mix(accent, [255, 255, 255], 0.88);
    var border = [203, 213, 225];
    var ink = [30, 41, 59];
    var muted = [100, 116, 139];

    var pageW = doc.internal.pageSize.getWidth();
    var pageH = doc.internal.pageSize.getHeight();
    var marginX = 30;
    var topY = 30;
    var gap = 24;
    var blockH = (pageH - topY - 24 - gap) / 2;
    var usableW = pageW - marginX * 2;
    var logoUrl = c.logoUrl || "";
    var managerName = c.managerName || "مدیر مدرسه";

    var imgCache = {};
    var urls = [];
    var resolveDisciplinePdf;
    var rejectDisciplinePdf;
    var disciplineStarted = false;
    var disciplinePromise = new Promise(function (resolve, reject) {
      resolveDisciplinePdf = resolve;
      rejectDisciplinePdf = reject;
    });

    function disciplineProgress(percent, text) {
      if (typeof o.onProgress === "function") o.onProgress(percent, text || "");
    }
    blocks.forEach(function (b) {
      if (b.avatar_url) urls.push(b.avatar_url);
    });
    if (logoUrl) urls.push(logoUrl);
    urls = urls.filter(function (url, idx, arr) { return url && arr.indexOf(url) === idx; });

    function saveName() {
      var base = o.filename || o.title || "دفتر-انضباطی";
      base = String(base)
        .replace(/[\\/:*?"<>|]+/g, " ")
        .replace(/\s+/g, "-")
        .replace(/-+/g, "-")
        .replace(/^-|-$/g, "")
        .trim();
      if (!/\.pdf$/i.test(base)) base += ".pdf";
      return base;
    }

    function renderAll() {
      if (disciplineStarted) return;
      disciplineStarted = true;
      var renderIndex = 0;
      var chunkSize = blocks.length > 20 ? 2 : 4;
      disciplineProgress(12, "اطلاعات آماده شد؛ در حال ساخت صفحات دفتر انضباطی...");

      function renderChunk() {
        try {
          var end = Math.min(blocks.length, renderIndex + chunkSize);
          for (; renderIndex < end; renderIndex++) {
            var block = blocks[renderIndex];
            var slot = renderIndex % 2;
            if (renderIndex > 0 && slot === 0) doc.addPage();

            var y = topY + slot * (blockH + gap);
            drawDisciplineBlock(block, y, blockH);

            if (slot === 0 && renderIndex < blocks.length - 1) {
              var cutY = topY + blockH + gap / 2;
              doc.setDrawColor(170, 178, 188);
              doc.setLineWidth(0.6);
              if (doc.setLineDashPattern) doc.setLineDashPattern([4, 3], 0);
              doc.line(marginX, cutY, pageW - marginX, cutY);
              if (doc.setLineDashPattern) doc.setLineDashPattern([], 0);
            }
          }

          disciplineProgress(
            12 + Math.round((renderIndex / blocks.length) * 84),
            "در حال ساخت پرونده " + renderIndex + " از " + blocks.length + "..."
          );
          if (renderIndex < blocks.length) {
            window.setTimeout(renderChunk, 0);
            return;
          }

          disciplineProgress(98, "در حال ذخیره دفتر انضباطی...");
          doc.save(saveName());
          if (typeof o.onDone === "function") o.onDone();
          resolveDisciplinePdf({ fallback: false, blocks: blocks.length, pages: Math.ceil(blocks.length / 2) });
        } catch (error) {
          rejectDisciplinePdf(error);
        }
      }

      window.setTimeout(renderChunk, 0);
    }

    function text(txt, x, y, opt) {
      opt = opt || {};
      doc.text(shapeFa(toFa(txt || "")), x, y, opt);
    }

    function displayValue(value) {
      value = String(value == null ? "" : value).trim();
      return value ? value : "................";
    }

    function tableValue(value) {
      return String(value == null ? "" : value).trim();
    }

    function fitLines(txt, maxW, maxLines) {
      var shaped = shapeFa(toFa(txt || ""));
      var lines = doc.splitTextToSize(shaped, maxW);
      lines = lines.slice(0, maxLines || 2);
      return lines;
    }

    function drawQrCode(payload, x, y, size) {
      doc.setFillColor(255, 255, 255);
      doc.setDrawColor(border[0], border[1], border[2]);
      rrect(doc, x, y, size, size, 5, "FD");

      if (payload && window.HSTQRCode && window.HSTQRErrorCorrectLevel) {
        try {
          var qr = new window.HSTQRCode(-1, window.HSTQRErrorCorrectLevel.M);
          qr.addData(String(payload));
          qr.make();
          var count = qr.getModuleCount();
          var quiet = 4;
          var available = size - 8;
          var moduleSize = available / (count + quiet * 2);
          var originX = x + 4 + quiet * moduleSize;
          var originY = y + 4 + quiet * moduleSize;
          doc.setFillColor(15, 23, 42);
          for (var row = 0; row < count; row++) {
            for (var col = 0; col < count; col++) {
              if (qr.isDark(row, col)) {
                doc.rect(
                  originX + col * moduleSize,
                  originY + row * moduleSize,
                  moduleSize + 0.06,
                  moduleSize + 0.06,
                  "F"
                );
              }
            }
          }
          return;
        } catch (error) {}
      }

      doc.setTextColor(muted[0], muted[1], muted[2]);
      doc.setFontSize(5.5);
      text("نسخه دیجیتال", x + size / 2, y + size / 2 + 2, { align: "center", maxWidth: size - 8 });
    }

    function drawAvatar(block, x, y, w, h) {
      h = h || Math.round(w * 4 / 3);
      var img = block.avatar_url ? imgCache[block.avatar_url] : null;

      doc.setFillColor(248, 250, 252);
      doc.setDrawColor(border[0], border[1], border[2]);
      rrect(doc, x, y, w, h, 7, "FD");

      if (img) {
        try {
          var scale = 3;
          var cnv = document.createElement("canvas");
          cnv.width = Math.round(w * scale);
          cnv.height = Math.round(h * scale);
          var ctx = cnv.getContext("2d");
          var rr = 7 * scale;

          ctx.save();
          ctx.beginPath();
          ctx.moveTo(rr, 0);
          ctx.lineTo(cnv.width - rr, 0);
          ctx.quadraticCurveTo(cnv.width, 0, cnv.width, rr);
          ctx.lineTo(cnv.width, cnv.height - rr);
          ctx.quadraticCurveTo(cnv.width, cnv.height, cnv.width - rr, cnv.height);
          ctx.lineTo(rr, cnv.height);
          ctx.quadraticCurveTo(0, cnv.height, 0, cnv.height - rr);
          ctx.lineTo(0, rr);
          ctx.quadraticCurveTo(0, 0, rr, 0);
          ctx.closePath();
          ctx.clip();

          var iw = img.width || w;
          var ih = img.height || h;
          var targetRatio = w / h;
          var sourceRatio = iw / ih;
          var dw = cnv.width;
          var dh = cnv.height;
          var dx = 0;
          var dy = 0;

          // Show the original WordPress image inside the 3x4 portrait frame without aggressive cropping.
          // If the source is already 3x4, it fills the frame; otherwise it is fitted with white padding.
          if (sourceRatio > targetRatio) {
            dh = cnv.width / sourceRatio;
            dy = (cnv.height - dh) / 2;
          } else if (sourceRatio < targetRatio) {
            dw = cnv.height * sourceRatio;
            dx = (cnv.width - dw) / 2;
          }

          ctx.fillStyle = "#ffffff";
          ctx.fillRect(0, 0, cnv.width, cnv.height);

          var inset = 2 * scale;
          var safeW = cnv.width - inset * 2;
          var safeH = cnv.height - inset * 2;
          var safeRatio = safeW / safeH;
          dx = inset;
          dy = inset;
          dw = safeW;
          dh = safeH;

          if (sourceRatio > safeRatio) {
            dh = safeW / sourceRatio;
            dy = inset + (safeH - dh) / 2;
          } else if (sourceRatio < safeRatio) {
            dw = safeH * sourceRatio;
            dx = inset + (safeW - dw) / 2;
          }

          ctx.drawImage(img, 0, 0, iw, ih, dx, dy, dw, dh);
          ctx.restore();

          doc.addImage(cnv.toDataURL("image/png"), "PNG", x + 0.7, y + 0.7, w - 1.4, h - 1.4);
        } catch (e) {
          try { doc.addImage(img, "JPEG", x, y, w, h); } catch (ignore) {}
        }

        doc.setDrawColor(border[0], border[1], border[2]);
        rrect(doc, x, y, w, h, 7, "S");
        return;
      }

      doc.setTextColor(accent[0], accent[1], accent[2]);
      doc.setFontSize(15);
      text(
        initialsOf(block.student_name || block.name || "", block.first_name || "", block.last_name || "", block.initials || block.initial || ""),
        x + w / 2,
        y + h / 2 + 5,
        { align: "center" }
      );
    }

    function drawMetaChip(label, value, x, y, w, h) {
      doc.setFillColor(248, 250, 252);
      doc.setDrawColor(border[0], border[1], border[2]);
      doc.setLineWidth(0.7);
      rrect(doc, x, y, w, h, 5, "FD");

      var labelText = String(label || "");
      var valText = displayValue(value);
      var rightX = x + w - 5;
      var baseY = y + h / 2 + 3;

      doc.setFontSize(7.2);
      doc.setTextColor(muted[0], muted[1], muted[2]);
      text(labelText, rightX, baseY, { align: "right", maxWidth: w - 10 });

      var shapedLabel = shapeFa(toFa(labelText));
      var labelW = doc.getTextWidth(shapedLabel);
      var colonX = rightX - labelW - 2;
      doc.text(":", colonX, baseY, { align: "right" });

      var valueRightX = colonX - 6;
      doc.setTextColor(51, 65, 85);
      text(valText, valueRightX, baseY, { align: "right", maxWidth: Math.max(18, valueRightX - x - 4) });
    }

    function summaryItems(block) {
      var items = block && block.summary && Array.isArray(block.summary.items)
        ? block.summary.items
        : [];
      return items.filter(function (item) {
        return item && typeof item === "object";
      });
    }

    function summaryTotal(items) {
      return items.reduce(function (sum, item) {
        return sum + (Number(item.count) || 0);
      }, 0);
    }

    function donutDataUrl(items, total, size) {
      var scale = 4;
      var canvas = document.createElement("canvas");
      canvas.width = size * scale;
      canvas.height = size * scale;
      var ctx = canvas.getContext("2d");
      ctx.scale(scale, scale);
      ctx.imageSmoothingEnabled = true;

      var cx = size / 2;
      var cy = size / 2;
      var radius = size / 2 - 2;
      var innerRadius = radius * 0.58;
      var start = -Math.PI / 2;

      ctx.clearRect(0, 0, size, size);
      if (total <= 0) {
        ctx.beginPath();
        ctx.fillStyle = "#e2e8f0";
        ctx.arc(cx, cy, radius, 0, Math.PI * 2);
        ctx.fill();
      } else {
        items.forEach(function (item) {
          var count = Number(item.count) || 0;
          if (count <= 0) return;
          var slice = (count / total) * Math.PI * 2;
          ctx.beginPath();
          ctx.moveTo(cx, cy);
          ctx.fillStyle = item.color || "#cbd5e1";
          ctx.arc(cx, cy, radius, start, start + slice);
          ctx.closePath();
          ctx.fill();
          start += slice;
        });
      }

      ctx.beginPath();
      ctx.fillStyle = "#ffffff";
      ctx.arc(cx, cy, innerRadius, 0, Math.PI * 2);
      ctx.fill();
      return canvas.toDataURL("image/png");
    }

    function drawSummaryLegend(item, x, y, w, h) {
      var rgb = hexToRgb(item.color || "#cbd5e1");
      doc.setFillColor(255, 255, 255);
      doc.setDrawColor(border[0], border[1], border[2]);
      doc.setLineWidth(0.7);
      rrect(doc, x, y, w, h, 5, "FD");
      doc.setFillColor(rgb[0], rgb[1], rgb[2]);
      doc.circle(x + w - 8, y + h / 2, 2.8, "F");
      doc.setTextColor(71, 85, 105);
      doc.setFontSize(6.2);
      text(item.label || "", x + w - 15, y + h / 2 + 2.2, { align: "right", maxWidth: w - 40 });
      doc.setTextColor(accent[0], accent[1], accent[2]);
      doc.setFontSize(6.5);
      text(String(Number(item.count) || 0), x + 8, y + h / 2 + 2.2, { align: "left", maxWidth: 18 });
    }

    function metricNumber(value, decimals) {
      if (value === null || value === undefined || value === "" || !isFinite(Number(value))) return "ثبت نشده";
      var output = Number(value).toFixed(decimals == null ? 2 : decimals);
      return output.replace(/\.0+$/, "").replace(/(\.\d*?)0+$/, "$1");
    }

    function attendanceMetricValue(summary) {
      summary = summary || {};
      var label = summary.attendance_label || "بدون داده";
      var percentage = summary.attendance_percentage;
      return {
        label: label,
        percentage: (percentage === null || percentage === undefined || percentage === "" || !isFinite(Number(percentage)))
          ? ""
          : metricNumber(percentage, 1) + "٪"
      };
    }

    function metricTone(tone) {
      if (tone === "warning") return { bg: [254, 243, 199], fg: [217, 119, 6] };
      if (tone === "danger") return { bg: [254, 226, 226], fg: [220, 38, 38] };
      if (tone === "muted") return { bg: [226, 232, 240], fg: [100, 116, 139] };
      return { bg: [209, 250, 229], fg: [16, 185, 129] };
    }

    function drawSharedMetricIcon(kind, x, y, size, color) {
      var scale = size / 24;
      doc.setDrawColor(color[0], color[1], color[2]);
      doc.setLineWidth(1.2);

      function px(value) { return x + value * scale; }
      function py(value) { return y + value * scale; }

      if (kind === "attendance") {
        rrect(doc, px(4), py(5), 13 * scale, 14 * scale, 0.8 * scale, "S");
        doc.line(px(8), py(11), px(10.5), py(13.5));
        doc.line(px(10.5), py(13.5), px(15), py(9));
        doc.line(px(20), py(5), px(20), py(19));
        return;
      }

      var points = [];
      for (var i = 0; i < 10; i++) {
        var angle = -Math.PI / 2 + i * Math.PI / 5;
        var radius = i % 2 === 0 ? 9 : 4.2;
        points.push([px(12 + Math.cos(angle) * radius), py(12 + Math.sin(angle) * radius)]);
      }
      for (var p = 0; p < points.length; p++) {
        var next = points[(p + 1) % points.length];
        doc.line(points[p][0], points[p][1], next[0], next[1]);
      }
    }


    function drawMetricCard(x, y, w, h, title, value, kind, tone) {
      doc.setFillColor(248, 250, 252);
      doc.setDrawColor(border[0], border[1], border[2]);
      doc.setLineWidth(0.7);
      rrect(doc, x, y, w, h, 7, "FD");

      var iconSize = 18;
      var iconX = x + 8;
      var iconY = y + (h - iconSize) / 2;
      var toneColor = kind === "attendance" ? metricTone(tone).fg : accent;
      drawSharedMetricIcon(kind, iconX, iconY, iconSize, toneColor);

      var valueRightX = x + w - 10;
      doc.setTextColor(muted[0], muted[1], muted[2]);
      doc.setFontSize(6.2);
      text(title, valueRightX, y + 12, { align: "right", maxWidth: w - 38 });

      doc.setTextColor(toneColor[0], toneColor[1], toneColor[2]);
      doc.setFontSize(9.2);
      if (kind === "attendance" && value && typeof value === "object") {
        var label = String(value.label || "");
        var percentage = String(value.percentage || "");
        var shapedLabel = shapeFa(toFa(label));
        doc.text(shapedLabel, valueRightX, y + 27, { align: "right", maxWidth: w - 38 });

        if (percentage) {
          var labelWidth = doc.getTextWidth(shapedLabel);
          var percentageRightX = valueRightX - labelWidth - 5;
          doc.text(toFa(percentage), percentageRightX, y + 27, { align: "right", maxWidth: 34 });
        }
      } else {
        text(value, valueRightX, y + 27, { align: "right", maxWidth: w - 38 });
      }
    }

    function drawDisciplineManagerArea(x, y, w, name) {
      var boxW = 58;
      var boxH = 42;
      var gap = 10;
      var stampX = x;
      var signatureX = x + boxW + gap;

      doc.setFillColor(255, 255, 255);
      doc.setDrawColor(border[0], border[1], border[2]);
      doc.setLineWidth(0.7);
      rrect(doc, stampX, y, boxW, boxH, 7, "S");
      rrect(doc, signatureX, y, boxW, boxH, 7, "S");
      doc.setTextColor(muted[0], muted[1], muted[2]);
      doc.setFontSize(6.2);
      text("مهر مدرسه", stampX + boxW / 2, y + 17, { align: "center", maxWidth: boxW - 8 });
      text("امضای مدیر", signatureX + boxW / 2, y + 13, { align: "center", maxWidth: boxW - 8 });
      doc.setTextColor(accent[0], accent[1], accent[2]);
      doc.setFontSize(5.4);
      text(name || managerName, signatureX + boxW / 2, y + 29, { align: "center", maxWidth: boxW - 8 });
    }

    function drawDisciplineBlock(block, top, maxH) {
      var x = marginX;
      var y = top;
      var R = 9;

      doc.setDrawColor(border[0], border[1], border[2]);
      doc.setLineWidth(0.7);
      rrect(doc, x, y, usableW, maxH, R, "S");

      // Header inspired by compact student report header: QR left, school center, student identity right.
      var school = c.schoolName || "";
      var logoImg = logoUrl ? imgCache[logoUrl] : null;
      var headX = x + 10;
      var headY = y + 10;
      var headW = usableW - 20;
      var headH = 92;

      doc.setFillColor(248, 250, 252);
      doc.setDrawColor(border[0], border[1], border[2]);
      doc.setLineWidth(0.7);
      rrect(doc, headX, headY, headW, headH, 8, "FD");

      var qrSize = 58;
      var qrX = headX + 10;
      var qrY = headY + 8;
      drawQrCode(block.download_url || "", qrX, qrY, qrSize);
      doc.setFontSize(5.8);
      doc.setTextColor(muted[0], muted[1], muted[2]);
      text("دریافت نسخه دیجیتال", qrX + qrSize / 2, qrY + qrSize + 12, { align: "center", maxWidth: qrSize + 18 });

      var centerX = headX + headW / 2;
      if (logoImg) {
        var logoH = 22;
        var ratio = (logoImg.width && logoImg.height) ? (logoImg.width / logoImg.height) : 1;
        var logoW = logoH * ratio;
        if (logoW > 38) {
          logoW = 38;
          logoH = logoW / ratio;
        }
        try { doc.addImage(logoImg, centerX - logoW / 2, headY + 9, logoW, logoH); } catch (e) {}
      }

      doc.setTextColor(accent[0], accent[1], accent[2]);
      doc.setFontSize(10.5);
      if (school) text(school, centerX, headY + 43, { align: "center", maxWidth: 190 });
      doc.setTextColor(ink[0], ink[1], ink[2]);
      doc.setFontSize(8);
      text("دفتر انضباطی دانش‌آموز", centerX, headY + 58, { align: "center", maxWidth: 170 });
      doc.setTextColor(muted[0], muted[1], muted[2]);
      doc.setFontSize(6.5);
      text("ثبت و پیگیری موارد انضباطی", centerX, headY + 70, { align: "center", maxWidth: 170 });

      var disciplineYearText = block.academic_year || (block.term_name ? ("سال تحصیلی " + block.term_name) : "");
      if (disciplineYearText) {
        doc.setFontSize(6.2);
        text(disciplineYearText, centerX, headY + 83, { align: "center", maxWidth: 170 });
      }

      var avW = 42;
      var avH = 56;
      var avX = headX + headW - 12 - avW;
      var avY = headY + 16;
      drawAvatar(block, avX, avY, avW, avH);
      doc.setTextColor(ink[0], ink[1], ink[2]);
      doc.setFontSize(10.2);
      text(block.student_name || "", avX - 8, avY + 14, { align: "right", maxWidth: 150 });
      doc.setTextColor(muted[0], muted[1], muted[2]);
      doc.setFontSize(6.6);
      text("کد ملی: " + displayValue(block.national_code), avX - 8, avY + 30, { align: "right", maxWidth: 150 });
      text("کلاس: " + displayValue(block.classes), avX - 8, avY + 44, { align: "right", maxWidth: 150 });

      y = headY + headH + 9;

      var chipGap = 5;
      var chipW = (usableW - 20 - chipGap * 2) / 3;
      drawMetaChip("نام پدر", block.father_name, x + usableW - 10 - chipW, y, chipW, 18);
      drawMetaChip("موبایل دانش‌آموز", block.phone, x + usableW - 10 - chipW * 2 - chipGap, y, chipW, 18);
      drawMetaChip("تاریخ تولد", block.birthdate, x + 10, y, chipW, 18);
      drawMetaChip("موبایل پدر", block.father_phone, x + usableW - 10 - chipW, y + 23, chipW, 18);
      drawMetaChip("موبایل مادر", block.mother_phone, x + usableW - 10 - chipW * 2 - chipGap, y + 23, chipW, 18);
      drawMetaChip("کلاس", block.classes, x + 10, y + 23, chipW, 18);

      y = y + 48;

      var metricSummary = block.summary || {};
      var metricGap = 7;
      var metricH = 34;
      var metricW = (usableW - 20 - metricGap) / 2;
      var metricRightX = x + usableW - 10 - metricW;
      var metricLeftX = x + 10;
      drawMetricCard(metricRightX, y, metricW, metricH, "وضعیت حضور و غیاب", attendanceMetricValue(metricSummary), "attendance", metricSummary.attendance_tone || "muted");
      drawMetricCard(metricLeftX, y, metricW, metricH, "معدل کل انضباط", metricNumber(metricSummary.discipline_average, 2), "score", "info");
      y += metricH + 6;

      // Summary chart based on discipline records and score-entry attendance.
      var items = summaryItems(block);
      var total = summaryTotal(items);
      var summaryX = x + 10;
      var summaryY = y;
      var summaryW = usableW - 20;
      var summaryH = 52;
      doc.setFillColor(248, 250, 252);
      doc.setDrawColor(border[0], border[1], border[2]);
      doc.setLineWidth(0.7);
      rrect(doc, summaryX, summaryY, summaryW, summaryH, 7, "FD");

      var chartSize = 42;
      var chartX = summaryX + summaryW - chartSize - 7;
      var chartY = summaryY + 5;
      try {
        doc.addImage(donutDataUrl(items, total, 180), "PNG", chartX, chartY, chartSize, chartSize);
      } catch (e) {}

      doc.setTextColor(accent[0], accent[1], accent[2]);
      doc.setFontSize(10.5);
      text(String(total || 0), chartX + chartSize / 2, chartY + 19, { align: "center", maxWidth: 20 });
      doc.setTextColor(muted[0], muted[1], muted[2]);
      doc.setFontSize(5.8);
      text("جمع", chartX + chartSize / 2, chartY + 29, { align: "center", maxWidth: 22 });

      var titleRightX = chartX - 8;
      doc.setTextColor(muted[0], muted[1], muted[2]);
      doc.setFontSize(6.2);
      text("نمودار موارد انضباطی، غیبت و تأخیر", titleRightX, summaryY + 10, { align: "right", maxWidth: Math.max(120, titleRightX - summaryX - 8) });

      var legendX = summaryX + 8;
      var legendY = summaryY + 16;
      var legendGap = 5;
      var legendH = 13;
      var legendAreaW = Math.max(210, chartX - legendX - 8);
      var legendW = Math.floor((legendAreaW - legendGap * 2) / 3);
      var legendRightX = legendX + legendAreaW;
      items.slice(0, 5).forEach(function (item, idx) {
        var col = idx % 3;
        var row = Math.floor(idx / 3);
        var itemX = legendRightX - legendW - col * (legendW + legendGap);
        drawSummaryLegend(item, itemX, legendY + row * (legendH + 4), legendW, legendH);
      });

      // Records table. Footer position is derived from the real table end so
      // attendance totals and manager boxes can never overlap table rows.
      var records = block.records || [];
      var maxRecordRows = 4;
      var visible = records.slice(0, maxRecordRows);
      var omitted = Math.max(0, records.length - visible.length);
      var outRows = visible.slice();
      if (omitted) outRows.push({ _omitted: true });
      while (outRows.length < 4) outRows.push(null);

      var tableX = x + 10;
      var tableW = usableW - 20;
      var tableTop = summaryY + summaryH + 6;
      var tableHeadH = 17;
      var tableHeadGap = 3;
      var footerGap = 11;
      var managerBoxH = 42;
      var bottomSafe = 10;
      var maxRowsHeight = (top + maxH - bottomSafe - managerBoxH - footerGap) - (tableTop + tableHeadH + tableHeadGap);
      var rowH = Math.max(8.5, Math.min(13, maxRowsHeight / outRows.length));
      var tableEndY = tableTop + tableHeadH + tableHeadGap + rowH * outRows.length;
      var separatorY = tableEndY + 5;
      var footerY = tableEndY + footerGap;

      var widths = [30, 52, 48, 48, 108, tableW - 30 - 52 - 48 - 48 - 108];
      var heads = ["ردیف", "تاریخ", "نوع", "شدت", "عنوان", "توضیحات"];

      doc.setFillColor(accent[0], accent[1], accent[2]);
      doc.setDrawColor(accent[0], accent[1], accent[2]);
      rrect(doc, tableX, tableTop, tableW, tableHeadH, 5, "F");
      doc.setTextColor(255);
      doc.setFontSize(7);
      var right = tableX + tableW;
      for (var h = 0; h < heads.length; h++) {
        var w = widths[h];
        text(heads[h], right - w / 2, tableTop + tableHeadH / 2 + 2.2, { align: "center", maxWidth: w - 4 });
        right -= w;
      }

      function drawRow(row, rowIndex, isOmitted) {
        var yy = tableTop + tableHeadH + tableHeadGap + rowIndex * rowH;
        var rr = tableX + tableW;
        var typeKey = row && row.type_key ? String(row.type_key) : "";
        var fill = rowIndex % 2 ? [250, 252, 255] : [255, 255, 255];
        if (!isOmitted && typeKey === "violation") fill = [254, 242, 242];
        else if (!isOmitted && typeKey === "warning") fill = [255, 251, 235];
        else if (!isOmitted && typeKey === "praise") fill = [236, 253, 245];
        else if (!isOmitted && typeKey === "absence") fill = [248, 250, 252];
        else if (!isOmitted && typeKey === "late") fill = [239, 246, 255];

        doc.setFillColor(fill[0], fill[1], fill[2]);
        doc.rect(tableX, yy, tableW, rowH, "F");
        var cells = isOmitted
          ? [String(rowIndex + 1), "", "", "", "ادامه دارد", omitted + " مورد دیگر ثبت شده است"]
          : [
              String(rowIndex + 1),
              tableValue(row && row.date ? row.date : ""),
              tableValue(row && row.type ? row.type : ""),
              tableValue(row && row.severity ? row.severity : ""),
              tableValue(row && row.title ? row.title : ""),
              tableValue(row && row.description ? row.description : ""),
            ];

        doc.setTextColor(ink[0], ink[1], ink[2]);
        doc.setFontSize(6.1);
        for (var ci = 0; ci < widths.length; ci++) {
          var cw = widths[ci];
          var left = rr - cw;
          doc.setDrawColor(border[0], border[1], border[2]);
          doc.setLineWidth(0.7);
          doc.rect(left, yy, cw, rowH);
          var lines = fitLines(cells[ci], cw - 4, ci === 5 ? 2 : 1);
          var lineY = yy + rowH / 2 - ((lines.length - 1) * 3) + 2.2;
          lines.forEach(function (line) {
            doc.text(line, left + cw / 2, lineY, { align: "center", maxWidth: cw - 4 });
            lineY += 5.8;
          });
          rr -= cw;
        }
      }

      outRows.forEach(function (row, idx) {
        drawRow(row, idx, !!(row && row._omitted));
      });

      // Attendance totals and manager approval area.
      var summary = block.summary || {};
      doc.setDrawColor(border[0], border[1], border[2]);
      doc.setLineWidth(0.7);
      doc.line(x + 12, separatorY, x + usableW - 12, separatorY);
      doc.setTextColor(muted[0], muted[1], muted[2]);
      doc.setFontSize(5.8);
      text(
        "غیبت: " + (Number(summary.absence_count) || 0) +
        " | تأخیر: " + (Number(summary.late_count) || 0),
        x + usableW - 12,
        footerY + 10,
        { align: "right", maxWidth: 215 }
      );
      text("کارنامه انضباطی دانش‌آموز", x + usableW - 12, footerY + 25, { align: "right", maxWidth: 180 });
      drawDisciplineManagerArea(x + 12, footerY, usableW - 24, block.manager_name || managerName);

    }

    disciplineProgress(5, "در حال آماده‌سازی تصاویر و اطلاعات دفتر انضباطی...");
    if (!urls.length) {
      renderAll();
      return disciplinePromise;
    }

    var pending = urls.length;
    urls.forEach(function (url) {
      var im = new Image();
      im.crossOrigin = "anonymous";
      im.onload = function () {
        imgCache[url] = im;
        if (--pending === 0) renderAll();
      };
      im.onerror = function () {
        if (--pending === 0) renderAll();
      };
      im.src = url;
    });
    window.setTimeout(function () {
      if (pending > 0) {
        pending = 0;
        renderAll();
      }
    }, 5000);
    return disciplinePromise;
  }

  // ---- Weekly report-card PDF -------------------------------------------
  // Render the exact live HTML/CSS page used by the preview onto a canvas.
  // The PDF therefore has one visual source of truth instead of the removed
  // hand-positioned jsPDF layout.
  function reportCardPdf(o) {
    o = o || {};
    var Ctor = jsPDFCtor();
    var root = o.root && o.root.querySelectorAll ? o.root : null;
    var pages = root ? Array.prototype.slice.call(root.querySelectorAll("[data-hst-report-preview-page]")) : [];

    if (!Ctor || !pages.length) {
      return Promise.reject(new Error("report_card_pdf_dom_unavailable"));
    }

    function safeName(value) {
      var name = String(value || "کارنامه-هفتگی")
        .replace(/[\\/:*?"<>|]+/g, " ")
        .replace(/\s+/g, "-")
        .replace(/-+/g, "-")
        .replace(/^-|-$/g, "");
      return /\.pdf$/i.test(name) ? name : name + ".pdf";
    }

    function waitForAssets(node) {
      var imagePromises = Array.prototype.slice.call(node.querySelectorAll("img")).map(function (img) {
        if (img.complete) return Promise.resolve();
        return new Promise(function (resolve) {
          var finished = false;
          var done = function () {
            if (finished) return;
            finished = true;
            img.removeEventListener("load", done);
            img.removeEventListener("error", done);
            resolve();
          };
          img.addEventListener("load", done, { once: true });
          img.addEventListener("error", done, { once: true });
          window.setTimeout(done, 5000);
        });
      });
      var fontPromise = document.fonts && document.fonts.ready
        ? document.fonts.ready.catch(function () {})
        : Promise.resolve();
      return Promise.all([fontPromise].concat(imagePromises));
    }

    function px(value) {
      var number = parseFloat(value);
      return Number.isFinite(number) ? number : 0;
    }

    function isTransparent(value) {
      value = String(value || "").trim().toLowerCase();
      return !value || value === "transparent" || value === "rgba(0, 0, 0, 0)" || value === "rgba(0,0,0,0)";
    }

    function rectRelative(rect, rootRect) {
      return {
        x: rect.left - rootRect.left,
        y: rect.top - rootRect.top,
        width: rect.width,
        height: rect.height,
        right: rect.right - rootRect.left,
        bottom: rect.bottom - rootRect.top
      };
    }

    function radiusValues(style) {
      return {
        tl: Math.max(0, px(style.borderTopLeftRadius)),
        tr: Math.max(0, px(style.borderTopRightRadius)),
        br: Math.max(0, px(style.borderBottomRightRadius)),
        bl: Math.max(0, px(style.borderBottomLeftRadius))
      };
    }

    function roundedRectPath(context, x, y, width, height, radius) {
      var maxRadius = Math.max(0, Math.min(width / 2, height / 2));
      var tl = Math.min(maxRadius, radius.tl || 0);
      var tr = Math.min(maxRadius, radius.tr || 0);
      var br = Math.min(maxRadius, radius.br || 0);
      var bl = Math.min(maxRadius, radius.bl || 0);
      context.beginPath();
      context.moveTo(x + tl, y);
      context.lineTo(x + width - tr, y);
      if (tr) context.quadraticCurveTo(x + width, y, x + width, y + tr);
      else context.lineTo(x + width, y);
      context.lineTo(x + width, y + height - br);
      if (br) context.quadraticCurveTo(x + width, y + height, x + width - br, y + height);
      else context.lineTo(x + width, y + height);
      context.lineTo(x + bl, y + height);
      if (bl) context.quadraticCurveTo(x, y + height, x, y + height - bl);
      else context.lineTo(x, y + height);
      context.lineTo(x, y + tl);
      if (tl) context.quadraticCurveTo(x, y, x + tl, y);
      else context.lineTo(x, y);
      context.closePath();
    }

    function drawBackground(context, box, style, radius) {
      var color = style.backgroundColor;
      if (!isTransparent(color) && box.width > 0 && box.height > 0) {
        context.save();
        roundedRectPath(context, box.x, box.y, box.width, box.height, radius);
        context.fillStyle = color;
        context.fill();
        context.restore();
      }
    }

    function drawUniformBorder(context, box, radius, width, color) {
      if (!(width > 0) || isTransparent(color)) return;
      var inset = width / 2;
      context.save();
      roundedRectPath(
        context,
        box.x + inset,
        box.y + inset,
        Math.max(0, box.width - width),
        Math.max(0, box.height - width),
        {
          tl: Math.max(0, radius.tl - inset),
          tr: Math.max(0, radius.tr - inset),
          br: Math.max(0, radius.br - inset),
          bl: Math.max(0, radius.bl - inset)
        }
      );
      context.strokeStyle = color;
      context.lineWidth = width;
      context.stroke();
      context.restore();
    }

    function drawBorders(context, box, style, radius) {
      var topWidth = px(style.borderTopWidth);
      var rightWidth = px(style.borderRightWidth);
      var bottomWidth = px(style.borderBottomWidth);
      var leftWidth = px(style.borderLeftWidth);
      var topColor = style.borderTopColor;
      var rightColor = style.borderRightColor;
      var bottomColor = style.borderBottomColor;
      var leftColor = style.borderLeftColor;
      var uniform = Math.abs(topWidth - rightWidth) < 0.05 &&
        Math.abs(topWidth - bottomWidth) < 0.05 &&
        Math.abs(topWidth - leftWidth) < 0.05 &&
        topColor === rightColor && topColor === bottomColor && topColor === leftColor;

      if (uniform) {
        drawUniformBorder(context, box, radius, topWidth, topColor);
        return;
      }

      context.save();
      context.lineCap = "butt";
      if (topWidth > 0 && !isTransparent(topColor)) {
        context.beginPath();
        context.moveTo(box.x, box.y + topWidth / 2);
        context.lineTo(box.x + box.width, box.y + topWidth / 2);
        context.strokeStyle = topColor;
        context.lineWidth = topWidth;
        context.stroke();
      }
      if (rightWidth > 0 && !isTransparent(rightColor)) {
        context.beginPath();
        context.moveTo(box.x + box.width - rightWidth / 2, box.y);
        context.lineTo(box.x + box.width - rightWidth / 2, box.y + box.height);
        context.strokeStyle = rightColor;
        context.lineWidth = rightWidth;
        context.stroke();
      }
      if (bottomWidth > 0 && !isTransparent(bottomColor)) {
        context.beginPath();
        context.moveTo(box.x, box.y + box.height - bottomWidth / 2);
        context.lineTo(box.x + box.width, box.y + box.height - bottomWidth / 2);
        context.strokeStyle = bottomColor;
        context.lineWidth = bottomWidth;
        context.stroke();
      }
      if (leftWidth > 0 && !isTransparent(leftColor)) {
        context.beginPath();
        context.moveTo(box.x + leftWidth / 2, box.y);
        context.lineTo(box.x + leftWidth / 2, box.y + box.height);
        context.strokeStyle = leftColor;
        context.lineWidth = leftWidth;
        context.stroke();
      }
      context.restore();
    }

    function contentBox(box, style) {
      var left = px(style.borderLeftWidth) + px(style.paddingLeft);
      var right = px(style.borderRightWidth) + px(style.paddingRight);
      var top = px(style.borderTopWidth) + px(style.paddingTop);
      var bottom = px(style.borderBottomWidth) + px(style.paddingBottom);
      return {
        x: box.x + left,
        y: box.y + top,
        width: Math.max(0, box.width - left - right),
        height: Math.max(0, box.height - top - bottom)
      };
    }

    function drawReplacedImage(context, image, box, style) {
      if (!image || !(box.width > 0) || !(box.height > 0)) return;
      var sourceWidth = image.naturalWidth || image.videoWidth || image.width || 0;
      var sourceHeight = image.naturalHeight || image.videoHeight || image.height || 0;
      if (!(sourceWidth > 0) || !(sourceHeight > 0)) return;
      var target = contentBox(box, style);
      if (!(target.width > 0) || !(target.height > 0)) return;
      var fit = String(style.objectFit || "fill");
      var drawWidth = target.width;
      var drawHeight = target.height;
      var offsetX = target.x;
      var offsetY = target.y;
      if (fit === "contain" || fit === "cover") {
        var ratio = fit === "contain"
          ? Math.min(target.width / sourceWidth, target.height / sourceHeight)
          : Math.max(target.width / sourceWidth, target.height / sourceHeight);
        drawWidth = sourceWidth * ratio;
        drawHeight = sourceHeight * ratio;
        offsetX += (target.width - drawWidth) / 2;
        offsetY += (target.height - drawHeight) / 2;
      }
      try {
        context.drawImage(image, offsetX, offsetY, drawWidth, drawHeight);
      } catch (error) {}
    }

    function drawCutLine(context, box) {
      context.save();
      context.beginPath();
      context.setLineDash([5, 4]);
      context.moveTo(box.x, box.y + box.height / 2);
      context.lineTo(box.x + box.width, box.y + box.height / 2);
      context.strokeStyle = "#aab2bc";
      context.lineWidth = Math.max(1, box.height || 1);
      context.stroke();
      context.restore();
    }

    function inlineSvgStyles(source, clone) {
      var sourceNodes = [source].concat(Array.prototype.slice.call(source.querySelectorAll("*")));
      var cloneNodes = [clone].concat(Array.prototype.slice.call(clone.querySelectorAll("*")));
      var properties = [
        "color", "fill", "fill-opacity", "stroke", "stroke-width", "stroke-dasharray",
        "stroke-dashoffset", "stroke-linecap", "stroke-linejoin", "stroke-opacity",
        "font-family", "font-size", "font-weight", "font-style", "direction",
        "text-anchor", "dominant-baseline", "opacity", "display", "visibility"
      ];
      sourceNodes.forEach(function (node, index) {
        var copy = cloneNodes[index];
        if (!copy || node.nodeType !== 1) return;
        var computed = window.getComputedStyle(node);
        properties.forEach(function (property) {
          var value = computed.getPropertyValue(property);
          if (value) copy.style.setProperty(property, value);
        });
      });
    }

    function svgToImage(svgElement, box) {
      return new Promise(function (resolve) {
        try {
          var clone = svgElement.cloneNode(true);
          inlineSvgStyles(svgElement, clone);
          clone.setAttribute("xmlns", "http://www.w3.org/2000/svg");
          clone.setAttribute("width", String(Math.max(1, box.width)));
          clone.setAttribute("height", String(Math.max(1, box.height)));
          if (!clone.getAttribute("viewBox")) {
            clone.setAttribute("viewBox", "0 0 " + Math.max(1, box.width) + " " + Math.max(1, box.height));
          }
          var fontBase64 = typeof window.PN_VAZIR_FONT_B64 === "string" ? window.PN_VAZIR_FONT_B64 : "";
          if (fontBase64) {
            var defs = clone.querySelector("defs") || clone.insertBefore(document.createElementNS("http://www.w3.org/2000/svg", "defs"), clone.firstChild);
            var styleNode = document.createElementNS("http://www.w3.org/2000/svg", "style");
            styleNode.textContent = "@font-face{font-family:Vazir;src:url(data:font/truetype;base64," + fontBase64 + ") format('truetype');font-weight:100 900;}";
            defs.appendChild(styleNode);
          }
          var serialized = new XMLSerializer().serializeToString(clone);
          var image = new Image();
          image.onload = function () { resolve(image); };
          image.onerror = function () { resolve(null); };
          image.src = "data:image/svg+xml;charset=utf-8," + encodeURIComponent(serialized);
        } catch (error) {
          resolve(null);
        }
      });
    }

    function textFont(style) {
      var fontStyle = style.fontStyle && style.fontStyle !== "normal" ? style.fontStyle : "";
      var fontWeight = style.fontWeight || "400";
      var fontSize = style.fontSize || "16px";
      var fontFamily = style.fontFamily || "sans-serif";
      return [fontStyle, fontWeight, fontSize, fontFamily].filter(Boolean).join(" ");
    }

    function groupTextRects(textNode) {
      var text = textNode.nodeValue || "";
      var groups = [];
      var current = null;
      var range = document.createRange();
      for (var index = 0; index < text.length; index++) {
        var character = text.charAt(index);
        range.setStart(textNode, index);
        range.setEnd(textNode, index + 1);
        var rects = range.getClientRects();
        var rect = rects && rects.length ? rects[0] : null;
        if (!rect || (!rect.width && !rect.height)) continue;
        var sameLine = current && Math.abs(current.top - rect.top) < 1.5 && Math.abs(current.height - rect.height) < 2;
        if (!sameLine) {
          current = { start: index, end: index + 1, top: rect.top, height: rect.height };
          groups.push(current);
        } else {
          current.end = index + 1;
        }
      }
      range.detach();
      return groups;
    }

    function drawTextNode(context, textNode, parentStyle, rootRect) {
      var text = textNode.nodeValue || "";
      if (!text || !text.trim()) return;
      var groups = groupTextRects(textNode);
      var fontSize = px(parentStyle.fontSize) || 16;
      context.save();
      context.font = textFont(parentStyle);
      context.fillStyle = parentStyle.color || "#000";
      context.textBaseline = "top";
      context.direction = parentStyle.direction === "ltr" ? "ltr" : "rtl";
      context.textAlign = "start";
      if (parentStyle.letterSpacing && parentStyle.letterSpacing !== "normal") {
        try { context.letterSpacing = parentStyle.letterSpacing; } catch (error) {}
      }
      groups.forEach(function (group) {
        var lineText = text.slice(group.start, group.end).replace(/[\r\n\t]+/g, " ");
        if (!lineText.trim()) return;
        var range = document.createRange();
        range.setStart(textNode, group.start);
        range.setEnd(textNode, group.end);
        var rect = range.getBoundingClientRect();
        range.detach();
        var x = parentStyle.direction === "ltr" ? rect.left - rootRect.left : rect.right - rootRect.left;
        var verticalSlack = Math.max(0, rect.height - fontSize);
        var y = rect.top - rootRect.top + verticalSlack / 2 - 0.5;
        context.fillText(lineText, x, y);
      });
      context.restore();
    }

    function shouldClip(style, radius) {
      var overflowX = String(style.overflowX || style.overflow || "visible");
      var overflowY = String(style.overflowY || style.overflow || "visible");
      return overflowX !== "visible" || overflowY !== "visible" || radius.tl || radius.tr || radius.br || radius.bl;
    }

    function paintElement(context, element, rootRect) {
      if (!element || element.nodeType !== 1) return Promise.resolve();
      var style = window.getComputedStyle(element);
      if (style.display === "none" || style.visibility === "hidden" || parseFloat(style.opacity || "1") <= 0) {
        return Promise.resolve();
      }
      var rect = element.getBoundingClientRect();
      if (!(rect.width > 0) || !(rect.height > 0)) return Promise.resolve();
      var box = rectRelative(rect, rootRect);
      var radius = radiusValues(style);
      var opacity = parseFloat(style.opacity || "1");
      if (!Number.isFinite(opacity)) opacity = 1;

      context.save();
      context.globalAlpha *= opacity;
      drawBackground(context, box, style, radius);

      if (element.classList && element.classList.contains("hst-print-cut-line")) {
        drawCutLine(context, box);
        drawBorders(context, box, style, radius);
        context.restore();
        return Promise.resolve();
      }

      context.save();
      if (shouldClip(style, radius)) {
        roundedRectPath(context, box.x, box.y, box.width, box.height, radius);
        context.clip();
      }

      var tag = String(element.tagName || element.localName || "").toUpperCase();
      var replaced = false;
      var task = Promise.resolve();
      if (tag === "IMG") {
        replaced = true;
        drawReplacedImage(context, element, box, style);
      } else if (tag === "CANVAS") {
        replaced = true;
        drawReplacedImage(context, element, box, style);
      } else if (tag === "SVG") {
        replaced = true;
        task = svgToImage(element, box).then(function (image) {
          if (image) context.drawImage(image, box.x, box.y, box.width, box.height);
        });
      }

      return task.then(function () {
        if (replaced) return;
        var chain = Promise.resolve();
        Array.prototype.forEach.call(element.childNodes, function (child) {
          chain = chain.then(function () {
            if (child.nodeType === 3) {
              drawTextNode(context, child, style, rootRect);
              return null;
            }
            if (child.nodeType === 1) return paintElement(context, child, rootRect);
            return null;
          });
        });
        return chain;
      }).then(function () {
        context.restore();
        drawBorders(context, box, style, radius);
        context.restore();
      }, function (error) {
        context.restore();
        context.restore();
        throw error;
      });
    }

    function rasterizePage(page) {
      return waitForAssets(page).then(function () {
        return new Promise(function (resolve) {
          window.requestAnimationFrame(function () {
            window.requestAnimationFrame(resolve);
          });
        });
      }).then(function () {
        var rect = page.getBoundingClientRect();
        var width = Math.max(1, Math.ceil(rect.width || page.offsetWidth || 794));
        var height = Math.max(1, Math.ceil(rect.height || page.offsetHeight || 1123));
        // Render the full A4 page at roughly 300 DPI. The previous ~220 DPI
        // canvas became visibly soft after zooming, especially around Persian
        // glyphs and thin table/chart lines. Keep a safe upper bound for mobile.
        var scale = Math.max(3, Math.min(3.25, 2480 / width));
        var canvas = document.createElement("canvas");
        canvas.width = Math.max(1, Math.round(width * scale));
        canvas.height = Math.max(1, Math.round(height * scale));
        var context = canvas.getContext("2d", { alpha: false });
        if (!context) throw new Error("report_card_canvas_context_unavailable");
        context.setTransform(scale, 0, 0, scale, 0, 0);
        context.fillStyle = "#ffffff";
        context.fillRect(0, 0, width, height);
        return paintElement(context, page, rect).then(function () {
          // PNG avoids JPEG ringing around text and fine rules in report cards.
          var dataUrl = canvas.toDataURL("image/png");
          canvas.width = 1;
          canvas.height = 1;
          return dataUrl;
        });
      });
    }

    return waitForAssets(root).then(function () {
      var doc = new Ctor({ orientation: "portrait", unit: "pt", format: "a4", compress: true });
      var pageWidth = doc.internal.pageSize.getWidth();
      var pageHeight = doc.internal.pageSize.getHeight();
      var chain = Promise.resolve();

      pages.forEach(function (page, index) {
        chain = chain.then(function () {
          if (typeof o.onProgress === "function") {
            o.onProgress(Math.round((index / pages.length) * 90), "در حال ساخت صفحه " + (index + 1) + " از " + pages.length + "...");
          }
          return rasterizePage(page).then(function (dataUrl) {
            if (index > 0) doc.addPage();
            doc.addImage(dataUrl, "PNG", 0, 0, pageWidth, pageHeight, undefined, "SLOW");
          });
        });
      });

      return chain.then(function () {
        if (typeof o.onProgress === "function") o.onProgress(100, "فایل کارنامه آماده شد.");
        doc.save(safeName(o.filename));
        return { fallback: false, pages: pages.length, renderer: "preview-dom-canvas" };
      });
    });
  }


  function tuitionPdf(o) {
    o = o || {};
    var Ctor = jsPDFCtor();
    var c = cfg();
    var rows = o.rows || [];
    if (!Ctor || !rows.length) {
      if (rows.length && typeof printDocument === "function") {
        printDocument({ title: "فاکتور شهریه", subtitle: o.subtitle || "", bodyHtml: "<pre dir=\"rtl\">" + escapeHtml(JSON.stringify(rows, null, 2)) + "</pre>" });
      }
      if (typeof o.onDone === "function") o.onDone();
      return;
    }

    function tuitionProgress(percent, text) {
      if (typeof o.onProgress === "function") o.onProgress(percent, text || "");
    }

    tuitionProgress(1, "در حال آماده‌سازی تصاویر و اطلاعات فاکتورها...");

    var schoolName = c.schoolName || "مدرسه";
    var urls = [c.logoUrl || ""];
    rows.forEach(function (row) {
      if (row.avatar_full_url) urls.push(row.avatar_full_url);
      else if (row.avatar_url) urls.push(row.avatar_url);
      if (row.qr_url) urls.push(row.qr_url);
    });

    loadPdfImages(urls, function (imgCache) {
      tuitionProgress(8, "در حال ساخت فایل PDF...");
      var doc = new Ctor({ orientation: "portrait", unit: "pt", format: (c.paper || "a4").toLowerCase() });
      var hasFa = ensureVazir(doc);
      if (hasFa) doc.setFont("Vazirmatn", "normal");

      var accent = hexToRgb(c.accent || "#334155");
      var accentSoft = mix(accent, [255, 255, 255], 0.88);
      var borderCol = [214, 221, 229];
      var pageW = doc.internal.pageSize.getWidth();
      var pageH = doc.internal.pageSize.getHeight();
      var marginX = 28;
      var topY = 34;
      var gap = 24;
      var blockH = (pageH - topY - 28 - gap) / 2;
      var usableW = pageW - marginX * 2;

      function addImageContain(img, x, y, w, h) {
        if (!img) return false;
        try {
          var ratio = (img.width && img.height) ? img.width / img.height : 1;
          var boxRatio = w / h;
          var drawW = w, drawH = h, dx = x, dy = y;
          if (ratio > boxRatio) {
            drawW = w;
            drawH = drawW / ratio;
            dy = y + (h - drawH) / 2;
          } else {
            drawH = h;
            drawW = drawH * ratio;
            dx = x + (w - drawW) / 2;
          }
          doc.addImage(img, dx, dy, drawW, drawH);
          return true;
        } catch (e) {
          return false;
        }
      }

      function drawStudentPortrait(row, x, y, w, h) {
        var imgUrl = row.avatar_full_url || row.avatar_url || "";
        var img = imgUrl ? imgCache[imgUrl] : null;
        doc.setFillColor(255, 255, 255);
        doc.setDrawColor(borderCol[0], borderCol[1], borderCol[2]);
        rrect(doc, x, y, w, h, 8, "FD");

        if (img) {
          addImageContain(img, x + 3, y + 3, w - 6, h - 6);
          doc.setDrawColor(borderCol[0], borderCol[1], borderCol[2]);
          rrect(doc, x, y, w, h, 8, "S");
          return;
        }

        doc.setFillColor(accentSoft[0], accentSoft[1], accentSoft[2]);
        rrect(doc, x + 3, y + 3, w - 6, h - 6, 6, "F");
        doc.setTextColor(accent[0], accent[1], accent[2]);
        doc.setFontSize(15);
        doc.text(
          shapeFa(initialsOf(row.student_name || row.name || "", row.first_name || "", row.last_name || "", row.initials || "")),
          x + w / 2,
          y + h / 2 + 5,
          { align: "center", maxWidth: w - 8 }
        );
      }

      function field(label, value, x, y, w) {
        doc.setTextColor(100, 116, 139);
        doc.setFontSize(6.8);
        doc.text(shapeFa(toFa(label)), x + w, y, { align: "right", maxWidth: w });
        doc.setTextColor(30, 41, 59);
        doc.setFontSize(9);
        doc.text(shapeFa(toFa(value || "—")), x + w, y + 14, { align: "right", maxWidth: w });
      }

      function drawCutLine(y) {
        doc.setDrawColor(170, 178, 188);
        doc.setLineWidth(0.6);
        if (doc.setLineDashPattern) doc.setLineDashPattern([4, 3], 0);
        doc.line(marginX, y, pageW - marginX, y);
        if (doc.setLineDashPattern) doc.setLineDashPattern([], 0);
      }

      function drawQr(url, x, y, size, label) {
        var qrImg = url ? imgCache[url] : null;
        doc.setFillColor(255, 255, 255);
        doc.setDrawColor(borderCol[0], borderCol[1], borderCol[2]);
        rrect(doc, x, y, size, size, 5, "FD");
        if (qrImg) {
          try { doc.addImage(qrImg, "PNG", x + 4, y + 4, size - 8, size - 8); }
          catch (e1) { try { doc.addImage(qrImg, "JPEG", x + 4, y + 4, size - 8, size - 8); } catch (e2) {} }
        } else {
          doc.setTextColor(100, 116, 139);
          doc.setFontSize(5.8);
          doc.text(shapeFa("پرداخت آنلاین"), x + size / 2, y + size / 2 + 2, { align: "center", maxWidth: size - 6 });
        }
        if (label) {
          doc.setTextColor(100, 116, 139);
          doc.setFontSize(5.7);
          doc.text(shapeFa(label), x + size / 2, y + size + 12, { align: "center", maxWidth: size + 16 });
        }
      }

      function drawManagerArea(x, y, w) {
        var box = 54;
        var gap = 14;
        var rightX = x + w - box;
        var leftX = rightX - gap - box;
        var boxH = 52;
        var managerName = c.managerName || "مدیر مدرسه";

        doc.setFillColor(255, 255, 255);
        doc.setDrawColor(borderCol[0], borderCol[1], borderCol[2]);
        rrect(doc, rightX, y, box, boxH, 8, "S");
        rrect(doc, leftX, y, box, boxH, 8, "S");

        doc.setTextColor(100, 116, 139);
        doc.setFontSize(6.5);
        doc.text(shapeFa("مهر مدرسه"), rightX + box / 2, y + 21, { align: "center", maxWidth: box - 8 });
        doc.text(shapeFa("امضای مدیر"), leftX + box / 2, y + 17, { align: "center", maxWidth: box - 8 });
        doc.setTextColor(accent[0], accent[1], accent[2]);
        doc.setFontSize(5.8);
        doc.text(shapeFa(toFa(managerName)), leftX + box / 2, y + 35, { align: "center", maxWidth: box - 8 });
      }

      function drawBlock(row, top, maxH) {
        var frameX = marginX;
        var frameY = top;
        var frameW = usableW;
        var innerPad = 10;
        var bodyX = frameX + innerPad;
        var bodyW = frameW - innerPad * 2;

        doc.setDrawColor(borderCol[0], borderCol[1], borderCol[2]);
        doc.setLineWidth(0.7);
        rrect(doc, frameX, frameY, frameW, maxH, 10, "S");

        var headH = 78;
        var headX = bodyX;
        var headY = frameY + innerPad;
        doc.setFillColor(248, 250, 252);
        doc.setDrawColor(borderCol[0], borderCol[1], borderCol[2]);
        rrect(doc, headX, headY, bodyW, headH, 8, "FD");

        var qrSize = 50;
        var qrX = headX + 10;
        var qrY = headY + 8;
        if (row.qr_url) {
          drawQr(row.qr_url, qrX, qrY, qrSize, "پرداخت آنلاین");
        } else {
          doc.setFillColor(accentSoft[0], accentSoft[1], accentSoft[2]);
          rrect(doc, qrX, qrY, qrSize, qrSize, 8, "F");
          doc.setTextColor(accent[0], accent[1], accent[2]);
          doc.setFontSize(8.4);
          doc.text(shapeFa("فاکتور"), qrX + qrSize / 2, qrY + 29, { align: "center", maxWidth: qrSize - 8 });
        }

        var centerX = headX + bodyW / 2;
        var logoImg = c.logoUrl ? imgCache[c.logoUrl] : null;
        if (logoImg) addImageContain(logoImg, centerX - 19, headY + 8, 38, 22);

        doc.setTextColor(accent[0], accent[1], accent[2]);
        doc.setFontSize(10.2);
        doc.text(shapeFa(toFa(schoolName)), centerX, headY + 41, { align: "center", maxWidth: 180 });
        doc.setTextColor(30, 41, 59);
        doc.setFontSize(8);
        doc.text(shapeFa("فاکتور شهریه"), centerX, headY + 55, { align: "center", maxWidth: 180 });
        doc.setTextColor(100, 116, 139);
        doc.setFontSize(6.7);
        doc.text(shapeFa(toFa(row.plan_title || o.subtitle || "")), centerX, headY + 68, { align: "center", maxWidth: 180 });

        var avW = 42;
        var avH = 56;
        var avX = headX + bodyW - 12 - avW;
        var avY = headY + 11;
        drawStudentPortrait(row, avX, avY, avW, avH);

        doc.setTextColor(30, 41, 59);
        doc.setFontSize(9.6);
        doc.text(shapeFa(toFa(row.student_name || "دانش‌آموز")), avX - 8, avY + 14, { align: "right", maxWidth: 150 });
        doc.setTextColor(100, 116, 139);
        doc.setFontSize(6.7);
        doc.text(shapeFa(toFa("کلاس: " + (row.class_name || "عمومی"))), avX - 8, avY + 30, { align: "right", maxWidth: 150 });
        doc.text(shapeFa(toFa("کد ملی: " + (row.national_code || row.student_login || "—"))), avX - 8, avY + 45, { align: "right", maxWidth: 150 });

        var cardX = bodyX;
        var cardY = headY + headH + 12;
        var cardW = bodyW;
        var cardH = maxH - (cardY - frameY) - innerPad;
        doc.setFillColor(255, 255, 255);
        doc.setDrawColor(borderCol[0], borderCol[1], borderCol[2]);
        rrect(doc, cardX, cardY, cardW, cardH, 10, "FD");

        var contentW = cardW - 36;
        var colGap = 16;
        var colW = (contentW - colGap) / 2;
        var rightX = cardX + cardW - 18;
        var rowY = cardY + 28;

        field("عنوان شهریه", row.plan_title, rightX - colW, rowY, colW);
        field("مبلغ", row.amount_text, rightX - (colW * 2) - colGap, rowY, colW);
        rowY += 42;
        field("وضعیت", row.status_label, rightX - colW, rowY, colW);
        field("روش پرداخت", row.payment_method_label, rightX - (colW * 2) - colGap, rowY, colW);
        rowY += 42;
        field("تاریخ پرداخت", row.paid_at || "پرداخت نشده", rightX - colW, rowY, colW);
        field("سررسید", row.due_date || "—", rightX - (colW * 2) - colGap, rowY, colW);
        rowY += 42;
        field("شناسه صورتحساب", row.id || "—", rightX - colW, rowY, colW);

        var footerY = cardY + cardH - 92;
        doc.setDrawColor(borderCol[0], borderCol[1], borderCol[2]);
        doc.line(cardX + 18, footerY, cardX + cardW - 18, footerY);

        var notice = row.qr_url
          ? "این فاکتور برای پرداخت آنلاین شهریه " + schoolName + " صادر شده است"
          : "این فاکتور مربوط به صورتحساب شهریه " + schoolName + " است";
        doc.setFontSize(7.2);
        doc.setTextColor(100, 116, 139);
        doc.text(shapeFa(toFa(notice)), cardX + cardW - 18, footerY + 18, { align: "right", maxWidth: cardW - 36 });

        drawManagerArea(cardX + 18, footerY + 30, cardW - 36);
      }

      var drawIndex = 0;
      var totalRows = rows.length;
      var chunkSize = totalRows > 24 ? 2 : 4;

      function drawNextChunk() {
        var end = Math.min(totalRows, drawIndex + chunkSize);
        for (; drawIndex < end; drawIndex++) {
          var row = rows[drawIndex];
          var slot = drawIndex % 2;
          if (drawIndex > 0 && slot === 0) doc.addPage();
          var y = topY + slot * (blockH + gap);
          drawBlock(row, y, blockH);
          if (slot === 0 && drawIndex < rows.length - 1) {
            drawCutLine(topY + blockH + gap / 2);
          }
        }

        var percent = 8 + Math.round((drawIndex / totalRows) * 88);
        tuitionProgress(percent, "در حال ساخت فاکتور " + drawIndex + " از " + totalRows);

        if (drawIndex < totalRows) {
          window.setTimeout(drawNextChunk, 0);
          return;
        }

        tuitionProgress(98, "در حال ذخیره فایل PDF...");
        doc.save(o.filename || "tuition-invoices.pdf");
        if (typeof o.onDone === "function") o.onDone();
      }

      window.setTimeout(drawNextChunk, 0);
    });
  }

  window.HSTPrint = {
    printHtml: printHtml,
    printDocument: printDocument,
    examPaperPdf: examPaperPdf,
    examPaperPreview: examPaperPreview,
    tablePdf: tablePdf,
    tuitionPdf: tuitionPdf,
    gridPdf: gridPdf,
    disciplineBookPdf: disciplineBookPdf,
    reportCardPdf: reportCardPdf,
    isPdfAvailable: isPdfAvailable,
    shapeFa: shapeFa,
    buildPrintDocument: buildPrintDocument,
  };
})();
