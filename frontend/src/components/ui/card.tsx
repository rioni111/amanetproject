import * as React from "react";

import { cn } from "@/lib/utils/cn";

function Card({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div data-slot="card" className={cn("rounded-xl border border-border bg-surface shadow-[var(--shadow-card)]", className)} {...props} />
  );
}

function CardHeader({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return <div className={cn("flex items-start justify-between gap-4 px-5 pt-5 pb-4", className)} {...props} />;
}

function CardTitle({ className, ...props }: React.HTMLAttributes<HTMLHeadingElement>) {
  return <h2 className={cn("text-[15px] font-semibold tracking-[-0.01em] text-foreground", className)} {...props} />;
}

function CardDescription({ className, ...props }: React.HTMLAttributes<HTMLParagraphElement>) {
  return <p className={cn("mt-1 text-[13px] text-muted-foreground", className)} {...props} />;
}

function CardContent({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return <div className={cn("px-5 pb-5", className)} {...props} />;
}

export { Card, CardContent, CardDescription, CardHeader, CardTitle };
