import { FileQuestion } from "lucide-react";
import Link from "next/link";

import { BrandLockup } from "@/components/shared/brand-mark";
import { Button } from "@/components/ui/button";

export default function NotFound() {
  return (
    <main className="flex min-h-dvh flex-col items-center justify-center gap-8 bg-background px-4 text-center">
      <BrandLockup />
      <div className="flex flex-col items-center">
        <div className="mb-4 flex size-11 items-center justify-center rounded-full border border-border bg-surface-muted">
          <FileQuestion aria-hidden className="size-5 text-subtle-foreground" />
        </div>
        <h1 className="text-xl font-semibold tracking-[-0.01em]">Page not found</h1>
        <p className="mt-1.5 max-w-sm text-sm text-muted-foreground">The page you&apos;re looking for doesn&apos;t exist or has moved.</p>
        <Button asChild className="mt-6">
          <Link href="/dashboard">Back to dashboard</Link>
        </Button>
      </div>
    </main>
  );
}
