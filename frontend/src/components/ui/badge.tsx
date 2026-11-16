import { cva, type VariantProps } from "class-variance-authority";
import * as React from "react";

import { cn } from "@/lib/utils/cn";

const badgeVariants = cva(
  "inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2 py-0.5 text-xs font-medium leading-5",
  {
    variants: {
      tone: {
        neutral: "border-border bg-surface-muted text-muted-foreground",
        outline: "border-border-strong bg-transparent text-muted-foreground",
        primary: "border-primary/15 bg-primary-soft text-primary-soft-foreground",
        info: "border-info/15 bg-info-soft text-info",
        warning: "border-warning/15 bg-warning-soft text-warning",
        danger: "border-danger/15 bg-danger-soft text-danger",
      },
    },
    defaultVariants: { tone: "neutral" },
  },
);

export interface BadgeProps extends React.HTMLAttributes<HTMLSpanElement>, VariantProps<typeof badgeVariants> {}

function Badge({ className, tone, ...props }: BadgeProps) {
  return <span data-slot="badge" className={cn(badgeVariants({ tone }), className)} {...props} />;
}

export { Badge, badgeVariants };
