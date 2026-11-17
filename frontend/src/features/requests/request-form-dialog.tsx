"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Loader2 } from "lucide-react";
import * as React from "react";
import { useForm, useWatch } from "react-hook-form";
import { z } from "zod";

import { FormAlert } from "@/components/shared/form-alert";
import { FormField } from "@/components/shared/form-field";
import { useToast } from "@/components/shared/toaster";
import { Button } from "@/components/ui/button";
import { Dialog, DialogClose, DialogContent, DialogFooter } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { applyServerErrors, emptyToNull, useSubmitGuard } from "@/lib/forms";
import { todayIsoDate } from "@/lib/utils/format";
import { DOCUMENT_CATEGORY_LABELS } from "@/lib/utils/labels";
import { DOCUMENT_CATEGORIES, type DocumentRequest, type IssuedLink, type Matter } from "@/types/api";

import { useCreateRequest, useUpdateRequest } from "./api";

export const LINK_VALIDITY_OPTIONS = [1, 3, 7, 14, 30] as const;

const schema = z.object({
  title: z.string().trim().min(1, "Enter a title for the request.").max(255, "Use at most 255 characters."),
  instructions: z.string().trim().min(1, "Tell the client what to upload.").max(5000, "Use at most 5,000 characters."),
  requested_category: z.enum(DOCUMENT_CATEGORIES, { error: "Choose the document category." }),
  due_date: z.string().regex(/^(\d{4}-\d{2}-\d{2})?$/, "Enter a valid date."),
  issue_link: z.boolean(),
  validity: z.string(),
});

type Values = z.infer<typeof schema>;
const FIELDS = ["title", "instructions", "requested_category", "due_date"] as const;

function toValues(request?: DocumentRequest): Values {
  return {
    title: request?.title ?? "",
    instructions: request?.instructions ?? "",
    requested_category: (request?.requested_category ?? "") as Values["requested_category"],
    due_date: request?.due_date ?? "",
    issue_link: true,
    validity: "7",
  };
}

/**
 * Create (or edit) a client document request. The client is always the matter's client — it is shown,
 * never chosen — and the server assigns it. Optionally issues the secure upload link straight away.
 */
