import type { LucideIcon } from "lucide-react";
import * as React from "react";

import { cn } from "@/lib/utils/cn";

interface EmptyStateProps {
  icon: LucideIcon;
  title: string;
  description?: string;
  action?: React.ReactNode;
  className?: string;
}

export function EmptyState({ icon: Icon, title, description, action, className }: EmptyStateProps) {
  return (
    <div className={cn("flex flex-col items-center justify-center px-6 py-14 text-center", className)}>
      <div className="mb-4 flex size-11 items-center justify-center rounded-full border border-border bg-surface-muted">
        <Icon aria-hidden className="size-5 text-subtle-foreground" />
      </div>
      <p className="text-sm font-semibold text-foreground">{title}</p>
      {description ? <p className="mt-1 max-w-sm text-[13px] text-muted-foreground">{description}</p> : null}
      {action ? <div className="mt-5">{action}</div> : null}
    </div>
  );
}
