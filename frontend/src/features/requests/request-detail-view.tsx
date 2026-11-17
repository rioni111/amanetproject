"use client";

import {
  AlertTriangle,
  ArrowLeft,
  Ban,
  CheckCircle2,
  Download,
  Eye,
  FileSearch,
  FileText,
  Link2,
  Link2Off,
  Loader2,
  Mail,
  MessageSquareQuote,
  Pencil,
  RefreshCw,
  ShieldCheck,
  Undo2,
} from "lucide-react";
import Link from "next/link";
import * as React from "react";

import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { FormAlert } from "@/components/shared/form-alert";
import { FormField } from "@/components/shared/form-field";
import { useToast } from "@/components/shared/toaster";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog, DialogClose, DialogContent, DialogFooter } from "@/components/ui/dialog";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { CommunicationHistory } from "@/features/communications/communication-history";
import { SendEmailDialog } from "@/features/communications/send-email-dialog";
import { useMatter } from "@/features/matters/api";
import { downloadVersion } from "@/features/documents/api";
import { documentHref } from "@/features/documents/document-list";
import { PdfViewerDialog } from "@/features/documents/pdf-viewer-dialog";
import { ApiError, getErrorMessage } from "@/lib/api/errors";
import { useSubmitGuard } from "@/lib/forms";
import { formatDate, formatDateTime, formatFileSize } from "@/lib/utils/format";
import type { DocumentRequest, DocumentRequestSubmission, IssuedLink, Matter, SecureLink } from "@/types/api";

import {
  useCancelRequest,
  useDocumentRequest,
  useIssueLink,
  useRequestCorrection,
  useRequestSubmissions,
  useReviewAction,
  useRevokeLink,
  type LinkAction,
} from "./api";
import { LinkRevealDialog } from "./link-reveal-dialog";
import { LinkStateBadge, RequestStatusBadge, ReviewStatusBadge } from "./request-badges";
import { LINK_VALIDITY_OPTIONS, RequestFormDialog } from "./request-form-dialog";

export function RequestDetailView({ matterId, requestId }: { matterId: number; requestId: number }) {
  const detail = useDocumentRequest(matterId, requestId);
  const matter = useMatter(matterId);

  if (detail.isPending || matter.isPending) return <DetailSkeleton />;
  if (detail.isError || matter.isError) {
    const error = detail.error ?? matter.error;
    if (error instanceof ApiError && error.status === 404) {
      return (
        <Card>
          <EmptyState
            icon={FileSearch}
            title="Request not found"
            description="It may belong to another matter, or you followed an outdated link."
            action={
              <Button variant="secondary" size="sm" asChild>
                <Link href="/document-requests">All client requests</Link>
              </Button>
            }
          />
        </Card>
      );
    }
    return (
      <Card>
        <ErrorState
          error={error}
          title="We couldn't load this request"
          onRetry={() => {
            void detail.refetch();
            void matter.refetch();
          }}
        />
      </Card>
    );
  }
  return <RequestWorkspace request={detail.data.data} links={detail.data.links} matter={matter.data} />;
}

