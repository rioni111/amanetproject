"use client";

import {
  Archive,
  ArchiveRestore,
  ArrowLeft,
  Download,
  Eye,
  FileSearch,
  FileUp,
  Fingerprint,
  Loader2,
  Pencil,
  ShieldCheck,
} from "lucide-react";
import Link from "next/link";
import * as React from "react";

import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { FormAlert } from "@/components/shared/form-alert";
import { FormField } from "@/components/shared/form-field";
import { ListSkeleton } from "@/components/shared/loading-skeleton";
import { useToast } from "@/components/shared/toaster";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog, DialogClose, DialogContent, DialogFooter } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { ApiError, getErrorMessage } from "@/lib/api/errors";
import { useSubmitGuard } from "@/lib/forms";
import { cn } from "@/lib/utils/cn";
import { formatDate, formatDateTime, formatFileSize } from "@/lib/utils/format";
import { DOCUMENT_CATEGORY_LABELS } from "@/lib/utils/labels";
import { DOCUMENT_CATEGORIES, type DocumentCategory, type DocumentVersion, type LegalDocument } from "@/types/api";

import { useArchiveDocument, useDocument, useDocumentVersions, useUpdateDocument } from "./api";
import { DocumentCategoryBadge, useDownload } from "./document-list";
import { DocumentUploadDialog } from "./document-upload-dialog";
import { PdfViewerDialog } from "./pdf-viewer-dialog";

export function DocumentDetailView({ matterId, documentId }: { matterId: number; documentId: number }) {
  const document = useDocument(matterId, documentId);

  if (document.isPending) {
    return (
      <div className="flex flex-col gap-6" role="status" aria-label="Loading document">
        <Skeleton className="h-3 w-32" />
        <Skeleton className="h-8 w-1/2" />
        <Skeleton className="h-72 w-full rounded-xl" />
      </div>
    );
  }
  if (document.isError) {
    return (
      <Card>
        {document.error instanceof ApiError && document.error.status === 404 ? (
          <EmptyState
            icon={FileSearch}
            title="Document not found"
            description="It does not belong to this matter, or the link is outdated."
            action={
              <Button variant="secondary" size="sm" asChild>
                <Link href="/documents">Back to documents</Link>
              </Button>
            }
          />
        ) : (
          <ErrorState
            error={document.error}
            title="We couldn't load this document"
            onRetry={() => document.refetch()}
            retrying={document.isRefetching}
          />
        )}
      </Card>
    );
  }
  return <DocumentWorkspace document={document.data} />;
}

