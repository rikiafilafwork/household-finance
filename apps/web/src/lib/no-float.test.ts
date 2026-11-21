import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

// Jaring kedua selain ESLint (Keputusan #1): tidak ada parseFloat/toFixed di src.
function files(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    return statSync(path).isDirectory() ? files(path) : /\.(ts|tsx)$/.test(name) && !name.endsWith('.test.ts') ? [path] : [];
  });
}

describe('no float in money paths', () => {
  it('never calls parseFloat or toFixed in src', () => {
    const offenders = files(join(__dirname, '..')).filter((file) => /\bparseFloat\s*\(|\.toFixed\s*\(/.test(readFileSync(file, 'utf8')));
    expect(offenders).toEqual([]);
  });
});
