/**
 * Date and text formatting. API timestamps are ISO-8601 in UTC; they are rendered
 * in the viewer's local time zone.
 */

const DAY_MS = 86_400_000;

const dateFormatter = new Intl.DateTimeFormat("en-GB", { day: "numeric", month: "short", year: "numeric" });
const shortDateFormatter = new Intl.DateTimeFormat("en-GB", { day: "numeric", month: "short" });
const longDateFormatter = new Intl.DateTimeFormat("en-GB", { weekday: "long", day: "numeric", month: "long", year: "numeric" });
const monthFormatter = new Intl.DateTimeFormat("en-GB", { month: "short" });

const DATE_ONLY = /^(\d{4})-(\d{2})-(\d{2})$/;

/**
 * Parses an API value. Calendar dates ("2026-11-30", e.g. deadlines) become local midnight of that
 * day — never shifted by time zones; ISO timestamps are parsed as instants.
 */
export function toDate(value: string): Date {
  const match = DATE_ONLY.exec(value);
  return match ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : new Date(value);
}

export function isDateOnly(value: string): boolean {
  return DATE_ONLY.test(value);
}

/** Today's calendar date in the viewer's time zone, as YYYY-MM-DD (for date inputs). */
export function todayIsoDate(now: Date = new Date()): string {
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

export function formatDate(iso: string | null | undefined): string {
  return iso ? dateFormatter.format(toDate(iso)) : "—";
}

export function formatShortDate(iso: string): string {
  return shortDateFormatter.format(toDate(iso));
}

const dateTimeFormatter = new Intl.DateTimeFormat("en-GB", {
  day: "numeric",
  month: "short",
  year: "numeric",
  hour: "2-digit",
  minute: "2-digit",
});
const timeFormatter = new Intl.DateTimeFormat("en-GB", { hour: "2-digit", minute: "2-digit" });

export function formatDateTime(iso: string | null | undefined): string {
  return iso ? dateTimeFormatter.format(new Date(iso)) : "—";
}

export function formatTime(iso: string): string {
  return timeFormatter.format(new Date(iso));
}

export function formatLongDate(date: Date): string {
  return longDateFormatter.format(date);
}

export function formatMonth(iso: string): string {
  return monthFormatter.format(toDate(iso));
}

export function formatDay(iso: string): string {
  return String(toDate(iso).getDate());
}

function startOfDay(date: Date): number {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
}

/** Whole calendar days from today to the given date (negative = past). */
export function daysUntil(iso: string, now: Date = new Date()): number {
  return Math.round((startOfDay(toDate(iso)) - startOfDay(now)) / DAY_MS);
}

export function relativeDays(iso: string, now: Date = new Date()): string {
  const days = daysUntil(iso, now);
  if (days === 0) return "Today";
  if (days === 1) return "Tomorrow";
  if (days === -1) return "Yesterday";
  return days > 0 ? `In ${days} days` : `${Math.abs(days)} days ago`;
}

export type DeadlineUrgency = "overdue" | "imminent" | "soon" | "later" | "none";

export function deadlineUrgency(iso: string | null, isActive = true, now: Date = new Date()): DeadlineUrgency {
  if (!iso || !isActive) return "none";
  const days = daysUntil(iso, now);
  // A calendar-date deadline is overdue once the whole day has passed.
  if (isDateOnly(iso) ? days < 0 : new Date(iso).getTime() < now.getTime()) return "overdue";
  if (days <= 3) return "imminent";
  if (days <= 7) return "soon";
  return "later";
}

export function greeting(now: Date = new Date()): string {
  const hour = now.getHours();
  if (hour < 12) return "Good morning";
  if (hour < 18) return "Good afternoon";
  return "Good evening";
}

export function firstName(name: string): string {
  return name.trim().split(/\s+/)[0] ?? name;
}

export function initials(name: string): string {
  const parts = name
    .replace(/[^\p{L}\s]/gu, " ")
    .trim()
    .split(/\s+/)
    .filter(Boolean);
  const letters = parts.length > 1 ? parts[0][0] + parts[parts.length - 1][0] : (parts[0] ?? "?").slice(0, 2);
  return letters.toUpperCase();
}

export function pluralize(count: number, singular: string, plural = `${singular}s`): string {
  return `${count} ${count === 1 ? singular : plural}`;
}
