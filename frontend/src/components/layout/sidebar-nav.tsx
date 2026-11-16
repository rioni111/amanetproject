"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

import { cn } from "@/lib/utils/cn";

import { PRIMARY_NAV, UPCOMING_NAV } from "./nav-config";

export function SidebarNav({ onNavigate }: { onNavigate?: () => void }) {
  const pathname = usePathname();

  return (
    <nav aria-label="Main" className="flex flex-col gap-7">
      <div>
        <p className="mb-2 px-3 text-[11px] font-semibold tracking-[0.08em] text-sidebar-muted uppercase">Workspace</p>
        <ul className="flex flex-col gap-0.5">
          {PRIMARY_NAV.map(({ title, href, icon: Icon }) => {
            const active = pathname === href || pathname.startsWith(`${href}/`);
            return (
              <li key={href}>
                <Link
                  href={href}
                  onClick={onNavigate}
                  aria-current={active ? "page" : undefined}
                  className={cn(
                    "group relative flex h-9 items-center gap-3 rounded-md px-3 text-sm font-medium transition-colors",
                    "focus-visible:outline-sidebar-accent",
                    active ? "bg-sidebar-active text-white" : "text-sidebar-foreground/80 hover:bg-sidebar-hover hover:text-white",
                  )}
                >
                  {active ? <span aria-hidden className="absolute top-2 bottom-2 left-0 w-[3px] rounded-r bg-sidebar-accent" /> : null}
                  <Icon
                    aria-hidden
                    className={cn("size-4", active ? "text-sidebar-accent" : "text-sidebar-muted group-hover:text-sidebar-foreground")}
                  />
                  {title}
                </Link>
              </li>
            );
          })}
        </ul>
      </div>

      <div>
        <p className="mb-2 px-3 text-[11px] font-semibold tracking-[0.08em] text-sidebar-muted uppercase">Coming soon</p>
        <ul className="flex flex-col gap-0.5">
          {UPCOMING_NAV.map(({ title, icon: Icon, phase }) => (
            <li key={title}>
              <div
                aria-disabled="true"
                className="flex h-9 cursor-default items-center gap-3 rounded-md px-3 text-sm text-sidebar-muted/80"
              >
                <Icon aria-hidden className="size-4 opacity-70" />
                <span className="flex-1">{title}</span>
                <span className="rounded border border-sidebar-border px-1.5 py-px text-[10px] font-medium tracking-wide text-sidebar-muted">
                  Soon<span className="sr-only"> — planned for phase {phase}, not yet available</span>
                </span>
              </div>
            </li>
          ))}
        </ul>
      </div>
    </nav>
  );
}
