"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Loader2 } from "lucide-react";
import * as React from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";

import { FormAlert } from "@/components/shared/form-alert";
import { FormField } from "@/components/shared/form-field";
import { useToast } from "@/components/shared/toaster";
import { Button } from "@/components/ui/button";
import { Dialog, DialogClose, DialogContent, DialogFooter } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { useClientOptions } from "@/features/clients/api";
import { applyServerErrors, emptyToNull, useSubmitGuard } from "@/lib/forms";
import { todayIsoDate } from "@/lib/utils/format";
import { MATTER_STATUS_LABELS, PRIORITY_LABELS } from "@/lib/utils/labels";
import { PRIORITIES, type Matter } from "@/types/api";

import { useSaveMatter } from "./api";

/** Suggestions only — the matter type is free text (max 100 characters). */
export const MATTER_TYPE_SUGGESTIONS = [
  "Contract Review",
  "Employment Dispute",
  "Corporate Registration",
  "Property Agreement",
  "Commercial Litigation",
  "Intellectual Property",
  "Regulatory Advice",
];

const INITIAL_STATUSES = ["open", "in_progress", "on_hold"] as const;
const isoDate = z.string().regex(/^(\d{4}-\d{2}-\d{2})?$/, "Enter a valid date.");

const matterSchema = z
  .object({
    title: z.string().trim().min(1, "Enter a matter title.").max(255, "Use at most 255 characters."),
    client_id: z.string().min(1, "Choose the client this matter is for."),
    matter_type: z.string().trim().max(100, "Use at most 100 characters."),
    priority: z.enum(PRIORITIES),
    status: z.enum(INITIAL_STATUSES),
    opened_date: isoDate,
    due_date: isoDate,
    description: z.string().max(10000, "Use at most 10,000 characters."),
  })
  .refine((v) => !v.opened_date || !v.due_date || v.due_date >= v.opened_date, {
    path: ["due_date"],
    message: "The deadline cannot be before the opening date.",
  });

type MatterValues = z.infer<typeof matterSchema>;
const FIELDS = ["title", "client_id", "matter_type", "priority", "status", "opened_date", "due_date", "description"] as const;

function toValues(matter?: Matter, defaultClientId?: number): MatterValues {
  return {
    title: matter?.title ?? "",
    client_id: String(matter?.client?.id ?? defaultClientId ?? ""),
    matter_type: matter?.matter_type ?? "",
    priority: matter?.priority ?? "medium",
    status: "open",
    opened_date: matter?.opened_date ?? todayIsoDate(),
    due_date: matter?.due_date ?? "",
    description: matter?.description ?? "",
  };
}

