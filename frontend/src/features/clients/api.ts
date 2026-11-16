"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/lib/api/client";
import { invalidateWorkspace } from "@/lib/api/invalidate";
import type { Client, ClientType, Paginated, Resource } from "@/types/api";

export type ClientSort = "name" | "matters_count" | "created_at";

export interface ClientFilters {
  search: string;
  type: ClientType | "";
  sort: ClientSort;
  page: number;
}

export interface ClientInput {
  name: string;
  client_type: ClientType;
  email: string | null;
  phone: string | null;
  address: string | null;
  reference_number: string | null;
}

export const clientKeys = {
  all: ["clients"] as const,
  list: (filters: ClientFilters) => ["clients", "list", filters] as const,
  detail: (id: number) => ["clients", "detail", id] as const,
  options: ["clients", "options"] as const,
};

export function useClients(filters: ClientFilters) {
  return useQuery({
    queryKey: clientKeys.list(filters),
    queryFn: ({ signal }) =>
      api.get<Paginated<Client>>(
        "/api/clients",
        {
          search: filters.search,
          type: filters.type,
          sort: filters.sort,
          direction: filters.sort === "name" ? "asc" : "desc",
          page: filters.page,
          per_page: 10,
        },
        signal,
      ),
    placeholderData: keepPreviousData,
  });
}

export function useClient(id: number) {
  return useQuery({
    queryKey: clientKeys.detail(id),
    queryFn: async ({ signal }) => (await api.get<Resource<Client>>(`/api/clients/${id}`, undefined, signal)).data,
  });
}

/** Clients for the matter form's picker (alphabetical, first 100). */
export function useClientOptions(enabled = true) {
  return useQuery({
    queryKey: clientKeys.options,
    queryFn: ({ signal }) => api.get<Paginated<Client>>("/api/clients", { sort: "name", direction: "asc", per_page: 100 }, signal),
    enabled,
    staleTime: 60_000,
  });
}

export function useSaveClient(clientId?: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (input: ClientInput) =>
      (clientId
        ? await api.patch<Resource<Client>>(`/api/clients/${clientId}`, input)
        : await api.post<Resource<Client>>("/api/clients", input)
      ).data,
    onSuccess: (client) => {
      queryClient.setQueryData(clientKeys.detail(client.id), client);
      invalidateWorkspace(queryClient, { clientId: client.id });
    },
  });
}

export function useDeleteClient() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => api.delete(`/api/clients/${id}`),
    onSuccess: (_data, id) => {
      queryClient.removeQueries({ queryKey: clientKeys.detail(id) });
      invalidateWorkspace(queryClient);
    },
  });
}
