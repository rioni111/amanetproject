import { Check, Circle, Eye, PenLine, Send, X } from "lucide-react";

import { Badge, type BadgeProps } from "@/components/ui/badge";
import { cn } from "@/lib/utils/cn";
import { formatDateTime } from "@/lib/utils/format";
import { SIGNING_STATUS_LABELS } from "@/lib/utils/labels";
import type { SigningRequest, SigningStatus } from "@/types/api";

const TONES: Record<SigningStatus, BadgeProps["tone"]> = {
  ready: "neutral",
  sent: "info",
  viewed: "info",
  started: "warning",
  signed: "primary",
  cancelled: "outline",
};

export function SigningStatusBadge({ status }: { status: SigningStatus }) {
  return (
    <Badge tone={TONES[status]} data-testid="signing-status">
      {SIGNING_STATUS_LABELS[status]}
    </Badge>
  );
}

/**
 * Sent → Viewed → Started → Signed, showing only milestones that actually happened (with their server
 * timestamps). Administrator signing has no invitation, view or start step.
 */
export function SigningProgress({ request }: { request: SigningRequest }) {
  const client = request.signer_type === "client";
  const steps = client
    ? [
        {
          key: "sent",
          label: "Sent",
          at: request.sent_at,
          icon: Send,
          done: "Invitation marked as shared by the administrator (outside the app).",
          pending: request.status === "ready" ? "Not shared yet — generate and copy the link, send it, then mark it as shared." : "Not confirmed as shared.",
        },
        { key: "viewed", label: "Viewed", at: request.viewed_at, icon: Eye, done: "The designated document was opened through the secure link.", pending: "Not opened yet." },
        { key: "started", label: "Started", at: request.started_at, icon: PenLine, done: "The signer began the signing process.", pending: "Not started yet." },
        { key: "signed", label: "Signed", at: request.signed_at, icon: Check, done: "The signed PDF was generated and securely saved.", pending: "Not signed yet." },
      ]
    : [{ key: "signed", label: "Signed", at: request.signed_at, icon: Check, done: "The administrator signed; the signed PDF was generated and securely saved.", pending: "Waiting for the administrator's signature." }];

  return (
    <ol aria-label="Signing progress" className="flex flex-col gap-3">
      {steps.map((step) => {
        const reached = step.at !== null;
        const Icon = reached ? step.icon : Circle;
        return (
          <li key={step.key} className="flex items-start gap-3" data-testid={`progress-${step.key}`} data-reached={reached ? "true" : "false"}>
            <span
              aria-hidden
              className={cn("mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full", reached ? "bg-primary-soft text-primary" : "bg-surface-muted text-subtle-foreground")}
            >
              <Icon className="size-3.5" />
            </span>
            <div className="min-w-0">
              <p className="text-[13px] font-medium text-foreground">
                {step.label}
                <span className="sr-only">{reached ? " — completed" : " — not reached"}</span>
                {reached ? <span className="ml-2 text-xs font-normal text-muted-foreground">{formatDateTime(step.at)}</span> : null}
              </p>
              <p className="text-xs text-muted-foreground">{reached ? step.done : step.pending}</p>
            </div>
          </li>
        );
      })}
      {request.status === "cancelled" ? (
        <li className="flex items-start gap-3" data-testid="progress-cancelled">
          <span aria-hidden className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-danger-soft text-danger">
            <X className="size-3.5" />
          </span>
          <div>
            <p className="text-[13px] font-medium text-foreground">
              Cancelled <span className="ml-2 text-xs font-normal text-muted-foreground">{formatDateTime(request.cancelled_at)}</span>
            </p>
            <p className="text-xs text-muted-foreground">{request.cancellation_label}</p>
          </div>
        </li>
      ) : null}
    </ol>
  );
}
