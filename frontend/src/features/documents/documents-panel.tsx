"use client";

import { FileUp, FolderOpen, SearchX } from "lucide-react";
import * as React from "react";

import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { TableSkeleton } from "@/components/shared/loading-skeleton";
import { SearchInput } from "@/components/shared/search-input";
import { Button } from "@/components/ui/button";
import { Select } from "@/components/ui/select";
import { cn } from "@/lib/utils/cn";
import { DOCUMENT_CATEGORY_LABELS } from "@/lib/utils/labels";
import { DOCUMENT_CATEGORIES, type DocumentCategory, type Matter } from "@/types/api";

import { useMatterDocuments, type DocumentFilters } from "./api";
import { DocumentList } from "./document-list";
import { DocumentUploadDialog } from "./document-upload-dialog";

/** Documents tab of the matter workspace. Archived matters show their documents read-only. */
export function DocumentsPanel({ matter }: { matter: Matter }) {
  const [search, setSearch] = React.useState("");
  const [category, setCategory] = React.useState<DocumentCategory | "">("");
  const [showArchived, setShowArchived] = React.useState(false);
  const [uploading, setUploading] = React.useState(false);
  const readOnly = matter.status === "archived";

  const filters: DocumentFilters = {
    search,
    category,
    status: showArchived ? "archived" : "active",
    matter_id: "",
    client_id: "",
    uploaded_from: "",
    uploaded_to: "",
    sort: "updated_at",
    page: 1,
  };
  const documents = useMatterDocuments(matter.id, filters);
  const filtered = Boolean(search || category);

  return (
    <section aria-labelledby="documents-heading" className="flex flex-col gap-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 id="documents-heading" className="text-[15px] font-semibold text-foreground">
            Documents
          </h2>
          <p className="mt-0.5 text-[13px] text-muted-foreground">
            {matter.documents_count ?? 0} active {matter.documents_count === 1 ? "document" : "documents"} · PDFs are stored privately with
            every version kept.
          </p>
        </div>
        {!readOnly ? (
          <Button onClick={() => setUploading(true)}>
            <FileUp aria-hidden />
            Upload PDF
          </Button>
        ) : null}
      </div>

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        <SearchInput
          value={search}
          onChange={setSearch}
          label="Search documents"
          placeholder="Search title or file name…"
          className="sm:flex-1"
        />
        <div className="grid grid-cols-2 gap-3 sm:flex">
          <label htmlFor="documents-category" className="sr-only">
            Category
          </label>
          <Select
            id="documents-category"
            value={category}
            onChange={(e) => setCategory(e.target.value as DocumentCategory | "")}
            className="sm:w-44"
          >
            <option value="">All categories</option>
            {DOCUMENT_CATEGORIES.map((c) => (
              <option key={c} value={c}>
                {DOCUMENT_CATEGORY_LABELS[c]}
              </option>
            ))}
          </Select>
          <label htmlFor="documents-status" className="sr-only">
            Document status
          </label>
          <Select
            id="documents-status"
            value={showArchived ? "archived" : "active"}
            onChange={(e) => setShowArchived(e.target.value === "archived")}
            className="sm:w-36"
          >
            <option value="active">Active</option>
            <option value="archived">Archived</option>
          </Select>
        </div>
      </div>

      <div className="overflow-hidden rounded-xl border border-border bg-surface">
        {documents.isPending ? (
          <TableSkeleton rows={3} columns={5} />
        ) : documents.isError ? (
          <ErrorState
            error={documents.error}
            title="We couldn't load documents"
            onRetry={() => documents.refetch()}
            retrying={documents.isRefetching}
          />
        ) : documents.data.data.length === 0 ? (
          filtered || showArchived ? (
            <EmptyState
              icon={SearchX}
              title={showArchived ? "No archived documents" : "No documents match"}
              description="Try another search term or category."
            />
          ) : (
            <EmptyState
              icon={FolderOpen}
              title="No documents have been uploaded to this matter yet."
              description={
                readOnly ? "This matter is archived and read-only." : "Upload contracts, correspondence or evidence as PDF files."
              }
              action={
                !readOnly ? (
                  <Button size="sm" onClick={() => setUploading(true)}>
                    <FileUp aria-hidden />
                    Upload the first PDF
                  </Button>
                ) : undefined
              }
            />
          )
        ) : (
          <div className={cn("transition-opacity", documents.isPlaceholderData && "opacity-60")}>
            <DocumentList documents={documents.data.data} caption={`Documents of ${matter.matter_number}`} />
          </div>
        )}
      </div>

      <DocumentUploadDialog
        open={uploading}
        onOpenChange={setUploading}
        target={{ kind: "new", matterId: matter.id, matterLabel: `${matter.matter_number} · ${matter.title}` }}
      />
    </section>
  );
}
