"use client";

import { AlertCircle, ChevronDown, ChevronUp, Download, Loader2, Maximize2, RotateCw, ZoomIn, ZoomOut } from "lucide-react";
import type { PDFDocumentLoadingTask, PDFDocumentProxy, RenderTask } from "pdfjs-dist";
import * as React from "react";

import { Button } from "@/components/ui/button";
import { Dialog, DialogContent } from "@/components/ui/dialog";
import { apiBlob } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { formatFileSize } from "@/lib/utils/format";
import type { DocumentVersion } from "@/types/api";

import { versionFileUrl } from "./api";

const MIN_SCALE = 0.5;
const MAX_SCALE = 3;

/** PDF.js is loaded only in the browser, and only when a preview is opened. */
async function loadPdfJs() {
  const pdfjs = await import("pdfjs-dist");
  pdfjs.GlobalWorkerOptions.workerSrc = "/pdfjs/pdf.worker.min.mjs";
  return pdfjs;
}

function describeError(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 401) return "Your session has ended. Sign in again to view this document.";
    if (error.status === 404) return "The file for this version could not be found.";
    if (error.status === 409) return error.message;
    return error.message;
  }
  const name = (error as { name?: string })?.name;
  if (name === "PasswordException") return "This PDF is password-protected and cannot be previewed.";
  if (name === "InvalidPDFException") return "This PDF is damaged or not supported, so it cannot be previewed. You can still download it.";
  return "The preview could not be loaded. Check your connection and try again.";
}

/**
 * Secure in-app PDF preview. The file is fetched from the authorised preview endpoint with the
 * session cookie (never a credential in a URL) and rendered by PDF.js onto <canvas> elements only:
 * no annotation/form layer, no PDF scripting, no links or external resources are activated.
 */
export function PdfViewerDialog({
  open,
  onOpenChange,
  matterId,
  documentId,
  title,
  version,
  onDownload,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  matterId: number;
  documentId: number;
  title: string;
  version: DocumentVersion;
  onDownload?: () => void;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="sm:h-[92dvh] sm:max-w-5xl"
        title={`${title} · ${version.label}`}
        description={`${version.original_filename} · ${formatFileSize(version.file_size)}`}
      >
        {open ? <PdfViewer key={version.id} matterId={matterId} documentId={documentId} version={version} onDownload={onDownload} /> : null}
      </DialogContent>
    </Dialog>
  );
}

