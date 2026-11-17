"use client";

import { useInfiniteQuery } from "@tanstack/react-query";
import {
  MailCheck,
  MailX,
  Archive,
  Ban,
  Eye,
  FileCheck2,
  FileWarning,
  Inbox,
  Link2,
  Link2Off,
  RefreshCw,
  Send,
  Download,
  FileStack,
  FileUp,
  ArchiveRestore,
  ArrowRightLeft,
  CheckCircle2,
  CircleDot,
  FilePlus2,
  Flag,
  History,
  ListPlus,
  Loader2,
  NotebookPen,
  PenLine,
  PencilLine,
  RotateCcw,
  Trash2,
  type LucideIcon,
} from "lucide-react";

import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { ListSkeleton } from "@/components/shared/loading-skeleton";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api/client";
import { cn } from "@/lib/utils/cn";
import { formatDate, formatTime } from "@/lib/utils/format";
import { MATTER_STATUS_LABELS, PRIORITY_LABELS, REQUEST_STATUS_LABELS, SIGNING_STATUS_LABELS } from "@/lib/utils/labels";
import type { ActivityEvent, DocumentRequestStatus, MatterStatus, Paginated, Priority, SigningStatus } from "@/types/api";

/** Human labels and icons per event type — raw codes are never shown. */
const EVENT_META: Record<string, { label: string; icon: LucideIcon; tone: string }> = {
  "matter.created": { label: "Matter opened", icon: FilePlus2, tone: "text-primary bg-primary-soft" },
  "matter.updated": { label: "Details updated", icon: PencilLine, tone: "text-info bg-info-soft" },
  "matter.status_changed": { label: "Status changed", icon: ArrowRightLeft, tone: "text-primary bg-primary-soft" },
  "matter.priority_changed": { label: "Priority changed", icon: Flag, tone: "text-warning bg-warning-soft" },
  "matter.archived": { label: "Archived", icon: Archive, tone: "text-muted-foreground bg-surface-muted" },
  "matter.restored": { label: "Restored", icon: ArchiveRestore, tone: "text-primary bg-primary-soft" },
  "task.created": { label: "Task added", icon: ListPlus, tone: "text-info bg-info-soft" },
  "task.updated": { label: "Task updated", icon: PencilLine, tone: "text-info bg-info-soft" },
  "task.completed": { label: "Task completed", icon: CheckCircle2, tone: "text-primary bg-primary-soft" },
  "task.reopened": { label: "Task reopened", icon: RotateCcw, tone: "text-warning bg-warning-soft" },
  "task.deleted": { label: "Task deleted", icon: Trash2, tone: "text-danger bg-danger-soft" },
  "note.added": { label: "Internal note added", icon: NotebookPen, tone: "text-muted-foreground bg-surface-muted" },
  "note.updated": { label: "Internal note edited", icon: NotebookPen, tone: "text-muted-foreground bg-surface-muted" },
  "note.deleted": { label: "Internal note deleted", icon: Trash2, tone: "text-danger bg-danger-soft" },
  "document.uploaded": { label: "Document uploaded", icon: FileUp, tone: "text-primary bg-primary-soft" },
  "document.version_added": { label: "New document version added", icon: FileStack, tone: "text-primary bg-primary-soft" },
  "document.updated": { label: "Document details updated", icon: PencilLine, tone: "text-info bg-info-soft" },
  "document.archived": { label: "Document archived", icon: Archive, tone: "text-muted-foreground bg-surface-muted" },
  "document.restored": { label: "Document restored", icon: ArchiveRestore, tone: "text-primary bg-primary-soft" },
  "document.downloaded": { label: "Document downloaded", icon: Download, tone: "text-muted-foreground bg-surface-muted" },
  "document.requested": { label: "Document requested from client", icon: Inbox, tone: "text-info bg-info-soft" },
  "document_request.updated": { label: "Document request updated", icon: PencilLine, tone: "text-info bg-info-soft" },
  "document_request.cancelled": { label: "Document request cancelled", icon: Ban, tone: "text-muted-foreground bg-surface-muted" },
  "link.created": { label: "Upload link generated", icon: Link2, tone: "text-info bg-info-soft" },
  "link.rotated": { label: "Upload link replaced", icon: RefreshCw, tone: "text-info bg-info-soft" },
  "link.revoked": { label: "Upload link revoked", icon: Link2Off, tone: "text-muted-foreground bg-surface-muted" },
  "document.submitted": { label: "Client submitted a document", icon: Send, tone: "text-primary bg-primary-soft" },
  "document.resubmitted": { label: "Client submitted a corrected document", icon: Send, tone: "text-primary bg-primary-soft" },
  "document.review_started": { label: "Review started", icon: Eye, tone: "text-info bg-info-soft" },
  "document.approved": { label: "Document approved", icon: FileCheck2, tone: "text-primary bg-primary-soft" },
  "document.correction_requested": { label: "Correction requested", icon: FileWarning, tone: "text-warning bg-warning-soft" },
  "signing.requested": { label: "Signature requested", icon: PenLine, tone: "text-info bg-info-soft" },
  "signing.link_created": { label: "Signing link generated", icon: Link2, tone: "text-info bg-info-soft" },
  "signing.link_rotated": { label: "Signing link replaced", icon: RefreshCw, tone: "text-info bg-info-soft" },
  "signing.link_revoked": { label: "Signing link revoked", icon: Link2Off, tone: "text-muted-foreground bg-surface-muted" },
  "signing.invitation_shared": { label: "Signing invitation shared", icon: Send, tone: "text-info bg-info-soft" },
  "signing.viewed": { label: "Document viewed for signing", icon: Eye, tone: "text-info bg-info-soft" },
  "signing.started": { label: "Signing started", icon: PenLine, tone: "text-warning bg-warning-soft" },
  "signing.completed": { label: "Document signed", icon: FileCheck2, tone: "text-primary bg-primary-soft" },
  "signing.cancelled": { label: "Signing request cancelled", icon: Ban, tone: "text-muted-foreground bg-surface-muted" },
  "document.signed": { label: "Signed PDF stored", icon: FileStack, tone: "text-primary bg-primary-soft" },
  // Phase 6: e-mail outcomes ("accepted by the mail server" — not inbox delivery).
  "email.accepted": { label: "E-mail accepted by the mail server", icon: MailCheck, tone: "text-primary bg-primary-soft" },
  "email.failed": { label: "E-mail not sent", icon: MailX, tone: "text-danger bg-danger-soft" },
};
const FALLBACK = { label: "Activity", icon: CircleDot, tone: "text-muted-foreground bg-surface-muted" };

