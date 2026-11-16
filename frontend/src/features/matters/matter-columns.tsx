import { Deadline } from "@/components/shared/deadline";
import type { Column } from "@/components/shared/data-table";
import { PriorityBadge } from "@/components/shared/priority-badge";
import { StatusBadge } from "@/components/shared/status-badge";
import type { Matter } from "@/types/api";

export function MatterIdentity({ matter, showClient = false }: { matter: Matter; showClient?: boolean }) {
  return (
    <div className="min-w-0">
      <p className="font-mono text-[11px] font-medium tracking-wide text-subtle-foreground">{matter.matter_number}</p>
      <p className="mt-0.5 truncate font-medium text-foreground" title={matter.title}>
        {matter.title}
      </p>
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
    cell: (m) => <span className="block min-w-[140px] text-[13px] text-muted-foreground">{m.client?.name ?? "—"}</span>,
  },
  type: {
    key: "type",
    header: "Type",
    cell: (m) => <span className="text-[13px] whitespace-nowrap text-muted-foreground">{m.matter_type ?? "—"}</span>,
  },
  status: { key: "status", header: "Status", cell: (m) => <StatusBadge status={m.status} /> },
  priority: { key: "priority", header: "Priority", cell: (m) => <PriorityBadge priority={m.priority} /> },
  deadline: { key: "deadline", header: "Deadline", cell: (m) => <Deadline dueAt={m.due_at} isActive={m.is_active} /> },
  openTasks: {
    key: "tasks",
    header: "Open tasks",
    headerClassName: "text-right",
    className: "text-right tabular-nums text-[13px] text-muted-foreground",
    cell: (m) => m.open_tasks_count ?? 0,
  },
} satisfies Record<string, Column<Matter>>;

/** Stacked card used for matters on small screens. */
export function MatterMobileItem({ matter }: { matter: Matter }) {
  return (
    <div className="flex flex-col gap-3">
      <div className="flex items-start justify-between gap-3">
        <MatterIdentity matter={matter} />
        <StatusBadge status={matter.status} />
      </div>
      <p className="text-[13px] text-muted-foreground">{matter.client?.name}</p>
      <div className="flex items-end justify-between gap-3">
        <Deadline dueAt={matter.due_at} isActive={matter.is_active} />
        <PriorityBadge priority={matter.priority} />
      </div>
    </div>
  );
}
