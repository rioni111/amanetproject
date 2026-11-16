import * as React from "react";

import { AppHeader } from "./app-header";
import { AppSidebar } from "./app-sidebar";

export function AppShell({ children }: { children: React.ReactNode }) {
  return (
    <div className="min-h-dvh">
      <a
        href="#main-content"
        className="sr-only z-50 rounded-md bg-primary px-4 py-2 text-sm font-medium text-white focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
      >
        Skip to content
      </a>
      <AppSidebar />
      <div className="lg:pl-64">
        <AppHeader />
        <main id="main-content" tabIndex={-1} className="mx-auto max-w-[1400px] px-4 py-8 outline-none sm:px-6 lg:px-8 lg:py-10">
          {children}
        </main>
      </div>
    </div>
  );
}
