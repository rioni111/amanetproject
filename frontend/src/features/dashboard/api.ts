"use client";

import { useQuery } from "@tanstack/react-query";

import { api } from "@/lib/api/client";
import type { DashboardSummary, Resource } from "@/types/api";

export const dashboardKeys = { summary: ["dashboard", "summary"] as const };

export function useDashboardSummary() {
  return useQuery({
    queryKey: dashboardKeys.summary,
    queryFn: async ({ signal }) => (await api.get<Resource<DashboardSummary>>("/api/dashboard/summary", undefined, signal)).data,
  });
}
