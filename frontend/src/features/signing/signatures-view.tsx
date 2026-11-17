"use client";

import { PenLine, SearchX } from "lucide-react";
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
import { SIGNING_STATUS_LABELS } from "@/lib/utils/labels";
import { SIGNING_STATUSES } from "@/types/api";

import { useSigningList, type SigningFilters, type SigningSort } from "./api";
import { SigningList } from "./signing-list";

const SORTS: { value: SigningSort; label: string }[] = [
  { value: "updated_at", label: "Recently updated" },
  { value: "created_at", label: "Newest requests" },
  { value: "signed_at", label: "Recently signed" },
  { value: "matter_number", label: "Matter number" },
];
const DEFAULTS: SigningFilters = { search: "", status: "", signer_type: "", sort: "updated_at", page: 1 };

function parse(params: URLSearchParams): SigningFilters {
  const status = params.get("status") ?? "";
  const type = params.get("signer_type") ?? "";
  const sort = params.get("sort") ?? "";
  const page = Number.parseInt(params.get("page") ?? "1", 10);
  return {
    search: (params.get("search") ?? "").slice(0, 100),
    status: status === "active" || (SIGNING_STATUSES as readonly string[]).includes(status) ? (status as SigningFilters["status"]) : "",
    signer_type: type === "admin" || type === "client" ? type : "",
    sort: SORTS.some((s) => s.value === sort) ? (sort as SigningSort) : DEFAULTS.sort,
    page: Number.isFinite(page) && page > 0 ? page : 1,
  };
}

/** Workspace-wide signing requests with progress; filters live in the URL (server-side filtering). */
export function SignaturesView() {
  const [filters, setFilters] = useUrlFilters(parse, DEFAULTS);
  const list = useSigningList(filters);
  const onSearch = React.useCallback((search: string) => setFilters({ search }), [setFilters]);
  const filtered = Boolean(filters.search || filters.status || filters.signer_type);

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Signatures"
        description="Every signing request: one signer per PDF, tracked from Sent to Viewed, Started and Signed. Open a request for its link, progress and evidence."
      />
      <Card className="overflow-hidden">
        <div className="flex flex-col gap-3 border-b border-border p-4 sm:flex-row sm:items-center">
          <SearchInput value={filters.search} onChange={onSearch} label="Search signing requests" placeholder="Search document, signer or matter…" className="sm:flex-1 lg:max-w-md" />
          <div className="grid grid-cols-3 gap-3 sm:flex">
            <label htmlFor="filter-signing-status" className="sr-only">
              Status
            </label>
            <Select id="filter-signing-status" value={filters.status} onChange={(e) => setFilters({ status: e.target.value as SigningFilters["status"] })} className="sm:w-44">
              <option value="">All statuses</option>
              <option value="active">Pending (not signed)</option>
              {SIGNING_STATUSES.map((s) => (
                <option key={s} value={s}>
                  {SIGNING_STATUS_LABELS[s]}
                </option>
              ))}
            </Select>
            <label htmlFor="filter-signer-type" className="sr-only">
              Signer
            </label>
            <Select id="filter-signer-type" value={filters.signer_type} onChange={(e) => setFilters({ signer_type: e.target.value as SigningFilters["signer_type"] })} className="sm:w-36">
              <option value="">All signers</option>
              <option value="client">Clients</option>
              <option value="admin">Administrators</option>
            </Select>
            <label htmlFor="filter-signing-sort" className="sr-only">
              Sort by
            </label>
            <Select id="filter-signing-sort" value={filters.sort} onChange={(e) => setFilters({ sort: e.target.value as SigningSort })} className="sm:w-44">
              {SORTS.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </Select>
          </div>
        </div>
        {list.isPending ? (
          <div className="p-4">
            <ListSkeleton rows={6} />
          </div>
        ) : list.isError ? (
          <ErrorState error={list.error} title="We couldn't load signing requests" onRetry={() => list.refetch()} retrying={list.isRefetching} />
        ) : list.data.data.length === 0 ? (
          filtered ? (
            <EmptyState
              icon={SearchX}
              title="No signing requests match your filters"
              description="Try a different search term or remove a filter."
              action={
                <Button variant="secondary" size="sm" onClick={() => setFilters({ search: "", status: "", signer_type: "" })}>
                  Clear filters
                </Button>
              }
            />
          ) : (
            <EmptyState icon={PenLine} title="No signing requests yet" description="Open a matter's Signatures tab, or a document's “Sign PDF” action, to start." />
          )
        ) : (
          <div className={cn("transition-opacity", list.isPlaceholderData && "opacity-60")} aria-busy={list.isPlaceholderData}>
            <SigningList requests={list.data.data} label="Signing requests" showMatter />
            <Pagination meta={list.data.meta} noun="signing requests" disabled={list.isPlaceholderData} onPageChange={(page) => setFilters({ page })} />
          </div>
        )}
      </Card>
    </div>
  );
}
