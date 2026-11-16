import { AppShell } from "@/components/layout/app-shell";
import { AuthGuard } from "@/features/auth/auth-guard";

/**
 * Protected pages render only after the browser has confirmed the session with the API
 * (see AuthGuard), so navigation into this segment is allowed to wait for that check.
 */
export const instant = false;

export default function DashboardLayout({ children }: LayoutProps<"/">) {
  return (
    <AuthGuard>
      <AppShell>{children}</AppShell>
    </AuthGuard>
  );
}
