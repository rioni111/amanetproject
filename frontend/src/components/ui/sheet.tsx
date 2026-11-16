"use client";

import * as DialogPrimitive from "@radix-ui/react-dialog";
import { X } from "lucide-react";
import * as React from "react";

import { cn } from "@/lib/utils/cn";

const Sheet = DialogPrimitive.Root;
const SheetTrigger = DialogPrimitive.Trigger;
const SheetClose = DialogPrimitive.Close;
const SheetTitle = DialogPrimitive.Title;
const SheetDescription = DialogPrimitive.Description;

/** Accessible slide-over panel (focus trap, Escape to close, aria-modal) built on Radix Dialog. */
function SheetContent({
  className,
  children,
  side = "left",
  closeLabel = "Close navigation",
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Content> & { side?: "left" | "right"; closeLabel?: string }) {
  return (
    <DialogPrimitive.Portal>
      <DialogPrimitive.Overlay className="fixed inset-0 z-40 bg-[#111827]/50 backdrop-blur-[2px]" />
      <DialogPrimitive.Content
        className={cn(
          "fixed inset-y-0 z-50 flex w-[18rem] max-w-[85vw] flex-col shadow-[var(--shadow-raised)] outline-none",
          side === "left" ? "left-0" : "right-0",
          className,
        )}
        {...props}
      >
        {children}
        <DialogPrimitive.Close
          className="absolute top-4 right-3 inline-flex size-8 cursor-pointer items-center justify-center rounded-md text-sidebar-muted transition-colors hover:bg-sidebar-hover hover:text-white"
          aria-label={closeLabel}
        >
          <X className="size-4" aria-hidden />
        </DialogPrimitive.Close>
      </DialogPrimitive.Content>
    </DialogPrimitive.Portal>
  );
}

export { Sheet, SheetClose, SheetContent, SheetDescription, SheetTitle, SheetTrigger };
