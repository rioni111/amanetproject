"use client";

import { AlertTriangle, CheckCircle2, ExternalLink, Loader2, Mail, RotateCcw } from "lucide-react";
import * as React from "react";

import { Button } from "@/components/ui/button";
import { Dialog, DialogClose, DialogContent, DialogFooter } from "@/components/ui/dialog";
import { Select } from "@/components/ui/select";
import { ApiError, getErrorMessage } from "@/lib/api/errors";
import { formatDateTime } from "@/lib/utils/format";
import type { EmailDelivery } from "@/types/api";

import { type EmailTarget, newIdempotencyKey, useCommunicationStatus, useSendEmail } from "./api";

const VALIDITY = [1, 3, 7, 14, 30] as const;

type Outcome =
  | { kind: "accepted"; delivery: EmailDelivery; replayed: boolean }
  | { kind: "failed"; message: string; delivery: EmailDelivery | null }
  | { kind: "error"; message: string };

/**
 * Confirm-and-send dialog for one request e-mail. The recipient shown is the one the SERVER will use (the
 * request's client / the designated signer) — it cannot be edited here. Feedback is honest: "accepted by the
 * configured mail server" only after the server says so; a failure says nothing was delivered.
 */
export function SendEmailDialog({
  open,
  onOpenChange,
  target,
  matterId,
  targetId,
  title,
  what,
  recipientName,
  recipientEmail,
  replacesLink,
  defaultValidity = 7,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  target: EmailTarget;
  matterId: number;
  targetId: number;
  title: string;
  /** One sentence: what the client receives. */
  what: string;
  recipientName: string;
  recipientEmail: string | null;
  /** A link was already issued or e-mailed: warn that it stops working once this e-mail is accepted. */
  replacesLink: boolean;
  defaultValidity?: number;
}) {
  const status = useCommunicationStatus();
  const send = useSendEmail(target, matterId, targetId);
  const [validity, setValidity] = React.useState(String(defaultValidity));
  const [outcome, setOutcome] = React.useState<Outcome | null>(null);
  const keyRef = React.useRef<string | null>(null);
  const available = status.data?.available ?? false;

  const close = (next: boolean) => {
    if (send.isPending) return;
    if (!next) {
      setOutcome(null);
      setValidity(String(defaultValidity));
      keyRef.current = null;
    }
    onOpenChange(next);
  };

  const doSend = async () => {
    // One key per attempt: a double click or network retry of THIS attempt never sends twice.
    keyRef.current ??= newIdempotencyKey();
    try {
      const result = await send.mutateAsync({ idempotency_key: keyRef.current, link_expires_in_days: Number(validity) });
      setOutcome({ kind: "accepted", delivery: result.data, replayed: Boolean(result.replayed) });
    } catch (error) {
      if (error instanceof ApiError && error.code === "email_failed") {
        setOutcome({ kind: "failed", message: error.message, delivery: (error.data as EmailDelivery | null) ?? null });
      } else {
        setOutcome({ kind: "error", message: getErrorMessage(error) });
      }
      keyRef.current = null; // a retry is a new attempt
    }
  };

  return (
    <Dialog open={open} onOpenChange={close}>
      <DialogContent title={title} description={what} className="sm:max-w-lg">
        <div className="flex flex-col gap-4 px-5 py-5" data-testid="send-email-dialog">
          <dl className="flex flex-col gap-2 rounded-lg border border-border bg-surface-muted/60 px-4 py-3 text-[13px]">
            <div className="flex flex-wrap justify-between gap-x-4 gap-y-1">
              <dt className="text-muted-foreground">To</dt>
              <dd className="min-w-0 text-right font-medium break-all text-foreground" data-testid="email-recipient">
                {recipientName}
                {recipientEmail ? <span className="font-normal text-muted-foreground"> &lt;{recipientEmail}&gt;</span> : null}
              </dd>
            </div>
            {status.data ? (
              <div className="flex flex-wrap justify-between gap-x-4 gap-y-1">
                <dt className="text-muted-foreground">From</dt>
                <dd className="min-w-0 text-right break-all text-foreground">
                  {status.data.from_name} &lt;{status.data.from_address}&gt;
                </dd>
              </div>
            ) : null}
          </dl>

          {!recipientEmail ? (
            <Notice tone="danger">
              No e-mail address is on file for this recipient. Copy the link and share it yourself instead.
            </Notice>
          ) : null}
          {status.isSuccess && !available ? (
            <Notice tone="danger" testId="email-unavailable">
              E-mail sending is unavailable: {status.data?.reason} You can still copy the link and share it yourself.
            </Notice>
          ) : null}
          {replacesLink && outcome === null ? (
            <Notice tone="warning">
              A new secure link is created for this e-mail. Earlier links for this request stop working once the mail server accepts it.
            </Notice>
          ) : null}

          {outcome === null ? (
            <label className="flex flex-col gap-1.5 text-[13px] font-medium text-foreground sm:max-w-56" htmlFor="email-link-validity">
              Link valid for
              <Select id="email-link-validity" value={validity} onChange={(e) => setValidity(e.target.value)} disabled={send.isPending}>
                {VALIDITY.map((d) => (
                  <option key={d} value={d}>
                    {d} {d === 1 ? "day" : "days"}
                  </option>
                ))}
              </Select>
            </label>
          ) : null}

          <div aria-live="polite" role="status" className="contents">
            {send.isPending ? (
              <p className="flex items-center gap-2 text-[13px] text-muted-foreground">
                <Loader2 aria-hidden className="size-4 animate-spin" />
                Sending to the mail server…
              </p>
            ) : null}
            {outcome?.kind === "accepted" ? (
              <div className="flex flex-col gap-1.5 rounded-lg border border-primary/25 bg-primary-soft px-4 py-3 text-[13px] text-primary-soft-foreground" data-testid="email-accepted">
                <p className="flex items-center gap-2 font-medium">
                  <CheckCircle2 aria-hidden className="size-4" />
                  {outcome.replayed ? "This e-mail was already accepted (repeated request)." : "E-mail accepted by the configured mail server."}
                </p>
                <p>
                  {formatDateTime(outcome.delivery.accepted_at)} · to {outcome.delivery.recipient_email}. This confirms the mail server took the message; it
                  does not prove it reached the inbox.
                </p>
                {status.data?.mail_ui_url ? (
                  <a href={status.data.mail_ui_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 font-medium underline">
                    Open the development inbox <ExternalLink aria-hidden className="size-3" />
                  </a>
                ) : null}
              </div>
            ) : null}
            {outcome?.kind === "failed" || outcome?.kind === "error" ? (
              <Notice tone="danger" testId="email-failed">
                {outcome.message}
              </Notice>
            ) : null}
          </div>
        </div>
        <DialogFooter>
          <DialogClose asChild>
            <Button variant="secondary" disabled={send.isPending}>
              {outcome?.kind === "accepted" ? "Done" : "Cancel"}
            </Button>
          </DialogClose>
          {outcome?.kind !== "accepted" ? (
            <Button onClick={() => void doSend()} disabled={send.isPending || !available || !recipientEmail}>
              {send.isPending ? <Loader2 aria-hidden className="animate-spin" /> : outcome ? <RotateCcw aria-hidden /> : <Mail aria-hidden />}
              {send.isPending ? "Sending…" : outcome ? "Try again" : "Send e-mail"}
            </Button>
          ) : null}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function Notice({ tone, children, testId }: { tone: "danger" | "warning"; children: React.ReactNode; testId?: string }) {
  return (
    <p
      role={tone === "danger" ? "alert" : undefined}
      data-testid={testId}
      className={
        tone === "danger"
          ? "flex items-start gap-2 rounded-lg border border-danger/25 bg-danger-soft px-3.5 py-2.5 text-[13px] text-danger"
          : "flex items-start gap-2 rounded-lg border border-warning/30 bg-warning-soft px-3.5 py-2.5 text-[13px] text-foreground"
      }
    >
      <AlertTriangle aria-hidden className="mt-0.5 size-4 shrink-0" />
      <span>{children}</span>
    </p>
  );
}
