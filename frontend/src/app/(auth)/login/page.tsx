import { Briefcase, CalendarClock, ShieldCheck } from "lucide-react";
import type { Metadata } from "next";

import { BrandLockup, BrandMark } from "@/components/shared/brand-mark";
import { LoginForm } from "@/features/auth/login-form";
import { RedirectIfAuthenticated } from "@/features/auth/redirect-if-authenticated";

export const metadata: Metadata = { title: "Sign in" };

const highlights = [
  { icon: Briefcase, title: "Every matter in one place", body: "Status, priority and deadlines for each client engagement." },
  { icon: CalendarClock, title: "Deadlines you won't miss", body: "Upcoming and overdue dates surfaced the moment you sign in." },
  { icon: ShieldCheck, title: "Private by design", body: "Session-secured administrator access with server-side authorisation." },
];

export default function LoginPage() {
  return (
    <main className="grid min-h-dvh lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
      {/* Brand panel */}
      <section
        aria-label="About Lexora Workspace"
        className="relative hidden flex-col justify-between overflow-hidden bg-sidebar p-12 text-sidebar-foreground lg:flex"
      >
        <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-sidebar-accent/60 to-transparent" />
        <BrandLockup tone="light" />

        <div className="max-w-md">
          <p className="text-[13px] font-medium tracking-[0.08em] text-sidebar-accent uppercase">Legal matter workspace</p>
          <h2 className="mt-4 font-serif text-[34px] leading-[1.15] tracking-[-0.01em] text-white">
            Calm, organised control over every client matter.
          </h2>
          <ul className="mt-10 flex flex-col gap-6">
            {highlights.map(({ icon: Icon, title, body }) => (
              <li key={title} className="flex gap-4">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg border border-sidebar-border bg-sidebar-hover">
                  <Icon aria-hidden className="size-4 text-sidebar-accent" />
                </span>
                <span>
                  <span className="block text-sm font-medium text-white">{title}</span>
                  <span className="mt-0.5 block text-[13px] text-sidebar-muted">{body}</span>
                </span>
              </li>
            ))}
          </ul>
        </div>

        <p className="text-xs text-sidebar-muted">All matters and clients in this workspace are fictional demo data.</p>
      </section>

      {/* Sign-in form */}
      <section className="flex items-center justify-center px-4 py-12 sm:px-8">
        <div className="w-full max-w-[400px]">
          <div className="mb-10 lg:hidden">
            <BrandLockup />
          </div>
          <BrandMark className="mb-6 hidden size-10 lg:block" />
          <h1 className="text-[28px] leading-tight font-semibold tracking-[-0.02em]">Welcome back</h1>
          <p className="mt-2 text-sm text-muted-foreground">Sign in to your administrator workspace.</p>

          <div className="mt-8">
            <RedirectIfAuthenticated />
            <LoginForm />
          </div>

          <p className="mt-10 text-xs text-subtle-foreground">
            Restricted to firm administrators. Need access? Ask your workspace owner to create an account for you.
          </p>
        </div>
      </section>
    </main>
  );
}
