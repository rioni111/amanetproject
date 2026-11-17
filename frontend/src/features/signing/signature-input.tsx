"use client";

import { Eraser, PenLine, Type } from "lucide-react";
import * as React from "react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils/cn";

import type { SignatureInput } from "./api";
import { PAD_ASPECT } from "./geometry";

type Stroke = [number, number][];

const MAX_POINTS = 3500;

/**
 * Signature capture: draw (mouse, touch or stylus — Pointer Events) or type the full name (the keyboard
 * and screen-reader friendly alternative). Drawn signatures are kept as vector strokes normalised to the
 * pad (0..1), which is exactly what the server draws into the PDF — never an image, SVG or HTML.
 */
export function SignatureInputPanel({
  signerName,
  value,
  onChange,
  disabled,
}: {
  signerName: string;
  value: SignatureInput | null;
  onChange: (value: SignatureInput | null) => void;
  disabled?: boolean;
}) {
  const [method, setMethod] = React.useState<"drawn" | "typed">(value?.method ?? "drawn");
  const radios = React.useRef<Partial<Record<"drawn" | "typed", HTMLButtonElement | null>>>({});
  const choose = (id: "drawn" | "typed") => {
    if (id === method) return;
    setMethod(id);
    onChange(null);
  };

  return (
    <div className="flex flex-col gap-3">
      {/* WAI-ARIA radio group: one Tab stop (the checked option); arrow keys move and select. */}
      <div role="radiogroup" aria-label="Signature method" className="inline-flex w-fit rounded-lg border border-border bg-surface-muted p-0.5">
        {(
          [
            ["drawn", "Draw", PenLine],
            ["typed", "Type", Type],
          ] as const
        ).map(([id, label, Icon]) => (
          <button
            key={id}
            ref={(el) => {
              radios.current[id] = el;
            }}
            type="button"
            role="radio"
            aria-checked={method === id}
            tabIndex={method === id ? 0 : -1}
            disabled={disabled}
            onClick={() => choose(id)}
            onKeyDown={(e) => {
              if (!["ArrowRight", "ArrowDown", "ArrowLeft", "ArrowUp"].includes(e.key)) return;
              e.preventDefault();
              const next = id === "drawn" ? "typed" : "drawn"; // two options: every arrow moves to the other one
              choose(next);
              radios.current[next]?.focus();
            }}
            className={cn(
              "inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-[13px] font-medium transition-colors",
              method === id ? "bg-surface text-foreground shadow-sm" : "text-muted-foreground hover:text-foreground",
            )}
          >
            <Icon aria-hidden className="size-3.5" />
            {label}
          </button>
        ))}
      </div>
      {method === "drawn" ? (
        <SignaturePad onChange={onChange} disabled={disabled} />
      ) : (
        <TypedSignature signerName={signerName} onChange={onChange} disabled={disabled} />
      )}
    </div>
  );
}

