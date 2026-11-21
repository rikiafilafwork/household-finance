import { Navigate } from 'react-router';
import { FullPageSpinner } from '@/auth/guards';
import { lastHouseholdId, useHouseholds } from '@/hooks/useHouseholds';

/** `/` → household terakhir dipakai (atau yang pertama); belum punya → onboarding. */
export function HomeRedirect() {
  const { data: households, isPending, isError } = useHouseholds();

  if (isPending) return <FullPageSpinner />;
  if (isError) return <FullPageSpinner />;
  if (households.length === 0) return <Navigate to="/onboarding" replace />;

  const last = lastHouseholdId();
  const target = households.find((h) => h.id === last) ?? households[0]!;
  return <Navigate to={`/h/${target.id}`} replace />;
}
