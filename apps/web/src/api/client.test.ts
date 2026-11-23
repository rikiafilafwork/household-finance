import type { AxiosAdapter, AxiosResponse, InternalAxiosRequestConfig } from 'axios';
import { AxiosError } from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const toastError = vi.fn();
vi.mock('sonner', () => ({ toast: { error: (msg: string) => toastError(msg) } }));

const { ApiError, createApiClient, onUnauthorized } = await import('./client');

type Reply = { status: number; data?: unknown };

/** Adapter palsu: mencatat setiap request, membalas dari antrean per "METHOD url". */
function fakeServer(routes: Record<string, Reply[]>) {
  const calls: string[] = [];
  const adapter: AxiosAdapter = async (config: InternalAxiosRequestConfig) => {
    const key = `${config.method?.toUpperCase()} ${(config.baseURL ?? '').replace(/\/$/, '')}${config.url}`;
    calls.push(key);
    const reply = routes[key]?.shift() ?? { status: 200, data: {} };
    const response = { status: reply.status, data: reply.data, headers: {}, config, statusText: '' } as AxiosResponse;
    if (reply.status >= 400) throw new AxiosError('fail', String(reply.status), config, {}, response);
    return response;
  };
  return { adapter, calls };
}

function client(routes: Record<string, Reply[]>) {
  const server = fakeServer(routes);
  const http = createApiClient();
  http.defaults.adapter = server.adapter;
  return { http, calls: server.calls };
}

beforeEach(() => {
  toastError.mockReset();
});

describe('CSRF', () => {
  it('fetches the CSRF cookie once, only before mutating requests', async () => {
    const { http, calls } = client({});

    await http.get('/accounts');
    await http.post('/accounts', { name: 'A' });
    await http.patch('/accounts/1', {});
    await http.delete('/accounts/1');

    expect(calls).toEqual([
      'GET /api/accounts',
      'GET /sanctum/csrf-cookie',
      'POST /api/accounts',
      'PATCH /api/accounts/1',
      'DELETE /api/accounts/1',
    ]);
  });

  it('refreshes the cookie and retries once on 419 (expired session/CSRF)', async () => {
    const { http, calls } = client({ 'POST /api/accounts': [{ status: 419 }, { status: 201, data: { data: { id: 1 } } }] });

    const res = await http.post('/accounts', { name: 'A' });

    expect(res.status).toBe(201);
    expect(calls.filter((c) => c === 'GET /sanctum/csrf-cookie')).toHaveLength(2);
    expect(toastError).not.toHaveBeenCalled();
  });

  it('gives up after one 419 retry', async () => {
    const { http } = client({ 'POST /api/accounts': [{ status: 419 }, { status: 419 }] });

    await expect(http.post('/accounts', {})).rejects.toMatchObject({ status: 419 });
  });
});

describe('error handling', () => {
  it('401 → calls the unauthorized handler, no toast', async () => {
    const handler = vi.fn();
    onUnauthorized(handler);
    const { http } = client({ 'GET /api/accounts': [{ status: 401 }] });

    await expect(http.get('/accounts')).rejects.toBeInstanceOf(ApiError);
    expect(handler).toHaveBeenCalledOnce();
    expect(toastError).not.toHaveBeenCalled();
  });

  it('403 → toast', async () => {
    const { http } = client({ 'PUT /api/accounts/1': [{ status: 403 }] });

    await expect(http.put('/accounts/1', {})).rejects.toMatchObject({ status: 403 });
    expect(toastError).toHaveBeenCalledWith('Anda tidak punya akses untuk tindakan ini.');
  });

  it('409 → toast with the server’s explanation (e.g. already checked on another device)', async () => {
    const message = 'Item ini sudah dicentang. Muat ulang untuk melihat transaksinya.';
    const { http } = client({ 'POST /api/plan-items/3/check': [{ status: 409, data: { message } }] });

    await expect(http.post('/plan-items/3/check', {})).rejects.toMatchObject({ status: 409, message });
    expect(toastError).toHaveBeenCalledWith(message);
  });

  it('422 → ApiError with field errors, no toast', async () => {
    const errors = { amount: ['Nominal wajib diisi.'], 'items.0.limit_amount': ['Minimal 1.'] };
    const { http } = client({ 'POST /api/transactions': [{ status: 422, data: { message: 'Data tidak valid.', errors } }] });

    const error = await http.post('/transactions', {}).catch((e: unknown) => e);

    expect(error).toBeInstanceOf(ApiError);
    expect((error as InstanceType<typeof ApiError>).isValidation).toBe(true);
    expect((error as InstanceType<typeof ApiError>).first('amount')).toBe('Nominal wajib diisi.');
    expect((error as InstanceType<typeof ApiError>).fieldErrors).toEqual(errors);
    expect(toastError).not.toHaveBeenCalled();
  });

  it.each([
    [429, 'Terlalu banyak percobaan. Coba lagi sebentar lagi.'],
    [500, 'Terjadi kesalahan di server. Coba lagi.'],
  ])('%i → toast', async (status, message) => {
    const { http } = client({ 'GET /api/accounts': [{ status }] });

    await expect(http.get('/accounts')).rejects.toMatchObject({ status });
    expect(toastError).toHaveBeenCalledWith(message);
  });

  it('silent requests never toast or redirect', async () => {
    const handler = vi.fn();
    onUnauthorized(handler);
    const { http } = client({ 'GET /api/auth/me': [{ status: 401 }], 'GET /api/x': [{ status: 500 }] });

    await expect(http.get('/auth/me', { silent: true })).rejects.toMatchObject({ status: 401 });
    await expect(http.get('/x', { silent: true })).rejects.toMatchObject({ status: 500 });
    expect(handler).not.toHaveBeenCalled();
    expect(toastError).not.toHaveBeenCalled();
  });
});
