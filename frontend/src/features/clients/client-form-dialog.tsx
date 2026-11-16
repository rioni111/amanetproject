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
import { applyServerErrors, emptyToNull, useSubmitGuard } from "@/lib/forms";
import { CLIENT_TYPE_LABELS } from "@/lib/utils/labels";
import { CLIENT_TYPES, type Client } from "@/types/api";

import { useSaveClient } from "./api";

// Mirrors App\Http\Requests\Clients\ClientRequest (the server stays authoritative).
const PHONE = /^\+?[0-9 ()\-./]{5,50}$/;
const REFERENCE = /^[A-Za-z0-9][A-Za-z0-9\-_./]*$/;

const clientSchema = z.object({
  name: z.string().trim().min(1, "Enter the client's name.").max(200, "Use at most 200 characters."),
  client_type: z.enum(CLIENT_TYPES, { message: "Choose individual or organization." }),
  email: z
    .string()
    .trim()
    .max(255)
    .refine((v) => v === "" || z.email().safeParse(v).success, "Enter a valid email address."),
  phone: z
    .string()
    .trim()
    .max(50, "Use at most 50 characters.")
    .refine((v) => v === "" || PHONE.test(v), "Use digits, spaces and + ( ) - . / only.")
    .refine((v) => v === "" || (v.match(/\d/g)?.length ?? 0) >= 5, "The phone number must contain at least 5 digits."),
  address: z.string().trim().max(1000, "Use at most 1000 characters."),
  reference_number: z
    .string()
    .trim()
    .max(50, "Use at most 50 characters.")
    .refine((v) => v === "" || REFERENCE.test(v), "Use letters, numbers and - _ . / only."),
});

type ClientValues = z.infer<typeof clientSchema>;
const FIELDS = ["name", "client_type", "email", "phone", "address", "reference_number"] as const;

function toValues(client?: Client): ClientValues {
  return {
    name: client?.name ?? "",
    client_type: client?.client_type ?? "individual",
    email: client?.email ?? "",
    phone: client?.phone ?? "",
    address: client?.address ?? "",
    reference_number: client?.reference_number ?? "",
  };
}

export function ClientFormDialog({
  open,
  onOpenChange,
  client,
  onSaved,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Present when editing. */
  client?: Client;
  onSaved?: (client: Client) => void;
}) {
  const save = useSaveClient(client?.id);
  const toast = useToast();
  const guard = useSubmitGuard();
  const [formError, setFormError] = React.useState<string | null>(null);

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<ClientValues>({ resolver: zodResolver(clientSchema), defaultValues: toValues(client) });

  // Fresh form each time the dialog opens — but never while it is open (background refetches
  // must not wipe what the user is typing).
  const wasOpen = React.useRef(false);
  React.useEffect(() => {
    if (open && !wasOpen.current) {
      reset(toValues(client));
      setFormError(null);
    }
    wasOpen.current = open;
  }, [open, client, reset]);

  const onSubmit = handleSubmit((values) =>
    guard(async () => {
      setFormError(null);
      try {
        const saved = await save.mutateAsync({
          name: values.name.trim(),
          client_type: values.client_type,
          email: emptyToNull(values.email),
          phone: emptyToNull(values.phone),
          address: emptyToNull(values.address),
          reference_number: emptyToNull(values.reference_number),
        });
        toast.success(client ? "Client updated" : "Client created", saved.name);
        onOpenChange(false);
        onSaved?.(saved);
      } catch (error) {
        setFormError(applyServerErrors(error, setError, FIELDS));
      }
    }),
  );

  const pending = save.isPending;

  return (
    <Dialog open={open} onOpenChange={(next) => (!pending ? onOpenChange(next) : undefined)}>
      <DialogContent
        title={client ? "Edit client" : "New client"}
        description={client ? `Update the details of ${client.name}.` : "Add an individual or organization your firm represents."}
      >
        <form onSubmit={onSubmit} noValidate aria-label={client ? "Edit client" : "New client"}>
          <div className="flex flex-col gap-5 px-5 py-5">
            <FormAlert message={formError} />
            <div className="grid gap-5 sm:grid-cols-[1fr_200px]">
              <FormField id="client-name" label="Client name" required error={errors.name?.message}>
                {(aria) => (
                  <Input {...aria} {...register("name")} aria-required placeholder="e.g. Harborline Freight Ltd." autoComplete="off" />
                )}
              </FormField>
              <FormField id="client-type" label="Client type" required error={errors.client_type?.message}>
                {(aria) => (
                  <Select {...aria} {...register("client_type")} aria-required>
                    {CLIENT_TYPES.map((type) => (
                      <option key={type} value={type}>
                        {CLIENT_TYPE_LABELS[type]}
                      </option>
                    ))}
                  </Select>
                )}
              </FormField>
            </div>
            <div className="grid gap-5 sm:grid-cols-2">
              <FormField id="client-email" label="Email" error={errors.email?.message} hint="Optional">
                {(aria) => (
                  <Input
                    {...aria}
                    {...register("email")}
                    type="email"
                    inputMode="email"
                    placeholder="name@example.com"
                    autoComplete="off"
                  />
                )}
              </FormField>
              <FormField id="client-phone" label="Phone" error={errors.phone?.message} hint="Optional">
                {(aria) => (
                  <Input {...aria} {...register("phone")} type="tel" inputMode="tel" placeholder="+1 555 0100 0000" autoComplete="off" />
                )}
              </FormField>
            </div>
            <FormField id="client-address" label="Address" error={errors.address?.message} hint="Optional">
              {(aria) => <Textarea {...aria} {...register("address")} rows={3} placeholder="Street, city, postcode, country" />}
            </FormField>
            <FormField
              id="client-reference"
              label="Reference number"
              error={errors.reference_number?.message}
              hint="Optional. Your firm's client reference, unique across clients (e.g. CL-1009)."
            >
              {(aria) => (
                <Input {...aria} {...register("reference_number")} placeholder="CL-1009" autoComplete="off" className="font-mono" />
              )}
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
              {pending ? "Saving…" : client ? "Save changes" : "Create client"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
