"use client";

import { CheckCircle2, X, XCircle } from "lucide-react";
import * as React from "react";

import { cn } from "@/lib/utils/cn";

type ToastTone = "success" | "error";
interface Toast {
  id: number;
  tone: ToastTone;
  title: string;
  description?: string;
}

const ToastContext = React.createContext<((toast: Omit<Toast, "id">) => void) | null>(null);

/**
 * Minimal, dependency-free notifications. Success messages are only raised after the API has
 * confirmed a change. Announced politely (errors assertively) to screen readers.
 */
export function ToastProvider({ children }: { children: React.ReactNode }) {
  const [toasts, setToasts] = React.useState<Toast[]>([]);
  const nextId = React.useRef(1);

  const dismiss = React.useCallback((id: number) => setToasts((all) => all.filter((t) => t.id !== id)), []);
  const push = React.useCallback(
    (toast: Omit<Toast, "id">) => {
      const id = nextId.current++;
      setToasts((all) => [...all.slice(-3), { ...toast, id }]);
      window.setTimeout(() => dismiss(id), toast.tone === "error" ? 8000 : 5000);
    },
    [dismiss],
  );

  return (
    <ToastContext.Provider value={push}>
      {children}
      <div className="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-4 sm:items-end">
        <div aria-live="polite" role="status" className="contents">
          {toasts
            .filter((t) => t.tone === "success")
            .map((t) => (
              <ToastItem key={t.id} toast={t} onDismiss={dismiss} />
            ))}
        </div>
        <div aria-live="assertive" role="alert" className="contents">
          {toasts
            .filter((t) => t.tone === "error")
            .map((t) => (
              <ToastItem key={t.id} toast={t} onDismiss={dismiss} />
            ))}
        </div>
      </div>
    </ToastContext.Provider>
  );
}

function ToastItem({ toast, onDismiss }: { toast: Toast; onDismiss: (id: number) => void }) {
  const Icon = toast.tone === "success" ? CheckCircle2 : XCircle;
  return (
    <div
      data-testid="toast"
      className={cn(
        "pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border bg-surface px-4 py-3 shadow-[var(--shadow-raised)]",
        toast.tone === "success" ? "border-primary/20" : "border-danger/25",
      )}
    >
      <Icon aria-hidden className={cn("mt-0.5 size-4 shrink-0", toast.tone === "success" ? "text-primary" : "text-danger")} />
      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium text-foreground">{toast.title}</p>
        {toast.description ? <p className="mt-0.5 text-[13px] text-muted-foreground">{toast.description}</p> : null}
      </div>
      <button
        type="button"
        onClick={() => onDismiss(toast.id)}
        className="-mr-1 inline-flex size-6 shrink-0 cursor-pointer items-center justify-center rounded text-subtle-foreground hover:bg-surface-muted hover:text-foreground"
        aria-label="Dismiss notification"
      >
        <X aria-hidden className="size-3.5" />
      </button>
    </div>
  );
}

export function useToast() {
  const push = React.useContext(ToastContext);
  if (!push) throw new Error("useToast must be used inside <ToastProvider>");
  return React.useMemo(
    () => ({
      success: (title: string, description?: string) => push({ tone: "success", title, description }),
      error: (title: string, description?: string) => push({ tone: "error", title, description }),
    }),
    [push],
  );
}
