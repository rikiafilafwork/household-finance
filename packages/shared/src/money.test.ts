import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import {
  InvalidMoneyFormatError,
  formatIDR,
  formatThousands,
  parseIDRInput,
  percentOf,
  reachesPercent,
  tryParseIDRInput,
} from './money';

// Kasus sama dengan apps/api/tests/Unit/MoneyTest.php supaya PHP dan TS berperilaku identik.
describe('parseIDRInput', () => {
  it.each([
    ['Rp1.250.000', 1_250_000],
    ['Rp 1.250.000', 1_250_000],
    ['rp. 25.000', 25_000],
    ['1.250', 1_250],
    ['1250000', 1_250_000],
    ['1.250.000,00', 1_250_000],
    ['Rp50.000,0', 50_000],
    ['  75.000  ', 75_000],
    ['-Rp50.000', -50_000],
    ['Rp-50.000', -50_000],
    ['0', 0],
    ['000123', 123],
    ['Rp9.000.000.000.000', 9_000_000_000_000],
  ])('%s → %i', (input, expected) => {
    expect(parseIDRInput(input)).toBe(expected);
  });

  it('never treats a dot as a decimal separator (unlike Number("1.250") === 1.25)', () => {
    expect(Number('1.250')).toBe(1.25);
    expect(parseIDRInput('1.250')).toBe(1250);
  });

  it.each([
    '1.250,50',
    '1.250,5',
    '1.250,000',
    '',
    'Rp',
    'seratus ribu',
    '1.25',
    '12.50.000',
    '1.250.',
    '.250',
    '1,250,000',
    '--50.000',
    '-Rp-50.000',
    '1e6',
    '99999999999999999999',
    '9007199254740992', // MAX_SAFE_INTEGER + 1
  ])('rejects %j', (input) => {
    expect(() => parseIDRInput(input)).toThrow(InvalidMoneyFormatError);
  });

  it('tryParseIDRInput returns null instead of throwing', () => {
    expect(tryParseIDRInput('1.250,50')).toBeNull();
    expect(tryParseIDRInput('Rp1.000')).toBe(1000);
  });
});

describe('format', () => {
  it.each([
    [1_250_000, 'Rp1.250.000'],
    [1_250, 'Rp1.250'],
    [999, 'Rp999'],
    [0, 'Rp0'],
    [-50_000, '-Rp50.000'],
    [1_000_000_000_000, 'Rp1.000.000.000.000'],
  ])('formatIDR(%i) → %s', (amount, expected) => {
    expect(formatIDR(amount)).toBe(expected);
  });

  it('formatThousands omits the prefix', () => {
    expect(formatThousands(1_250_000)).toBe('1.250.000');
    expect(formatThousands(-1_000)).toBe('-1.000');
  });

  it('refuses non-integer amounts', () => {
    expect(() => formatIDR(1.5)).toThrow(TypeError);
  });

  it.each([0, 1, 999, 1_000, 25_000, 1_250_000, -75_500, 123_456_789_012])(
    'round-trips %i',
    (amount) => {
      expect(parseIDRInput(formatIDR(amount))).toBe(amount);
    },
  );
});

describe('percent', () => {
  it.each([
    [80_000, 100_000, 80],
    [79_999, 100_000, 79],
    [150_000, 100_000, 150],
    [1, 3, 33],
    [0, 500_000, 0],
  ])('percentOf(%i, %i) → %i', (part, whole, expected) => {
    expect(percentOf(part, whole)).toBe(expected);
  });

  it('rejects a non-positive whole', () => {
    expect(() => percentOf(1, 0)).toThrow(RangeError);
  });

  it('compares thresholds without division', () => {
    expect(reachesPercent(79_999, 100_000, 80)).toBe(false);
    expect(reachesPercent(80_000, 100_000, 80)).toBe(true);
    expect(reachesPercent(1_000_000, 1_000_000, 100)).toBe(true);
    expect(reachesPercent(999_999, 1_000_000, 100)).toBe(false);
  });
});

describe('no float parsing in shared source', () => {
  it('does not call parseFloat anywhere (comments ignored)', () => {
    const dir = dirname(fileURLToPath(import.meta.url));
    const stripComments = (code: string) =>
      code.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '');
    const offenders = readdirSync(dir)
      .filter((f) => f.endsWith('.ts') && !f.endsWith('.test.ts'))
      .filter((f) => /\bparseFloat\s*\(/.test(stripComments(readFileSync(join(dir, f), 'utf8'))));
    expect(offenders).toEqual([]);
  });
});
