"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { AlertTriangle, CalendarClock, CheckCircle2, Circle, ListChecks, Loader2, Pencil, Plus, Trash2 } from "lucide-react";
import * as React from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";

import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { FormAlert } from "@/components/shared/form-alert";
import { FormField } from "@/components/shared/form-field";
import { ListSkeleton } from "@/components/shared/loading-skeleton";
import { PriorityBadge } from "@/components/shared/priority-badge";
import { useToast } from "@/components/shared/toaster";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog, DialogClose, DialogContent, DialogFooter } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { getErrorMessage } from "@/lib/api/errors";
import { applyServerErrors, emptyToNull, useSubmitGuard } from "@/lib/forms";
import { cn } from "@/lib/utils/cn";
import { formatDate, formatDateTime, relativeDays } from "@/lib/utils/format";
import { PRIORITY_LABELS, TASK_STATUS_LABELS } from "@/lib/utils/labels";
import { PRIORITIES, TASK_STATUSES, type Matter, type Task } from "@/types/api";

import { useCreateTask, useDeleteTask, useTasks, useUpdateTask, type TaskFilter } from "./api";

const FILTERS: { value: TaskFilter; label: string }[] = [
  { value: "", label: "All tasks" },
  { value: "open", label: "Outstanding" },
  { value: "todo", label: "To do" },
  { value: "in_progress", label: "In progress" },
  { value: "completed", label: "Completed" },
];

