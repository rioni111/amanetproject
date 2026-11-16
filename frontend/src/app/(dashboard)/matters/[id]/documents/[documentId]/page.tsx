import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";

import { Skeleton } from "@/components/ui/skeleton";
import { DocumentDetailView } from "@/features/documents/document-detail-view";

// Rendered behind the client-side session check in (dashboard)/layout.tsx.
export const instant = false;

export const metadata: Metadata = { title: "Document" };

function parseId(raw: string): number {
  const id = Number(raw);
  if (!/^\d+$/.test(raw) || !Number.isSafeInteger(id) || id < 1) notFound();
  return id;
}

/** With Cache Components, params are runtime data: they are read inside a Suspense boundary. */
export default function DocumentPage({ params }: PageProps<"/matters/[id]/documents/[documentId]">) {
  return (
    <Suspense fallback={<Skeleton className="h-64 w-full rounded-xl" />}>
      {params.then(({ id, documentId }) => (
        <DocumentDetailView matterId={parseId(id)} documentId={parseId(documentId)} />
      ))}
    </Suspense>
  );
}
