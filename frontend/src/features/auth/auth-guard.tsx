"use client";

import { usePathname, useRouter } from "next/navigation";
import * as React from "react";

import { ErrorState } from "@/components/shared/error-state";
import { FullPageLoader } from "@/components/shared/loading-skeleton";
import type { User } from "@/types/api";

import { useCurrentUser } from "./hooks";

const CurrentUserContext = React.createContext<User | null>(null);

/** The authenticated administrator. Only usable below <AuthGuard>. */
export function useAuthenticatedUser(): User {
  const user = React.useContext(CurrentUserContext);
  if (!user) throw new Error("useAuthenticatedUser must be used inside <AuthGuard>.");
  return user;
}

/**
 * Renders protected UI only after the API has confirmed the session. While the
 * check is pending nothing private is rendered; on 401 the user is sent to /login.
 * Laravel still authorises every API call — this is a UX layer, not a security boundary.
 */
export function AuthGuard({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const { data: user, isPending, isError, error, refetch, isRefetching } = useCurrentUser();

  React.useEffect(() => {
    if (!isPending && !isError && user === null) {
      router.replace(`/login?next=${encodeURIComponent(pathname)}`);
    }
  }, [isPending, isError, user, router, pathname]);

  if (isPending) return <FullPageLoader />;

  if (isError) {
    return (
      <div className="flex min-h-dvh items-center justify-center bg-background p-4">
        <ErrorState error={error} title="We couldn't verify your session" onRetry={() => refetch()} retrying={isRefetching} />
      </div>
    );
  }

  if (!user) return <FullPageLoader label="Redirecting to sign in" />;

  return <CurrentUserContext.Provider value={user}>{children}</CurrentUserContext.Provider>;
}