function PdfViewer({
  matterId,
  documentId,
  version,
  onDownload,
}: {
  matterId: number;
  documentId: number;
  version: DocumentVersion;
  onDownload?: () => void;
}) {
  const [pdf, setPdf] = React.useState<PDFDocumentProxy | null>(null);
  const [error, setError] = React.useState<string | null>(null);
  const [attempt, setAttempt] = React.useState(0);
  const [scale, setScale] = React.useState<number | null>(null);
  const [page, setPage] = React.useState(1);
  const scrollRef = React.useRef<HTMLDivElement>(null);

  React.useEffect(() => {
    const controller = new AbortController();
    let task: PDFDocumentLoadingTask | null = null;
    let cancelled = false;
    (async () => {
      try {
        const [pdfjs, blob] = await Promise.all([
          loadPdfJs(),
          apiBlob(versionFileUrl(matterId, documentId, version.id, "preview"), controller.signal),
        ]);
        const data = new Uint8Array(await blob.arrayBuffer());
        task = pdfjs.getDocument({ data, standardFontDataUrl: "/pdfjs/standard_fonts/", enableXfa: false, stopAtErrors: false });
        const loaded = await task.promise;
        if (cancelled) return;
        // Initial zoom: fit the first page to the available width.
        const first = await loaded.getPage(1);
        const width = (scrollRef.current?.clientWidth ?? 800) - 32;
        setScale(Math.min(MAX_SCALE, Math.max(MIN_SCALE, width / first.getViewport({ scale: 1 }).width)));
        setPdf(loaded);
      } catch (err) {
        if (!cancelled && !(err instanceof DOMException && err.name === "AbortError")) setError(describeError(err));
      }
    })();
    return () => {
      cancelled = true;
      controller.abort();
      if (task) void task.destroy(); // releases the worker and the document's memory
    };
  }, [matterId, documentId, version.id, attempt]);

  const goTo = (target: number) => {
    if (!pdf) return;
    const next = Math.min(pdf.numPages, Math.max(1, target));
    scrollRef.current?.querySelector<HTMLElement>(`[data-page="${next}"]`)?.scrollIntoView({ block: "start" });
    setPage(next);
  };

  const fitWidth = async () => {
    if (!pdf) return;
    const first = await pdf.getPage(page);
    const width = (scrollRef.current?.clientWidth ?? 800) - 32;
    setScale(Math.min(MAX_SCALE, Math.max(MIN_SCALE, width / first.getViewport({ scale: 1 }).width)));
  };

  const zoom = (delta: number) => setScale((s) => Math.min(MAX_SCALE, Math.max(MIN_SCALE, Math.round(((s ?? 1) + delta) * 100) / 100)));

  return (
    <div className="flex h-[78dvh] flex-col sm:h-full">
      <div
        role="toolbar"
        aria-label="PDF viewer controls"
        className="flex flex-wrap items-center gap-1.5 border-b border-border bg-surface-muted/60 px-3 py-2"
      >
        <Button
          variant="ghost"
          size="icon"
          className="size-8"
          aria-label="Previous page"
          disabled={!pdf || page <= 1}
          onClick={() => goTo(page - 1)}
        >
          <ChevronUp aria-hidden />
        </Button>
        <Button
          variant="ghost"
          size="icon"
          className="size-8"
          aria-label="Next page"
          disabled={!pdf || page >= (pdf?.numPages ?? 1)}
          onClick={() => goTo(page + 1)}
        >
          <ChevronDown aria-hidden />
        </Button>
        <span className="min-w-24 text-[13px] text-muted-foreground tabular-nums" aria-live="polite" data-testid="pdf-page-status">
          {pdf ? `Page ${page} of ${pdf.numPages}` : "Loading…"}
        </span>
        <span className="mx-1 h-5 w-px bg-border" aria-hidden />
        <Button
          variant="ghost"
          size="icon"
          className="size-8"
          aria-label="Zoom out"
          disabled={!pdf || (scale ?? 1) <= MIN_SCALE}
          onClick={() => zoom(-0.25)}
        >
          <ZoomOut aria-hidden />
        </Button>
        <span className="w-12 text-center text-[13px] text-muted-foreground tabular-nums" data-testid="pdf-zoom">
          {scale ? `${Math.round(scale * 100)}%` : "—"}
        </span>
        <Button
          variant="ghost"
          size="icon"
          className="size-8"
          aria-label="Zoom in"
          disabled={!pdf || (scale ?? 1) >= MAX_SCALE}
          onClick={() => zoom(0.25)}
        >
          <ZoomIn aria-hidden />
        </Button>
        <Button variant="ghost" size="icon" className="size-8" aria-label="Fit to width" disabled={!pdf} onClick={() => void fitWidth()}>
          <Maximize2 aria-hidden />
        </Button>
        {onDownload ? (
          <Button variant="secondary" size="sm" className="ml-auto" onClick={onDownload}>
            <Download aria-hidden />
            Download {version.label}
          </Button>
        ) : null}
      </div>

      <div ref={scrollRef} className="min-h-0 flex-1 overflow-auto bg-surface-sunken px-4 py-4" data-testid="pdf-scroll">
        {error ? (
          <div role="alert" className="mx-auto flex max-w-md flex-col items-center gap-3 py-16 text-center">
            <AlertCircle aria-hidden className="size-6 text-danger" />
            <p className="text-sm text-foreground">{error}</p>
            <Button
              variant="secondary"
              size="sm"
              onClick={() => {
                setError(null);
                setPdf(null);
                setAttempt((n) => n + 1);
              }}
            >
              <RotateCw aria-hidden />
              Try again
            </Button>
          </div>
        ) : !pdf || scale === null ? (
          <div role="status" className="flex items-center justify-center gap-2 py-24 text-sm text-muted-foreground">
            <Loader2 aria-hidden className="size-4 animate-spin" />
            Loading preview…
          </div>
        ) : (
          <div
            role="document"
            aria-label={`PDF preview, ${pdf.numPages} ${pdf.numPages === 1 ? "page" : "pages"}`}
            className="flex flex-col items-center gap-4"
          >
            {Array.from({ length: pdf.numPages }, (_, i) => (
              <PdfPage key={i + 1} pdf={pdf} number={i + 1} total={pdf.numPages} scale={scale} root={scrollRef} onVisible={setPage} />
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

/** Renders one page when it approaches the viewport (lazy), re-renders on zoom, cancels stale renders. */
function PdfPage({
  pdf,
  number,
  total,
  scale,
  root,
  onVisible,
}: {
  pdf: PDFDocumentProxy;
  number: number;
  total: number;
  scale: number;
  root: React.RefObject<HTMLDivElement | null>;
  onVisible: (page: number) => void;
}) {
  const wrapperRef = React.useRef<HTMLDivElement>(null);
  const canvasRef = React.useRef<HTMLCanvasElement>(null);
  const [near, setNear] = React.useState(number === 1);
  const [size, setSize] = React.useState<{ width: number; height: number } | null>(null);
  const [rendered, setRendered] = React.useState(false);

  React.useEffect(() => {
    const element = wrapperRef.current;
    if (!element) return;
    const lazy = new IntersectionObserver(([entry]) => entry.isIntersecting && setNear(true), {
      root: root.current,
      rootMargin: "150% 0px",
    });
    const current = new IntersectionObserver(([entry]) => entry.isIntersecting && onVisible(number), {
      root: root.current,
      threshold: 0.5,
    });
    lazy.observe(element);
    current.observe(element);
    return () => {
      lazy.disconnect();
      current.disconnect();
    };
  }, [root, number, onVisible]);

  React.useEffect(() => {
    let task: RenderTask | null = null;
    let cancelled = false;
    (async () => {
      const page = await pdf.getPage(number);
      const viewport = page.getViewport({ scale });
      if (cancelled) return;
      setSize({ width: Math.floor(viewport.width), height: Math.floor(viewport.height) });
      const canvas = canvasRef.current;
      if (!near || !canvas) return;
      const ratio = window.devicePixelRatio || 1;
      canvas.width = Math.floor(viewport.width * ratio);
      canvas.height = Math.floor(viewport.height * ratio);
      task = page.render({ canvas, viewport, transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : undefined });
      try {
        await task.promise;
        if (!cancelled) setRendered(true);
      } catch {
        // Cancelled by a newer zoom level or unmount.
      }
    })();
    return () => {
      cancelled = true;
      task?.cancel();
    };
  }, [pdf, number, scale, near]);

  return (
    <div ref={wrapperRef} data-page={number} className="max-w-full scroll-mt-4">
      <canvas
        ref={canvasRef}
        role="img"
        aria-label={`Page ${number} of ${total}`}
        data-rendered={rendered ? "true" : "false"}
        className="block max-w-none bg-white shadow-[var(--shadow-raised)]"
        style={size ? { width: size.width, height: size.height } : { width: 600, height: 800 }}
      />
    </div>
  );
}
