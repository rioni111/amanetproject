"use client";

import {
  AlertCircle,
  CalendarClock,
  CheckCircle2,
  Clock3,
  FileText,
  FileUp,
  Link2Off,
  Loader2,
  LockKeyhole,
  RotateCw,
  ShieldCheck,
  Undo2,
  X,
  type LucideIcon,
} from "lucide-react";
import * as React from "react";

import { BrandMark } from "@/components/shared/brand-mark";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { fetchUploadRequest, isWellFormedToken, PublicApiError, submitUpload, type PublicErrorCode } from "@/lib/api/public-client";
import { cn } from "@/lib/utils/cn";
import { formatDate, formatDateTime, formatFileSize } from "@/lib/utils/format";
import type { PublicSubmissionResult, PublicUploadRequest } from "@/types/api";

/** Reads the token from the URL fragment (#…), which browsers never send to any server. */
function readToken(): string {
  return typeof window === "undefined" ? "" : decodeURIComponent(window.location.hash.replace(/^#/, "")).trim();
}

type State =
  | { kind: "loading" }
  | { kind: "ready"; request: PublicUploadRequest }
  | { kind: "submitted"; result: PublicSubmissionResult; request: PublicUploadRequest }
  | { kind: "blocked"; code: PublicErrorCode; message: string }
  | { kind: "network"; message: string };

const BLOCKED: Record<string, { icon: LucideIcon; title: string }> = {
  link_invalid: { icon: Link2Off, title: "This link is not valid" },
  link_expired: { icon: Clock3, title: "This link has expired" },
  link_used: { icon: CheckCircle2, title: "This link has already been used" },
  request_approved: { icon: CheckCircle2, title: "Nothing more is needed" },
  request_cancelled: { icon: X, title: "This request has been closed" },
  request_unavailable: { icon: LockKeyhole, title: "This request is no longer available" },
  too_many_attempts: { icon: Clock3, title: "Please wait a moment" },
  origin_not_allowed: { icon: Link2Off, title: "This page cannot be used here" },
};

function subscribeToHash(onChange: () => void): () => void {
  window.addEventListener("hashchange", onChange);
  return () => window.removeEventListener("hashchange", onChange);
}

const INCOMPLETE_LINK: State = {
  kind: "blocked",
  code: "link_invalid",
  message: "The link is incomplete or was mistyped. Please open the full link the firm sent you, or ask the firm for a new one.",
};

/**
 * Public, passwordless upload page for one document request. It shows only what the client needs:
 * the request title, instructions, category, due date and (for a correction) the firm's reason.
 * The token is kept in memory only — never in storage, caches, logs or third-party requests.
 */
export function ClientUploadView() {
  // null while prerendering on the server (which never sees the fragment).
  const token = React.useSyncExternalStore(subscribeToHash, readToken, () => null);
  const [attempt, setAttempt] = React.useState(0);
  // The outcome is stored with the token/attempt it belongs to; anything else is still loading.
  const [outcome, setOutcome] = React.useState<{ key: string; state: State } | null>(null);
  const key = `${token}#${attempt}`;
  const wellFormed = token !== null && isWellFormedToken(token);

  React.useEffect(() => {
    if (!wellFormed || token === null) return;
    const controller = new AbortController();
    const settle = (state: State) => setOutcome({ key, state });
    fetchUploadRequest(token, controller.signal)
      .then((request) => settle({ kind: "ready", request }))
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === "AbortError") return;
        if (error instanceof PublicApiError && error.code !== "network" && error.code !== "server") {
          settle({ kind: "blocked", code: error.code, message: error.message });
        } else {
          settle({ kind: "network", message: error instanceof Error ? error.message : "The page could not be loaded." });
        }
      });
    return () => controller.abort();
  }, [token, wellFormed, key]);

  const state: State =
    token === null ? { kind: "loading" } : !wellFormed ? INCOMPLETE_LINK : outcome?.key === key ? outcome.state : { kind: "loading" };
  const setState = (next: State) => setOutcome({ key, state: next });

  return (
    <div className="flex min-h-dvh flex-col bg-background">
      <header className="border-b border-border bg-surface">
        <div className="mx-auto flex max-w-2xl items-center gap-2.5 px-4 py-3.5 sm:px-6">
          <BrandMark className="size-7" />
          <div className="min-w-0 leading-tight">
            <p className="truncate text-sm font-semibold text-foreground">
              {state.kind === "ready" || state.kind === "submitted" ? state.request.firm_name : "Lexora"}
            </p>
            <p className="text-[11px] font-medium tracking-[0.08em] text-subtle-foreground uppercase">Secure document upload</p>
          </div>
        </div>
      </header>

      <main className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-5 px-4 py-6 sm:px-6 sm:py-10">
        {state.kind === "loading" ? (
          <div role="status" className="flex items-center justify-center gap-2 py-24 text-sm text-muted-foreground">
            <Loader2 aria-hidden className="size-4 animate-spin" />
            Checking your secure link…
          </div>
        ) : state.kind === "network" ? (
          <Notice icon={AlertCircle} title="We couldn't load this page" tone="danger">
            <p>{state.message}</p>
            <Button variant="secondary" size="sm" className="mt-4" onClick={() => setAttempt((n) => n + 1)}>
              <RotateCw aria-hidden />
              Try again
            </Button>
          </Notice>
        ) : state.kind === "blocked" ? (
          <Notice icon={BLOCKED[state.code]?.icon ?? Link2Off} title={BLOCKED[state.code]?.title ?? "This link cannot be used"}>
            <p data-testid="public-link-message">{state.message}</p>
          </Notice>
        ) : state.kind === "submitted" ? (
          <Notice icon={CheckCircle2} title="Document submitted" tone="success">
            <p data-testid="upload-success">Your document was submitted successfully. The firm will review it.</p>
            <dl className="mt-4 grid gap-1 text-[13px] text-muted-foreground">
              <div>
                <dt className="inline">File: </dt>
                <dd className="inline font-medium text-foreground">{state.result.original_filename}</dd>
              </div>
              <div>
                <dt className="inline">Size: </dt>
                <dd className="inline">{formatFileSize(state.result.file_size)}</dd>
              </div>
              <div>
                <dt className="inline">Received: </dt>
                <dd className="inline">{formatDateTime(state.result.submitted_at)}</dd>
              </div>
            </dl>
            <p className="mt-4 text-[13px] text-muted-foreground">
              This link has now been used and can be closed. If the firm needs anything else, they will contact you with a new link.
            </p>
          </Notice>
        ) : (
          <UploadForm
            token={token ?? ""}
            request={state.request}
            onSubmitted={(result) => setState({ kind: "submitted", result, request: state.request })}
            onBlocked={(code, message) => setState({ kind: "blocked", code, message })}
          />
        )}
      </main>

      <footer className="border-t border-border bg-surface">
        <p className="mx-auto flex max-w-2xl items-center gap-2 px-4 py-3 text-xs text-subtle-foreground sm:px-6">
          <ShieldCheck aria-hidden className="size-3.5 shrink-0" />
          Your file is sent over this private link directly to the firm. It is not visible to anyone else.
        </p>
      </footer>
    </div>
  );
}

