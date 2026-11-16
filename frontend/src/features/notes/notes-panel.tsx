"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Loader2, Lock, NotebookPen, Pencil, Trash2 } from "lucide-react";
import * as React from "react";

import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { FormAlert } from "@/components/shared/form-alert";
import { ListSkeleton } from "@/components/shared/loading-skeleton";
import { useToast } from "@/components/shared/toaster";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { api } from "@/lib/api/client";
import { ApiError, getErrorMessage } from "@/lib/api/errors";
import { invalidateWorkspace } from "@/lib/api/invalidate";
import { useSubmitGuard } from "@/lib/forms";
import { formatDateTime, initials } from "@/lib/utils/format";
import type { Matter, MatterNote, Paginated, Resource } from "@/types/api";

const MAX_LENGTH = 10_000;

function useNotes(matterId: number) {
  return useQuery({
    queryKey: ["matter", matterId, "notes"],
    queryFn: ({ signal }) => api.get<Paginated<MatterNote>>(`/api/matters/${matterId}/notes`, { per_page: 100 }, signal),
  });
}

function useNoteMutation<V, R>(matterId: number, request: (variables: V) => Promise<R>) {
  const queryClient = useQueryClient();
  return useMutation({ mutationFn: request, onSuccess: () => invalidateWorkspace(queryClient, { matterId }) });
}

/**
 * Confidential internal notes. Bodies are rendered as plain text (React escapes them; no HTML is ever
 * interpreted) with line breaks preserved.
 */
export function NotesPanel({ matter }: { matter: Matter }) {
  const notes = useNotes(matter.id);
  const readOnly = matter.status === "archived";

  return (
    <section aria-labelledby="notes-heading" className="flex flex-col gap-4">
      <div>
        <h2 id="notes-heading" className="text-[15px] font-semibold text-foreground">
          Internal notes
        </h2>
        <p className="mt-0.5 inline-flex items-center gap-1.5 text-[13px] text-muted-foreground">
          <Lock aria-hidden className="size-3.5" />
          Visible to firm administrators only. Never shared with clients.
        </p>
      </div>

      {!readOnly ? <NoteComposer matterId={matter.id} /> : null}

      {notes.isPending ? (
        <ListSkeleton rows={2} />
      ) : notes.isError ? (
        <ErrorState error={notes.error} title="We couldn't load notes" onRetry={() => notes.refetch()} retrying={notes.isRefetching} />
      ) : notes.data.data.length === 0 ? (
        <EmptyState
          icon={NotebookPen}
          title="No internal notes yet"
          description={readOnly ? "This matter is archived." : "Record advice, strategy and conversations for the team."}
        />
      ) : (
        <ol aria-label="Internal notes" className="flex flex-col gap-3">
          {notes.data.data.map((note) => (
            <NoteItem key={note.id} matterId={matter.id} note={note} readOnly={readOnly} />
          ))}
        </ol>
      )}
    </section>
  );
}

function NoteComposer({ matterId }: { matterId: number }) {
  const create = useNoteMutation(
    matterId,
    async (body: string) => (await api.post<Resource<MatterNote>>(`/api/matters/${matterId}/notes`, { body })).data,
  );
  const toast = useToast();
  const guard = useSubmitGuard();
  const [body, setBody] = React.useState("");
  const [error, setError] = React.useState<string | null>(null);

  const submit = (event: React.FormEvent) => {
    event.preventDefault();
    void guard(async () => {
      if (!body.trim()) {
        setError("Write the note before saving.");
        return;
      }
      setError(null);
      try {
        await create.mutateAsync(body);
        setBody("");
        toast.success("Internal note added");
      } catch (err) {
        setError(err instanceof ApiError && err.isValidation ? (err.field("body") ?? err.message) : getErrorMessage(err));
      }
    });
  };

  return (
    <form
      onSubmit={submit}
      noValidate
      aria-label="Add internal note"
      className="flex flex-col gap-3 rounded-xl border border-border bg-surface p-4"
    >
      <label htmlFor="new-note" className="text-[13px] font-medium text-foreground">
        New internal note
      </label>
      <Textarea
        id="new-note"
        value={body}
        onChange={(e) => setBody(e.target.value)}
        rows={3}
        maxLength={MAX_LENGTH}
        placeholder="e.g. Called opposing counsel — they will respond by Friday."
        aria-invalid={Boolean(error)}
        aria-describedby={error ? "new-note-error" : undefined}
      />
      {error ? (
        <p id="new-note-error" className="text-xs font-medium text-danger">
          {error}
        </p>
      ) : null}
      <div className="flex items-center justify-between gap-3">
        <span className="text-xs text-subtle-foreground tabular-nums">
          {body.length.toLocaleString()} / {MAX_LENGTH.toLocaleString()}
        </span>
        <Button type="submit" disabled={create.isPending}>
          {create.isPending ? <Loader2 aria-hidden className="animate-spin" /> : null}
          {create.isPending ? "Saving…" : "Add note"}
        </Button>
      </div>
    </form>
  );
}

