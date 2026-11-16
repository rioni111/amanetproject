import { ChevronDown } from "lucide-react";
import * as React from "react";

import { cn } from "@/lib/utils/cn";

/**
 * Styled native <select>: fully keyboard and screen-reader accessible on every platform.
 */
function Select({ className, children, ...props }: React.SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <div className={cn("relative", className)}>
      <select
        data-slot="select"
        className={cn(
          "h-9 w-full cursor-pointer appearance-none rounded-md border border-input bg-surface pr-9 pl-3 text-sm text-foreground shadow-[var(--shadow-card)] transition-colors",
          "hover:border-border-strong focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-primary/25",
        )}
        {...props}
      >
        {children}
      </select>
      <ChevronDown aria-hidden className="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-subtle-foreground" />
    </div>
  );
}

export { Select };
