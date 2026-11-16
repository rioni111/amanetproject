"use client";

import { Briefcase, SearchX } from "lucide-react";
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
import { MATTER_STATUSES, PRIORITIES, type MatterStatus, type Priority } from "@/types/api";

import { useMatters, type MatterFilters, type MatterSort } from "./api";
import { MatterMobileItem, matterColumns } from "./matter-columns";

const SORT_OPTIONS: { value: MatterSort; label: string }[] = [
  { value: "updated_at", label: "Recently updated" },
  { value: "due_at", label: "Deadline (soonest)" },
  { value: "priority", label: "Priority (highest)" },
  { value: "matter_number", label: "Matter number" },
];

const DEFAULTS: MatterFilters = { search: "", status: "", priority: "", sort: "updated_at", page: 1 };

function parseFilters(params: URLSearchParams): MatterFilters {
  const status = params.get("status") ?? "";
  const priority = params.get("priority") ?? "";
  const sort = params.get("sort") ?? "";
  const page = Number.parseInt(params.get("page") ?? "1", 10);

  return {
    search: (params.get("search") ?? "").slice(0, 100),
    status: status === "active" || (MATTER_STATUSES as readonly string[]).includes(status) ? (status as MatterStatus | "active") : "",
    priority: (PRIORITIES as readonly string[]).includes(priority) ? (priority as Priority) : "",
    sort: SORT_OPTIONS.some((option) => option.value === sort) ? (sort as MatterSort) : DEFAULTS.sort,
    page: Number.isFinite(page) && page > 0 ? page : 1,
  };
}

export function MattersView() {
  const [filters, setFilters] = useUrlFilters(parseFilters, DEFAULTS);
  const { data, isPending, isError, error, refetch, isRefetching, isPlaceholderData } = useMatters(filters);
  const onSearch = React.useCallback((search: string) => setFilters({ search }), [setFilters]);
  const filtered = Boolean(filters.search || filters.status || filters.priority);

  return (
    <div className="flex flex-col gap-6">
      <PageHeader title="Matters" description="Every legal matter across your clients, with status, priority and deadlines." />

      <Card className="overflow-hidden">
        <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:items-center">
          <SearchInput
            value={filters.search}
            onChange={onSearch}
            label="Search matters"
            placeholder="Search by title, number, type or client…"
            className="lg:max-w-sm lg:flex-1"
          />
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:ml-auto lg:flex">
            <label className="sr-only" htmlFor="filter-status">
              Status
            </label>
            <Select
              id="filter-status"
              value={filters.status}
              onChange={(e) => setFilters({ status: e.target.value as MatterFilters["status"] })}
              className="lg:w-40"
            >
              <option value="">All statuses</option>
              <option value="active">Active (open, in progress, on hold)</option>
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
              className="lg:w-36"
            >
              <option value="">All priorities</option>
              {PRIORITIES.map((priority) => (
                <option key={priority} value={priority}>
                  {PRIORITY_LABELS[priority]}
                </option>
              ))}
            </Select>
            <label className="sr-only" htmlFor="sort-matters">
              Sort by
            </label>
            <Select
              id="sort-matters"
              value={filters.sort}
              onChange={(e) => setFilters({ sort: e.target.value as MatterSort })}
              className="col-span-2 sm:col-span-1 lg:w-44"
            >
              {SORT_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
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
                <Button variant="secondary" size="sm" onClick={() => setFilters({ search: "", status: "", priority: "" })}>
                  Clear filters
                </Button>
              }
            />
          ) : (
            <EmptyState icon={Briefcase} title="No matters yet" description="Matters will appear here once they are opened." />
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
                matterColumns.deadline,
                matterColumns.openTasks,
              ]}
              renderMobileItem={(m) => <MatterMobileItem matter={m} />}
            />
            <Pagination meta={data.meta} noun="matters" disabled={isPlaceholderData} onPageChange={(page) => setFilters({ page })} />
          </div>
        )}
      </Card>
    </div>
  );
}
