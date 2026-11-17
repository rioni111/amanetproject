"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { api, apiBlob, apiUpload } from "@/lib/api/client";
import { invalidateWorkspace } from "@/lib/api/invalidate";
import type { DocumentCategory, DocumentStatus, DocumentVersion, LegalDocument, Paginated, Resource } from "@/types/api";

export type DocumentSort = "updated_at" | "created_at" | "title" | "matter_number" | "category";

export interface DocumentFilters {
  search: string;
  category: DocumentCategory | "";
  status: DocumentStatus | "all" | "";
  matter_id: number | "";
  client_id: number | "";
  uploaded_from: string;
  uploaded_to: string;
  sort: DocumentSort;
  page: number;
}

const ASCENDING: DocumentSort[] = ["title", "matter_number", "category"];

function query(filters: DocumentFilters, perPage: number) {
  return {
    search: filters.search,
    category: filters.category,
    status: filters.status,
    matter_id: filters.matter_id,
    client_id: filters.client_id,
    uploaded_from: filters.uploaded_from,
    uploaded_to: filters.uploaded_to,
    sort: filters.sort,
    direction: ASCENDING.includes(filters.sort) ? "asc" : "desc",
    page: filters.page,
    per_page: perPage,
  };
}

/** Workspace-wide library (GET /api/documents). */
export function useDocumentLibrary(filters: DocumentFilters, perPage = 10) {
  return useQuery({
    queryKey: ["documents", "library", filters, perPage],
    queryFn: ({ signal }) => api.get<Paginated<LegalDocument>>("/api/documents", query(filters, perPage), signal),
    placeholderData: keepPreviousData,
  });
}

/** One matter's documents. Kept under the "matter" key so matter mutations refresh it too. */
export function useMatterDocuments(matterId: number, filters: DocumentFilters, perPage = 50) {
  return useQuery({
    queryKey: ["matter", matterId, "documents", filters, perPage],
    queryFn: ({ signal }) => api.get<Paginated<LegalDocument>>(`/api/matters/${matterId}/documents`, query(filters, perPage), signal),
    placeholderData: keepPreviousData,
  });
}

export function useDocument(matterId: number, documentId: number) {
  return useQuery({
    queryKey: ["document", matterId, documentId],
    queryFn: async ({ signal }) =>
      (await api.get<Resource<LegalDocument>>(`/api/matters/${matterId}/documents/${documentId}`, undefined, signal)).data,
  });
}

export function useDocumentVersions(matterId: number, documentId: number) {
  return useQuery({
    queryKey: ["document", matterId, documentId, "versions"],
    queryFn: ({ signal }) =>
      api.get<Paginated<DocumentVersion>>(`/api/matters/${matterId}/documents/${documentId}/versions`, undefined, signal),
  });
}

export interface DocumentUploadInput {
  file: File;
  title: string;
  category: DocumentCategory;
  description: string | null;
}

/** New document (version 1). Real upload progress is reported through `onProgress`. */
export function useUploadDocument(matterId: number | null) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({ input, onProgress }: { input: DocumentUploadInput; onProgress?: (fraction: number) => void }) => {
      if (!matterId) throw new Error("Choose a matter first.");
      const form = new FormData();
      form.append("file", input.file);
      form.append("title", input.title);
      form.append("category", input.category);
      if (input.description) form.append("description", input.description);
      return (await apiUpload<Resource<LegalDocument>>(`/api/matters/${matterId}/documents`, form, onProgress)).data;
    },
    onSuccess: (document) => invalidateWorkspace(queryClient, { matterId: document.matter_id }),
  });
}

export function useUploadVersion(matterId: number, documentId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({ file, lockVersion, onProgress }: { file: File; lockVersion: number; onProgress?: (fraction: number) => void }) => {
      const form = new FormData();
      form.append("file", file);
      form.append("lock_version", String(lockVersion));
      return (await apiUpload<Resource<DocumentVersion>>(`/api/matters/${matterId}/documents/${documentId}/versions`, form, onProgress))
        .data;
    },
    onSuccess: () => invalidateWorkspace(queryClient, { matterId }),
  });
}

function useDocumentMutation<V>(matterId: number, documentId: number, request: (variables: V) => Promise<Resource<LegalDocument>>) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (variables: V) => (await request(variables)).data,
    onSuccess: (document) => {
      queryClient.setQueryData(["document", matterId, documentId], document);
      invalidateWorkspace(queryClient, { matterId });
    },
  });
}

export function useUpdateDocument(matterId: number, documentId: number) {
  return useDocumentMutation(
    matterId,
    documentId,
    (vars: { title: string; description: string | null; category: DocumentCategory; lock_version: number }) =>
      api.patch<Resource<LegalDocument>>(`/api/matters/${matterId}/documents/${documentId}`, vars),
  );
}

export function useArchiveDocument(matterId: number, documentId: number, archive: boolean) {
  return useDocumentMutation(matterId, documentId, (vars: { lock_version: number }) =>
    api.post<Resource<LegalDocument>>(`/api/matters/${matterId}/documents/${documentId}/${archive ? "archive" : "restore"}`, vars),
  );
}

export function versionFileUrl(matterId: number, documentId: number, versionId: number, kind: "preview" | "download"): string {
  return `/api/matters/${matterId}/documents/${documentId}/versions/${versionId}/${kind}`;
}

/** Fetches the authorised download (recorded in the matter history) and saves it under its name. */
export async function downloadVersion(matterId: number, documentId: number, version: DocumentVersion): Promise<void> {
  const blob = await apiBlob(versionFileUrl(matterId, documentId, version.id, "download"));
  const url = URL.createObjectURL(blob);
  try {
    const link = document.createElement("a");
    link.href = url;
    link.download = version.original_filename;
    link.rel = "noopener";
    document.body.appendChild(link);
    link.click();
    link.remove();
  } finally {
    // Give the browser a moment to start the download before releasing the memory.
    window.setTimeout(() => URL.revokeObjectURL(url), 10_000);
  }
}
