"use client";

import { AlertCircle, ChevronLeft, ChevronRight, Loader2, Move, PenLine, ShieldCheck } from "lucide-react";
import type { PDFDocumentProxy, RenderTask } from "pdfjs-dist";
import * as React from "react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils/cn";

import type { SignatureInput, SignaturePayload } from "./api";
import { BOX_RATIO, SIGNATURE_AREA, clampPlacement, defaultPlacement, heightFraction, type PageSize, type Placement } from "./geometry";
import { SignatureInputPanel } from "./signature-input";

/**
 * The signing workspace shared by administrator and client signing: review the real PDF page by page,
 * create a drawn or typed signature, place it (click/tap the page, drag the box, or use the arrow keys),
 * confirm the name (client) and the versioned consent statement, then sign. Nothing here is a signature
 * by itself: the server generates and stores the signed PDF and only then reports success.
 */
export function SigningFlow({
  pdf,
  signerName,
  mode,
  consent,
  submitting,
  error,
  onSubmit,
  caption,
}: {
  pdf: PDFDocumentProxy;
  signerName: string;
  mode: "admin" | "client";
  consent: { version: string; text: string };
  submitting: boolean;
  error: string | null;
  onSubmit: (payload: SignaturePayload) => void;
  /** Shown above the controls, e.g. which exact version is being signed. */
  caption?: React.ReactNode;
}) {
  const [page, setPage] = React.useState(pdf.numPages);
  const [sizes, setSizes] = React.useState<Record<number, PageSize>>({});
  const [placement, setPlacement] = React.useState<Placement | null>(null);
  const [signature, setSignature] = React.useState<SignatureInput | null>(null);
  const [confirmedName, setConfirmedName] = React.useState("");
  const [consented, setConsented] = React.useState(false);
  const [problem, setProblem] = React.useState<string | null>(null);

  const onPageSize = React.useCallback(
    (number: number, size: PageSize) => {
      setSizes((s) => (s[number] ? s : { ...s, [number]: size }));
      setPlacement((current) => current ?? (number === pdf.numPages ? defaultPlacement(number, size) : current));
    },
    [pdf.numPages],
  );

  const placeOnPage = (x: number, y: number) => {
    const size = sizes[page];
    if (!size || submitting) return;
    const width = placement?.width ?? defaultPlacement(page, size).width;
    const h = heightFraction(width, size);
    setPlacement(clampPlacement({ page, width, x: x - width / 2, y: y - h / 2 }, size));
  };

  const submit = () => {
    setProblem(null);
    if (!signature) return setProblem(mode === "client" ? "Draw or type your signature first." : "Draw or type the signature first.");
    if (!placement || !sizes[placement.page]) return setProblem("Choose where the signature goes on the document.");
    if (mode === "client" && confirmedName.trim().length < 2) return setProblem("Confirm your full name.");
    if (!consented) return setProblem("Confirm the signing statement to sign.");
    onSubmit({
      ...signature,
      page: placement.page,
      x: round(placement.x),
      y: round(placement.y),
      width: round(placement.width),
      consent: true,
      consent_version: consent.version,
      ...(mode === "client" ? { confirmed_name: confirmedName.trim() } : {}),
    });
  };

  const size = sizes[page];
  const boxHere = placement && placement.page === page && size;
  const message = problem ?? error;

  return (
    <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
      <section aria-label="Document pages" className="flex min-w-0 flex-col gap-3">
        <div className="flex flex-wrap items-center gap-2" role="toolbar" aria-label="Page navigation">
          <Button variant="secondary" size="icon" className="size-9" aria-label="Previous page" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            <ChevronLeft aria-hidden />
          </Button>
          <span className="min-w-24 text-center text-[13px] text-muted-foreground tabular-nums" aria-live="polite" data-testid="signing-page-status">
            Page {page} of {pdf.numPages}
          </span>
          <Button
            variant="secondary"
            size="icon"
            className="size-9"
            aria-label="Next page"
            disabled={page >= pdf.numPages}
            onClick={() => setPage((p) => p + 1)}
          >
            <ChevronRight aria-hidden />
          </Button>
          {placement && placement.page !== page && size ? (
            <Button variant="secondary" size="sm" className="ml-auto" onClick={() => setPlacement(clampPlacement({ ...placement, page }, size))}>
              <Move aria-hidden />
              Move signature to this page
            </Button>
          ) : (
            <span className="ml-auto text-xs text-subtle-foreground">Click or tap the page to place the signature.</span>
          )}
        </div>
        <div className="overflow-hidden rounded-xl border border-border bg-surface-sunken p-2 sm:p-4">
          <SignablePage
            key={page}
            pdf={pdf}
            number={page}
            onSize={onPageSize}
            onPick={placeOnPage}
            overlay={
              boxHere ? (
                <PlacementBox
                  placement={placement}
                  size={size}
                  signature={signature}
                  signerName={signerName}
                  disabled={submitting}
                  onMove={(next) => setPlacement(clampPlacement(next, size))}
                />
              ) : null
            }
          />
        </div>
        <p className="text-xs text-subtle-foreground" data-testid="placement-summary">
          {placement ? `Signature on page ${placement.page} of ${pdf.numPages}.` : "No position chosen yet."}
        </p>
      </section>

      <section aria-label="Sign" className="flex flex-col gap-5">
        {caption}
        <div className="flex flex-col gap-2">
          <h3 className="text-[13px] font-semibold text-foreground">1. Your signature</h3>
          <SignatureInputPanel signerName={signerName} value={signature} onChange={setSignature} disabled={submitting} />
        </div>
        <div className="flex flex-col gap-2">
          <h3 className="text-[13px] font-semibold text-foreground">2. Position</h3>
          <p className="text-xs text-muted-foreground">
            The box shows exactly where the signature and the line “Electronically signed by …” will appear. Drag it, click the page, or
            focus it and use the arrow keys.
          </p>
          {placement && sizes[placement.page] ? (
            <label className="flex items-center gap-3 text-xs text-muted-foreground">
              Size
              <input
                type="range"
                aria-label="Signature size"
                min={0}
                max={100}
                value={sizeToSlider(placement, sizes[placement.page])}
                disabled={submitting}
                onChange={(e) => setPlacement(clampPlacement(sliderToSize(placement, sizes[placement.page], Number(e.target.value)), sizes[placement.page]))}
                className="flex-1 accent-[var(--color-primary)]"
              />
            </label>
          ) : null}
        </div>
        <div className="flex flex-col gap-3">
          <h3 className="text-[13px] font-semibold text-foreground">3. Confirm</h3>
          {mode === "client" ? (
            <div className="flex flex-col gap-1.5">
              <label htmlFor="confirmed-name" className="text-[13px] font-medium text-foreground">
                Your full name
              </label>
              <Input
                id="confirmed-name"
                autoComplete="name"
                value={confirmedName}
                placeholder={signerName}
                disabled={submitting}
                onChange={(e) => setConfirmedName(e.target.value)}
                aria-describedby="confirmed-name-hint"
              />
              <p id="confirmed-name-hint" className="text-xs text-subtle-foreground">
                This request is addressed to <span className="font-medium text-foreground">{signerName}</span>.
              </p>
            </div>
          ) : (
            <p className="rounded-lg border border-border bg-surface-muted/60 px-3 py-2 text-[13px] text-muted-foreground">
              Signing as <span className="font-medium text-foreground">{signerName}</span> (your signed-in administrator account).
            </p>
          )}
          <label className="flex items-start gap-2.5 rounded-lg border border-border px-3 py-3 text-[13px] text-foreground">
            <input
              type="checkbox"
              className="mt-0.5 size-4 shrink-0 accent-[var(--color-primary)]"
              checked={consented}
              disabled={submitting}
              onChange={(e) => setConsented(e.target.checked)}
              data-testid="consent-checkbox"
            />
            <span>
              {consent.text}
              <span className="mt-1 block text-xs text-subtle-foreground">Statement version {consent.version}</span>
            </span>
          </label>
        </div>

        {message ? (
          <p role="alert" className="flex items-start gap-2 rounded-lg border border-danger/25 bg-danger-soft px-3.5 py-2.5 text-[13px] text-danger">
            <AlertCircle aria-hidden className="mt-0.5 size-4 shrink-0" />
            {message}
          </p>
        ) : null}

        <Button size="lg" onClick={submit} disabled={submitting}>
          {submitting ? <Loader2 aria-hidden className="animate-spin" /> : <PenLine aria-hidden />}
          {submitting ? "Signing — generating the signed PDF…" : "Sign document"}
        </Button>
        <p className="flex items-start gap-2 text-xs text-subtle-foreground">
          <ShieldCheck aria-hidden className="mt-0.5 size-3.5 shrink-0" />
          This is an electronic signature recorded by the firm&apos;s workspace (signature, confirmed name, time and technical details). It is not a
          certificate-based digital signature.
        </p>
      </section>
    </div>
  );
}