function transition(event: ActivityEvent): string | null {
  const { from, to } = event.metadata as { from?: string; to?: string };
  if (!from || !to) return null;
  if (event.event_type === "matter.priority_changed") {
    return `${PRIORITY_LABELS[from as Priority] ?? from} → ${PRIORITY_LABELS[to as Priority] ?? to}`;
  }
  if (event.metadata.signing_request_id !== undefined && from in SIGNING_STATUS_LABELS && to in SIGNING_STATUS_LABELS) {
    return `${SIGNING_STATUS_LABELS[from as SigningStatus]} → ${SIGNING_STATUS_LABELS[to as SigningStatus]}`;
  }
  if (event.metadata.request_id !== undefined && from in REQUEST_STATUS_LABELS && to in REQUEST_STATUS_LABELS) {
    return `${REQUEST_STATUS_LABELS[from as DocumentRequestStatus]} → ${REQUEST_STATUS_LABELS[to as DocumentRequestStatus]}`;
  }
  if (event.event_type.startsWith("matter.")) {
    return `${MATTER_STATUS_LABELS[from as MatterStatus] ?? from} → ${MATTER_STATUS_LABELS[to as MatterStatus] ?? to}`;
  }
  return null;
}

export function ActivityTimeline({ matterId }: { matterId: number }) {
  const history = useInfiniteQuery({
    queryKey: ["matter", matterId, "activity"],
    queryFn: ({ pageParam, signal }) =>
      api.get<Paginated<ActivityEvent>>(`/api/matters/${matterId}/activity`, { page: pageParam, per_page: 20 }, signal),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined),
  });

  const events = history.data?.pages.flatMap((page) => page.data) ?? [];
  const total = history.data?.pages[0]?.meta.total ?? 0;

  return (
    <section aria-labelledby="activity-heading" className="flex flex-col gap-4">
      <div>
        <h2 id="activity-heading" className="text-[15px] font-semibold text-foreground">
          Activity history
        </h2>
        <p className="mt-0.5 text-[13px] text-muted-foreground">
          A dated, permanent record of everything that happened on this matter. Newest first.
        </p>
      </div>

      {history.isPending ? (
        <ListSkeleton rows={4} />
      ) : history.isError ? (
        <ErrorState
          error={history.error}
          title="We couldn't load the history"
          onRetry={() => history.refetch()}
          retrying={history.isRefetching}
        />
      ) : events.length === 0 ? (
        <EmptyState icon={History} title="No activity yet" description="Changes to this matter will appear here." />
      ) : (
        <>
          <ol aria-label="Activity history" className="relative flex flex-col">
            {events.map((event, index) => {
              const meta = EVENT_META[event.event_type] ?? FALLBACK;
              const Icon = meta.icon;
              const change = transition(event);
              const newDay = index === 0 || formatDate(events[index - 1].occurred_at) !== formatDate(event.occurred_at);
              return (
                <li key={event.id} className="relative flex gap-3 pb-5 last:pb-0" data-testid="activity-event">
                  {index < events.length - 1 ? <span aria-hidden className="absolute top-8 bottom-0 left-[15px] w-px bg-border" /> : null}
                  <span
                    aria-hidden
                    className={cn("relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full", meta.tone)}
                  >
                    <Icon className="size-4" />
                  </span>
                  <div className="min-w-0 flex-1 pt-0.5">
                    <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                      <p className="text-sm font-medium text-foreground">{meta.label}</p>
                      <time dateTime={event.occurred_at} className="text-xs whitespace-nowrap text-subtle-foreground">
                        {newDay ? `${formatDate(event.occurred_at)} · ` : ""}
                        {formatTime(event.occurred_at)}
                      </time>
                    </div>
                    <p className="mt-0.5 text-[13px] break-words text-muted-foreground">{event.description}</p>
                    <p className="mt-1 text-xs text-subtle-foreground">
                      {event.actor.label ?? "System"}
                      {event.actor.type === "client" ? <span className="text-subtle-foreground"> · client, via secure link</span> : null}
                      {change ? (
                        <span className="ml-2 rounded bg-surface-muted px-1.5 py-0.5 font-medium text-muted-foreground">{change}</span>
                      ) : null}
                    </p>
                  </div>
                </li>
              );
            })}
          </ol>
          <div className="flex items-center justify-between gap-3 border-t border-border pt-4">
            <p className="text-xs text-subtle-foreground" aria-live="polite">
              Showing {events.length} of {total} events
            </p>
            {history.hasNextPage ? (
              <Button variant="secondary" size="sm" onClick={() => history.fetchNextPage()} disabled={history.isFetchingNextPage}>
                {history.isFetchingNextPage ? <Loader2 aria-hidden className="animate-spin" /> : null}
                Load older activity
              </Button>
            ) : null}
          </div>
        </>
      )}
    </section>
  );
}
