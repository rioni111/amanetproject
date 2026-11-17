"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/lib/api/client";
import { invalidateWorkspace } from "@/lib/api/invalidate";
import type { CommunicationStatus, EmailDelivery, EmailSendResult, Resource } from "@/types/api";

/** Which request an e-mail belongs to (the API path segment). */
export type EmailTarget = "document-requests" | "signing-requests";

/** Whether e-mail can be sent (and what is configured) — never credentials. */
export function useCommunicationStatus() {
  return useQuery({
    queryKey: ["communications", "status"],
    queryFn: async ({ signal }) => (await api.get<Resource<CommunicationStatus>>("/api/communications/status", undefined, signal)).data,
    staleTime: 60_000,
  });
}

export function useDeliveryHistory(target: EmailTarget, matterId: number, id: number) {
  return useQuery({
    queryKey: ["communications", target, matterId, id],
    queryFn: async ({ signal }) => (await api.get<{ data: EmailDelivery[] }>(`/api/matters/${matterId}/${target}/${id}/communications`, undefined, signal)).data,
  });
}

/**
 * Sends the e-mail for a request. The recipient is decided by the server (the request's client or the
 * designated signer); the browser only sends an idempotency key so a double click or a retried request
 * can never produce a second e-mail. Failures are recorded too, so caches refresh either way.
 */
export function useSendEmail(target: EmailTarget, matterId: number, id: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: { idempotency_key: string; link_expires_in_days?: number }) =>
      api.post<EmailSendResult>(`/api/matters/${matterId}/${target}/${id}/send-email`, input),
    onSettled: () => {
      invalidateWorkspace(queryClient, { matterId });
      void queryClient.invalidateQueries({ queryKey: ["communications"] });
      void queryClient.invalidateQueries({ queryKey: ["notifications"] });
    },
  });
}

/** A UUID v4 per send attempt (crypto.randomUUID is available in every secure context: HTTPS or localhost). */
export function newIdempotencyKey(): string {
  return globalThis.crypto.randomUUID();
}
