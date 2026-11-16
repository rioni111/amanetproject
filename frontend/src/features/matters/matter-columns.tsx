import Link from "next/link";

import { Deadline } from "@/components/shared/deadline";
import type { Column } from "@/components/shared/data-table";
import { PriorityBadge } from "@/components/shared/priority-badge";
import { StatusBadge } from "@/components/shared/status-badge";
import { formatDate } from "@/lib/utils/format";
import type { Matter } from "@/types/api";

import { MatterQuickActions } from "./matter-actions";

export function MatterIdentity({ matter, showClient = false }: { matter: Matter; showClient?: boolean }) {
  return (
    <div className="min-w-0">
      <p className="font-mono text-[11px] font-medium tracking-wide text-subtle-foreground">{matter.matter_number}</p>
      <Link
        href={`/matters/${matter.id}`}
        className="mt-0.5 block truncate font-medium text-foreground hover:text-primary hover:underline"
        title={matter.title}
      >
        {matter.title}
      </Link>
      {showClient && matter.client ? <p className="mt-0.5 truncate text-[13px] text-muted-foreground">{matter.client.name}</p> : null}
    </div>
  );
}

export const matterColumns = {
  matter: { key: "matter", header: "Matter", cell: (m) => <MatterIdentity matter={m} />, className: "max-w-[320px]" },
  matterWithClient: {
    key: "matter",
    header: "Matter",
    cell: (m) => <MatterIdentity matter={m} showClient />,
    className: "max-w-[340px]",
  },
  client: {
    key: "client",
    header: "Client",
    cell: (m) =>
      m.client ? (
        <Link
          href={`/clients/${m.client.id}`}
          className="block min-w-[140px] text-[13px] text-muted-foreground hover:text-foreground hover:underline"
        >
          {m.client.name}
        </Link>
      ) : (
        <span className="text-[13px] text-subtle-foreground">—</span>
      ),
  },
  type: {
    key: "type",
    header: "Type",
    cell: (m) => <span className="text-[13px] whitespace-nowrap text-muted-foreground">{m.matter_type ?? "—"}</span>,
  },
  status: { key: "status", header: "Status", cell: (m) => <StatusBadge status={m.status} /> },
  priority: { key: "priority", header: "Priority", cell: (m) => <PriorityBadge priority={m.priority} /> },
  opened: {
    key: "opened",
    header: "Opened",
    cell: (m) => <span className="text-[13px] whitespace-nowrap text-muted-foreground">{formatDate(m.opened_date ?? m.opened_at)}</span>,
  },
  deadline: { key: "deadline", header: "Deadline", cell: (m) => <Deadline dueAt={m.due_at} dueDate={m.due_date} isActive={m.is_active} /> },
  openTasks: {
    key: "tasks",
    header: "Open tasks",
    headerClassName: "text-right",
    className: "text-right tabular-nums text-[13px] text-muted-foreground",
    cell: (m) => m.open_tasks_count ?? 0,
  },
  updated: {
    key: "updated",
    header: "Updated",
    cell: (m) => <span className="text-[13px] whitespace-nowrap text-muted-foreground">{formatDate(m.updated_at)}</span>,
  },
  actions: {
    key: "actions",
    header: <span className="sr-only">Actions</span>,
    headerClassName: "text-right",
    className: "text-right",
    cell: (m) => <MatterQuickActions matter={m} />,
  },
} satisfies Record<string, Column<Matter>>;

/** Stacked card used for matters on small screens. */
export function MatterMobileItem({ matter, actions = false }: { matter: Matter; actions?: boolean }) {
  return (
    <div className="flex flex-col gap-3">
      <div className="flex items-start justify-between gap-3">
        <MatterIdentity matter={matter} />
        <StatusBadge status={matter.status} />
      </div>
      <p className="text-[13px] text-muted-foreground">{matter.client?.name}</p>
      <div className="flex items-end justify-between gap-3">
        <Deadline dueAt={matter.due_at} dueDate={matter.due_date} isActive={matter.is_active} />
        <PriorityBadge priority={matter.priority} />
      </div>
      {actions ? (
        <div className="flex items-center justify-between gap-3 border-t border-border pt-3 text-[13px] text-muted-foreground">
          <span>
            <span className="font-semibold text-foreground tabular-nums">{matter.open_tasks_count ?? 0}</span> open tasks
          </span>
          <MatterQuickActions matter={matter} />
        </div>
      ) : null}
    </div>
  );
}
