"use client";

import { MutationCache, QueryCache, QueryClient, QueryClientProvider } from "@tanstack/react-query";
import * as React from "react";

import { ToastProvider } from "@/components/shared/toaster";
import { currentUserKey } from "@/features/auth/hooks";
import { ApiError } from "@/lib/api/errors";

function makeQueryClient(): QueryClient {
  const client: QueryClient = new QueryClient({
    queryCache: new QueryCache({
      onError: (error) => {
        // Session expired mid-use: clear identity so <AuthGuard> redirects to /login.
        if (error instanceof ApiError && error.isUnauthenticated) client.setQueryData(currentUserKey, null);
      },
    }),
    mutationCache: new MutationCache({
      onError: (error, _vars, _ctx, mutation) => {
        if (error instanceof ApiError && error.isUnauthenticated && mutation.options.mutationKey?.[0] !== "auth") {
          client.setQueryData(currentUserKey, null);
        }
      },
    }),
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        refetchOnWindowFocus: true,
        retry: (failureCount, error) => {
          if (error instanceof ApiError && error.status >= 400 && error.status < 500) return false;
          return failureCount < 2;
        },
      },
    },
  });
  return client;
}

let browserQueryClient: QueryClient | undefined;

function getQueryClient(): QueryClient {
  // Isolate server renders; reuse a single client in the browser.
  if (typeof window === "undefined") return makeQueryClient();
  browserQueryClient ??= makeQueryClient();
  return browserQueryClient;
}

export function Providers({ children }: { children: React.ReactNode }) {
  return (
    <QueryClientProvider client={getQueryClient()}>
      <ToastProvider>{children}</ToastProvider>
    </QueryClientProvider>
  );
}
