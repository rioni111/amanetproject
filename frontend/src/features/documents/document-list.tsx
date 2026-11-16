"use client";

import { useQueryClient } from "@tanstack/react-query";
import { ChevronRight, Download, Eye, FileText, Loader2 } from "lucide-react";
import Link from "next/link";
import * as React from "react";

import { DataTable, type Column } from "@/components/shared/data-table";
import { useToast } from "@/components/shared/toaster";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { getErrorMessage } from "@/lib/api/errors";
import { formatDate, formatDateTime, formatFileSize } from "@/lib/utils/format";
import { DOCUMENT_CATEGORY_LABELS } from "@/lib/utils/labels";
import type { DocumentCategory, DocumentVersion, LegalDocument } from "@/types/api";

import { downloadVersion } from "./api";
import { PdfViewerDialog } from "./pdf-viewer-dialog";

export function documentHref(document: Pick<LegalDocument, "id" | "matter_id">): string {
  return `/matters/${document.matter_id}/documents/${document.id}`;
}

export function DocumentCategoryBadge({ category }: { category: DocumentCategory }) {
  return (
    <Badge tone={category === "evidence" || category === "court_documents" ? "warning" : category === "other" ? "neutral" : "primary"}>
      {DOCUMENT_CATEGORY_LABELS[category]}
    </Badge>
  );
}

/** Download through the API (recorded in the matter history), with feedback; refreshes the timeline. */
export function useDownload(matterId: number, documentId: number) {
  const toast = useToast();
  const queryClient = useQueryClient();
  const [busy, setBusy] = React.useState<number | null>(null);
  const download = React.useCallback(
    async (version: DocumentVersion) => {
      if (busy !== null) return;
      setBusy(version.id);
      try {
        await downloadVersion(matterId, documentId, version);
        void queryClient.invalidateQueries({ queryKey: ["matter", matterId, "activity"] });
      } catch (error) {
        toast.error("Download failed", getErrorMessage(error));
      } finally {
        setBusy(null);
      }
    },
    [busy, matterId, documentId, queryClient, toast],
  );
  return { download, busyVersionId: busy };
}

/** Preview + download of the current version, and a link to the document page. */
export function DocumentRowActions({ document, compact = false }: { document: LegalDocument; compact?: boolean }) {
  const [previewing, setPreviewing] = React.useState(false);
  const { download, busyVersionId } = useDownload(document.matter_id, document.id);
  const version = document.current_version;

  return (
    <div className="flex items-center justify-end gap-0.5">
      {version ? (
        <>
          <Button
            variant="ghost"
            size={compact ? "icon" : "sm"}
            className={compact ? "size-8" : undefined}
            onClick={() => setPreviewing(true)}
            aria-label={`Preview ${document.title}`}
          >
            <Eye aria-hidden />
            {!compact ? <span className="hidden xl:inline">Preview</span> : null}
          </Button>
          <Button
            variant="ghost"
            size={compact ? "icon" : "sm"}
            className={compact ? "size-8" : undefined}
            onClick={() => void download(version)}
            disabled={busyVersionId !== null}
            aria-label={`Download ${document.title} (${version.label})`}
          >
            {busyVersionId === version.id ? <Loader2 aria-hidden className="animate-spin" /> : <Download aria-hidden />}
            {!compact ? <span className="hidden xl:inline">Download</span> : null}
          </Button>
        </>
      ) : null}
      <Button variant="ghost" size={compact ? "icon" : "sm"} className={compact ? "size-8" : undefined} asChild>
        <Link href={documentHref(document)} aria-label={`Open ${document.title}`}>
          {!compact ? <span className="hidden xl:inline">Open</span> : null}
          <ChevronRight aria-hidden />
        </Link>
      </Button>
      {version ? (
        <PdfViewerDialog
          open={previewing}
          onOpenChange={setPreviewing}
          matterId={document.matter_id}
          documentId={document.id}
          title={document.title}
          version={version}
          onDownload={() => void download(version)}
        />
      ) : null}
    </div>
  );
}

