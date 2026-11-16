"use client";

import { ChevronRight, Mail, Pencil, Phone, Plus, SearchX, Users } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import * as React from "react";

import { ClientAvatar } from "@/components/shared/client-avatar";
import { DataTable, type Column } from "@/components/shared/data-table";
import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { TableSkeleton } from "@/components/shared/loading-skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { Pagination } from "@/components/shared/pagination";
import { SearchInput } from "@/components/shared/search-input";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Select } from "@/components/ui/select";
import { useUrlFilters } from "@/hooks/use-url-filters";
import { cn } from "@/lib/utils/cn";
import { formatDate } from "@/lib/utils/format";
import { CLIENT_TYPE_LABELS } from "@/lib/utils/labels";
import { CLIENT_TYPES, type Client, type ClientType } from "@/types/api";

import { useClients, type ClientFilters, type ClientSort } from "./api";
import { ClientFormDialog } from "./client-form-dialog";

const SORT_OPTIONS: { value: ClientSort; label: string }[] = [
  { value: "name", label: "Name (A–Z)" },
  { value: "matters_count", label: "Most matters" },
  { value: "created_at", label: "Newest" },
];

const DEFAULTS: ClientFilters = { search: "", type: "", sort: "name", page: 1 };

function parseFilters(params: URLSearchParams): ClientFilters {
  const type = params.get("type") ?? "";
  const sort = params.get("sort") ?? "";
  const page = Number.parseInt(params.get("page") ?? "1", 10);
  return {
    search: (params.get("search") ?? "").slice(0, 100),
    type: (CLIENT_TYPES as readonly string[]).includes(type) ? (type as ClientType) : "",
    sort: SORT_OPTIONS.some((option) => option.value === sort) ? (sort as ClientSort) : DEFAULTS.sort,
    page: Number.isFinite(page) && page > 0 ? page : 1,
  };
}

function ClientIdentity({ client }: { client: Client }) {
  return (
    <div className="flex min-w-0 items-center gap-3">
      <ClientAvatar name={client.name} type={client.client_type} />
      <div className="min-w-0">
        <Link href={`/clients/${client.id}`} className="block truncate font-medium text-foreground hover:text-primary hover:underline">
          {client.name}
        </Link>
        <p className="font-mono text-[11px] text-subtle-foreground">{client.reference_number ?? "No reference"}</p>
      </div>
    </div>
  );
}

function ClientContact({ client }: { client: Client }) {
  return (
    <div className="flex min-w-0 flex-col gap-1 text-[13px] text-muted-foreground">
      {client.email ? (
        <a href={`mailto:${client.email}`} className="inline-flex min-w-0 items-center gap-1.5 hover:text-foreground hover:underline">
          <Mail aria-hidden className="size-3.5 shrink-0 text-subtle-foreground" />
          <span className="truncate">{client.email}</span>
        </a>
      ) : (
        <span className="text-subtle-foreground">No email</span>
      )}
      {client.phone ? (
        <span className="inline-flex items-center gap-1.5">
          <Phone aria-hidden className="size-3.5 shrink-0 text-subtle-foreground" />
          {client.phone}
        </span>
      ) : null}
    </div>
  );
}

function MatterCount({ client }: { client: Client }) {
  return (
    <span className="text-[13px] whitespace-nowrap text-muted-foreground">
      <span className="font-semibold text-foreground tabular-nums">{client.active_matters_count ?? 0}</span> active
      <span className="text-subtle-foreground"> / {client.matters_count ?? 0} total</span>
    </span>
  );
}

function buildColumns(onEdit: (client: Client) => void): Column<Client>[] {
  return [
    { key: "client", header: "Client", cell: (c) => <ClientIdentity client={c} />, className: "max-w-[300px]" },
    {
      key: "type",
      header: "Type",
      cell: (c) => <Badge tone={c.client_type === "organization" ? "primary" : "neutral"}>{CLIENT_TYPE_LABELS[c.client_type]}</Badge>,
    },
    { key: "contact", header: "Contact", cell: (c) => <ClientContact client={c} />, className: "max-w-[280px]" },
    { key: "matters", header: "Matters", cell: (c) => <MatterCount client={c} /> },
    {
      key: "created",
      header: "Added",
      cell: (c) => <span className="text-[13px] whitespace-nowrap text-muted-foreground">{formatDate(c.created_at)}</span>,
    },
    {
      key: "actions",
      header: <span className="sr-only">Actions</span>,
      headerClassName: "text-right",
      className: "text-right",
      cell: (c) => <ClientRowActions client={c} onEdit={onEdit} />,
    },
  ];
}