function NoteItem({ matterId, note, readOnly }: { matterId: number; note: MatterNote; readOnly: boolean }) {
  const update = useNoteMutation(
    matterId,
    async (vars: { body: string; lock_version: number }) =>
      (await api.patch<Resource<MatterNote>>(`/api/matters/${matterId}/notes/${note.id}`, vars)).data,
  );
  const remove = useNoteMutation(matterId, () => api.delete(`/api/matters/${matterId}/notes/${note.id}`));
  const toast = useToast();
  const guard = useSubmitGuard();
  const [editing, setEditing] = React.useState(false);
  const [draft, setDraft] = React.useState(note.body);
  const [error, setError] = React.useState<string | null>(null);
  const [confirmDelete, setConfirmDelete] = React.useState(false);
  const author = note.author?.name ?? "Former administrator";

  const save = (event: React.FormEvent) => {
    event.preventDefault();
    void guard(async () => {
      if (!draft.trim()) {
        setError("A note cannot be empty.");
        return;
      }
      try {
        await update.mutateAsync({ body: draft, lock_version: note.lock_version });
        setEditing(false);
        setError(null);
        toast.success("Internal note updated");
      } catch (err) {
        setError(err instanceof ApiError && err.isValidation ? (err.field("body") ?? err.message) : getErrorMessage(err));
      }
    });
  };

  return (
    <li className="rounded-xl border border-border bg-surface p-4" data-testid="note">
      <div className="flex items-start gap-3">
        <span
          aria-hidden
          className="flex size-8 shrink-0 items-center justify-center rounded-full border border-border bg-surface-muted text-[11px] font-semibold text-muted-foreground"
        >
          {initials(author)}
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <p className="text-[13px] font-medium text-foreground">{author}</p>
            <p className="text-xs text-subtle-foreground">
              <time dateTime={note.created_at ?? undefined}>{formatDateTime(note.created_at)}</time>
              {note.is_edited ? (
                <span title={`Edited ${formatDateTime(note.updated_at)}`}> · edited {formatDateTime(note.updated_at)}</span>
              ) : null}
            </p>
          </div>
          {editing ? (
            <form onSubmit={save} noValidate className="mt-2 flex flex-col gap-2" aria-label="Edit internal note">
              <label htmlFor={`note-${note.id}`} className="sr-only">
                Edit note
              </label>
              <Textarea
                id={`note-${note.id}`}
                value={draft}
                onChange={(e) => setDraft(e.target.value)}
                rows={4}
                maxLength={MAX_LENGTH}
                autoFocus
                aria-invalid={Boolean(error)}
              />
              <FormAlert message={error} />
              <div className="flex justify-end gap-2">
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={update.isPending}
                  onClick={() => {
                    setEditing(false);
                    setDraft(note.body);
                    setError(null);
                  }}
                >
                  Cancel
                </Button>
                <Button type="submit" size="sm" disabled={update.isPending}>
                  {update.isPending ? "Saving…" : "Save note"}
                </Button>
              </div>
            </form>
          ) : (
            <p className="mt-1.5 text-sm leading-relaxed break-words whitespace-pre-wrap text-foreground">{note.body}</p>
          )}
        </div>
        {!readOnly && !editing ? (
          <div className="flex shrink-0 gap-0.5">
            <Button
              variant="ghost"
              size="icon"
              className="size-8"
              aria-label="Edit note"
              onClick={() => {
                setDraft(note.body);
                setEditing(true);
              }}
            >
              <Pencil aria-hidden />
            </Button>
            <Button
              variant="ghost"
              size="icon"
              className="size-8 hover:text-danger"
              aria-label="Delete note"
              onClick={() => setConfirmDelete(true)}
            >
              <Trash2 aria-hidden />
            </Button>
          </div>
        ) : null}
      </div>
      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title="Delete this internal note?"
        description="The note text is permanently removed. The matter's history keeps a record that a note was deleted, by whom and when."
        confirmLabel="Delete note"
        destructive
        pending={remove.isPending}
        onConfirm={async () => {
          try {
            await remove.mutateAsync(undefined);
            toast.success("Internal note deleted");
            setConfirmDelete(false);
          } catch (err) {
            setConfirmDelete(false);
            toast.error("Note not deleted", getErrorMessage(err));
          }
        }}
      />
    </li>
  );
}
