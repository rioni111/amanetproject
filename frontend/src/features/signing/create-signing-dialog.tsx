"use client";

import { Loader2 } from "lucide-react";
import * as React from "react";

import { FormAlert } from "@/components/shared/form-alert";
import { FormField } from "@/components/shared/form-field";
import { useToast } from "@/components/shared/toaster";
import { Button } from "@/components/ui/button";
import { Dialog, DialogClose, DialogContent, DialogFooter } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { useMatterDocuments, type DocumentFilters } from "@/features/documents/api";
import { LINK_VALIDITY_OPTIONS } from "@/features/requests/request-form-dialog";
import { ApiError, getErrorMessage } from "@/lib/api/errors";
import { useSubmitGuard } from "@/lib/forms";
import type { IssuedLink, Matter, SignerType, SigningRequest } from "@/types/api";

import { useCreateSigningRequest } from "./api";

const DOCS: DocumentFilters = { search: "", category: "", status: "active", matter_id: "", client_id: "", uploaded_from: "", uploaded_to: "", sort: "updated_at", page: 1 };

/**
 * Create a signing request for ONE signer and ONE exact version (the current, unsigned version of a
 * document). Client signers sign through a secure link; an administrator request is signed by the
 * administrator who creates it.
 */
export function CreateSigningDialog({
  open,
  onOpenChange,
  matter,
  onCreated,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  matter: Matter & { client?: { name: string; email?: string | null } };
  onCreated: (request: SigningRequest, link: IssuedLink | null) => void;
}) {
  const documents = useMatterDocuments(matter.id, DOCS);
  const create = useCreateSigningRequest(matter.id);
  const toast = useToast();
  const guard = useSubmitGuard();
  const [documentId, setDocumentId] = React.useState("");
  const [signerType, setSignerType] = React.useState<SignerType>("client");
  const [name, setName] = React.useState("");
  const [email, setEmail] = React.useState("");
  const [message, setMessage] = React.useState("");
  const [issueLink, setIssueLink] = React.useState(true);
  const [validity, setValidity] = React.useState("7");
  const [errors, setErrors] = React.useState<Record<string, string>>({});
  const [formError, setFormError] = React.useState<string | null>(null);

  const wasOpen = React.useRef(false);
  React.useEffect(() => {
    if (open && !wasOpen.current) {
      setDocumentId("");
      setSignerType("client");
      setName(matter.client?.name ?? "");
      setEmail("");
      setMessage("");
      setIssueLink(true);
      setValidity("7");
      setErrors({});
      setFormError(null);
    }
    wasOpen.current = open;
  }, [open, matter.client?.name]);

  // Only documents whose current version is an original (unsigned) PDF and without an open request.
  const eligible = (documents.data?.data ?? []).filter((d) => d.current_version?.kind === "upload");
  const selected = eligible.find((d) => String(d.id) === documentId);

  const submit = (event: React.FormEvent) => {
    event.preventDefault();
    void guard(async () => {
      const next: Record<string, string> = {};
      if (!selected?.current_version) next.document_id = "Choose the document to be signed.";
      if (signerType === "client" && name.trim().length < 2) next.signer_name = "Enter the signer's full name.";
      setErrors(next);
      if (Object.keys(next).length > 0 || !selected?.current_version) return;
      setFormError(null);
      try {
        const result = await create.mutateAsync({
          document_id: selected.id,
          document_version_id: selected.current_version.id,
          signer_type: signerType,
          ...(signerType === "client"
            ? { signer_name: name.trim(), signer_email: email.trim() || null, message: message.trim() || null }
            : {}),
          issue_link: signerType === "client" && issueLink,
          ...(signerType === "client" && issueLink ? { link_expires_in_days: Number(validity) } : {}),
        });
        toast.success("Signing request created", `${selected.title} · ${result.data.signer_name}`);
        onOpenChange(false);
        onCreated(result.data, result.link);
      } catch (error) {
        if (error instanceof ApiError && error.isValidation) {
          const fields: Record<string, string> = {};
          for (const f of ["document_id", "document_version_id", "signer_name", "signer_email", "message", "document"]) {
            const m = error.field(f);
            if (m) fields[f === "document_version_id" || f === "document" ? "document_id" : f] = m;
          }
          setErrors(fields);
          if (Object.keys(fields).length === 0) setFormError(error.message);
        } else {
          setFormError(getErrorMessage(error));
        }
      }
    });
  };

  const pending = create.isPending;
  return (
    <Dialog open={open} onOpenChange={(next) => (!pending ? onOpenChange(next) : undefined)}>
      <DialogContent title="New signing request" description="One signer signs one exact PDF version. The original stays unchanged; the signed PDF becomes a new version.">
        <form onSubmit={submit} noValidate aria-label="New signing request">
          <div className="flex flex-col gap-5 px-5 py-5">
            <FormAlert message={formError} />
            <FormField id="signing-document" label="Document" required error={errors.document_id} hint="Only documents whose current version is an unsigned original are listed.">
              {(aria) => (
                <Select {...aria} aria-required value={documentId} onChange={(e) => setDocumentId(e.target.value)} disabled={pending || documents.isPending}>
                  <option value="">{documents.isPending ? "Loading documents…" : eligible.length ? "Choose a document" : "No eligible documents"}</option>
                  {eligible.map((d) => (
                    <option key={d.id} value={d.id}>
                      {d.title} · {d.current_version?.label}
                    </option>
                  ))}
                </Select>
              )}
            </FormField>

            <fieldset className="flex flex-col gap-2">
              <legend className="mb-1 text-[13px] font-medium text-foreground">Signer (one per PDF)</legend>
              {(
                [
                  ["client", `Client — ${matter.client?.name ?? "the matter's client"}`, "Signs through a secure link, no account needed."],
                  ["admin", "Me (administrator)", "You sign inside the workspace."],
                ] as const
              ).map(([value, label, hint]) => (
                <label key={value} className="flex items-start gap-2.5 rounded-lg border border-border px-3 py-2.5 text-[13px]">
                  <input
                    type="radio"
                    name="signer_type"
                    value={value}
                    checked={signerType === value}
                    onChange={() => setSignerType(value)}
                    disabled={pending}
                    className="mt-0.5 accent-[var(--color-primary)]"
                  />
                  <span>
                    <span className="font-medium text-foreground">{label}</span>
                    <span className="block text-xs text-muted-foreground">{hint}</span>
                  </span>
                </label>
              ))}
            </fieldset>

            {signerType === "client" ? (
              <>
                <div className="grid gap-5 sm:grid-cols-2">
                  <FormField id="signer-name" label="Signer's full name" required error={errors.signer_name} hint="The signer must confirm this name.">
                    {(aria) => <Input {...aria} aria-required value={name} maxLength={200} onChange={(e) => setName(e.target.value)} disabled={pending} />}
                  </FormField>
                  <FormField id="signer-email" label="Signer's e-mail" error={errors.signer_email} hint="Optional — recorded with the request.">
                    {(aria) => <Input {...aria} type="email" value={email} maxLength={255} onChange={(e) => setEmail(e.target.value)} disabled={pending} />}
                  </FormField>
                </div>
                <FormField id="signer-message" label="Message to the signer" hint="Optional. Shown on the signing page.">
                  {(aria) => <Textarea {...aria} rows={2} value={message} maxLength={2000} onChange={(e) => setMessage(e.target.value)} disabled={pending} />}
                </FormField>
                <div className="flex flex-col gap-3 rounded-lg border border-border px-3.5 py-3">
                  <label className="flex items-start gap-2.5 text-[13px] text-foreground">
                    <input type="checkbox" className="mt-0.5 size-4 accent-[var(--color-primary)]" checked={issueLink} onChange={(e) => setIssueLink(e.target.checked)} disabled={pending} />
                    <span>
                      Generate the secure signing link now
                      <span className="block text-xs text-muted-foreground">Links are not e-mailed automatically; you copy and send it yourself.</span>
                    </span>
                  </label>
                  {issueLink ? (
                    <FormField id="signing-validity" label="Link valid for" className="sm:max-w-48">
                      {(aria) => (
                        <Select {...aria} value={validity} onChange={(e) => setValidity(e.target.value)} disabled={pending}>
                          {LINK_VALIDITY_OPTIONS.map((d) => (
                            <option key={d} value={d}>
                              {d} {d === 1 ? "day" : "days"}
                              {d === 7 ? " (default)" : ""}
                            </option>
                          ))}
                        </Select>
                      )}
                    </FormField>
                  ) : null}
                </div>
              </>
            ) : null}
          </div>
          <DialogFooter>
            <DialogClose asChild>
              <Button variant="secondary" disabled={pending}>
                Cancel
              </Button>
            </DialogClose>
            <Button type="submit" disabled={pending}>
              {pending ? <Loader2 aria-hidden className="animate-spin" /> : null}
              {pending ? "Creating…" : "Create signing request"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
