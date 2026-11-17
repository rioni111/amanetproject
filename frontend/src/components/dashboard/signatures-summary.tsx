import { ArrowRight, PenLine } from "lucide-react";
import Link from "next/link";

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import type { DashboardStats } from "@/types/api";

/** Signing requests across active (non-archived) matters: awaiting a signature, and completed. */
export function SignaturesSummary({ stats }: { stats: DashboardStats }) {
  const items = [
    { label: "Awaiting signature", value: stats.signatures_pending, href: "/signatures?status=active", testId: "signatures-stat-pending" },
    { label: "Signed", value: stats.signatures_signed, href: "/signatures?status=signed", testId: "signatures-stat-signed" },
  ];
  return (
    <Card data-testid="signatures-summary">
      <CardHeader className="flex flex-row items-center justify-between gap-3">
        <CardTitle className="inline-flex items-center gap-2">
          <PenLine aria-hidden className="size-4 text-primary" />
          Signatures
        </CardTitle>
        <Link href="/signatures" className="inline-flex items-center gap-1 text-[13px] font-medium text-primary hover:underline">
          View all
          <ArrowRight aria-hidden className="size-3.5" />
        </Link>
      </CardHeader>
      <CardContent>
        <ul className="grid grid-cols-2 gap-3" aria-label="Signature figures">
          {items.map((item) => (
            <li key={item.label}>
              <Link
                href={item.href}
                aria-label={`${item.label}: ${item.value}`}
                className="block rounded-lg border border-border bg-surface-muted/50 px-3 py-2.5 transition-colors hover:border-border-strong"
              >
                <span className="block text-xs text-muted-foreground">{item.label}</span>
                <span className="mt-0.5 block text-lg font-semibold text-foreground tabular-nums" data-testid={item.testId}>
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