import type { LucideIcon } from "lucide-react";
import Link from "next/link";
import * as React from "react";

import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils/cn";

interface StatCardProps {
  label: string;
  value: number;
  icon: LucideIcon;
  hint?: React.ReactNode;
  href?: string;
  linkLabel?: string;
}

export function StatCard({ label, value, icon: Icon, hint, href, linkLabel }: StatCardProps) {
  const body = (
    <>
      <div className="flex items-center justify-between">
        <p className="text-[13px] font-medium text-muted-foreground">{label}</p>
        <span className="flex size-8 items-center justify-center rounded-lg border border-border bg-surface-muted">
          <Icon aria-hidden className="size-4 text-primary" />
        </span>
      </div>
      <p className="mt-3 text-[30px] leading-none font-semibold tracking-[-0.02em] text-foreground tabular-nums">
        {value.toLocaleString()}
      </p>
      {hint ? <div className="mt-2.5 text-[13px] text-subtle-foreground">{hint}</div> : null}
    </>
  );

  const className = "block rounded-xl border border-border bg-surface p-5 shadow-[var(--shadow-card)]";

  if (href) {
    return (
      <Link
        href={href}
        aria-label={linkLabel ?? `${label}: ${value}`}
        className={cn(className, "transition-[border-color,box-shadow] hover:border-border-strong hover:shadow-[var(--shadow-raised)]")}
      >
        {body}
      </Link>
    );
  }

  return <div className={className}>{body}</div>;
}

export function StatCardSkeleton() {
  return (
    <div className="rounded-xl border border-border bg-surface p-5 shadow-[var(--shadow-card)]" aria-hidden>
      <div className="flex items-center justify-between">
        <Skeleton className="h-3 w-24" />
        <Skeleton className="size-8 rounded-lg" />
      </div>
      <Skeleton className="mt-3 h-[30px] w-14" />
      <Skeleton className="mt-2.5 h-3 w-32" />
    </div>
  );
}
