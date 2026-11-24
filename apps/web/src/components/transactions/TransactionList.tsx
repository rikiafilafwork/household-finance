import { ArrowLeftRight, Scale } from 'lucide-react';
import type { Account, Category, Transaction } from '@/api/types';
import { MoneyText } from '@/components/money/MoneyText';
import { formatDate } from '@/lib/dates';

export function transactionLabel(tx: Pick<Transaction, 'type' | 'amount' | 'category_id'> & { category?: Category | null }, categories: Map<number, Category>): string {
  if (tx.type === 'transfer') return tx.amount < 0 ? 'Transfer keluar' : 'Transfer masuk';
  if (tx.type === 'adjustment') return 'Penyesuaian saldo';
  const category = tx.category ?? (tx.category_id ? categories.get(tx.category_id) : undefined);
  return category?.name ?? (tx.type === 'income' ? 'Pemasukan' : 'Pengeluaran');
}

/** Daftar dikelompokkan per tanggal (DESC), dengan jumlah bersih harian. */
export function TransactionList({ transactions, accounts, categories, onSelect }: {
  transactions: Transaction[];
  accounts: Map<number, Account>;
  categories: Map<number, Category>;
  onSelect: (tx: Transaction) => void;
}) {
  const groups: { date: string; items: Transaction[] }[] = [];
  for (const tx of transactions) {
    const last = groups.at(-1);
    if (last?.date === tx.occurred_on) last.items.push(tx);
    else groups.push({ date: tx.occurred_on, items: [tx] });
  }

  return (
    <div className="grid gap-4">
      {groups.map((group) => {
        // Transfer tidak dihitung di jumlah harian (bukan pemasukan/pengeluaran).
        const net = group.items.filter((t) => t.type === 'income' || t.type === 'expense').reduce((sum, t) => sum + t.amount, 0);
        return (
          <section key={group.date} className="overflow-hidden rounded-lg border bg-background">
            <header className="flex items-center justify-between bg-muted/50 px-4 py-2 text-xs font-medium text-muted-foreground">
              <span>{formatDate(group.date, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</span>
              {net !== 0 && <MoneyText amount={net} tone="signed" />}
            </header>
            <ul className="divide-y">
              {group.items.map((tx) => {
                const category = tx.category ?? (tx.category_id ? categories.get(tx.category_id) : undefined);
                const account = accounts.get(tx.account_id);
                return (
                  <li key={tx.id}>
                    <button type="button" onClick={() => onSelect(tx)} className="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-accent/50 focus-visible:bg-accent/50 focus-visible:outline-none">
                      <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted" style={category?.color ? { backgroundColor: `${category.color}22` } : undefined}>
                        {tx.type === 'transfer' ? <ArrowLeftRight className="size-4 text-muted-foreground" />
                          : tx.type === 'adjustment' ? <Scale className="size-4 text-muted-foreground" />
                          : <span className="size-2.5 rounded-full" style={{ backgroundColor: category?.color ?? 'var(--muted-foreground)' }} />}
                      </span>
                      <span className="grid min-w-0 flex-1">
                        <span className="truncate text-sm font-medium">
                          {transactionLabel(tx, categories)}
                          {category?.archived_at && <span className="ml-1 text-xs font-normal text-muted-foreground">(diarsip)</span>}
                        </span>
                        <span className="truncate text-xs text-muted-foreground">
                          {account?.name ?? 'Akun'}{tx.plan_item_id ? ' · dari rencana' : ''}{tx.note ? ` · ${tx.note}` : ''}
                        </span>
                      </span>
                      <MoneyText amount={tx.amount} tone="signed" className="text-sm font-medium" />
                    </button>
                  </li>
                );
              })}
            </ul>
          </section>
        );
      })}
    </div>
  );
}
