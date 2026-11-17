import type { Metadata } from "next";
import { Suspense } from "react";

import { ListSkeleton } from "@/components/shared/loading-skeleton";
import { SignaturesView } from "@/features/signing/signatures-view";

// Rendered behind the client-side session check in (dashboard)/layout.tsx.
export const instant = false;

export const metadata: Metadata = { title: "Signatures" };

export default function SignaturesPage() {
  return (
    <Suspense fallback={<ListSkeleton rows={8} />}>
      <SignaturesView />
    </Suspense>
  );
}