function Notice({
  icon: Icon,
  title,
  tone = "neutral",
  children,
}: {
  icon: LucideIcon;
  title: string;
  tone?: "neutral" | "success" | "danger";
  children: React.ReactNode;
}) {
  return (
    <section
      role={tone === "danger" ? "alert" : "status"}
      aria-labelledby="notice-title"
      className="rounded-xl border border-border bg-surface px-5 py-8 text-center shadow-[var(--shadow-card)] sm:px-8"
    >
      <span
        className={cn(
          "mx-auto flex size-11 items-center justify-center rounded-full",
          tone === "success" ? "bg-primary-soft text-primary" : tone === "danger" ? "bg-danger-soft text-danger" : "bg-surface-muted text-muted-foreground",
        )}
      >
        <Icon aria-hidden className="size-5" />
      </span>
      <h1 id="notice-title" className="mt-4 text-lg font-semibold text-foreground">
        {title}
      </h1>
      <div className="mx-auto mt-2 max-w-md text-sm text-muted-foreground">{children}</div>
    </section>
  );
}

async function precheck(file: File, maxMb: number): Promise<string | null> {
  if (!/\.pdf$/i.test(file.name)) return "Only PDF files (.pdf) can be uploaded.";
  if (file.size === 0) return "The file is empty.";
  if (file.size > maxMb * 1024 * 1024) return `The PDF is larger than the ${maxMb} MB limit.`;
  const head = new TextDecoder("latin1").decode(await file.slice(0, 1024).arrayBuffer());
  if (!head.includes("%PDF-")) return "This file is not a valid PDF.";
  return null;
}

