"use client";

import { ArrowLeft, Ban, CheckCircle2, Download, Eye, FileSearch, Link2, Link2Off, Loader2, PenLine, RefreshCw, Send, ShieldCheck } from "lucide-react";
import Link from "next/link";
import * as React from "react";

import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { useToast } from "@/components/shared/toaster";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { downloadVersion } from "@/features/documents/api";
import { documentHref } from "@/features/documents/document-list";
import { PdfViewerDialog } from "@/features/documents/pdf-viewer-dialog";
import { LinkRevealDialog } from "@/features/requests/link-reveal-dialog";
import { LinkStateBadge } from "@/features/requests/request-badges";
import { ApiError, getErrorMessage } from "@/lib/api/errors";
import { formatDateTime, formatFileSize } from "@/lib/utils/format";
import type { DocumentVersion, IssuedLink, SecureLink, SignatureEvidence, SigningRequest } from "@/types/api";

import { AdminSignDialog } from "./admin-sign-dialog";
import { useRevokeSigningLink, useSigningAction, useSigningLink, useSigningRequest } from "./api";
import { SigningProgress, SigningStatusBadge } from "./signing-badges";

export function SigningDetailView({ matterId, id }: { matterId: number; id: number }) {
  const detail = useSigningRequest(matterId, id);
  if (detail.isPending) {
    return (
      <div className="flex flex-col gap-4" role="status" aria-label="Loading signing request">
        <Skeleton className="h-7 w-2/3" />
        <Skeleton className="h-64 w-full rounded-xl" />
      </div>
    );
  }
  if (detail.isError) {
    return (
      <Card>
        {detail.error instanceof ApiError && detail.error.status === 404 ? (
          <EmptyState icon={FileSearch} title="Signing request not found" description="It may belong to another matter, or the link is outdated." action={<Button variant="secondary" size="sm" asChild><Link href="/signatures">All signatures</Link></Button>} />
        ) : (
          <ErrorState error={detail.error} title="We couldn't load this signing request" onRetry={() => detail.refetch()} />
        )}
      </Card>
    );
  }
  return <SigningWorkspace request={detail.data.data} links={detail.data.links} evidence={detail.data.evidence} />;
}

