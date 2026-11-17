"use client";

import { ArrowLeft, Briefcase, FileSearch, Hash, Mail, MapPin, Pencil, Phone, Plus, Trash2 } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import * as React from "react";

import { ClientAvatar } from "@/components/shared/client-avatar";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { DataTable } from "@/components/shared/data-table";
import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { TableSkeleton } from "@/components/shared/loading-skeleton";
import { Pagination } from "@/components/shared/pagination";
import { useToast } from "@/components/shared/toaster";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useMatters, type MatterFilters } from "@/features/matters/api";
import { MatterMobileItem, matterColumns } from "@/features/matters/matter-columns";
import { MatterFormDialog } from "@/features/matters/matter-form-dialog";
import { ApiError, getErrorMessage } from "@/lib/api/errors";
import { cn } from "@/lib/utils/cn";
import { formatDate } from "@/lib/utils/format";
import { CLIENT_TYPE_LABELS } from "@/lib/utils/labels";
import type { Client } from "@/types/api";

import { useClient, useDeleteClient } from "./api";
import { ClientFormDialog } from "./client-form-dialog";

export function ClientDetailView({ id }: { id: number }) {
  const client = useClient(id);

  if (client.isPending) {
    return (
      <div className="flex flex-col gap-6" role="status" aria-label="Loading client">
        <Skeleton className="h-3 w-24" />
        <Skeleton className="h-8 w-1/2" />
        <div className="grid gap-6 lg:grid-cols-[340px_minmax(0,1fr)]">
          <Skeleton className="h-64 rounded-xl" />
          <Skeleton className="h-64 rounded-xl" />
        </div>
      </div>
    );
  }
  if (client.isError) {
    return (
      <Card>
        {client.error instanceof ApiError && client.error.status === 404 ? (
          <EmptyState
            icon={FileSearch}
            title="Client not found"
            description="It may have been deleted, or the link is outdated."
            action={
              <Button variant="secondary" size="sm" asChild>
                <Link href="/clients">Back to clients</Link>
              </Button>
            }
          />
        ) : (
          <ErrorState
            error={client.error}
            title="We couldn't load this client"
            onRetry={() => client.refetch()}
            retrying={client.isRefetching}
          />
        )}
      </Card>
    );
  }
  return <ClientWorkspace client={client.data} />;
}

