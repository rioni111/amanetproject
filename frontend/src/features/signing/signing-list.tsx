import { ChevronRight, Clock3 } from "lucide-react";
import Link from "next/link";

import { LinkStateBadge } from "@/features/requests/request-badges";
import { formatDateTime } from "@/lib/utils/format";
import type { SigningRequest } from "@/types/api";

import { signingHref } from "./api";
import { SigningStatusBadge } from "./signing-badges";

function latestProgress(r: SigningRequest): string {
  if (r.signed_at) return `Signed ${formatDateTime(r.signed_at)}`;
  if (r.cancelled_at) return `Cancelled ${formatDateTime(r.cancelled_at)}`;
  if (r.started_at) return `Started ${formatDateTime(r.started_at)}`;
  if (r.viewed_at) return `Viewed ${formatDateTime(r.viewed_at)}`;
  if (r.sent_at) return `Sent ${formatDateTime(r.sent_at)}`;
  return `Created ${formatDateTime(r.created_at)}`;
}

/** Responsive list of signing requests; each row opens the request (progress, link, evidence). */
export function SigningList({ requests, label, showMatter = false }: { requests: SigningRequest[]; label: string; showMatter?: boolean }) {
  return (
    <ul aria-label={label} className="divide-y divide-border">
      {requests.map((r) => (
        <li key={r.id} data-testid="signing-row" className="relative flex items-start gap-3 px-4 py-3.5 hover:bg-surface-muted/50 sm:px-5">
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
              <Link href={signingHref(r)} className="text-sm font-medium break-words text-foreground after:absolute after:inset-0 hover:text-primary">
                {r.document?.title ?? "Document"} {r.source_version ? `· ${r.source_version.label}` : ""}
              </Link>
              <SigningStatusBadge status={r.status} />
            </div>
            <p className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground">
              {showMatter && r.matter ? (
                <span>
                  <span className="font-mono">{r.matter.matter_number}</span> · {r.matter.title}
                </span>
              ) : null}
              <span>
                {r.signer_name} ({r.signer_type_label.toLowerCase()})
              </span>
              <span>{latestProgress(r)}</span>
              {r.active_link ? (
                <span className="inline-flex items-center gap-1">
                  <Clock3 aria-hidden className="size-3.5" />
                  Link until {formatDateTime(r.active_link.expires_at)}
                </span>
              ) : null}
            </p>
          </div>
          <div className="flex shrink-0 items-center gap-2">
            {r.active_link ? (
              <span className="hidden sm:inline-flex">
                <LinkStateBadge state={r.active_link.state} />
              </span>
            ) : null}
            <ChevronRight aria-hidden className="size-4 text-subtle-foreground" />
          </div>
        </li>
      ))}
    </ul>
  );
}
