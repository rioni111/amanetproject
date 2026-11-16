import { cn } from "@/lib/utils/cn";
import { PRIORITY_LABELS } from "@/lib/utils/labels";
import type { Priority } from "@/types/api";

const BARS: Record<Priority, number> = { low: 1, medium: 2, high: 3, urgent: 4 };

const COLORS: Record<Priority, { text: string; bar: string }> = {
  low: { text: "text-muted-foreground", bar: "bg-subtle-foreground" },
  medium: { text: "text-info", bar: "bg-info" },
  high: { text: "text-warning", bar: "bg-[#c2810f]" },
  urgent: { text: "text-danger", bar: "bg-danger" },
};

/**
 * Priority shown as text plus a 4-step signal meter, so meaning never relies on colour alone.
 */
export function PriorityBadge({ priority, className }: { priority: Priority; className?: string }) {
  const level = BARS[priority];
  const color = COLORS[priority];

  return (
    <span className={cn("inline-flex items-center gap-2 text-[13px] font-medium whitespace-nowrap", color.text, className)}>
      <span aria-hidden className="flex h-3 items-end gap-[2px]">
        {[1, 2, 3, 4].map((step) => (
          <span
            key={step}
            className={cn("w-[3px] rounded-[1px]", step <= level ? color.bar : "bg-border-strong")}
            style={{ height: `${4 + step * 2}px` }}
          />
        ))}
      </span>
      {PRIORITY_LABELS[priority]}
      <span className="sr-only"> priority</span>
    </span>
  );
}
