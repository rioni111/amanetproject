import type { QueryClient } from "@tanstack/react-query";

/**
 * Cache keys that depend on workspace data. After a confirmed mutation every view that can show
 * the changed figures is refreshed from the API (no optimistic fake state):
 *  - matters / clients lists and details, dashboard statistics, the matter's tasks, notes and history.
 */
export function invalidateWorkspace(queryClient: QueryClient, options: { matterId?: number; clientId?: number } = {}) {
  void queryClient.invalidateQueries({ queryKey: ["dashboard"] });
  void queryClient.invalidateQueries({ queryKey: ["matters"] });
  void queryClient.invalidateQueries({ queryKey: ["clients"] });
  void queryClient.invalidateQueries({ queryKey: ["documents"] });
  void queryClient.invalidateQueries({ queryKey: ["document"] });
  void queryClient.invalidateQueries({ queryKey: ["document-requests"] });
  void queryClient.invalidateQueries({ queryKey: ["document-request"] });
  if (options.matterId) {
    void queryClient.invalidateQueries({ queryKey: ["matter", options.matterId] });
  }
}
