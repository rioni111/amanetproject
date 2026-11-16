import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils/cn";

/** Table placeholder that matches the real table's row height to avoid layout shift. */
export function TableSkeleton({ rows = 6, columns = 5, className }: { rows?: number; columns?: number; className?: string }) {
  return (
    <div className={cn("w-full", className)} role="status" aria-label="Loading">
      <div className="flex h-10 items-center gap-6 border-b border-border bg-surface-muted/70 px-5">
        {Array.from({ length: columns }).map((_, i) => (
          <Skeleton key={i} className="h-2.5 flex-1" />
        ))}
      </div>
      {Array.from({ length: rows }).map((_, r) => (
        <div key={r} className="flex h-[61px] items-center gap-6 border-b border-border px-5 last:border-0">
          {Array.from({ length: columns }).map((_, c) => (
            <div key={c} className="flex flex-1 flex-col gap-2">
              <Skeleton className={cn("h-3", c === 0 ? "w-4/5" : "w-3/5")} />
              {c === 0 ? <Skeleton className="h-2.5 w-2/5" /> : null}
            </div>
          ))}
        </div>
      ))}
      <span className="sr-only">Loading…</span>
    </div>
  );
}

export function ListSkeleton({ rows = 4 }: { rows?: number }) {
  return (
    <div role="status" aria-label="Loading" className="divide-y divide-border">
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="flex items-center gap-4 py-3.5">
          <Skeleton className="size-11 rounded-lg" />
          <div className="flex flex-1 flex-col gap-2">
            <Skeleton className="h-3 w-3/4" />
            <Skeleton className="h-2.5 w-1/2" />
          </div>
        </div>
      ))}
      <span className="sr-only">Loading…</span>
    </div>
  );
}

export function FullPageLoader({ label = "Loading your workspace" }: { label?: string }) {
  return (
    <div className="flex min-h-dvh items-center justify-center bg-background" role="status" aria-live="polite">
      <div className="flex flex-col items-center gap-4">
        <span aria-hidden className="size-7 animate-spin rounded-full border-2 border-border-strong border-t-primary" />
        <p className="text-sm text-muted-foreground">{label}…</p>
      </div>
    </div>
  );
}
