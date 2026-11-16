import { ArrowRight, Briefcase } from "lucide-react";
import Link from "next/link";

import { DataTable } from "@/components/shared/data-table";
import { EmptyState } from "@/components/shared/empty-state";
import { Card, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { MatterMobileItem, matterColumns } from "@/features/matters/matter-columns";
import type { Matter } from "@/types/api";

export function RecentMatters({ matters }: { matters: Matter[] }) {
  return (
    <Card className="overflow-hidden">
      <CardHeader>
        <div>
          <CardTitle>Recent matters</CardTitle>
          <CardDescription>Most recently updated across all clients.</CardDescription>
        </div>
        <Link href="/matters" className="inline-flex items-center gap-1 text-[13px] font-medium text-primary hover:underline">
          View all <ArrowRight aria-hidden className="size-3.5" />
        </Link>
      </CardHeader>
      {matters.length === 0 ? (
        <EmptyState
          icon={Briefcase}
          title="No matters yet"
          description="Matters you open will appear here."
          className="border-t border-border"
        />
      ) : (
        <DataTable
          className="border-t border-border"
          caption="Recently updated matters"
          rows={matters}
          getRowKey={(m) => m.id}
          columns={[matterColumns.matterWithClient, matterColumns.status, matterColumns.priority, matterColumns.deadline]}
          renderMobileItem={(m) => <MatterMobileItem matter={m} />}
        />
      )}
    </Card>
  );
}
