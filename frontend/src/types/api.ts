/**
 * Types mirroring the Laravel API Resources (backend/app/Http/Resources).
 * Keep in sync with docs/api-conventions.md.
 */

export type UserRole = "admin";

export interface User {
  id: number;
  name: string;
  email: string;
  role: UserRole;
}

export const MATTER_STATUSES = ["open", "in_progress", "on_hold", "closed", "archived"] as const;
export type MatterStatus = (typeof MATTER_STATUSES)[number];

export const PRIORITIES = ["low", "medium", "high", "urgent"] as const;
export type Priority = (typeof PRIORITIES)[number];

export const CLIENT_TYPES = ["individual", "organization"] as const;
export type ClientType = (typeof CLIENT_TYPES)[number];

export const TASK_STATUSES = ["todo", "in_progress", "completed"] as const;
export type TaskStatus = (typeof TASK_STATUSES)[number];

export interface ClientSummary {
  id: number;
  name: string;
  client_type: ClientType;
  reference_number?: string | null;
}

export interface Client extends ClientSummary {
  email: string | null;
  phone: string | null;
  address: string | null;
  reference_number: string | null;
  matters_count?: number;
  active_matters_count?: number;
  closed_matters_count?: number;
  archived_matters_count?: number;
  created_at: string | null;
  updated_at?: string | null;
}

export interface Matter {
  id: number;
  matter_number: string;
  title: string;
  description: string | null;
  matter_type: string | null;
  status: MatterStatus;
  priority: Priority;
  is_active: boolean;
  is_overdue: boolean;
  opened_at: string | null;
  /** Calendar date (YYYY-MM-DD) in the firm's time zone — what forms edit and dates display. */
  opened_date: string | null;
  due_at: string | null;
  due_date: string | null;
  closed_at: string | null;
  archived_at: string | null;
  archived_from_status: MatterStatus | null;
  created_at: string | null;
  updated_at: string | null;
  lock_version: number;
  allowed_transitions: MatterStatus[];
  client?: ClientSummary;
  open_tasks_count?: number;
  overdue_tasks_count?: number;
  completed_tasks_count?: number;
  tasks_count?: number;
  notes_count?: number;
  documents_count?: number;
}

export interface Task {
  id: number;
  matter_id: number;
  title: string;
  description: string | null;
  status: TaskStatus;
  priority: Priority;
  is_open: boolean;
  is_overdue: boolean;
  due_at: string | null;
  due_date: string | null;
  completed_at: string | null;
  created_by?: { id: number; name: string } | null;
  created_at: string | null;
  updated_at: string | null;
  lock_version: number;
}

/** Confidential internal note. Rendered as plain text only. */
export interface MatterNote {
  id: number;
  matter_id: number;
  body: string;
  author?: { id: number; name: string } | null;
  is_edited: boolean;
  created_at: string | null;
  updated_at: string | null;
  lock_version: number;
}

export interface ActivityEvent {
  id: number;
  event_type: string;
  description: string;
  actor: { type: "admin" | "client" | "system"; label: string | null };
  subject_type: string | null;
  subject_id: number | null;
  metadata: Record<string, unknown>;
  occurred_at: string;
}

export const DOCUMENT_CATEGORIES = [
  "contracts",
  "agreements",
  "correspondence",
  "evidence",
  "court_documents",
  "identification",
  "other",
] as const;
export type DocumentCategory = (typeof DOCUMENT_CATEGORIES)[number];
export type DocumentStatus = "active" | "archived";

/** One immutable stored PDF. Storage paths are never exposed by the API. */
export interface DocumentVersion {
  id: number;
  document_id: number;
  version_number: number;
  label: string;
  kind: "upload" | "signed";
  origin: "admin" | "client";
  original_filename: string;
  mime_type: string;
  file_size: number;
  sha256_checksum: string;
  uploaded_by: { id: number | null; name: string } | null;
  is_current?: boolean;
  created_at: string | null;
}

export interface LegalDocument {
  id: number;
  matter_id: number;
  title: string;
  description: string | null;
  category: DocumentCategory;
  category_label: string;
  status: DocumentStatus;
  origin: "admin" | "client";
  versions_count: number;
  current_version?: DocumentVersion;
  uploaded_by?: { id: number; name: string } | null;
  matter?: { id: number; matter_number: string; title: string; status: MatterStatus; client: { id: number; name: string } | null };
  archived_at: string | null;
  created_at: string | null;
  updated_at: string | null;
  lock_version: number;
}

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
}

export interface Paginated<T> {
  data: T[];
  meta: PaginationMeta;
  links: { first: string | null; last: string | null; prev: string | null; next: string | null };
}

export interface Resource<T> {
  data: T;
}

export interface DashboardStats {
  total_clients: number;
  active_matters: number;
  total_matters: number;
  upcoming_deadlines: number;
  overdue_matters: number;
  open_tasks: number;
  overdue_tasks: number;
}

export interface DashboardSummary {
  stats: DashboardStats;
  status_breakdown: { status: MatterStatus; label: string; count: number }[];
  upcoming_window_days: number;
  recent_matters: Matter[];
  upcoming_deadlines: Matter[];
  generated_at: string;
}

/** Laravel error body: { message, errors? } */
export interface ApiErrorBody {
  message?: string;
  errors?: Record<string, string[]>;
}
