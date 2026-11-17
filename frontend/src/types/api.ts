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
  requests?: MatterRequestCounts;
  signatures?: MatterSignatureCounts;
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
  active_signing_request?: { id: number; status: SigningStatus; signer_type: SignerType; signer_name: string } | null;
  archived_at: string | null;
  created_at: string | null;
  updated_at: string | null;
  lock_version: number;
}

export const DOCUMENT_REQUEST_STATUSES = ["pending", "submitted", "under_review", "correction_requested", "approved", "cancelled"] as const;
export type DocumentRequestStatus = (typeof DOCUMENT_REQUEST_STATUSES)[number];
export type DocumentRequestStatusGroup = "open" | "awaiting_client" | "awaiting_review";
export type SubmissionReviewStatus = "pending" | "in_review" | "approved" | "correction_requested" | "cancelled";
export type SecureLinkState = "active" | "expired" | "revoked" | "used";

/** Link status for administrators. Tokens and hashes are never part of any API response. */
export interface SecureLink {
  id: number;
  purpose: "document_upload" | "document_signing";
  state: SecureLinkState;
  expires_at: string;
  revoked_at: string | null;
  revocation_reason: string | null;
  revocation_label: string | null;
  consumed_at: string | null;
  first_opened_at: string | null;
  created_by?: { id: number; name: string } | null;
  created_at: string | null;
}

/** Returned exactly once, by the action that created the link. Never stored or cached by the app. */
export interface IssuedLink {
  id: number;
  url: string;
  expires_at: string;
  is_local_url: boolean;
  shown_once: true;
}

export interface DocumentRequestSubmission {
  id: number;
  document_request_id: number;
  submission_number: number;
  document_id: number;
  version?: DocumentVersion;
  client_message: string | null;
  submitted_by: string;
  submitted_at: string;
  review_status: SubmissionReviewStatus;
  review_status_label: string;
  review_started_at: string | null;
  reviewed_at: string | null;
  reviewed_by: { id: number | null; name: string } | null;
  correction_reason: string | null;
}

export interface DocumentRequest {
  id: number;
  matter_id: number;
  title: string;
  instructions: string;
  requested_category: DocumentCategory;
  requested_category_label: string;
  status: DocumentRequestStatus;
  status_label: string;
  is_open: boolean;
  is_overdue: boolean;
  due_at: string | null;
  due_date: string | null;
  document_id: number | null;
  submissions_count: number;
  last_submitted_at: string | null;
  review_started_at: string | null;
  approved_at: string | null;
  approved_version?: { id: number; version_number: number; label: string } | null;
  reviewed_by?: { id: number; name: string } | null;
  cancelled_at: string | null;
  client?: { id: number; name: string; email?: string };
  matter?: { id: number; matter_number: string; title: string; status: MatterStatus };
  document?: { id: number; title: string; status: DocumentStatus; versions_count: number } | null;
  latest_submission?: DocumentRequestSubmission | null;
  active_link?: SecureLink | null;
  created_by?: { id: number; name: string } | null;
  created_at: string | null;
  updated_at: string | null;
  lock_version: number;
  allowed_actions: {
    edit: boolean;
    issue_link: boolean;
    rotate_link: boolean;
    revoke_link: boolean;
    start_review: boolean;
    approve: boolean;
    request_correction: boolean;
    cancel: boolean;
    send_email: boolean;
  };
}

export interface MatterRequestCounts {
  open: number;
  pending: number;
  awaiting_review: number;
  correction_requested: number;
  approved: number;
  overdue: number;
}

/** What the public upload page receives for a valid link — nothing else about the firm's records. */
export interface PublicUploadRequest {
  firm_name: string;
  title: string;
  instructions: string;
  category: DocumentCategory;
  category_label: string;
  due_date: string | null;
  is_overdue: boolean;
  expires_at: string;
  max_file_mb: number;
  is_correction: boolean;
  correction: { reason: string; requested_at: string | null } | null;
}

export interface PublicSubmissionResult {
  submission_number: number;
  submitted_at: string;
  original_filename: string;
  file_size: number;
  is_correction: boolean;
}

export const SIGNING_STATUSES = ["ready", "sent", "viewed", "started", "signed", "cancelled"] as const;
export type SigningStatus = (typeof SIGNING_STATUSES)[number];
export type SignerType = "admin" | "client";

