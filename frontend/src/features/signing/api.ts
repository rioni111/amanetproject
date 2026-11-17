"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/lib/api/client";
import { invalidateWorkspace } from "@/lib/api/invalidate";
import type { IssuedLink, Paginated, Resource, SecureLink, SignatureEvidence, SignerType, SigningRequest, SigningStatus } from "@/types/api";

/** The signature the signer produced, ready for the API (strokes are normalised to the signature area). */
export type SignatureInput =
  | { method: "drawn"; strokes: [number, number][][]; typed_name: null }
  | { method: "typed"; strokes: null; typed_name: string };

export interface PlacementInput {
  page: number;
  x: number;
  y: number;
  width: number;
}

export interface SignaturePayload extends PlacementInput {
  method: "drawn" | "typed";
  strokes: [number, number][][] | null;
  typed_name: string | null;
  consent: boolean;
  consent_version: string;
  confirmed_name?: string;
}

export type SigningSort = "updated_at" | "created_at" | "signed_at" | "matter_number";

export interface SigningFilters {
  search: string;
  status: SigningStatus | "active" | "";
  signer_type: SignerType | "";
  sort: SigningSort;
  page: number;
}

export function useSigningList(filters: SigningFilters, perPage = 10) {
  return useQuery({
    queryKey: ["signing-requests", "list", filters, perPage],
    queryFn: ({ signal }) =>
      api.get<Paginated<SigningRequest>>(
        "/api/signing-requests",
        { ...filters, direction: filters.sort === "matter_number" ? "asc" : "desc", per_page: perPage },
        signal,
      ),
    placeholderData: keepPreviousData,
  });
}

export function useMatterSigningRequests(matterId: number) {
  return useQuery({
    queryKey: ["matter", matterId, "signing-requests"],
    queryFn: ({ signal }) =>
      api.get<Paginated<SigningRequest>>(`/api/matters/${matterId}/signing-requests`, { per_page: 100, sort: "updated_at" }, signal),
  });
}

export interface SigningDetail {
  data: SigningRequest;
  links: SecureLink[];
  evidence: SignatureEvidence | null;
}

export function useSigningRequest(matterId: number, id: number) {
  return useQuery({
    queryKey: ["signing-request", matterId, id],
    queryFn: ({ signal }) => api.get<SigningDetail>(`/api/matters/${matterId}/signing-requests/${id}`, undefined, signal),
  });
}

export interface Eligibility {
  eligible: true;
  page_count: number;
  pages: { width: number; height: number }[];
  consent: { version: string; text: string };
}

/** Server check that a version can be signed now (archive state, current version, PDF engine support). */
export function useSigningEligibility(matterId: number, documentId: number, versionId: number | null, enabled: boolean) {
  return useQuery({
    queryKey: ["signing-eligibility", matterId, documentId, versionId],
    queryFn: async ({ signal }) =>
      (
        await api.get<Resource<Eligibility>>(
          `/api/matters/${matterId}/documents/${documentId}/versions/${versionId}/signing-eligibility`,
          undefined,
          signal,
        )
      ).data,
    enabled: enabled && versionId !== null,
    retry: false,
    staleTime: 0,
  });
}

/** Responses that create a link carry its URL once; callers keep it in component state only (never the cache). */
export interface WithLink {
  data: SigningRequest;
  link: IssuedLink | null;
}

function useSigningMutation<V, R>(matterId: number, request: (variables: V) => Promise<R>) {
  const queryClient = useQueryClient();
  return useMutation({ mutationFn: request, onSuccess: () => invalidateWorkspace(queryClient, { matterId }) });
}

export interface CreateSigningInput {
  document_id: number;
  document_version_id: number;
  signer_type: SignerType;
  signer_name?: string | null;
  signer_email?: string | null;
  message?: string | null;
  issue_link: boolean;
  link_expires_in_days?: number;
}

export function useCreateSigningRequest(matterId: number) {
  return useSigningMutation(matterId, (input: CreateSigningInput) => api.post<WithLink>(`/api/matters/${matterId}/signing-requests`, input));
}

export function useSigningLink(matterId: number, id: number) {
  return useSigningMutation(matterId, ({ rotate, expires_in_days }: { rotate: boolean; expires_in_days?: number }) =>
    api.post<WithLink>(`/api/matters/${matterId}/signing-requests/${id}/links${rotate ? "/rotate" : ""}`, { expires_in_days }),
  );
}

export function useRevokeSigningLink(matterId: number, id: number) {
  return useSigningMutation(matterId, (linkId: number) =>
    api.post<{ data: SigningRequest }>(`/api/matters/${matterId}/signing-requests/${id}/links/${linkId}/revoke`),
  );
}

export function useSigningAction(matterId: number, id: number) {
  return useSigningMutation(matterId, ({ action, lock_version }: { action: "mark-shared" | "cancel"; lock_version: number }) =>
    api.post<Resource<SigningRequest>>(`/api/matters/${matterId}/signing-requests/${id}/${action}`, { lock_version }),
  );
}

/** Administrator signs an existing request designated to them. */
export function useAdminSign(matterId: number, id: number) {
  return useSigningMutation(matterId, (payload: SignaturePayload & { lock_version: number }) =>
    api.post<Resource<SigningRequest>>(`/api/matters/${matterId}/signing-requests/${id}/admin-sign`, payload),
  );
}

/** "Sign PDF" on a document: creates the administrator's request and signs it in one server step. */
export function useSignDocument(matterId: number, documentId: number) {
  return useSigningMutation(matterId, (payload: SignaturePayload & { document_version_id: number }) =>
    api.post<Resource<SigningRequest>>(`/api/matters/${matterId}/documents/${documentId}/sign`, payload),
  );
}

export function signingHref(request: { id: number; matter_id: number }): string {
  return `/matters/${request.matter_id}/signatures/${request.id}`;
}