function ClientRowActions({ client, onEdit }: { client: Client; onEdit: (client: Client) => void }) {
  return (
    <div className="flex items-center justify-end gap-1">
      <Button variant="ghost" size="sm" onClick={() => onEdit(client)} aria-label={`Edit ${client.name}`}>
        <Pencil aria-hidden />
        <span className="hidden xl:inline">Edit</span>
      </Button>
      <Button variant="ghost" size="sm" asChild>
        <Link href={`/clients/${client.id}`} aria-label={`Open ${client.name}`}>
          <span className="hidden xl:inline">Open</span>
          <ChevronRight aria-hidden />
        </Link>
      </Button>
    </div>
  );
}

export function ClientsView() {
  const [filters, setFilters] = useUrlFilters(parseFilters, DEFAULTS);
  const { data, isPending, isError, error, refetch, isRefetching, isPlaceholderData } = useClients(filters);
  const onSearch = React.useCallback((search: string) => setFilters({ search }), [setFilters]);
  const filtered = Boolean(filters.search || filters.type);
  const router = useRouter();
  const [formOpen, setFormOpen] = React.useState(false);
  const [editing, setEditing] = React.useState<Client | undefined>(undefined);
  const columns = React.useMemo(
    () =>
      buildColumns((client) => {
        setEditing(client);
        setFormOpen(true);
      }),
    [],
  );
  const openCreate = () => {
    setEditing(undefined);
    setFormOpen(true);
  };

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Clients"
        description="Individuals and organizations your firm represents."
        actions={
          <Button onClick={openCreate}>
            <Plus aria-hidden />
            New client
          </Button>
        }
      />

      <Card className="overflow-hidden">
        <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:items-center">
          <SearchInput
            value={filters.search}
            onChange={onSearch}
            label="Search clients"
            placeholder="Search by name, email, phone or reference…"
            className="lg:max-w-sm lg:flex-1"
          />
          <div className="grid grid-cols-2 gap-3 lg:ml-auto lg:flex">
            <label className="sr-only" htmlFor="filter-type">
              Client type
            </label>
            <Select
              id="filter-type"
              value={filters.type}
              onChange={(e) => setFilters({ type: e.target.value as ClientFilters["type"] })}
              className="lg:w-40"
            >
              <option value="">All types</option>
              {CLIENT_TYPES.map((type) => (
                <option key={type} value={type}>
                  {CLIENT_TYPE_LABELS[type]}
                </option>
              ))}
            </Select>
            <label className="sr-only" htmlFor="sort-clients">
              Sort by
            </label>
            <Select
              id="sort-clients"
              value={filters.sort}
              onChange={(e) => setFilters({ sort: e.target.value as ClientSort })}
              className="lg:w-40"
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
          <TableSkeleton rows={8} columns={4} />
        ) : isError ? (
          <ErrorState error={error} title="We couldn't load clients" onRetry={() => refetch()} retrying={isRefetching} />
        ) : data.data.length === 0 ? (
          filtered ? (
            <EmptyState
              icon={SearchX}
              title="No clients match your filters"
              description="Try a different search term or client type."
              action={
                <Button variant="secondary" size="sm" onClick={() => setFilters({ search: "", type: "" })}>
                  Clear filters
                </Button>
              }
            />
          ) : (
            <EmptyState
              icon={Users}
              title="No clients yet"
              description="Add your first client to start opening matters."
              action={
                <Button size="sm" onClick={openCreate}>
                  <Plus aria-hidden />
                  New client
                </Button>
              }
            />
          )
        ) : (
          <div className={cn("transition-opacity", isPlaceholderData && "opacity-60")} aria-busy={isPlaceholderData}>
            <DataTable
              caption="Clients"
              rows={data.data}
              getRowKey={(c) => c.id}
              columns={columns}
              renderMobileItem={(c) => (
                <div className="flex flex-col gap-3">
                  <div className="flex items-start justify-between gap-3">
                    <ClientIdentity client={c} />
                    <Badge tone={c.client_type === "organization" ? "primary" : "neutral"}>{CLIENT_TYPE_LABELS[c.client_type]}</Badge>
                  </div>
                  <ClientContact client={c} />
                  <div className="flex items-center justify-between gap-3">
                    <MatterCount client={c} />
                    <ClientRowActions
                      client={c}
                      onEdit={(client) => {
                        setEditing(client);
                        setFormOpen(true);
                      }}
                    />
                  </div>
                </div>
              )}
            />
            <Pagination meta={data.meta} noun="clients" disabled={isPlaceholderData} onPageChange={(page) => setFilters({ page })} />
          </div>
        )}
      </Card>
      <ClientFormDialog
        open={formOpen}
        onOpenChange={setFormOpen}
        client={editing}
        onSaved={(client) => {
          if (!editing) router.push(`/clients/${client.id}`);
        }}
      />
    </div>
  );
}
