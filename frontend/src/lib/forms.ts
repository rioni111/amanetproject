import * as React from "react";
import type { FieldValues, Path, UseFormSetError } from "react-hook-form";

import { ApiError, getErrorMessage } from "@/lib/api/errors";

/**
 * Maps a Laravel 422 response onto the form's fields. Returns a message for the form-level banner
 * when the error is not (only) field-specific — e.g. 409 conflicts, 5xx or network errors.
 */
export function applyServerErrors<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
  fields: readonly Path<T>[],
): string | null {
  if (error instanceof ApiError && error.isValidation) {
    let matched = false;
    for (const field of fields) {
      const message = error.field(field);
      if (message) {
        setError(field, { type: "server", message }, { shouldFocus: !matched });
        matched = true;
      }
    }
    return matched ? null : error.message;
  }
  return getErrorMessage(error);
}

/**
 * Synchronous re-entrancy guard for submit handlers: a double click arrives before React has
 * re-rendered the disabled button, so `disabled` alone cannot prevent a duplicate request.
 */
export function useSubmitGuard() {
  const busy = React.useRef(false);
  return React.useCallback(async <R>(work: () => Promise<R>): Promise<R | undefined> => {
    if (busy.current) return undefined;
    busy.current = true;
    try {
      return await work();
    } finally {
      busy.current = false;
    }
  }, []);
}

/** "" → null for optional text inputs, trimmed. */
export function emptyToNull(value: string | null | undefined): string | null {
  const trimmed = (value ?? "").trim();
  return trimmed === "" ? null : trimmed;
}
