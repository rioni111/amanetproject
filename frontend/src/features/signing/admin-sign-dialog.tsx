"use client";

import { AlertCircle, Loader2 } from "lucide-react";
import { useRouter } from "next/navigation";
import type { PDFDocumentLoadingTask, PDFDocumentProxy } from "pdfjs-dist";
import * as React from "react";

import { useToast } from "@/components/shared/toaster";
import { Dialog, DialogContent } from "@/components/ui/dialog";
import { useAuthenticatedUser } from "@/features/auth/auth-guard";
import { versionFileUrl } from "@/features/documents/api";
import { loadPdfJs } from "@/features/documents/pdf-viewer-dialog";
import { apiBlob } from "@/lib/api/client";
import { ApiError, getErrorMessage } from "@/lib/api/errors";
import { formatFileSize } from "@/lib/utils/format";
import type { DocumentVersion } from "@/types/api";

import { signingHref, useAdminSign, useSignDocument, useSigningEligibility, type SignaturePayload } from "./api";
import { SigningFlow } from "./signing-flow";

/**
 * Administrator signing of one exact, current PDF version: the server first confirms eligibility (archive
 * state, current version, PDF engine support), the real PDF is shown with PDF.js, and the signed PDF is
 * generated and stored by the API. Success is shown only after the API confirms it.
 */
export function AdminSignDialog({
  open,
  onOpenChange,
  matterId,
  documentId,
  documentTitle,
  version,
  signingRequest,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  matterId: number;
  documentId: number;
  documentTitle: string;
  version: DocumentVersion;
  /** Present when signing a prepared administrator request (otherwise one is created and signed in one step). */
  signingRequest?: { id: number; lock_version: number };
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="sm:h-[94dvh] sm:max-w-6xl"
        title={`Sign ${documentTitle}`}
        description={`${version.label} · ${version.original_filename} · ${formatFileSize(version.file_size)} — the original stays unchanged; the signed PDF becomes a new version.`}
      >
        {open ? (
          <AdminSignBody
            matterId={matterId}
            documentId={documentId}
            version={version}
            signingRequest={signingRequest}
            onDone={() => onOpenChange(false)}
          />
        ) : null}
      </DialogContent>
    </Dialog>
  );
}

function AdminSignBody({
  matterId,
  documentId,
  version,
  signingRequest,
  onDone,
}: {
  matterId: number;
  documentId: number;
  version: DocumentVersion;
  signingRequest?: { id: number; lock_version: number };
  onDone: () => void;
}) {
  const user = useAuthenticatedUser();
  const router = useRouter();
  const toast = useToast();
  const eligibility = useSigningEligibility(matterId, documentId, version.id, true);
  const signNow = useSignDocument(matterId, documentId);
  const signPrepared = useAdminSign(matterId, signingRequest?.id ?? 0);
  const [pdf, setPdf] = React.useState<PDFDocumentProxy | null>(null);
  const [loadError, setLoadError] = React.useState<string | null>(null);
  const [error, setError] = React.useState<string | null>(null);
  const busy = React.useRef(false);

  React.useEffect(() => {
    if (!eligibility.isSuccess) return;
    const controller = new AbortController();
    let task: PDFDocumentLoadingTask | null = null;
    (async () => {
      try {
        const [pdfjs, blob] = await Promise.all([loadPdfJs(), apiBlob(versionFileUrl(matterId, documentId, version.id, "preview"), controller.signal)]);
        task = pdfjs.getDocument({ data: new Uint8Array(await blob.arrayBuffer()), standardFontDataUrl: "/pdfjs/standard_fonts/", enableXfa: false });
        setPdf(await task.promise);
      } catch (err) {
        if (!(err instanceof DOMException && err.name === "AbortError")) setLoadError(getErrorMessage(err));
      }
    })();
    return () => {
      controller.abort();
      if (task) void task.destroy();
    };
  }, [eligibility.isSuccess, matterId, documentId, version.id]);

  const submit = async (payload: SignaturePayload) => {
    if (busy.current) return; // double click
    busy.current = true;
    setError(null);
    try {
      const result = signingRequest
        ? await signPrepared.mutateAsync({ ...payload, lock_version: signingRequest.lock_version })
        : await signNow.mutateAsync({ ...payload, document_version_id: version.id });
      toast.success("Document signed", `The signed PDF was saved as ${result.data.signed_version?.label ?? "a new version"}.`);
      onDone();
      router.push(signingHref(result.data));
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setError(Object.values(err.fieldErrors)[0]?.[0] ?? err.message);
      } else {
        setError(getErrorMessage(err));
      }
    } finally {
      busy.current = false;
    }
  };

  const failure = eligibility.isError ? getErrorMessage(eligibility.error) : loadError;
  return (
    <div className="max-h-[80dvh] overflow-y-auto px-4 py-4 sm:max-h-none sm:px-6">
      {failure ? (
        <p role="alert" className="flex items-start gap-2 rounded-lg border border-danger/25 bg-danger-soft px-4 py-3 text-sm text-danger" data-testid="sign-unavailable">
          <AlertCircle aria-hidden className="mt-0.5 size-4 shrink-0" />
          {failure}
        </p>
      ) : !pdf || !eligibility.data ? (
        <div role="status" className="flex items-center justify-center gap-2 py-24 text-sm text-muted-foreground">
          <Loader2 aria-hidden className="size-4 animate-spin" />
          Preparing the document for signing…
        </div>
      ) : (
        <SigningFlow
          pdf={pdf}
          signerName={user.name}
          mode="admin"
          consent={eligibility.data!.consent}
          submitting={signNow.isPending || signPrepared.isPending}
          error={error}
          onSubmit={(payload) => void submit(payload)}
          caption={
            <p className="rounded-lg border border-border bg-surface-muted/60 px-3 py-2 text-xs text-muted-foreground">
              Source: <span className="font-medium text-foreground">{version.label}</span> · SHA-256 {version.sha256_checksum.slice(0, 16)}…
            </p>
          }
        />
      )}
    </div>
  );
}
