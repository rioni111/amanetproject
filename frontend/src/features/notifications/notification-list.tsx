"use client";

import { AlertTriangle, Bell, CheckCircle2, Clock, FileUp, FileWarning, MailX, PenLine, type LucideIcon } from "lucide-react";
import Link from "next/link";

import { cn } from "@/lib/utils/cn";
import { formatDateTime } from "@/lib/utils/format";
import type { NotificationKind, WorkspaceNotification } from "@/types/api";

const ICONS: Record<NotificationKind, { icon: LucideIcon; tone: string }> = {
  document_submitted: { icon: FileUp, tone: "text-info bg-info-soft" },
  correction_submitted: { icon: FileWarning, tone: "text-info bg-info-soft" },
  document_signed: { icon: PenLine, tone: "text-primary bg-primary-soft" },
  request_overdue: { icon: Clock, tone: "text-warning bg-warning-soft" },
  signing_link_expired: { icon: AlertTriangle, tone: "text-warning bg-warning-soft" },
  email_failed: { icon: MailX, tone: "text-danger bg-danger-soft" },
};

/**
 * One list for the header panel and the full page. Opening an item marks it read (persisted) and follows its
 * internal link; "Mark as read" does the same without leaving.
 */
export function NotificationList({
  items,
  onOpen,
  onMarkRead,
  compact = false,
}: {
  items: WorkspaceNotification[];
  onOpen: (n: WorkspaceNotification) => void;
  onMarkRead: (n: WorkspaceNotification) => void;
  compact?: boolean;
}) {
  return (
    <ul className="flex flex-col divide-y divide-border" aria-label="Notifications">
      {items.map((n) => {
        const { icon: Icon, tone } = ICONS[n.kind] ?? { icon: Bell, tone: "text-muted-foreground bg-surface-muted" };
        const unread = n.read_at === null;
        return (
          <li key={n.id} className={cn("flex gap-3 py-3", compact ? "px-3" : "px-1")} data-testid="notification-item" data-unread={unread}>
            <span aria-hidden className={cn("mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full", tone)}>
              <Icon className="size-4" />
            </span>
            <div className="min-w-0 flex-1">
              <Link
                href={n.href}
                onClick={() => onOpen(n)}
                className="block text-[13px] font-semibold text-foreground hover:text-primary hover:underline focus-visible:underline"
              >
                {unread ? <span className="sr-only">Unread: </span> : null}
                {n.title}
              </Link>
              <p className="mt-0.5 text-[13px] break-words text-muted-foreground">{n.body}</p>
              <p className="mt-1 text-xs text-subtle-foreground">
                {n.matter?.matter_number ? `${n.matter.matter_number} · ` : ""}
                {formatDateTime(n.created_at)}
              </p>
            </div>
            {unread ? (
              <button
                type="button"
                onClick={() => onMarkRead(n)}
                className="h-fit shrink-0 cursor-pointer rounded-md px-2 py-1 text-xs font-medium text-primary hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-ring"
                aria-label={`Mark "${n.title}" as read`}
              >
                <span className="flex items-center gap-1">
                  <span aria-hidden className="size-2 rounded-full bg-primary" />
                  Mark read
                </span>
              </button>
            ) : (
              <CheckCircle2 aria-label="Read" className="mt-1 size-4 shrink-0 text-subtle-foreground" />
            )}
          </li>
        );
      })}
    </ul>
  );
}
