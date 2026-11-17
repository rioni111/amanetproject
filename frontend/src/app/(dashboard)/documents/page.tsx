import type { Metadata } from "next";
import { Suspense } from "react";

import { TableSkeleton } from "@/components/shared/loading-skeleton";
import { DocumentsLibraryView } from "@/features/documents/documents-library-view";

// Rendered behind the client-side session check in (dashboard)/layout.tsx.
export const instant = false;

export const metadata: Metadata = { title: "Documents" };

export default function DocumentsPage() {
  return (
    <Suspense fallback={<TableSkeleton rows={8} columns={6} />}>
      <DocumentsLibraryView />
    </Suspense>
  );
}
