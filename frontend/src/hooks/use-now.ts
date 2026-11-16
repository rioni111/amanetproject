"use client";

import * as React from "react";

const MINUTE = 60_000;

function subscribe(onChange: () => void) {
  const id = window.setInterval(onChange, MINUTE);
  return () => window.clearInterval(id);
}

const getSnapshot = () => Math.floor(Date.now() / MINUTE);
const getServerSnapshot = () => null;

/**
 * The viewer's current time, refreshed every minute. Returns null during server
 * rendering/hydration so clock-dependent text never causes hydration mismatches.
 */
export function useNow(): Date | null {
  const minute = React.useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
  return React.useMemo(() => (minute === null ? null : new Date(minute * MINUTE)), [minute]);
}
