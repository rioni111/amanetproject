import * as React from "react";

import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { cn } from "@/lib/utils/cn";

export interface Column<T> {
  key: string;
  header: React.ReactNode;
  cell: (row: T) => React.ReactNode;
  className?: string;
  headerClassName?: string;
}

interface DataTableProps<T> {
  caption: string;
  columns: Column<T>[];
  rows: T[];
  getRowKey: (row: T) => React.Key;
  /** Card renderer for small screens. When provided the table is shown from `md` up. */
  renderMobileItem?: (row: T) => React.ReactNode;
  className?: string;
}

/**
 * Presentational table with an optional stacked-card layout on mobile,
 * so data never forces horizontal page scrolling on phones.
 */
export function DataTable<T>({ caption, columns, rows, getRowKey, renderMobileItem, className }: DataTableProps<T>) {
  return (
    <div className={className}>
      <div className={cn(renderMobileItem && "hidden md:block")}>
        <Table>
          <TableCaption>{caption}</TableCaption>
          <TableHeader>
            <TableRow className="hover:bg-transparent">
              {columns.map((column) => (
                <TableHead key={column.key} className={column.headerClassName}>
                  {column.header}
                </TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((row) => (
              <TableRow key={getRowKey(row)}>
                {columns.map((column) => (
                  <TableCell key={column.key} className={column.className}>
                    {column.cell(row)}
                  </TableCell>
                ))}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
      {renderMobileItem ? (
        <ul className="divide-y divide-border md:hidden" aria-label={caption}>
          {rows.map((row) => (
            <li key={getRowKey(row)} className="px-4 py-4">
              {renderMobileItem(row)}
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}
