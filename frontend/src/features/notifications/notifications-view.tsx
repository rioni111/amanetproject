"use client";

import { Bell, CheckCheck } from "lucide-react";
import * as React from "react";

import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { ListSkeleton } from "@/components/shared/loading-skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { Pagination } from "@/components/shared/pagination";
import { useToast } from "@/components/shared/toaster";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { getErrorMessage } from "@/lib/api/errors";
import type { WorkspaceNotification } from "@/types/api";

import { useMarkAllRead, useMarkRead, useNotifications } from "./api";
import { NotificationList } from "./notification-list";

export function NotificationsView() {
  const [page, setPage] = React.useState(1);
  const [unreadOnly, setUnreadOnly] = React.useState(false);
  const list = useNotifications({ page, unreadOnly });
  const markRead = useMarkRead();
  const markAll = useMarkAllRead();
  const toast = useToast();
  const unread = list.data?.meta.unread_count ?? 0;

  const read = (n: WorkspaceNotification) => {
    if (n.read_at === null) markRead.mutate(n.id, { onError: (e) => toast.error("Could not mark as read", getErrorMessage(e)) });
  };

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Notifications"
        description="Client uploads, signatures, overdue requests, expired links and e-mail problems that need your attention."
        actions={
          <Button
            variant="secondary"
            onClick={() => markAll.mutate(undefined, { onSuccess: () => toast.success("All notifications marked as read") })}
            disabled={unread === 0 || markAll.isPending}
          >
            <CheckCheck aria-hidden />
            Mark all read
          </Button>
        }
      />
      <div role="group" aria-label="Show" className="inline-flex w-fit rounded-lg border border-border bg-surface-muted p-0.5 text-[13px]">
        {[
          [false, "All"],
          [true, `Unread (${unread})`],
        ].map(([value, label]) => (
          <button
            key={String(value)}
            type="button"
            aria-pressed={unreadOnly === value}
            onClick={() => {
              setUnreadOnly(value as boolean);
              setPage(1);
            }}
            className={`cursor-pointer rounded-md px-3 py-1.5 font-medium ${unreadOnly === value ? "bg-surface text-foreground shadow-sm" : "text-muted-foreground"}`}
          >
            {label as string}
          </button>
        ))}
      </div>
      <Card>
        <CardContent className="py-2">
          {list.isPending ? (
            <ListSkeleton rows={6} />
          ) : list.isError ? (
            <ErrorState error={list.error} title="Notifications could not be loaded" onRetry={() => void list.refetch()} />
          ) : list.data.data.length === 0 ? (
            <EmptyState icon={Bell} title={unreadOnly ? "No unread notifications" : "No notifications yet"} description="You will be notified when a client uploads, signs, or when something needs attention." />
          ) : (
            <NotificationList items={list.data.data} onOpen={read} onMarkRead={read} />
          )}
        </CardContent>
      </Card>
      {list.data && list.data.meta.last_page > 1 ? (
        <Pagination meta={list.data.meta} onPageChange={setPage} noun="notifications" disabled={list.isFetching} />
      ) : null}
    </div>
  );
}
