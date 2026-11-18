"use client";

import { FileUp, FolderOpen, SearchX } from "lucide-react";
import { useRouter } from "next/navigation";
import * as React from "react";

import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { TableSkeleton } from "@/components/shared/loading-skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { Pagination } from "@/components/shared/pagination";
import { SearchInput } from "@/components/shared/search-input";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { useClientOptions } from "@/features/clients/api";
import { useMatters, type MatterFilters } from "@/features/matters/api";
import { useUrlFilters } from "@/hooks/use-url-filters";
import { cn } from "@/lib/utils/cn";
import { DOCUMENT_CATEGORY_LABELS } from "@/lib/utils/labels";
import { DOCUMENT_CATEGORIES, type DocumentCategory } from "@/types/api";

import { useDocumentLibrary, type DocumentFilters, type DocumentSort } from "./api";
import { documentHref, DocumentList } from "./document-list";
import { DocumentUploadDialog } from "./document-upload-dialog";

const SORT_OPTIONS: { value: DocumentSort; label: string }[] = [
  { value: "updated_at", label: "Recently updated" },
  { value: "created_at", label: "Newest uploaded" },
  { value: "title", label: "Title (A–Z)" },
  { value: "matter_number", label: "Matter number" },
  { value: "category", label: "Category" },
];

const DEFAULTS: DocumentFilters = {
  search: "",
  category: "",
  status: "",
  matter_id: "",
  client_id: "",
  uploaded_from: "",
  uploaded_to: "",
  sort: "updated_at",
  page: 1,
};

const DATE = /^\d{4}-\d{2}-\d{2}$/;

function parseFilters(params: URLSearchParams): DocumentFilters {
  const category = params.get("category") ?? "";
  const status = params.get("status") ?? "";
  const sort = params.get("sort") ?? "";
  const matterId = Number.parseInt(params.get("matter_id") ?? "", 10);
  const clientId = Number.parseInt(params.get("client_id") ?? "", 10);
  const page = Number.parseInt(params.get("page") ?? "1", 10);
  const from = params.get("uploaded_from") ?? "";
  const to = params.get("uploaded_to") ?? "";
  return {
    search: (params.get("search") ?? "").slice(0, 100),
    category: (DOCUMENT_CATEGORIES as readonly string[]).includes(category) ? (category as DocumentCategory) : "",
    status: status === "archived" || status === "all" ? status : "",
    matter_id: Number.isFinite(matterId) && matterId > 0 ? matterId : "",
    client_id: Number.isFinite(clientId) && clientId > 0 ? clientId : "",
    uploaded_from: DATE.test(from) ? from : "",
    uploaded_to: DATE.test(to) ? to : "",
    sort: SORT_OPTIONS.some((o) => o.value === sort) ? (sort as DocumentSort) : DEFAULTS.sort,
    page: Number.isFinite(page) && page > 0 ? page : 1,
  };
}

/**
 * Workspace-wide document library. Filters live in the URL through useUrlFilters (which builds each
 * update on the latest requested filters, so rapid changes never overwrite one another).
 */