export function TasksPanel({ matter }: { matter: Matter }) {
  const [filter, setFilter] = React.useState<TaskFilter>("");
  const tasks = useTasks(matter.id, filter);
  const [editing, setEditing] = React.useState<Task | null>(null);
  const [creating, setCreating] = React.useState(false);
  const readOnly = matter.status === "archived";

  return (
    <section aria-labelledby="tasks-heading" className="flex flex-col gap-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 id="tasks-heading" className="text-[15px] font-semibold text-foreground">
            Tasks
          </h2>
          <p className="mt-0.5 text-[13px] text-muted-foreground">
            {matter.open_tasks_count ?? 0} outstanding
            {matter.overdue_tasks_count ? <span className="text-danger"> · {matter.overdue_tasks_count} overdue</span> : null}
            {" · "}
            {matter.completed_tasks_count ?? 0} completed
          </p>
        </div>
        <div className="flex gap-2">
          <label htmlFor="task-filter" className="sr-only">
            Filter tasks
          </label>
          <Select id="task-filter" value={filter} onChange={(e) => setFilter(e.target.value as TaskFilter)} className="w-40">
            {FILTERS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
          {!readOnly ? (
            <Button onClick={() => setCreating(true)}>
              <Plus aria-hidden />
              Add task
            </Button>
          ) : null}
        </div>
      </div>

      {tasks.isPending ? (
        <ListSkeleton rows={3} />
      ) : tasks.isError ? (
        <ErrorState error={tasks.error} title="We couldn't load tasks" onRetry={() => tasks.refetch()} retrying={tasks.isRefetching} />
      ) : tasks.data.data.length === 0 ? (
        <EmptyState
          icon={ListChecks}
          title={filter ? "No tasks match this filter" : "No tasks yet"}
          description={
            filter
              ? "Choose another filter to see more tasks."
              : readOnly
                ? "This matter is archived."
                : "Break the matter down into steps and deadlines."
          }
          action={
            !filter && !readOnly ? (
              <Button size="sm" onClick={() => setCreating(true)}>
                <Plus aria-hidden />
                Add the first task
              </Button>
            ) : undefined
          }
        />
      ) : (
        <ul aria-label="Tasks" className="divide-y divide-border overflow-hidden rounded-xl border border-border bg-surface">
          {tasks.data.data.map((task) => (
            <TaskRow key={task.id} matter={matter} task={task} readOnly={readOnly} onEdit={() => setEditing(task)} />
          ))}
        </ul>
      )}

      <TaskFormDialog matter={matter} open={creating} onOpenChange={setCreating} />
      <TaskFormDialog
        matter={matter}
        task={editing ?? undefined}
        open={editing !== null}
        onOpenChange={(open) => !open && setEditing(null)}
      />
    </section>
  );
}

function TaskRow({ matter, task, readOnly, onEdit }: { matter: Matter; task: Task; readOnly: boolean; onEdit: () => void }) {
  const update = useUpdateTask(matter.id);
  const remove = useDeleteTask(matter.id);
  const toast = useToast();
  const guard = useSubmitGuard();
  const [confirmDelete, setConfirmDelete] = React.useState(false);
  const completed = task.status === "completed";

  const toggle = () =>
    guard(async () => {
      try {
        await update.mutateAsync({ id: task.id, status: completed ? "todo" : "completed", lock_version: task.lock_version });
        toast.success(completed ? "Task reopened" : "Task completed", task.title);
      } catch (error) {
        toast.error(completed ? "Task not reopened" : "Task not completed", getErrorMessage(error));
      }
    });

  return (
    <li className={cn("flex items-start gap-3 px-4 py-3.5", completed && "bg-surface-muted/40")} data-testid="task-row">
      <button
        type="button"
        role="checkbox"
        aria-checked={completed}
        aria-label={completed ? `Reopen task: ${task.title}` : `Mark task as completed: ${task.title}`}
        disabled={readOnly || update.isPending}
        onClick={() => void toggle()}
        className="mt-0.5 inline-flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-full text-subtle-foreground transition-colors hover:text-primary disabled:cursor-not-allowed disabled:opacity-60"
      >
        {update.isPending ? (
          <Loader2 aria-hidden className="size-4 animate-spin" />
        ) : completed ? (
          <CheckCircle2 aria-hidden className="size-5 text-primary" />
        ) : (
          <Circle aria-hidden className="size-5" />
        )}
      </button>
      <div className="min-w-0 flex-1">
        <p className={cn("text-sm font-medium break-words text-foreground", completed && "text-muted-foreground line-through")}>
          {task.title}
        </p>
        {task.description ? (
          <p className="mt-0.5 text-[13px] break-words whitespace-pre-line text-muted-foreground">{task.description}</p>
        ) : null}
        <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs">
          <Badge tone={completed ? "neutral" : task.status === "in_progress" ? "primary" : "info"}>{TASK_STATUS_LABELS[task.status]}</Badge>
          <PriorityBadge priority={task.priority} className="text-xs" />
          {task.due_date ? (
            <span className={cn("inline-flex items-center gap-1", task.is_overdue ? "font-medium text-danger" : "text-muted-foreground")}>
              {task.is_overdue ? <AlertTriangle aria-hidden className="size-3" /> : <CalendarClock aria-hidden className="size-3" />}
              {task.is_overdue ? "Overdue · " : "Due "}
              {formatDate(task.due_date)}
              {!completed ? <span className="text-subtle-foreground">({relativeDays(task.due_date)})</span> : null}
            </span>
          ) : (
            <span className="text-subtle-foreground">No due date</span>
          )}
          {completed && task.completed_at ? (
            <span className="text-subtle-foreground">Completed {formatDateTime(task.completed_at)}</span>
          ) : null}
        </div>
      </div>
      {!readOnly ? (
        <div className="flex shrink-0 items-center gap-0.5">
          <Button variant="ghost" size="icon" className="size-8" onClick={onEdit} aria-label={`Edit task: ${task.title}`}>
            <Pencil aria-hidden />
          </Button>
          <Button
            variant="ghost"
            size="icon"
            className="size-8 hover:text-danger"
            onClick={() => setConfirmDelete(true)}
            aria-label={`Delete task: ${task.title}`}
          >
            <Trash2 aria-hidden />
          </Button>
        </div>
      ) : null}
      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title="Delete this task?"
        description={
          <p>
            <strong className="text-foreground">{task.title}</strong> will be removed. The deletion is recorded in the matter&apos;s
            history.
          </p>
        }
        confirmLabel="Delete task"
        destructive
        pending={remove.isPending}
        onConfirm={async () => {
          try {
            await remove.mutateAsync(task.id);
            toast.success("Task deleted", task.title);
            setConfirmDelete(false);
          } catch (error) {
            setConfirmDelete(false);
            toast.error("Task not deleted", getErrorMessage(error));
          }
        }}
      />
    </li>
  );
}

const taskSchema = z.object({
  title: z.string().trim().min(1, "Enter a task title.").max(255, "Use at most 255 characters."),
  description: z.string().max(5000, "Use at most 5,000 characters."),
  status: z.enum(TASK_STATUSES),
  priority: z.enum(PRIORITIES),
  due_date: z.string().regex(/^(\d{4}-\d{2}-\d{2})?$/, "Enter a valid date."),
});
type TaskValues = z.infer<typeof taskSchema>;
const TASK_FIELDS = ["title", "description", "status", "priority", "due_date"] as const;

function TaskFormDialog({
  matter,
  task,
  open,
  onOpenChange,
}: {
  matter: Matter;
  task?: Task;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const create = useCreateTask(matter.id);
  const update = useUpdateTask(matter.id);
  const toast = useToast();
  const guard = useSubmitGuard();
  const [formError, setFormError] = React.useState<string | null>(null);
  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<TaskValues>({ resolver: zodResolver(taskSchema) });

  const wasOpen = React.useRef(false);
  const openedVersion = React.useRef<number | undefined>(undefined);
  React.useEffect(() => {
    if (open && !wasOpen.current) {
      reset({
        title: task?.title ?? "",
        description: task?.description ?? "",
        status: task?.status ?? "todo",
        priority: task?.priority ?? "medium",
        due_date: task?.due_date ?? "",
      });
      openedVersion.current = task?.lock_version;
      setFormError(null);
    }
    wasOpen.current = open;
  }, [open, task, reset]);

  const pending = create.isPending || update.isPending;

  // handleSubmit is invoked inside the submit event (not during render), so reading the
  // opened-version ref happens in an event handler.
  const onSubmit = (event: React.FormEvent<HTMLFormElement>) =>
    void handleSubmit((values) =>
      guard(async () => {
        setFormError(null);
        const input = {
          title: values.title.trim(),
          description: emptyToNull(values.description),
          status: values.status,
          priority: values.priority,
          due_date: emptyToNull(values.due_date),
        };
        try {
          if (task) {
            await update.mutateAsync({ id: task.id, ...input, lock_version: openedVersion.current ?? task.lock_version });
            toast.success("Task updated", input.title);
          } else {
            await create.mutateAsync(input);
            toast.success("Task added", input.title);
          }
          onOpenChange(false);
        } catch (error) {
          setFormError(applyServerErrors(error, setError, TASK_FIELDS));
        }
      }),
    )(event);

  return (
    <Dialog open={open} onOpenChange={(next) => (!pending ? onOpenChange(next) : undefined)}>
      <DialogContent title={task ? "Edit task" : "New task"} description={`${matter.matter_number} · ${matter.title}`}>
        <form onSubmit={onSubmit} noValidate aria-label={task ? "Edit task" : "New task"}>
          <div className="flex flex-col gap-5 px-5 py-5">
            <FormAlert message={formError} />
            <FormField id="task-title" label="Task title" required error={errors.title?.message}>
              {(aria) => (
                <Input
                  {...aria}
                  {...register("title")}
                  aria-required
                  placeholder="e.g. Send first redline to counterparty"
                  autoComplete="off"
                />
              )}
            </FormField>
            <div className="grid gap-5 sm:grid-cols-3">
              <FormField id="task-status" label="Status" required error={errors.status?.message}>
                {(aria) => (
                  <Select {...aria} {...register("status")} aria-required>
                    {TASK_STATUSES.map((status) => (
                      <option key={status} value={status}>
                        {TASK_STATUS_LABELS[status]}
                      </option>
                    ))}
                  </Select>
                )}
              </FormField>
              <FormField id="task-priority" label="Priority" required error={errors.priority?.message}>
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
              <FormField id="task-due" label="Due date" error={errors.due_date?.message}>
                {(aria) => <Input {...aria} {...register("due_date")} type="date" min="1990-01-01" max="2099-12-31" />}
              </FormField>
            </div>
            <FormField id="task-description" label="Description" error={errors.description?.message} hint="Optional">
              {(aria) => <Textarea {...aria} {...register("description")} rows={3} placeholder="Details, instructions or context" />}
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
              {pending ? "Saving…" : task ? "Save task" : "Add task"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
