import { describe, expect, it } from 'vitest';
import { safeNext } from './redirect';

describe('safeNext', () => {
  it.each([
    ['/h/1/transactions?wallet_id=2', '/h/1/transactions?wallet_id=2'],
    ['/invitations/abc', '/invitations/abc'],
    [null, '/'],
    ['', '/'],
    ['https://evil.example', '/'],
    ['//evil.example', '/'],
    ['/\\evil.example', '/'],
    ['javascript:alert(1)', '/'],
    ['/login', '/'],
  ])('%s → %s', (input, expected) => {
    expect(safeNext(input)).toBe(expected);
  });
});
