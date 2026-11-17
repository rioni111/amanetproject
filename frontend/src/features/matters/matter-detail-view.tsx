"use client";

import {
  AlertTriangle,
  ArrowLeft,
  Briefcase,
  Building2,
  CalendarClock,
  CalendarDays,
  Clock3,
  FileSearch,
  Hash,
  Pencil,
  User,
} from "lucide-react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import * as React from "react";

import { Deadline } from "@/components/shared/deadline";
import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { PriorityBadge } from "@/components/shared/priority-badge";
import { StatusBadge } from "@/components/shared/status-badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { TabPanel, Tabs } from "@/components/ui/tabs";
import { ActivityTimeline } from "@/features/activity/activity-timeline";
import { DocumentsPanel } from "@/features/documents/documents-panel";
import { NotesPanel } from "@/features/notes/notes-panel";
import { RequestsPanel } from "@/features/requests/requests-panel";
import { SignaturesPanel } from "@/features/signing/signatures-panel";
import { TasksPanel } from "@/features/tasks/tasks-panel";
import { ApiError } from "@/lib/api/errors";
import { formatDate, formatDateTime } from "@/lib/utils/format";
import { CLIENT_TYPE_LABELS, MATTER_STATUS_LABELS } from "@/lib/utils/labels";
import type { Matter } from "@/types/api";

import { useMatter } from "./api";
import { ArchiveRestoreButton, PriorityControl, StatusControl } from "./matter-actions";
import { MatterFormDialog } from "./matter-form-dialog";

const TABS = ["overview", "tasks", "documents", "requests", "signatures", "notes", "activity"] as const;
type Tab = (typeof TABS)[number];

export function MatterDetailView({ id }: { id: number }) {
  const matter = useMatter(id);

  if (matter.isPending) return <DetailSkeleton />;
  if (matter.isError) {
    if (matter.error instanceof ApiError && matter.error.status === 404) {
      return (
        <Card>
          <EmptyState
            icon={FileSearch}
            title="Matter not found"
            description="It may have been mistyped, or you followed an outdated link."
            action={
              <Button variant="secondary" size="sm" asChild>
                <Link href="/matters">Back to matters</Link>
              </Button>
            }
          />
        </Card>
      );
    }
    return (
      <Card>
        <ErrorState
          error={matter.error}
          title="We couldn't load this matter"
          onRetry={() => matter.refetch()}
          retrying={matter.isRefetching}
        />
      </Card>
    );
  }
  return <MatterWorkspace matter={matter.data} />;
}

