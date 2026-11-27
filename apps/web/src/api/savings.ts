import { api, unwrap } from './client';
import type { CursorPage, Id, SavingsAllocation, SavingsGoal, SavingsSummary, Transaction } from './types';

const base = '/savings-goals';

export interface GoalBody {
  name: string;
  target_amount: number;
  target_date: string | null;
}

interface MoneyBody {
  amount: number;
  note?: string | null;
  client_id: string;
}

export const savingsApi = {
  summary: () => unwrap(api.get<{ data: SavingsSummary }>('/savings')),
  createGoal: (body: GoalBody & { account_id: Id }) => unwrap(api.post<{ data: SavingsGoal }>(base, body)),
  updateGoal: (id: Id, body: Partial<GoalBody>) => unwrap(api.patch<{ data: SavingsGoal }>(`${base}/${id}`, body)),
  removeGoal: (id: Id) => api.delete(`${base}/${id}`),
  archiveGoal: (id: Id) => unwrap(api.post<{ data: SavingsGoal }>(`${base}/${id}/archive`)),
  unarchiveGoal: (id: Id) => unwrap(api.post<{ data: SavingsGoal }>(`${base}/${id}/unarchive`)),
  achieveGoal: (id: Id) => unwrap(api.post<{ data: SavingsGoal }>(`${base}/${id}/achieve`)),
  unachieveGoal: (id: Id) => unwrap(api.post<{ data: SavingsGoal }>(`${base}/${id}/unachieve`)),
  /** `amount` bertanda: positif tambah, negatif kurangi. 201 = baru, 200 = retry client_id yang sama. */
  allocate: (goalId: Id, body: MoneyBody & { occurred_on: string }) =>
    unwrap(api.post<{ data: SavingsAllocation }>(`${base}/${goalId}/allocations`, body)),
  allocations: async (goalId: Id, cursor?: string | null) =>
    (await api.get<CursorPage<SavingsAllocation>>(`${base}/${goalId}/allocations`, { params: cursor ? { cursor } : {} })).data,
  move: (goalId: Id, body: MoneyBody & { to_goal_id: Id }) =>
    unwrap(api.post<{ data: SavingsAllocation }>(`${base}/${goalId}/move`, body)),
  use: (goalId: Id, body: MoneyBody & { category_id: Id; occurred_on: string }) =>
    unwrap(api.post<{ data: { transaction: Transaction; goal: SavingsGoal } }>(`${base}/${goalId}/use`, body)),
};
