"use client";

import { Search, X } from "lucide-react";
import * as React from "react";

import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils/cn";

interface SearchInputProps {
  value: string;
  onChange: (value: string) => void;
  label: string;
  placeholder?: string;
  className?: string;
  /** Debounce in ms before onChange fires (local input stays instant). */
  delay?: number;
}

export function SearchInput({ value, onChange, label, placeholder, className, delay = 300 }: SearchInputProps) {
  const [draft, setDraft] = React.useState(value);
  const [synced, setSynced] = React.useState(value);

  // Keep the field in sync when the value changes externally (e.g. "Clear filters").
  if (value !== synced) {
    setSynced(value);
    setDraft(value);
  }

  React.useEffect(() => {
    if (draft.trim() === value) return;
    const timer = window.setTimeout(() => onChange(draft.trim()), delay);
    return () => window.clearTimeout(timer);
  }, [draft, value, delay, onChange]);

  return (
    <div className={cn("relative", className)}>
      <label className="sr-only" htmlFor="list-search">
        {label}
      </label>
      <Search aria-hidden className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-subtle-foreground" />
      <Input
        id="list-search"
        type="search"
        value={draft}
        onChange={(event) => setDraft(event.target.value)}
        placeholder={placeholder}
        maxLength={100}
        autoComplete="off"
        className="h-9 pr-9 pl-9 [&::-webkit-search-cancel-button]:hidden"
      />
      {draft ? (
        <button
          type="button"
          onClick={() => {
            setDraft("");
            onChange("");
          }}
          className="absolute top-1/2 right-2 inline-flex size-6 -translate-y-1/2 cursor-pointer items-center justify-center rounded text-subtle-foreground hover:bg-surface-muted hover:text-foreground"
          aria-label="Clear search"
        >
          <X aria-hidden className="size-3.5" />
        </button>
      ) : null}
    </div>
  );
}
