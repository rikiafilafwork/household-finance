import axios, { AxiosError, type AxiosInstance, type InternalAxiosRequestConfig } from 'axios';
import { toast } from 'sonner';

/**
 * Satu klien HTTP untuk semua halaman (#32). Sanctum SPA: cookie sesi + X-XSRF-TOKEN,
 * satu origin dengan API (nginx), jadi tidak perlu CORS.
 *
 * Penanganan error seragam:
 *   401 → onUnauthorized() (router mengarahkan ke /login)
 *   403 → toast "tidak punya akses"
 *   419 → CSRF/sesi kedaluwarsa: ambil cookie baru, ulangi SEKALI
 *   422 → TIDAK ada toast; ApiError.fieldErrors dipetakan ke form oleh pemanggil
 *   429 / 5xx / jaringan → toast
 */

export class ApiError extends Error {
  constructor(
    public readonly status: number, // 0 = jaringan / tanpa respons
    message: string,
    public readonly fieldErrors: Record<string, string[]> = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }

  get isValidation() {
    return this.status === 422;
  }

  /** Pesan pertama untuk field (atau prefix field, mis. `items.0`). */
  first(field: string): string | undefined {
    return this.fieldErrors[field]?.[0];
  }
}

type RetriableConfig = InternalAxiosRequestConfig & { _csrfRetried?: boolean; silent?: boolean };

declare module 'axios' {
  interface AxiosRequestConfig {
    /** Jangan tampilkan toast untuk error ini (pemanggil menanganinya sendiri). */
    silent?: boolean;
  }
}

const MUTATING = new Set(['post', 'put', 'patch', 'delete']);

let unauthorizedHandler: () => void = () => {};
export function onUnauthorized(handler: () => void) {
  unauthorizedHandler = handler;
}

export function createApiClient(): AxiosInstance & { resetCsrf(): void } {
  const http = axios.create({
    baseURL: '/api',
    withCredentials: true,
    withXSRFToken: true,
    xsrfCookieName: 'XSRF-TOKEN',
    xsrfHeaderName: 'X-XSRF-TOKEN',
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  });

  // Cookie CSRF diambil sekali per muat halaman, hanya sebelum request yang mengubah data.
  let csrf: Promise<unknown> | null = null;
  const ensureCsrf = () => (csrf ??= http.get('/sanctum/csrf-cookie', { baseURL: '/' }).catch((e) => {
    csrf = null;
    throw e;
  }));

  http.interceptors.request.use(async (config) => {
    if (MUTATING.has((config.method ?? 'get').toLowerCase())) {
      await ensureCsrf();
    }
    return config;
  });

  http.interceptors.response.use(
    (response) => response,
    async (error: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
      const config = error.config as RetriableConfig | undefined;
      const status = error.response?.status ?? 0;

      if (status === 419 && config && !config._csrfRetried) {
        csrf = null;
        config._csrfRetried = true;
        await ensureCsrf();
        return http.request(config);
      }

      const apiError = toApiError(error);
      if (!config?.silent) {
        report(apiError);
      }
      return Promise.reject(apiError);
    },
  );

  return Object.assign(http, { resetCsrf: () => (csrf = null) });
}

function toApiError(error: AxiosError<{ message?: string; errors?: Record<string, string[]> }>): ApiError {
  const status = error.response?.status ?? 0;
  const body = error.response?.data;

  if (status === 0) return new ApiError(0, 'Tidak bisa terhubung ke server. Periksa koneksi Anda.');
  if (status === 422) return new ApiError(422, body?.message ?? 'Periksa kembali isian Anda.', body?.errors ?? {});
  if (status === 401) return new ApiError(401, 'Sesi Anda berakhir. Silakan masuk lagi.');
  if (status === 403) return new ApiError(403, 'Anda tidak punya akses untuk tindakan ini.');
  if (status === 404) return new ApiError(404, body?.message || 'Data tidak ditemukan.');
  if (status === 410) return new ApiError(410, body?.message || 'Tautan ini sudah tidak berlaku.');
  if (status === 429) return new ApiError(429, 'Terlalu banyak percobaan. Coba lagi sebentar lagi.');
  return new ApiError(status, 'Terjadi kesalahan di server. Coba lagi.');
}

function report(error: ApiError) {
  switch (error.status) {
    case 401:
      unauthorizedHandler();
      return;
    case 422: // ditampilkan di form
    case 404: // ditangani halaman
    case 410:
      return;
    default:
      toast.error(error.message);
  }
}

export const api = createApiClient();

/** Ambil `data` dari respons `{ data: ... }`. */
export async function unwrap<T>(promise: Promise<{ data: { data: T } }>): Promise<T> {
  return (await promise).data.data;
}
