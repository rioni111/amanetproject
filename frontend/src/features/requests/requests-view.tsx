"use client";

import { Inbox, SearchX } from "lucide-react";
import * as React from "react";

import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { ListSkeleton } from "@/components/shared/loading-skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { Pagination } from "@/components/shared/pagination";
import { SearchInput } from "@/components/shared/search-input";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Select } from "@/components/ui/select";
import { useUrlFilters } from "@/hooks/use-url-filters";
import { cn } from "@/lib/utils/cn";
import { REQUEST_STATUS_LABELS } from "@/lib/utils/labels";
import { DOCUMENT_REQUEST_STATUSES } from "@/types/api";

import { useRequestList, type RequestFilters, type RequestSort } from "./api";
import { RequestList } from "./request-list";

const SORT_OPTIONS: { value: RequestSort; label: string }[] = [
  { value: "updated_at", label: "Recently updated" },
  { value: "due_at", label: "Due date (soonest)" },
  { value: "last_submitted_at", label: "Latest submission" },
  { value: "created_at", label: "Newest requests" },
  { value: "title", label: "Title (A–Z)" },
  { value: "matter_number", label: "Matter number" },
];

const GROUPS = [
  { value: "open", label: "All outstanding" },
  { value: "awaiting_client", label: "Waiting for the client" },
  { value: "awaiting_review", label: "Waiting for review" },
] as const;

const STATUS_VALUES: string[] = [...GROUPS.map((g) => g.value), ...DOCUMENT_REQUEST_STATUSES];

const DEFAULTS: RequestFilters = { search: "", status: "", overdue: 0, sort: "updated_at", page: 1 };

function parseFilters(params: URLSearchParams): RequestFilters {
  const status = params.get("status") ?? "";
  const sort = params.get("sort") ?? "";
  const page = Number.parseInt(params.get("page") ?? "1", 10);
  return {
    search: (params.get("search") ?? "").slice(0, 100),
    status: STATUS_VALUES.includes(status) ? (status as RequestFilters["status"]) : "",
    overdue: params.get("overdue") === "1" ? 1 : 0,
    sort: SORT_OPTIONS.some((o) => o.value === sort) ? (sort as RequestSort) : DEFAULTS.sort,
    page: Number.isFinite(page) && page > 0 ? page : 1,
  };
}

/** Workspace-wide outstanding client requirements. Filters live in the URL (server-side filtering). */
export function RequestsView() {
  const [filters, setFilters] = useUrlFilters(parseFilters, DEFAULTS);
  const list = useRequestList(filters);
  const onSearch = React.useCallback((search: string) => setFilters({ search }), [setFilters]);
  const filtered = Boolean(filters.search || filters.status || filters.overdue);

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Client requests"
        description="Documents requested from clients through secure upload links: what is outstanding, overdue, waiting for review or completed."
      />
      <Card className="overflow-hidden">
        <div className="flex flex-col gap-3 border-b border-border p-4 sm:flex-row sm:items-center">
          <SearchInput
            value={filters.search}
            onChange={onSearch}
            label="Search requests"
            placeholder="Search request, matter or client…"
            className="sm:flex-1 lg:max-w-md"
          />
          <div className="grid grid-cols-2 gap-3 sm:flex">
            <label htmlFor="filter-request-status" className="sr-only">
              Status
            </label>
            <Select
              id="filter-request-status"
              value={filters.status}
              onChange={(e) => setFilters({ status: e.target.value as RequestFilters["status"] })}
              className="sm:w-52"
            >
              <option value="">All requests</option>
              <optgroup label="Outstanding">
                {GROUPS.map((g) => (
                  <option key={g.value} value={g.value}>
                    {g.label}
                  </option>
                ))}
              </optgroup>
              <optgroup label="Status">
                {DOCUMENT_REQUEST_STATUSES.map((s) => (
                  <option key={s} value={s}>
                    {REQUEST_STATUS_LABELS[s]}
                  </option>
                ))}
              </optgroup>
            </Select>
            <label htmlFor="filter-request-sort" className="sr-only">
              Sort by
            </label>
            <Select
              id="filter-request-sort"
              value={filters.sort}
              onChange={(e) => setFilters({ sort: e.target.value as RequestSort })}
              className="sm:w-48"
            >
              {SORT_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <label className="inline-flex items-center gap-2 text-[13px] text-foreground sm:ml-auto">
            <input
              type="checkbox"
              className="size-4 accent-[var(--color-primary)]"
              checked={filters.overdue === 1}
              onChange={(e) => setFilters({ overdue: e.target.checked ? 1 : 0 })}
            />
            Overdue only
          </label>
        </div>

        {list.isPending ? (
          <div className="p-4">
            <ListSkeleton rows={6} />
          </div>
        ) : list.isError ? (
          <ErrorState error={list.error} title="We couldn't load client requests" onRetry={() => list.refetch()} retrying={list.isRefetching} />
        ) : list.data.data.length === 0 ? (
          filtered ? (
            <EmptyState
              icon={SearchX}
              title="No requests match your filters"
              description="Try a different search term or remove a filter."
              action={
                <Button variant="secondary" size="sm" onClick={() => setFilters({ search: "", status: "", overdue: 0 })}>
                  Clear filters
                </Button>
              }
            />
          ) : (
            <EmptyState icon={Inbox} title="No client requests yet" description="Open a matter and use “Request document” to ask a client for a PDF." />
          )
        ) : (
          <div className={cn("transition-opacity", list.isPlaceholderData && "opacity-60")} aria-busy={list.isPlaceholderData}>
            <RequestList requests={list.data.data} label="Client requests" showMatter />
            <Pagination meta={list.data.meta} noun="requests" disabled={list.isPlaceholderData} onPageChange={(page) => setFilters({ page })} />
          </div>
        )}
      </Card>
    </div>
  );
}
