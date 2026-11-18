"use client";

import * as DialogPrimitive from "@radix-ui/react-dialog";
import { X } from "lucide-react";
import * as React from "react";

import { cn } from "@/lib/utils/cn";

import { useReturnFocus } from "./use-return-focus";

const Dialog = DialogPrimitive.Root;
const DialogTrigger = DialogPrimitive.Trigger;
const DialogClose = DialogPrimitive.Close;

/**
 * Accessible modal dialog (Radix: focus trap, Escape, labelled, focus returned on close).
 * Full-height sheet on phones, centred card from `sm` up; content scrolls inside the viewport.
 */
function DialogContent({
  className,
  children,
  title,
  description,
  onOpenAutoFocus,
  onCloseAutoFocus,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Content> & { title: React.ReactNode; description?: React.ReactNode }) {
  const focusHandlers = useReturnFocus(onOpenAutoFocus, onCloseAutoFocus);
  return (
    <DialogPrimitive.Portal>
      <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-[#111827]/45 backdrop-blur-[2px]" />
      <DialogPrimitive.Content
        className={cn(
          "fixed inset-x-0 bottom-0 z-50 flex max-h-[92dvh] w-full flex-col rounded-t-2xl border border-border bg-surface shadow-[var(--shadow-raised)] outline-none",
          "sm:inset-x-auto sm:top-1/2 sm:bottom-auto sm:left-1/2 sm:max-h-[88dvh] sm:w-[calc(100%-2rem)] sm:max-w-xl sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-xl",
          className,
        )}
        {...(description ? {} : { "aria-describedby": undefined })}
        {...props}
        {...focusHandlers}
      >
        <div className="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
          <div className="min-w-0">
            <DialogPrimitive.Title className="text-base font-semibold text-foreground">{title}</DialogPrimitive.Title>
            {description ? (
              <DialogPrimitive.Description className="mt-1 text-[13px] text-muted-foreground">{description}</DialogPrimitive.Description>
            ) : null}
          </div>
          <DialogPrimitive.Close
            className="-mr-1.5 inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-md text-subtle-foreground transition-colors hover:bg-surface-muted hover:text-foreground"
            aria-label="Close"
          >
            <X aria-hidden className="size-4" />
          </DialogPrimitive.Close>
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto">{children}</div>
      </DialogPrimitive.Content>
    </DialogPrimitive.Portal>
  );
}

/** Sticky action row at the bottom of a dialog form. */
function DialogFooter({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn(
        "sticky bottom-0 flex flex-col-reverse gap-2 border-t border-border bg-surface px-5 py-4 sm:flex-row sm:justify-end",
        className,
      )}
      {...props}
    />
  );
}

export { Dialog, DialogClose, DialogContent, DialogFooter, DialogTrigger };
