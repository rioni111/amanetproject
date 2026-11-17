import { Mail } from "lucide-react";

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import type { DashboardStats } from "@/types/api";

/**
 * Client e-mail (Phase 6). "Accepted" means the configured mail server accepted the message — it is not a
 * delivery receipt. Failures are listed on each request and as notifications.
 */
export function CommunicationsSummary({ stats }: { stats: DashboardStats }) {
  const items = [
    { label: "Sending now", value: stats.emails_sending, testId: "emails-stat-sending" },
    { label: "Accepted by mail server (30 days)", value: stats.emails_accepted_30d, testId: "emails-stat-accepted" },
    { label: "Failed (30 days)", value: stats.emails_failed_30d, testId: "emails-stat-failed" },
  ];
  return (
    <Card data-testid="communications-summary">
      <CardHeader>
        <CardTitle className="inline-flex items-center gap-2">
          <Mail aria-hidden className="size-4 text-primary" />
          Client e-mail
        </CardTitle>
      </CardHeader>
      <CardContent>
        <ul className="grid grid-cols-1 gap-3 sm:grid-cols-3" aria-label="E-mail figures">
          {items.map((item) => (
            <li key={item.label} className="rounded-lg border border-border bg-surface-muted/50 px-3 py-2.5" aria-label={`${item.label}: ${item.value}`}>
              <span className="block text-xs text-muted-foreground">{item.label}</span>
              <span
                className={`mt-0.5 block text-lg font-semibold tabular-nums ${item.testId === "emails-stat-failed" && item.value > 0 ? "text-danger" : "text-foreground"}`}
                data-testid={item.testId}
              >
                {item.value}
              </span>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}
