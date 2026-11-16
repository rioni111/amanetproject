"use client";

import * as React from "react";

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { Button } from "@/components/ui/button";

interface ConfirmDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description: React.ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  destructive?: boolean;
  pending?: boolean;
  onConfirm: () => void;
}

/**
 * Accessible confirmation dialog (Radix AlertDialog: focus trap, Escape, labelled).
 * Foundation for destructive actions such as archiving or link revocation in later phases.
 */
export function ConfirmDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel = "Confirm",
  cancelLabel = "Cancel",
  destructive = false,
  pending = false,
  onConfirm,
}: ConfirmDialogProps) {
  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <AlertDialogContent>
        <AlertDialogTitle>{title}</AlertDialogTitle>
        <AlertDialogDescription asChild>
          <div>{description}</div>
        </AlertDialogDescription>
        <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <AlertDialogCancel asChild>
            <Button variant="secondary" disabled={pending}>
              {cancelLabel}
            </Button>
          </AlertDialogCancel>
          <AlertDialogAction asChild>
            <Button
              variant={destructive ? "danger" : "primary"}
              disabled={pending}
              onClick={(event) => {
                event.preventDefault();
                onConfirm();
              }}
            >
              {pending ? "Working…" : confirmLabel}
            </Button>
          </AlertDialogAction>
        </div>
      </AlertDialogContent>
    </AlertDialog>
  );
}
