import { ChevronLeft, ChevronRight } from "lucide-react";

import { Button } from "@/components/ui/button";
import type { PaginationMeta } from "@/types/api";

interface PaginationProps {
  meta: PaginationMeta;
  onPageChange: (page: number) => void;
  noun?: string;
  disabled?: boolean;
}

export function Pagination({ meta, onPageChange, noun = "results", disabled }: PaginationProps) {
  const { current_page: page, last_page: last, from, to, total } = meta;

  return (
    <nav
      aria-label="Pagination"
      className="flex flex-col items-center justify-between gap-3 border-t border-border px-5 py-3.5 sm:flex-row"
    >
      <p className="text-[13px] text-muted-foreground" aria-live="polite">
        {total === 0 ? (
          `No ${noun}`
        ) : (
          <>
            Showing <span className="font-medium text-foreground">{from}</span>–<span className="font-medium text-foreground">{to}</span> of{" "}
            <span className="font-medium text-foreground">{total}</span> {noun}
          </>
        )}
      </p>
      <div className="flex items-center gap-2">
        <span className="mr-1 text-[13px] text-subtle-foreground">
          Page {page} of {Math.max(last, 1)}
        </span>
        <Button
          variant="secondary"
          size="sm"
          onClick={() => onPageChange(page - 1)}
          disabled={disabled || page <= 1}
          aria-label="Previous page"
        >
          <ChevronLeft aria-hidden />
          <span className="hidden sm:inline">Previous</span>
        </Button>
        <Button
          variant="secondary"
          size="sm"
          onClick={() => onPageChange(page + 1)}
          disabled={disabled || page >= last}
          aria-label="Next page"
        >
          <span className="hidden sm:inline">Next</span>
          <ChevronRight aria-hidden />
        </Button>
      </div>
    </nav>
  );
}
