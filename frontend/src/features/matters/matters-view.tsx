"use client";

import { Briefcase, Plus, SearchX } from "lucide-react";
import { useRouter } from "next/navigation";
import * as React from "react";

import { DataTable } from "@/components/shared/data-table";
import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { TableSkeleton } from "@/components/shared/loading-skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { Pagination } from "@/components/shared/pagination";
import { SearchInput } from "@/components/shared/search-input";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Select } from "@/components/ui/select";
import { useUrlFilters } from "@/hooks/use-url-filters";
import { cn } from "@/lib/utils/cn";
import { MATTER_STATUS_LABELS, PRIORITY_LABELS } from "@/lib/utils/labels";
import { MATTER_STATUSES, PRIORITIES, type Priority } from "@/types/api";

import { useClientOptions } from "@/features/clients/api";

import { useMatters, type DeadlineFilter, type MatterFilters, type MatterSort } from "./api";
import { MatterMobileItem, matterColumns } from "./matter-columns";
import { MatterFormDialog } from "./matter-form-dialog";

const SORT_OPTIONS: { value: MatterSort; label: string }[] = [
  { value: "updated_at", label: "Recently updated" },
  { value: "created_at", label: "Newest first" },
  { value: "opened_at", label: "Opening date (newest)" },
  { value: "due_at", label: "Deadline (soonest)" },
  { value: "priority", label: "Priority (highest)" },
  { value: "matter_number", label: "Matter number" },
];

const DEADLINE_OPTIONS: { value: DeadlineFilter; label: string }[] = [
  { value: "", label: "Any deadline" },
  { value: "overdue", label: "Overdue" },
  { value: "upcoming", label: "Due in the next 14 days" },
  { value: "none", label: "No deadline" },
];

const DEFAULTS: MatterFilters = { search: "", status: "", priority: "", deadline: "", client_id: "", sort: "updated_at", page: 1 };

function parseFilters(params: URLSearchParams): MatterFilters {
  const status = params.get("status") ?? "";
  const priority = params.get("priority") ?? "";
  const sort = params.get("sort") ?? "";
  const deadline = params.get("deadline") ?? "";
  const clientId = Number.parseInt(params.get("client_id") ?? "", 10);
  const page = Number.parseInt(params.get("page") ?? "1", 10);

  return {
    search: (params.get("search") ?? "").slice(0, 100),
    status: status === "active" || status === "all" || (MATTER_STATUSES as readonly string[]).includes(status) ? (status as MatterFilters["status"]) : "",
    priority: (PRIORITIES as readonly string[]).includes(priority) ? (priority as Priority) : "",
    deadline: DEADLINE_OPTIONS.some((option) => option.value === deadline) ? (deadline as DeadlineFilter) : "",
    client_id: Number.isFinite(clientId) && clientId > 0 ? clientId : "",
    sort: SORT_OPTIONS.some((option) => option.value === sort) ? (sort as MatterSort) : DEFAULTS.sort,
    page: Number.isFinite(page) && page > 0 ? page : 1,
  };
}

