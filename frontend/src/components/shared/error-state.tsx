"use client";

import { AlertCircle, RotateCw } from "lucide-react";

import { Button } from "@/components/ui/button";
import { getErrorMessage } from "@/lib/api/errors";
import { cn } from "@/lib/utils/cn";

interface ErrorStateProps {
  error: unknown;
  title?: string;
  onRetry?: () => void;
  retrying?: boolean;
  className?: string;
}

export function ErrorState({ error, title = "We couldn't load this data", onRetry, retrying, className }: ErrorStateProps) {
  return (
    <div role="alert" className={cn("flex flex-col items-center justify-center px-6 py-12 text-center", className)}>
      <div className="mb-4 flex size-11 items-center justify-center rounded-full border border-danger/15 bg-danger-soft">
        <AlertCircle aria-hidden className="size-5 text-danger" />
      </div>
      <p className="text-sm font-semibold text-foreground">{title}</p>
      <p className="mt-1 max-w-sm text-[13px] text-muted-foreground">{getErrorMessage(error)}</p>
      {onRetry ? (
        <Button variant="secondary" size="sm" className="mt-5" onClick={onRetry} disabled={retrying}>
          <RotateCw aria-hidden className={cn(retrying && "animate-spin")} />
          {retrying ? "Retrying…" : "Try again"}
        </Button>
      ) : null}
    </div>
  );
}
