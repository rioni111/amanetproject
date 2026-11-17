import { ArrowRight, Inbox } from "lucide-react";
import Link from "next/link";

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { cn } from "@/lib/utils/cn";
import type { DashboardStats } from "@/types/api";

/**
 * Outstanding client document requirements across active (non-archived) matters. Each figure links to
 * the request list filtered the same way on the server, so a number and its list always agree.
 */
export function RequestsSummary({ stats }: { stats: DashboardStats }) {
  const items = [
    { label: "Awaiting review", value: stats.requests_awaiting_review, href: "/document-requests?status=awaiting_review", tone: "warning" },
    { label: "Waiting for clients", value: stats.requests_pending, href: "/document-requests?status=pending" },
    { label: "Corrections requested", value: stats.requests_correction, href: "/document-requests?status=correction_requested" },
    { label: "Overdue", value: stats.requests_overdue, href: "/document-requests?overdue=1", tone: "danger" },
    { label: "Approved", value: stats.requests_approved, href: "/document-requests?status=approved" },
  ] as const;

  return (
    <Card data-testid="requests-summary">
      <CardHeader className="flex flex-row items-center justify-between gap-3">
        <div>
          <CardTitle className="inline-flex items-center gap-2">
            <Inbox aria-hidden className="size-4 text-primary" />
            Client document requests
          </CardTitle>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {stats.requests_outstanding === 0
              ? "No outstanding requirements."
              : `${stats.requests_outstanding} outstanding ${stats.requests_outstanding === 1 ? "requirement" : "requirements"}.`}
          </p>
        </div>
        <Link href="/document-requests?status=open" className="inline-flex items-center gap-1 text-[13px] font-medium text-primary hover:underline">
          View all
          <ArrowRight aria-hidden className="size-3.5" />
        </Link>
      </CardHeader>
      <CardContent>
        <ul className="grid grid-cols-2 gap-3 sm:grid-cols-5" aria-label="Request figures">
          {items.map((item) => (
            <li key={item.label}>
              <Link
                href={item.href}
                aria-label={`${item.label}: ${item.value}`}
                className="block rounded-lg border border-border bg-surface-muted/50 px-3 py-2.5 transition-colors hover:border-border-strong"
              >
                <span className="block text-xs text-muted-foreground">{item.label}</span>
                <span
                  className={cn(
                    "mt-0.5 block text-lg font-semibold tabular-nums",
                    "tone" in item && item.tone === "danger" && item.value > 0
                      ? "text-danger"
                      : "tone" in item && item.tone === "warning" && item.value > 0
                        ? "text-warning"
                        : "text-foreground",
                  )}
                  data-testid={`requests-stat-${item.label.toLowerCase().replace(/\s+/g, "-")}`}
                >
                  {item.value}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}
