"use client";

import { CheckCircle2, FileText, FileUp, Loader2, X } from "lucide-react";
import * as React from "react";

import { FormAlert } from "@/components/shared/form-alert";
import { FormField } from "@/components/shared/form-field";
import { useToast } from "@/components/shared/toaster";
import { Button } from "@/components/ui/button";
import { Dialog, DialogClose, DialogContent, DialogFooter } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { useMatters, type MatterFilters } from "@/features/matters/api";
import { ApiError, getErrorMessage } from "@/lib/api/errors";
import { useSubmitGuard } from "@/lib/forms";
import { cn } from "@/lib/utils/cn";
import { formatFileSize } from "@/lib/utils/format";
import { DOCUMENT_CATEGORY_LABELS } from "@/lib/utils/labels";
import { DOCUMENT_CATEGORIES, type DocumentCategory, type LegalDocument } from "@/types/api";

import { useUploadDocument, useUploadVersion } from "./api";

/** Mirrors DOCUMENTS_MAX_UPLOAD_MB (backend); the server re-checks everything. */
export const MAX_UPLOAD_MB = 20;

/** Quick browser-side checks for instant feedback. The server's byte-level validation is authoritative. */
async function precheck(file: File): Promise<string | null> {
  if (!/\.pdf$/i.test(file.name)) return "Only PDF files (.pdf) can be uploaded.";
  if (file.size === 0) return "The file is empty.";
  if (file.size > MAX_UPLOAD_MB * 1024 * 1024) return `The PDF is larger than the ${MAX_UPLOAD_MB} MB limit.`;
  const head = new TextDecoder("latin1").decode(await file.slice(0, 1024).arrayBuffer());
  if (!head.includes("%PDF-")) return "This file is not a valid PDF.";
  return null;
}

