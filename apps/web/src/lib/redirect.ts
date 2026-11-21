/**
 * `?next=` hanya boleh path relatif di aplikasi ini (mencegah open redirect ke situs lain:
 * `//evil.com`, `https://…`, `/\evil.com`).
 */
export function safeNext(next: string | null | undefined, fallback = '/'): string {
  if (!next || !next.startsWith('/') || next.startsWith('//') || next.startsWith('/\\')) return fallback;
  if (next.startsWith('/login') || next.startsWith('/register')) return fallback;
  return next;
}

export function withNext(path: string, next: string): string {
  return `${path}?next=${encodeURIComponent(next)}`;
}
