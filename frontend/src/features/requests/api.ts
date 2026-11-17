"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api } from "@/lib/api/client";
import { invalidateWorkspace } from "@/lib/api/invalidate";
import type {
  DocumentCategory,
  DocumentRequest,
  DocumentRequestStatus,
  DocumentRequestStatusGroup,
  DocumentRequestSubmission,
  IssuedLink,
  Paginated,
  Resource,
  SecureLink,
} from "@/types/api";

export type RequestSort = "updated_at" | "created_at" | "due_at" | "title" | "last_submitted_at" | "matter_number";

export interface RequestFilters {
  search: string;
  status: DocumentRequestStatus | DocumentRequestStatusGroup | "";
  overdue: 0 | 1;
  sort: RequestSort;
  page: number;
}

const ASCENDING: RequestSort[] = ["title", "matter_number", "due_at"];

function query(filters: RequestFilters, perPage: number) {
  return {
    search: filters.search,
    status: filters.status,
    overdue: filters.overdue ? 1 : undefined,
    sort: filters.sort,
    direction: ASCENDING.includes(filters.sort) ? "asc" : "desc",
    page: filters.page,
    per_page: perPage,
  };
}

/** Workspace-wide outstanding requirements (GET /api/document-requests). */
export function useRequestList(filters: RequestFilters, perPage = 10) {
  return useQuery({
    queryKey: ["document-requests", "list", filters, perPage],
    queryFn: ({ signal }) => api.get<Paginated<DocumentRequest>>("/api/document-requests", query(filters, perPage), signal),
    placeholderData: keepPreviousData,
  });
}

/** One matter's requests. Under the "matter" key too, so matter mutations refresh it. */
export function useMatterRequests(matterId: number) {
  return useQuery({
    queryKey: ["matter", matterId, "document-requests"],
    queryFn: ({ signal }) =>
      api.get<Paginated<DocumentRequest>>(`/api/matters/${matterId}/document-requests`, { per_page: 100, sort: "updated_at" }, signal),
  });
}

export interface RequestDetail {
  data: DocumentRequest;
  links: SecureLink[];
}

export function useDocumentRequest(matterId: number, requestId: number) {
  return useQuery({
    queryKey: ["document-request", matterId, requestId],
    queryFn: ({ signal }) => api.get<RequestDetail>(`/api/matters/${matterId}/document-requests/${requestId}`, undefined, signal),
  });
}

export function useRequestSubmissions(matterId: number, requestId: number) {
  return useQuery({
    queryKey: ["document-request", matterId, requestId, "submissions"],
    queryFn: ({ signal }) =>
      api.get<{ data: DocumentRequestSubmission[] }>(`/api/matters/${matterId}/document-requests/${requestId}/submissions`, undefined, signal),
  });
}

export interface RequestInput {
  title: string;
  instructions: string;
  requested_category: DocumentCategory;
  due_date: string | null;
}

/** Phase 6: outcome of "send the correction by e-mail" (the correction itself is always recorded). */
export interface CorrectionEmailOutcome {
  status: "accepted" | "failed" | "not_sent";
  message: string;
  delivery: unknown;
}

/** Responses that create a link carry its URL once. Callers keep it in component state only. */
export interface WithLink {
  data: DocumentRequest;
  link: IssuedLink | null;
}

function useRequestMutation<V, R>(matterId: number, request: (variables: V) => Promise<R>) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: request,
    // Never put the response (which may contain a one-time URL) into the query cache: refetch instead.
    onSuccess: () => invalidateWorkspace(queryClient, { matterId }),
  });
}

export function useCreateRequest(matterId: number) {
  return useRequestMutation(matterId, (input: RequestInput & { issue_link: boolean; link_expires_in_days?: number }) =>
    api.post<WithLink>(`/api/matters/${matterId}/document-requests`, input),
  );
}

export function useUpdateRequest(matterId: number, requestId: number) {
  return useRequestMutation(matterId, (input: RequestInput & { lock_version: number }) =>
    api.patch<Resource<DocumentRequest>>(`/api/matters/${matterId}/document-requests/${requestId}`, input),
  );
}

export type LinkAction = "issue" | "rotate";

export function useIssueLink(matterId: number, requestId: number) {
  return useRequestMutation(matterId, ({ action, expires_in_days }: { action: LinkAction; expires_in_days?: number }) =>
    api.post<WithLink>(`/api/matters/${matterId}/document-requests/${requestId}/links${action === "rotate" ? "/rotate" : ""}`, {
      expires_in_days,
    }),
  );
}

export function useRevokeLink(matterId: number, requestId: number) {
  return useRequestMutation(matterId, (linkId: number) =>
    api.post<{ data: DocumentRequest; revoked_link: SecureLink }>(
      `/api/matters/${matterId}/document-requests/${requestId}/links/${linkId}/revoke`,
    ),
  );
}

export type ReviewAction = "start-review" | "approve";

export function useReviewAction(matterId: number, requestId: number) {
  return useRequestMutation(
    matterId,
    ({ action, submission_id, lock_version }: { action: ReviewAction; submission_id: number; lock_version: number }) =>
      api.post<Resource<DocumentRequest>>(`/api/matters/${matterId}/document-requests/${requestId}/${action}`, {
        submission_id,
        lock_version,
      }),
  );
}

export function useRequestCorrection(matterId: number, requestId: number) {
  return useRequestMutation(
    matterId,
    (input: { submission_id: number; lock_version: number; reason: string; expires_in_days?: number; send_email?: boolean }) =>
      api.post<WithLink & { email?: CorrectionEmailOutcome }>(`/api/matters/${matterId}/document-requests/${requestId}/request-correction`, input),
  );
}

export function useCancelRequest(matterId: number, requestId: number) {
  return useRequestMutation(matterId, (input: { lock_version: number }) =>
    api.post<Resource<DocumentRequest>>(`/api/matters/${matterId}/document-requests/${requestId}/cancel`, input),
  );
}

export function requestHref(request: { id: number; matter_id: number }): string {
  return `/matters/${request.matter_id}/requests/${request.id}`;
}
