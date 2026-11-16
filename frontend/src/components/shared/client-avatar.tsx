import { Building2 } from "lucide-react";

import { cn } from "@/lib/utils/cn";
import { initials } from "@/lib/utils/format";
import type { ClientType } from "@/types/api";

export function ClientAvatar({ name, type, className }: { name: string; type: ClientType; className?: string }) {
  return (
    <span
      aria-hidden
      className={cn(
        "flex size-9 shrink-0 items-center justify-center rounded-lg border text-xs font-semibold",
        type === "organization"
          ? "border-primary/15 bg-primary-soft text-primary-soft-foreground"
          : "border-border bg-surface-muted text-muted-foreground",
        className,
      )}
    >
      {type === "organization" ? <Building2 className="size-4" /> : initials(name)}
    </span>
  );
}
