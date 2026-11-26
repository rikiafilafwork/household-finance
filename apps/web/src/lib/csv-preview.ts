import type { DateFormat } from '@/api/imports';

/**
 * Cuplikan CSV di browser untuk wizard pemetaan kolom. Parsing sungguhan tetap di server
 * (App\Imports\Csv\CsvReader); di sini cukup untuk menampilkan header dan beberapa baris.
 * Aturan delimiter & record kosong sengaja sama dengan server supaya `skip_rows` berarti sama.
 */

export type Delimiter = ',' | ';' | '\t';
const DELIMITERS: Delimiter[] = [',', ';', '\t'];

/** Delimiter yang paling sering muncul di luar tanda kutip pada 20 baris pertama; seri → koma. */
export function detectDelimiter(text: string): Delimiter {
  const sample = text.split(/\r\n|\r|\n/).slice(0, 20).join('\n').replace(/"(?:[^"]|"")*"/g, '');
  let best: Delimiter = ',';
  let bestCount = 0;
  for (const candidate of DELIMITERS) {
    const count = sample.split(candidate).length - 1;
    if (count > bestCount) [best, bestCount] = [candidate, count];
  }
  return best;
}

/** Record CSV (tanda kutip ganda, "" sebagai kutip), sel di-trim, record kosong dibuang. */
export function parseCsv(text: string, delimiter: Delimiter, maxRecords = 50): string[][] {
  const records: string[][] = [];
  let cells: string[] = [];
  let cell = '';
  let quoted = false;

  const endRecord = () => {
    cells.push(cell.trim());
    if (cells.join('') !== '') records.push(cells);
    cells = [];
    cell = '';
  };

  for (let i = 0; i < text.length && records.length < maxRecords; i++) {
    const ch = text[i];
    if (quoted) {
      if (ch === '"' && text[i + 1] === '"') {
        cell += '"';
        i++;
      } else if (ch === '"') {
        quoted = false;
      } else {
        cell += ch;
      }
    } else if (ch === '"') {
      quoted = true;
    } else if (ch === delimiter) {
      cells.push(cell.trim());
      cell = '';
    } else if (ch === '\n' || ch === '\r') {
      if (ch === '\r' && text[i + 1] === '\n') i++;
      endRecord();
    } else {
      cell += ch;
    }
  }
  if (records.length < maxRecords && (cell !== '' || cells.length > 0)) endRecord();

  return records;
}

/** Bagian awal file sebagai teks (BOM UTF-8 dibuang). Cukup untuk header + cuplikan. */
export async function readHead(file: File, bytes = 64 * 1024): Promise<string> {
  const text = await file.slice(0, bytes).text();
  return text.replace(/^\uFEFF/, '');
}

const DATE_GUESSES: [RegExp, DateFormat][] = [
  [/^\d{4}-\d{1,2}-\d{1,2}$/, 'Y-m-d'],
  [/^\d{4}\/\d{1,2}\/\d{1,2}$/, 'Y/m/d'],
  [/^\d{1,2}\/\d{1,2}\/\d{4}$/, 'd/m/Y'],
  [/^\d{1,2}-\d{1,2}-\d{4}$/, 'd-m-Y'],
  [/^\d{1,2}\.\d{1,2}\.\d{4}$/, 'd.m.Y'],
  [/^\d{1,2}\/\d{1,2}\/\d{2}$/, 'd/m/y'],
  [/^\d{1,2}-\d{1,2}-\d{2}$/, 'd-m-y'],
  [/^\d{1,2} \p{L}+ \d{4}$/u, 'd M Y'],
  [/^\d{1,2}-\p{L}+-\d{4}$/u, 'd-M-Y'],
  [/^\d{1,2} \p{L}+ \d{2}$/u, 'd M y'],
  [/^\d{1,2}-\p{L}+-\d{2}$/u, 'd-M-y'],
  [/^\d{1,2}\/\d{1,2}$/, 'd/m'],
  [/^\d{1,2}-\d{1,2}$/, 'd-m'],
  [/^\d{1,2} \p{L}+$/u, 'd M'],
];

/** Format tanggal yang cocok dengan mayoritas nilai contoh (tanpa `'` di depan ala Excel). */
export function guessDateFormat(values: string[]): DateFormat | null {
  const cleaned = values.map((v) => v.replace(/^'/, '').trim()).filter(Boolean);
  for (const [pattern, format] of DATE_GUESSES) {
    const hits = cleaned.filter((v) => pattern.test(v)).length;
    if (hits > 0 && hits * 2 >= cleaned.length) return format;
  }
  return null;
}

/**
 * Pemisah desimal dari contoh nominal: `1.250.000,00` → `,`; `1,250,000.00 DB` → `.`.
 * Angka tanpa desimal (`25.000`) dianggap format Indonesia.
 */
export function guessDecimalSeparator(values: string[]): ',' | '.' {
  let dot = 0;
  let comma = 0;
  for (const raw of values) {
    const v = raw.replace(/\s*[A-Za-z]+\.?$/, '').trim();
    if (/\.\d{1,2}$/.test(v) || /^\d{1,3}(,\d{3})+(\.\d+)?$/.test(v)) dot++;
    else if (/,\d{1,2}$/.test(v) || /^\d{1,3}(\.\d{3})+$/.test(v)) comma++;
  }
  return dot > comma ? '.' : ',';
}
