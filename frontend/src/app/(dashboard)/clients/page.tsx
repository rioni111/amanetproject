import type { Metadata } from "next";
import { Suspense } from "react";

import { TableSkeleton } from "@/components/shared/loading-skeleton";
import { ClientsView } from "@/features/clients/clients-view";

// Rendered behind the client-side session check in (dashboard)/layout.tsx.
export const instant = false;

export const metadata: Metadata = { title: "Clients" };

export default function ClientsPage() {
  return (
    <Suspense fallback={<TableSkeleton rows={8} columns={4} />}>
      <ClientsView />
    </Suspense>
  );
}