function DocumentIdentity({ document }: { document: LegalDocument }) {
  return (
    <div className="flex min-w-0 items-start gap-3">
      <FileText aria-hidden className="mt-0.5 size-5 shrink-0 text-subtle-foreground" />
      <div className="min-w-0">
        <Link
          href={documentHref(document)}
          className="block truncate font-medium text-foreground hover:text-primary hover:underline"
          title={document.title}
        >
          {document.title}
        </Link>
        <p className="mt-0.5 truncate text-xs text-subtle-foreground">{document.current_version?.original_filename}</p>
      </div>
    </div>
  );
}

function VersionCell({ document }: { document: LegalDocument }) {
  return (
    <span className="text-[13px] whitespace-nowrap text-muted-foreground">
      <span className="font-mono font-medium text-foreground">{document.current_version?.label ?? "—"}</span>
      {document.versions_count > 1 ? ` of ${document.versions_count}` : ""} · {formatFileSize(document.current_version?.file_size)}
    </span>
  );
}

/**
 * Documents as a table (md and up) and as cards (phones). `showMatter` adds matter and client
 * columns for the workspace-wide library.
 */
export function DocumentList({
  documents,
  caption,
  showMatter = false,
}: {
  documents: LegalDocument[];
  caption: string;
  showMatter?: boolean;
}) {
  const columns: Column<LegalDocument>[] = [
    { key: "title", header: "Document", cell: (d) => <DocumentIdentity document={d} />, className: "max-w-[300px]" },
    ...(showMatter
      ? [
          {
            key: "matter",
            header: "Matter",
            cell: (d: LegalDocument) =>
              d.matter ? (
                <div className="min-w-[150px]">
                  <Link
                    href={`/matters/${d.matter.id}`}
                    className="font-mono text-[11px] text-subtle-foreground hover:text-foreground hover:underline"
                  >
                    {d.matter.matter_number}
                  </Link>
                  <p className="truncate text-[13px] text-muted-foreground">{d.matter.client?.name ?? d.matter.title}</p>
                </div>
              ) : null,
          },
        ]
      : []),
    { key: "category", header: "Category", cell: (d) => <DocumentCategoryBadge category={d.category} /> },
    { key: "version", header: "Version", cell: (d) => <VersionCell document={d} /> },
    {
      key: "uploaded",
      header: "Uploaded",
      cell: (d) => <span className="text-[13px] whitespace-nowrap text-muted-foreground">{formatDate(d.created_at)}</span>,
    },
    {
      key: "updated",
      header: "Updated",
      cell: (d) => (
        <span className="text-[13px] whitespace-nowrap text-muted-foreground" title={formatDateTime(d.updated_at)}>
          {formatDate(d.updated_at)}
        </span>
      ),
    },
    {
      key: "actions",
      header: <span className="sr-only">Actions</span>,
      headerClassName: "text-right",
      className: "text-right",
      cell: (d) => <DocumentRowActions document={d} />,
    },
  ];

  return (
    <DataTable
      caption={caption}
      rows={documents}
      getRowKey={(d) => d.id}
      columns={columns}
      renderMobileItem={(d) => (
        <div className="flex flex-col gap-2.5" data-testid="document-card">
          <div className="flex items-start justify-between gap-3">
            <DocumentIdentity document={d} />
            <DocumentCategoryBadge category={d.category} />
          </div>
          {showMatter && d.matter ? (
            <p className="truncate text-[13px] text-muted-foreground">
              <span className="font-mono text-[11px]">{d.matter.matter_number}</span> · {d.matter.client?.name ?? d.matter.title}
            </p>
          ) : null}
          <div className="flex items-center justify-between gap-3">
            <VersionCell document={d} />
            <DocumentRowActions document={d} compact />
          </div>
        </div>
      )}
    />
  );
}
