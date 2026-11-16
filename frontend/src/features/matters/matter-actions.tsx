"use client";

import { useQueryClient } from "@tanstack/react-query";
import { Archive, ArchiveRestore, ExternalLink, Pencil } from "lucide-react";
import Link from "next/link";
import * as React from "react";

import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { useToast } from "@/components/shared/toaster";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent } from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { ApiError, getErrorMessage } from "@/lib/api/errors";
import { invalidateWorkspace } from "@/lib/api/invalidate";
import { MATTER_STATUS_LABELS, PRIORITY_LABELS } from "@/lib/utils/labels";
import { PRIORITIES, type Matter, type MatterStatus, type Priority } from "@/types/api";

import { useArchiveMatter, useChangePriority, useChangeStatus, useMatter, useRestoreMatter } from "./api";
import { MatterFormDialog } from "./matter-form-dialog";

/** Shared error handling: conflicts (stale data) refresh the matter so the user sees the latest state. */
function useActionFeedback(matterId: number) {
  const toast = useToast();
  const queryClient = useQueryClient();
  return React.useCallback(
    (error: unknown, title: string) => {
      const message = error instanceof ApiError && error.isValidation ? (error.field("status") ?? error.message) : getErrorMessage(error);
      toast.error(title, message);
      if (error instanceof ApiError && (error.status === 409 || error.status === 422)) {
        invalidateWorkspace(queryClient, { matterId });
      }
    },
    [toast, queryClient, matterId],
  );
}

/**
 * Status select offering only the transitions the API allows from the current state
 * (`allowed_transitions`). Closing asks for confirmation. Archived matters use restore instead.
 */
export function StatusControl({ matter, id = `status-${matter.id}` }: { matter: Matter; id?: string }) {
  const change = useChangeStatus(matter.id);
  const toast = useToast();
  const fail = useActionFeedback(matter.id);
  const [confirmClose, setConfirmClose] = React.useState(false);

  async function apply(status: MatterStatus) {
    try {
      const updated = await change.mutateAsync({ status, lock_version: matter.lock_version });
      toast.success(`Status changed to ${MATTER_STATUS_LABELS[updated.status]}`, `${updated.matter_number} · ${updated.title}`);
    } catch (error) {
      fail(error, "Status not changed");
    } finally {
      setConfirmClose(false);
    }
  }

  const options: MatterStatus[] = [matter.status, ...matter.allowed_transitions];
  const disabled = change.isPending || matter.status === "archived";

  return (
    <div className="flex flex-col gap-2">
      <Label htmlFor={id}>Status</Label>
      <Select
        id={id}
        value={matter.status}
        disabled={disabled}
        aria-describedby={matter.status === "archived" ? `${id}-hint` : undefined}
        onChange={(event) => {
          const next = event.target.value as MatterStatus;
          if (next === "closed") setConfirmClose(true);
          else void apply(next);
        }}
      >
        {options.map((status) => (
          <option key={status} value={status}>
            {status === matter.status ? `${MATTER_STATUS_LABELS[status]} (current)` : MATTER_STATUS_LABELS[status]}
          </option>
        ))}
      </Select>
      {matter.status === "archived" ? (
        <p id={`${id}-hint`} className="text-xs text-subtle-foreground">
          Restore the matter to change its status.
        </p>
      ) : null}
      <ConfirmDialog
        open={confirmClose}
        onOpenChange={setConfirmClose}
        title={`Close ${matter.matter_number}?`}
        description="Closed matters leave the active workload and dashboard totals. You can reopen the matter later."
        confirmLabel="Close matter"
        pending={change.isPending}
        onConfirm={() => void apply("closed")}
      />
    </div>
  );
}

export function PriorityControl({ matter, id = `priority-${matter.id}` }: { matter: Matter; id?: string }) {
  const change = useChangePriority(matter.id);
  const toast = useToast();
  const fail = useActionFeedback(matter.id);

  return (
    <div className="flex flex-col gap-2">
      <Label htmlFor={id}>Priority</Label>
      <Select
        id={id}
        value={matter.priority}
        disabled={change.isPending || matter.status === "archived"}
        onChange={async (event) => {
          try {
            const updated = await change.mutateAsync({ priority: event.target.value as Priority, lock_version: matter.lock_version });
            toast.success(`Priority set to ${PRIORITY_LABELS[updated.priority]}`, `${updated.matter_number} · ${updated.title}`);
          } catch (error) {
            fail(error, "Priority not changed");
          }
        }}
      >
        {PRIORITIES.map((priority) => (
          <option key={priority} value={priority}>
            {PRIORITY_LABELS[priority]}
          </option>
        ))}
      </Select>
    </div>
  );
}

