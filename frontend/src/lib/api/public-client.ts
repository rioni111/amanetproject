import type { PublicSigningInfo, PublicSubmissionResult, PublicUploadRequest } from "@/types/api";

import { API_URL } from "./config";

/**
 * API client for the PUBLIC client-upload page — deliberately separate from the administrator client:
 *  - never sends cookies (`credentials: "omit"`, XHR `withCredentials = false`), so an administrator
 *    session in the same browser is never used or exposed;
 *  - the upload token travels in the X-Upload-Token header, never in a URL (no server/proxy logs);
 *  - no Referer is sent, responses are not cached, nothing is stored (no storage, no query cache).
 */

export const UPLOAD_TOKEN_HEADER = "X-Upload-Token";
const TOKEN_PATTERN = /^[A-Za-z0-9_-]{43}$/;

export function isWellFormedToken(token: string): boolean {
  return TOKEN_PATTERN.test(token);
}

export type PublicErrorCode =
  | "link_invalid"
  | "link_expired"
  | "link_used"
  | "request_approved"
  | "request_cancelled"
  | "request_unavailable"
  | "too_many_attempts"
  | "already_signed"
  | "pdf_unsupported"
  | "signing_failed"
  | "origin_not_allowed"
  | "validation"
  | "network"
  | "server";

export class PublicApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: PublicErrorCode,
    message: string,
    readonly fieldErrors: Record<string, string[]> = {},
  ) {
    super(message);
    this.name = "PublicApiError";
  }
}

const NETWORK_MESSAGE = "We couldn't reach the firm's server. Check your internet connection and try again.";

function toError(status: number, body: { message?: string; code?: string; errors?: Record<string, string[]> } | null): PublicApiError {
  if (status === 422 && body?.code) return new PublicApiError(422, body.code as PublicErrorCode, body.message ?? "The request could not be completed.");
  if (status === 422) return new PublicApiError(422, "validation", body?.message ?? "Please check the file and try again.", body?.errors ?? {});
  if (status === 409) return new PublicApiError(409, "validation", body?.message ?? "The request could not be completed.");
  if (body?.code) return new PublicApiError(status, body.code as PublicErrorCode, body.message ?? "This link cannot be used.");
  if (status === 413) return new PublicApiError(413, "validation", body?.message ?? "The file is too large.", { file: [body?.message ?? "The file is too large."] });
  if (status === 429) return new PublicApiError(429, "too_many_attempts", "Too many attempts. Please wait a few minutes and try again.");
  return new PublicApiError(status, "server", "Something went wrong on the firm's side. Please try again in a moment.");
}

function parse(text: string): { message?: string; code?: string; errors?: Record<string, string[]>; data?: unknown } | null {
  try {
    return text ? JSON.parse(text) : null;
  } catch {
    return null;
  }
}

export async function fetchUploadRequest(token: string, signal?: AbortSignal): Promise<PublicUploadRequest> {
  let response: Response;
  try {
    response = await fetch(`${API_URL}/api/public/document-upload`, {
      method: "GET",
      headers: { Accept: "application/json", [UPLOAD_TOKEN_HEADER]: token },
      credentials: "omit",
      cache: "no-store",
      referrerPolicy: "no-referrer",
      signal,
    });
  } catch (error) {
    if (error instanceof DOMException && error.name === "AbortError") throw error;
    throw new PublicApiError(0, "network", NETWORK_MESSAGE);
  }
  const body = parse(await response.text());
  if (!response.ok) throw toError(response.status, body);
  return (body as { data: PublicUploadRequest }).data;
}

// ------------------------------------------------------------------ Phase 5: public signing

export const SIGNING_TOKEN_HEADER = "X-Signing-Token";

export type PublicSigningCode = PublicErrorCode | "already_signed";

async function signingFetch(token: string, path: string, init: RequestInit = {}): Promise<Response> {
  try {
    return await fetch(`${API_URL}/api/public/signing${path}`, {
      ...init,
      headers: { Accept: "application/json", [SIGNING_TOKEN_HEADER]: token, ...(init.headers ?? {}) },
      credentials: "omit",
      cache: "no-store",
      referrerPolicy: "no-referrer",
    });
  } catch (error) {
    if (error instanceof DOMException && error.name === "AbortError") throw error;
    throw new PublicApiError(0, "network", NETWORK_MESSAGE);
  }
}

async function signingJson<T>(token: string, path: string, init: RequestInit = {}): Promise<T> {
  const response = await signingFetch(token, path, init);
  const body = parse(await response.text());
  if (!response.ok) throw toError(response.status, body);
  return body as T;
}

export function fetchSigningInfo(token: string, signal?: AbortSignal) {
  return signingJson<{ data: PublicSigningInfo }>(token, "", { signal }).then((r) => r.data);
}

/** The designated PDF as bytes (token in a header; no URL that could be shared or cached). */
export async function fetchSigningDocument(token: string, signal?: AbortSignal): Promise<Uint8Array> {
  const response = await signingFetch(token, "/document", { signal });
  if (!response.ok) throw toError(response.status, parse(await response.text()));
  return new Uint8Array(await response.arrayBuffer());
}

export function recordSigningProgress(token: string, step: "view" | "start") {
  return signingJson<{ data: { status: string } }>(token, `/${step}`, { method: "POST" }).then((r) => r.data.status);
}

export function completeSigning(token: string, payload: object) {
  return signingJson<{ message: string; data: { status: string; signed_at: string; document_title: string } }>(token, "/complete", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

/** multipart upload with real progress (XMLHttpRequest); `onProgress` receives 0–1 from the browser. */
export function submitUpload(
  token: string,
  file: File,
  message: string | null,
  onProgress?: (fraction: number) => void,
): Promise<{ message: string; data: PublicSubmissionResult }> {
  const form = new FormData();
  form.append("file", file);
  if (message) form.append("message", message);

  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open("POST", `${API_URL}/api/public/document-upload/submit`);
    xhr.withCredentials = false;
    xhr.setRequestHeader("Accept", "application/json");
    xhr.setRequestHeader(UPLOAD_TOKEN_HEADER, token);
    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable && onProgress) onProgress(event.loaded / event.total);
    };
    xhr.onload = () => {
      const body = parse(xhr.responseText);
      if (xhr.status >= 200 && xhr.status < 300) resolve(body as { message: string; data: PublicSubmissionResult });
      else reject(toError(xhr.status, body));
    };
    xhr.onerror = () => reject(new PublicApiError(0, "network", NETWORK_MESSAGE));
    xhr.ontimeout = () => reject(new PublicApiError(0, "network", NETWORK_MESSAGE));
    xhr.send(form);
  });
}
