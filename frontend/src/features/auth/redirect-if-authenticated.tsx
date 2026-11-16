"use client";

import { useRouter } from "next/navigation";
import * as React from "react";

import { useCurrentUser } from "./hooks";
import { safeNextPath } from "./redirect";

/**
 * Sends a signed-in administrator from /login to their destination. Also performs the
 * post-login navigation, since a successful login seeds the current-user query.
 *
 * The `next` parameter is read from window.location inside the effect (not useSearchParams)
 * so the login form needs no Suspense boundary and is fully part of the static shell.
 */
export function RedirectIfAuthenticated() {
  const router = useRouter();
  const { data: user } = useCurrentUser();

  React.useEffect(() => {
    if (!user) return;
    const next = new URLSearchParams(window.location.search).get("next");
    router.replace(safeNextPath(next));
  }, [user, router]);

  return null;
}
