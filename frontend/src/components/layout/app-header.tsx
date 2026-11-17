"use client";

import { ChevronRight, Menu } from "lucide-react";
import { usePathname } from "next/navigation";
import * as React from "react";

import { BrandMark } from "@/components/shared/brand-mark";
import { Sheet, SheetContent, SheetDescription, SheetTitle, SheetTrigger } from "@/components/ui/sheet";
import { useAuthenticatedUser } from "@/features/auth/auth-guard";
import { NotificationBell } from "@/features/notifications/notification-bell";
import { initials } from "@/lib/utils/format";

import { SidebarContent } from "./app-sidebar";
import { findNavItem } from "./nav-config";

export function AppHeader() {
  const pathname = usePathname();
  const user = useAuthenticatedUser();
  const current = findNavItem(pathname);
  const [open, setOpen] = React.useState(false);

  return (
    <header className="sticky top-0 z-20 border-b border-border bg-background/85 backdrop-blur supports-[backdrop-filter]:bg-background/75">
      <div className="mx-auto flex h-14 max-w-[1400px] items-center gap-3 px-4 sm:px-6 lg:px-8">
        <Sheet open={open} onOpenChange={setOpen}>
          <SheetTrigger asChild>
            <button
              type="button"
              className="-ml-1.5 inline-flex size-9 cursor-pointer items-center justify-center rounded-md text-muted-foreground hover:bg-surface-muted hover:text-foreground lg:hidden"
              aria-label="Open navigation"
            >
              <Menu aria-hidden className="size-5" />
            </button>
          </SheetTrigger>
          <SheetContent className="bg-sidebar">
            <SheetTitle className="sr-only">Navigation</SheetTitle>
            <SheetDescription className="sr-only">Main navigation and account</SheetDescription>
            <SidebarContent onNavigate={() => setOpen(false)} />
          </SheetContent>
        </Sheet>

        <BrandMark className="size-7 lg:hidden" />

        <nav aria-label="Breadcrumb" className="min-w-0 flex-1">
          <ol className="flex items-center gap-1.5 text-sm">
            <li className="hidden text-subtle-foreground sm:block">Workspace</li>
            <li aria-hidden className="hidden text-border-strong sm:block">
              <ChevronRight className="size-3.5" />
            </li>
            <li className="truncate font-medium text-foreground" aria-current="page">
              {current?.title ?? "Workspace"}
            </li>
          </ol>
        </nav>

        <NotificationBell />

        <div className="flex items-center gap-2.5" aria-label="Signed in administrator">
          <div className="hidden text-right leading-tight sm:block">
            <p className="text-[13px] font-medium text-foreground">{user.name}</p>
            <p className="text-xs text-subtle-foreground">Administrator</p>
          </div>
          <span
            aria-hidden
            className="flex size-8 items-center justify-center rounded-full border border-border bg-surface text-xs font-semibold text-muted-foreground"
          >
            {initials(user.name)}
          </span>
        </div>
      </div>
    </header>
  );
}
