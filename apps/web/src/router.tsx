import { createBrowserRouter } from 'react-router';
import { formatIDR } from '@household-finance/shared';

// Kerangka #31. Halaman sesungguhnya menyusul di #34–#40.
function Placeholder() {
  return (
    <main className="mx-auto flex min-h-dvh max-w-md flex-col items-center justify-center gap-2 p-6 text-center">
      <h1 className="text-2xl font-semibold">Keuangan Rumah</h1>
      <p className="text-muted-foreground">Web siap. Contoh format: {formatIDR(1_250_000)}</p>
    </main>
  );
}

export const router = createBrowserRouter([{ path: '*', element: <Placeholder /> }]);
