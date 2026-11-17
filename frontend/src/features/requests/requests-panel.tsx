"use client";

import { Inbox, Plus } from "lucide-react";
import { useRouter } from "next/navigation";
import * as React from "react";

import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { ListSkeleton } from "@/components/shared/loading-skeleton";
import { Button } from "@/components/ui/button";
import type { DocumentRequest, IssuedLink, Matter } from "@/types/api";

import { requestHref, useMatterRequests } from "./api";
import { LinkRevealDialog } from "./link-reveal-dialog";
import { RequestFormDialog } from "./request-form-dialog";
import { RequestList } from "./request-list";

/** "Client requests" tab of the matter workspace: outstanding requirements and their requests. */
export function RequestsPanel({ matter }: { matter: Matter }) {
  const requests = useMatterRequests(matter.id);
  const router = useRouter();
  const [creating, setCreating] = React.useState(false);
  const [issued, setIssued] = React.useState<{ request: DocumentRequest; link: IssuedLink } | null>(null);
  const readOnly = matter.status === "archived";
  const counts = matter.requests;

  const onCreated = (request: DocumentRequest, link: IssuedLink | null) => {
    if (link) setIssued({ request, link });
    else router.push(requestHref(request));
  };

  return (
    <section aria-labelledby="requests-heading" className="flex flex-col gap-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 id="requests-heading" className="text-[15px] font-semibold text-foreground">
            Client requests
          </h2>
          <p className="mt-0.5 text-[13px] text-muted-foreground">
            Ask {matter.client?.name ?? "the client"} for documents through secure upload links, then review what arrives.
          </p>
        </div>
        {!readOnly ? (
          <Button onClick={() => setCreating(true)}>
            <Plus aria-hidden />
            Request document
          </Button>
        ) : null}
      </div>

      {counts ? (
        <dl aria-label="Outstanding requirements" className="grid grid-cols-2 gap-3 sm:grid-cols-5">
          <Counter label="Awaiting client" value={counts.pending} />
          <Counter label="Awaiting review" value={counts.awaiting_review} tone={counts.awaiting_review > 0 ? "warning" : undefined} />
          <Counter label="Corrections requested" value={counts.correction_requested} />
          <Counter label="Overdue" value={counts.overdue} tone={counts.overdue > 0 ? "danger" : undefined} />
          <Counter label="Approved" value={counts.approved} />
        </dl>
      ) : null}

      <div className="overflow-hidden rounded-xl border border-border bg-surface">
        {requests.isPending ? (
          <div className="p-4">
            <ListSkeleton rows={3} />
          </div>
        ) : requests.isError ? (
          <ErrorState
            error={requests.error}
            title="We couldn't load client requests"
            onRetry={() => requests.refetch()}
            retrying={requests.isRefetching}
          />
        ) : requests.data.data.length === 0 ? (
          <EmptyState
            icon={Inbox}
            title="No documents have been requested from the client yet."
            description={readOnly ? "This matter is archived and read-only." : "Create a request to send the client a secure upload link."}
            action={
              !readOnly ? (
                <Button size="sm" onClick={() => setCreating(true)}>
                  <Plus aria-hidden />
                  Request the first document
                </Button>
              ) : undefined
            }
          />
        ) : (
          <RequestList requests={requests.data.data} label={`Client requests of ${matter.matter_number}`} />
        )}
      </div>

      <RequestFormDialog open={creating} onOpenChange={setCreating} matter={matter} onCreated={onCreated} />
      <LinkRevealDialog
        link={issued?.link ?? null}
        onClose={() => {
          const target = issued?.request;
          setIssued(null);
          if (target) router.push(requestHref(target));
        }}
        intro={
          issued ? (
            <p className="text-[13px] text-muted-foreground">
              The request <span className="font-medium text-foreground">{issued.request.title}</span> was created. Copy the link below and
              send it to the client.
            </p>
          ) : null
        }
      />
    </section>
  );
}

function Counter({ label, value, tone }: { label: string; value: number; tone?: "warning" | "danger" }) {
  return (
    <div className="rounded-lg border border-border bg-surface-muted/50 px-3 py-2.5">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd
        className={
          tone === "danger"
            ? "mt-0.5 text-lg font-semibold text-danger tabular-nums"
            : tone === "warning"
              ? "mt-0.5 text-lg font-semibold text-warning tabular-nums"
              : "mt-0.5 text-lg font-semibold text-foreground tabular-nums"
        }
      >
        {value}
      </dd>
    </div>
  );
}
