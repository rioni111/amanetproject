/**
 * Date and text formatting. API timestamps are ISO-8601 in UTC; they are rendered
 * in the viewer's local time zone.
 */

const DAY_MS = 86_400_000;

const dateFormatter = new Intl.DateTimeFormat("en-GB", { day: "numeric", month: "short", year: "numeric" });
const shortDateFormatter = new Intl.DateTimeFormat("en-GB", { day: "numeric", month: "short" });
const longDateFormatter = new Intl.DateTimeFormat("en-GB", { weekday: "long", day: "numeric", month: "long", year: "numeric" });
const monthFormatter = new Intl.DateTimeFormat("en-GB", { month: "short" });

export function formatDate(iso: string | null | undefined): string {
  return iso ? dateFormatter.format(new Date(iso)) : "—";
}

export function formatShortDate(iso: string): string {
  return shortDateFormatter.format(new Date(iso));
}

export function formatLongDate(date: Date): string {
  return longDateFormatter.format(date);
}

export function formatMonth(iso: string): string {
  return monthFormatter.format(new Date(iso));
}

export function formatDay(iso: string): string {
  return String(new Date(iso).getDate());
}

function startOfDay(date: Date): number {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
}

/** Whole calendar days from today to the given date (negative = past). */
export function daysUntil(iso: string, now: Date = new Date()): number {
  return Math.round((startOfDay(new Date(iso)) - startOfDay(now)) / DAY_MS);
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
  if (new Date(iso).getTime() < now.getTime()) return "overdue";
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