function RequestWorkspace({ request, links, matter }: { request: DocumentRequest; links: SecureLink[]; matter: Matter }) {
  const toast = useToast();
  const [editing, setEditing] = React.useState(false);
  const [cancelling, setCancelling] = React.useState(false);
  const [emailing, setEmailing] = React.useState(false);
  const [issued, setIssued] = React.useState<{ link: IssuedLink; correction: boolean } | null>(null);
  const cancel = useCancelRequest(request.matter_id, request.id);
  const archived = matter.status === "archived";

  const doCancel = () =>
    cancel.mutate(
      { lock_version: request.lock_version },
      {
        onSuccess: () => {
          toast.success("Request cancelled", "The upload link no longer works.");
          setCancelling(false);
        },
        onError: (error) => {
          toast.error("The request could not be cancelled", getErrorMessage(error));
          setCancelling(false);
        },
      },
    );

  return (
    <div className="flex flex-col gap-6">
      <div>
        <Link
          href={`/matters/${request.matter_id}?tab=requests`}
          className="inline-flex items-center gap-1.5 text-[13px] font-medium text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft aria-hidden className="size-3.5" />
          {matter.matter_number} · Client requests
        </Link>
      </div>

      <header className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="min-w-0">
          <p className="text-xs font-medium tracking-wide text-subtle-foreground uppercase">Client document request</p>
          <h1 className="mt-1 text-[26px] leading-tight font-semibold tracking-[-0.02em] break-words text-foreground">{request.title}</h1>
          <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-[13px] text-muted-foreground">
            <RequestStatusBadge status={request.status} />
            {request.is_overdue ? (
              <span className="inline-flex items-center gap-1 font-medium text-danger">
                <AlertTriangle aria-hidden className="size-3.5" />
                Overdue
              </span>
            ) : null}
            <Link href={`/matters/${matter.id}`} className="font-medium text-foreground hover:text-primary hover:underline">
              <span className="font-mono">{matter.matter_number}</span> · {matter.title}
            </Link>
            {request.client ? <span>{request.client.name}</span> : null}
            <span>{request.requested_category_label}</span>
            <span>{request.due_date ? `Due ${formatDate(request.due_date)}` : "No due date"}</span>
          </div>
        </div>
        <div className="flex shrink-0 flex-wrap gap-2">
          {request.allowed_actions.send_email ? (
            <Button onClick={() => setEmailing(true)}>
              <Mail aria-hidden />
              {request.status === "correction_requested" ? "E-mail correction" : "Send e-mail"}
            </Button>
          ) : null}
          <Button variant="secondary" onClick={() => setEditing(true)} disabled={!request.allowed_actions.edit}>
            <Pencil aria-hidden />
            Edit
          </Button>
          <Button variant="secondary" onClick={() => setCancelling(true)} disabled={!request.allowed_actions.cancel}>
            <Ban aria-hidden />
            Cancel request
          </Button>
        </div>
      </header>

      {archived ? (
        <div role="status" className="flex items-start gap-2.5 rounded-lg border border-border-strong bg-surface-muted px-4 py-3 text-[13px] text-muted-foreground">
          <AlertTriangle aria-hidden className="mt-px size-4 shrink-0" />
          <span>This matter is archived and read-only. Client uploads are refused and its upload links were revoked.</span>
        </div>
      ) : null}

      {request.status === "approved" && request.approved_version ? (
        <div role="status" className="flex items-start gap-2.5 rounded-lg border border-primary/20 bg-primary-soft px-4 py-3 text-[13px] text-primary-soft-foreground" data-testid="approved-banner">
          <CheckCircle2 aria-hidden className="mt-px size-4 shrink-0" />
          <span>
            Approved {request.approved_at ? formatDateTime(request.approved_at) : ""}
            {request.reviewed_by ? ` by ${request.reviewed_by.name}` : ""} — version {request.approved_version.label} was accepted. Approval
            records that the requested document was received in order; it is not a signature.
          </span>
        </div>
      ) : null}

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div className="flex min-w-0 flex-col gap-6">
          <ReviewCard request={request} onIssued={(link) => setIssued({ link, correction: true })} />
          <SubmissionHistory request={request} />
          <CommunicationHistory target="document-requests" matterId={request.matter_id} id={request.id} />
        </div>
        <div className="flex min-w-0 flex-col gap-6">
          <Card>
            <CardHeader>
              <CardTitle>Instructions to the client</CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-[13px] whitespace-pre-wrap text-foreground">{request.instructions}</p>
            </CardContent>
          </Card>
          <LinkCard request={request} links={links} onIssued={(link) => setIssued({ link, correction: false })} />
          <Card>
            <CardHeader>
              <CardTitle>Details</CardTitle>
            </CardHeader>
            <CardContent>
              <dl className="flex flex-col gap-2 text-[13px]">
                <Row label="Requested by" value={request.created_by?.name ?? "—"} />
                <Row label="Created" value={formatDateTime(request.created_at)} />
                <Row label="Submissions" value={String(request.submissions_count)} />
                {request.document ? (
                  <Row
                    label="Document"
                    value={
                      <Link href={documentHref({ id: request.document.id, matter_id: request.matter_id })} className="text-primary hover:underline">
                        {request.document.title} ({request.document.versions_count} {request.document.versions_count === 1 ? "version" : "versions"})
                      </Link>
                    }
                  />
                ) : null}
                {request.cancelled_at ? <Row label="Cancelled" value={formatDateTime(request.cancelled_at)} /> : null}
              </dl>
            </CardContent>
          </Card>
        </div>
      </div>

      <RequestFormDialog open={editing} onOpenChange={setEditing} matter={matter} request={request} />
      <SendEmailDialog
        open={emailing}
        onOpenChange={setEmailing}
        target="document-requests"
        matterId={request.matter_id}
        targetId={request.id}
        title={request.status === "correction_requested" ? "E-mail the correction request" : "E-mail the document request"}
        what={
          request.status === "correction_requested"
            ? "The client receives a secure link to a page that shows your correction instructions and accepts the corrected PDF."
            : "The client receives the request details and a secure link to upload the PDF. No account is needed."
        }
        recipientName={request.client?.name ?? "The client"}
        recipientEmail={request.client?.email ?? null}
        replacesLink={links.length > 0}
      />
      <ConfirmDialog
        open={cancelling}
        onOpenChange={setCancelling}
        title="Cancel this request?"
        description="The client will no longer be able to upload a file for it, and any active upload link stops working immediately. Files already submitted stay in the matter. This cannot be undone."
        confirmLabel="Cancel request"
        cancelLabel="Keep request"
        destructive
        pending={cancel.isPending}
        onConfirm={doCancel}
      />
      <LinkRevealDialog
        link={issued?.link ?? null}
        onClose={() => setIssued(null)}
        title={issued?.correction ? "Correction link" : "Secure upload link"}
        intro={
          issued?.correction ? (
            <p className="text-[13px] text-muted-foreground">
              The correction request was recorded. Send this new link to the client: it shows your correction instructions and accepts one
              corrected PDF, which will be stored as the next version. Earlier versions are kept.
            </p>
          ) : null
        }
      />
    </div>
  );
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="min-w-0 text-right font-medium break-words text-foreground">{value}</dd>
    </div>
  );
}

