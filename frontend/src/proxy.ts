import { NextResponse, type NextRequest } from "next/server";

const SESSION_COOKIE = process.env.NEXT_PUBLIC_SESSION_COOKIE ?? "lexora_session";

/**
 * Optimistic route protection: visitors without any Laravel session cookie are sent
 * to /login before protected HTML is served. This avoids a flash of the app shell
 * but is NOT the security boundary — the session is verified by the API
 * (see AuthGuard), and Laravel authorises every data request.
 */
export function proxy(request: NextRequest) {
  if (request.cookies.has(SESSION_COOKIE)) return NextResponse.next();

  const url = request.nextUrl.clone();
  url.pathname = "/login";
  url.search = `?next=${encodeURIComponent(request.nextUrl.pathname + request.nextUrl.search)}`;
  return NextResponse.redirect(url);
}

export const config = {
  // /client-upload is deliberately NOT listed: it is the public, passwordless client page.
  matcher: ["/dashboard/:path*", "/matters/:path*", "/clients/:path*", "/documents/:path*", "/document-requests/:path*"],
};
