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

/**
 * Authenticated binary GET (PDF preview/download). Same cookie-based session as every other call;
 * no credentials ever appear in the URL. Errors are normalised to ApiError (JSON error bodies).
 */
export async function apiBlob(path: string, signal?: AbortSignal): Promise<Blob> {
  const response = await send(path, { signal });
  if (!response.ok) throw await toApiError(response);
  return await response.blob();
}

/**
 * multipart/form-data upload through XMLHttpRequest, because fetch cannot report upload progress.
 * `onProgress` receives real byte counts from the browser (0–1); nothing is simulated.
 * CSRF and cookies are handled exactly as in `send()`, including one retry after a 419.
 */
export async function apiUpload<T>(path: string, form: FormData, onProgress?: (fraction: number) => void): Promise<T> {
  const attempt = async (): Promise<{ status: number; body: string; retryAfter: string | null }> => {
    await ensureCsrfCookie();
    const token = readCookie("XSRF-TOKEN");
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open("POST", buildUrl(path));
      xhr.withCredentials = true;
      xhr.setRequestHeader("Accept", "application/json");
      xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
      if (token) xhr.setRequestHeader("X-XSRF-TOKEN", token);
      xhr.upload.onprogress = (event) => {
        if (event.lengthComputable && onProgress) onProgress(event.loaded / event.total);
      };
      xhr.onload = () => resolve({ status: xhr.status, body: xhr.responseText, retryAfter: xhr.getResponseHeader("Retry-After") });
      xhr.onerror = () => reject(new ApiError(0, fallbackMessage(0)));
      xhr.send(form);
    });
  };

  let result = await attempt();
  if (result.status === 419) {
    await ensureCsrfCookie(true);
    result = await attempt();
  }
  const response = new Response(result.body || null, {
    status: result.status,
    headers: { "Content-Type": "application/json", ...(result.retryAfter ? { "Retry-After": result.retryAfter } : {}) },
  });
  if (!response.ok) throw await toApiError(response);
  return (await response.json()) as T;
}

export const api = {
  get: <T>(path: string, query?: Query, signal?: AbortSignal) => apiRequest<T>(path, { query, signal }),
  post: <T>(path: string, body?: unknown) => apiRequest<T>(path, { method: "POST", body }),
  patch: <T>(path: string, body?: unknown) => apiRequest<T>(path, { method: "PATCH", body }),
  delete: (path: string) => apiRequest<void>(path, { method: "DELETE" }),
};
