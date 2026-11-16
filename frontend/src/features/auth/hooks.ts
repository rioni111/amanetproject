"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { fetchCurrentUser, login, logout, type LoginCredentials } from "./api";

export const currentUserKey = ["auth", "me"] as const;

export function useCurrentUser() {
  return useQuery({
    queryKey: currentUserKey,
    queryFn: ({ signal }) => fetchCurrentUser(signal),
    staleTime: 5 * 60_000,
    retry: false,
  });
}

export function useLogin() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationKey: ["auth", "login"],
    mutationFn: (credentials: LoginCredentials) => login(credentials),
    onSuccess: (user) => {
      // Drop anything cached for a previous session, then seed the new identity.
      queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== "auth" });
      queryClient.setQueryData(currentUserKey, user);
    },
  });
}

export function useLogout() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationKey: ["auth", "logout"],
    mutationFn: logout,
    onSettled: () => {
      // Whatever the server said, forget all private data held in memory.
      queryClient.clear();
      queryClient.setQueryData(currentUserKey, null);
    },
  });
}
