import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { toast } from 'sonner';
import { ApiError } from '@/api/client';
import { invitationApi } from '@/api/households';
import { qk } from '@/api/keys';
import { useLogout, useMe } from '@/auth/useAuth';
import { FullPageSpinner } from '@/auth/guards';
import { FormMessage } from '@/components/form/Field';
import { AuthLayout } from '@/components/layout/AuthLayout';
import { Button } from '@/components/ui/button';
import { withNext } from '@/lib/redirect';

const roleLabel = { owner: 'owner', member: 'anggota' } as const;

/** Link dari email undangan: /invitations/{token}. Pratinjau boleh tanpa login. */
export function AcceptInvitationPage() {
  const { token = '' } = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const me = useMe();
  const logout = useLogout();
  const [message, setMessage] = useState<string | null>(null);
  const here = `/invitations/${token}`;

  const preview = useQuery({ queryKey: ['invitation', token], queryFn: () => invitationApi.preview(token), retry: false });

  const accept = useMutation({
    mutationFn: () => invitationApi.accept(token),
    onSuccess: async (household) => {
      await queryClient.invalidateQueries({ queryKey: qk.households });
      toast.success(`Bergabung dengan ${household.name}.`);
      navigate(`/h/${household.id}`, { replace: true });
    },
    onError: (error) => setMessage(error instanceof ApiError ? (error.serverMessage ?? error.message) : 'Gagal menerima undangan.'),
  });

  if (preview.isPending || me.isPending) return <FullPageSpinner />;

  if (preview.isError) {
    const error = preview.error instanceof ApiError ? preview.error : null;
    return (
      <AuthLayout title="Undangan tidak bisa dipakai" description={error?.status === 404 ? 'Tautan undangan ini tidak ditemukan.' : (error?.serverMessage ?? 'Tautan undangan ini sudah tidak berlaku.')}>
        <p className="text-sm text-muted-foreground">Minta owner household mengirim undangan baru.</p>
        <Button asChild variant="outline" className="mt-4 w-full"><Link to="/">Ke beranda</Link></Button>
      </AuthLayout>
    );
  }

  const invitation = preview.data;
  const user = me.data;
  const wrongAccount = user && user.email.toLowerCase() !== invitation.email.toLowerCase();

  return (
    <AuthLayout
      title={`Undangan ke ${invitation.household.name}`}
      description={<>{invitation.invited_by.name} mengundang <strong>{invitation.email}</strong> sebagai {roleLabel[invitation.role]}.</>}
    >
      <div className="grid gap-3">
        <FormMessage message={message} />
        {!user && (
          <>
            <Button asChild className="w-full"><Link to={`${withNext('/register', here)}&email=${encodeURIComponent(invitation.email)}`}>Daftar untuk menerima</Link></Button>
            <Button asChild variant="outline" className="w-full"><Link to={`${withNext('/login', here)}&email=${encodeURIComponent(invitation.email)}`}>Saya sudah punya akun</Link></Button>
          </>
        )}
        {user && wrongAccount && (
          <>
            <p className="text-sm">
              Anda masuk sebagai <strong>{user.email}</strong>, sedangkan undangan ini untuk <strong>{invitation.email}</strong>.
            </p>
            <Button className="w-full" variant="outline" disabled={logout.isPending} onClick={() => logout.mutate(undefined, { onSettled: () => navigate(`${withNext('/login', here)}&email=${encodeURIComponent(invitation.email)}`) })}>
              Keluar dan masuk dengan {invitation.email}
            </Button>
          </>
        )}
        {user && !wrongAccount && (
          <Button className="w-full" disabled={accept.isPending} onClick={() => accept.mutate()}>
            {accept.isPending ? 'Memproses…' : `Terima dan bergabung`}
          </Button>
        )}
        <p className="text-xs text-muted-foreground">Berlaku sampai {new Date(invitation.expires_at).toLocaleDateString('id-ID', { dateStyle: 'long' })}.</p>
      </div>
    </AuthLayout>
  );
}