function SignaturePad({ onChange, disabled }: { onChange: (value: SignatureInput | null) => void; disabled?: boolean }) {
  const canvasRef = React.useRef<HTMLCanvasElement>(null);
  const strokes = React.useRef<Stroke[]>([]);
  const drawing = React.useRef(false);
  const [empty, setEmpty] = React.useState(true);

  const redraw = React.useCallback(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const ratio = window.devicePixelRatio || 1;
    const { width, height } = canvas.getBoundingClientRect();
    canvas.width = Math.round(width * ratio);
    canvas.height = Math.round(height * ratio);
    const ctx = canvas.getContext("2d");
    if (!ctx) return;
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.clearRect(0, 0, width, height);
    ctx.lineCap = "round";
    ctx.lineJoin = "round";
    ctx.strokeStyle = "#111827";
    ctx.lineWidth = Math.max(1.6, width * 0.006);
    for (const stroke of strokes.current) {
      ctx.beginPath();
      stroke.forEach(([x, y], i) => (i === 0 ? ctx.moveTo(x * width, y * height) : ctx.lineTo(x * width, y * height)));
      ctx.stroke();
    }
  }, []);

  React.useEffect(() => {
    redraw();
    const observer = new ResizeObserver(() => redraw());
    if (canvasRef.current) observer.observe(canvasRef.current);
    return () => observer.disconnect();
  }, [redraw]);

  const point = (event: React.PointerEvent<HTMLCanvasElement>): [number, number] => {
    const rect = event.currentTarget.getBoundingClientRect();
    const x = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
    const y = Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height));
    return [Math.round(x * 10000) / 10000, Math.round(y * 10000) / 10000];
  };

  const total = () => strokes.current.reduce((n, s) => n + s.length, 0);

  const emit = () => {
    const usable = strokes.current.filter((s) => s.length >= 2);
    setEmpty(usable.length === 0);
    onChange(usable.length > 0 ? { method: "drawn", strokes: usable, typed_name: null } : null);
  };

  return (
    <div className="flex flex-col gap-2">
      <div className="relative overflow-hidden rounded-lg border border-border-strong bg-white">
        <canvas
          ref={canvasRef}
          data-testid="signature-pad"
          aria-label="Signature drawing area. Draw your signature with a mouse, finger or stylus, or switch to Type."
          role="img"
          className={cn("block w-full touch-none select-none", disabled ? "cursor-not-allowed opacity-60" : "cursor-crosshair")}
          style={{ aspectRatio: String(PAD_ASPECT) }}
          onPointerDown={(e) => {
            if (disabled || strokes.current.length >= 60 || total() >= MAX_POINTS) return;
            e.currentTarget.setPointerCapture(e.pointerId);
            drawing.current = true;
            strokes.current.push([point(e)]);
            redraw();
          }}
          onPointerMove={(e) => {
            if (!drawing.current) return;
            const stroke = strokes.current[strokes.current.length - 1];
            const next = point(e);
            const last = stroke[stroke.length - 1];
            if (Math.hypot(next[0] - last[0], (next[1] - last[1]) / PAD_ASPECT) < 0.003 || stroke.length >= 500 || total() >= MAX_POINTS) return;
            stroke.push(next);
            redraw();
          }}
          onPointerUp={() => {
            if (!drawing.current) return;
            drawing.current = false;
            emit();
          }}
          onPointerCancel={() => {
            drawing.current = false;
            emit();
          }}
        />
        <span aria-hidden className="pointer-events-none absolute right-4 bottom-[22%] left-4 border-b border-dashed border-border-strong" />
        {empty ? (
          <span aria-hidden className="pointer-events-none absolute inset-0 flex items-center justify-center text-[13px] text-subtle-foreground">
            Sign here
          </span>
        ) : null}
      </div>
      <div className="flex items-center justify-between gap-2">
        <p className="text-xs text-subtle-foreground">Use a mouse, your finger or a stylus. Prefer the keyboard? Choose “Type”.</p>
        <Button
          variant="ghost"
          size="sm"
          disabled={disabled || empty}
          onClick={() => {
            strokes.current = [];
            redraw();
            emit();
          }}
        >
          <Eraser aria-hidden />
          Clear
        </Button>
      </div>
    </div>
  );
}

function TypedSignature({ signerName, onChange, disabled }: { signerName: string; onChange: (value: SignatureInput | null) => void; disabled?: boolean }) {
  const [name, setName] = React.useState("");
  return (
    <div className="flex flex-col gap-2">
      <label htmlFor="typed-signature" className="text-[13px] font-medium text-foreground">
        Type your full name
      </label>
      <Input
        id="typed-signature"
        value={name}
        maxLength={80}
        autoComplete="name"
        placeholder={signerName}
        disabled={disabled}
        onChange={(e) => {
          setName(e.target.value);
          const trimmed = e.target.value.trim();
          onChange(trimmed.length >= 2 ? { method: "typed", strokes: null, typed_name: trimmed } : null);
        }}
      />
      <div
        aria-hidden
        className="flex items-center justify-center rounded-lg border border-border-strong bg-white px-4 text-foreground"
        style={{ aspectRatio: String(PAD_ASPECT), fontFamily: "'Times New Roman', Times, serif", fontStyle: "italic", fontSize: "clamp(22px, 5vw, 40px)" }}
      >
        {name.trim() || <span className="text-base not-italic text-subtle-foreground">Your typed signature</span>}
      </div>
      <p className="text-xs text-subtle-foreground">The typed name must match the designated signer: {signerName}.</p>
    </div>
  );
}
