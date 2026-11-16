import * as React from "react";

import { cn } from "@/lib/utils/cn";

function Input({ className, type = "text", ...props }: React.InputHTMLAttributes<HTMLInputElement>) {
  return (
    <input
      type={type}
      data-slot="input"
      className={cn(
        "flex h-10 w-full min-w-0 rounded-md border border-input bg-surface px-3 text-sm text-foreground shadow-[var(--shadow-card)] transition-colors",
        "placeholder:text-subtle-foreground hover:border-border-strong",
        "focus-visible:border-primary focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-primary/25",
        "disabled:cursor-not-allowed disabled:opacity-60",
        "aria-invalid:border-danger aria-invalid:focus-visible:outline-danger/25",
        className,
      )}
      {...props}
    />
  );
}

export { Input };
