import { afterEach, describe, expect, it, vi } from 'vitest';
import { CLIENT_ID_PATTERN, newClientId } from './client-id';

describe('newClientId', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('returns a UUID v4', () => {
    expect(newClientId()).toMatch(CLIENT_ID_PATTERN);
  });

  it('returns a new value on every call', () => {
    const ids = new Set(Array.from({ length: 1000 }, () => newClientId()));
    expect(ids.size).toBe(1000);
  });

  it('falls back to getRandomValues outside a secure context (http://<lan-ip>)', () => {
    const real = globalThis.crypto;
    vi.stubGlobal('crypto', { getRandomValues: real.getRandomValues.bind(real) });

    const id = newClientId();
    expect(id).toMatch(CLIENT_ID_PATTERN);
    expect(newClientId()).not.toBe(id);
  });

  it('fails loudly without any crypto source', () => {
    vi.stubGlobal('crypto', undefined);
    expect(() => newClientId()).toThrow(/getRandomValues/);
  });
});