export function DocumentsLibraryView() {
  const [filters, setFilters] = useUrlFilters(parseFilters, DEFAULTS);
  const library = useDocumentLibrary(filters);
  const clients = useClientOptions();
  const matterFilters: MatterFilters = {
    search: "",
    status: "all", // the library also holds documents of archived matters
    priority: "",
    deadline: "",
    client_id: "",
    sort: "matter_number",
    page: 1,
  };
  const matters = useMatters(matterFilters, 100);
  const router = useRouter();
  const [uploading, setUploading] = React.useState(false);
  const onSearch = React.useCallback((search: string) => setFilters({ search }), [setFilters]);
  const filtered = Boolean(
    filters.search ||
    filters.category ||
    filters.matter_id ||
    filters.client_id ||
    filters.uploaded_from ||
    filters.uploaded_to ||
    filters.status,
  );
  const clear = () =>
    setFilters({ search: "", category: "", status: "", matter_id: "", client_id: "", uploaded_from: "", uploaded_to: "" });

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Documents"
        description="Every PDF across your matters, with full version history. Search covers titles, file names, matters and clients (not the text inside PDFs)."
        actions={
          <Button onClick={() => setUploading(true)}>
            <FileUp aria-hidden />
            Upload PDF
          </Button>
        }
      />
      <Card className="overflow-hidden">
        <div className="flex flex-col gap-3 border-b border-border p-4">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
            <SearchInput
              value={filters.search}
              onChange={onSearch}
              label="Search documents"
              placeholder="Search title, file name, matter or client…"
              className="sm:flex-1 lg:max-w-md"
            />
            <label htmlFor="sort-documents" className="sr-only">
              Sort by
            </label>
            <Select
              id="sort-documents"
              value={filters.sort}
              onChange={(e) => setFilters({ sort: e.target.value as DocumentSort })}
              className="sm:ml-auto sm:w-48"
            >
              {SORT_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-6">
            <label htmlFor="filter-doc-category" className="sr-only">
              Category
            </label>
            <Select
              id="filter-doc-category"
              value={filters.category}
              onChange={(e) => setFilters({ category: e.target.value as DocumentCategory | "" })}
            >
              <option value="">All categories</option>
              {DOCUMENT_CATEGORIES.map((c) => (
                <option key={c} value={c}>
                  {DOCUMENT_CATEGORY_LABELS[c]}
                </option>
              ))}
            </Select>
            <label htmlFor="filter-doc-matter" className="sr-only">
              Matter
            </label>
            <Select
              id="filter-doc-matter"
              value={String(filters.matter_id)}
              onChange={(e) => setFilters({ matter_id: e.target.value ? Number(e.target.value) : "" })}
            >
              <option value="">All matters</option>
              {matters.data?.data.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.matter_number} · {m.title}
                </option>
              ))}
            </Select>
            <label htmlFor="filter-doc-client" className="sr-only">
              Client
            </label>
            <Select
              id="filter-doc-client"
              value={String(filters.client_id)}
              onChange={(e) => setFilters({ client_id: e.target.value ? Number(e.target.value) : "" })}
            >
              <option value="">All clients</option>
              {clients.data?.data.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </Select>
            <label htmlFor="filter-doc-status" className="sr-only">
              Document status
            </label>
            <Select
              id="filter-doc-status"
              value={filters.status}
              onChange={(e) => setFilters({ status: e.target.value as DocumentFilters["status"] })}
            >
              <option value="">Active documents</option>
              <option value="archived">Archived documents</option>
              <option value="all">All documents</option>
            </Select>
            <label htmlFor="filter-doc-from" className="sr-only">
              Uploaded from
            </label>
            <Input
              id="filter-doc-from"
              type="date"
              value={filters.uploaded_from}
              onChange={(e) => setFilters({ uploaded_from: e.target.value })}
              className="h-9"
              title="Uploaded from"
            />
            <label htmlFor="filter-doc-to" className="sr-only">
              Uploaded until
            </label>
            <Input
              id="filter-doc-to"
              type="date"
              value={filters.uploaded_to}
              onChange={(e) => setFilters({ uploaded_to: e.target.value })}
              className="h-9"
              title="Uploaded until"
            />
          </div>
        </div>

        {library.isPending ? (
          <TableSkeleton rows={6} columns={6} />
        ) : library.isError ? (
          <ErrorState
            error={library.error}
            title="We couldn't load documents"
            onRetry={() => library.refetch()}
            retrying={library.isRefetching}
          />
        ) : library.data.data.length === 0 ? (
          filtered ? (
            <EmptyState
              icon={SearchX}
              title="No documents match your filters"
              description="Try a different search term or remove a filter."
              action={
                <Button variant="secondary" size="sm" onClick={clear}>
                  Clear filters
                </Button>
              }
            />
          ) : (
            <EmptyState
              icon={FolderOpen}
              title="No documents yet"
              description="Upload a PDF to a matter to start the library."
              action={
                <Button size="sm" onClick={() => setUploading(true)}>
                  <FileUp aria-hidden />
                  Upload PDF
                </Button>
              }
            />
          )
        ) : (
          <div className={cn("transition-opacity", library.isPlaceholderData && "opacity-60")} aria-busy={library.isPlaceholderData}>
            <DocumentList documents={library.data.data} caption="Documents" showMatter />
            <Pagination
              meta={library.data.meta}
              noun="documents"
              disabled={library.isPlaceholderData}
              onPageChange={(page) => setFilters({ page })}
            />
          </div>
        )}
      </Card>
      <DocumentUploadDialog
        open={uploading}
        onOpenChange={setUploading}
        target={{ kind: "new" }}
        onUploaded={({ matterId, documentId }) => router.push(documentHref({ id: documentId, matter_id: matterId }))}
      />
    </div>
  );
}
