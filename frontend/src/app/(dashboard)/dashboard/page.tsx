import type { Metadata } from "next";

import { DashboardView } from "@/features/dashboard/dashboard-view";

// Rendered behind the client-side session check in (dashboard)/layout.tsx.
export const instant = false;

export const metadata: Metadata = { title: "Dashboard" };

export default function DashboardPage() {
  return <DashboardView />;
}
