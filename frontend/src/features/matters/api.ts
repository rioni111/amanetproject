"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";

import { api } from "@/lib/api/client";
import type { Matter, MatterStatus, Paginated, Priority } from "@/types/api";

export type MatterSort = "updated_at" | "due_at" | "matter_number" | "priority";

export interface MatterFilters {
  search: string;
  status: MatterStatus | "active" | "";
  priority: Priority | "";
  sort: MatterSort;
  page: number;
}

export const matterKeys = {
  all: ["matters"] as const,
  list: (filters: MatterFilters) => ["matters", "list", filters] as const,
};

export function useMatters(filters: MatterFilters) {
  return useQuery({
    queryKey: matterKeys.list(filters),
    queryFn: ({ signal }) =>
      api.get<Paginated<Matter>>(
        "/api/matters",
        {
          search: filters.search,
          status: filters.status,
          priority: filters.priority,
          sort: filters.sort,
          direction: filters.sort === "priority" || filters.sort === "updated_at" ? "desc" : "asc",
          page: filters.page,
          per_page: 10,
        },
        signal,
      ),
    placeholderData: keepPreviousData,
  });
}
