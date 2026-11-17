import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";

import { Skeleton } from "@/components/ui/skeleton";
import { RequestDetailView } from "@/features/requests/request-detail-view";

// Rendered behind the client-side session check in (dashboard)/layout.tsx.
export const instant = false;

export const metadata: Metadata = { title: "Client request" };

function parseId(raw: string): number {
  const id = Number(raw);
  if (!/^\d+$/.test(raw) || !Number.isSafeInteger(id) || id < 1) notFound();
  return id;
}

/** With Cache Components, params are runtime data: they are read inside a Suspense boundary. */
export default function DocumentRequestPage({ params }: PageProps<"/matters/[id]/requests/[requestId]">) {
  return (
    <Suspense fallback={<Skeleton className="h-64 w-full rounded-xl" />}>
      {params.then(({ id, requestId }) => (
        <RequestDetailView matterId={parseId(id)} requestId={parseId(requestId)} />
      ))}
    </Suspense>
  );
}
