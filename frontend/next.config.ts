import type { NextConfig } from "next";

const apiUrl = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
const isDev = process.env.NODE_ENV === "development";
// Served over HTTPS (production): ask browsers to stay on HTTPS and upgrade any stray http:// request.
const https = apiUrl.startsWith("https://");

/**
 * Security headers. The SPA talks to the Laravel API directly from the browser (credentials: "include"),
 * so the API origin is allowed in connect-src.
 *
 * script-src keeps 'unsafe-inline' deliberately (Phase 7 decision, docs/SECURITY_AUDIT.md): the app uses
 * Partial Prerendering (cacheComponents), whose prerendered shells carry inline bootstrap scripts that cannot
 * receive a per-request nonce — Next.js documents nonce-based CSP as incompatible with PPR. The XSS risk this
 * leaves is mitigated by React's escaping (no dangerouslySetInnerHTML/innerHTML/eval anywhere in src), no
 * third-party scripts, and everything else locked down: no plugins, no framing, no foreign origins.
 */
const securityHeaders = [
  { key: "X-Frame-Options", value: "DENY" },
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=(), payment=(), usb=()" },
  { key: "Cross-Origin-Opener-Policy", value: "same-origin" },
  ...(https ? [{ key: "Strict-Transport-Security", value: "max-age=31536000; includeSubDomains" }] : []),
  {
    key: "Content-Security-Policy",
    value: [
      "default-src 'self'",
      `connect-src 'self' ${apiUrl}${isDev ? " ws:" : ""}`,
      "img-src 'self' data:",
      "font-src 'self'",
      "style-src 'self' 'unsafe-inline'",
      `script-src 'self' 'unsafe-inline'${isDev ? " 'unsafe-eval'" : ""}`,
      "worker-src 'self'",
      "object-src 'none'",
      "frame-src 'none'",
      "frame-ancestors 'none'",
      "base-uri 'self'",
      "form-action 'self'",
      ...(https ? ["upgrade-insecure-requests"] : []),
    ].join("; "),
  },
];

const nextConfig: NextConfig = {
  // The isolated E2E server (playwright.config.ts) builds into its own directory so it can run next
  // to the developer's `next dev`.
  distDir: process.env.NEXT_DIST_DIR ?? ".next",
  cacheComponents: true,
  partialPrefetching: true,
  poweredByHeader: false,
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
  async headers() {
    return [
      { source: "/:path*", headers: securityHeaders },
      // Public client upload page: never leak its URL through Referer, never index it.
      {
        source: "/client-sign",
        headers: [
          { key: "Referrer-Policy", value: "no-referrer" },
          { key: "X-Robots-Tag", value: "noindex, nofollow, noarchive" },
        ],
      },
      {
        source: "/client-upload",
        headers: [
          { key: "Referrer-Policy", value: "no-referrer" },
          { key: "X-Robots-Tag", value: "noindex, nofollow, noarchive" },
        ],
      },
    ];
  },
};

export default nextConfig;
