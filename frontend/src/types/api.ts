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

export interface ClientSummary {
  id: number;
  name: string;
  client_type: ClientType;
}

export interface Client extends ClientSummary {
  email: string;
  phone: string | null;
  address: string | null;
  reference_number: string | null;
  matters_count?: number;
  active_matters_count?: number;
  created_at: string | null;
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
  due_at: string | null;
  closed_at: string | null;
  archived_at: string | null;
  updated_at: string | null;
  client?: ClientSummary;
  open_tasks_count?: number;
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
