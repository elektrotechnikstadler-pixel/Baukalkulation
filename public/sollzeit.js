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

  global.bkTagesSoll = bkTagesSoll;
  global.bkIstStundenEintrag = bkIstStundenEintrag;
})(window);