function DocumentWorkspace({ document }: { document: LegalDocument }) {
  const [uploading, setUploading] = React.useState(false);
  const [editing, setEditing] = React.useState(false);
  const [confirmArchive, setConfirmArchive] = React.useState(false);
  const [preview, setPreview] = React.useState<DocumentVersion | null>(null);
  const { download, busyVersionId } = useDownload(document.matter_id, document.id);
  const archived = document.status === "archived";
  const matterArchived = document.matter?.status === "archived";
  const readOnly = archived || matterArchived;
  const toggle = useArchiveDocument(document.matter_id, document.id, !archived);
  const toast = useToast();
  const current = document.current_version;

  return (
    <div className="flex flex-col gap-6">
      <div>
        <Link
          href={`/matters/${document.matter_id}?tab=documents`}
          className="inline-flex items-center gap-1.5 text-[13px] font-medium text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft aria-hidden className="size-3.5" />
          {document.matter ? `${document.matter.matter_number} · Documents` : "Matter documents"}
        </Link>
      </div>

      <header className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <DocumentCategoryBadge category={document.category} />
            {archived ? <Badge tone="outline">Archived</Badge> : null}
            {current ? <span className="font-mono text-xs text-subtle-foreground">Current {current.label}</span> : null}
          </div>
          <h1 className="mt-2 text-[26px] leading-tight font-semibold tracking-[-0.02em] break-words text-foreground">{document.title}</h1>
          {document.matter ? (
            <p className="mt-1.5 text-[13px] text-muted-foreground">
              <Link href={`/matters/${document.matter.id}`} className="hover:text-foreground hover:underline">
                {document.matter.matter_number} · {document.matter.title}
              </Link>
              {document.matter.client ? (
                <>
                  {" · "}
                  <Link href={`/clients/${document.matter.client.id}`} className="hover:text-foreground hover:underline">
                    {document.matter.client.name}
                  </Link>
                </>
              ) : null}
            </p>
          ) : null}
        </div>
        <div className="flex shrink-0 flex-wrap gap-2">
          {current ? (
            <>
              <Button onClick={() => setPreview(current)}>
                <Eye aria-hidden />
                Preview
              </Button>
              <Button variant="secondary" onClick={() => void download(current)} disabled={busyVersionId !== null}>
                {busyVersionId === current.id ? <Loader2 aria-hidden className="animate-spin" /> : <Download aria-hidden />}
                Download
              </Button>
            </>
          ) : null}
          <Button variant="secondary" onClick={() => setUploading(true)} disabled={readOnly}>
            <FileUp aria-hidden />
            New version
          </Button>
          <Button variant="secondary" onClick={() => setEditing(true)} disabled={readOnly}>
            <Pencil aria-hidden />
            Edit details
          </Button>
          <Button variant="ghost" onClick={() => setConfirmArchive(true)} disabled={matterArchived}>
            {archived ? <ArchiveRestore aria-hidden /> : <Archive aria-hidden />}
            {archived ? "Restore" : "Archive"}
          </Button>
        </div>
      </header>

      {readOnly ? (
        <p role="status" className="rounded-lg border border-border-strong bg-surface-muted px-4 py-3 text-[13px] text-muted-foreground">
          {matterArchived
            ? "The matter is archived, so this document is read-only. Every version can still be previewed and downloaded."
            : "This document is archived. All versions are kept and can be previewed and downloaded; restore it to make changes."}
        </p>
      ) : null}

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        <VersionHistory document={document} onPreview={setPreview} onDownload={(v) => void download(v)} busyVersionId={busyVersionId} />
        <Card className="h-fit">
          <CardHeader>
            <CardTitle>Details</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className="flex flex-col divide-y divide-border text-[13px]">
              <Row label="Category" value={DOCUMENT_CATEGORY_LABELS[document.category]} />
              <Row
                label="Description"
                value={document.description ? <span className="whitespace-pre-wrap">{document.description}</span> : "—"}
              />
              <Row label="Versions" value={document.versions_count} />
              <Row label="First uploaded" value={formatDateTime(document.created_at)} />
              <Row label="Uploaded by" value={document.uploaded_by?.name ?? "—"} />
              <Row label="Last updated" value={formatDateTime(document.updated_at)} />
              {document.archived_at ? <Row label="Archived" value={formatDate(document.archived_at)} /> : null}
            </dl>
            <p className="mt-4 flex items-start gap-2 text-xs text-subtle-foreground">
              <ShieldCheck aria-hidden className="mt-px size-3.5 shrink-0" />
              Files are stored privately and checked against their SHA-256 fingerprint every time they are opened.
            </p>
          </CardContent>
        </Card>
      </div>

      {preview ? (
        <PdfViewerDialog
          open
          onOpenChange={(open) => !open && setPreview(null)}
          matterId={document.matter_id}
          documentId={document.id}
          title={document.title}
          version={preview}
          onDownload={() => void download(preview)}
        />
      ) : null}
      <DocumentUploadDialog
        open={uploading}
        onOpenChange={setUploading}
        target={{ kind: "version", matterId: document.matter_id, document }}
      />
      <EditDocumentDialog open={editing} onOpenChange={setEditing} document={document} />
      <ConfirmDialog
        open={confirmArchive}
        onOpenChange={setConfirmArchive}
        title={archived ? `Restore ${document.title}?` : `Archive ${document.title}?`}
        description={
          archived
            ? "The document becomes active again and can receive new versions."
            : "The document leaves the active lists and becomes read-only. No version is deleted; everything stays available."
        }
        confirmLabel={archived ? "Restore document" : "Archive document"}
        pending={toggle.isPending}
        onConfirm={async () => {
          try {
            await toggle.mutateAsync({ lock_version: document.lock_version });
            toast.success(archived ? "Document restored" : "Document archived", document.title);
          } catch (error) {
            toast.error(archived ? "Document not restored" : "Document not archived", getErrorMessage(error));
          } finally {
            setConfirmArchive(false);
          }
        }}
      />
    </div>
  );
}

function VersionHistory({
  document,
  onPreview,
  onDownload,
  busyVersionId,
}: {
  document: LegalDocument;
  onPreview: (version: DocumentVersion) => void;
  onDownload: (version: DocumentVersion) => void;
  busyVersionId: number | null;
}) {
  const versions = useDocumentVersions(document.matter_id, document.id);
  return (
    <Card className="min-w-0">
      <CardHeader className="border-b border-border">
        <div>
          <CardTitle>Version history</CardTitle>
          <CardDescription>Every uploaded file is kept unchanged. Newest first.</CardDescription>
        </div>
      </CardHeader>
      {versions.isPending ? (
        <div className="px-5">
          <ListSkeleton rows={2} />
        </div>
      ) : versions.isError ? (
        <ErrorState
          error={versions.error}
          title="We couldn't load the versions"
          onRetry={() => versions.refetch()}
          retrying={versions.isRefetching}
        />
      ) : (
        <ol aria-label="Versions" className="divide-y divide-border">
          {versions.data.data.map((version) => (
            <li
              key={version.id}
              className={cn("flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center", version.is_current && "bg-primary-soft/30")}
              data-testid="version-row"
            >
              <div className="flex min-w-0 flex-1 items-start gap-3">
                <span
                  className={cn(
                    "flex h-7 min-w-10 shrink-0 items-center justify-center rounded-md px-2 font-mono text-xs font-semibold",
                    version.is_current ? "bg-primary text-primary-foreground" : "bg-surface-muted text-muted-foreground",
                  )}
                >
                  {version.label}
                </span>
                <div className="min-w-0">
                  <p className="flex flex-wrap items-center gap-2 text-sm font-medium break-all text-foreground">
                    {version.original_filename}
                    {version.is_current ? <Badge tone="primary">Current</Badge> : null}
                  </p>
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    {formatFileSize(version.file_size)} · {formatDateTime(version.created_at)} · {version.uploaded_by?.name ?? "Unknown"}
                  </p>
                  <p
                    className="mt-0.5 flex items-center gap-1 font-mono text-[11px] text-subtle-foreground"
                    title={`SHA-256 ${version.sha256_checksum}`}
                  >
                    <Fingerprint aria-hidden className="size-3" />
                    <span className="sr-only">SHA-256 </span>
                    {version.sha256_checksum.slice(0, 16)}…
                  </p>
                </div>
              </div>
              <div className="flex shrink-0 gap-1.5 sm:justify-end">
                <Button variant="secondary" size="sm" onClick={() => onPreview(version)} aria-label={`Preview ${version.label}`}>
                  <Eye aria-hidden />
                  Preview
                </Button>
                <Button
                  variant="secondary"
                  size="sm"
                  onClick={() => onDownload(version)}
                  disabled={busyVersionId !== null}
                  aria-label={`Download ${version.label}`}
                >
                  {busyVersionId === version.id ? <Loader2 aria-hidden className="animate-spin" /> : <Download aria-hidden />}
                  Download
                </Button>
              </div>
            </li>
          ))}
        </ol>
      )}
    </Card>
  );
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
      <dt className="shrink-0 text-muted-foreground">{label}</dt>
      <dd className="min-w-0 text-right font-medium break-words text-foreground">{value}</dd>
    </div>
  );
}