export interface SigningRequest {
  id: number;
  matter_id: number;
  document_id: number;
  signer_type: SignerType;
  signer_type_label: string;
  signer_name: string;
  signer_email: string | null;
  message: string | null;
  status: SigningStatus;
  status_label: string;
  is_active: boolean;
  delivery_channel: "manual" | "email" | null;
  delivery_status: "not_shared" | "shared_manually" | "email_accepted";
  delivery_status_label: string;
  sent_at: string | null;
  viewed_at: string | null;
  started_at: string | null;
  signed_at: string | null;
  cancelled_at: string | null;
  cancellation_reason: string | null;
  cancellation_label: string | null;
  matter?: { id: number; matter_number: string; title: string; status: MatterStatus };
  client?: { id: number; name: string } | null;
  document?: { id: number; title: string; status: DocumentStatus; current_version_id: number | null };
  source_version?: DocumentVersion;
  signed_version?: DocumentVersion | null;
  active_link?: SecureLink | null;
  created_by?: { id: number; name: string } | null;
  created_at: string | null;
  updated_at: string | null;
  lock_version: number;
  allowed_actions: {
    issue_link: boolean;
    rotate_link: boolean;
    revoke_link: boolean;
    mark_shared: boolean;
    cancel: boolean;
    admin_sign: boolean;
    send_email: boolean;
  };
}

/** Evidence of a completed signature (administrators only). */
export interface SignatureEvidence {
  id: number;
  signing_request_id: number;
  signer_type: SignerType;
  signer_name: string;
  signer_email: string | null;
  confirmed_name: string;
  identity_basis: string;
  signature_method: "drawn" | "typed";
  signature_method_label: string;
  page_number: number;
  placement: { page: number; x: number; y: number; width: number; page_width_pt: number; page_height_pt: number };
  source_version_id: number;
  source_sha256: string;
  signed_version_id: number;
  signed_sha256: string;
  consent_version: string;
  consent_text: string;
  consent_accepted_at: string;
  signed_at: string;
  ip_address: string | null;
  user_agent: string | null;
}

export interface MatterSignatureCounts {
  total: number;
  active: number;
  sent: number;
  viewed: number;
  started: number;
  signed: number;
}

/** What the public signing page receives — nothing else about the firm's records. */
export interface PublicSigningInfo {
  firm_name: string;
  document_title: string;
  version_label: string;
  file_name: string;
  signer_name: string;
  signer_email: string | null;
  message: string | null;
  status: SigningStatus;
  viewed: boolean;
  started: boolean;
  expires_at: string;
  consent: { version: string; text: string };
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
  requests_outstanding: number;
  requests_pending: number;
  requests_awaiting_review: number;
  requests_correction: number;
  requests_overdue: number;
  requests_approved: number;
  signatures_pending: number;
  signatures_signed: number;
  emails_sending: number;
  emails_accepted_30d: number;
  emails_failed_30d: number;
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
  /** Machine-readable reason, e.g. "email_failed" (502) or "email_unavailable" (503). */
  code?: string;
  /** Payload some error responses carry (the failed e-mail delivery record). */
  data?: unknown;
}

// ---------------------------------------------------------------- Phase 6: e-mail and notifications

export type EmailCommunicationType =
  | "document_request"
  | "document_correction"
  | "document_request_reminder"
  | "signing_invitation"
  | "signing_reminder"
  | "test";

/** "accepted" = accepted by the configured SMTP server — not proof of arrival in the recipient's inbox. */
export type EmailDeliveryStatus = "pending" | "sending" | "accepted" | "failed" | "cancelled" | "skipped";

export interface EmailDelivery {
  id: number;
  matter_id: number | null;
  document_request_id: number | null;
  signing_request_id: number | null;
  communication_type: EmailCommunicationType;
  communication_label: string;
  initiated_by: "manual" | "automatic";
  reminder_slot: string | null;
  recipient_email: string;
  recipient_name: string;
  subject: string;
  status: EmailDeliveryStatus;
  status_label: string;
  attempt_count: number;
  last_attempt_at: string | null;
  accepted_at: string | null;
  failed_at: string | null;
  failure_category: string | null;
  failure_message: string | null;
  provider_message_id: string | null;
  link?: { id: number; state: SecureLinkState; expires_at: string } | null;
  created_by?: { id: number; name: string } | null;
  created_at: string | null;
}

export interface EmailSendResult {
  data: EmailDelivery;
  message: string;
  replayed?: boolean;
}

/** E-mail transport readiness — never credentials. */
export interface CommunicationStatus {
  available: boolean;
  reason: string | null;
  mailer: string;
  transport: string;
  host: string | null;
  port: number | null;
  encryption: string | null;
  authenticated: boolean;
  from_address: string;
  from_name: string;
  reply_to: string | null;
  public_app_url: string;
  public_url_https: boolean;
  public_url_local: boolean;
  mail_ui_url: string | null;
}

export type NotificationKind =
  | "document_submitted"
  | "correction_submitted"
  | "document_signed"
  | "request_overdue"
  | "signing_link_expired"
  | "email_failed";

export interface WorkspaceNotification {
  id: string;
  kind: NotificationKind;
  title: string;
  body: string;
  href: string;
  matter: { id: number; matter_number: string | null; title: string | null } | null;
  read_at: string | null;
  created_at: string | null;
}

export interface NotificationPage {
  data: WorkspaceNotification[];
  meta: PaginationMeta & { unread_count: number };
}
