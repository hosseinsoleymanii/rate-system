jQuery(function ($) {
  "use strict";

  const $page = $("[data-hst-import]");
  if (!$page.length) return;

  let parsedRows = [];
  let parsedRowsSource = "";

  const esc = (value) => $("<span>").text(value == null ? "" : value).html();


  function toEnDigits(value) {
    return String(value == null ? "" : value)
      .replace(/[۰-۹]/g, (d) => "۰۱۲۳۴۵۶۷۸۹".indexOf(d))
      .replace(/[٠-٩]/g, (d) => "٠١٢٣٤٥٦٧٨٩".indexOf(d));
  }

  function onlyDigits(value) {
    return toEnDigits(value).replace(/[^0-9]/g, "");
  }

  function normalizeFa(value) {
    return String(value == null ? "" : value)
      .replace(/ي/g, "ی")
      .replace(/ك/g, "ک")
      .replace(/ۀ/g, "ه")
      .replace(/\u200c/g, " ")
      .replace(/\s+/g, " ")
      .trim();
  }

  function compactFa(value) {
    return normalizeFa(value).replace(/\s+/g, "");
  }

  function normalizeMobile(value) {
    let phone = onlyDigits(value);
    if (phone.length === 10 && phone[0] === "9") phone = "0" + phone;
    if (phone.length === 12 && phone.startsWith("98")) phone = "0" + phone.slice(2);
    return phone;
  }

  function isMobile(value) {
    const phone = normalizeMobile(value);
    return phone.length === 11 && phone.startsWith("09");
  }

  function normalizeStudentIdentifier(value) {
    const digits = onlyDigits(value);

    // Sida may return student national codes as 8 or 9 digits when one/two
    // leading zeros have been omitted. Keep these identifiers exactly as-is.
    if (digits.length === 8) {
      return /^(\d)\1{7}$/.test(digits) ? "" : digits;
    }

    if (digits.length === 9) {
      return /^(\d)\1{8}$/.test(digits) ? "" : digits;
    }

    if (digits.length === 10) {
      return isValidIranNationalCode(digits) ? digits : "";
    }

    return "";
  }

  function normalizeNationalCode(value) {
    return normalizeStudentIdentifier(value);
  }

  function isValidIranNationalCode(code) {
    if (!/^\d{10}$/.test(code)) return false;
    if (/^(\d)\1{9}$/.test(code)) return false;

    let sum = 0;
    for (let i = 0; i < 9; i++) {
      sum += parseInt(code[i], 10) * (10 - i);
    }

    const remainder = sum % 11;
    const check = parseInt(code[9], 10);
    return remainder < 2 ? check === remainder : check === 11 - remainder;
  }

  function normalizeBirthdate(value) {
    const raw = normalizeFa(value);
    const digits = onlyDigits(raw);

    if (digits.length === 8) {
      return digits.slice(0, 4) + "/" + digits.slice(4, 6) + "/" + digits.slice(6, 8);
    }

    const slashDate = toEnDigits(raw).replace(/-/g, "/");
    if (/^\d{4}\/\d{1,2}\/\d{1,2}$/.test(slashDate)) {
      return slashDate.replace(/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/, function (_, y, m, d) {
        return y + "/" + String(m).padStart(2, "0") + "/" + String(d).padStart(2, "0");
      });
    }

    return "";
  }

  function splitStudentFullName(fullName) {
    const parts = normalizeFa(fullName).split(/\s+/).filter(Boolean);
    if (parts.length < 2) {
      return { first_name: parts[0] || "", last_name: "" };
    }

    return {
      first_name: parts[parts.length - 1],
      last_name: parts.slice(0, -1).join(" "),
    };
  }

  function splitTeacherFullName(fullName) {
    const parts = normalizeFa(fullName).split(/\s+/).filter(Boolean);
    if (parts.length < 2) {
      return { first_name: parts[0] || "", last_name: "" };
    }

    return {
      first_name: parts[0],
      last_name: parts.slice(1).join(" "),
    };
  }

  function gradeFromText(value) {
    const text = compactFa(value);
    const num = onlyDigits(value);
    const map = {
      "1": "اول", "01": "اول",
      "2": "دوم", "02": "دوم",
      "3": "سوم", "03": "سوم",
      "4": "چهارم", "04": "چهارم",
      "5": "پنجم", "05": "پنجم",
      "6": "ششم", "06": "ششم",
      "7": "هفتم", "07": "هفتم",
      "8": "هشتم", "08": "هشتم",
      "9": "نهم", "09": "نهم",
      "10": "دهم",
      "11": "یازدهم",
      "12": "دوازدهم",
    };

    if (map[num]) return map[num];

    const words = ["دوازدهم", "یازدهم", "يازدهم", "دهم", "نهم", "هشتم", "هفتم", "ششم", "پنجم", "چهارم", "سوم", "دوم", "اول"];
    for (const word of words) {
      const normalized = compactFa(word);
      if (text === normalized || text.indexOf(normalized) !== -1) {
        return normalizeFa(word);
      }
    }

    return "";
  }

  function gradeAliases(grade) {
    const normalized = normalizeFa(grade);
    const aliases = {
      "اول": ["اول", "1", "01"],
      "دوم": ["دوم", "2", "02"],
      "سوم": ["سوم", "3", "03"],
      "چهارم": ["چهارم", "4", "04"],
      "پنجم": ["پنجم", "5", "05"],
      "ششم": ["ششم", "6", "06"],
      "هفتم": ["هفتم", "7", "07"],
      "هشتم": ["هشتم", "8", "08"],
      "نهم": ["نهم", "9", "09"],
      "دهم": ["دهم", "10"],
      "یازدهم": ["یازدهم", "يازدهم", "11"],
      "دوازدهم": ["دوازدهم", "12"],
    };

    return aliases[normalized] || (normalized ? [normalized] : []);
  }

  function fieldFromText(value) {
    const text = normalizeFa(value);
    if (/انسان|ادبیات|ادبيات/.test(text)) return "ادبیات و علوم انسانی";
    if (/تجرب/.test(text)) return "علوم تجربی";
    if (/ریاض|رياض/.test(text)) return "ریاضی";
    if (/معارف/.test(text)) return "معارف";
    if (/فنی|فني/.test(text)) return "فنی";
    if (/کار/.test(text)) return "کار و دانش";
    return "";
  }

  function cellsFromLine(line) {
    const text = String(line || "").trim();
    const parts = text.includes("\t") ? text.split("\t") : text.split(/\s{2,}|،|,|\|/);

    return parts
      .map((cell) => normalizeFa(String(cell || "").replace(/^"(.*)"$/, "$1").replace(/""/g, '"')))
      .filter(Boolean);
  }


  function normalizeHeader(value) {
    return compactFa(value)
      .replace(/[\/\\_\-–—:：؛؛،,.()\[\]{}«»"']/g, "")
      .replace(/نامکاربری/g, "نامکاربری")
      .replace(/دانشاموز/g, "دانشآموز");
  }


  function headerAliases(role) {
    const common = {
      "نام": "first_name",
      "نامکوچک": "first_name",
      "نامدانشآموز": "first_name",
      "نامدانشآموزان": "first_name",
      "ناممعلم": "first_name",
      "ناممعلمان": "first_name",
      "نامخانوادگی": "last_name",
      "نامخانوادگیدانشآموز": "last_name",
      "نامخانوادگیمعلم": "last_name",
      "فامیلی": "last_name",
      "نامفامیلی": "last_name",
      "موبایل": "phone",
      "شمارههمراه": "phone",
      "شمارههمراهدانشآموز": "phone",
      "شمارههمراهمعلم": "phone",
      "شمارهتماس": "phone",
      "شمارهتماسدانشآموز": "phone",
      "شمارهتماسمعلم": "phone",
      "شمارهتلفن": "phone",
      "شمارهتلفنهمراه": "phone",
      "شماره": "phone",
      "موبایلدانشآموز": "phone",
      "موبایلمعلم": "phone",
      "شمارهاموبایلنامکاربری": "phone",
      "شمارههمراهنامکاربری": "phone",
      "شمارهتلفننامکاربری": "phone",
      "شمارههمراهپیامرسان": "phone",
      "موبایلپیامرسان": "phone",
      "کدملی": "national_code",
      "کدملیدانشآموز": "national_code",
      "کدملیمعلم": "national_code",
      "کدملیکددانشآموزی": "national_code",
      "کددانشآموزی": "student_code",
      "کددانشاموزی": "student_code",
      "تاریختولد": "birthdate",
      "تاريخ تولد": "birthdate",
      "تاریختولددانشآموز": "birthdate",
      "تاریختولدمعلم": "birthdate",
    };

    const student = {
      "نامپدر": "father_name",
      "پدر": "father_name",
      "نامپدردانشآموز": "father_name",
      "شمارهتماسپدر": "father_phone",
      "شمارههمراهپدر": "father_phone",
      "موبایلپدر": "father_phone",
      "تلفنپدر": "father_phone",
      "شمارهتماسمادر": "mother_phone",
      "شمارههمراهمادر": "mother_phone",
      "موبایلمادر": "mother_phone",
      "تلفنمادر": "mother_phone",
      "پایه": "grade",
      "مقطع": "grade",
      "پایهدانشآموز": "grade",
      "رشته": "field",
      "رشتهتحصیلی": "field",
      "کدرشته": "field_code",
      "کلاسمقصد": "class_title",
      "کلاسمقصدعنوانکلاس": "class_title",
      "عنوانکلاسکلاسمقصد": "class_title",
      "کلاسعنوانکلاس": "class_title",
      "عنوانکلاس": "class_title",
      "کلاس": "class_title",
      "کلاسسیدا": "class_title",
      "جنسیت": "gender",
      "جنس": "gender",
      "محلصدور": "issue_place",
      "صادرهاز": "issue_place",
      "آدرس": "address",
      "ادرس": "address",
      "نشانی": "address",
    };

    const teacher = {
      "کدپرسنلی": "personnel_code",
      "کدپرسنلیمعل": "personnel_code",
      "کدپرسنلیمعلم": "personnel_code",
      "شمارهپرسنلی": "personnel_code",
      "کدکارمندی": "personnel_code",
    };

    return Object.assign({}, common, role === "teacher" ? teacher : student);
  }

  function fieldForHeader(header, role) {
    const normalized = normalizeHeader(header);
    const aliases = headerAliases(role);

    if (aliases[normalized]) return aliases[normalized];

    if (role === "teacher" && normalized.indexOf("پرسنلی") !== -1) return "personnel_code";
    if (role === "student" && normalized.indexOf("نامپدر") !== -1) return "father_name";
    if (role === "student" && normalized.indexOf("کلاس") !== -1 && (normalized.indexOf("مقصد") !== -1 || normalized.indexOf("عنوان") !== -1)) return "class_title";
    if (role === "student" && normalized.indexOf("پدر") !== -1 && (normalized.indexOf("شماره") !== -1 || normalized.indexOf("موبایل") !== -1 || normalized.indexOf("همراه") !== -1)) return "father_phone";
    if (role === "student" && normalized.indexOf("مادر") !== -1 && (normalized.indexOf("شماره") !== -1 || normalized.indexOf("موبایل") !== -1)) return "mother_phone";
    if (normalized.indexOf("موبایل") !== -1 || normalized.indexOf("همراه") !== -1 || normalized.indexOf("نامکاربری") !== -1) return "phone";
    if (normalized.indexOf("کدملی") !== -1) return "national_code";
    if (normalized.indexOf("تاریختولد") !== -1 || normalized.indexOf("تاريخ تولد") !== -1) return "birthdate";
    if (normalized.indexOf("نامخانوادگی") !== -1 || normalized.indexOf("فامیلی") !== -1) return "last_name";
    if (normalized === "نام" || normalized.indexOf("نامدانشآموز") !== -1 || normalized.indexOf("ناممعلم") !== -1) return "first_name";

    return "";
  }

  function textToRows(text) {
    return String(text || "")
      .split(/\r\n|\r|\n/)
      .map((line) => cellsFromLine(line))
      .filter((row) => row.length);
  }

  function findHeaderMapping(rows, role) {
    let best = { index: -1, map: [], count: 0 };

    rows.slice(0, 12).forEach((row, rowIndex) => {
      const map = row.map((cell) => fieldForHeader(cell, role));
      const unique = Array.from(new Set(map.filter(Boolean)));
      const count = unique.length;

      if (count > best.count) {
        best = { index: rowIndex, map, count };
      }
    });

    const has = (field) => best.map.includes(field);
    const hasStudentCode = has("national_code") || has("student_code");

    const hasRequired = role === "teacher"
      ? has("first_name") && has("last_name") && has("phone") && has("national_code") && has("personnel_code")
      : has("first_name") && has("last_name") && has("phone") && hasStudentCode && has("father_name") && has("birthdate");

    return hasRequired ? best : { index: -1, map: [], count: 0 };
  }

  function normalizeStudentExcelRow(obj) {
    const national = normalizeStudentIdentifier(obj.national_code || obj.student_code || "") || onlyDigits(obj.national_code || obj.student_code || "");
    const classTitle = normalizeFa(obj.class_title || [obj.grade, obj.field].filter(Boolean).join(" "));

    return {
      role: "student",
      source: "excel",
      first_name: normalizeFa(obj.first_name),
      last_name: normalizeFa(obj.last_name),
      father_name: normalizeFa(obj.father_name),
      national_code: national,
      student_code: national,
      phone: normalizeMobile(obj.phone),
      father_phone: normalizeMobile(obj.father_phone),
      mother_phone: normalizeMobile(obj.mother_phone),
      birthdate: normalizeBirthdate(obj.birthdate) || normalizeFa(obj.birthdate),
      class_title: classTitle,
      grade: gradeFromText(classTitle),
      field: fieldFromText(classTitle),
    };
  }

  function normalizeTeacherExcelRow(obj) {
    return {
      role: "teacher",
      source: "excel",
      first_name: normalizeFa(obj.first_name),
      last_name: normalizeFa(obj.last_name),
      personnel_code: onlyDigits(obj.personnel_code),
      national_code: onlyDigits(obj.national_code),
      birthdate: normalizeBirthdate(obj.birthdate) || normalizeFa(obj.birthdate),
      phone: normalizeMobile(obj.phone),
    };
  }

  function rowsToImportRows(rows, role) {
    const mapping = findHeaderMapping(rows, role);
    if (mapping.index < 0) return [];

    const out = [];

    rows.slice(mapping.index + 1).forEach((row) => {
      const obj = {};
      let hasValue = false;

      mapping.map.forEach((field, colIndex) => {
        if (!field) return;
        const value = normalizeFa(row[colIndex] == null ? "" : row[colIndex]);
        if (value !== "") hasValue = true;

        obj[field] = value;
      });

      if (!hasValue) return;

      const rowObj = role === "teacher" ? normalizeTeacherExcelRow(obj) : normalizeStudentExcelRow(obj);
      const usefulValues = [rowObj.first_name, rowObj.last_name, rowObj.phone, rowObj.national_code, rowObj.student_code, rowObj.personnel_code, rowObj.father_name]
        .filter((value) => String(value || "").trim() !== "");

      if (usefulValues.length >= 2) {
        out.push(rowObj);
      }
    });

    return out;
  }


  function parseStudentOfficeRow(cells) {
    if (cells.length < 14) return null;
    if (!/^\d+$/.test(onlyDigits(cells[0]))) return null;

    const code = normalizeStudentIdentifier(cells[3]);
    const birthdate = normalizeBirthdate(cells[7]);
    const phone = normalizeMobile(cells[13]);

    if (!code || !birthdate || !isMobile(phone)) return null;

    const classTitle = normalizeFa(cells[10]);

    return Object.assign({}, splitStudentFullName(cells[1]), {
      role: "student",
      source: "office",
      father_name: normalizeFa(cells[2]),
      national_code: code,
      student_code: code,
      phone,
      father_phone: "",
      mother_phone: "",
      birthdate,
      issue_place: normalizeFa(cells[8]),
      gender: normalizeFa(cells[9]),
      class_title: classTitle,
      grade: gradeFromText(classTitle),
      field: fieldFromText(classTitle),
      address: normalizeFa(cells[11]),
    });
  }

  function parseStudentSidaGridRow(cells) {
    if (cells.length < 9) return null;
    if (!/^\d+$/.test(onlyDigits(cells[0]))) return null;

    const code = normalizeStudentIdentifier(cells[1]);
    if (!code) return null;

    return {
      role: "student",
      source: "sida_grid",
      national_code: code,
      student_code: code,
      first_name: normalizeFa(cells[2]),
      last_name: normalizeFa(cells[3]),
      father_name: normalizeFa(cells[4]),
      father_phone: "",
      mother_phone: "",
      grade: gradeFromText(cells[5]) || normalizeFa(cells[5]),
      field_code: onlyDigits(cells[6]),
      field: normalizeFa(cells[7]),
      student_type: normalizeFa(cells[8]),
    };
  }

  function parseStudentLegacyRow(cells) {
    if (cells.length < 4) return null;

    const joined = cells.join(" ");
    if (/نام خانوادگ|کد ملی|کدملی|موبایل|همراه|شماره|ردیف|تاریخ تولد|کد دانش/.test(joined) && !cells.some(isMobile)) {
      return null;
    }

    const code = normalizeStudentIdentifier(cells[3]);
    if (!code) return null;

    let phone = "";
    let birthdate = "";
    cells.forEach((cell) => {
      if (!phone && isMobile(cell)) phone = normalizeMobile(cell);
      if (!birthdate && (onlyDigits(cell).length === 8 || /^\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}$/.test(toEnDigits(cell)))) {
        birthdate = normalizeBirthdate(cell);
      }
    });

    if (!phone) return null;

    return {
      role: "student",
      source: "legacy",
      first_name: normalizeFa(cells[0]),
      last_name: normalizeFa(cells[1]),
      father_name: normalizeFa(cells[2]),
      national_code: code,
      student_code: code,
      phone,
      father_phone: "",
      mother_phone: "",
      birthdate,
      grade: gradeFromText(cells[5] || ""),
      field: fieldFromText((cells[6] || "") + " " + cells.slice(7).join(" ")),
    };
  }

  function mergeRows(base, incoming) {
    const merged = Object.assign({}, base);
    const preferIncoming = ["first_name", "last_name", "father_name", "grade", "field", "field_code", "student_type"];

    Object.keys(incoming).forEach((key) => {
      if (key === "source") return;
      const value = incoming[key];
      if (value == null || String(value).trim() === "") return;
      if (!merged[key] || (incoming.source === "sida_grid" && preferIncoming.includes(key))) {
        merged[key] = value;
      }
    });

    merged.source = [base.source, incoming.source].filter(Boolean).join("+");
    return merged;
  }

  function parseStudents(text) {
    const map = {};
    const order = [];

    String(text || "").split(/\r\n|\r|\n/).forEach((line) => {
      const cells = cellsFromLine(line);
      if (cells.length < 2) return;

      const row = parseStudentOfficeRow(cells) || parseStudentSidaGridRow(cells) || parseStudentLegacyRow(cells);
      if (!row) return;

      const key = row.national_code || row.student_code;
      if (!key) return;

      if (map[key]) {
        map[key] = mergeRows(map[key], row);
      } else {
        map[key] = row;
        order.push(key);
      }
    });

    return order.map((key) => map[key]);
  }

  function parseTeachers(text) {
    const lines = String(text || "").split(/\r\n|\r|\n/).map(normalizeFa).filter(Boolean);
    const rows = [];

    for (let i = 0; i < lines.length; i++) {
      const line = lines[i];
      const cells = cellsFromLine(line);
      let row = null;

      // Common Sida copy shape:
      // 1   12556772   مهدی جلیلی
      // 09396121329
      const match = line.match(/^(\d+)\s+(\d{5,})\s+(.+?)(?:\s+(09\d{9}|9\d{9}))?$/);
      if (match && !/کد|عنوان|موبایل|سامانه|عملیات|گزارشات|اعتبار/.test(line)) {
        let phone = match[4] || "";
        if (!phone && i + 1 < lines.length && isMobile(lines[i + 1])) {
          phone = lines[i + 1];
          i++;
        }

        if (phone) {
          const name = splitTeacherFullName(match[3]);
          row = {
            role: "teacher",
            source: "sida_personnel",
            personnel_code: onlyDigits(match[2]),
            first_name: name.first_name,
            last_name: name.last_name,
            phone: normalizeMobile(phone),
            national_code: "",
            birthdate: "",
          };
        }
      }

      // CSV / Excel fallback: first_name, last_name, personnel_code, national_code, birthdate, phone
      if (!row && cells.length >= 4) {
        let phone = "";
        cells.forEach((cell) => {
          if (!phone && isMobile(cell)) phone = normalizeMobile(cell);
        });

        const personnel = onlyDigits(cells[2] || cells[1] || "");
        if (phone && personnel && !/نام خانوادگ|کد پرسنلی|موبایل|عنوان/.test(cells.join(" "))) {
          row = {
            role: "teacher",
            source: "legacy",
            first_name: normalizeFa(cells[0]),
            last_name: normalizeFa(cells[1]),
            personnel_code: personnel,
            national_code: onlyDigits(cells[3] || ""),
            birthdate: normalizeBirthdate(cells[4] || "") || normalizeFa(cells[4] || ""),
            phone,
          };
        }
      }

      if (row && row.personnel_code) {
        rows.push(row);
      }
    }

    const map = {};
    const order = [];
    rows.forEach((row) => {
      const key = row.personnel_code || row.phone;
      if (!map[key]) order.push(key);
      map[key] = Object.assign({}, map[key] || {}, row);
    });

    return order.map((key) => map[key]);
  }

  function importRole() {
    return $("#hst-import-role").val() === "teacher" ? "teacher" : "student";
  }

  function looksLikeSidaStudentText(text) {
    const value = normalizeFa(text);
    return /دفتر آمار|چاپ دفتر آمار|کد دانش آموزی|کد دانش‌آموزی|نام خانوادگی و نام/.test(value) &&
      /کدملی|کد ملی|تاریخ تولد|همراه|پایه|رشته/.test(value);
  }

  function parse(text) {
    const role = importRole();

    // Pasted Ctrl+A Sida text has table-like headers too. Keep the original
    // Sida parser first so the office-statistics template does not get
    // misdetected as a generic Excel/header table.
    if (role === "student" && looksLikeSidaStudentText(text)) {
      const sidaRows = parseStudents(text);
      if (sidaRows.length) return sidaRows;
    }

    if (role === "teacher") {
      const teacherRows = parseTeachers(text);
      if (teacherRows.length) return teacherRows;
    }

    const rows = textToRows(text);
    const excelRows = rowsToImportRows(rows, role);

    if (excelRows.length) {
      return excelRows;
    }

    return role === "teacher" ? parseTeachers(text) : parseStudents(text);
  }

  function duplicatePhones(rows) {
    const counts = {};
    (rows || parsedRows || []).forEach(function (row) {
      const phone = normalizeMobile(row && row.phone);
      if (!isMobile(phone)) return;
      counts[phone] = (counts[phone] || 0) + 1;
    });
    return counts;
  }

  function isDuplicatePhone(phone, rows) {
    const normalized = normalizeMobile(phone);
    if (!isMobile(normalized)) return false;
    return (duplicatePhones(rows)[normalized] || 0) > 1;
  }

  function isBlockingDuplicatePhone(row, rows) {
    rows = rows || parsedRows || [];
    const phone = normalizeMobile(row && row.phone);
    if (!isMobile(phone) || (duplicatePhones(rows)[phone] || 0) <= 1) {
      return false;
    }

    // During student update by national/student code, repeated parent mobile
    // should not block updating the existing student. Backend will keep the
    // student's existing username if the submitted phone belongs to another user.
    if ((row.role || importRole()) === "student" && row._can_update_existing_student) {
      return false;
    }

    return true;
  }

  function clearPhoneConflicts(rows) {
    (rows || parsedRows || []).forEach(function (row) {
      if (!row) return;
      row._phone_conflict = "";
      row._phone_conflict_phone = "";
      row._can_update_existing_student = false;
      row._existing_student_user_id = 0;
    });
  }

  function phoneConflictRowCount(rows) {
    let count = 0;
    (rows || parsedRows || []).forEach(function (row) {
      const phone = normalizeMobile(row && row.phone);
      if (phone && row && row._phone_conflict && row._phone_conflict_phone === phone) count++;
    });
    return count;
  }

  async function refreshImportPhoneConflicts(rows, role) {
    rows = rows || parsedRows || [];
    role = role || importRole();

    clearPhoneConflicts(rows);

    const payloadRows = rows
      .map(function (row, index) {
        return {
          _row_index: index,
          phone: normalizeMobile(row && row.phone),
          first_name: row && (row.first_name || ""),
          last_name: row && (row.last_name || ""),
          father_name: row && (row.father_name || ""),
          national_code: row && (row.national_code || row.student_code || ""),
          student_code: row && (row.student_code || row.national_code || ""),
          personnel_code: row && (row.personnel_code || ""),
        };
      })
      .filter(function (row) {
        return isMobile(row.phone);
      });

    if (!payloadRows.length) {
      updatePreviewState();
      return {};
    }

    try {
      const conflicts = {};
      const rowResults = {};
      const batchSize = 25;

      for (let offset = 0; offset < payloadRows.length; offset += batchSize) {
        const response = await HST.ajax({
          action: "hst_import_phone_conflicts",
          import_role: role,
          rows: payloadRows.slice(offset, offset + batchSize),
        });

        if (!response || !response.success) {
          throw new Error(HST.getMessage(response, "بررسی تداخل شماره‌های موبایل کامل نشد."));
        }

        const data = response.data || {};
        Object.assign(conflicts, data.conflicts || {});
        Object.assign(rowResults, data.row_results || {});
      }

      rows.forEach(function (row, index) {
        const phone = normalizeMobile(row && row.phone);
        const rowResult = rowResults[index] || {};

        if (rowResult.can_update_existing) {
          row._can_update_existing_student = true;
          row._existing_student_user_id = rowResult.user_id || 0;
        }

        if (rowResult.conflict && rowResult.message) {
          row._phone_conflict = rowResult.message;
          row._phone_conflict_phone = phone;
          return;
        }

        if (!phone || !conflicts[phone] || row._can_update_existing_student) return;

        row._phone_conflict = conflicts[phone].message || "این شماره موبایل قبلاً برای کاربر دیگری ثبت شده است.";
        row._phone_conflict_phone = phone;
      });

      updatePreviewState();
      return conflicts;
    } catch (error) {
      console.error("HST import phone conflict check failed:", error);
      return {};
    }
  }

  function looksLikeNumericIdentifierName(value) {
    const text = normalizeFa(value).replace(/[\s\-_.]+/g, "");
    const digits = onlyDigits(text);
    return digits.length >= 6 && text === digits;
  }

  function fieldIssues(row) {
    const issues = {};
    const role = row.role || importRole();

    if (!row.first_name) issues.first_name = "نام خالی است.";
    if (!row.last_name) issues.last_name = "نام خانوادگی خالی است.";

    if (!isMobile(row.phone)) {
      issues.phone = role === "teacher" ? "موبایل معلم نامعتبر است." : "موبایل دانش‌آموز نامعتبر است.";
    } else if (row._phone_conflict && row._phone_conflict_phone === normalizeMobile(row.phone)) {
      issues.phone = row._phone_conflict;
    } else if (isBlockingDuplicatePhone(row)) {
      issues.phone = "این شماره موبایل در پیش‌نمایش تکراری است و این ردیف هنوز به دانش‌آموز موجود قابل بروزرسانی وصل نشده است.";
    }

    if (role === "teacher") {
      if (looksLikeNumericIdentifierName(row.first_name)) issues.first_name = "نام معلم شبیه کد عددی است؛ نوع ورود یا ستون‌های فایل را بررسی کنید.";
      if (looksLikeNumericIdentifierName(row.last_name)) issues.last_name = "نام خانوادگی معلم شبیه کد عددی است؛ نوع ورود یا ستون‌های فایل را بررسی کنید.";
      if (!row.personnel_code) issues.personnel_code = "کد پرسنلی خالی است.";
      if (!/^\d{10}$/.test(onlyDigits(row.national_code))) issues.national_code = "کد ملی معلم باید دقیقاً 10 رقم باشد.";
      return issues;
    }

    if (!row.father_name) issues.father_name = "نام پدر خالی است.";
    if (!normalizeStudentIdentifier(row.national_code || row.student_code)) {
      issues.national_code = "کد ملی / کد دانش‌آموزی نامعتبر است.";
    }
    if (!row.birthdate) issues.birthdate = "تاریخ تولد خالی است.";

    return issues;
  }

  function fieldIssue(row, field) {
    return fieldIssues(row)[field] || "";
  }

  function rowIssues(row) {
    return Object.values(fieldIssues(row)).filter(Boolean);
  }

  function missingFields(row) {
    const issues = fieldIssues(row);
    return Object.keys(issues).map(function (key) {
      const labels = {
        first_name: "نام",
        last_name: "نام خانوادگی",
        father_name: "نام پدر",
        personnel_code: "کد پرسنلی",
        national_code: importRole() === "teacher" ? "کد ملی" : "کد ملی / کد دانش‌آموزی",
        birthdate: "تاریخ تولد",
        phone: isBlockingDuplicatePhone(row) ? "موبایل تکراری" : "موبایل",
      };
      return labels[key] || key;
    });
  }

  function isReady(row) {
    return rowIssues(row).length === 0;
  }

  function fieldErrorClass(row, field, extraClass) {
    return (extraClass || "") + (fieldIssue(row, field) ? " hst-import-edit--invalid" : "");
  }

  function editableInput(rowIndex, key, value, extraClass, title) {
    const classes = extraClass || "";
    const isBirthdate = key === "birthdate";
    const rawValue = value || "";
    const displayValue = esc(isBirthdate ? (normalizeBirthdate(rawValue) || formatBirthdateInput(rawValue) || rawValue) : rawValue);
    const dateAttrs = isBirthdate ? ' inputmode="numeric" maxlength="10" autocomplete="off" placeholder="____/__/__" dir="ltr" data-hst-date-mask="birthdate"' : "";
    const inputId = "hst-import-row-" + rowIndex + "-" + key.replace(/[^a-z0-9_-]/gi, "-");
    const inputName = "hst_import_rows[" + rowIndex + "][" + key + "]";

    return '<input type="text" class="hst-import-edit ' + classes + '" ' +
      'id="' + esc(inputId) + '" name="' + esc(inputName) + '" ' +
      'data-row-index="' + rowIndex + '" data-field="' + key + '" value="' + displayValue + '"' +
      dateAttrs +
      (title ? ' title="' + esc(title) + '" aria-label="' + esc(title) + '" aria-invalid="true"' : ' title="' + displayValue + '"') +
      '>';
  }

  function photoPrefix() {
    return $.trim($("#hst-import-photo-prefix").val() || "").replace(/\/+$/, "");
  }

  function photoCode(row) {
    return normalizeStudentIdentifier((row && (row.national_code || row.student_code)) || "") || onlyDigits((row && (row.national_code || row.student_code)) || "");
  }

  function studentIdentifierVariants(code) {
    code = onlyDigits(code);
    if (!code) return [];

    const variants = [code];

    if (code.length === 8) {
      variants.push("0" + code, "00" + code);
    } else if (code.length === 9) {
      variants.push("0" + code);
      if (code[0] === "0") variants.push(code.slice(1));
    } else if (code.length === 10 && code[0] === "0") {
      variants.push(code.slice(1));
      if (code.slice(0, 2) === "00") variants.push(code.slice(2));
    }

    return Array.from(new Set(variants.filter(function (item) {
      return item && item.length >= 8 && item.length <= 10;
    })));
  }

  function photoUrls(row) {
    const prefix = photoPrefix();
    const code = photoCode(row);

    if (!prefix || !code) return [];

    return studentIdentifierVariants(code).map(function (candidate) {
      return prefix + "/" + encodeURIComponent(candidate) + ".jpg";
    });
  }

  function isPrivatePhotoPrefix(prefix) {
    if (!prefix) return false;

    try {
      const parsed = new URL(prefix, window.location.href);
      const host = String(parsed.hostname || "").toLowerCase();
      if (!["http:", "https:"].includes(parsed.protocol)) return true;
      // Do not let a public school page initiate browser-side requests to a
      // remote/private photo host. The server checks and imports those images
      // during the final operation without triggering Private Network Access.
      if (parsed.origin !== window.location.origin) return true;
      if (host === "localhost" || host.endsWith(".local") || host === "::1") return true;
      if (/^127\./.test(host) || /^10\./.test(host) || /^192\.168\./.test(host) || /^169\.254\./.test(host)) return true;

      const match = host.match(/^172\.(\d{1,3})\./);
      return !!(match && Number(match[1]) >= 16 && Number(match[1]) <= 31);
    } catch (error) {
      return true;
    }
  }

  window.HSTImportTryNextPhoto = function (img) {
    const $img = $(img);
    const urls = String($img.attr("data-photo-urls") || "").split("|").filter(Boolean);
    const nextIndex = parseInt($img.attr("data-photo-index") || "0", 10) + 1;

    if (urls[nextIndex]) {
      $img.attr("data-photo-index", String(nextIndex));
      img.src = urls[nextIndex];
      return;
    }

    const holder = img.closest(".hst-import-photo-preview");
    if (holder) {
      holder.innerHTML = '<span class="hst-muted">بدون عکس</span>';
    }
  };

  function photoPreview(rowIndex, row) {
    const prefix = photoPrefix();
    if (prefix && isPrivatePhotoPrefix(prefix)) {
      return '<span class="hst-muted hst-import-photo-preview" data-photo-row="' + rowIndex + '">بررسی هنگام ثبت</span>';
    }

    const urls = photoUrls(row);

    if (!urls.length) {
      return '<span class="hst-muted hst-import-photo-preview" data-photo-row="' + rowIndex + '">—</span>';
    }

    return '<span class="hst-import-photo-preview" data-photo-row="' + rowIndex + '">' +
      '<img src="' + esc(urls[0]) + '" data-photo-index="0" data-photo-urls="' + esc(urls.join("|")) + '" alt="تصویر دانش‌آموز" loading="lazy" ' +
      'onerror="window.HSTImportTryNextPhoto&&window.HSTImportTryNextPhoto(this);">' +
      '</span>';
  }

  function syncPreviewEdits() {
    $("#hst-import-preview-body .hst-import-edit").each(function () {
      const rowIndex = parseInt($(this).data("row-index"), 10);
      const field = String($(this).data("field") || "");
      if (!parsedRows[rowIndex] || !field) return;

      let value = $.trim($(this).val() || "");

      if (field === "phone" || field === "father_phone" || field === "mother_phone") value = normalizeMobile(value);
      if (field === "national_code" || field === "student_code") {
        value = importRole() === "teacher" ? onlyDigits(value) : (normalizeStudentIdentifier(value) || onlyDigits(value));
      }
      if (field === "personnel_code" || field === "field_code") value = onlyDigits(value);
      if (field === "birthdate") value = normalizeBirthdate(value) || formatBirthdateInput(value) || value;

      parsedRows[rowIndex][field] = value;

      if (field === "national_code" && parsedRows[rowIndex].role === "student") {
        parsedRows[rowIndex].student_code = value;
      }
    });
  }

  function formatBirthdateInput(value) {
    const digits = onlyDigits(value).slice(0, 8);

    if (!digits) return "";
    if (digits.length <= 4) return digits;
    if (digits.length <= 6) return digits.slice(0, 4) + "/" + digits.slice(4);

    return digits.slice(0, 4) + "/" + digits.slice(4, 6) + "/" + digits.slice(6, 8);
  }

  function focusImportEdit($input) {
    if (!$input || !$input.length) return false;

    $input.trigger("focus");

    const input = $input.get(0);
    if (input && typeof input.select === "function") {
      window.setTimeout(function () {
        input.select();
      }, 0);
    }

    return true;
  }

  function moveImportEditByDirection($current, key) {
    const $inputs = $("#hst-import-preview-body .hst-import-edit:visible").filter(function () {
      return String($(this).attr("type") || "text") !== "hidden";
    });

    const index = $inputs.index($current);
    if (index < 0) return false;

    const isRtl = ($("html").attr("dir") || $("body").attr("dir") || "").toLowerCase() === "rtl" || $("body").css("direction") === "rtl";

    if (key === "ArrowLeft" || key === "ArrowRight") {
      const step = key === "ArrowLeft" ? (isRtl ? 1 : -1) : (isRtl ? -1 : 1);
      return focusImportEdit($inputs.eq(index + step));
    }

    const field = String($current.data("field") || "");
    const rowIndex = parseInt($current.data("row-index"), 10);
    const nextRow = key === "ArrowDown" ? rowIndex + 1 : rowIndex - 1;

    if (!Number.isFinite(nextRow) || nextRow < 0) return false;

    const $sameField = $('#hst-import-preview-body .hst-import-edit[data-row-index="' + nextRow + '"][data-field="' + field + '"]:visible').filter(function () {
      return String($(this).attr("type") || "text") !== "hidden";
    }).first();

    if ($sameField.length) {
      return focusImportEdit($sameField);
    }

    return false;
  }

  $(document).on("input", '#hst-import-preview-body .hst-import-edit[data-field="birthdate"]', function () {
    const formatted = formatBirthdateInput(this.value);
    this.value = formatted;
  });


  $(document).on("focus", '#hst-import-preview-body .hst-import-edit[data-field="birthdate"]', function () {
    const input = this;
    window.setTimeout(function () {
      if (!input.value && typeof input.setSelectionRange === "function") {
        input.setSelectionRange(0, 0);
      }
    }, 0);
  });

  $(document).on("keydown", "#hst-import-preview-body .hst-import-edit", function (event) {
    if (!["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown"].includes(event.key)) return;

    if (moveImportEditByDirection($(this), event.key)) {
      event.preventDefault();
    }
  });

  function duplicatePhoneRowCount(rows) {
    let count = 0;
    (rows || parsedRows || []).forEach(function (row) {
      if (isBlockingDuplicatePhone(row, rows)) count++;
    });
    return count;
  }

  function updatePreviewState() {
    syncPreviewEdits();

    const duplicateCount = duplicatePhoneRowCount(parsedRows);
    const conflictCount = phoneConflictRowCount(parsedRows);

    parsedRows.forEach(function (row, index) {
      const $tr = $('#hst-import-preview-body [data-preview-row="' + index + '"]');
      $tr.removeClass("hst-import-ok hst-import-bad").removeAttr("title");

      ["first_name", "last_name", "father_name", "father_phone", "mother_phone", "personnel_code", "national_code", "phone", "birthdate", "class_title"].forEach(function (field) {
        const issue = fieldIssue(row, field);
        const $input = $tr.find('[data-field="' + field + '"]');

        $input.toggleClass("hst-import-edit--invalid", !!issue);
        if (issue) {
          $input.attr("title", issue).attr("aria-invalid", "true");
        } else {
          $input.removeAttr("title").removeAttr("aria-invalid");
        }
      });

      const $photoCell = $tr.find('[data-hst-photo-cell]');
      if ($photoCell.length && row.role === "student") {
        $photoCell.html(photoPreview(index, row));
      }
    });

    $('[data-hst-import-ready-count]').text(parsedRows.filter(isReady).length);

    const $issues = $('[data-hst-preview-issues]');
    if ($issues.length) {
      if (duplicateCount > 0 || conflictCount > 0) {
        const messages = [];
        if (duplicateCount > 0) {
          messages.push(duplicateCount + " ردیف با شماره موبایل تکراری در همین پیش‌نمایش");
        }
        if (conflictCount > 0) {
          messages.push(conflictCount + " ردیف با شماره موبایل متعلق به کاربر موجود با نقش/کد متفاوت");
        }
        $issues.text("در پیش‌نمایش " + messages.join(" و ") + " وجود دارد. این ردیف‌ها تا زمان اصلاح موبایل ثبت نمی‌شوند.").prop("hidden", false);
      } else {
        $issues.text("").prop("hidden", true);
      }
    }
  }

  function initDynamicDatepickers() {
    if (window.HSTJalaliDatepicker && typeof window.HSTJalaliDatepicker.init === "function") {
      window.HSTJalaliDatepicker.init($("#hst-import-preview-body")[0]);
    }
  }

  function renderPreview(rows) {
    if (!rows.length) {
      return '<p class="hst-alert">هیچ ردیف معتبری شناسایی نشد. متن Ctrl+A سیدا یا فایل Excel همان نوع ورود را کامل وارد کنید.</p>';
    }

    const role = importRole();
    let html = '<div class="hst-table-wrap"><table class="hst-table hst-import-table hst-import-table--' + role + '"><thead><tr>';

    if (role === "teacher") {
      html += '<th>ردیف</th><th>نام</th><th>نام خانوادگی</th><th>کد پرسنلی</th><th>کد ملی</th><th>تاریخ تولد</th><th>موبایل</th>';
    } else {
      html += '<th>ردیف</th><th>تصویر</th><th>نام</th><th>نام خانوادگی</th><th>نام پدر</th><th>کد ملی / کد دانش‌آموزی</th><th>موبایل دانش‌آموز</th><th>شماره همراه پدر</th><th>شماره همراه مادر</th><th>تاریخ تولد</th><th>کلاس / رشته</th>';
    }

    html += '</tr></thead><tbody>';

    rows.forEach((row, index) => {
      row.role = role;
      const classLabel = row.class_title || [row.grade, row.field].filter(Boolean).join(" ");
      html += '<tr data-preview-row="' + index + '">';
      html += '<td>' + (index + 1) + '</td>';

      if (role === "teacher") {
        html += '<td>' + editableInput(index, "first_name", row.first_name, fieldErrorClass(row, "first_name"), fieldIssue(row, "first_name")) + '</td>';
        html += '<td>' + editableInput(index, "last_name", row.last_name, fieldErrorClass(row, "last_name"), fieldIssue(row, "last_name")) + '</td>';
        html += '<td>' + editableInput(index, "personnel_code", row.personnel_code, fieldErrorClass(row, "personnel_code", "hst-import-edit--code"), fieldIssue(row, "personnel_code")) + '</td>';
        html += '<td>' + editableInput(index, "national_code", row.national_code || "", fieldErrorClass(row, "national_code", "hst-import-edit--code"), fieldIssue(row, "national_code")) + '</td>';
        html += '<td>' + editableInput(index, "birthdate", row.birthdate || "", fieldErrorClass(row, "birthdate", "hst-import-edit--date"), fieldIssue(row, "birthdate")) + '</td>';
        html += '<td>' + editableInput(index, "phone", normalizeMobile(row.phone), fieldErrorClass(row, "phone", "hst-import-edit--phone"), fieldIssue(row, "phone")) + '</td>';
      } else {
        html += '<td data-hst-photo-cell>' + photoPreview(index, row) + '</td>';
        html += '<td>' + editableInput(index, "first_name", row.first_name, fieldErrorClass(row, "first_name"), fieldIssue(row, "first_name")) + '</td>';
        html += '<td>' + editableInput(index, "last_name", row.last_name, fieldErrorClass(row, "last_name"), fieldIssue(row, "last_name")) + '</td>';
        html += '<td>' + editableInput(index, "father_name", row.father_name, fieldErrorClass(row, "father_name"), fieldIssue(row, "father_name")) + '</td>';
        html += '<td>' + editableInput(index, "national_code", row.national_code || row.student_code, fieldErrorClass(row, "national_code", "hst-import-edit--code"), fieldIssue(row, "national_code")) + '</td>';
        html += '<td>' + editableInput(index, "phone", normalizeMobile(row.phone), fieldErrorClass(row, "phone", "hst-import-edit--phone"), fieldIssue(row, "phone")) + '</td>';
        html += '<td>' + editableInput(index, "father_phone", normalizeMobile(row.father_phone || ""), "hst-import-edit--phone") + '</td>';
        html += '<td>' + editableInput(index, "mother_phone", normalizeMobile(row.mother_phone || ""), "hst-import-edit--phone") + '</td>';
        html += '<td>' + editableInput(index, "birthdate", row.birthdate, fieldErrorClass(row, "birthdate", "hst-import-edit--date"), fieldIssue(row, "birthdate")) + '</td>';
        html += '<td>' + editableInput(index, "class_title", classLabel, "hst-import-edit--class") +
          '<input type="hidden" id="hst-import-row-' + index + '-grade" name="hst_import_rows[' + index + '][grade]" class="hst-import-edit" data-row-index="' + index + '" data-field="grade" value="' + esc(row.grade || "") + '">' +
          '<input type="hidden" id="hst-import-row-' + index + '-field" name="hst_import_rows[' + index + '][field]" class="hst-import-edit" data-row-index="' + index + '" data-field="field" value="' + esc(row.field || "") + '">' +
          '</td>';
      }

      html += '</tr>';
    });

    html += '</tbody></table></div>';
    html += '<div class="hst-report-stats">' +
      '<div class="hst-report-stat hst-report-stat--new"><b data-hst-import-ready-count>' + rows.filter(isReady).length + '</b><span>آماده برای ثبت</span></div>' +
      '<div class="hst-report-stat"><b>' + rows.length + '</b><span>کل ردیف‌های شناسایی‌شده</span></div>' +
      '</div>';

    const duplicateCount = duplicatePhoneRowCount(rows);
    html += '<p class="hst-alert" id="final-stats" data-hst-preview-issues' + (duplicateCount ? "" : " hidden") + '>' +
      (duplicateCount ? "در پیش‌نمایش " + duplicateCount + " ردیف با شماره موبایل تکراری وجود دارد. این ردیف‌ها تا زمان اصلاح موبایل ثبت نمی‌شوند." : "") +
      '</p>';

    return html;
  }

  $("#hst-import-preview").on("click", function () {
    const text = $("#hst-import-paste").val() || "";

    if (!$.trim(text)) {
      if (parsedRows.length && parsedRowsSource === "file") {
        showPreviewFromRows(parsedRows);
        return;
      }

      HST.toast("ابتدا متن Ctrl+A سیدا را وارد کنید یا فایل Excel را انتخاب کنید.", "error");
      return;
    }

    parsedRows = parse(text);
    parsedRowsSource = "text";
    $("#hst-import-preview-body").html(renderPreview(parsedRows));
    $("#hst-import-preview-card").removeAttr("hidden");
    $("#hst-import-result-card").attr("hidden", true);
    initDynamicDatepickers();
    refreshImportPhoneConflicts(parsedRows, importRole());
  });

  function rowsToText(rows) {
    return rows
      .map((row) => (Array.isArray(row) ? row : [row]).map((cell) => (cell == null ? "" : String(cell))).join("\t"))
      .join("\n");
  }

  function loadSheetJS() {
    return new Promise((resolve, reject) => {
      if (window.XLSX) return resolve(window.XLSX);
      const script = document.createElement("script");
      script.src = "https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js";
      script.onload = () => resolve(window.XLSX);
      script.onerror = () => reject(new Error("load failed"));
      document.head.appendChild(script);
    });
  }


  function showPreviewFromRows(rows, sourceLabel) {
    parsedRows = rows || [];
    parsedRowsSource = "file";
    $("#hst-import-preview-body").html(renderPreview(parsedRows));
    $("#hst-import-preview-card").removeAttr("hidden");
    $("#hst-import-result-card").attr("hidden", true);
    initDynamicDatepickers();
    refreshImportPhoneConflicts(parsedRows, importRole());

    if (sourceLabel) {
      HST.toast("پیش‌نمایش آماده شد.", "success");
    }
  }

  function parseDelimitedText(text, role) {
    if (role === "student" && looksLikeSidaStudentText(text)) {
      const sidaRows = parseStudents(text);
      if (sidaRows.length) return sidaRows;
    }

    if (role === "teacher") {
      const teacherRows = parseTeachers(text);
      if (teacherRows.length) return teacherRows;
    }

    const rows = textToRows(text);
    const structured = rowsToImportRows(rows, role);

    if (structured.length) {
      return structured;
    }

    return role === "teacher" ? parseTeachers(text) : parseStudents(text);
  }

  function fallbackTemplatePayload(role) {
    if (role === "teacher") {
      return {
        role: "teacher",
        filename: "نمونه-ورود-معلم‌ها.xlsx",
        title: "قالب ورود و بروزرسانی معلم‌ها",
        note: "اگر معلم قبلاً در سیستم ثبت شده باشد، اطلاعات او در همین فایل آمده و با آپلود مجدد، همان رکورد بروزرسانی می‌شود.",
        required: ["نام معلم", "نام خانوادگی", "شماره موبایل", "کد ملی", "کد پرسنلی"],
        headers: ["نام معلم", "نام خانوادگی", "شماره موبایل", "کد ملی", "کد پرسنلی", "تاریخ تولد"],
        rows: [["مهدی", "جلیلی", "09396121329", "0012345678", "12556772", ""]],
      };
    }

    return {
      role: "student",
      filename: "نمونه-ورود-دانش-آموزان.xlsx",
      title: "قالب ورود و بروزرسانی دانش‌آموزان",
      note: "اگر دانش‌آموز قبلاً در سیستم ثبت شده باشد، اطلاعات او در همین فایل آمده و با آپلود مجدد، همان رکورد بروزرسانی می‌شود.",
      required: ["نام دانش‌آموز", "نام خانوادگی", "شماره موبایل", "کد ملی / کد دانش‌آموزی", "نام پدر", "تاریخ تولد"],
      headers: ["نام دانش‌آموز", "نام خانوادگی", "شماره موبایل", "کد ملی / کد دانش‌آموزی", "نام پدر", "تاریخ تولد", "کلاس مقصد / عنوان کلاس", "شماره تماس پدر", "شماره تماس مادر"],
      rows: [["محمدمهدی", "کلانتری", "09100000000", "441711944", "حسین", "1389/01/01", "دهم انسانی", "", ""]],
    };
  }

  async function fetchTemplatePayload(role, trigger) {
    const response = await HST.request({
      action: "hst_import_template_rows",
      data: { import_role: role },
      trigger,
      showLoader: true,
      successMessage: false,
      errorMessage: "دریافت اطلاعات نمونه Excel ناموفق بود.",
      dedupe: "hst_import_template_rows_" + role,
    });

    return (response && response.success && response.data) ? response.data : fallbackTemplatePayload(role);
  }

  function columnWidths(headers) {
    return headers.map((header) => {
      const len = String(header || "").length;
      return { wch: Math.max(14, Math.min(38, len + 8)) };
    });
  }

  function applyWorkbookLayout(worksheet, payload) {
    const headers = payload.headers || [];
    const lastCol = Math.max(0, headers.length - 1);

    worksheet["!cols"] = columnWidths(headers);
    worksheet["!merges"] = [
      { s: { r: 0, c: 0 }, e: { r: 0, c: lastCol } },
      { s: { r: 1, c: 0 }, e: { r: 1, c: lastCol } },
    ];
    worksheet["!autofilter"] = { ref: XLSX.utils.encode_range({ s: { r: 2, c: 0 }, e: { r: Math.max(2, (payload.rows || []).length + 2), c: lastCol } }) };
    worksheet["!freeze"] = { xSplit: 0, ySplit: 3 };

    const range = XLSX.utils.decode_range(worksheet["!ref"]);
    for (let r = range.s.r; r <= range.e.r; r++) {
      for (let c = range.s.c; c <= range.e.c; c++) {
        const address = XLSX.utils.encode_cell({ r, c });
        if (!worksheet[address]) continue;

        worksheet[address].s = worksheet[address].s || {};
        worksheet[address].s.alignment = { horizontal: "right", vertical: "center", wrapText: true, readingOrder: 2 };
        worksheet[address].s.font = { name: "Tahoma", sz: r <= 2 ? 11 : 10 };
        worksheet[address].s.border = {
          top: { style: "thin", color: { rgb: "E5E7EB" } },
          bottom: { style: "thin", color: { rgb: "E5E7EB" } },
          left: { style: "thin", color: { rgb: "E5E7EB" } },
          right: { style: "thin", color: { rgb: "E5E7EB" } },
        };

        if (r === 0) {
          worksheet[address].s.font = { name: "Tahoma", sz: 14, bold: true, color: { rgb: "FFFFFF" } };
          worksheet[address].s.fill = { fgColor: { rgb: "0F766E" } };
        } else if (r === 1) {
          worksheet[address].s.font = { name: "Tahoma", sz: 10, color: { rgb: "334155" } };
          worksheet[address].s.fill = { fgColor: { rgb: "F8FAFC" } };
        } else if (r === 2) {
          const header = String(worksheet[address].v || "");
          const required = (payload.required || []).indexOf(header) !== -1;
          worksheet[address].s.font = { name: "Tahoma", sz: 10, bold: true, color: { rgb: required ? "991B1B" : "0F172A" } };
          worksheet[address].s.fill = { fgColor: { rgb: required ? "FEE2E2" : "E0F2FE" } };
        }
      }
    }

    worksheet["!rtl"] = true;
  }

  function downloadExcelPayload(payload) {
    return loadSheetJS()
      .then((XLSX) => {
        window.XLSX = XLSX;
        const rows = [
          [payload.title || "قالب انتقال از سیدا"],
          [payload.note || ""],
          payload.headers || [],
        ].concat(payload.rows || []);

        const workbook = XLSX.utils.book_new();
        workbook.Workbook = workbook.Workbook || {};
        workbook.Workbook.Views = [{ RTL: true }];

        const worksheet = XLSX.utils.aoa_to_sheet(rows);
        applyWorkbookLayout(worksheet, payload);

        XLSX.utils.book_append_sheet(workbook, worksheet, payload.role === "teacher" ? "معلم‌ها" : "دانش‌آموزان");
        XLSX.writeFile(workbook, payload.filename || "import-template.xlsx");
      });
  }

  async function downloadExcelTemplate(role, trigger) {
    try {
      const payload = await fetchTemplatePayload(role, trigger);
      await downloadExcelPayload(payload);
      HST.toast("نمونه Excel آماده شد.", "success");
    } catch (error) {
      console.error(error);
      HST.toast("ساخت نمونه Excel ناموفق بود.", "error");
    }
  }

  function readImportFile(file, role) {
    const name = String(file.name || "").toLowerCase();
    $("#hst-import-file-name").text(file.name);

    if (name.endsWith(".csv") || name.endsWith(".txt")) {
      const reader = new FileReader();
      reader.onload = (event) => {
        const text = String(event.target.result || "");
        const rows = parseDelimitedText(text, role);

        if (!rows.length) {
          $("#hst-import-paste").val(text);
          HST.toast("ردیف معتبری در فایل پیدا نشد. متن فایل در کادر قرار گرفت تا بررسی کنید.", "error");
          return;
        }

        showPreviewFromRows(rows, "فایل " + file.name);
      };
      reader.readAsText(file, "UTF-8");
      return;
    }

    if (name.endsWith(".xlsx") || name.endsWith(".xls")) {
      loadSheetJS()
        .then((XLSX) => {
          const reader = new FileReader();
          reader.onload = (event) => {
            const workbook = XLSX.read(new Uint8Array(event.target.result), { type: "array" });
            const sheet = workbook.Sheets[workbook.SheetNames[0]];
            const rawRows = XLSX.utils.sheet_to_json(sheet, { header: 1, raw: false, defval: "" });
            const rows = rowsToImportRows(rawRows, role);

            if (rows.length) {
              showPreviewFromRows(rows, "فایل " + file.name);
              return;
            }

            const text = rowsToText(rawRows);
            $("#hst-import-paste").val(text);
            const fallback = parse(text);
            if (fallback.length) {
              showPreviewFromRows(fallback, "فایل " + file.name);
            } else {
              HST.toast("ستون‌های فایل Excel شناسایی نشد. لطفاً از نمونه Excel همین بخش استفاده کنید.", "error");
            }
          };
          reader.readAsArrayBuffer(file);
        })
        .catch(() => HST.toast("بارگذاری کتابخانهٔ اکسل ناموفق بود. می‌توانید فایل را به CSV تبدیل کنید.", "error"));
      return;
    }

    HST.toast("فرمت فایل پشتیبانی نمی‌شود. از CSV یا Excel استفاده کنید.", "error");
  }

  $("#hst-import-file").on("change", function () {
    const file = this.files && this.files[0];
    if (!file) return;

    readImportFile(file, importRole());
  });

  $(document).on("dragenter dragover", ".hst-import-dropzone", function (event) {
    event.preventDefault();
    event.stopPropagation();
    $(this).addClass("is-dragover");
  });

  $(document).on("dragleave dragend drop", ".hst-import-dropzone", function (event) {
    event.preventDefault();
    event.stopPropagation();
    $(this).removeClass("is-dragover");
  });

  $(document).on("drop", ".hst-import-dropzone", function (event) {
    const file = event.originalEvent && event.originalEvent.dataTransfer && event.originalEvent.dataTransfer.files && event.originalEvent.dataTransfer.files[0];

    if (!file) return;
    readImportFile(file, importRole());
  });

  $("#hst-import-sample").on("click", function () {
    downloadExcelTemplate(importRole(), this);
  });


  function fieldMatches(rowField, className) {
    const field = compactFa(rowField);
    const name = compactFa(className);

    if (!field) return true;

    return name.indexOf(field) !== -1 ||
      (field.indexOf("انسان") !== -1 && name.indexOf("انسان") !== -1) ||
      (field.indexOf("ادبیات") !== -1 && name.indexOf("انسان") !== -1) ||
      (field.indexOf("ادبيات") !== -1 && name.indexOf("انسان") !== -1) ||
      (field.indexOf("تجرب") !== -1 && name.indexOf("تجرب") !== -1) ||
      (field.indexOf("ریاض") !== -1 && name.indexOf("ریاض") !== -1) ||
      (field.indexOf("رياض") !== -1 && name.indexOf("ریاض") !== -1) ||
      (field.indexOf("معارف") !== -1 && name.indexOf("معارف") !== -1) ||
      (field.indexOf("فنی") !== -1 && name.indexOf("فنی") !== -1) ||
      (field.indexOf("فني") !== -1 && name.indexOf("فنی") !== -1) ||
      (field.indexOf("کار") !== -1 && name.indexOf("کار") !== -1);
  }

  function matchClass(row) {
    const classes = window.hstImportClasses || [];
    if (!classes.length) return 0;

    const classTitle = compactFa(row.class_title);
    const gradeOptions = gradeAliases(row.grade).map(compactFa).filter(Boolean);
    const field = row.field;
    let best = 0;

    classes.forEach((classItem) => {
      const name = compactFa(classItem.name);
      const titleHit = classTitle && (name.indexOf(classTitle) !== -1 || classTitle.indexOf(name) !== -1);
      const gradeHit = gradeOptions.length ? gradeOptions.some((g) => name.indexOf(g) !== -1) : true;
      const fieldHit = fieldMatches(field, classItem.name);

      if (titleHit || (gradeHit && fieldHit)) {
        best = classItem.id;
      }
    });

    return best;
  }

  $(document).on("input change", "#hst-import-preview-body .hst-import-edit", function () {
    updatePreviewState();
  });

  $(document).on("input change", "#hst-import-photo-prefix", function () {
    if (parsedRows.length) {
      updatePreviewState();
    }
  });

  $("#hst-import-role").on("change", function () {
    parsedRows = [];
    parsedRowsSource = "";
    $("#hst-import-preview-card").attr("hidden", true);
    $("#hst-import-result-card").attr("hidden", true);
    updateModeUI();
  });

  function setStudentOnlyVisibility(show) {
    const $items = $("[data-hst-student-options], [data-hst-student-class-options], [data-hst-student-photo-options], [data-hst-student-only]");

    $items.each(function () {
      const $item = $(this);

      $item.prop("hidden", !show)
        .attr("aria-hidden", show ? "false" : "true");

      if (show) {
        $item.show();
      } else {
        $item.hide();
      }
    });
  }
  function importErrorHtml(errors) {
    if (!errors || !errors.length) return "";

    let html = '<div class="hst-import-result-issues">' +
      '<div class="hst-import-result-issues__head">' +
      '<div class="hst-import-result-issues__title"><b>موارد رد‌شده</b><span>این ردیف‌ها برای جلوگیری از خطا یا ثبت اشتباه وارد سیستم نشدند.</span></div>' +
      '<span class="hst-import-result-issues__count">' + errors.length + '</span>' +
      '</div><ul class="hst-import-result-issues__list">';

    errors.forEach(function (error) {
      const text = String(error || "");
      const parts = text.split(":");
      const name = parts.length > 1 ? parts.shift().trim() : "";
      const reason = parts.join(":").trim() || text;

      html += '<li class="hst-import-result-issue">' +
        '<span class="hst-import-result-issue__dot" aria-hidden="true"></span>' +
        '<span class="hst-import-result-issue__text">' +
        (name ? '<span class="hst-import-result-issue__name">' + esc(name) + '</span><span> — </span>' : '') +
        '<span class="hst-import-result-issue__reason">' + esc(reason) + '</span>' +
        '</span></li>';
    });

    html += '</ul></div>';
    return html;
  }


  function updateExcelUploaderText(role) {
    const isTeacher = role === "teacher";

    $("[data-hst-excel-title]").text(isTeacher ? "Excel معلم‌ها" : "Excel دانش‌آموزان");
    $("[data-hst-excel-desc]").text(
      isTeacher
        ? "نمونه شامل معلم‌های ثبت‌شده است؛ فایل را ویرایش و برای بروزرسانی همان معلم‌ها آپلود کنید."
        : "نمونه شامل دانش‌آموزان ثبت‌شده است؛ فایل را ویرایش و برای بروزرسانی همان دانش‌آموزان آپلود کنید."
    );
    $("[data-hst-upload-title]").text(isTeacher ? "انتخاب یا رها کردن فایل معلم‌ها" : "انتخاب یا رها کردن فایل دانش‌آموزان");
$("#hst-import-file").val("");
    $("#hst-import-file-name").text("فایلی انتخاب نشده");
  }

  function updateModeUI() {
    const role = importRole();
    const isTeacher = role === "teacher";

    setStudentOnlyVisibility(!isTeacher);
    updateExcelUploaderText(role);
    $(".hst-import-mode-row").toggleClass("is-teacher", isTeacher);

    if (isTeacher) {
      $("#hst-import-paste").attr("placeholder", "از صفحه مشخصات پرسنل / ثبت شماره موبایل پرسنل سیدا Ctrl+A و سپس Ctrl+C بزنید و متن را اینجا paste کنید...");
    } else {
      $("#hst-import-paste").attr("placeholder", "از صفحه دفتر آمار سیدا Ctrl+A و سپس Ctrl+C بزنید و متن را اینجا paste کنید...");
    }
  }

  updateModeUI();
  setTimeout(updateModeUI, 0);


  function importProgressMessages(role, totalRows, hasPhotoPrefix) {
    const label = role === "teacher" ? "معلم" : "دانش‌آموز";
    const messages = [
      "آماده‌سازی " + totalRows + " ردیف " + label,
      "بررسی فیلدهای ضروری و شماره‌های موبایل",
      role === "teacher" ? "جست‌وجوی معلم‌های موجود برای جلوگیری از ثبت تکراری" : "جست‌وجوی دانش‌آموزهای موجود با کد ملی / کد دانش‌آموزی",
      "به‌روزرسانی اطلاعات متفاوت و ساخت رکوردهای جدید",
      "ذخیره اطلاعات تکمیلی",
    ];

    if (role === "student" && hasPhotoPrefix) {
      messages.push("بررسی و دریافت تصاویر پروفایل دانش‌آموزان");
    }

    messages.push("نهایی‌سازی نتیجه و آماده‌سازی گزارش");

    return messages;
  }

  function setImportProgress(percent, message, detail) {
    const value = Math.max(0, Math.min(100, Math.round(percent || 0)));
    const $progress = $("#hst-import-progress");

    $progress.find("[data-hst-import-progress-bar]").css("width", value + "%");
    $progress.find("[data-hst-import-progress-percent]").text(value + "٪");
    $progress.find(".hst-import-progress__track").attr("aria-valuenow", value);

    if (message) {
      $progress.find("[data-hst-import-progress-message]").text(message);
    }

    if (detail) {
      $progress.find("[data-hst-import-progress-detail]").text(detail);
    }
  }

  function startImportProgress(role, totalRows, hasPhotoPrefix) {
    const $progress = $("#hst-import-progress");
    const messages = importProgressMessages(role, totalRows, hasPhotoPrefix);
    let closed = false;

    $progress.removeAttr("hidden").attr("aria-hidden", "false");
    $progress.find("[data-hst-import-progress-title]").text(role === "teacher" ? "در حال انتقال از سیدا معلم‌ها" : "در حال انتقال از سیدا دانش‌آموزان");
    $progress.find("[data-hst-import-progress-subtitle]").text("لطفاً تا پایان عملیات این صفحه را نبندید.");
    $progress.find(".hst-import-progress__track")
      .attr("role", "progressbar")
      .attr("aria-valuemin", "0")
      .attr("aria-valuemax", "100");

    setImportProgress(3, messages[0], "ردیف‌ها در بسته‌های کوچک و مطمئن ثبت می‌شوند.");

    return {
      advance(completedRows, detail) {
        if (closed) return;
        const completed = Math.max(0, Math.min(totalRows, completedRows || 0));
        const ratio = totalRows > 0 ? completed / totalRows : 0;
        const percent = Math.min(96, 4 + Math.round(ratio * 92));
        const messageIndex = Math.min(messages.length - 1, Math.floor(ratio * messages.length));
        setImportProgress(
          percent,
          messages[messageIndex],
          detail || (completed + " ردیف از " + totalRows + " ردیف پردازش شد.")
        );
      },
      finish(detail) {
        if (closed) return;
        closed = true;
        setImportProgress(100, "انتقال از سیدا کامل شد", detail || "نتیجه عملیات آماده نمایش است.");
        window.setTimeout(function () {
          $progress.attr("hidden", true).attr("aria-hidden", "true");
        }, 750);
      },
      fail(detail) {
        if (closed) return;
        closed = true;
        setImportProgress(100, "عملیات کامل نشد", detail || "خطایی در انتقال از سیدا رخ داد.");
        window.setTimeout(function () {
          $progress.attr("hidden", true).attr("aria-hidden", "true");
        }, 1200);
      },
      close() {
        if (closed) return;
        closed = true;
        $progress.attr("hidden", true).attr("aria-hidden", "true");
      },
    };
  }

  async function importRowsInBatches(requestPayload, validRows, role, hasPhotoPrefix, progress) {
    // Image hosts can be slow or unreachable. One student per request keeps an
    // image timeout isolated; ordinary imports use larger but bounded batches.
    const batchSize = hasPhotoPrefix ? 1 : (role === "teacher" ? 15 : 10);
    const summary = {
      created: 0,
      updated: 0,
      renamed: 0,
      skipped: 0,
      photos: 0,
      errors: [],
      credentials: [],
    };

    for (let offset = 0; offset < validRows.length; offset += batchSize) {
      const batch = validRows.slice(offset, offset + batchSize);
      const response = await HST.ajax(Object.assign({}, requestPayload, { rows: batch }));

      if (!response || !response.success) {
        throw new Error(HST.getMessage(response, "ثبت این بخش از اطلاعات کامل نشد."));
      }

      const data = response.data || {};
      ["created", "updated", "renamed", "skipped", "photos"].forEach(function (key) {
        summary[key] += Number(data[key] || 0);
      });
      summary.errors = summary.errors.concat(Array.isArray(data.errors) ? data.errors : []).slice(0, 50);
      summary.credentials = summary.credentials.concat(Array.isArray(data.credentials) ? data.credentials : []);

      const completed = Math.min(validRows.length, offset + batch.length);
      progress.advance(completed, completed + " ردیف از " + validRows.length + " ردیف ثبت شد.");
    }

    return summary;
  }


  let studentPhotoImportRunning = false;

  function toFaDigits(value) {
    return String(value == null ? "" : value).replace(/[0-9]/g, function (digit) {
      return "۰۱۲۳۴۵۶۷۸۹"[Number(digit)];
    });
  }

  function ensureStudentPhotoProgressModal() {
    let $modal = $("#hst-import-student-photos-modal");
    if ($modal.length) return $modal;

    $modal = $(`
      <div class="hst-modal" data-hst-progress-modal data-hst-modal-size="md" id="hst-import-student-photos-modal" role="dialog" aria-modal="true" aria-labelledby="hst-import-student-photos-title" aria-hidden="true">
        <div class="hst-modal__backdrop"></div>
        <div class="hst-modal__panel">
          <div class="hst-modal__header">
            <div>
              <h3 id="hst-import-student-photos-title">انتقال تصاویر دانش‌آموزان</h3>
              <p>تصاویر دانش‌آموزان سال تحصیلی فعال با استفاده از پیشوند واردشده دریافت و ثبت می‌شوند.</p>
            </div>
            <button type="button" class="hst-modal__close hst-import-student-photos-close" data-hst-progress-close aria-label="بستن">×</button>
          </div>
          <div class="hst-modal__body">
            <div class="hst-operation-progress" aria-live="polite">
              <div class="hst-operation-progress__head">
                <strong class="hst-operation-progress__title">در حال انتقال تصاویر</strong>
                <span class="hst-operation-progress__percent">۰٪</span>
              </div>
              <div class="hst-operation-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <span class="hst-operation-progress__bar"></span>
              </div>
              <p class="hst-operation-progress__hint">تا تکمیل عملیات، صفحه را نبندید و ترک نکنید.</p>
            </div>
          </div>
          <div class="hst-modal__footer">
            <button type="button" class="hst-btn hst-btn--ghost hst-import-student-photos-close" data-hst-progress-close>بستن</button>
          </div>
        </div>
      </div>
    `);

    $("body").append($modal);
    return $modal;
  }

  function setStudentPhotoModalRunning(running) {
    studentPhotoImportRunning = Boolean(running);
    const $modal = ensureStudentPhotoProgressModal();
    HST.setProgressModalLocked(
      $modal,
      studentPhotoImportRunning,
      "تا پایان انتقال تصاویر، صفحه را نبندید یا ترک نکنید."
    );
    $("#hst-import-student-photos").prop("disabled", studentPhotoImportRunning);
  }

  function openStudentPhotoProgressModal() {
    const $modal = ensureStudentPhotoProgressModal();
    $modal.addClass("is-open").attr("aria-hidden", "false");
    updateStudentPhotoProgress(0, "در حال آماده‌سازی فهرست دانش‌آموزان...");
    return $modal;
  }

  function closeStudentPhotoProgressModal() {
    if (studentPhotoImportRunning) {
      HST.toast("تا پایان انتقال تصاویر، صفحه را نبندید یا ترک نکنید.", "error");
      return false;
    }
    $("#hst-import-student-photos-modal").removeClass("is-open").attr("aria-hidden", "true");
    return true;
  }

  function updateStudentPhotoProgress(percent, text, title) {
    const $modal = ensureStudentPhotoProgressModal();
    const safePercent = Math.max(0, Math.min(100, parseInt(percent, 10) || 0));
    $modal.find(".hst-operation-progress__bar").css("width", safePercent + "%");
    $modal.find(".hst-operation-progress__percent").text(toFaDigits(safePercent + "٪"));
    $modal.find(".hst-operation-progress__track").attr("aria-valuenow", String(safePercent));
    if (text) $modal.find(".hst-operation-progress__hint").text(text);
    if (title) $modal.find(".hst-operation-progress__title").text(title);
  }

  async function requestStudentPhotoStep(token) {
    let lastError = null;
    for (let attempt = 0; attempt < 3; attempt += 1) {
      try {
        const response = await HST.ajax({
          action: "hst_import_student_photos_step",
          token: token,
        });
        if (!response || !response.success) {
          throw new Error(HST.getMessage(response, "انتقال این بخش از تصاویر انجام نشد."));
        }
        return response.data || {};
      } catch (error) {
        lastError = error;
        if (attempt < 2) {
          await new Promise(function (resolve) {
            window.setTimeout(resolve, 700 * (attempt + 1));
          });
        }
      }
    }
    throw lastError || new Error("ارتباط با سرور برقرار نشد.");
  }

  async function runStudentPhotoImport(prefix) {
    const $modal = openStudentPhotoProgressModal();
    setStudentPhotoModalRunning(true);

    try {
      const startResponse = await HST.ajax({
        action: "hst_import_student_photos_start",
        photo_prefix: prefix,
      });
      if (!startResponse || !startResponse.success) {
        throw new Error(HST.getMessage(startResponse, "شروع انتقال تصاویر انجام نشد."));
      }

      const start = startResponse.data || {};
      if (start.done) {
        updateStudentPhotoProgress(100, start.message || "همه دانش‌آموزان دارای تصویر هستند.", "عملیات کامل شد");
        HST.toast(start.message || "همه دانش‌آموزان دارای تصویر هستند.", "success");
        return;
      }

      const token = start.token || "";
      const total = Number(start.total || 0);
      if (!token || total < 1) {
        throw new Error("اطلاعات عملیات انتقال تصاویر کامل نیست.");
      }

      updateStudentPhotoProgress(1, "انتقال تصاویر برای " + toFaDigits(total) + " دانش‌آموز آغاز شد.");

      while (true) {
        const data = await requestStudentPhotoStep(token);
        const processed = Number(data.processed || 0);
        const currentTotal = Number(data.total || total);
        const progressText = data.done
          ? (data.message || "انتقال تصاویر دانش‌آموزان کامل شد.")
          : (data.message || (toFaDigits(processed) + " مورد از " + toFaDigits(currentTotal) + " بررسی شد."));

        updateStudentPhotoProgress(data.percent || 0, progressText, data.done ? "انتقال تصاویر کامل شد" : "در حال انتقال تصاویر");

        if (data.done) {
          const stats = data.stats || {};
          const imported = Number(stats.imported || 0);
          const failed = Number(stats.failed || 0);
          const summary = toFaDigits(imported) + " تصویر دریافت شد" + (failed ? " و " + toFaDigits(failed) + " تصویر پیدا نشد." : ".");
          updateStudentPhotoProgress(100, summary, "انتقال تصاویر کامل شد");
          HST.toast("انتقال تصاویر دانش‌آموزان کامل شد.", "success");
          break;
        }
      }
    } catch (error) {
      const message = HST.getMessage(error, "انتقال تصاویر دانش‌آموزان کامل نشد.");
      updateStudentPhotoProgress(100, message, "عملیات کامل نشد");
      HST.toast(message, "error");
    } finally {
      setStudentPhotoModalRunning(false);
      $modal.find(".hst-import-student-photos-close").removeAttr("hidden");
    }
  }

  $(document).on("click", ".hst-import-student-photos-close", function () {
    closeStudentPhotoProgressModal();
  });

  $(document).on("click", "#hst-import-student-photos-modal .hst-modal__backdrop", function () {
    closeStudentPhotoProgressModal();
  });

  $(window).on("beforeunload.hstStudentPhotoImport", function (event) {
    if (!studentPhotoImportRunning) return undefined;
    const message = "انتقال تصاویر دانش‌آموزان هنوز کامل نشده است.";
    event.preventDefault();
    event.originalEvent.returnValue = message;
    return message;
  });

  $("#hst-import-student-photos").on("click", async function () {
    if (studentPhotoImportRunning) return;

    const prefix = $.trim($("#hst-import-photo-prefix").val() || "");
    if (!prefix) {
      HST.toast("ابتدا پیشوند عکس دانش‌آموزان را وارد کنید.", "error");
      $("#hst-import-photo-prefix").trigger("focus");
      return;
    }

    const confirmation = await window.HSTModal.open({
      title: "انتقال تصاویر دانش‌آموزان",
      text: "تصاویر دانش‌آموزان سال تحصیلی فعال، بدون تکرار انتقال اطلاعات از سیدا، دریافت و ثبت شوند؟",
    });
    if (!confirmation.isConfirmed) return;

    runStudentPhotoImport(prefix);
  });

  $("#hst-import-confirm").on("click", async function () {
    syncPreviewEdits();
    const role = importRole();
    await refreshImportPhoneConflicts(parsedRows, role);
    const classSel = $("#hst-import-class").val();
    const termId = $("#hst-import-term").val() || 0;
    const autoMode = role === "student" && classSel === "auto";

    if (role === "student" && !autoMode && !classSel) {
      HST.toast("کلاس مقصد را انتخاب کنید یا حالت تشخیص خودکار را برگزینید.", "error");
      return;
    }

    const duplicateCount = duplicatePhoneRowCount(parsedRows);
    const conflictCount = phoneConflictRowCount(parsedRows);
    if (duplicateCount > 0 || conflictCount > 0) {
      updatePreviewState();
      HST.toast("شماره موبایل تکراری یا متعلق به کاربر دیگری در پیش‌نمایش وجود دارد. لطفاً قبل از ثبت، موبایل‌های مشکل‌دار را اصلاح کنید.", "error");
      return;
    }

    const validRows = parsedRows.filter(isReady);
    if (!validRows.length) {
      HST.toast(role === "teacher" ? "ردیف کاملی برای ثبت معلم وجود ندارد." : "ردیف کاملی برای ثبت دانش‌آموز وجود ندارد.", "error");
      return;
    }

    if (role === "student" && autoMode) {
      const unmatched = [];
      validRows.forEach((row) => {
        if (row.class_title) {
          row.grade = row.grade || gradeFromText(row.class_title);
          row.field = row.field || fieldFromText(row.class_title);
        }
        row._class_id = matchClass(row);
        if (!row._class_id) unmatched.push((row.first_name + " " + row.last_name).trim());
      });

      if (unmatched.length) {
        HST.toast("برای این موارد کلاسی مطابق پیدا نشد: " + unmatched.slice(0, 5).join("، ") + (unmatched.length > 5 ? " و..." : "") + ". لطفاً کلاس را دستی انتخاب کنید یا ابتدا کلاس‌ها را با نام متناظر سیدا بسازید.", "error");
        return;
      }
    }

    const requestPayload = {
      action: "hst_import_users",
      import_role: role,
      class_id: role === "student" ? (autoMode ? 0 : classSel) : 0,
      auto_class: autoMode ? 1 : 0,
      term_id: role === "student" ? termId : 0,
      photo_prefix: role === "student" ? $.trim($("#hst-import-photo-prefix").val() || "") : "",
    };
    const rowsToImport = role === "student" && autoMode
      ? validRows.map((row) => Object.assign({}, row, { class_id: row._class_id }))
      : validRows;

    const confirmResult = await window.HSTModal.open({
      title: role === "teacher" ? "ثبت معلم‌ها" : "ثبت دانش‌آموزان",
      text: validRows.length + (role === "teacher" ? " معلم" : " دانش‌آموز") + " ثبت یا به‌روزرسانی شوند؟",
    });

    if (!confirmResult.isConfirmed) {
      return;
    }

    const restoreTrigger = HST.setBusy(this);
    const hasPrefix = role === "student" && $.trim($("#hst-import-photo-prefix").val() || "").length > 0;
    const progress = startImportProgress(role, validRows.length, hasPrefix);

    try {
      const data = await importRowsInBatches(requestPayload, rowsToImport, role, hasPrefix, progress);
      let html = '<div class="hst-report-stats" id="final-stats">';

      html += '<div class="hst-report-stat hst-report-stat--new"><b>' + (data.created || 0) + '</b><span>ثبت جدید</span></div>';
      html += '<div class="hst-report-stat hst-report-stat--upd"><b>' + (data.updated || 0) + '</b><span>به‌روزرسانی</span></div>';
      html += '<div class="hst-report-stat"><b>' + (data.renamed || 0) + '</b><span>اصلاح نام کاربری</span></div>';
      html += '<div class="hst-report-stat hst-report-stat--skip"><b>' + (data.skipped || 0) + '</b><span>رد‌شده</span></div>';
      if (hasPrefix) {
        html += '<div class="hst-report-stat hst-report-stat--photo"><b>' + (data.photos || 0) + '</b><span>عکس دریافت‌شده</span></div>';
      }
      html += "</div>";

      if (data.errors && data.errors.length) {
        html += importErrorHtml(data.errors);
      }

      progress.finish("ثبت جدید: " + (data.created || 0) + "، به‌روزرسانی: " + (data.updated || 0) + "، رد‌شده: " + (data.skipped || 0));

      $("#hst-import-result-body").html(html);
      $("#hst-import-result-card").removeAttr("hidden");
      $("#hst-import-preview-card").attr("hidden", true);
      $("#hst-import-paste").val("");
      parsedRows = [];
      parsedRowsSource = "";
    } catch (error) {
      console.error("HST import request failed:", error);
      const errorMessage = HST.getMessage(error, error && error.message ? error.message : "ارتباط با سرور برقرار نشد");
      progress.fail(errorMessage);
      HST.toast(errorMessage, "error");
    } finally {
      restoreTrigger();
    }
  });
});
