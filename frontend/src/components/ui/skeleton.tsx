import { cn } from "@/lib/utils/cn";

function Skeleton({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return <div aria-hidden className={cn("animate-shimmer rounded-md bg-surface-sunken", className)} {...props} />;
}

export { Skeleton };
