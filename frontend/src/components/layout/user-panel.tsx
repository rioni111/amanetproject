"use client";

import { Loader2, LogOut } from "lucide-react";
import { useRouter } from "next/navigation";

import { useAuthenticatedUser } from "@/features/auth/auth-guard";
import { useLogout } from "@/features/auth/hooks";
import { initials } from "@/lib/utils/format";

/** Signed-in administrator + sign-out, pinned to the bottom of the sidebar. */
export function UserPanel() {
  const user = useAuthenticatedUser();
  const router = useRouter();
  const logout = useLogout();

  return (
    <div className="flex items-center gap-3 rounded-lg border border-sidebar-border bg-sidebar-hover p-2.5">
      <span
        aria-hidden
        className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-semibold text-white"
      >
        {initials(user.name)}
      </span>
      <div className="min-w-0 flex-1 leading-tight">
        <p className="truncate text-sm font-medium text-white">{user.name}</p>
        <p className="truncate text-xs text-sidebar-muted">{user.email}</p>
      </div>
      <button
        type="button"
        onClick={() => logout.mutate(undefined, { onSettled: () => router.replace("/login") })}
        disabled={logout.isPending}
        className="inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-md text-sidebar-muted transition-colors hover:bg-sidebar-hover hover:text-white focus-visible:outline-sidebar-accent disabled:opacity-60"
        aria-label="Sign out"
        title="Sign out"
      >
        {logout.isPending ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <LogOut aria-hidden className="size-4" />}
      </button>
    </div>
  );
}
