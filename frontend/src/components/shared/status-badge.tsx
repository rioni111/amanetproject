import { Badge, type BadgeProps } from "@/components/ui/badge";
import { MATTER_STATUS_LABELS } from "@/lib/utils/labels";
import type { MatterStatus } from "@/types/api";

const TONES: Record<MatterStatus, BadgeProps["tone"]> = {
  open: "info",
  in_progress: "primary",
  on_hold: "warning",
  closed: "neutral",
  archived: "outline",
};

const DOTS: Record<MatterStatus, string> = {
  open: "bg-info",
  in_progress: "bg-primary",
  on_hold: "bg-warning",
  closed: "bg-subtle-foreground",
  archived: "bg-border-strong",
};

export function StatusBadge({ status }: { status: MatterStatus }) {
  return (
    <Badge tone={TONES[status]}>
      <span aria-hidden className={`size-1.5 rounded-full ${DOTS[status]}`} />
      {MATTER_STATUS_LABELS[status]}
    </Badge>
  );
}

export const STATUS_DOT_CLASSES = DOTS;