function round(value: number): number {
  return Math.round(value * 1_000_000) / 1_000_000;
}

function sizeToSlider(p: Placement, size: PageSize): number {
  const pt = p.width * size.width;
  return Math.round(((pt - 100) / (320 - 100)) * 100);
}

function sliderToSize(p: Placement, size: PageSize, value: number): Placement {
  const pt = 100 + (value / 100) * 220;
  const width = pt / size.width;
  // Keep the box centred while resizing.
  const cx = p.x + p.width / 2;
  const cy = p.y + heightFraction(p.width, size) / 2;
  return { page: p.page, width, x: cx - width / 2, y: cy - heightFraction(width, size) / 2 };
}

/** One page of the source PDF, fit to the available width, with a click/tap target for placement. */
export function SignablePage({
  pdf,
  number,
  onSize,
  onPick,
  overlay,
  onRendered,
}: {
  pdf: PDFDocumentProxy;
  number: number;
  onSize?: (number: number, size: PageSize) => void;
  onPick?: (x: number, y: number) => void;
  overlay?: React.ReactNode;
  onRendered?: () => void;
}) {
  const wrapRef = React.useRef<HTMLDivElement>(null);
  const canvasRef = React.useRef<HTMLCanvasElement>(null);
  const [css, setCss] = React.useState<{ width: number; height: number } | null>(null);
  const [rendered, setRendered] = React.useState(false);
  const [available, setAvailable] = React.useState(0);

  React.useEffect(() => {
    const el = wrapRef.current;
    if (!el) return;
    const observer = new ResizeObserver(([entry]) => setAvailable(Math.floor(entry.contentRect.width)));
    observer.observe(el);
    return () => observer.disconnect();
  }, []);

  React.useEffect(() => {
    if (!available) return;
    let task: RenderTask | null = null;
    let cancelled = false;
    (async () => {
      const page = await pdf.getPage(number);
      const base = page.getViewport({ scale: 1 }); // points, rotation applied
      if (cancelled) return;
      onSize?.(number, { width: base.width, height: base.height });
      const scale = Math.min(available, 900) / base.width;
      const viewport = page.getViewport({ scale });
      setCss({ width: Math.floor(viewport.width), height: Math.floor(viewport.height) });
      const canvas = canvasRef.current;
      if (!canvas) return;
      const ratio = window.devicePixelRatio || 1;
      canvas.width = Math.floor(viewport.width * ratio);
      canvas.height = Math.floor(viewport.height * ratio);
      task = page.render({ canvas, viewport, transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : undefined });
      try {
        await task.promise;
        if (!cancelled) {
          setRendered(true);
          onRendered?.();
        }
      } catch {
        // superseded by a resize
      }
    })();
    return () => {
      cancelled = true;
      task?.cancel();
    };
  }, [pdf, number, available, onSize, onRendered]);

  return (
    <div ref={wrapRef} className="w-full">
      <div
        className="relative mx-auto bg-white shadow-[var(--shadow-raised)]"
        style={css ? { width: css.width, height: css.height } : { width: "100%", aspectRatio: "0.77" }}
        onClick={(e) => {
          if (e.target !== e.currentTarget && e.target !== canvasRef.current) return;
          const rect = e.currentTarget.getBoundingClientRect();
          onPick?.((e.clientX - rect.left) / rect.width, (e.clientY - rect.top) / rect.height);
        }}
        data-testid="signing-page"
      >
        <canvas
          ref={canvasRef}
          role="img"
          aria-label={`Page ${number} of the document`}
          data-rendered={rendered ? "true" : "false"}
          className={cn("block h-full w-full", onPick && "cursor-crosshair")}
        />
        {overlay}
      </div>
    </div>
  );
}

