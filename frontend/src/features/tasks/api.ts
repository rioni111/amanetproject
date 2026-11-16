"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/lib/api/client";
import { invalidateWorkspace } from "@/lib/api/invalidate";
import type { Paginated, Priority, Resource, Task, TaskStatus } from "@/types/api";

export type TaskFilter = "" | "open" | TaskStatus;

export interface TaskInput {
  title?: string;
  description?: string | null;
  status?: TaskStatus;
  priority?: Priority;
  due_date?: string | null;
  lock_version?: number;
}

export const taskKeys = {
  list: (matterId: number, filter: TaskFilter) => ["matter", matterId, "tasks", filter] as const,
};

export function useTasks(matterId: number, filter: TaskFilter) {
  return useQuery({
    queryKey: taskKeys.list(matterId, filter),
    queryFn: ({ signal }) => api.get<Paginated<Task>>(`/api/matters/${matterId}/tasks`, { status: filter, per_page: 100 }, signal),
  });
}

/** After any task change: the matter's task lists, counters, history and the dashboard refresh. */
function useTaskMutation<V, R>(matterId: number, request: (variables: V) => Promise<R>) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: request,
    onSuccess: () => invalidateWorkspace(queryClient, { matterId }),
  });
}

export function useCreateTask(matterId: number) {
  return useTaskMutation(
    matterId,
    async (input: TaskInput) => (await api.post<Resource<Task>>(`/api/matters/${matterId}/tasks`, input)).data,
  );
}

export function useUpdateTask(matterId: number) {
  return useTaskMutation(
    matterId,
    async ({ id, ...input }: TaskInput & { id: number }) =>
      (await api.patch<Resource<Task>>(`/api/matters/${matterId}/tasks/${id}`, input)).data,
  );
}

export function useDeleteTask(matterId: number) {
  return useTaskMutation(matterId, (id: number) => api.delete(`/api/matters/${matterId}/tasks/${id}`));
}