export function RequestFormDialog({
  open,
  onOpenChange,
  matter,
  request,
  onCreated,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  matter: Matter;
  request?: DocumentRequest;
  onCreated?: (request: DocumentRequest, link: IssuedLink | null) => void;
}) {
  const create = useCreateRequest(matter.id);
  const update = useUpdateRequest(matter.id, request?.id ?? 0);
  const toast = useToast();
  const guard = useSubmitGuard();
  const [formError, setFormError] = React.useState<string | null>(null);
  const editing = Boolean(request);
  const pending = create.isPending || update.isPending;

  const {
    register,
    handleSubmit,
    reset,
    setError,
    control,
    formState: { errors },
  } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: toValues(request) });

  const wasOpen = React.useRef(false);
  const openedVersion = React.useRef<number | undefined>(undefined);
  React.useEffect(() => {
    if (open && !wasOpen.current) {
      reset(toValues(request));
      openedVersion.current = request?.lock_version;
      setFormError(null);
    }
    wasOpen.current = open;
  }, [open, request, reset]);

  const issueLink = useWatch({ control, name: "issue_link" });

  const onSubmit = (event: React.FormEvent<HTMLFormElement>) =>
    void handleSubmit((values) =>
      guard(async () => {
        setFormError(null);
        const input = {
          title: values.title.trim(),
          instructions: values.instructions.trim(),
          requested_category: values.requested_category,
          due_date: emptyToNull(values.due_date),
        };
        try {
          if (request) {
            await update.mutateAsync({ ...input, lock_version: openedVersion.current ?? request.lock_version });
            toast.success("Request updated", input.title);
            onOpenChange(false);
          } else {
            const result = await create.mutateAsync({
              ...input,
              issue_link: values.issue_link,
              ...(values.issue_link ? { link_expires_in_days: Number(values.validity) } : {}),
            });
            toast.success("Document request created", result.data.title);
            onOpenChange(false);
            onCreated?.(result.data, result.link);
          }
        } catch (error) {
          setFormError(applyServerErrors(error, setError, FIELDS));
        }
      }),
    )(event);

  return (
    <Dialog open={open} onOpenChange={(next) => (!pending ? onOpenChange(next) : undefined)}>
      <DialogContent
        title={editing ? "Edit document request" : "Request a document from the client"}
        description={
          editing
            ? "Changes are visible to the client through the current upload link."
            : "The client uploads the PDF through a secure link — no account needed. It arrives directly in this matter."
        }
      >
        <form onSubmit={onSubmit} noValidate aria-label={editing ? "Edit document request" : "New document request"}>
          <div className="flex flex-col gap-5 px-5 py-5">
            <FormAlert message={formError} />
            <p className="rounded-lg border border-border bg-surface-muted/60 px-3.5 py-2.5 text-[13px] text-muted-foreground">
              Requested from <span className="font-medium text-foreground">{matter.client?.name ?? "the matter's client"}</span> for{" "}
              <span className="font-medium text-foreground">{matter.matter_number}</span>
            </p>

            <div className="grid gap-5 sm:grid-cols-[1fr_200px]">
              <FormField id="request-title" label="Request title" required error={errors.title?.message}>
                {(aria) => <Input {...aria} aria-required {...register("title")} placeholder="e.g. Signed service agreement" disabled={pending} />}
              </FormField>
              <FormField id="request-category" label="Document category" required error={errors.requested_category?.message}>
                {(aria) => (
                  <Select {...aria} aria-required {...register("requested_category")} disabled={pending}>
                    <option value="">Choose…</option>
                    {DOCUMENT_CATEGORIES.map((c) => (
                      <option key={c} value={c}>
                        {DOCUMENT_CATEGORY_LABELS[c]}
                      </option>
                    ))}
                  </Select>
                )}
              </FormField>
            </div>

            <FormField
              id="request-instructions"
              label="Instructions for the client"
              required
              error={errors.instructions?.message}
              hint="The client sees these instructions on the upload page. Do not include internal notes."
            >
              {(aria) => (
                <Textarea
                  {...aria}
                  aria-required
                  rows={4}
                  {...register("instructions")}
                  placeholder="e.g. Please upload the signed agreement as one PDF, all pages included."
                  disabled={pending}
                />
              )}
            </FormField>

            <FormField id="request-due" label="Due date" error={errors.due_date?.message} hint="Optional. Shown to the client.">
              {(aria) => <Input {...aria} type="date" min={editing ? undefined : todayIsoDate()} {...register("due_date")} disabled={pending} />}
            </FormField>

            {!editing ? (
              <div className="flex flex-col gap-3 rounded-lg border border-border px-3.5 py-3">
                <label className="flex items-start gap-2.5 text-[13px] text-foreground">
                  <input type="checkbox" className="mt-0.5 size-4 accent-[var(--color-primary)]" {...register("issue_link")} disabled={pending} />
                  <span>
                    Generate the secure upload link now
                    <span className="block text-xs text-muted-foreground">You can also generate it later from the request.</span>
                  </span>
                </label>
                {issueLink ? (
                  <FormField id="request-validity" label="Link valid for" className="sm:max-w-48">
                    {(aria) => (
                      <Select {...aria} {...register("validity")} disabled={pending}>
                        {LINK_VALIDITY_OPTIONS.map((days) => (
                          <option key={days} value={days}>
                            {days} {days === 1 ? "day" : "days"}
                            {days === 7 ? " (default)" : ""}
                          </option>
                        ))}
                      </Select>
                    )}
                  </FormField>
                ) : null}
              </div>
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
              {pending ? "Saving…" : editing ? "Save changes" : "Create request"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
