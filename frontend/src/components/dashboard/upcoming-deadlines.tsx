import { AlertTriangle, CalendarCheck2 } from "lucide-react";
import Link from "next/link";

import { EmptyState } from "@/components/shared/empty-state";
import { PriorityBadge } from "@/components/shared/priority-badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { cn } from "@/lib/utils/cn";
import { deadlineUrgency, formatDay, formatMonth, relativeDays } from "@/lib/utils/format";
import type { Matter } from "@/types/api";

const URGENCY_CHIP = {
  overdue: "border-danger/20 bg-danger-soft text-danger",
  imminent: "border-warning/20 bg-warning-soft text-warning",
  soon: "border-info/15 bg-info-soft text-info",
  later: "border-border bg-surface-muted text-muted-foreground",
  none: "border-border bg-surface-muted text-muted-foreground",
} as const;

interface UpcomingDeadlinesProps {
  matters: Matter[];
  windowDays: number;
  overdueCount: number;
}

export function UpcomingDeadlines({ matters, windowDays, overdueCount }: UpcomingDeadlinesProps) {
  return (
    <Card className="flex flex-col">
      <CardHeader>
        <div>
          <CardTitle>Upcoming deadlines</CardTitle>
          <CardDescription>Active matters due in the next {windowDays} days.</CardDescription>
        </div>
      </CardHeader>
      <CardContent className="flex flex-1 flex-col">
        {overdueCount > 0 ? (
          <Link
            href="/matters?status=active&sort=due_at"
            className="mb-3 flex items-center gap-2.5 rounded-lg border border-danger/20 bg-danger-soft px-3 py-2.5 text-[13px] font-medium text-danger hover:underline"
          >
            <AlertTriangle aria-hidden className="size-4 shrink-0" />
            {overdueCount === 1 ? "1 matter is past its deadline" : `${overdueCount} matters are past their deadline`}
          </Link>
        ) : null}

        {matters.length === 0 ? (
          <EmptyState
            icon={CalendarCheck2}
            title="Nothing due soon"
            description={`No active matter has a deadline in the next ${windowDays} days.`}
            className="flex-1 py-10"
          />
        ) : (
          <ol className="divide-y divide-border">
            {matters.map((matter) => {
              const urgency = deadlineUrgency(matter.due_at, matter.is_active);
              return (
                <li key={matter.id} className="flex items-center gap-4 py-3.5 first:pt-1 last:pb-0">
                  <time
                    dateTime={matter.due_at ?? undefined}
                    className="flex size-11 shrink-0 flex-col items-center justify-center rounded-lg border border-border bg-surface-muted leading-none"
                  >
                    <span className="text-[10px] font-semibold tracking-wider text-subtle-foreground uppercase">
                      {formatMonth(matter.due_at!)}
                    </span>
                    <span className="mt-1 text-base font-semibold text-foreground tabular-nums">{formatDay(matter.due_at!)}</span>
                  </time>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium text-foreground" title={matter.title}>
                      {matter.title}
                    </p>
                    <p className="mt-0.5 truncate text-xs text-muted-foreground">
                      {matter.client?.name} · <span className="font-mono">{matter.matter_number}</span>
                    </p>
                  </div>
                  <div className="flex shrink-0 flex-col items-end gap-1.5">
                    <span
                      className={cn("rounded-full border px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap", URGENCY_CHIP[urgency])}
                    >
                      {relativeDays(matter.due_at!)}
                    </span>
                    <PriorityBadge priority={matter.priority} className="text-xs" />
                  </div>
                </li>
              );
            })}
          </ol>
        )}
      </CardContent>
    </Card>
  );
}
