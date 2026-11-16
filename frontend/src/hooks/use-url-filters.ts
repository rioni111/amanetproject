"use client";

import { usePathname, useRouter, useSearchParams } from "next/navigation";
import * as React from "react";

/**
 * Keeps list filters in the URL query string so views are shareable and survive reloads.
 * `parse` must validate every value — the URL is untrusted input.
 */
export function useUrlFilters<T extends { [K in keyof T]: string | number }>(parse: (params: URLSearchParams) => T, defaults: T) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  const filters = React.useMemo(() => parse(new URLSearchParams(searchParams.toString())), [parse, searchParams]);

  const setFilters = React.useCallback(
    (patch: Partial<T>, { resetPage = true }: { resetPage?: boolean } = {}) => {
      const next = { ...filters, ...patch, ...(resetPage && !("page" in patch) ? { page: 1 } : {}) } as T;
      const params = new URLSearchParams();
      for (const [key, value] of Object.entries(next)) {
        if (value !== "" && value !== defaults[key as keyof T]) params.set(key, String(value));
      }
      const query = params.toString();
      router.replace(query ? `${pathname}?${query}` : pathname, { scroll: false });
    },
    [filters, defaults, pathname, router],
  );

  return [filters, setFilters] as const;
}
