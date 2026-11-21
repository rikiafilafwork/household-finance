/**
 * UUID v4 untuk `client_id` (Architecture.md Keputusan #11).
 *
 * Web: dibuat per submit form. Mobile: dibuat saat item masuk antrean dan TIDAK pernah diganti.
 *
 * `crypto.randomUUID()` hanya ada di secure context (HTTPS/localhost). Saat web diakses lewat
 * http://<ip-lan> (dogfooding di LAN), fallback ke `crypto.getRandomValues()` yang tetap tersedia.
 * React Native butuh polyfill getRandomValues (mis. `react-native-get-random-values` / expo-crypto).
 */
export function newClientId(): string {
  const c = globalThis.crypto as Crypto | undefined;

  if (typeof c?.randomUUID === 'function') {
    return c.randomUUID();
  }

  if (typeof c?.getRandomValues === 'function') {
    const bytes = c.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6]! & 0x0f) | 0x40; // versi 4
    bytes[8] = (bytes[8]! & 0x3f) | 0x80; // varian RFC 4122
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
  }

  throw new Error('Tidak ada sumber acak kriptografis (crypto.getRandomValues) untuk membuat client_id.');
}

export const CLIENT_ID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