function EditDocumentDialog({
  open,
  onOpenChange,
  document,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  document: LegalDocument;
}) {
  const update = useUpdateDocument(document.matter_id, document.id);
  const toast = useToast();
  const guard = useSubmitGuard();
  const [title, setTitle] = React.useState(document.title);
  const [category, setCategory] = React.useState<DocumentCategory>(document.category);
  const [description, setDescription] = React.useState(document.description ?? "");
  const [errors, setErrors] = React.useState<Record<string, string>>({});
  const [formError, setFormError] = React.useState<string | null>(null);
  const opened = React.useRef<{ open: boolean; version: number }>({ open: false, version: document.lock_version });

  React.useEffect(() => {
    if (open && !opened.current.open) {
      setTitle(document.title);
      setCategory(document.category);
      setDescription(document.description ?? "");
      setErrors({});
      setFormError(null);
      opened.current.version = document.lock_version;
    }
    opened.current.open = open;
  }, [open, document]);

  const submit = (event: React.FormEvent) => {
    event.preventDefault();
    void guard(async () => {
      if (!title.trim()) {
        setErrors({ title: "Enter a document title." });
        return;
      }
      setErrors({});
      setFormError(null);
      try {
        await update.mutateAsync({
          title: title.trim(),
          category,
          description: description.trim() || null,
          lock_version: opened.current.version,
        });
        toast.success("Document details updated", title.trim());
        onOpenChange(false);
      } catch (error) {
        if (error instanceof ApiError && error.isValidation) {
          const next: Record<string, string> = {};
          for (const f of ["title", "category", "description"]) {
            const m = error.field(f);
            if (m) next[f] = m;
          }
          setErrors(next);
          if (Object.keys(next).length === 0) setFormError(error.message);
        } else {
          setFormError(getErrorMessage(error));
        }
      }
    });
  };

  return (
    <Dialog open={open} onOpenChange={(next) => (!update.isPending ? onOpenChange(next) : undefined)}>
      <DialogContent title="Edit document details" description="Title, category and description. Stored files and versions never change.">
        <form onSubmit={submit} noValidate aria-label="Edit document details">
          <div className="flex flex-col gap-5 px-5 py-5">
            <FormAlert message={formError} />
            <FormField id="doc-title" label="Document title" required error={errors.title}>
              {(aria) => <Input {...aria} aria-required value={title} maxLength={255} onChange={(e) => setTitle(e.target.value)} />}
            </FormField>
            <FormField id="doc-category" label="Category" required error={errors.category}>
              {(aria) => (
                <Select {...aria} value={category} onChange={(e) => setCategory(e.target.value as DocumentCategory)}>
                  {DOCUMENT_CATEGORIES.map((c) => (
                    <option key={c} value={c}>
                      {DOCUMENT_CATEGORY_LABELS[c]}
                    </option>
                  ))}
                </Select>
              )}
            </FormField>
            <FormField id="doc-description" label="Description" error={errors.description} hint="Optional">
              {(aria) => (
                <Textarea {...aria} rows={3} value={description} maxLength={5000} onChange={(e) => setDescription(e.target.value)} />
              )}
            </FormField>
          </div>
          <DialogFooter>
            <DialogClose asChild>
              <Button variant="secondary" disabled={update.isPending}>
                Cancel
              </Button>
            </DialogClose>
            <Button type="submit" disabled={update.isPending}>
              {update.isPending ? <Loader2 aria-hidden className="animate-spin" /> : null}
              {update.isPending ? "Saving…" : "Save details"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
