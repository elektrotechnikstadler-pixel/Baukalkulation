(function (global) {
  const weekdayFields = [
    'sollstundenMo',
    'sollstundenDi',
    'sollstundenMi',
    'sollstundenDo',
    'sollstundenFr',
    'sollstundenSa',
    'sollstundenSo',
  ];

  function isoWeekday(dateValue) {
    let date;
    if (dateValue instanceof Date) {
      date = new Date(dateValue.getTime());
    } else if (typeof dateValue === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(dateValue)) {
      date = new Date(dateValue + 'T00:00:00');
      const localDate = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
      if (Number.isNaN(date.getTime()) || localDate !== dateValue) return null;
    } else {
      return null;
    }

    if (Number.isNaN(date.getTime())) return null;
    const weekday = date.getDay();
    return weekday === 0 ? 7 : weekday;
  }

  function numericValue(value) {
    if (typeof value === 'boolean' || value === null || value === undefined) return null;
    if (typeof value !== 'number' && typeof value !== 'string') return null;
    const normalized = String(value).trim();
    if (!/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/.test(normalized)) return null;
    const number = Number(normalized);
    return Number.isFinite(number) ? number : null;
  }

  function legacyWorkdays(user) {
    const raw = user && user.arbeitstage;
    const days = [];
    if (raw && typeof raw === 'object') {
      Object.entries(raw).forEach(([key, enabled]) => {
        const day = Number(key);
        if (enabled && Number.isInteger(day) && day >= 1 && day <= 7) days.push(day);
      });
    } else if (typeof raw === 'string') {
      raw.split(',').forEach(value => {
        const day = Number(value.trim());
        if (Number.isInteger(day) && day >= 1 && day <= 7) days.push(day);
      });
    }
    if (days.length) return new Set(days);

    const configuredDays = numericValue(user && user.sollTageWoche);
    const count = Math.min(7, Math.max(0, configuredDays === null ? 5 : Math.trunc(configuredDays)));
    return new Set(Array.from({ length: count }, (_, index) => index + 1));
  }

  function bkTagesSoll(user, isoDatumOderDate) {
    if (!user || typeof user !== 'object') return 0;
    const weekday = isoWeekday(isoDatumOderDate);
    if (weekday === null) return 0;

    if (user.sollzeitJeWochentag === true || Number(user.sollzeitJeWochentag) === 1) {
      const value = numericValue(user[weekdayFields[weekday - 1]]);
      return value === null ? 0 : value;
    }

    if (!legacyWorkdays(user).has(weekday)) return 0;
    const dailySoll = numericValue(user.sollstundenTag ?? user.sollTag);
    return dailySoll === null ? 8 : dailySoll;
  }

  function bkIstStundenEintrag(user, entry, customTypen) {
    if (!entry || typeof entry !== 'object') return 0;
    const type = entry.typ;
    const hours = numericValue(entry.stunden) ?? 0;
    if (type === 'arbeit') return hours;
    if (type === 'urlaub' || type === 'krank' || type === 'feiertag') return bkTagesSoll(user, entry.datum);
    if (type === 'abwesend' || type === 'gleitzeit' || type === 'sonstig') return 0;

    const custom = Array.isArray(customTypen) ? customTypen : [];
    const definition = custom.find(item => (typeof item === 'string' ? item : item && item.value) === type);
    if (typeof definition === 'string') return hours;
    if (!definition || definition.isArbeit === false) return 0;
    return definition.istGleichSoll === true ? bkTagesSoll(user, entry.datum) : hours;
  }

  function dateKeyFromParts(year, month, day) {
    return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
  }

  function localDateFromKey(value) {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
    const year = Number(value.slice(0, 4));
    const month = Number(value.slice(5, 7));
    const day = Number(value.slice(8, 10));
    const date = new Date(year, month - 1, day);
    if (
      Number.isNaN(date.getTime())
      || date.getFullYear() !== year
      || date.getMonth() !== month - 1
      || date.getDate() !== day
    ) {
      return null;
    }
    return date;
  }

  function dateKey(date) {
    return dateKeyFromParts(date.getFullYear(), date.getMonth() + 1, date.getDate());
  }

  function addDaysKey(value, days) {
    const date = localDateFromKey(value);
    if (!date) return null;
    date.setDate(date.getDate() + days);
    return dateKey(date);
  }

  function todayKey() {
    return dateKey(new Date());
  }

  function easterSundayKey(year) {
    const a = year % 19;
    const b = Math.floor(year / 100);
    const c = year % 100;
    const d = Math.floor(b / 4);
    const e = b % 4;
    const f = Math.floor((b + 8) / 25);
    const g = Math.floor((b - f + 1) / 3);
    const h = (19 * a + b - d - g + 15) % 30;
    const i = Math.floor(c / 4);
    const k = c % 4;
    const l = (32 + 2 * e + 2 * i - h - k) % 7;
    const m = Math.floor((a + 11 * h + 22 * l) / 451);
    const month = Math.floor((h + l - 7 * m + 114) / 31);
    const day = ((h + l - 7 * m + 114) % 31) + 1;
    return dateKeyFromParts(year, month, day);
  }

  function normalizeCustomFeiertage(customFeiertage) {
    let raw = customFeiertage ?? [];
    if (typeof raw === 'string') {
      try {
        const decoded = JSON.parse(raw);
        raw = Array.isArray(decoded) ? decoded : [];
      } catch (error) {
        raw = [];
      }
    }
    return Array.isArray(raw) ? raw : [];
  }

  function bkFeiertage(jahr, customFeiertage) {
    const year = Number(jahr);
    if (!Number.isInteger(year)) return new Map();
    const easter = easterSundayKey(year);
    const entries = [
      [dateKeyFromParts(year, 1, 1), 'Neujahr'],
      [dateKeyFromParts(year, 1, 6), 'Heilige Drei Könige'],
      [addDaysKey(easter, -2), 'Karfreitag'],
      [addDaysKey(easter, 1), 'Ostermontag'],
      [dateKeyFromParts(year, 5, 1), 'Tag der Arbeit'],
      [addDaysKey(easter, 39), 'Christi Himmelfahrt'],
      [addDaysKey(easter, 50), 'Pfingstmontag'],
      [addDaysKey(easter, 60), 'Fronleichnam'],
      [dateKeyFromParts(year, 8, 15), 'Mariä Himmelfahrt'],
      [dateKeyFromParts(year, 10, 3), 'Tag der Deutschen Einheit'],
      [dateKeyFromParts(year, 11, 1), 'Allerheiligen'],
      [dateKeyFromParts(year, 12, 25), '1. Weihnachtstag'],
      [dateKeyFromParts(year, 12, 26), '2. Weihnachtstag'],
    ];

    const holidays = new Map(entries);
    normalizeCustomFeiertage(customFeiertage).forEach(custom => {
      if (!custom || typeof custom !== 'object') return;
      const datum = String(custom.datum ?? '');
      if (!localDateFromKey(datum) || Number(datum.slice(0, 4)) !== year) return;
      const name = String(custom.name ?? '').trim();
      holidays.set(datum, name !== '' ? name : 'Feiertag');
    });

    return new Map(Array.from(holidays.entries()).sort(([left], [right]) => left.localeCompare(right)));
  }

  function bkVirtuelleFeiertage(entries, user, jahr, monat, customFeiertage) {
    const monatPrefix = typeof monat === 'string' && /^\d{4}-\d{2}$/.test(monat) ? `${monat}-` : null;
    const sourceEntries = Array.isArray(entries) ? entries : [];
    const entryDates = new Set(
      sourceEntries
        .map(entry => (entry && typeof entry === 'object' ? entry.datum : null))
        .filter(datum => typeof datum === 'string'),
    );
    const result = sourceEntries.slice(0, 0);

    bkFeiertage(jahr, customFeiertage).forEach((name, datum) => {
      if (monatPrefix && !datum.startsWith(monatPrefix)) return;
      const stunden = bkTagesSoll(user, datum);
      if (stunden <= 0 || entryDates.has(datum)) return;
      result.push({
        datum,
        typ: 'feiertag',
        stunden,
        bemerkung: name,
        _virtual: true,
      });
    });

    return result;
  }

  function roundLikePhp(value) {
    const sign = value < 0 ? -1 : 1;
    return sign * (Math.round(Math.abs(value) * 100 + Number.EPSILON) / 100);
  }

  function bkGleitzeitSaldo(entries, user, jahr, buchungen, options) {
    const year = Number(jahr);
    if (!Number.isInteger(year)) return 0;
    let start = dateKeyFromParts(year, 1, 1);
    const jahrEnde = dateKeyFromParts(year, 12, 31);
    let stichtag = options && localDateFromKey(options.heute) ? options.heute : todayKey();
    if (stichtag > jahrEnde) stichtag = jahrEnde;

    const startdatum = options && localDateFromKey(options.startdatum) ? options.startdatum : null;
    if (startdatum !== null && startdatum > start) start = startdatum;
    if (start > stichtag) return 0;

    const username = user && typeof user === 'object' ? user.username : null;
    const customTypen = options && Array.isArray(options.customTypen) ? options.customTypen : [];
    const feiertage = bkFeiertage(year, options && options.customFeiertage);
    const entryDates = new Set();
    let ist = 0;

    (Array.isArray(entries) ? entries : []).forEach(entry => {
      if (!entry || typeof entry !== 'object') return;
      if (username && entry.username !== undefined && entry.username !== username) return;
      const datum = typeof entry.datum === 'string' ? entry.datum : '';
      if (!localDateFromKey(datum) || datum < start || datum > stichtag) return;
      entryDates.add(datum);
      ist += bkIstStundenEintrag(user, entry, customTypen);
    });

    let soll = 0;
    for (let datum = start; datum !== null && datum <= stichtag; datum = addDaysKey(datum, 1)) {
      const tagesSoll = bkTagesSoll(user, datum);
      soll += tagesSoll;
      if (tagesSoll > 0 && feiertage.has(datum) && !entryDates.has(datum)) {
        ist += tagesSoll;
      }
    }

    (Array.isArray(buchungen) ? buchungen : []).forEach(buchung => {
      if (!buchung || typeof buchung !== 'object') return;
      if (username && buchung.username !== undefined && buchung.username !== username) return;
      const datum = typeof buchung.datum === 'string' ? buchung.datum : '';
      if (!localDateFromKey(datum) || datum < start || datum > stichtag) return;
      ist += numericValue(buchung.betrag) ?? 0;
    });

    return roundLikePhp(ist - soll);
  }

  function bkUrlaubsanspruch(user, jahr) {
    let raw = user && typeof user === 'object' ? user.urlaubstageProJahr : undefined;
    if (typeof raw === 'string') {
      try {
        const decoded = JSON.parse(raw);
        raw = decoded && typeof decoded === 'object' ? decoded : {};
      } catch (error) {
        raw = {};
      }
    }
    if (!raw || typeof raw !== 'object') return 30;

    const key = String(jahr);
    if (!Object.prototype.hasOwnProperty.call(raw, key)) return 30;
    const value = numericValue(raw[key]);
    return value === null ? 30 : value;
  }

  function formatHours(value) {
    const rounded = Math.round(value * 100) / 100;
    return `${String(Number(rounded.toFixed(2))).replace('.', ',')} h`;
  }

  function dayRangeLabel(startIndex, endIndex) {
    const labels = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
    return startIndex === endIndex ? labels[startIndex] : `${labels[startIndex]}–${labels[endIndex]}`;
  }

  function groupDayLabels(dayValues) {
    const groups = [];
    let start = 0;
    for (let index = 1; index <= dayValues.length; index += 1) {
      const current = dayValues[index];
      const previous = dayValues[index - 1];
      if (index < dayValues.length && current.index === previous.index + 1 && current.value === previous.value) continue;
      groups.push({ start, end: index - 1, value: previous.value });
      start = index;
    }
    return groups;
  }

  function bkSollBeschreibung(user) {
    if (!user || typeof user !== 'object') return 'kein Soll';

    if (user.sollzeitJeWochentag === true || Number(user.sollzeitJeWochentag) === 1) {
      const dayValues = weekdayFields
        .map((field, index) => ({ index, value: numericValue(user[field]) ?? 0 }))
        .filter(day => day.value > 0);
      if (!dayValues.length) return 'kein Soll';

      return groupDayLabels(dayValues)
        .map(group => `${dayRangeLabel(dayValues[group.start].index, dayValues[group.end].index)} ${formatHours(group.value)}`)
        .join(' · ');
    }

    const soll = numericValue(user.sollstundenTag ?? user.sollTag);
    const stunden = soll === null ? 8 : soll;
    const days = Array.from(legacyWorkdays(user)).sort((left, right) => left - right);
    if (stunden <= 0 || !days.length) return 'kein Soll';

    const dayValues = days.map(day => ({ index: day - 1, value: stunden }));
    const labels = groupDayLabels(dayValues)
      .map(group => dayRangeLabel(dayValues[group.start].index, dayValues[group.end].index))
      .join(', ');
    return `${formatHours(stunden)}/Tag (${labels})`;
  }

  global.bkTagesSoll = bkTagesSoll;
  global.bkIstStundenEintrag = bkIstStundenEintrag;
  global.bkFeiertage = bkFeiertage;
  global.bkVirtuelleFeiertage = bkVirtuelleFeiertage;
  global.bkGleitzeitSaldo = bkGleitzeitSaldo;
  global.bkUrlaubsanspruch = bkUrlaubsanspruch;
  global.bkSollBeschreibung = bkSollBeschreibung;
})(window);
