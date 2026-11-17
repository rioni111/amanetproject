"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/lib/api/client";
import type { NotificationPage, Resource, WorkspaceNotification } from "@/types/api";

/**
 * The signed-in administrator's notifications. Refresh strategy: a light unread-count poll (every 30 s,
 * paused while the tab is hidden, refreshed on focus) — no WebSocket infrastructure. The list itself is only
 * fetched when the panel or the page is open.
 */
export function useUnreadCount() {
  return useQuery({
    queryKey: ["notifications", "unread-count"],
    queryFn: async ({ signal }) => (await api.get<Resource<{ unread_count: number }>>("/api/notifications/unread-count", undefined, signal)).data.unread_count,
    refetchInterval: 30_000,
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: true,
  });
}

export function useNotifications(options: { page?: number; unreadOnly?: boolean; perPage?: number; enabled?: boolean } = {}) {
  const { page = 1, unreadOnly = false, perPage = 20, enabled = true } = options;
  return useQuery({
    queryKey: ["notifications", "list", page, unreadOnly, perPage],
    queryFn: ({ signal }) => api.get<NotificationPage>("/api/notifications", { page, unread: unreadOnly ? 1 : undefined, per_page: perPage }, signal),
    placeholderData: keepPreviousData,
    enabled,
  });
}

export function useMarkRead() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => api.post<{ data: WorkspaceNotification }>(`/api/notifications/${id}/read`),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["notifications"] }),
  });
}

export function useMarkAllRead() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => api.post<{ data: { marked: number } }>("/api/notifications/read-all"),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["notifications"] }),
  });
}
