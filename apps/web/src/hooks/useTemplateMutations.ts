import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { qk } from '@/api/keys';
import { templatesApi, type TemplateBody } from '@/api/templates';
import type { Id } from '@/api/types';

export function useTemplates() {
  return useQuery({ queryKey: qk.templates, queryFn: templatesApi.list });
}

/** Mengubah template tidak mengubah rencana yang sudah ada → cukup daftar template. */
export function useTemplateMutations() {
  const queryClient = useQueryClient();
  const refresh = () => queryClient.invalidateQueries({ queryKey: qk.templates });

  return {
    create: useMutation({ mutationFn: (body: TemplateBody) => templatesApi.create(body), onSuccess: refresh }),
    update: useMutation({ mutationFn: ({ id, body }: { id: Id; body: Partial<TemplateBody> }) => templatesApi.update(id, body), onSuccess: refresh }),
    remove: useMutation({ mutationFn: (id: Id) => templatesApi.remove(id), onSuccess: refresh }),
  };
}
