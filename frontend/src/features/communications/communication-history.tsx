"use client";

import { AlertTriangle, Ban, CheckCircle2, Clock, Loader2, Mail } from "lucide-react";

import { ErrorState } from "@/components/shared/error-state";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { formatDateTime } from "@/lib/utils/format";
import type { EmailDelivery } from "@/types/api";

import { type EmailTarget, useDeliveryHistory } from "./api";

/** Wording follows what SMTP can actually establish: "accepted by the mail server", never "delivered". */
function statusLine(d: EmailDelivery): { icon: typeof Mail; tone: string; text: string } {
  switch (d.status) {
    case "accepted":
      return { icon: CheckCircle2, tone: "text-primary", text: `Accepted by the mail server · ${formatDateTime(d.accepted_at)}` };
    case "failed":
      return { icon: AlertTriangle, tone: "text-danger", text: `Not sent · ${d.failure_message ?? "The e-mail could not be sent."}` };
    case "sending":
      return { icon: Loader2, tone: "text-muted-foreground", text: "Sending…" };
    case "skipped":
    case "cancelled":
      return { icon: Ban, tone: "text-muted-foreground", text: d.failure_message ?? "Not sent." };
    default:
      return { icon: Clock, tone: "text-muted-foreground", text: d.status_label };
  }
}

export function CommunicationHistory({ target, matterId, id }: { target: EmailTarget; matterId: number; id: number }) {
  const history = useDeliveryHistory(target, matterId, id);

  return (
    <Card data-testid="communication-history">
      <CardHeader>
        <CardTitle>E-mail history</CardTitle>
      </CardHeader>
      <CardContent>
        {history.isPending ? (
          <div className="flex flex-col gap-2" aria-busy>
            <Skeleton className="h-12 w-full" />
            <Skeleton className="h-12 w-full" />
          </div>
        ) : history.isError ? (
          <ErrorState title="E-mail history could not be loaded" error={history.error} onRetry={() => void history.refetch()} />
        ) : history.data.length === 0 ? (
          <p className="text-[13px] text-muted-foreground">No e-mails yet. Links shared by copying them do not appear here.</p>
        ) : (
          <ol className="flex flex-col divide-y divide-border" aria-label="E-mail history">
            {history.data.map((d) => {
              const s = statusLine(d);
              const Icon = s.icon;
              return (
                <li key={d.id} className="flex flex-col gap-1 py-3 first:pt-0 last:pb-0" data-testid="communication-item" data-status={d.status}>
                  <p className="flex flex-wrap items-center gap-x-2 text-[13px] font-medium text-foreground">
                    <Mail aria-hidden className="size-3.5 text-muted-foreground" />
                    {d.communication_label}
                    {d.initiated_by === "automatic" ? (
                      <span className="rounded bg-surface-muted px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground">automatic</span>
                    ) : null}
                  </p>
                  <p className="text-xs break-all text-muted-foreground">
                    To {d.recipient_name} &lt;{d.recipient_email}&gt;
                    {d.created_by ? ` · by ${d.created_by.name}` : d.initiated_by === "automatic" ? " · by the reminder schedule" : ""}
                    {d.last_attempt_at ? ` · ${formatDateTime(d.last_attempt_at)}` : ""}
                  </p>
                  <p className={`flex items-start gap-1.5 text-xs font-medium ${s.tone}`}>
                    <Icon aria-hidden className={`mt-px size-3.5 shrink-0 ${d.status === "sending" ? "animate-spin" : ""}`} />
                    <span>{s.text}</span>
                  </p>
                  {d.link ? (
                    <p className="text-[11px] text-subtle-foreground">
                      Link in this e-mail: {d.link.state}
                      {d.link.state === "active" ? ` until ${formatDateTime(d.link.expires_at)}` : ""}
                      {d.provider_message_id ? ` · server id ${d.provider_message_id}` : ""}
                    </p>
                  ) : null}
                </li>
              );
            })}
          </ol>
        )}
      </CardContent>
    </Card>
  );
}
