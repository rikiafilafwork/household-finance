import { api, unwrap } from './client';
import type { Household, Id, Invitation, InvitationPreview, Member, Role } from './types';

export const householdsApi = {
  list: () => unwrap(api.get<{ data: Household[] }>('/households')),
  get: (id: Id) => unwrap(api.get<{ data: Household }>(`/households/${id}`)),
  create: (body: { name: string }) => unwrap(api.post<{ data: Household }>('/households', body)),

  members: (id: Id) => unwrap(api.get<{ data: Member[] }>(`/households/${id}/members`)),
  changeRole: (id: Id, memberId: Id, role: Role) => unwrap(api.patch<{ data: Member }>(`/households/${id}/members/${memberId}`, { role })),
  removeMember: (id: Id, memberId: Id) => api.delete(`/households/${id}/members/${memberId}`),

  invitations: (id: Id) => unwrap(api.get<{ data: Invitation[] }>(`/households/${id}/invitations`)),
  invite: (id: Id, body: { email: string; role?: Role }) => unwrap(api.post<{ data: Invitation }>(`/households/${id}/invitations`, body)),
  revokeInvitation: (id: Id, invitationId: Id) => api.delete(`/households/${id}/invitations/${invitationId}`),
};

export const invitationApi = {
  preview: (token: string) => unwrap(api.get<{ data: InvitationPreview }>(`/invitations/${encodeURIComponent(token)}`, { silent: true })),
  accept: (token: string) => unwrap(api.post<{ data: Household }>(`/invitations/${encodeURIComponent(token)}/accept`)),
};