// ------------------------------------------------------------------ review

function ReviewCard({ request, onIssued }: { request: DocumentRequest; onIssued: (link: IssuedLink) => void }) {
  const submission = request.latest_submission;
  const toast = useToast();
  const review = useReviewAction(request.matter_id, request.id);
  const [approving, setApproving] = React.useState(false);
  const [correcting, setCorrecting] = React.useState(false);
  const [previewing, setPreviewing] = React.useState(false);
  const [downloading, setDownloading] = React.useState(false);

  if (!submission || !submission.version) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>Client submission</CardTitle>
        </CardHeader>
        <CardContent>
          <p className="text-[13px] text-muted-foreground" data-testid="no-submission">
            {request.status === "cancelled"
              ? "This request was cancelled before the client submitted a file."
              : "Nothing has been submitted yet. The client uploads through the secure link; the file then appears here for review."}
          </p>
        </CardContent>
      </Card>
    );
  }
  const version = submission.version;

  const act = (action: "start-review" | "approve") =>
    review.mutate(
      { action, submission_id: submission.id, lock_version: request.lock_version },
      {
        onSuccess: () => {
          toast.success(action === "approve" ? "Document approved" : "Review started", request.title);
          setApproving(false);
        },
        onError: (error) => {
          toast.error(action === "approve" ? "The document could not be approved" : "The review could not be started", getErrorMessage(error));
          setApproving(false);
        },
      },
    );

  const download = async () => {
    setDownloading(true);
    try {
      await downloadVersion(request.matter_id, submission.document_id, version);
    } catch (error) {
      toast.error("The download failed", getErrorMessage(error));
    } finally {
      setDownloading(false);
    }
  };

  return (
    <Card data-testid="review-card">
      <CardHeader className="flex flex-row items-start justify-between gap-3">
        <div>
          <CardTitle>Latest submission</CardTitle>
          <p className="mt-1 text-[13px] text-muted-foreground">
            Submission {submission.submission_number} · {formatDateTime(submission.submitted_at)} · by {submission.submitted_by} (client)
          </p>
        </div>
        <ReviewStatusBadge status={submission.review_status} />
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div className="flex items-center gap-3 rounded-lg border border-border bg-surface-muted/50 px-3.5 py-3">
          <FileText aria-hidden className="size-8 shrink-0 text-primary" />
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-medium text-foreground" data-testid="submission-file">
              {version.original_filename}
            </p>
            <p className="text-xs text-muted-foreground">
              Version {version.label} · {formatFileSize(version.file_size)} · SHA-256 {version.sha256_checksum.slice(0, 12)}…
            </p>
          </div>
        </div>

        {submission.client_message ? (
          <figure className="flex gap-2.5 rounded-lg border border-border px-3.5 py-3 text-[13px]">
            <MessageSquareQuote aria-hidden className="mt-0.5 size-4 shrink-0 text-subtle-foreground" />
            <div>
              <figcaption className="text-xs font-medium text-muted-foreground">Message from the client</figcaption>
              <blockquote className="mt-0.5 whitespace-pre-wrap text-foreground">{submission.client_message}</blockquote>
            </div>
          </figure>
        ) : null}

        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" onClick={() => setPreviewing(true)}>
            <Eye aria-hidden />
            Preview {version.label}
          </Button>
          <Button variant="secondary" onClick={() => void download()} disabled={downloading}>
            {downloading ? <Loader2 aria-hidden className="animate-spin" /> : <Download aria-hidden />}
            Download
          </Button>
          {request.allowed_actions.start_review ? (
            <Button onClick={() => act("start-review")} disabled={review.isPending}>
              {review.isPending ? <Loader2 aria-hidden className="animate-spin" /> : <ShieldCheck aria-hidden />}
              Start review
            </Button>
          ) : null}
          {request.allowed_actions.approve ? (
            <Button onClick={() => setApproving(true)} disabled={review.isPending}>
              <CheckCircle2 aria-hidden />
              Approve
            </Button>
          ) : null}
          {request.allowed_actions.request_correction ? (
            <Button variant="secondary" onClick={() => setCorrecting(true)} disabled={review.isPending}>
              <Undo2 aria-hidden />
              Request correction
            </Button>
          ) : null}
        </div>
      </CardContent>

      <PdfViewerDialog
        open={previewing}
        onOpenChange={setPreviewing}
        matterId={request.matter_id}
        documentId={submission.document_id}
        title={request.title}
        version={version}
        onDownload={() => void download()}
      />
      <ConfirmDialog
        open={approving}
        onOpenChange={setApproving}
        title={`Approve ${version.label}?`}
        description={
          <>
            <p>
              This records that <strong>{version.original_filename}</strong> ({version.label}) fulfils the request and closes it. Any upload
              link for this request stops working.
            </p>
            <p className="mt-2">Approval is not a signature — the PDF is not changed.</p>
          </>
        }
        confirmLabel="Approve document"
        pending={review.isPending}
        onConfirm={() => act("approve")}
      />
      <CorrectionDialog
        open={correcting}
        onOpenChange={setCorrecting}
        request={request}
        submission={submission}
        onIssued={(link) => {
          setCorrecting(false);
          onIssued(link);
        }}
      />
    </Card>
  );
}

