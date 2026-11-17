import type {
  ClientType,
  DocumentCategory,
  DocumentRequestStatus,
  MatterStatus,
  Priority,
  SecureLinkState,
  SubmissionReviewStatus,
  TaskStatus,
} from "@/types/api";

export const REQUEST_STATUS_LABELS: Record<DocumentRequestStatus, string> = {
  pending: "Awaiting client",
  submitted: "Submitted",
  under_review: "Under review",
  correction_requested: "Correction requested",
  approved: "Approved",
  cancelled: "Cancelled",
};

export const SUBMISSION_REVIEW_LABELS: Record<SubmissionReviewStatus, string> = {
  pending: "Awaiting review",
  in_review: "In review",
  approved: "Approved",
  correction_requested: "Correction requested",
  cancelled: "Not reviewed (request cancelled)",
};

export const LINK_STATE_LABELS: Record<SecureLinkState, string> = {
  active: "Active",
  expired: "Expired",
  revoked: "Revoked",
  used: "Used",
};

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
