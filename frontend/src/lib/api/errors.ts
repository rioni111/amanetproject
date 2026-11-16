import type { ApiErrorBody } from "@/types/api";

/**
 * Normalised error for every failed API call. Components only ever deal with this shape.
 */
export class ApiError extends Error {
  readonly status: number;
  readonly fieldErrors: Record<string, string[]>;
  readonly retryAfter: number | null;

  constructor(status: number, message: string, fieldErrors: Record<string, string[]> = {}, retryAfter: number | null = null) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.fieldErrors = fieldErrors;
    this.retryAfter = retryAfter;
  }

  get isUnauthenticated() {
    return this.status === 401;
  }

  get isValidation() {
    return this.status === 422;
  }

  /** First error message for a field, if any. */
  field(name: string): string | undefined {
    return this.fieldErrors[name]?.[0];
  }
}

const FALLBACK_MESSAGES: Record<number, string> = {
  0: "We couldn't reach the server. Check your connection and that the API is running.",
  401: "Your session has ended. Please sign in again.",
  403: "You don't have permission to do that.",
  404: "We couldn't find what you were looking for.",
  419: "Your session expired. Please try again.",
  422: "Please check the highlighted fields.",
  429: "Too many attempts. Please wait a moment and try again.",
  500: "Something went wrong on our side. Please try again.",
  503: "The service is temporarily unavailable. Please try again shortly.",
};

export function fallbackMessage(status: number): string {
  return FALLBACK_MESSAGES[status] ?? (status >= 500 ? FALLBACK_MESSAGES[500] : "The request could not be completed.");
}

export async function toApiError(response: Response): Promise<ApiError> {
  let body: ApiErrorBody = {};
  try {
    body = (await response.json()) as ApiErrorBody;
  } catch {
    // Non-JSON body (e.g. proxy error page) — fall back to a friendly message.
  }

  const retryAfterHeader = response.headers.get("Retry-After");
  const retryAfter = retryAfterHeader ? Number.parseInt(retryAfterHeader, 10) : null;

  // Never surface raw server exception messages for 5xx responses.
  const message = response.status >= 500 || !body.message ? fallbackMessage(response.status) : body.message;

  return new ApiError(response.status, message, body.errors ?? {}, Number.isFinite(retryAfter) ? retryAfter : null);
}

export function getErrorMessage(error: unknown): string {
  if (error instanceof ApiError) return error.message;
  return fallbackMessage(0);
}
