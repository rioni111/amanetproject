import { cn } from "@/lib/utils/cn";

/**
 * Original Lexora monogram: a stylised "L" set inside a seal, with a single
 * accent point suggesting a signature mark.
 */
export function BrandMark({ className, title }: { className?: string; title?: string }) {
  return (
    <svg
      viewBox="0 0 32 32"
      className={cn("size-8", className)}
      role={title ? "img" : undefined}
      aria-hidden={title ? undefined : true}
      aria-label={title}
    >
      <rect width="32" height="32" rx="8" fill="#0F766E" />
      <path
        d="M11 8.5v13.25c0 .97.78 1.75 1.75 1.75H22"
        fill="none"
        stroke="#fff"
        strokeWidth="2.6"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <circle cx="21.75" cy="10.25" r="2.1" fill="#5EEAD4" />
    </svg>
  );
}

export function BrandLockup({ className, tone = "dark" }: { className?: string; tone?: "dark" | "light" }) {
  return (
    <div className={cn("flex items-center gap-2.5", className)}>
      <BrandMark />
      <div className="leading-none">
        <span className={cn("block text-[15px] font-semibold tracking-[-0.01em]", tone === "light" ? "text-white" : "text-foreground")}>
          Lexora
        </span>
        <span
          className={cn(
            "mt-1 block text-[11px] font-medium tracking-[0.08em] uppercase",
            tone === "light" ? "text-sidebar-muted" : "text-subtle-foreground",
          )}
        >
          Workspace
        </span>
      </div>
    </div>
  );
}
