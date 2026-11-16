import type { ClientType, MatterStatus, Priority } from "@/types/api";

export const MATTER_STATUS_LABELS: Record<MatterStatus, string> = {
  open: "Open",
  in_progress: "In progress",
  on_hold: "On hold",
  closed: "Closed",
  archived: "Archived",
};

export const PRIORITY_LABELS: Record<Priority, string> = {
  low: "Low",
  medium: "Medium",
  high: "High",
  urgent: "Urgent",
};

export const CLIENT_TYPE_LABELS: Record<ClientType, string> = {
  individual: "Individual",
  organization: "Organization",
};
