import * as React from "react";

import { cn } from "@/lib/utils/cn";

function Table({ className, ...props }: React.TableHTMLAttributes<HTMLTableElement>) {
  return (
    <div className="relative w-full overflow-x-auto">
      <table className={cn("w-full caption-bottom border-collapse text-sm", className)} {...props} />
    </div>
  );
}

function TableHeader({ className, ...props }: React.HTMLAttributes<HTMLTableSectionElement>) {
  return <thead className={cn("bg-surface-muted/70", className)} {...props} />;
}

function TableBody({ className, ...props }: React.HTMLAttributes<HTMLTableSectionElement>) {
  return <tbody className={cn("[&_tr:last-child]:border-0", className)} {...props} />;
}

function TableRow({ className, ...props }: React.HTMLAttributes<HTMLTableRowElement>) {
  return <tr className={cn("border-b border-border transition-colors hover:bg-surface-muted/50", className)} {...props} />;
}

function TableHead({ className, ...props }: React.ThHTMLAttributes<HTMLTableCellElement>) {
  return (
    <th
      scope="col"
      className={cn(
        "h-10 px-4 text-left align-middle text-[11px] font-semibold tracking-[0.06em] whitespace-nowrap text-subtle-foreground uppercase first:pl-5 last:pr-5",
        className,
      )}
      {...props}
    />
  );
}

function TableCell({ className, ...props }: React.TdHTMLAttributes<HTMLTableCellElement>) {
  return <td className={cn("px-4 py-3.5 align-middle first:pl-5 last:pr-5", className)} {...props} />;
}

function TableCaption({ className, ...props }: React.HTMLAttributes<HTMLTableCaptionElement>) {
  return <caption className={cn("sr-only", className)} {...props} />;
}

export { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow };
