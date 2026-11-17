import type { Metadata } from "next";
import { Suspense } from "react";

import { ListSkeleton } from "@/components/shared/loading-skeleton";
import { NotificationsView } from "@/features/notifications/notifications-view";

// Rendered behind the client-side session check in (dashboard)/layout.tsx.
export const instant = false;

export const metadata: Metadata = { title: "Notifications" };

export default function NotificationsPage() {
  return (
    <Suspense fallback={<ListSkeleton rows={8} />}>
      <NotificationsView />
    </Suspense>
  );
}
