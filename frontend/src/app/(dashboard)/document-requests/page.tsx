import type { Metadata } from "next";
import { Suspense } from "react";

import { ListSkeleton } from "@/components/shared/loading-skeleton";
import { RequestsView } from "@/features/requests/requests-view";

// Rendered behind the client-side session check in (dashboard)/layout.tsx.
export const instant = false;

export const metadata: Metadata = { title: "Client requests" };

export default function DocumentRequestsPage() {
  return (
    <Suspense fallback={<ListSkeleton rows={8} />}>
      <RequestsView />
    </Suspense>
  );
}