function MatterWorkspace({ matter }: { matter: Matter }) {
  const searchParams = useSearchParams();
  const initial = searchParams.get("tab");
  const [tab, setTab] = React.useState<Tab>(TABS.includes(initial as Tab) ? (initial as Tab) : "overview");
  const [editing, setEditing] = React.useState(false);
  const archived = matter.status === "archived";

  const changeTab = (next: string) => {
    setTab(next as Tab);
    const url = new URL(window.location.href);
    if (next === "overview") url.searchParams.delete("tab");
    else url.searchParams.set("tab", next);
    window.history.replaceState(null, "", url);
  };

  return (
    <div className="flex flex-col gap-6">
      <div>
        <Link
          href="/matters"
          className="inline-flex items-center gap-1.5 text-[13px] font-medium text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft aria-hidden className="size-3.5" />
          All matters
        </Link>
      </div>

      <header className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
        <div className="min-w-0">
          <p className="font-mono text-xs font-medium tracking-wide text-subtle-foreground">{matter.matter_number}</p>
          <h1 className="mt-1 text-[26px] leading-tight font-semibold tracking-[-0.02em] break-words text-foreground">{matter.title}</h1>
          <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-[13px]">
            {matter.client ? (
              <Link
                href={`/clients/${matter.client.id}`}
                className="inline-flex items-center gap-1.5 font-medium text-foreground hover:text-primary hover:underline"
              >
                {matter.client.client_type === "organization" ? (
                  <Building2 aria-hidden className="size-3.5" />
                ) : (
                  <User aria-hidden className="size-3.5" />
                )}
                {matter.client.name}
              </Link>
            ) : null}
            <StatusBadge status={matter.status} />
            <PriorityBadge priority={matter.priority} />
            {matter.due_date ? (
              <Deadline
                dueAt={matter.due_at}
                dueDate={matter.due_date}
                isActive={matter.is_active}
                className="flex-row items-baseline gap-2"
              />
            ) : null}
          </div>
        </div>
        <div className="flex shrink-0 flex-wrap gap-2">
          <Button variant="secondary" onClick={() => setEditing(true)} disabled={archived}>
            <Pencil aria-hidden />
            Edit
          </Button>
          <ArchiveRestoreButton matter={matter} />
        </div>
      </header>

      {archived ? (
        <div
          role="status"
          className="flex items-start gap-2.5 rounded-lg border border-border-strong bg-surface-muted px-4 py-3 text-[13px] text-muted-foreground"
        >
          <AlertTriangle aria-hidden className="mt-px size-4 shrink-0" />
          <span>
            This matter is archived{matter.archived_at ? ` (since ${formatDate(matter.archived_at)})` : ""} and is read-only. Its tasks,
            notes and history are preserved. Restore it to make changes
            {matter.archived_from_status ? ` — it will return to ${MATTER_STATUS_LABELS[matter.archived_from_status]}` : ""}.
          </span>
        </div>
      ) : null}

      <Tabs
        label="Matter sections"
        idPrefix="matter"
        value={tab}
        onValueChange={changeTab}
        items={[
          { id: "overview", label: "Overview" },
          { id: "tasks", label: "Tasks", count: matter.open_tasks_count ?? 0 },
          { id: "documents", label: "Documents", count: matter.documents_count ?? 0 },
          { id: "requests", label: "Client requests", count: matter.requests?.open ?? 0 },
          { id: "signatures", label: "Signatures", count: matter.signatures?.active ?? 0 },
          { id: "notes", label: "Internal notes", count: matter.notes_count ?? 0 },
          { id: "activity", label: "Activity" },
        ]}
      />

      {tab === "overview" ? (
        <TabPanel idPrefix="matter" id="overview">
          <Overview matter={matter} onShowTasks={() => changeTab("tasks")} />
        </TabPanel>
      ) : null}
      {tab === "tasks" ? (
        <TabPanel idPrefix="matter" id="tasks">
          <TasksPanel matter={matter} />
        </TabPanel>
      ) : null}
      {tab === "documents" ? (
        <TabPanel idPrefix="matter" id="documents">
          <DocumentsPanel matter={matter} />
        </TabPanel>
      ) : null}
      {tab === "requests" ? (
        <TabPanel idPrefix="matter" id="requests">
          <RequestsPanel matter={matter} />
        </TabPanel>
      ) : null}
      {tab === "signatures" ? (
        <TabPanel idPrefix="matter" id="signatures">
          <SignaturesPanel matter={matter} />
        </TabPanel>
      ) : null}
      {tab === "notes" ? (
        <TabPanel idPrefix="matter" id="notes">
          <NotesPanel matter={matter} />
        </TabPanel>
      ) : null}
      {tab === "activity" ? (
        <TabPanel idPrefix="matter" id="activity">
          <ActivityTimeline matterId={matter.id} />
        </TabPanel>
      ) : null}

      <MatterFormDialog open={editing} onOpenChange={setEditing} matter={matter} />
    </div>
  );
}