function SigningWorkspace({ request, links, evidence }: { request: SigningRequest; links: SecureLink[]; evidence: SignatureEvidence | null }) {
  const toast = useToast();
  const action = useSigningAction(request.matter_id, request.id);
  const [confirm, setConfirm] = React.useState<"cancel" | "share" | null>(null);
  const [issued, setIssued] = React.useState<IssuedLink | null>(null);
  const [preview, setPreview] = React.useState<DocumentVersion | null>(null);
  const [signing, setSigning] = React.useState(false);
  const [downloading, setDownloading] = React.useState<number | null>(null);
  const client = request.signer_type === "client";

  const run = (kind: "cancel" | "mark-shared") =>
    action.mutate(
      { action: kind, lock_version: request.lock_version },
      {
        onSuccess: () => {
          toast.success(kind === "cancel" ? "Signing request cancelled" : "Marked as shared", kind === "cancel" ? "Its link no longer works." : "Progress now shows “Sent”.");
          setConfirm(null);
        },
        onError: (error) => {
          toast.error(kind === "cancel" ? "The request could not be cancelled" : "Could not mark as shared", getErrorMessage(error));
          setConfirm(null);
        },
      },
    );

  const download = async (version: DocumentVersion) => {
    setDownloading(version.id);
    try {
      await downloadVersion(request.matter_id, request.document_id, version);
    } catch (error) {
      toast.error("The download failed", getErrorMessage(error));
    } finally {
      setDownloading(null);
    }
  };

  return (
    <div className="flex flex-col gap-6">
      <Link href={`/matters/${request.matter_id}?tab=signatures`} className="inline-flex w-fit items-center gap-1.5 text-[13px] font-medium text-muted-foreground hover:text-foreground">
        <ArrowLeft aria-hidden className="size-3.5" />
        {request.matter?.matter_number} · Signatures
      </Link>
      <header className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="min-w-0">
          <p className="text-xs font-medium tracking-wide text-subtle-foreground uppercase">Signing request · one signer</p>
          <h1 className="mt-1 text-[26px] leading-tight font-semibold tracking-[-0.02em] break-words text-foreground">{request.document?.title}</h1>
          <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-[13px] text-muted-foreground">
            <SigningStatusBadge status={request.status} />
            <span>
              {request.signer_name} ({request.signer_type_label.toLowerCase()})
            </span>
            {request.source_version ? <span>Source {request.source_version.label}</span> : null}
            {request.document ? (
              <Link href={documentHref({ id: request.document.id, matter_id: request.matter_id })} className="font-medium text-foreground hover:text-primary hover:underline">
                Open document
              </Link>
            ) : null}
          </div>
        </div>
        <div className="flex shrink-0 flex-wrap gap-2">
          {request.allowed_actions.admin_sign && request.source_version ? (
            <Button onClick={() => setSigning(true)}>
              <PenLine aria-hidden />
              Sign now
            </Button>
          ) : null}
          {request.allowed_actions.cancel ? (
            <Button variant="secondary" onClick={() => setConfirm("cancel")}>
              <Ban aria-hidden />
              Cancel request
            </Button>
          ) : null}
        </div>
      </header>

      {request.status === "signed" && request.signed_version ? (
        <div role="status" className="flex items-start gap-2.5 rounded-lg border border-primary/20 bg-primary-soft px-4 py-3 text-[13px] text-primary-soft-foreground" data-testid="signed-banner">
          <CheckCircle2 aria-hidden className="mt-px size-4 shrink-0" />
          <span>
            Signed {formatDateTime(request.signed_at)} by {request.signer_name}. The signed PDF is stored as {request.signed_version.label}; the original{" "}
            {request.source_version?.label} is unchanged.
          </span>
        </div>
      ) : null}

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div className="flex min-w-0 flex-col gap-6">
          <Card>
            <CardHeader>
              <CardTitle>Progress</CardTitle>
            </CardHeader>
            <CardContent>
              <SigningProgress request={request} />
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>Documents</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
              {[
                ["Source (signed from)", request.source_version],
                ["Signed PDF", request.signed_version ?? null],
              ].map(([label, version]) =>
                version ? (
                  <div key={label as string} className="flex flex-wrap items-center gap-3 rounded-lg border border-border px-3.5 py-3" data-testid={label === "Signed PDF" ? "signed-version" : "source-version"}>
                    <div className="min-w-0 flex-1">
                      <p className="text-xs text-muted-foreground">{label as string}</p>
                      <p className="truncate text-sm font-medium text-foreground">
                        {(version as DocumentVersion).label} · {(version as DocumentVersion).original_filename}
                      </p>
                      <p className="text-xs text-muted-foreground">
                        {formatFileSize((version as DocumentVersion).file_size)} · SHA-256 {(version as DocumentVersion).sha256_checksum.slice(0, 16)}…
                      </p>
                    </div>
                    <Button variant="secondary" size="sm" onClick={() => setPreview(version as DocumentVersion)} aria-label={`Preview ${(version as DocumentVersion).label}`}>
                      <Eye aria-hidden />
                      Preview
                    </Button>
                    <Button variant="secondary" size="sm" onClick={() => void download(version as DocumentVersion)} disabled={downloading !== null} aria-label={`Download ${(version as DocumentVersion).label}`}>
                      {downloading === (version as DocumentVersion).id ? <Loader2 aria-hidden className="animate-spin" /> : <Download aria-hidden />}
                      Download
                    </Button>
                  </div>
                ) : null,
              )}
            </CardContent>
          </Card>
          {evidence ? <EvidenceCard evidence={evidence} /> : null}
        </div>
        <div className="flex min-w-0 flex-col gap-6">
          <Card>
            <CardHeader>
              <CardTitle>Signer</CardTitle>
            </CardHeader>
            <CardContent>
              <dl className="flex flex-col gap-2 text-[13px]">
                <Row label="Name" value={request.signer_name} />
                <Row label="E-mail" value={request.signer_email ?? "—"} />
                <Row label="Type" value={request.signer_type_label} />
                {client ? <Row label="Delivery" value={request.delivery_status_label} /> : null}
                <Row label="Created" value={formatDateTime(request.created_at)} />
              </dl>
              {request.message ? <p className="mt-3 rounded-md bg-surface-muted px-3 py-2 text-xs whitespace-pre-wrap text-muted-foreground">{request.message}</p> : null}
            </CardContent>
          </Card>
          {client ? <SigningLinkCard request={request} links={links} onIssued={setIssued} onShare={() => setConfirm("share")} /> : null}
        </div>
      </div>

      {preview && request.document ? (
        <PdfViewerDialog open onOpenChange={(o) => !o && setPreview(null)} matterId={request.matter_id} documentId={request.document.id} title={request.document.title} version={preview} onDownload={() => void download(preview)} />
      ) : null}
      {request.source_version && request.document ? (
        <AdminSignDialog
          open={signing}
          onOpenChange={setSigning}
          matterId={request.matter_id}
          documentId={request.document.id}
          documentTitle={request.document.title}
          version={request.source_version}
          signingRequest={{ id: request.id, lock_version: request.lock_version }}
        />
      ) : null}
      <ConfirmDialog
        open={confirm === "cancel"}
        onOpenChange={(o) => setConfirm(o ? "cancel" : null)}
        title="Cancel this signing request?"
        description="The signer will no longer be able to sign, and any active signing link stops working immediately. Nothing already signed is affected. This cannot be undone."
        confirmLabel="Cancel request"
        cancelLabel="Keep request"
        destructive
        pending={action.isPending}
        onConfirm={() => run("cancel")}
      />
      <ConfirmDialog
        open={confirm === "share"}
        onOpenChange={(o) => setConfirm(o ? "share" : null)}
        title="Mark the invitation as shared?"
        description="Confirm that you have sent the signing link to the signer yourself (for example by e-mail). Lexora does not send it — this only records that you did, and progress will show “Sent”."
        confirmLabel="Yes, I shared it"
        pending={action.isPending}
        onConfirm={() => run("mark-shared")}
      />
      <LinkRevealDialog link={issued} purpose="signing" title="Secure signing link" onClose={() => setIssued(null)} />
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

function SigningLinkCard({ request, links, onIssued, onShare }: { request: SigningRequest; links: SecureLink[]; onIssued: (link: IssuedLink) => void; onShare: () => void }) {
  const toast = useToast();
  const issue = useSigningLink(request.matter_id, request.id);
  const revoke = useRevokeSigningLink(request.matter_id, request.id);
  const [confirm, setConfirm] = React.useState<"rotate" | "revoke" | null>(null);
  const active = request.active_link;

  const doIssue = (rotate: boolean) =>
    issue.mutate(
      { rotate },
      {
        onSuccess: (r) => {
          setConfirm(null);
          if (r.link) onIssued(r.link);
        },
        onError: (e) => {
          setConfirm(null);
          toast.error("The link could not be generated", getErrorMessage(e));
        },
      },
    );

  return (
    <Card data-testid="signing-link-card">
      <CardHeader>
        <CardTitle>Secure signing link</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        {active ? (
          <div className="flex flex-col gap-1 text-[13px]">
            <div className="flex items-center justify-between">
              <span className="text-muted-foreground">Current link</span>
              <LinkStateBadge state={active.state} />
            </div>
            <p className="text-xs text-muted-foreground">
              {active.state === "expired" ? "Expired" : "Valid until"} {formatDateTime(active.expires_at)}
            </p>
          </div>
        ) : (
          <p className="text-[13px] text-muted-foreground" data-testid="no-signing-link">
            {request.status === "signed" ? "The link was used to sign and no longer works." : request.is_active ? "No active link." : "This request is closed."}
          </p>
        )}
        {request.allowed_actions.mark_shared ? (
          <Button onClick={onShare}>
            <Send aria-hidden />
            Mark invitation as shared
          </Button>
        ) : null}
        {request.allowed_actions.issue_link ? (
          <Button variant={request.allowed_actions.mark_shared ? "secondary" : "primary"} onClick={() => doIssue(false)} disabled={issue.isPending}>
            {issue.isPending ? <Loader2 aria-hidden className="animate-spin" /> : <Link2 aria-hidden />}
            Generate signing link
          </Button>
        ) : request.allowed_actions.rotate_link ? (
          <Button variant="secondary" onClick={() => setConfirm("rotate")} disabled={issue.isPending}>
            <RefreshCw aria-hidden />
            Generate replacement link
          </Button>
        ) : null}
        {request.allowed_actions.revoke_link && active && request.is_active ? (
          <Button variant="ghost" className="text-danger hover:text-danger" onClick={() => setConfirm("revoke")} disabled={revoke.isPending}>
            <Link2Off aria-hidden />
            Revoke link
          </Button>
        ) : null}
        <p className="text-xs text-subtle-foreground">Links are shown once and are not e-mailed by Lexora. One signature per link; viewing does not use it up.</p>
        {links.length > 0 ? (
          <details className="text-xs">
            <summary className="cursor-pointer font-medium text-muted-foreground">Link history ({links.length})</summary>
            <ul className="mt-2 flex flex-col gap-1.5">
              {links.map((l) => (
                <li key={l.id} className="flex items-center justify-between gap-2">
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
        onOpenChange={(o) => setConfirm(o ? "rotate" : null)}
        title="Replace the signing link?"
        description="The current link stops working immediately. You will get a new link to send to the signer."
        confirmLabel="Generate replacement"
        pending={issue.isPending}
        onConfirm={() => doIssue(true)}
      />
      <ConfirmDialog
        open={confirm === "revoke"}
        onOpenChange={(o) => setConfirm(o ? "revoke" : null)}
        title="Revoke the signing link?"
        description="The signer will no longer be able to open or sign with it. You can generate a new link while the request is open."
        confirmLabel="Revoke link"
        destructive
        pending={revoke.isPending}
        onConfirm={() =>
          active &&
          revoke.mutate(active.id, {
            onSuccess: () => {
              setConfirm(null);
              toast.success("Link revoked", "It no longer works.");
            },
            onError: (e) => {
              setConfirm(null);
              toast.error("The link could not be revoked", getErrorMessage(e));
            },
          })
        }
      />
    </Card>
  );
}

function EvidenceCard({ evidence }: { evidence: SignatureEvidence }) {
  return (
    <Card data-testid="evidence-card">
      <CardHeader>
        <CardTitle className="inline-flex items-center gap-2">
          <ShieldCheck aria-hidden className="size-4 text-primary" />
          Signature evidence
        </CardTitle>
      </CardHeader>
      <CardContent>
        <dl className="grid gap-x-6 gap-y-2 text-[13px] sm:grid-cols-2">
          <Row label="Designated signer" value={evidence.signer_name} />
          <Row label="Name confirmed" value={evidence.confirmed_name} />
          <Row label="Signer type" value={evidence.signer_type === "admin" ? "Administrator" : "Client"} />
          <Row label="Method" value={evidence.signature_method_label} />
          <Row label="Signed at (server)" value={formatDateTime(evidence.signed_at)} />
          <Row label="Consent accepted" value={`${formatDateTime(evidence.consent_accepted_at)} · v${evidence.consent_version}`} />
          <Row label="Page" value={evidence.page_number} />
          <Row label="IP address" value={evidence.ip_address ?? "—"} />
        </dl>
        <div className="mt-4 flex flex-col gap-1 rounded-lg bg-surface-muted px-3 py-2 font-mono text-[11px] break-all text-muted-foreground">
          <span>Source SHA-256 {evidence.source_sha256}</span>
          <span>Signed SHA-256 {evidence.signed_sha256}</span>
        </div>
        <p className="mt-3 text-xs text-muted-foreground">
          <span className="font-medium text-foreground">Identity basis:</span> {evidence.identity_basis} This is an application-level electronic
          signature record, not a certificate-based (qualified) signature.
        </p>
        <details className="mt-2 text-xs text-muted-foreground">
          <summary className="cursor-pointer font-medium">Consent statement the signer accepted</summary>
          <p className="mt-1">{evidence.consent_text}</p>
          {evidence.user_agent ? <p className="mt-1">Browser: {evidence.user_agent}</p> : null}
        </details>
      </CardContent>
    </Card>
  );
}
