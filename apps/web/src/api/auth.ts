import { api, unwrap } from './client';
import type { User } from './types';

export const authApi = {
  /** null kalau belum login (tanpa toast / redirect). */
  me: async (): Promise<User | null> => {
    try {
      return await unwrap(api.get<{ data: User }>('/auth/me', { silent: true }));
    } catch {
      return null;
    }
  },
  login: (body: { email: string; password: string; remember?: boolean }) => unwrap(api.post<{ data: User }>('/auth/login', body)),
  register: (body: { name: string; email: string; password: string; password_confirmation: string; timezone?: string }) =>
    unwrap(api.post<{ data: User }>('/auth/register', body)),
  updateProfile: (body: { name?: string; timezone?: string }) => unwrap(api.patch<{ data: User }>('/auth/me', body)),
  changePassword: (body: { current_password: string; password: string; password_confirmation: string }) => api.put('/auth/password', body),
  logout: async () => {
    await api.post('/auth/logout');
    api.resetCsrf();
  },
};
