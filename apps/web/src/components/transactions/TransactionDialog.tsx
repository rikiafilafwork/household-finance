import { useQuery } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ApiError } from '@/api/client';
import { transfersApi } from '@/api/transactions';
import type { Category, Transaction, Wallet } from '@/api/types';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { useTransactionMutations } from '@/hooks/useTransactionMutations';
import { formatDate } from '@/lib/dates';
import { MoneyText } from '@/components/money/MoneyText';
import { TransactionForm } from './TransactionForm';
import { TransferForm } from './TransferForm';

export type DialogState =
  | { mode: 'new'; kind: 'expense' | 'income' | 'transfer' }
  | { mode: 'edit'; transaction: Transaction; canManage: boolean }
  | null;

const titles = { expense: 'Catat pengeluaran', income: 'Catat pemasukan', transfer: 'Transfer antar wallet' } as const;

export function TransactionDialog({ state, onClose, householdId, wallets, categories, today }: {
  state: DialogState;
  onClose: () => void;
  householdId: number;
  wallets: Wallet[];
  categories: Category[];
  today: string;
}) {
  const m = useTransactionMutations(householdId);
  const done = (message: string) => {
    toast.success(message);
    onClose();
  };

  const editing = state?.mode === 'edit' ? state.transaction : null;
  const transferQuery = useQuery({
    queryKey: ['transfer', householdId, editing?.transfer_group_id],
    queryFn: () => transfersApi.get(householdId, editing!.transfer_group_id!),
    enabled: !!editing?.transfer_group_id,
    retry: false,
  });

  let title = '';
  let body: React.ReactNode = null;

  if (state?.mode === 'new' && state.kind !== 'transfer') {
    title = titles[state.kind];
    body = (
      <TransactionForm
        key={state.kind}
        defaultType={state.kind}
        wallets={wallets}
        categories={categories}
        today={today}
        submitLabel="Simpan"
        onSubmit={async (payload, client_id) => {
          await m.create.mutateAsync({ ...payload, client_id });
          done(payload.type === 'expense' ? 'Pengeluaran dicatat.' : 'Pemasukan dicatat.');
        }}
      />
    );
  } else if (state?.mode === 'new') {
    title = titles.transfer;
    body = (
      <TransferForm
        wallets={wallets}
        today={today}
        onSubmit={async (payload, client_id) => {
          await m.createTransfer.mutateAsync({ ...payload, client_id });
          done('Transfer dicatat.');
        }}
      />
    );
  } else if (editing && (editing.type === 'income' || editing.type === 'expense')) {
    title = state?.mode === 'edit' && state.canManage ? 'Ubah transaksi' : 'Detail transaksi';
    body = state?.mode === 'edit' && state.canManage ? (
      <TransactionForm
        initial={editing}
        wallets={wallets}
        categories={categories}
        today={today}
        onSubmit={async (payload) => {
          await m.update.mutateAsync({ id: editing.id, body: payload });
          done('Transaksi diperbarui.');
        }}
        onDelete={async () => {
          await m.remove.mutateAsync(editing.id);
          done('Transaksi dihapus.');
        }}
      />
    ) : <ReadOnly tx={editing} wallets={wallets} categories={categories} note="Hanya pencatat atau owner household yang bisa mengubah transaksi ini." />;
  } else if (editing && editing.type === 'transfer') {
    title = 'Transfer';
    if (transferQuery.isPending) body = <Skeleton className="h-48" />;
    else if (transferQuery.isError) {
      const forbidden = transferQuery.error instanceof ApiError && transferQuery.error.status === 403;
      body = <ReadOnly tx={editing} wallets={wallets} categories={categories} note={forbidden ? 'Transfer ini melibatkan wallet pribadi anggota lain, jadi detailnya tidak ditampilkan.' : 'Transfer tidak bisa dimuat.'} />;
    } else if (state?.mode === 'edit' && state.canManage) {
      const transfer = transferQuery.data;
      body = (
        <TransferForm
          initial={transfer}
          wallets={wallets}
          today={today}
          onSubmit={async (payload) => {
            await m.updateTransfer.mutateAsync({ groupId: transfer.transfer_group_id, body: payload });
            done('Transfer diperbarui.');
          }}
          onDelete={async () => {
            await m.removeTransfer.mutateAsync(transfer.transfer_group_id);
            done('Transfer dihapus.');
          }}
        />
      );
    } else body = <ReadOnly tx={editing} wallets={wallets} categories={categories} note="Hanya pencatat atau owner household yang bisa mengubah transfer ini." />;
  } else if (editing) {
    title = 'Penyesuaian saldo';
    body = <ReadOnly tx={editing} wallets={wallets} categories={categories} note="Penyesuaian saldo dibuat dari halaman Wallet (Sesuaikan saldo) dan tidak diubah di sini." />;
  }

  return (
    <Dialog open={state !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[92dvh] overflow-y-auto sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription className="sr-only">{title}</DialogDescription>
        </DialogHeader>
        {body}
      </DialogContent>
    </Dialog>
  );
}

function ReadOnly({ tx, wallets, categories, note }: { tx: Transaction; wallets: Wallet[]; categories: Category[]; note: string }) {
  const wallet = wallets.find((w) => w.id === tx.wallet_id);
  const category = tx.category ?? categories.find((c) => c.id === tx.category_id);
  return (
    <div className="grid gap-3 text-sm">
      <MoneyText amount={tx.amount} tone="signed" className="text-2xl font-semibold" />
      <dl className="grid grid-cols-[6rem_1fr] gap-y-1.5">
        <dt className="text-muted-foreground">Tanggal</dt><dd>{formatDate(tx.occurred_on, { dateStyle: 'long' })}</dd>
        <dt className="text-muted-foreground">Wallet</dt><dd>{wallet?.name ?? '—'}</dd>
        {category && (<><dt className="text-muted-foreground">Kategori</dt><dd>{category.name}</dd></>)}
        {tx.note && (<><dt className="text-muted-foreground">Catatan</dt><dd>{tx.note}</dd></>)}
      </dl>
      <p className="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">{note}</p>
    </div>
  );
}
