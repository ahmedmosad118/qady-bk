// Pure helpers for the barcode label printing screen (no DOM / no Vue).

const SETTINGS_KEY = 'qady.barcodePrint.settings.v1';

// mode "roll": one label per page (page size = label size, e.g. thermal printers).
// mode "sheet": labels laid out on a sheet (A4 sticker paper or custom paper).
export const PRESETS = [
  { key: 'roll-30x20', mode: 'roll', width: 30, height: 20 },
  { key: 'roll-38x25', mode: 'roll', width: 38, height: 25 },
  { key: 'roll-40x30', mode: 'roll', width: 40, height: 30 },
  { key: 'roll-50x25', mode: 'roll', width: 50, height: 25 },
  { key: 'roll-50x30', mode: 'roll', width: 50, height: 30 },
  { key: 'roll-58x40', mode: 'roll', width: 58, height: 40 },
  { key: 'roll-60x40', mode: 'roll', width: 60, height: 40 },
  { key: 'roll-70x40', mode: 'roll', width: 70, height: 40 },
  { key: 'roll-100x50', mode: 'roll', width: 100, height: 50 },
  { key: 'roll-100x150', mode: 'roll', width: 100, height: 150 },
  { key: 'a4-3x8', mode: 'sheet', width: 70, height: 37, columns: 3, gapX: 0, gapY: 0, marginTop: 0, marginLeft: 0, paperWidth: 210, paperHeight: 297 },
  { key: 'a4-3x7', mode: 'sheet', width: 63.5, height: 38.1, columns: 3, gapX: 2.5, gapY: 0, marginTop: 15.1, marginLeft: 7.2, paperWidth: 210, paperHeight: 297 },
  { key: 'a4-4x11', mode: 'sheet', width: 48.5, height: 25.4, columns: 4, gapX: 0, gapY: 0, marginTop: 8.8, marginLeft: 8, paperWidth: 210, paperHeight: 297 },
  { key: 'a4-5x13', mode: 'sheet', width: 38, height: 21.2, columns: 5, gapX: 2.5, gapY: 0, marginTop: 10.7, marginLeft: 6.5, paperWidth: 210, paperHeight: 297 },
  { key: 'a4-2x5', mode: 'sheet', width: 99, height: 57, columns: 2, gapX: 0, gapY: 0, marginTop: 6, marginLeft: 6, paperWidth: 210, paperHeight: 297 },
];

export const DEFAULT_SETTINGS = {
  preset: 'roll-50x30',
  mode: 'roll',
  width: 50,
  height: 30,
  columns: 3,
  gapX: 0,
  gapY: 0,
  marginTop: 0,
  marginLeft: 0,
  paperWidth: 210,
  paperHeight: 297,
  showName: true,
  showPrice: true,
  showAuthor: false,
  showPublisher: false,
  showBarcodeText: true,
  fontSize: 8,
  padding: 1,
  barcodeType: 'auto', // auto | CODE128 | EAN13
};

const num = (v, fallback, min = 0, max = 1000) => {
  const n = Number(v);
  return Number.isFinite(n) ? Math.min(Math.max(n, min), max) : fallback;
};

export function sanitizeSettings(raw = {}) {
  const s = { ...DEFAULT_SETTINGS, ...raw };
  return {
    ...s,
    mode: s.mode === 'sheet' ? 'sheet' : 'roll',
    width: num(s.width, DEFAULT_SETTINGS.width, 10, 300),
    height: num(s.height, DEFAULT_SETTINGS.height, 8, 300),
    columns: Math.round(num(s.columns, 1, 1, 12)),
    gapX: num(s.gapX, 0, 0, 50),
    gapY: num(s.gapY, 0, 0, 50),
    marginTop: num(s.marginTop, 0, 0, 100),
    marginLeft: num(s.marginLeft, 0, 0, 100),
    paperWidth: num(s.paperWidth, 210, 50, 1000),
    paperHeight: num(s.paperHeight, 297, 50, 1000),
    fontSize: num(s.fontSize, 8, 4, 24),
    padding: num(s.padding, 1, 0, 10),
    barcodeType: ['auto', 'CODE128', 'EAN13'].includes(s.barcodeType) ? s.barcodeType : 'auto',
  };
}

export function loadSettings() {
  try {
    const raw = window.localStorage.getItem(SETTINGS_KEY);
    return sanitizeSettings(raw ? JSON.parse(raw) : {});
  } catch {
    return sanitizeSettings();
  }
}

export function saveSettings(settings) {
  try {
    window.localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings));
  } catch {
    // storage unavailable: settings simply are not remembered
  }
}

export function applyPreset(settings, key) {
  const preset = PRESETS.find((p) => p.key === key);
  if (!preset) return sanitizeSettings({ ...settings, preset: 'custom' });
  const { key: presetKey, ...values } = preset;
  return sanitizeSettings({ ...settings, ...values, preset: presetKey });
}

export function isValidEan13(code) {
  if (!/^\d{13}$/.test(code)) return false;
  let sum = 0;
  for (let i = 0; i < 12; i++) sum += Number(code[i]) * (i % 2 === 0 ? 1 : 3);
  return (10 - (sum % 10)) % 10 === Number(code[12]);
}

/** JsBarcode format for a code, honoring the user's choice but never failing. */
export function resolveFormat(code, barcodeType) {
  if (barcodeType === 'CODE128') return 'CODE128';
  return isValidEan13(String(code)) ? 'EAN13' : 'CODE128';
}

/** Repeat each label by its copies count. */
export function expandLabels(labels) {
  return labels.flatMap((l) => Array.from({ length: l.copies }, () => l));
}

export function rowsPerPage(s) {
  if (s.mode !== 'sheet') return 1;
  const usable = s.paperHeight - s.marginTop;
  return Math.max(1, Math.floor((usable + s.gapY) / (s.height + s.gapY)));
}

/** Group labels into printed pages. */
export function paginate(labels, s) {
  const perPage = s.mode === 'sheet' ? s.columns * rowsPerPage(s) : 1;
  const pages = [];
  for (let i = 0; i < labels.length; i += perPage) pages.push(labels.slice(i, i + perPage));
  return pages;
}

export function pageCss(s) {
  const w = s.mode === 'sheet' ? s.paperWidth : s.width;
  const h = s.mode === 'sheet' ? s.paperHeight : s.height;
  return `@page { size: ${w}mm ${h}mm; margin: 0; }`;
}