function ClientWorkspace({ client }: { client: Client }) {
  const router = useRouter();
  const toast = useToast();
  const remove = useDeleteClient();
  const [editing, setEditing] = React.useState(false);
  const [creatingMatter, setCreatingMatter] = React.useState(false);
  const [confirmDelete, setConfirmDelete] = React.useState(false);
  const [page, setPage] = React.useState(1);
  // A client's record lists every matter, archived ones included (they also block deleting the client).
  const filters: MatterFilters = { search: "", status: "all", priority: "", deadline: "", client_id: client.id, sort: "updated_at", page };
  const matters = useMatters(filters, 8);
  const canDelete = (client.matters_count ?? 0) === 0;

  return (
    <div className="flex flex-col gap-6">
      <div>
        <Link
          href="/clients"
          className="inline-flex items-center gap-1.5 text-[13px] font-medium text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft aria-hidden className="size-3.5" />
          All clients
        </Link>
      </div>

      <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex min-w-0 items-center gap-4">
          <ClientAvatar name={client.name} type={client.client_type} className="size-12 rounded-xl text-sm" />
          <div className="min-w-0">
            <h1 className="text-[26px] leading-tight font-semibold tracking-[-0.02em] break-words text-foreground">{client.name}</h1>
            <div className="mt-1.5 flex flex-wrap items-center gap-2 text-[13px] text-muted-foreground">
              <Badge tone={client.client_type === "organization" ? "primary" : "neutral"}>{CLIENT_TYPE_LABELS[client.client_type]}</Badge>
              {client.reference_number ? <span className="font-mono text-xs">{client.reference_number}</span> : null}
              <span>Client since {formatDate(client.created_at)}</span>
            </div>
          </div>
        </div>
        <div className="flex shrink-0 flex-wrap gap-2">
          <Button variant="secondary" onClick={() => setEditing(true)}>
            <Pencil aria-hidden />
            Edit client
          </Button>
          <Button onClick={() => setCreatingMatter(true)}>
            <Plus aria-hidden />
            New matter
          </Button>
        </div>
      </header>

      <div className="grid gap-6 lg:grid-cols-[340px_minmax(0,1fr)]">
        <div className="flex flex-col gap-6">
          <Card>
            <CardHeader>
              <CardTitle>Contact</CardTitle>
            </CardHeader>
            <CardContent>
              <dl className="flex flex-col gap-3.5 text-[13px]">
                <ContactRow icon={Mail} label="Email">
                  {client.email ? (
                    <a href={`mailto:${client.email}`} className="break-all text-foreground hover:text-primary hover:underline">
                      {client.email}
                    </a>
                  ) : null}
                </ContactRow>
                <ContactRow icon={Phone} label="Phone">
                  {client.phone ? (
                    <a href={`tel:${client.phone.replace(/[^\d+]/g, "")}`} className="text-foreground hover:text-primary hover:underline">
                      {client.phone}
                    </a>
                  ) : null}
                </ContactRow>
                <ContactRow icon={MapPin} label="Address">
                  {client.address ? <span className="whitespace-pre-line text-foreground">{client.address}</span> : null}
                </ContactRow>
                <ContactRow icon={Hash} label="Reference">
                  {client.reference_number ? <span className="font-mono text-foreground">{client.reference_number}</span> : null}
                </ContactRow>
              </dl>
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>Matters</CardTitle>
            </CardHeader>
            <CardContent>
              <dl className="grid grid-cols-2 gap-3">
                <Count label="Active" value={client.active_matters_count ?? 0} highlight />
                <Count label="Closed" value={client.closed_matters_count ?? 0} />
                <Count label="Archived" value={client.archived_matters_count ?? 0} />
                <Count label="Total" value={client.matters_count ?? 0} />
              </dl>
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <div>
                <CardTitle>Record management</CardTitle>
                <CardDescription>
                  {canDelete
                    ? "This client has no matters, so the record can be deleted."
                    : "Clients with matters are retained to keep legal records complete."}
                </CardDescription>
              </div>
            </CardHeader>
            <CardContent>
              <Button
                variant="secondary"
                size="sm"
                disabled={!canDelete}
                onClick={() => setConfirmDelete(true)}
                className="hover:text-danger"
              >
                <Trash2 aria-hidden />
                Delete client
              </Button>
            </CardContent>
          </Card>
        </div>

        <Card className="min-w-0 overflow-hidden">
          <CardHeader className="border-b border-border">
            <div>
              <CardTitle>Legal matters</CardTitle>
              <CardDescription>All matters for {client.name}, most recently updated first.</CardDescription>
            </div>
            {(client.matters_count ?? 0) > 0 ? (
              <Button variant="link" size="sm" asChild>
                <Link href={`/matters?search=${encodeURIComponent(client.name)}`}>Open in matters</Link>
              </Button>
            ) : null}
          </CardHeader>
          {matters.isPending ? (
            <TableSkeleton rows={4} columns={4} />
          ) : matters.isError ? (
            <ErrorState
              error={matters.error}
              title="We couldn't load matters"
              onRetry={() => matters.refetch()}
              retrying={matters.isRefetching}
            />
          ) : matters.data.data.length === 0 ? (
            <EmptyState
              icon={Briefcase}
              title="No matters yet"
              description="Open the first matter for this client."
              action={
                <Button size="sm" onClick={() => setCreatingMatter(true)}>
                  <Plus aria-hidden />
                  New matter
                </Button>
              }
            />
          ) : (
            <div className={cn("transition-opacity", matters.isPlaceholderData && "opacity-60")}>
              <DataTable
                caption={`Matters for ${client.name}`}
                rows={matters.data.data}
                getRowKey={(m) => m.id}
                columns={[
                  matterColumns.matter,
                  matterColumns.status,
                  matterColumns.priority,
                  matterColumns.deadline,
                  matterColumns.openTasks,
                ]}
                renderMobileItem={(m) => <MatterMobileItem matter={m} />}
              />
              <Pagination meta={matters.data.meta} noun="matters" disabled={matters.isPlaceholderData} onPageChange={setPage} />
            </div>
          )}
        </Card>
      </div>

      <ClientFormDialog open={editing} onOpenChange={setEditing} client={client} />
      <MatterFormDialog
        open={creatingMatter}
        onOpenChange={setCreatingMatter}
        defaultClientId={client.id}
        onSaved={(matter) => router.push(`/matters/${matter.id}`)}
      />
      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title={`Delete ${client.name}?`}
        description="The client record is removed permanently. This is only possible because the client has no matters."
        confirmLabel="Delete client"
        destructive
        pending={remove.isPending}
        onConfirm={async () => {
          try {
            await remove.mutateAsync(client.id);
            toast.success("Client deleted", client.name);
            router.push("/clients");
          } catch (error) {
            setConfirmDelete(false);
            toast.error("Client not deleted", getErrorMessage(error));
          }
        }}
      />
    </div>
  );
}

function ContactRow({ icon: Icon, label, children }: { icon: typeof Mail; label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-start gap-3">
      <Icon aria-hidden className="mt-0.5 size-4 shrink-0 text-subtle-foreground" />
      <div className="min-w-0">
        <dt className="text-xs text-subtle-foreground">{label}</dt>
        <dd className="mt-0.5">{children ?? <span className="text-subtle-foreground">Not provided</span>}</dd>
      </div>
    </div>
  );
}

function Count({ label, value, highlight }: { label: string; value: number; highlight?: boolean }) {
  return (
    <div className="rounded-lg border border-border bg-surface-muted/50 px-3 py-2.5">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className={cn("mt-0.5 text-lg font-semibold tabular-nums", highlight ? "text-primary" : "text-foreground")}>{value}</dd>
    </div>
  );
}
