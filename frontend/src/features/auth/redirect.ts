/** Only allow same-app relative paths as post-login destinations (prevents open redirects). */
export function safeNextPath(next: string | null | undefined, fallback = "/dashboard"): string {
  if (!next || !next.startsWith("/") || next.startsWith("//") || next.startsWith("/\\") || next.startsWith("/login")) {
    return fallback;
  }
  return next;
}