export function MattersView() {
  const [filters, setFilters] = useUrlFilters(parseFilters, DEFAULTS);
  const { data, isPending, isError, error, refetch, isRefetching, isPlaceholderData } = useMatters(filters);
  const onSearch = React.useCallback((search: string) => setFilters({ search }), [setFilters]);
  const filtered = Boolean(filters.search || filters.status || filters.priority || filters.deadline || filters.client_id);
  const clientOptions = useClientOptions();
  const router = useRouter();
  const [creating, setCreating] = React.useState(false);
  const clearFilters = () => setFilters({ search: "", status: "", priority: "", deadline: "", client_id: "" });

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Matters"
        description="Every legal matter across your clients, with status, priority and deadlines."
        actions={
          <Button onClick={() => setCreating(true)}>
            <Plus aria-hidden />
            New matter
          </Button>
        }
      />

      <Card className="overflow-hidden">
        <div className="flex flex-col gap-3 border-b border-border p-4">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
            <SearchInput
              value={filters.search}
              onChange={onSearch}
              label="Search matters"
              placeholder="Search by title, number, type, client or client reference…"
              className="sm:flex-1 lg:max-w-md"
            />
            <label className="sr-only" htmlFor="sort-matters">
              Sort by
            </label>
            <Select
              id="sort-matters"
              value={filters.sort}
              onChange={(e) => setFilters({ sort: e.target.value as MatterSort })}
              className="sm:ml-auto sm:w-52"
            >
              {SORT_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          </div>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <label className="sr-only" htmlFor="filter-status">
              Status
            </label>
            <Select
              id="filter-status"
              value={filters.status}
              onChange={(e) => setFilters({ status: e.target.value as MatterFilters["status"] })}
            >
              <option value="">All except archived</option>
              <option value="active">Active (open, in progress, on hold)</option>
              <option value="all">All, including archived</option>
              {MATTER_STATUSES.map((status) => (
                <option key={status} value={status}>
                  {MATTER_STATUS_LABELS[status]}
                </option>
              ))}
            </Select>
            <label className="sr-only" htmlFor="filter-priority">
              Priority
            </label>
            <Select
              id="filter-priority"
              value={filters.priority}
              onChange={(e) => setFilters({ priority: e.target.value as MatterFilters["priority"] })}
            >
              <option value="">All priorities</option>
              {PRIORITIES.map((priority) => (
                <option key={priority} value={priority}>
                  {PRIORITY_LABELS[priority]}
                </option>
              ))}
            </Select>
            <label className="sr-only" htmlFor="filter-deadline">
              Deadline
            </label>
            <Select
              id="filter-deadline"
              value={filters.deadline}
              onChange={(e) => setFilters({ deadline: e.target.value as DeadlineFilter })}
            >
              {DEADLINE_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
            <label className="sr-only" htmlFor="filter-client">
              Client
            </label>
            <Select
              id="filter-client"
              value={String(filters.client_id)}
              onChange={(e) => setFilters({ client_id: e.target.value ? Number(e.target.value) : "" })}
            >
              <option value="">All clients</option>
              {clientOptions.data?.data.map((client) => (
                <option key={client.id} value={client.id}>
                  {client.name}
                </option>
              ))}
            </Select>
          </div>
        </div>
        {isPending ? (
          <TableSkeleton rows={8} columns={6} />
        ) : isError ? (
          <ErrorState error={error} title="We couldn't load matters" onRetry={() => refetch()} retrying={isRefetching} />
        ) : data.data.length === 0 ? (
          filtered ? (
            <EmptyState
              icon={SearchX}
              title="No matters match your filters"
              description="Try a different search term or remove a filter."
              action={
                <Button variant="secondary" size="sm" onClick={clearFilters}>
                  Clear filters
                </Button>
              }
            />
          ) : (
            <EmptyState
              icon={Briefcase}
              title="No matters yet"
              description="Open your first matter to start tracking work and deadlines."
              action={
                <Button size="sm" onClick={() => setCreating(true)}>
                  <Plus aria-hidden />
                  New matter
                </Button>
              }
            />
          )
        ) : (
          <div className={cn("transition-opacity", isPlaceholderData && "opacity-60")} aria-busy={isPlaceholderData}>
            <DataTable
              caption="Matters"
              rows={data.data}
              getRowKey={(m) => m.id}
              columns={[
                matterColumns.matter,
                matterColumns.client,
                matterColumns.type,
                matterColumns.status,
                matterColumns.priority,
                matterColumns.opened,
                matterColumns.deadline,
                matterColumns.openTasks,
                matterColumns.updated,
                matterColumns.actions,
              ]}
              renderMobileItem={(m) => <MatterMobileItem matter={m} actions />}
            />
            <Pagination meta={data.meta} noun="matters" disabled={isPlaceholderData} onPageChange={(page) => setFilters({ page })} />
          </div>
        )}
      </Card>
      <MatterFormDialog open={creating} onOpenChange={setCreating} onSaved={(matter) => router.push(`/matters/${matter.id}`)} />
    </div>
  );
}
