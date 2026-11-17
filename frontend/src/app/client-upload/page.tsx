import type { Metadata } from "next";

import { ClientUploadView } from "@/features/client-upload/client-upload-view";

/**
 * Public, passwordless client upload page (outside the authenticated dashboard layout and not
 * matched by the session proxy). The secure token is in the URL fragment (#…), which the browser
 * never sends to this server: the HTML is identical for everyone and contains no secret, so it can
 * be prerendered. The token is read and used only in the browser (see ClientUploadView).
 */
export const metadata: Metadata = {
  title: "Secure document upload",
  description: "Upload a document requested by your law firm.",
  referrer: "no-referrer",
  robots: { index: false, follow: false, nocache: true, googleBot: { index: false, follow: false } },
};

export default function ClientUploadPage() {
  return <ClientUploadView />;
}
