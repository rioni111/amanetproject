import { STATUS_DOT_CLASSES } from "@/components/shared/status-badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { cn } from "@/lib/utils/cn";
import type { DashboardSummary } from "@/types/api";

/** Single stacked bar showing how matters are distributed across statuses. */
export function StatusBreakdown({ breakdown, total }: { breakdown: DashboardSummary["status_breakdown"]; total: number }) {
  return (
    <Card>
      <CardHeader className="pb-3">
        <div>
          <CardTitle>Matter pipeline</CardTitle>
          <CardDescription>How all {total} matters are distributed by status.</CardDescription>
        </div>
      </CardHeader>
      <CardContent>
        {total > 0 ? (
          <div className="flex h-2.5 w-full overflow-hidden rounded-full bg-surface-sunken" aria-hidden>
            {breakdown
              .filter((item) => item.count > 0)
              .map((item) => (
                <div
                  key={item.status}
                  className={cn("h-full border-r-2 border-surface last:border-r-0", STATUS_DOT_CLASSES[item.status])}
                  style={{ width: `${(item.count / total) * 100}%` }}
                />
              ))}
          </div>
        ) : (
          <div className="h-2.5 w-full rounded-full bg-surface-sunken" aria-hidden />
        )}
        <ul className="mt-4 grid grid-cols-2 gap-x-6 gap-y-2.5 sm:grid-cols-3 lg:grid-cols-5">
          {breakdown.map((item) => (
            <li key={item.status} className="flex items-center gap-2 text-[13px]">
              <span aria-hidden className={cn("size-2 rounded-full", STATUS_DOT_CLASSES[item.status])} />
              <span className="text-muted-foreground">{item.label}</span>
              <span className="ml-auto font-semibold text-foreground tabular-nums sm:ml-0">{item.count}</span>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}