/** Archive (any non-archived matter) or restore (archived matter) with confirmation. */
export function ArchiveRestoreButton({ matter, size = "md" }: { matter: Matter; size?: "sm" | "md" }) {
  const archive = useArchiveMatter(matter.id);
  const restore = useRestoreMatter(matter.id);
  const toast = useToast();
  const fail = useActionFeedback(matter.id);
  const [open, setOpen] = React.useState(false);
  const archived = matter.status === "archived";
  const mutation = archived ? restore : archive;

  async function confirm() {
    try {
      const updated = await mutation.mutateAsync({ lock_version: matter.lock_version });
      toast.success(
        archived ? `Matter restored to ${MATTER_STATUS_LABELS[updated.status]}` : "Matter archived",
        `${updated.matter_number} · ${updated.title}`,
      );
      setOpen(false);
    } catch (error) {
      setOpen(false);
      fail(error, archived ? "Matter not restored" : "Matter not archived");
    }
  }

  const restoreTo = matter.archived_from_status ? MATTER_STATUS_LABELS[matter.archived_from_status] : null;

  return (
    <>
      <Button variant="secondary" size={size} onClick={() => setOpen(true)}>
        {archived ? <ArchiveRestore aria-hidden /> : <Archive aria-hidden />}
        {archived ? "Restore" : "Archive"}
      </Button>
      <ConfirmDialog
        open={open}
        onOpenChange={setOpen}
        title={archived ? `Restore ${matter.matter_number}?` : `Archive ${matter.matter_number}?`}
        description={
          archived ? (
            <p>The matter returns to {restoreTo ? <strong>{restoreTo}</strong> : "its previous status"} and becomes editable again.</p>
          ) : (
            <p>
              Archived matters are read-only and excluded from active totals. Tasks, notes and history are kept, and you can restore the
              matter at any time.
            </p>
          )
        }
        confirmLabel={archived ? "Restore matter" : "Archive matter"}
        pending={mutation.isPending}
        onConfirm={() => void confirm()}
      />
    </>
  );
}

/** Row-level quick actions for the matters list (status, priority, edit, archive/restore, open). */
export function MatterQuickActions({ matter }: { matter: Matter }) {
  const [open, setOpen] = React.useState(false);
  const [editing, setEditing] = React.useState(false);

  return (
    <>
      <Button variant="ghost" size="sm" onClick={() => setOpen(true)} aria-label={`Manage ${matter.matter_number}`}>
        <Pencil aria-hidden />
        <span className="hidden xl:inline">Manage</span>
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent
          title={`${matter.matter_number} · ${matter.title}`}
          description="Quick actions. Every change is saved immediately and recorded in the matter's history."
        >
          {open ? (
            <QuickActionsBody
              matter={matter}
              onEdit={() => {
                setOpen(false);
                setEditing(true);
              }}
            />
          ) : null}
        </DialogContent>
      </Dialog>
      {editing ? <EditFromDetail matter={matter} onOpenChange={setEditing} /> : null}
    </>
  );
}

/** Reads the matter's detail cache (updated synchronously by every mutation) so consecutive quick
 *  actions always send the latest lock_version. */
function QuickActionsBody({ matter: listMatter, onEdit }: { matter: Matter; onEdit: () => void }) {
  const detail = useMatter(listMatter.id);
  const matter = detail.data ?? listMatter;
  return (
    <div className="flex flex-col gap-5 px-5 py-5">
      <div className="grid gap-4 sm:grid-cols-2">
        <StatusControl matter={matter} id={`quick-status-${matter.id}`} />
        <PriorityControl matter={matter} id={`quick-priority-${matter.id}`} />
      </div>
      <div className="flex flex-wrap gap-2 border-t border-border pt-5">
        <Button variant="secondary" disabled={matter.status === "archived"} onClick={onEdit}>
          <Pencil aria-hidden />
          Edit details
        </Button>
        <ArchiveRestoreButton matter={matter} />
        <Button variant="ghost" asChild className="sm:ml-auto">
          <Link href={`/matters/${matter.id}`}>
            Open matter
            <ExternalLink aria-hidden />
          </Link>
        </Button>
      </div>
    </div>
  );
}

function EditFromDetail({ matter: listMatter, onOpenChange }: { matter: Matter; onOpenChange: (open: boolean) => void }) {
  const detail = useMatter(listMatter.id);
  return <MatterFormDialog open onOpenChange={onOpenChange} matter={detail.data ?? listMatter} />;
}
