import type { Metadata } from "next";
import { Suspense } from "react";

import { TableSkeleton } from "@/components/shared/loading-skeleton";
import { MattersView } from "@/features/matters/matters-view";

// Rendered behind the client-side session check in (dashboard)/layout.tsx.
export const instant = false;

export const metadata: Metadata = { title: "Matters" };

export default function MattersPage() {
  return (
    <Suspense fallback={<TableSkeleton rows={8} columns={6} />}>
      <MattersView />
    </Suspense>
  );
}