function PlacementBox({
  placement,
  size,
  signature,
  signerName,
  disabled,
  onMove,
}: {
  placement: Placement;
  size: PageSize;
  signature: SignatureInput | null;
  signerName: string;
  disabled?: boolean;
  onMove: (next: Placement) => void;
}) {
  const drag = React.useRef<{ x: number; y: number; start: Placement; w: number; h: number } | null>(null);
  const h = heightFraction(placement.width, size);

  return (
    <button
      type="button"
      data-testid="signature-box"
      aria-label={`Signature position on page ${placement.page}. Use the arrow keys to move it.`}
      disabled={disabled}
      className="absolute touch-none rounded-sm border-2 border-dashed border-primary bg-primary-soft/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
      style={{ left: `${placement.x * 100}%`, top: `${placement.y * 100}%`, width: `${placement.width * 100}%`, height: `${h * 100}%`, cursor: "move" }}
      onPointerDown={(e) => {
        const parent = e.currentTarget.parentElement!.getBoundingClientRect();
        e.currentTarget.setPointerCapture(e.pointerId);
        drag.current = { x: e.clientX, y: e.clientY, start: placement, w: parent.width, h: parent.height };
      }}
      onPointerMove={(e) => {
        const d = drag.current;
        if (!d) return;
        onMove({ ...d.start, x: d.start.x + (e.clientX - d.x) / d.w, y: d.start.y + (e.clientY - d.y) / d.h });
      }}
      onPointerUp={() => (drag.current = null)}
      onPointerCancel={() => (drag.current = null)}
      onKeyDown={(e) => {
        const step = e.shiftKey ? 0.05 : 0.01;
        const moves: Record<string, [number, number]> = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
        const m = moves[e.key];
        if (!m) return;
        e.preventDefault();
        onMove({ ...placement, x: placement.x + m[0], y: placement.y + m[1] });
      }}
    >
      <SignaturePreview signature={signature} signerName={signerName} />
    </button>
  );
}

