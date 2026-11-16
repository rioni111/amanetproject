import * as React from "react";

import { RequiredMark } from "@/components/shared/form-alert";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils/cn";

interface FormFieldProps {
  id: string;
  label: string;
  /** Shows a required marker (the control itself should also carry aria-required). */
  required?: boolean;
  error?: string;
  hint?: React.ReactNode;
  labelAside?: React.ReactNode;
  className?: string;
  /** Receives the aria props that connect the control to its label, hint and error. */
  children: (aria: { id: string; "aria-invalid": boolean; "aria-describedby"?: string }) => React.ReactNode;
}

export function FormField({ id, label, required, error, hint, labelAside, className, children }: FormFieldProps) {
  const errorId = `${id}-error`;
  const hintId = `${id}-hint`;
  const describedBy = [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(" ") || undefined;

  return (
    <div className={cn("flex flex-col gap-2", className)}>
      <div className="flex items-center justify-between">
        <Label htmlFor={id}>
          {label}
          {required ? <RequiredMark /> : null}
        </Label>
        {labelAside}
      </div>
      {children({ id, "aria-invalid": Boolean(error), "aria-describedby": describedBy })}
      {hint && !error ? (
        <p id={hintId} className="text-xs text-subtle-foreground">
          {hint}
        </p>
      ) : null}
      {error ? (
        <p id={errorId} className="text-xs font-medium text-danger">
          {error}
        </p>
      ) : null}
    </div>
  );
}
