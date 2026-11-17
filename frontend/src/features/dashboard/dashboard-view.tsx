"use client";

import { Briefcase, CalendarClock, ListChecks, Users } from "lucide-react";

import { CommunicationsSummary } from "@/components/dashboard/communications-summary";
import { RecentMatters } from "@/components/dashboard/recent-matters";
import { RequestsSummary } from "@/components/dashboard/requests-summary";
import { SignaturesSummary } from "@/components/dashboard/signatures-summary";
import { StatCard, StatCardSkeleton } from "@/components/dashboard/stat-card";
import { StatusBreakdown } from "@/components/dashboard/status-breakdown";
import { UpcomingDeadlines } from "@/components/dashboard/upcoming-deadlines";
import { ErrorState } from "@/components/shared/error-state";
import { ListSkeleton, TableSkeleton } from "@/components/shared/loading-skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { Card, CardContent, CardHeader } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useAuthenticatedUser } from "@/features/auth/auth-guard";
import { useNow } from "@/hooks/use-now";
import { firstName, formatLongDate, greeting, pluralize } from "@/lib/utils/format";

import { useDashboardSummary } from "./api";

export function DashboardView() {
  const user = useAuthenticatedUser();
  const now = useNow();
  const { data, isPending, isError, error, refetch, isRefetching } = useDashboardSummary();

  return (
    <div className="flex flex-col gap-8">
      <PageHeader
        eyebrow={now ? formatLongDate(now) : " "}
        title={`${now ? greeting(now) : "Welcome"}, ${firstName(user.name)}`}
        description={
          data
            ? `You have ${pluralize(data.stats.active_matters, "active matter")} and ${pluralize(data.stats.upcoming_deadlines, "deadline")} in the next ${data.upcoming_window_days} days.`
            : "Here's an overview of matters, clients and deadlines across your workspace."
        }
      />

      {isError ? (
        <Card>
          <ErrorState error={error} title="We couldn't load your dashboard" onRetry={() => refetch()} retrying={isRefetching} />
        </Card>
      ) : (
        <>
          <section aria-label="Key figures" className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {isPending ? (
              Array.from({ length: 4 }).map((_, i) => <StatCardSkeleton key={i} />)
            ) : (
              <>
                <StatCard
                  label="Active matters"
                  value={data.stats.active_matters}
                  icon={Briefcase}
                  hint={`of ${data.stats.total_matters} total matters`}
                  href="/matters?status=active"
                  linkLabel={`Active matters: ${data.stats.active_matters}. View active matters`}
                />
                <StatCard
                  label="Total clients"
                  value={data.stats.total_clients}
                  icon={Users}
                  hint="Individuals and organizations"
                  href="/clients"
                  linkLabel={`Total clients: ${data.stats.total_clients}. View clients`}
                />
                <StatCard
                  label="Upcoming deadlines"
                  value={data.stats.upcoming_deadlines}
                  icon={CalendarClock}
                  hint={
                    <>
                      Next {data.upcoming_window_days} days
                      {data.stats.overdue_matters > 0 ? (
                        <span className="font-medium text-danger"> · {data.stats.overdue_matters} overdue</span>
                      ) : null}
                    </>
                  }
                  href="/matters?status=active&sort=due_at"
                  linkLabel={`Upcoming deadlines: ${data.stats.upcoming_deadlines}. View matters by deadline`}
                />
                <StatCard
                  label="Open tasks"
                  value={data.stats.open_tasks}
                  icon={ListChecks}
                  hint={
                    data.stats.overdue_tasks > 0 ? (
                      <span className="font-medium text-danger">{data.stats.overdue_tasks} past due</span>
                    ) : (
                      "None past due"
                    )
                  }
                />
              </>
            )}
          </section>

          {isPending ? (
            <Card className="p-5">
              <Skeleton className="h-3 w-32" />
              <Skeleton className="mt-2 h-3 w-56" />
              <Skeleton className="mt-5 h-2.5 w-full rounded-full" />
            </Card>
          ) : (
            <StatusBreakdown breakdown={data.status_breakdown} total={data.stats.total_matters} />
          )}

          {isPending ? null : (
            <div className="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1.75fr)_minmax(0,1fr)]">
              <RequestsSummary stats={data.stats} />
              <SignaturesSummary stats={data.stats} />
              <div className="xl:col-span-2">
                <CommunicationsSummary stats={data.stats} />
              </div>
            </div>
          )}

          <div className="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1.75fr)_minmax(0,1fr)]">
            {isPending ? (
              <>
                <Card className="overflow-hidden">
                  <CardHeader>
                    <Skeleton className="h-4 w-36" />
                  </CardHeader>
                  <TableSkeleton rows={6} columns={5} className="border-t border-border" />
                </Card>
                <Card>
                  <CardHeader>
                    <Skeleton className="h-4 w-40" />
                  </CardHeader>
                  <CardContent>
                    <ListSkeleton rows={5} />
                  </CardContent>
                </Card>
              </>
            ) : (
              <>
                <RecentMatters matters={data.recent_matters} />
                <UpcomingDeadlines
                  matters={data.upcoming_deadlines}
                  windowDays={data.upcoming_window_days}
                  overdueCount={data.stats.overdue_matters}
                />
              </>
            )}
          </div>
        </>
      )}
    </div>
  );
}
