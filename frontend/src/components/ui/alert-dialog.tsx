"use client";

import * as AlertDialogPrimitive from "@radix-ui/react-alert-dialog";
import * as React from "react";

import { cn } from "@/lib/utils/cn";

import { useReturnFocus } from "./use-return-focus";

const AlertDialog = AlertDialogPrimitive.Root;
const AlertDialogTrigger = AlertDialogPrimitive.Trigger;
const AlertDialogAction = AlertDialogPrimitive.Action;
const AlertDialogCancel = AlertDialogPrimitive.Cancel;

function AlertDialogContent({ className, onOpenAutoFocus, onCloseAutoFocus, ...props }: React.ComponentProps<typeof AlertDialogPrimitive.Content>) {
  const focusHandlers = useReturnFocus(onOpenAutoFocus, onCloseAutoFocus);
  return (
    <AlertDialogPrimitive.Portal>
      <AlertDialogPrimitive.Overlay className="fixed inset-0 z-50 bg-[#111827]/45 backdrop-blur-[2px]" />
      <AlertDialogPrimitive.Content
        className={cn(
          "fixed top-1/2 left-1/2 z-50 w-[calc(100%-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 rounded-xl border border-border bg-surface p-6 shadow-[var(--shadow-raised)]",
          className,
        )}
        {...props}
        {...focusHandlers}
      />
    </AlertDialogPrimitive.Portal>
  );
}

function AlertDialogTitle({ className, ...props }: React.ComponentProps<typeof AlertDialogPrimitive.Title>) {
  return <AlertDialogPrimitive.Title className={cn("text-base font-semibold text-foreground", className)} {...props} />;
}

function AlertDialogDescription({ className, ...props }: React.ComponentProps<typeof AlertDialogPrimitive.Description>) {
  return <AlertDialogPrimitive.Description className={cn("mt-2 text-sm text-muted-foreground", className)} {...props} />;
}

export {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogTitle,
  AlertDialogTrigger,
};
