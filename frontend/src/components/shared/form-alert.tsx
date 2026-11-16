import { AlertCircle } from "lucide-react";

/** Form-level error banner (conflicts, server or network errors). Announced immediately. */
export function FormAlert({ message, id }: { message: string | null; id?: string }) {
  if (!message) return null;
  return (
    <div
      id={id}
      role="alert"
      className="flex items-start gap-2.5 rounded-lg border border-danger/20 bg-danger-soft px-3.5 py-3 text-[13px] text-danger"
    >
      <AlertCircle aria-hidden className="mt-px size-4 shrink-0" />
      <span>{message}</span>
    </div>
  );
}

/** Visual + accessible "required" marker for labels. */
export function RequiredMark() {
  return (
    <>
      <span aria-hidden className="ml-0.5 text-danger">
        *
      </span>
      <span className="sr-only"> (required)</span>
    </>
  );
}
