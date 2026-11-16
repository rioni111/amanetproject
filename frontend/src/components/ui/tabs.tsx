"use client";

import * as React from "react";

import { cn } from "@/lib/utils/cn";

export interface TabItem {
  id: string;
  label: React.ReactNode;
  count?: number;
}

/**
 * WAI-ARIA tabs (no extra dependency): roving tabindex, ←/→/Home/End move between tabs,
 * each tab controls a labelled tabpanel rendered by the caller with <TabPanel>.
 */
export function Tabs({
  label,
  items,
  value,
  onValueChange,
  idPrefix,
  className,
}: {
  label: string;
  items: TabItem[];
  value: string;
  onValueChange: (id: string) => void;
  idPrefix: string;
  className?: string;
}) {
  const refs = React.useRef<(HTMLButtonElement | null)[]>([]);

  function onKeyDown(event: React.KeyboardEvent, index: number) {
    const last = items.length - 1;
    const next =
      event.key === "ArrowRight"
        ? index === last
          ? 0
          : index + 1
        : event.key === "ArrowLeft"
          ? index === 0
            ? last
            : index - 1
          : event.key === "Home"
            ? 0
            : event.key === "End"
              ? last
              : null;
    if (next === null) return;
    event.preventDefault();
    onValueChange(items[next].id);
    refs.current[next]?.focus();
  }

  return (
    <div role="tablist" aria-label={label} className={cn("flex gap-1 overflow-x-auto border-b border-border", className)}>
      {items.map((item, index) => {
        const selected = item.id === value;
        return (
          <button
            key={item.id}
            ref={(el) => {
              refs.current[index] = el;
            }}
            type="button"
            role="tab"
            id={`${idPrefix}-tab-${item.id}`}
            aria-selected={selected}
            aria-controls={`${idPrefix}-panel-${item.id}`}
            tabIndex={selected ? 0 : -1}
            onClick={() => onValueChange(item.id)}
            onKeyDown={(event) => onKeyDown(event, index)}
            className={cn(
              "relative -mb-px inline-flex shrink-0 cursor-pointer items-center gap-2 border-b-2 px-3 pt-1 pb-3 text-sm font-medium whitespace-nowrap transition-colors",
              selected ? "border-primary text-foreground" : "border-transparent text-muted-foreground hover:text-foreground",
            )}
          >
            {item.label}
            {item.count !== undefined ? (
              <span
                className={cn(
                  "rounded-full px-1.5 text-[11px] leading-5 font-semibold tabular-nums",
                  selected ? "bg-primary-soft text-primary-soft-foreground" : "bg-surface-muted text-subtle-foreground",
                )}
              >
                {item.count}
              </span>
            ) : null}
          </button>
        );
      })}
    </div>
  );
}

export function TabPanel({
  idPrefix,
  id,
  children,
  className,
}: {
  idPrefix: string;
  id: string;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <div
      role="tabpanel"
      id={`${idPrefix}-panel-${id}`}
      aria-labelledby={`${idPrefix}-tab-${id}`}
      tabIndex={0}
      className={cn("outline-none", className)}
    >
      {children}
    </div>
  );
}