/** What will be drawn into the PDF: the signature over a line, and the caption below it. */
export function SignaturePreview({ signature, signerName }: { signature: SignatureInput | null; signerName: string }) {
  const area = SIGNATURE_AREA * 100;
  return (
    <span className="pointer-events-none absolute inset-0 block">
      <svg viewBox={`0 0 1000 ${1000 * BOX_RATIO}`} preserveAspectRatio="none" className="absolute inset-0 h-full w-full" aria-hidden>
        {signature?.method === "drawn"
          ? signature.strokes.map((stroke, i) => (
              <polyline
                key={i}
                points={stroke.map(([x, y]) => `${x * 1000},${y * 1000 * BOX_RATIO * SIGNATURE_AREA}`).join(" ")}
                fill="none"
                stroke="#111827"
                strokeWidth={6}
                strokeLinecap="round"
                strokeLinejoin="round"
                vectorEffect="non-scaling-stroke"
              />
            ))
          : null}
        <line x1="0" x2="1000" y1={1000 * BOX_RATIO * SIGNATURE_AREA} y2={1000 * BOX_RATIO * SIGNATURE_AREA} stroke="#6B7280" strokeWidth={1.5} vectorEffect="non-scaling-stroke" />
      </svg>
      {signature?.method === "typed" ? (
        <span
          className="absolute inset-x-0 top-0 flex items-end justify-center overflow-hidden text-foreground"
          style={{ height: `${area}%`, fontFamily: "'Times New Roman', Times, serif", fontStyle: "italic", fontSize: "min(28px, 4cqw)", containerType: "inline-size" }}
        >
          <span className="truncate pb-[3%]" style={{ fontSize: "clamp(10px, 3.2vw, 26px)" }}>
            {signature.typed_name}
          </span>
        </span>
      ) : null}
      {!signature ? (
        <span className="absolute inset-x-0 top-0 flex items-center justify-center text-[11px] text-primary" style={{ height: `${area}%` }}>
          Signature
        </span>
      ) : null}
      <span className="absolute inset-x-1 bottom-0 truncate text-left text-[8px] leading-tight text-muted-foreground sm:text-[9px]" style={{ height: `${100 - area}%` }}>
        Electronically signed by {signerName}
      </span>
    </span>
  );
}
