import { AlertTriangle, CalendarClock, ChevronRight, Link2 } from "lucide-react";
import Link from "next/link";

import { formatDate, formatDateTime } from "@/lib/utils/format";
import type { DocumentRequest } from "@/types/api";

import { requestHref } from "./api";
import { LinkStateBadge, RequestStatusBadge } from "./request-badges";

/**
 * Responsive list of document requests (cards stack on phones). Each row links to the request's
 * review page; nothing here exposes a link URL — only its state.
 */
export function RequestList({ requests, showMatter = false, label }: { requests: DocumentRequest[]; showMatter?: boolean; label: string }) {
  return (
    <ul aria-label={label} className="divide-y divide-border">
      {requests.map((request) => (
        <li key={request.id} data-testid="request-row" className="relative flex items-start gap-3 px-4 py-3.5 hover:bg-surface-muted/50 sm:px-5">
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
              <Link
                href={requestHref(request)}
                className="text-sm font-medium break-words text-foreground after:absolute after:inset-0 hover:text-primary"
              >
                {request.title}
              </Link>
              <RequestStatusBadge status={request.status} />
              {request.is_overdue ? (
                <span className="inline-flex items-center gap-1 text-xs font-medium text-danger">
                  <AlertTriangle aria-hidden className="size-3.5" />
                  Overdue
                </span>
              ) : null}
            </div>
            <p className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground">
              {showMatter && request.matter ? (
                <span>
                  <span className="font-mono">{request.matter.matter_number}</span> · {request.matter.title}
                </span>
              ) : null}
              {request.client ? <span>{request.client.name}</span> : null}
              <span>{request.requested_category_label}</span>
              {request.due_date ? (
                <span className="inline-flex items-center gap-1">
                  <CalendarClock aria-hidden className="size-3.5" />
                  Due {formatDate(request.due_date)}
                </span>
              ) : null}
              {request.last_submitted_at ? <span>Last submission {formatDateTime(request.last_submitted_at)}</span> : null}
              {request.latest_submission?.version ? <span>{request.latest_submission.version.label}</span> : null}
            </p>
          </div>
          <div className="flex shrink-0 items-center gap-2">
            {request.active_link ? (
              <span className="hidden items-center gap-1.5 sm:inline-flex">
                <Link2 aria-hidden className="size-3.5 text-subtle-foreground" />
                <span className="sr-only">Upload link:</span>
                <LinkStateBadge state={request.active_link.state} />
              </span>
            ) : null}
            <ChevronRight aria-hidden className="size-4 text-subtle-foreground" />
          </div>
        </li>
      ))}
    </ul>
  );
}