export function MatterFormDialog({
  open,
  onOpenChange,
  matter,
  defaultClientId,
  onSaved,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Present when editing. */
  matter?: Matter;
  defaultClientId?: number;
  onSaved?: (matter: Matter) => void;
}) {
  const save = useSaveMatter(matter?.id);
  const clients = useClientOptions(open);
  const toast = useToast();
  const guard = useSubmitGuard();
  const [formError, setFormError] = React.useState<string | null>(null);
  const editing = Boolean(matter);

  const {
    register,
    handleSubmit,
    reset,
    setError,
    setValue,
    getValues,
    formState: { errors },
  } = useForm<MatterValues>({ resolver: zodResolver(matterSchema), defaultValues: toValues(matter, defaultClientId) });

  // Reset only when the dialog opens: background refetches must not wipe what the user is typing.
  // The version loaded at that moment is what we send back, so a concurrent edit still yields 409.
  const wasOpen = React.useRef(false);
  const openedVersion = React.useRef<number | undefined>(undefined);
  React.useEffect(() => {
    if (open && !wasOpen.current) {
      reset(toValues(matter, defaultClientId));
      openedVersion.current = matter?.lock_version;
      setFormError(null);
    }
    wasOpen.current = open;
  }, [open, matter, defaultClientId, reset]);

  // The client options arrive after the form is reset; re-apply the selection once they exist.
  React.useEffect(() => {
    if (clients.isSuccess) setValue("client_id", getValues("client_id"));
  }, [clients.isSuccess, clients.data, setValue, getValues]);

  // handleSubmit is invoked inside the submit event (not during render), so reading the
  // opened-version ref happens in an event handler.
  const onSubmit = (event: React.FormEvent<HTMLFormElement>) =>
    void handleSubmit((values) =>
      guard(async () => {
        setFormError(null);
        try {
          const saved = await save.mutateAsync({
            title: values.title.trim(),
            client_id: Number(values.client_id),
            matter_type: emptyToNull(values.matter_type),
            priority: values.priority,
            opened_date: emptyToNull(values.opened_date),
            due_date: emptyToNull(values.due_date),
            description: emptyToNull(values.description),
            ...(matter ? { lock_version: openedVersion.current ?? matter.lock_version } : { status: values.status }),
          });
          toast.success(matter ? "Matter updated" : `Matter ${saved.matter_number} created`, saved.title);
          onOpenChange(false);
          onSaved?.(saved);
        } catch (error) {
          setFormError(applyServerErrors(error, setError, FIELDS));
        }
      }),
    )(event);

  const pending = save.isPending;
  const clientList = clients.data?.data ?? [];
  // Keep the current client selectable even if it is not in the first page of options.
  const currentClient = matter?.client && !clientList.some((c) => c.id === matter.client!.id) ? matter.client : null;

  return (
    <Dialog open={open} onOpenChange={(next) => (!pending ? onOpenChange(next) : undefined)}>
      <DialogContent
        className="sm:max-w-2xl"
        title={matter ? `Edit ${matter.matter_number}` : "New matter"}
        description={
          matter
            ? "Update the matter's details. The matter number never changes."
            : "A unique matter number is assigned automatically when you save."
        }
      >
        <form onSubmit={onSubmit} noValidate aria-label={matter ? "Edit matter" : "New matter"}>
          <div className="flex flex-col gap-5 px-5 py-5">
            <FormAlert message={formError} />
            <FormField id="matter-title" label="Matter title" required error={errors.title?.message}>
              {(aria) => (
                <Input {...aria} {...register("title")} aria-required placeholder="e.g. Distribution Agreement Review" autoComplete="off" />
              )}
            </FormField>
            <div className="grid gap-5 sm:grid-cols-2">
              <FormField
                id="matter-client"
                label="Client"
                required
                error={errors.client_id?.message}
                hint={clients.isError ? "Clients could not be loaded. Close and try again." : undefined}
              >
                {(aria) => (
                  <Select {...aria} {...register("client_id")} aria-required disabled={clients.isPending}>
                    <option value="">{clients.isPending ? "Loading clients…" : "Choose a client"}</option>
                    {currentClient ? <option value={currentClient.id}>{currentClient.name}</option> : null}
                    {clientList.map((client) => (
                      <option key={client.id} value={client.id}>
                        {client.name}
                        {client.reference_number ? ` (${client.reference_number})` : ""}
                      </option>
                    ))}
                  </Select>
                )}
              </FormField>
              <FormField
                id="matter-type"
                label="Matter type"
                error={errors.matter_type?.message}
                hint="Optional — pick a suggestion or type your own."
              >
                {(aria) => (
                  <>
                    <Input
                      {...aria}
                      {...register("matter_type")}
                      list="matter-type-suggestions"
                      placeholder="e.g. Contract Review"
                      autoComplete="off"
                    />
                    <datalist id="matter-type-suggestions">
                      {MATTER_TYPE_SUGGESTIONS.map((type) => (
                        <option key={type} value={type} />
                      ))}
                    </datalist>
                  </>
                )}
              </FormField>
            </div>
            <div className={editing ? "grid gap-5" : "grid gap-5 sm:grid-cols-2"}>
              <FormField id="matter-priority" label="Priority" required error={errors.priority?.message}>
                {(aria) => (
                  <Select {...aria} {...register("priority")} aria-required>
                    {PRIORITIES.map((priority) => (
                      <option key={priority} value={priority}>
                        {PRIORITY_LABELS[priority]}
                      </option>
                    ))}
                  </Select>
                )}
              </FormField>
              {!editing ? (
                <FormField id="matter-status" label="Initial status" required error={errors.status?.message}>
                  {(aria) => (
                    <Select {...aria} {...register("status")} aria-required>
                      {INITIAL_STATUSES.map((status) => (
                        <option key={status} value={status}>
                          {MATTER_STATUS_LABELS[status]}
                        </option>
                      ))}
                    </Select>
                  )}
                </FormField>
              ) : null}
            </div>
            <div className="grid gap-5 sm:grid-cols-2">
              <FormField id="matter-opened" label="Opening date" error={errors.opened_date?.message}>
                {(aria) => <Input {...aria} {...register("opened_date")} type="date" min="1990-01-01" max="2099-12-31" />}
              </FormField>
              <FormField
                id="matter-due"
                label="Deadline"
                error={errors.due_date?.message}
                hint="Optional. Overdue once the whole day has passed."
              >
                {(aria) => <Input {...aria} {...register("due_date")} type="date" min="1990-01-01" max="2099-12-31" />}
              </FormField>
            </div>
            <FormField
              id="matter-description"
              label="Description"
              error={errors.description?.message}
              hint="Optional. Scope, background and key issues."
            >
              {(aria) => <Textarea {...aria} {...register("description")} rows={4} placeholder="What is this matter about?" />}
            </FormField>
          </div>
          <DialogFooter>
            <DialogClose asChild>
              <Button variant="secondary" disabled={pending}>
                Cancel
              </Button>
            </DialogClose>
            <Button type="submit" disabled={pending}>
              {pending ? <Loader2 aria-hidden className="animate-spin" /> : null}
              {pending ? "Saving…" : matter ? "Save changes" : "Create matter"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
