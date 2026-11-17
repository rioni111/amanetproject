"use client";

import { Bell, CheckCheck, Loader2 } from "lucide-react";
import Link from "next/link";
import * as React from "react";

import { useToast } from "@/components/shared/toaster";
import { getErrorMessage } from "@/lib/api/errors";
import type { WorkspaceNotification } from "@/types/api";

import { useMarkAllRead, useMarkRead, useNotifications, useUnreadCount } from "./api";
import { NotificationList } from "./notification-list";

/**
 * Header bell with the unread count and a panel of recent notifications. A disclosure (button + region):
 * Escape or a click outside closes it and focus returns to the bell.
 */
export function NotificationBell() {
  const [open, setOpen] = React.useState(false);
  const unread = useUnreadCount();
  const list = useNotifications({ perPage: 8, enabled: open });
  const markRead = useMarkRead();
  const markAll = useMarkAllRead();
  const toast = useToast();
  const buttonRef = React.useRef<HTMLButtonElement>(null);
  const panelRef = React.useRef<HTMLDivElement>(null);
  const count = unread.data ?? 0;

  React.useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") {
        setOpen(false);
        buttonRef.current?.focus();
      }
    };
    const onPointer = (e: PointerEvent) => {
      const target = e.target as Node;
      if (!panelRef.current?.contains(target) && !buttonRef.current?.contains(target)) setOpen(false);
    };
    document.addEventListener("keydown", onKey);
    document.addEventListener("pointerdown", onPointer);
    return () => {
      document.removeEventListener("keydown", onKey);
      document.removeEventListener("pointerdown", onPointer);
    };
  }, [open]);

  const read = (n: WorkspaceNotification) => {
    if (n.read_at === null) markRead.mutate(n.id, { onError: (e) => toast.error("Could not mark as read", getErrorMessage(e)) });
  };

  return (
    <div className="relative">
      <button
        ref={buttonRef}
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        aria-controls="notification-panel"
        aria-label={count > 0 ? `Notifications, ${count} unread` : "Notifications"}
        data-testid="notification-bell"
        className="relative inline-flex size-9 cursor-pointer items-center justify-center rounded-md text-muted-foreground hover:bg-surface-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
      >
        <Bell aria-hidden className="size-[18px]" />
        {count > 0 ? (
          <span
            aria-hidden
            data-testid="notification-count"
            className="absolute -top-0.5 -right-0.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-danger px-1 text-[10px] font-semibold text-white"
          >
            {count > 99 ? "99+" : count}
          </span>
        ) : null}
      </button>
      {open ? (
        <div
          ref={panelRef}
          id="notification-panel"
          role="region"
          aria-label="Recent notifications"
          className="fixed inset-x-3 top-16 z-40 max-h-[70dvh] overflow-hidden rounded-xl border border-border bg-surface shadow-[var(--shadow-overlay,0_10px_30px_rgba(0,0,0,.12))] sm:absolute sm:inset-x-auto sm:top-11 sm:right-0 sm:w-[380px]"
        >
          <div className="flex items-center justify-between gap-2 border-b border-border px-3 py-2.5">
            <p className="text-sm font-semibold text-foreground">Notifications</p>
            <button
              type="button"
              onClick={() => markAll.mutate(undefined, { onError: (e) => toast.error("Could not mark all as read", getErrorMessage(e)) })}
              disabled={count === 0 || markAll.isPending}
              className="inline-flex cursor-pointer items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary hover:bg-primary-soft disabled:cursor-default disabled:opacity-50"
            >
              {markAll.isPending ? <Loader2 aria-hidden className="size-3.5 animate-spin" /> : <CheckCheck aria-hidden className="size-3.5" />}
              Mark all read
            </button>
          </div>
          <div className="max-h-[calc(70dvh-96px)] overflow-y-auto">
            {list.isPending ? (
              <p className="flex items-center gap-2 px-3 py-6 text-[13px] text-muted-foreground" role="status">
                <Loader2 aria-hidden className="size-4 animate-spin" /> Loading notifications…
              </p>
            ) : list.isError ? (
              <p className="px-3 py-6 text-[13px] text-danger" role="alert">
                Notifications could not be loaded. {getErrorMessage(list.error)}
              </p>
            ) : list.data.data.length === 0 ? (
              <p className="px-3 py-6 text-center text-[13px] text-muted-foreground">You are all caught up.</p>
            ) : (
              <NotificationList
                compact
                items={list.data.data}
                onOpen={(n) => {
                  read(n);
                  setOpen(false);
                }}
                onMarkRead={read}
              />
            )}
          </div>
          <div className="border-t border-border px-3 py-2 text-right">
            <Link href="/notifications" onClick={() => setOpen(false)} className="text-xs font-medium text-primary hover:underline">
              View all notifications
            </Link>
          </div>
        </div>
      ) : null}
    </div>
  );
}
