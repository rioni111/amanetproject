"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";

import { api } from "@/lib/api/client";
import type { Client, ClientType, Paginated } from "@/types/api";

export type ClientSort = "name" | "matters_count" | "created_at";

export interface ClientFilters {
  search: string;
  type: ClientType | "";
  sort: ClientSort;
  page: number;
}

export const clientKeys = {
  all: ["clients"] as const,
  list: (filters: ClientFilters) => ["clients", "list", filters] as const,
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
