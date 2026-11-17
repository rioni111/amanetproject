import { Badge, type BadgeProps } from "@/components/ui/badge";
import { LINK_STATE_LABELS, REQUEST_STATUS_LABELS, SUBMISSION_REVIEW_LABELS } from "@/lib/utils/labels";
import type { DocumentRequestStatus, SecureLinkState, SubmissionReviewStatus } from "@/types/api";

const REQUEST_TONES: Record<DocumentRequestStatus, BadgeProps["tone"]> = {
  pending: "info",
  submitted: "warning",
  under_review: "primary",
  correction_requested: "warning",
  approved: "primary",
  cancelled: "outline",
};

const REQUEST_DOTS: Record<DocumentRequestStatus, string> = {
  pending: "bg-info",
  submitted: "bg-warning",
  under_review: "bg-primary",
  correction_requested: "bg-warning",
  approved: "bg-primary",
  cancelled: "bg-border-strong",
};

export function RequestStatusBadge({ status }: { status: DocumentRequestStatus }) {
  return (
    <Badge tone={REQUEST_TONES[status]} data-testid="request-status">
      <span aria-hidden className={`size-1.5 rounded-full ${REQUEST_DOTS[status]}`} />
      {REQUEST_STATUS_LABELS[status]}
    </Badge>
  );
}

const LINK_TONES: Record<SecureLinkState, BadgeProps["tone"]> = {
  active: "primary",
  expired: "warning",
  revoked: "outline",
  used: "neutral",
};

export function LinkStateBadge({ state }: { state: SecureLinkState }) {
  return (
    <Badge tone={LINK_TONES[state]} data-testid="link-state">
      {LINK_STATE_LABELS[state]}
    </Badge>
  );
}

const REVIEW_TONES: Record<SubmissionReviewStatus, BadgeProps["tone"]> = {
  pending: "warning",
  in_review: "info",
  approved: "primary",
  correction_requested: "danger",
  cancelled: "outline",
};

export function ReviewStatusBadge({ status }: { status: SubmissionReviewStatus }) {
  return <Badge tone={REVIEW_TONES[status]}>{SUBMISSION_REVIEW_LABELS[status]}</Badge>;
}