function UploadForm({
  token,
  request,
  onSubmitted,
  onBlocked,
}: {
  token: string;
  request: PublicUploadRequest;
  onSubmitted: (result: PublicSubmissionResult) => void;
  onBlocked: (code: PublicErrorCode, message: string) => void;
}) {
  const [file, setFile] = React.useState<File | null>(null);
  const [fileError, setFileError] = React.useState<string | null>(null);
  const [message, setMessage] = React.useState("");
  const [progress, setProgress] = React.useState<number | null>(null);
  const [formError, setFormError] = React.useState<string | null>(null);
  const [dragging, setDragging] = React.useState(false);
  const busy = React.useRef(false);
  const inputRef = React.useRef<HTMLInputElement>(null);
  const uploading = progress !== null;

  async function pick(candidate: File | undefined) {
    if (!candidate) return;
    setFormError(null);
    const problem = await precheck(candidate, request.max_file_mb);
    setFile(problem ? null : candidate);
    setFileError(problem);
  }

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (busy.current) return; // double click / double submit
    if (!file) {
      setFileError(fileError ?? "Choose the PDF file to upload.");
      return;
    }
    if (message.length > 2000) {
      setFormError("Please keep your message under 2,000 characters.");
      return;
    }
    busy.current = true;
    setFormError(null);
    setProgress(0);
    try {
      const result = await submitUpload(token, file, message.trim() || null, setProgress);
      onSubmitted(result.data);
    } catch (error) {
      setProgress(null);
      if (error instanceof PublicApiError) {
        if (error.code === "validation") {
          const fieldMessage = error.fieldErrors.file?.[0];
          if (fieldMessage) setFileError(fieldMessage);
          setFormError(error.fieldErrors.message?.[0] ?? (fieldMessage ? null : error.message));
        } else if (error.code === "network" || error.code === "server") {
          setFormError(error.message);
        } else {
          onBlocked(error.code, error.message);
        }
      } else {
        setFormError("The upload failed. Please try again.");
      }
    } finally {
      busy.current = false;
    }
  };

  return (
    <>
      <section aria-labelledby="request-title" className="rounded-xl border border-border bg-surface px-5 py-6 shadow-[var(--shadow-card)] sm:px-7">
        <p className="text-xs font-medium tracking-wide text-subtle-foreground uppercase">
          {request.is_correction ? "Corrected document requested" : "Document requested"}
        </p>
        <h1 id="request-title" className="mt-1 text-xl leading-tight font-semibold tracking-[-0.01em] break-words text-foreground">
          {request.title}
        </h1>
        <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-[13px] text-muted-foreground">
          <span className="inline-flex items-center gap-1.5">
            <FileText aria-hidden className="size-3.5" />
            {request.category_label} · PDF
          </span>
          {request.due_date ? (
            <span className={cn("inline-flex items-center gap-1.5", request.is_overdue && "font-medium text-danger")}>
              <CalendarClock aria-hidden className="size-3.5" />
              {request.is_overdue ? "Was due" : "Due"} {formatDate(request.due_date)}
            </span>
          ) : null}
          <span className="inline-flex items-center gap-1.5">
            <Clock3 aria-hidden className="size-3.5" />
            Link valid until {formatDateTime(request.expires_at)}
          </span>
        </div>

        {request.correction ? (
          <div className="mt-5 rounded-lg border border-warning/30 bg-warning-soft px-4 py-3" data-testid="correction-notice">
            <p className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-warning">
              <Undo2 aria-hidden className="size-4" />
              The firm asked for a correction
            </p>
            <p className="mt-1 text-sm whitespace-pre-wrap text-foreground">{request.correction.reason}</p>
            <p className="mt-2 text-xs text-muted-foreground">
              Your new file will replace the version under review. Earlier files are kept on record by the firm.
            </p>
          </div>
        ) : null}

        <div className="mt-5">
          <h2 className="text-[13px] font-semibold text-foreground">Instructions</h2>
          <p className="mt-1 text-sm whitespace-pre-wrap text-foreground" data-testid="request-instructions">
            {request.instructions}
          </p>
        </div>
      </section>

      <form
        onSubmit={(e) => void submit(e)}
        noValidate
        aria-label="Upload your document"
        className="flex flex-col gap-5 rounded-xl border border-border bg-surface px-5 py-6 shadow-[var(--shadow-card)] sm:px-7"
      >
        {formError ? (
          <p role="alert" className="flex items-start gap-2 rounded-lg border border-danger/25 bg-danger-soft px-3.5 py-2.5 text-[13px] text-danger">
            <AlertCircle aria-hidden className="mt-0.5 size-4 shrink-0" />
            {formError}
          </p>
        ) : null}

        <div className="flex flex-col gap-2">
          <span id="client-file-label" className="text-[13px] font-medium text-foreground">
            Your PDF file
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
              if (!uploading) void pick(e.dataTransfer.files?.[0]);
            }}
            className={cn(
              "flex flex-col items-center gap-2 rounded-xl border border-dashed px-4 py-7 text-center transition-colors",
              dragging ? "border-primary bg-primary-soft/50" : "border-border-strong bg-surface-muted/40",
              fileError && "border-danger",
            )}
          >
            {file ? (
              <div className="flex w-full items-center gap-3 text-left">
                <FileText aria-hidden className="size-8 shrink-0 text-primary" />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium text-foreground" data-testid="client-selected-file">
                    {file.name}
                  </p>
                  <p className="text-xs text-muted-foreground">{formatFileSize(file.size)} · PDF</p>
                </div>
                {!uploading ? (
                  <Button variant="ghost" size="icon" className="size-9" aria-label="Remove selected file" onClick={() => setFile(null)}>
                    <X aria-hidden />
                  </Button>
                ) : null}
              </div>
            ) : (
              <>
                <FileUp aria-hidden className="size-7 text-subtle-foreground" />
                <Button variant="secondary" onClick={() => inputRef.current?.click()} disabled={uploading}>
                  Choose PDF file
                </Button>
                <p className="hidden text-xs text-subtle-foreground sm:block">or drag it here</p>
                <p className="text-xs text-subtle-foreground">PDF only · up to {request.max_file_mb} MB · not password-protected</p>
              </>
            )}
            <input
              ref={inputRef}
              id="client-file"
              type="file"
              accept="application/pdf,.pdf"
              className="sr-only"
              aria-labelledby="client-file-label"
              aria-invalid={Boolean(fileError)}
              aria-describedby={fileError ? "client-file-error" : undefined}
              disabled={uploading}
              onChange={(e) => {
                void pick(e.target.files?.[0]);
                e.target.value = "";
              }}
            />
          </div>
          {fileError ? (
            <p id="client-file-error" role="alert" className="text-xs font-medium text-danger">
              {fileError}
            </p>
          ) : null}
        </div>

        <div className="flex flex-col gap-2">
          <label htmlFor="client-message" className="text-[13px] font-medium text-foreground">
            Message to the firm <span className="font-normal text-subtle-foreground">(optional)</span>
          </label>
          <Textarea
            id="client-message"
            rows={3}
            maxLength={2000}
            value={message}
            onChange={(e) => setMessage(e.target.value)}
            placeholder="Anything the firm should know about this file"
            disabled={uploading}
          />
        </div>

        {uploading ? (
          <div className="flex flex-col gap-1.5" role="status" aria-live="polite">
            <div className="flex justify-between text-xs text-muted-foreground">
              <span>{progress < 1 ? "Uploading…" : "Verifying and storing securely…"}</span>
              <span className="tabular-nums">{Math.round(progress * 100)}%</span>
            </div>
            <div className="h-1.5 overflow-hidden rounded-full bg-surface-muted">
              <div className="h-full rounded-full bg-primary transition-[width]" style={{ width: `${Math.round(progress * 100)}%` }} />
            </div>
          </div>
        ) : null}

        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-xs text-subtle-foreground">This link accepts one file. After submitting, the firm reviews it.</p>
          <Button type="submit" size="lg" disabled={uploading} className="w-full sm:w-auto">
            {uploading ? <Loader2 aria-hidden className="animate-spin" /> : <FileUp aria-hidden />}
            {uploading ? "Uploading…" : request.is_correction ? "Submit corrected document" : "Submit document"}
          </Button>
        </div>
      </form>
    </>
  );
}