function Overview({ matter, onShowTasks }: { matter: Matter; onShowTasks: () => void }) {
  return (
    <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
      <div className="flex min-w-0 flex-col gap-6">
        <Card>
          <CardHeader>
            <CardTitle>Description</CardTitle>
          </CardHeader>
          <CardContent>
            {matter.description ? (
              <p className="text-sm leading-relaxed break-words whitespace-pre-wrap text-foreground">{matter.description}</p>
            ) : (
              <p className="text-sm text-subtle-foreground">No description yet.</p>
            )}
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>Outstanding work</CardTitle>
            <Button variant="link" size="sm" onClick={onShowTasks}>
              View tasks
            </Button>
          </CardHeader>
          <CardContent>
            <dl className="grid grid-cols-3 gap-3">
              <Stat label="Outstanding" value={matter.open_tasks_count ?? 0} />
              <Stat label="Overdue" value={matter.overdue_tasks_count ?? 0} tone={matter.overdue_tasks_count ? "danger" : undefined} />
              <Stat label="Completed" value={matter.completed_tasks_count ?? 0} />
            </dl>
          </CardContent>
        </Card>
      </div>
      <div className="flex flex-col gap-6">
        {matter.status !== "archived" ? (
          <Card>
            <CardHeader>
              <CardTitle>Status &amp; priority</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
              <StatusControl matter={matter} />
              <PriorityControl matter={matter} />
            </CardContent>
          </Card>
        ) : null}
        <Card>
          <CardHeader>
            <CardTitle>Details</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className="flex flex-col divide-y divide-border text-[13px]">
              <Detail icon={Hash} label="Matter number" value={<span className="font-mono">{matter.matter_number}</span>} />
              <Detail icon={Briefcase} label="Matter type" value={matter.matter_type ?? "—"} />
              <Detail
                icon={Building2}
                label="Client"
                value={
                  matter.client ? (
                    <span className="text-right">
                      {matter.client.name}
                      <span className="block text-xs text-subtle-foreground">
                        {CLIENT_TYPE_LABELS[matter.client.client_type]}
                        {matter.client.reference_number ? ` · ${matter.client.reference_number}` : ""}
                      </span>
                    </span>
                  ) : (
                    "—"
                  )
                }
              />
              <Detail icon={CalendarDays} label="Opened" value={formatDate(matter.opened_date)} />
              <Detail icon={CalendarClock} label="Deadline" value={matter.due_date ? formatDate(matter.due_date) : "No deadline"} />
              {matter.closed_at ? <Detail icon={CalendarDays} label="Closed" value={formatDate(matter.closed_at)} /> : null}
              {matter.archived_at ? <Detail icon={CalendarDays} label="Archived" value={formatDate(matter.archived_at)} /> : null}
              <Detail icon={Clock3} label="Last updated" value={formatDateTime(matter.updated_at)} />
            </dl>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}

function Stat({ label, value, tone }: { label: string; value: number; tone?: "danger" }) {
  return (
    <div className="rounded-lg border border-border bg-surface-muted/50 px-3 py-3">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd
        className={
          tone === "danger"
            ? "mt-1 text-xl font-semibold text-danger tabular-nums"
            : "mt-1 text-xl font-semibold text-foreground tabular-nums"
        }
      >
        {value}
      </dd>
    </div>
  );
}

function Detail({ icon: Icon, label, value }: { icon: typeof Hash; label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
      <dt className="inline-flex shrink-0 items-center gap-2 text-muted-foreground">
        <Icon aria-hidden className="size-3.5 text-subtle-foreground" />
        {label}
      </dt>
      <dd className="min-w-0 text-right font-medium break-words text-foreground">{value}</dd>
    </div>
  );
}

function DetailSkeleton() {
  return (
    <div className="flex flex-col gap-6" role="status" aria-label="Loading matter">
      <Skeleton className="h-3 w-24" />
      <div className="flex flex-col gap-3">
        <Skeleton className="h-3 w-28" />
        <Skeleton className="h-7 w-2/3" />
        <Skeleton className="h-4 w-1/2" />
      </div>
      <Skeleton className="h-10 w-full" />
      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        <Skeleton className="h-48 w-full rounded-xl" />
        <Skeleton className="h-48 w-full rounded-xl" />
      </div>
      <span className="sr-only">Loading matter…</span>
    </div>
  );
}
