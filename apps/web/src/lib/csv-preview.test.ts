import { describe, expect, it } from 'vitest';
import { detectDelimiter, guessDateFormat, guessDecimalSeparator, parseCsv, readHead } from './csv-preview';

describe('detectDelimiter', () => {
  it.each([
    ['a,b,c\n1,2,3', ','],
    ['Tanggal;Ket;Jumlah\n03/11/2026;KOPI;25.000,00', ';'],
    ['a\tb\tc\n1\t2\t3', '\t'],
    ['03/11/2026,"KOPI; GULA","1,250,000.00"', ','],
    ['abc', ','],
  ])('%j → %j', (text, expected) => {
    expect(detectDelimiter(text)).toBe(expected);
  });
});

describe('parseCsv', () => {
  it('handles quotes, escaped quotes, CRLF and blank records like the server', () => {
    expect(parseCsv('Tanggal;Ket\r\n\r\n03/11/2026;"TEH; ""MANIS"""\n;\n04/11/2026; KOPI \n', ';')).toEqual([
      ['Tanggal', 'Ket'],
      ['03/11/2026', 'TEH; "MANIS"'],
      ['04/11/2026', 'KOPI'],
    ]);
  });

  it('keeps a final record without trailing newline and stops at the limit', () => {
    expect(parseCsv('a,b\n1,2', ',')).toEqual([['a', 'b'], ['1', '2']]);
    expect(parseCsv('1\n2\n3\n4\n', ',', 2)).toEqual([['1'], ['2']]);
  });
});

it('reads the head of a file without the UTF-8 BOM', async () => {
  const file = new File(['\uFEFFTanggal,Ket\n03/11/2026,KOPI\n'], 'm.csv', { type: 'text/csv' });
  expect(await readHead(file)).toBe('Tanggal,Ket\n03/11/2026,KOPI\n');
});

describe('guessDateFormat', () => {
  it.each([
    [['03/11/2026', '15/11/2026'], 'd/m/Y'],
    [['2026-11-03'], 'Y-m-d'],
    [["'03/11", "'15/11", 'PEND'], 'd/m'],
    [['03 Nov 2026', '17 Agu 2026'], 'd M Y'],
    [['03-Okt-26'], 'd-M-y'],
    [['03.11.2026'], 'd.m.Y'],
  ] as const)('%j → %s', (values, expected) => {
    expect(guessDateFormat([...values])).toBe(expected);
  });

  it('gives up on text that is not a date', () => {
    expect(guessDateFormat(['KOPI', 'GAJI'])).toBeNull();
    expect(guessDateFormat([])).toBeNull();
  });
});

describe('guessDecimalSeparator', () => {
  it.each([
    [['1.250.000,00', '25.000,00'], ','],
    [['25.000', '1.250'], ','],
    [['1,250,000.00 DB', '25,000.00 CR'], '.'],
    [['25000.00'], '.'],
    [['25000'], ','],
  ] as const)('%j → %j', (values, expected) => {
    expect(guessDecimalSeparator([...values])).toBe(expected);
  });
});
