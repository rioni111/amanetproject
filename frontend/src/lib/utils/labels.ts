import type { ClientType, DocumentCategory, MatterStatus, Priority, TaskStatus } from "@/types/api";

export const DOCUMENT_CATEGORY_LABELS: Record<DocumentCategory, string> = {
  contracts: "Contracts",
  agreements: "Agreements",
  correspondence: "Correspondence",
  evidence: "Evidence",
  court_documents: "Court documents",
  identification: "Identification",
  other: "Other",
};

export const TASK_STATUS_LABELS: Record<TaskStatus, string> = {
  todo: "To do",
  in_progress: "In progress",
  completed: "Completed",
};

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
