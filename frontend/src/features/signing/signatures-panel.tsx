"use client";

import { PenLine, Plus } from "lucide-react";
import { useRouter } from "next/navigation";
import * as React from "react";

import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { ListSkeleton } from "@/components/shared/loading-skeleton";
import { Button } from "@/components/ui/button";
import { LinkRevealDialog } from "@/features/requests/link-reveal-dialog";
import type { IssuedLink, Matter, SigningRequest } from "@/types/api";

import { signingHref, useMatterSigningRequests } from "./api";
import { CreateSigningDialog } from "./create-signing-dialog";
import { SigningList } from "./signing-list";

/** "Signatures" tab of the matter workspace. Archived matters show their signing records read-only. */
export function SignaturesPanel({ matter }: { matter: Matter }) {
  const list = useMatterSigningRequests(matter.id);
  const router = useRouter();
  const [creating, setCreating] = React.useState(false);
  const [issued, setIssued] = React.useState<{ request: SigningRequest; link: IssuedLink } | null>(null);
  const readOnly = matter.status === "archived";
  const counts = matter.signatures;

  return (
    <section aria-labelledby="signatures-heading" className="flex flex-col gap-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 id="signatures-heading" className="text-[15px] font-semibold text-foreground">
            Signatures
          </h2>
          <p className="mt-0.5 text-[13px] text-muted-foreground">One signer per PDF. Signed PDFs are stored as new versions; originals never change.</p>
        </div>
        {!readOnly ? (
          <Button onClick={() => setCreating(true)}>
            <Plus aria-hidden />
            New signing request
          </Button>
        ) : null}
      </div>

      {counts ? (
        <dl aria-label="Signature figures" className="grid grid-cols-3 gap-3 sm:grid-cols-6">
          {(
            [
              ["Requests", counts.total],
              ["Pending", counts.active],
              ["Sent", counts.sent],
              ["Viewed", counts.viewed],
              ["Started", counts.started],
              ["Signed", counts.signed],
            ] as const
          ).map(([label, value]) => (
            <div key={label} className="rounded-lg border border-border bg-surface-muted/50 px-3 py-2.5">
              <dt className="text-xs text-muted-foreground">{label}</dt>
              <dd className="mt-0.5 text-lg font-semibold text-foreground tabular-nums">{value}</dd>
            </div>
          ))}
        </dl>
      ) : null}

      <div className="overflow-hidden rounded-xl border border-border bg-surface">
        {list.isPending ? (
          <div className="p-4">
            <ListSkeleton rows={3} />
          </div>
        ) : list.isError ? (
          <ErrorState error={list.error} title="We couldn't load signing requests" onRetry={() => list.refetch()} retrying={list.isRefetching} />
        ) : list.data.data.length === 0 ? (
          <EmptyState
            icon={PenLine}
            title="No signing requests yet."
            description={readOnly ? "This matter is archived and read-only." : "Ask the client to sign a PDF, or sign one yourself from the document page."}
            action={
              !readOnly ? (
                <Button size="sm" onClick={() => setCreating(true)}>
                  <Plus aria-hidden />
                  Create the first signing request
                </Button>
              ) : undefined
            }
          />
        ) : (
          <SigningList requests={list.data.data} label={`Signing requests of ${matter.matter_number}`} />
        )}
      </div>

      <CreateSigningDialog
        open={creating}
        onOpenChange={setCreating}
        matter={matter}
        onCreated={(request, link) => (link ? setIssued({ request, link }) : router.push(signingHref(request)))}
      />
      <LinkRevealDialog
        link={issued?.link ?? null}
        purpose="signing"
        title="Secure signing link"
        onClose={() => {
          const target = issued?.request;
          setIssued(null);
          if (target) router.push(signingHref(target));
        }}
        intro={
          issued ? (
            <p className="text-[13px] text-muted-foreground">
              Send this link to <span className="font-medium text-foreground">{issued.request.signer_name}</span>. After sending it, mark the
              invitation as shared on the request page so the progress shows “Sent”.
            </p>
          ) : null
        }
      />
    </section>
  );
}