function titleFromFilename(name: string): string {
  return name
    .replace(/\.pdf$/i, "")
    .replace(/[_-]+/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

type Target = { kind: "new"; matterId?: number; matterLabel?: string } | { kind: "version"; matterId: number; document: LegalDocument };

/**
 * Upload a new document (version 1) — from a matter (matter fixed) or from the library (choose an
 * active matter) — or a new version of an existing document. Success is shown only after the API
 * has stored the file and its metadata.
 */
export function DocumentUploadDialog({
  open,
  onOpenChange,
  target,
  onUploaded,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  target: Target;
  onUploaded?: (result: { matterId: number; documentId: number }) => void;
}) {
  const toast = useToast();
  const guard = useSubmitGuard();
  const [file, setFile] = React.useState<File | null>(null);
  const [fileError, setFileError] = React.useState<string | null>(null);
  const [title, setTitle] = React.useState("");
  const [titleTouched, setTitleTouched] = React.useState(false);
  const [category, setCategory] = React.useState<DocumentCategory | "">("");
  const [description, setDescription] = React.useState("");
  const [matterId, setMatterId] = React.useState<number | "">(target.kind === "new" ? (target.matterId ?? "") : target.matterId);
  const [errors, setErrors] = React.useState<Record<string, string>>({});
  const [formError, setFormError] = React.useState<string | null>(null);
  const [progress, setProgress] = React.useState<number | null>(null);
  const [dragging, setDragging] = React.useState(false);
  const inputRef = React.useRef<HTMLInputElement>(null);

  const isVersion = target.kind === "version";
  const chooseMatter = target.kind === "new" && !target.matterId;
  const uploadDocument = useUploadDocument(typeof matterId === "number" ? matterId : null);
  const uploadVersion = useUploadVersion(target.matterId ?? 0, isVersion ? target.document.id : 0);
  const pending = uploadDocument.isPending || uploadVersion.isPending;

  const matterFilters: MatterFilters = {
    search: "",
    status: "active",
    priority: "",
    deadline: "",
    client_id: "",
    sort: "matter_number",
    page: 1,
  };
  const matters = useMatters(matterFilters, 100);

  const wasOpen = React.useRef(false);
  React.useEffect(() => {
    if (open && !wasOpen.current) {
      setFile(null);
      setFileError(null);
      setTitle("");
      setTitleTouched(false);
      setCategory(isVersion ? target.document.category : "");
      setDescription("");
      setMatterId(target.kind === "new" ? (target.matterId ?? "") : target.matterId);
      setErrors({});
      setFormError(null);
      setProgress(null);
    }
    wasOpen.current = open;
  }, [open, isVersion, target]);

  async function pick(candidate: File | undefined) {
    if (!candidate) return;
    setFormError(null);
    const problem = await precheck(candidate);
    setFile(problem ? null : candidate);
    setFileError(problem);
    if (!problem && !titleTouched && !isVersion) setTitle(titleFromFilename(candidate.name));
  }

  const submit = (event: React.FormEvent) => {
    event.preventDefault();
    void guard(async () => {
      const next: Record<string, string> = {};
      if (!file) next.file = fileError ?? "Choose a PDF file to upload.";
      if (!isVersion) {
        if (!title.trim()) next.title = "Enter a document title.";
        else if (title.trim().length > 255) next.title = "Use at most 255 characters.";
        if (!category) next.category = "Choose a category.";
        if (chooseMatter && !matterId) next.matter = "Choose the matter this document belongs to.";
        if (description.length > 5000) next.description = "Use at most 5,000 characters.";
      }
      setErrors(next);
      if (Object.keys(next).length > 0 || !file) return;

      setFormError(null);
      setProgress(0);
      try {
        if (target.kind === "version") {
          const version = await uploadVersion.mutateAsync({ file, lockVersion: target.document.lock_version, onProgress: setProgress });
          toast.success(`Version ${version.label} uploaded`, target.document.title);
          onOpenChange(false);
          onUploaded?.({ matterId: target.matterId, documentId: target.document.id });
        } else {
          const document = await uploadDocument.mutateAsync({
            input: { file, title: title.trim(), category: category as DocumentCategory, description: description.trim() || null },
            onProgress: setProgress,
          });
          toast.success("Document uploaded", `${document.title} (v1)`);
          onOpenChange(false);
          onUploaded?.({ matterId: document.matter_id, documentId: document.id });
        }
      } catch (error) {
        setProgress(null);
        if (error instanceof ApiError && error.isValidation) {
          const fieldErrors: Record<string, string> = {};
          for (const field of ["file", "title", "category", "description"]) {
            const message = error.field(field);
            if (message) fieldErrors[field] = message;
          }
          setErrors(fieldErrors);
          if (Object.keys(fieldErrors).length === 0) setFormError(error.message);
        } else {
          setFormError(getErrorMessage(error));
        }
      }
    });
  };

  const selectedMatter = target.kind === "version" ? target.document.matter : matters.data?.data.find((m) => m.id === matterId);
  const matterLabel =
    target.kind === "new" && target.matterLabel
      ? target.matterLabel
      : selectedMatter
        ? `${selectedMatter.matter_number} · ${selectedMatter.title}`
        : null;

  return (
    <Dialog open={open} onOpenChange={(next) => (!pending ? onOpenChange(next) : undefined)}>
      <DialogContent
        title={isVersion ? `New version of ${target.document.title}` : "Upload PDF"}
        description={
          isVersion
            ? `The current version (${target.document.current_version?.label ?? "v1"}) is kept; the upload becomes v${target.document.versions_count + 1}.`
            : "Add a PDF to a matter. Files are stored privately and can only be opened by signed-in administrators."
        }
      >
        <form onSubmit={submit} noValidate aria-label={isVersion ? "Upload new version" : "Upload document"}>
          <div className="flex flex-col gap-5 px-5 py-5">
            <FormAlert message={formError} />

            {chooseMatter ? (
              <FormField id="upload-matter" label="Matter" required error={errors.matter}>
                {(aria) => (
                  <Select
                    {...aria}
                    aria-required
                    value={String(matterId)}
                    onChange={(e) => setMatterId(e.target.value ? Number(e.target.value) : "")}
                    disabled={pending || matters.isPending}
                  >
                    <option value="">{matters.isPending ? "Loading matters…" : "Choose an active matter"}</option>
                    {matters.data?.data.map((m) => (
                      <option key={m.id} value={m.id}>
                        {m.matter_number} · {m.title}
                      </option>
                    ))}
                  </Select>
                )}
              </FormField>
            ) : matterLabel ? (
              <p className="rounded-lg border border-border bg-surface-muted/60 px-3.5 py-2.5 text-[13px] text-muted-foreground">
                Uploading to <span className="font-medium text-foreground">{matterLabel}</span>
              </p>
            ) : null}

            <div className="flex flex-col gap-2">
              <span id="upload-file-label" className="text-[13px] leading-none font-medium text-foreground">
                PDF file
                <span aria-hidden className="ml-0.5 text-danger">
                  *
                </span>
              </span>
              <div
                onDragOver={(e) => {
                  e.preventDefault();
                  setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(e) => {
                  e.preventDefault();
                  setDragging(false);
                  if (!pending) void pick(e.dataTransfer.files?.[0]);
                }}
                className={cn(
                  "flex flex-col items-center gap-2 rounded-xl border border-dashed px-4 py-6 text-center transition-colors",
                  dragging ? "border-primary bg-primary-soft/50" : "border-border-strong bg-surface-muted/40",
                  (errors.file || fileError) && "border-danger",
                )}
              >
                {file ? (
                  <div className="flex w-full items-center gap-3 text-left">
                    <FileText aria-hidden className="size-8 shrink-0 text-primary" />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium text-foreground" data-testid="selected-file">
                        {file.name}
                      </p>
                      <p className="text-xs text-muted-foreground">{formatFileSize(file.size)} · PDF</p>
                    </div>
                    {!pending ? (
                      <Button
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        aria-label="Remove selected file"
                        onClick={() => setFile(null)}
                      >
                        <X aria-hidden />
                      </Button>
                    ) : null}
                  </div>
                ) : (
                  <>
                    <FileUp aria-hidden className="size-7 text-subtle-foreground" />
                    <p className="text-sm text-foreground">
                      <span className="hidden sm:inline">Drag a PDF here, or </span>
                      <Button variant="link" className="h-auto p-0 text-sm" onClick={() => inputRef.current?.click()}>
                        choose a file
                      </Button>
                    </p>
                    <p className="text-xs text-subtle-foreground">PDF only · up to {MAX_UPLOAD_MB} MB · not password-protected</p>
                  </>
                )}
                <input
                  ref={inputRef}
                  id="upload-file"
                  type="file"
                  accept="application/pdf,.pdf"
                  className="sr-only"
                  aria-labelledby="upload-file-label"
                  aria-invalid={Boolean(errors.file || fileError)}
                  aria-describedby={errors.file || fileError ? "upload-file-error" : undefined}
                  disabled={pending}
                  onChange={(e) => {
                    void pick(e.target.files?.[0]);
                    e.target.value = "";
                  }}
                />
              </div>
              {errors.file || fileError ? (
                <p id="upload-file-error" className="text-xs font-medium text-danger" role="alert">
                  {errors.file ?? fileError}
                </p>
              ) : null}
            </div>

            {!isVersion ? (
              <>
                <div className="grid gap-5 sm:grid-cols-[1fr_200px]">
                  <FormField id="upload-title" label="Document title" required error={errors.title}>
                    {(aria) => (
                      <Input
                        {...aria}
                        aria-required
                        value={title}
                        maxLength={255}
                        onChange={(e) => {
                          setTitle(e.target.value);
                          setTitleTouched(true);
                        }}
                        placeholder="e.g. Employment Agreement"
                        disabled={pending}
                      />
                    )}
                  </FormField>
                  <FormField id="upload-category" label="Category" required error={errors.category}>
                    {(aria) => (
                      <Select
                        {...aria}
                        aria-required
                        value={category}
                        onChange={(e) => setCategory(e.target.value as DocumentCategory)}
                        disabled={pending}
                      >
                        <option value="">Choose…</option>
                        {DOCUMENT_CATEGORIES.map((c) => (
                          <option key={c} value={c}>
                            {DOCUMENT_CATEGORY_LABELS[c]}
                          </option>
                        ))}
                      </Select>
                    )}
                  </FormField>
                </div>
                <FormField id="upload-description" label="Description" error={errors.description} hint="Optional">
                  {(aria) => (
                    <Textarea
                      {...aria}
                      rows={2}
                      value={description}
                      onChange={(e) => setDescription(e.target.value)}
                      placeholder="What is this document?"
                      disabled={pending}
                    />
                  )}
                </FormField>
              </>
            ) : null}

            {progress !== null ? (
              <div className="flex flex-col gap-1.5" role="status" aria-live="polite">
                <div className="flex justify-between text-xs text-muted-foreground">
                  <span>{progress < 1 ? "Uploading…" : "Verifying and storing…"}</span>
                  <span className="tabular-nums">{Math.round(progress * 100)}%</span>
                </div>
                <div className="h-1.5 overflow-hidden rounded-full bg-surface-muted">
                  <div className="h-full rounded-full bg-primary transition-[width]" style={{ width: `${Math.round(progress * 100)}%` }} />
                </div>
              </div>
            ) : null}
          </div>
          <DialogFooter>
            <DialogClose asChild>
              <Button variant="secondary" disabled={pending}>
                Cancel
              </Button>
            </DialogClose>
            <Button type="submit" disabled={pending}>
              {pending ? <Loader2 aria-hidden className="animate-spin" /> : <CheckCircle2 aria-hidden />}
              {pending ? "Uploading…" : isVersion ? "Upload new version" : "Upload PDF"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
