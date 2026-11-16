"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/lib/api/client";
import { invalidateWorkspace } from "@/lib/api/invalidate";
import type { Matter, MatterStatus, Paginated, Priority, Resource } from "@/types/api";

export type MatterSort = "updated_at" | "created_at" | "opened_at" | "due_at" | "matter_number" | "priority";
export type DeadlineFilter = "" | "overdue" | "upcoming" | "none";

export interface MatterFilters {
  search: string;
  status: MatterStatus | "active" | "";
  priority: Priority | "";
  deadline: DeadlineFilter;
  client_id: number | "";
  sort: MatterSort;
  page: number;
}

export interface MatterInput {
  title: string;
  description: string | null;
  client_id: number;
  matter_type: string | null;
  priority: Priority;
  opened_date: string | null;
  due_date: string | null;
  status?: MatterStatus;
  lock_version?: number;
}

export const matterKeys = {
  all: ["matters"] as const,
  list: (filters: MatterFilters) => ["matters", "list", filters] as const,
  detail: (id: number) => ["matter", id] as const,
};

const ASCENDING: MatterSort[] = ["due_at", "matter_number"];

export function useMatters(filters: MatterFilters, perPage = 10) {
  return useQuery({
    queryKey: [...matterKeys.list(filters), perPage],
    queryFn: ({ signal }) =>
      api.get<Paginated<Matter>>(
        "/api/matters",
        {
          search: filters.search,
          status: filters.status,
          priority: filters.priority,
          deadline: filters.deadline,
          client_id: filters.client_id,
          sort: filters.sort,
          direction: ASCENDING.includes(filters.sort) ? "asc" : "desc",
          page: filters.page,
          per_page: perPage,
        },
        signal,
      ),
    placeholderData: keepPreviousData,
  });
}

export function useMatter(id: number) {
  return useQuery({
    queryKey: [...matterKeys.detail(id), "detail"],
    queryFn: async ({ signal }) => (await api.get<Resource<Matter>>(`/api/matters/${id}`, undefined, signal)).data,
  });
}

function useMatterMutation<V>(request: (variables: V) => Promise<Resource<Matter>>) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (variables: V) => (await request(variables)).data,
    onSuccess: (matter) => {
      queryClient.setQueryData([...matterKeys.detail(matter.id), "detail"], matter);
      invalidateWorkspace(queryClient, { matterId: matter.id });
    },
  });
}

export function useSaveMatter(matterId?: number) {
  return useMatterMutation((input: MatterInput) =>
    matterId ? api.patch<Resource<Matter>>(`/api/matters/${matterId}`, input) : api.post<Resource<Matter>>("/api/matters", input),
  );
}

/** Priority-only edit (quick action); goes through the same validated PATCH endpoint. */
export function useChangePriority(matterId: number) {
  return useMatterMutation((vars: { priority: Priority; lock_version: number }) =>
    api.patch<Resource<Matter>>(`/api/matters/${matterId}`, vars),
  );
}

export function useChangeStatus(matterId: number) {
  return useMatterMutation((vars: { status: MatterStatus; lock_version: number }) =>
    api.post<Resource<Matter>>(`/api/matters/${matterId}/status`, vars),
  );
}

export function useArchiveMatter(matterId: number) {
  return useMatterMutation((vars: { lock_version: number }) => api.post<Resource<Matter>>(`/api/matters/${matterId}/archive`, vars));
}

export function useRestoreMatter(matterId: number) {
  return useMatterMutation((vars: { lock_version: number }) => api.post<Resource<Matter>>(`/api/matters/${matterId}/restore`, vars));
}
