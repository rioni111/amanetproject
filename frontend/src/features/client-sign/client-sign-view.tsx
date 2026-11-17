"use client";

import { AlertCircle, CheckCircle2, ChevronLeft, ChevronRight, Clock3, FileText, Link2Off, Loader2, LockKeyhole, PenLine, RotateCw, ShieldCheck, X, type LucideIcon } from "lucide-react";
import type { PDFDocumentLoadingTask, PDFDocumentProxy } from "pdfjs-dist";
import * as React from "react";

import { BrandMark } from "@/components/shared/brand-mark";
import { Button } from "@/components/ui/button";
import { loadPdfJs } from "@/features/documents/pdf-viewer-dialog";
import type { SignaturePayload } from "@/features/signing/api";
import { SignablePage, SigningFlow } from "@/features/signing/signing-flow";
import { completeSigning, fetchSigningDocument, fetchSigningInfo, isWellFormedToken, PublicApiError, recordSigningProgress } from "@/lib/api/public-client";
import { cn } from "@/lib/utils/cn";
import { formatDateTime } from "@/lib/utils/format";
import type { PublicSigningInfo } from "@/types/api";

function readToken(): string {
  return typeof window === "undefined" ? "" : decodeURIComponent(window.location.hash.replace(/^#/, "")).trim();
}

function subscribeToHash(onChange: () => void): () => void {
  window.addEventListener("hashchange", onChange);
  return () => window.removeEventListener("hashchange", onChange);
}

type Loaded = { info: PublicSigningInfo; pdf: PDFDocumentProxy };
type State =
  | { kind: "loading" }
  | { kind: "ready"; loaded: Loaded }
  | { kind: "signed"; title: string; signedAt: string }
  | { kind: "blocked"; code: string; message: string }
  | { kind: "network"; message: string };

const BLOCKED: Record<string, { icon: LucideIcon; title: string }> = {
  link_invalid: { icon: Link2Off, title: "This link is not valid" },
  link_expired: { icon: Clock3, title: "This link has expired" },
  already_signed: { icon: CheckCircle2, title: "Already signed" },
  request_cancelled: { icon: X, title: "This signing request was withdrawn" },
  request_unavailable: { icon: LockKeyhole, title: "This signing request is no longer available" },
  too_many_attempts: { icon: Clock3, title: "Please wait a moment" },
  origin_not_allowed: { icon: Link2Off, title: "This page cannot be used here" },
};

const INCOMPLETE: State = {
  kind: "blocked",
  code: "link_invalid",
  message: "The link is incomplete or was mistyped. Please open the full link the firm sent you, or ask the firm for a new one.",
};

/**
 * Public, passwordless signing page for ONE document. The token lives in the URL fragment and in memory
 * only; the API is called without cookies. "Viewed" is recorded only after the PDF has actually been
 * rendered here, "Started" only when the signer chooses to start.
 */
export function ClientSignView() {
  const token = React.useSyncExternalStore(subscribeToHash, readToken, () => null);
  const [attempt, setAttempt] = React.useState(0);
  const [outcome, setOutcome] = React.useState<{ key: string; state: State } | null>(null);
  const key = `${token}#${attempt}`;
  const wellFormed = token !== null && isWellFormedToken(token);

  React.useEffect(() => {
    if (!wellFormed || token === null) return;
    const controller = new AbortController();
    let task: PDFDocumentLoadingTask | null = null;
    const settle = (state: State) => setOutcome({ key, state });
    (async () => {
      try {
        const [info, bytes, pdfjs] = await Promise.all([
          fetchSigningInfo(token, controller.signal),
          fetchSigningDocument(token, controller.signal),
          loadPdfJs(),
        ]);
        task = pdfjs.getDocument({ data: bytes, standardFontDataUrl: "/pdfjs/standard_fonts/", enableXfa: false });
        settle({ kind: "ready", loaded: { info, pdf: await task.promise } });
      } catch (error) {
        if (error instanceof DOMException && error.name === "AbortError") return;
        if (error instanceof PublicApiError && BLOCKED[error.code]) settle({ kind: "blocked", code: error.code, message: error.message });
        else settle({ kind: "network", message: error instanceof Error ? error.message : "The document could not be loaded." });
      }
    })();
    return () => {
      controller.abort();
      if (task) void task.destroy();
    };
  }, [token, wellFormed, key]);

  const state: State = token === null ? { kind: "loading" } : !wellFormed ? INCOMPLETE : outcome?.key === key ? outcome.state : { kind: "loading" };
  const setState = (next: State) => setOutcome({ key, state: next });

  return (
    <div className="flex min-h-dvh flex-col bg-background">
      <header className="border-b border-border bg-surface">
        <div className="mx-auto flex max-w-6xl items-center gap-2.5 px-4 py-3.5 sm:px-6">
          <BrandMark className="size-7" />
          <div className="min-w-0 leading-tight">
            <p className="truncate text-sm font-semibold text-foreground">{state.kind === "ready" ? state.loaded.info.firm_name : "Lexora"}</p>
            <p className="text-[11px] font-medium tracking-[0.08em] text-subtle-foreground uppercase">Secure document signing</p>
          </div>
        </div>
      </header>
      <main className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-5 px-4 py-6 sm:px-6 sm:py-8">
        {state.kind === "loading" ? (
          <div role="status" className="flex items-center justify-center gap-2 py-24 text-sm text-muted-foreground">
            <Loader2 aria-hidden className="size-4 animate-spin" />
            Opening your document…
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
            <p data-testid="public-sign-message">{state.message}</p>
          </Notice>
        ) : state.kind === "signed" ? (
          <Notice icon={CheckCircle2} title="Document signed" tone="success">
            <p data-testid="sign-success">The document was signed successfully. The firm has received your signed document.</p>
            <p className="mt-3 text-[13px] text-muted-foreground">
              {state.title} · signed {formatDateTime(state.signedAt)}. This link has now been used and can be closed.
            </p>
          </Notice>
        ) : (
          <SigningSession
            token={token!}
            loaded={state.loaded}
            onSigned={(title, signedAt) => setState({ kind: "signed", title, signedAt })}
            onBlocked={(code, message) => setState({ kind: "blocked", code, message })}
          />
        )}
      </main>
      <footer className="border-t border-border bg-surface">
        <p className="mx-auto flex max-w-6xl items-center gap-2 px-4 py-3 text-xs text-subtle-foreground sm:px-6">
          <ShieldCheck aria-hidden className="size-3.5 shrink-0" />
          This private link opens only this document. Your signature is sent directly to the firm.
        </p>
      </footer>
    </div>
  );
}

function SigningSession({
  token,
  loaded,
  onSigned,
  onBlocked,
}: {
  token: string;
  loaded: Loaded;
  onSigned: (title: string, signedAt: string) => void;
  onBlocked: (code: string, message: string) => void;
}) {
  const { info, pdf } = loaded;
  const [phase, setPhase] = React.useState<"review" | "sign">(info.started ? "sign" : "review");
  const [page, setPage] = React.useState(1);
  const [busy, setBusy] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);
  const viewRecorded = React.useRef(info.viewed);
  const submitting = React.useRef(false);

  const handleError = (err: unknown) => {
    if (err instanceof PublicApiError && BLOCKED[err.code] && err.code !== "too_many_attempts") return onBlocked(err.code, err.message);
    if (err instanceof PublicApiError && err.code === "validation") return setError(Object.values(err.fieldErrors)[0]?.[0] ?? err.message);
    setError(err instanceof Error ? err.message : "Something went wrong. Please try again.");
  };

  // "Viewed" = the designated PDF was actually rendered in this browser (not a link preview or prefetch).
  const onRendered = React.useCallback(() => {
    if (viewRecorded.current) return;
    viewRecorded.current = true;
    recordSigningProgress(token, "view").catch(() => {
      viewRecorded.current = false;
    });
  }, [token]);

  const start = async () => {
    setBusy(true);
    setError(null);
    try {
      if (!viewRecorded.current) {
        await recordSigningProgress(token, "view");
        viewRecorded.current = true;
      }
      await recordSigningProgress(token, "start");
      setPhase("sign");
    } catch (err) {
      handleError(err);
    } finally {
      setBusy(false);
    }
  };

  const submit = async (payload: SignaturePayload) => {
    if (submitting.current) return; // double click / double submit
    submitting.current = true;
    setBusy(true);
    setError(null);
    try {
      const result = await completeSigning(token, payload);
      onSigned(result.data.document_title, result.data.signed_at);
    } catch (err) {
      handleError(err);
    } finally {
      submitting.current = false;
      setBusy(false);
    }
  };

  return (
    <>
      <section aria-labelledby="sign-title" className="rounded-xl border border-border bg-surface px-5 py-5 shadow-[var(--shadow-card)] sm:px-7">
        <p className="text-xs font-medium tracking-wide text-subtle-foreground uppercase">Signature requested</p>
        <h1 id="sign-title" className="mt-1 text-xl leading-tight font-semibold tracking-[-0.01em] break-words text-foreground">
          {info.document_title}
        </h1>
        <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-[13px] text-muted-foreground">
          <span className="inline-flex items-center gap-1.5">
            <FileText aria-hidden className="size-3.5" />
            {info.file_name} · {info.version_label} · {pdf.numPages} {pdf.numPages === 1 ? "page" : "pages"}
          </span>
          <span data-testid="designated-signer">
            Signer: <span className="font-medium text-foreground">{info.signer_name}</span>
            {info.signer_email ? ` (${info.signer_email})` : ""}
          </span>
          <span className="inline-flex items-center gap-1.5">
            <Clock3 aria-hidden className="size-3.5" />
            Link valid until {formatDateTime(info.expires_at)}
          </span>
        </div>
        {info.message ? (
          <p className="mt-4 rounded-lg border border-border bg-surface-muted/60 px-4 py-3 text-sm whitespace-pre-wrap text-foreground">{info.message}</p>
        ) : null}
      </section>

      {phase === "review" ? (
        <section aria-label="Review the document" className="flex flex-col gap-3 rounded-xl border border-border bg-surface p-3 shadow-[var(--shadow-card)] sm:p-5">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="text-[15px] font-semibold text-foreground">Review the document</h2>
            <div className="ml-auto flex items-center gap-2">
              <Button variant="secondary" size="icon" className="size-9" aria-label="Previous page" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                <ChevronLeft aria-hidden />
              </Button>
              <span className="min-w-24 text-center text-[13px] text-muted-foreground tabular-nums" aria-live="polite" data-testid="review-page-status">
                Page {page} of {pdf.numPages}
              </span>
              <Button variant="secondary" size="icon" className="size-9" aria-label="Next page" disabled={page >= pdf.numPages} onClick={() => setPage((p) => p + 1)}>
                <ChevronRight aria-hidden />
              </Button>
            </div>
          </div>
          <div className="rounded-lg bg-surface-sunken p-2 sm:p-4">
            <SignablePage key={page} pdf={pdf} number={page} onRendered={onRendered} />
          </div>
          {error ? (
            <p role="alert" className="flex items-start gap-2 rounded-lg border border-danger/25 bg-danger-soft px-3.5 py-2.5 text-[13px] text-danger">
              <AlertCircle aria-hidden className="mt-0.5 size-4 shrink-0" />
              {error}
            </p>
          ) : null}
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-xs text-subtle-foreground">Read the whole document first. Nothing is signed until you confirm on the next step.</p>
            <Button size="lg" onClick={() => void start()} disabled={busy} className="w-full sm:w-auto">
              {busy ? <Loader2 aria-hidden className="animate-spin" /> : <PenLine aria-hidden />}
              Start signing
            </Button>
          </div>
        </section>
      ) : (
        <section aria-label="Sign the document" className={cn("rounded-xl border border-border bg-surface p-3 shadow-[var(--shadow-card)] sm:p-5")}>
          <SigningFlow pdf={pdf} signerName={info.signer_name} mode="client" consent={info.consent} submitting={busy} error={error} onSubmit={(p) => void submit(p)} />
        </section>
      )}
    </>
  );
}

function Notice({ icon: Icon, title, tone = "neutral", children }: { icon: LucideIcon; title: string; tone?: "neutral" | "success" | "danger"; children: React.ReactNode }) {
  return (
    <section
      role={tone === "danger" ? "alert" : "status"}
      aria-labelledby="notice-title"
      className="mx-auto w-full max-w-2xl rounded-xl border border-border bg-surface px-5 py-8 text-center shadow-[var(--shadow-card)] sm:px-8"
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
