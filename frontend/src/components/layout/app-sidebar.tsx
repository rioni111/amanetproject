import { BrandLockup } from "@/components/shared/brand-mark";

import { SidebarNav } from "./sidebar-nav";
import { UserPanel } from "./user-panel";

/** Persistent desktop sidebar (lg and up). */
export function AppSidebar() {
  return (
    <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col bg-sidebar lg:flex">
      <SidebarContent />
    </aside>
  );
}

export function SidebarContent({ onNavigate }: { onNavigate?: () => void }) {
  return (
    <div className="flex h-full flex-col px-3 py-5">
      <div className="px-3 pb-7">
        <BrandLockup tone="light" />
      </div>
      <div className="flex-1 overflow-y-auto">
        <SidebarNav onNavigate={onNavigate} />
      </div>
      <div className="pt-4">
        <UserPanel />
      </div>
    </div>
  );
}