function CorrectionDialog({
  open,
  onOpenChange,
  request,
  submission,
  onIssued,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  request: DocumentRequest;
  submission: DocumentRequestSubmission;
  onIssued: (link: IssuedLink) => void;
}) {
  const correction = useRequestCorrection(request.matter_id, request.id);
  const guard = useSubmitGuard();
  const toast = useToast();
  const [reason, setReason] = React.useState("");
  const [byEmail, setByEmail] = React.useState(false);
  const [validity, setValidity] = React.useState("7");
  const [error, setError] = React.useState<string | null>(null);
  const [formError, setFormError] = React.useState<string | null>(null);

  const wasOpen = React.useRef(false);
  React.useEffect(() => {
    if (open && !wasOpen.current) {
      setReason("");
      setByEmail(false);
      setValidity("7");
      setError(null);
      setFormError(null);
    }
    wasOpen.current = open;
  }, [open]);

  const submit = (event: React.FormEvent) => {
    event.preventDefault();
    void guard(async () => {
      const text = reason.trim();
      if (text.length < 10) {
        setError("Give the client a clear reason (at least 10 characters).");
        return;
      }
      if (text.length > 2000) {
        setError("Keep the reason under 2,000 characters.");
        return;
      }
      setError(null);
      setFormError(null);
      try {
        const result = await correction.mutateAsync({
          submission_id: submission.id,
          lock_version: request.lock_version,
          reason: text,
          expires_in_days: Number(validity),
          send_email: byEmail,
        });
        if (result.email) {
          // The decision is recorded either way; the e-mail outcome is reported honestly.
          if (result.email.status === "accepted") toast.success("Correction requested", result.email.message);
          else toast.error("Correction requested — e-mail not sent", result.email.message);
          onOpenChange(false);
        } else if (result.link) onIssued(result.link);
      } catch (err) {
        if (err instanceof ApiError && err.isValidation && err.field("reason")) setError(err.field("reason") ?? null);
        else setFormError(getErrorMessage(err));
      }
    });
  };

  return (
    <Dialog open={open} onOpenChange={(next) => (!correction.isPending ? onOpenChange(next) : undefined)}>
      <DialogContent
        title="Request a correction"
        description={`Submission ${submission.submission_number} (${submission.version?.label ?? ""}) is kept. A new secure link lets the client upload a corrected PDF as the next version.`}
      >
        <form onSubmit={submit} noValidate aria-label="Request correction">
          <div className="flex flex-col gap-5 px-5 py-5">
            <FormAlert message={formError} />
            <FormField
              id="correction-reason"
              label="What needs to be corrected?"
              required
              error={error ?? undefined}
              hint="The client sees this text on the new upload page. It is not added to the matter history."
            >
              {(aria) => (
                <Textarea
                  {...aria}
                  aria-required
                  rows={4}
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  placeholder="e.g. Please upload the agreement with all pages included."
                  disabled={correction.isPending}
                />
              )}
            </FormField>
            <label className="flex items-start gap-2.5 text-[13px] text-foreground" htmlFor="correction-by-email">
              <input
                id="correction-by-email"
                type="checkbox"
                className="mt-0.5 size-4 accent-[var(--primary)]"
                checked={byEmail}
                onChange={(e) => setByEmail(e.target.checked)}
                disabled={correction.isPending || !request.client?.email}
              />
              <span>
                <span className="font-medium">E-mail the correction request to the client</span>
                <span className="block text-xs text-muted-foreground">
                  {request.client?.email
                    ? `Sent to ${request.client.email} with a new secure link. If the e-mail fails, the correction still stands and you can resend it.`
                    : "No e-mail address is on file for this client."}
                </span>
              </span>
            </label>
            <FormField id="correction-validity" label="New link valid for" className="sm:max-w-48">
              {(aria) => (
                <Select {...aria} value={validity} onChange={(e) => setValidity(e.target.value)} disabled={correction.isPending}>
                  {LINK_VALIDITY_OPTIONS.map((days) => (
                    <option key={days} value={days}>
                      {days} {days === 1 ? "day" : "days"}
                    </option>
                  ))}
                </Select>
              )}
            </FormField>
          </div>
          <DialogFooter>
            <DialogClose asChild>
              <Button variant="secondary" disabled={correction.isPending}>
                Cancel
              </Button>
            </DialogClose>
            <Button type="submit" disabled={correction.isPending}>
              {correction.isPending ? <Loader2 aria-hidden className="animate-spin" /> : <Undo2 aria-hidden />}
              {correction.isPending ? "Saving…" : "Request correction"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

// ------------------------------------------------------------------ history

function SubmissionHistory({ request }: { request: DocumentRequest }) {
  const submissions = useRequestSubmissions(request.matter_id, request.id);
  const [preview, setPreview] = React.useState<DocumentRequestSubmission | null>(null);
  const items = submissions.data?.data ?? [];

  return (
    <Card>
      <CardHeader>
        <CardTitle>Submission history</CardTitle>
      </CardHeader>
      <CardContent>
        {submissions.isPending ? (
          <Skeleton className="h-16 w-full" />
        ) : submissions.isError ? (
          <ErrorState error={submissions.error} title="We couldn't load the submissions" onRetry={() => submissions.refetch()} />
        ) : items.length === 0 ? (
          <p className="text-[13px] text-muted-foreground">No submissions yet.</p>
        ) : (
          <ol aria-label="Submissions" className="flex flex-col divide-y divide-border">
            {items.map((s) => (
              <li key={s.id} data-testid="submission-row" className="flex flex-col gap-1.5 py-3 first:pt-0 last:pb-0">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="text-sm font-medium text-foreground">
                    Submission {s.submission_number} · {s.version?.label}
                    <span className="ml-2 text-xs font-normal text-muted-foreground">{formatDateTime(s.submitted_at)}</span>
                  </p>
                  <div className="flex items-center gap-2">
                    <ReviewStatusBadge status={s.review_status} />
                    {s.version ? (
                      <Button variant="ghost" size="sm" onClick={() => setPreview(s)} aria-label={`Preview submission ${s.submission_number} (${s.version.label})`}>
                        <Eye aria-hidden />
                        Preview
                      </Button>
                    ) : null}
                  </div>
                </div>
                <p className="text-xs text-muted-foreground">
                  {s.version?.original_filename} · {formatFileSize(s.version?.file_size)}
                  {s.reviewed_by ? ` · decided by ${s.reviewed_by.name}${s.reviewed_at ? ` on ${formatDateTime(s.reviewed_at)}` : ""}` : ""}
                </p>
                {s.correction_reason ? (
                  <p className="rounded-md bg-warning-soft px-2.5 py-1.5 text-xs text-warning">Correction requested: {s.correction_reason}</p>
                ) : null}
              </li>
            ))}
          </ol>
        )}
      </CardContent>
      {preview?.version ? (
        <PdfViewerDialog
          open
          onOpenChange={(open) => (!open ? setPreview(null) : undefined)}
          matterId={request.matter_id}
          documentId={preview.document_id}
          title={request.title}
          version={preview.version}
        />
      ) : null}
    </Card>
  );
}

// ------------------------------------------------------------------ links

function LinkCard({ request, links, onIssued }: { request: DocumentRequest; links: SecureLink[]; onIssued: (link: IssuedLink) => void }) {
  const toast = useToast();
  const issue = useIssueLink(request.matter_id, request.id);
  const revoke = useRevokeLink(request.matter_id, request.id);
  const [confirm, setConfirm] = React.useState<"rotate" | "revoke" | null>(null);
  const [validity, setValidity] = React.useState("7");
  const active = request.active_link;
  const usable = active?.state === "active";

  const doIssue = (action: LinkAction) =>
    issue.mutate(
      { action, expires_in_days: Number(validity) },
      {
        onSuccess: (result) => {
          setConfirm(null);
          if (result.link) onIssued(result.link);
        },
        onError: (error) => {
          setConfirm(null);
          toast.error("The link could not be generated", getErrorMessage(error));
        },
      },
    );

  const doRevoke = () => {
    if (!active) return;
    revoke.mutate(active.id, {
      onSuccess: () => {
        setConfirm(null);
        toast.success("Link revoked", "It no longer works for the client.");
      },
      onError: (error) => {
        setConfirm(null);
        toast.error("The link could not be revoked", getErrorMessage(error));
      },
    });
  };

  return (
    <Card data-testid="link-card">
      <CardHeader>
        <CardTitle>Secure upload link</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {active ? (
          <div className="flex flex-col gap-1.5 text-[13px]">
            <div className="flex items-center justify-between gap-2">
              <span className="text-muted-foreground">Current link</span>
              <LinkStateBadge state={active.state} />
            </div>
            <p className="text-xs text-muted-foreground">
              {active.state === "expired" ? "Expired" : "Valid until"} {formatDateTime(active.expires_at)}
              {active.first_opened_at ? ` · opened by the client ${formatDateTime(active.first_opened_at)}` : " · not opened yet"}
            </p>
          </div>
        ) : (
          <p className="text-[13px] text-muted-foreground" data-testid="no-active-link">
            {request.status === "submitted" || request.status === "under_review"
              ? "The client has submitted a file; their link was used. Request a correction to send a new one."
              : request.is_open
                ? "No active link. Generate one to let the client upload."
                : "This request is closed; no upload link is active."}
          </p>
        )}

        {request.allowed_actions.issue_link || request.allowed_actions.rotate_link ? (
          <div className="flex flex-col gap-2">
            <label htmlFor="link-validity" className="text-xs text-muted-foreground">
              Validity of a new link
            </label>
            <Select id="link-validity" value={validity} onChange={(e) => setValidity(e.target.value)} className="h-8">
              {LINK_VALIDITY_OPTIONS.map((days) => (
                <option key={days} value={days}>
                  {days} {days === 1 ? "day" : "days"}
                </option>
              ))}
            </Select>
            {usable ? (
              <Button variant="secondary" onClick={() => setConfirm("rotate")} disabled={issue.isPending}>
                <RefreshCw aria-hidden />
                Generate replacement link
              </Button>
            ) : (
              <Button onClick={() => doIssue("issue")} disabled={issue.isPending || !request.allowed_actions.issue_link}>
                {issue.isPending ? <Loader2 aria-hidden className="animate-spin" /> : <Link2 aria-hidden />}
                Generate upload link
              </Button>
            )}
          </div>
        ) : null}
        {request.allowed_actions.revoke_link && active ? (
          <Button variant="ghost" className="text-danger hover:text-danger" onClick={() => setConfirm("revoke")} disabled={revoke.isPending}>
            <Link2Off aria-hidden />
            Revoke link
          </Button>
        ) : null}

        <p className="text-xs text-subtle-foreground">
          Links are shown once when generated and are not emailed automatically. Each link allows one PDF upload for this request only.
        </p>

        {links.length > 0 ? (
          <details className="text-xs">
            <summary className="cursor-pointer font-medium text-muted-foreground">Link history ({links.length})</summary>
            <ul className="mt-2 flex flex-col gap-1.5" aria-label="Link history">
              {links.map((l) => (
                <li key={l.id} className="flex flex-wrap items-center justify-between gap-2">
                  <span className="text-muted-foreground">
                    {formatDateTime(l.created_at)}
                    {l.revocation_label ? ` · ${l.revocation_label}` : ""}
                  </span>
                  <LinkStateBadge state={l.state} />
                </li>
              ))}
            </ul>
          </details>
        ) : null}
      </CardContent>

      <ConfirmDialog
        open={confirm === "rotate"}
        onOpenChange={(open) => setConfirm(open ? "rotate" : null)}
        title="Replace the upload link?"
        description="The current link stops working immediately. You will get a new link to send to the client."
        confirmLabel="Generate replacement"
        pending={issue.isPending}
        onConfirm={() => doIssue("rotate")}
      />
      <ConfirmDialog
        open={confirm === "revoke"}
        onOpenChange={(open) => setConfirm(open ? "revoke" : null)}
        title="Revoke the upload link?"
        description="The client will no longer be able to upload with it. You can generate a new link later while the request is open."
        confirmLabel="Revoke link"
        destructive
        pending={revoke.isPending}
        onConfirm={doRevoke}
      />
    </Card>
  );
}

function DetailSkeleton() {
  return (
    <div className="flex flex-col gap-6" role="status" aria-label="Loading request">
      <Skeleton className="h-3 w-40" />
      <Skeleton className="h-7 w-2/3" />
      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        <Skeleton className="h-56 w-full rounded-xl" />
        <Skeleton className="h-56 w-full rounded-xl" />
      </div>
      <span className="sr-only">Loading request…</span>
    </div>
  );
}
