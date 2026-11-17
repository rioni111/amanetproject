"use client";

import * as React from "react";

/**
 * Phase 7 (issue P7-009, WCAG 2.4.3 Focus Order): returns keyboard focus to the element that opened a
 * dialog. Radix does this only for its own <Trigger>; most dialogs here are opened from state (a button
 * that calls setOpen), and focus then fell back to <body> on close — keyboard users lost their place.
 *
 * Radix fires onOpenAutoFocus just BEFORE it moves focus into the dialog, so the element focused at that
 * moment is the opener; onCloseAutoFocus focuses it again if it is still on the page.
 */
export function useReturnFocus(onOpenAutoFocus?: (event: Event) => void, onCloseAutoFocus?: (event: Event) => void) {
  const opener = React.useRef<HTMLElement | null>(null);

  const handleOpen = React.useCallback(
    (event: Event) => {
      const active = document.activeElement;
      opener.current = active instanceof HTMLElement && active !== document.body ? active : null;
      onOpenAutoFocus?.(event);
    },
    [onOpenAutoFocus],
  );

  const handleClose = React.useCallback(
    (event: Event) => {
      onCloseAutoFocus?.(event);
      if (event.defaultPrevented) return;
      const element = opener.current;
      opener.current = null;
      if (element && element.isConnected) {
        event.preventDefault();
        element.focus();
      }
    },
    [onCloseAutoFocus],
  );

  return { onOpenAutoFocus: handleOpen, onCloseAutoFocus: handleClose };
}
