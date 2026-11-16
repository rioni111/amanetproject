import { API_URL } from "./config";
import { ApiError, fallbackMessage, toApiError } from "./errors";

/**
 * Thin, typed fetch wrapper for the Laravel API (Sanctum SPA authentication).
 *
 * - Always sends cookies (`credentials: "include"`); no tokens are ever stored in JS.
 * - For state-changing requests, reads the XSRF-TOKEN cookie set by
 *   GET /sanctum/csrf-cookie and echoes it in the X-XSRF-TOKEN header.
 * - Retries once after refreshing the CSRF cookie when Laravel answers 419.
 */

type Query = Record<string, string | number | boolean | null | undefined>;

interface RequestOptions {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  body?: unknown;
  query?: Query;
  signal?: AbortSignal;
}

const MUTATING = new Set(["POST", "PUT", "PATCH", "DELETE"]);

function readCookie(name: string): string | null {
  if (typeof document === "undefined") return null;
  const match = document.cookie.split("; ").find((row) => row.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

export function buildUrl(path: string, query?: Query): string {
  const url = new URL(`${API_URL}${path.startsWith("/") ? path : `/${path}`}`);
  if (query) {
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== null && value !== "") url.searchParams.set(key, String(value));
    }
  }
  return url.toString();
}

/** Ask Laravel to (re)issue the XSRF-TOKEN cookie. */
export async function ensureCsrfCookie(force = false): Promise<void> {
  if (!force && readCookie("XSRF-TOKEN")) return;
  try {
    const response = await fetch(`${API_URL}/sanctum/csrf-cookie`, {
      credentials: "include",
      headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
    });
    if (!response.ok) throw await toApiError(response);
  } catch (error) {
    if (error instanceof ApiError) throw error;
    throw new ApiError(0, fallbackMessage(0));
  }
}

async function send(path: string, options: RequestOptions): Promise<Response> {
  const method = options.method ?? "GET";
  const headers: Record<string, string> = {
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
  };

  if (options.body !== undefined) headers["Content-Type"] = "application/json";

  if (MUTATING.has(method)) {
    await ensureCsrfCookie();
    const token = readCookie("XSRF-TOKEN");
    if (token) headers["X-XSRF-TOKEN"] = token;
  }

  try {
    return await fetch(buildUrl(path, options.query), {
      method,
      headers,
      credentials: "include",
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
      signal: options.signal,
      cache: "no-store",
    });
  } catch (error) {
    if (error instanceof DOMException && error.name === "AbortError") throw error;
    throw new ApiError(0, fallbackMessage(0));
  }
}

export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  let response = await send(path, options);

  // CSRF token rotated or expired: refresh once and retry.
  if (response.status === 419 && MUTATING.has(options.method ?? "GET")) {
    await ensureCsrfCookie(true);
    response = await send(path, options);
  }

  if (!response.ok) throw await toApiError(response);
  if (response.status === 204) return undefined as T;
  return (await response.json()) as T;
}

export const api = {
  get: <T>(path: string, query?: Query, signal?: AbortSignal) => apiRequest<T>(path, { query, signal }),
  post: <T>(path: string, body?: unknown) => apiRequest<T>(path, { method: "POST", body }),
  patch: <T>(path: string, body?: unknown) => apiRequest<T>(path, { method: "PATCH", body }),
  delete: (path: string) => apiRequest<void>(path, { method: "DELETE" }),
};
