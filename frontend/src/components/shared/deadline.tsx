"use client";

import { AlertTriangle } from "lucide-react";

import { cn } from "@/lib/utils/cn";
import { deadlineUrgency, formatDate, relativeDays } from "@/lib/utils/format";

const TONE = {
  overdue: "text-danger",
  imminent: "text-warning",
  soon: "text-foreground",
  later: "text-muted-foreground",
  none: "text-muted-foreground",
} as const;

/** Deadline cell: absolute date + relative hint ("In 3 days", "2 days ago"). */
export function Deadline({
  dueAt: dueAtTimestamp,
  dueDate,
  isActive = true,
  className,
}: {
  dueAt: string | null;
  /** Calendar date from the API (preferred: never shifted by time zones). */
  dueDate?: string | null;
  isActive?: boolean;
  className?: string;
}) {
  const dueAt = dueDate ?? dueAtTimestamp;
  if (!dueAt) return <span className={cn("text-subtle-foreground", className)}>No deadline</span>;

  const urgency = deadlineUrgency(dueAt, isActive);

  return (
    <span className={cn("flex flex-col", className)}>
      <time dateTime={dueAt} className="text-[13px] font-medium whitespace-nowrap text-foreground">
        {formatDate(dueAt)}
      </time>
      {isActive ? (
        <span className={cn("mt-0.5 inline-flex items-center gap-1 text-xs whitespace-nowrap", TONE[urgency])}>
          {urgency === "overdue" ? <AlertTriangle aria-hidden className="size-3" /> : null}
          {urgency === "overdue" ? `Overdue · ${relativeDays(dueAt)}` : relativeDays(dueAt)}
        </span>
      ) : null}
    </span>
  );
}
