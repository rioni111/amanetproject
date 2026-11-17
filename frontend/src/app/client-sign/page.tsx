import type { Metadata } from "next";

import { ClientSignView } from "@/features/client-sign/client-sign-view";

/**
 * Public, passwordless signing page (outside the authenticated layout and the session proxy). The secure
 * token is in the URL fragment (#…), which the browser never sends to this server, so the HTML contains no
 * secret and can be prerendered. The token is used only in the browser, in a request header.
 */
export const metadata: Metadata = {
  title: "Secure document signing",
  description: "Review and sign a document sent by your law firm.",
  referrer: "no-referrer",
  robots: { index: false, follow: false, nocache: true, googleBot: { index: false, follow: false } },
};

export default function ClientSignPage() {
  return <ClientSignView />;
}
