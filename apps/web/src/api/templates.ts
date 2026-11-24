import { api, unwrap } from './client';
import type { PlanItemType, PlanMode } from './plan';
import type { Category, Id } from './types';

export type TemplateFrequency = 'monthly' | 'yearly';

/** Template rencana berulang (Keputusan #9). plan:generate menurunkan item bulanan darinya. */
export interface PlanTemplate {
  id: Id;
  type: PlanItemType;
  mode: PlanMode;
  name: string;
  category_id: Id;
  category?: Category;
  planned_amount: number; // positif
  account_id: Id | null;
  frequency: TemplateFrequency;
  day_of_month: number | null; // 1..31, dibatasi hari terakhir bulan; null untuk amplop
  month_of_year: number | null; // 1..12, hanya tahunan
  auto_post: boolean;
  is_active: boolean;
}

export type TemplateBody = Omit<PlanTemplate, 'id' | 'category'>;

const base = '/plan-templates';

export const templatesApi = {
  list: () => unwrap(api.get<{ data: PlanTemplate[] }>(base)),
  create: (body: TemplateBody) => unwrap(api.post<{ data: PlanTemplate }>(base, body)),
  update: (id: Id, body: Partial<TemplateBody>) => unwrap(api.patch<{ data: PlanTemplate }>(`${base}/${id}`, body)),
  remove: (id: Id) => api.delete(`${base}/${id}`),
};
