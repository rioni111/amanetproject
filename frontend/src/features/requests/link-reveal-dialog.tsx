"use client";

import { AlertTriangle, Check, Copy, KeyRound } from "lucide-react";
import * as React from "react";

import { Button } from "@/components/ui/button";
import { Dialog, DialogClose, DialogContent, DialogFooter } from "@/components/ui/dialog";
import { formatDateTime } from "@/lib/utils/format";
import type { IssuedLink } from "@/types/api";

/**
 * Shows a newly issued upload link ONCE. The URL contains the client's secret token, so it lives only
 * in this dialog's props (component state of the caller) — never in the query cache, storage, the
 * page URL or logs — and is gone when the dialog closes. Nothing here claims the link was emailed.
 */
export function LinkRevealDialog({
  link,
  onClose,
  title = "Secure upload link",
  intro,
}: {
  link: IssuedLink | null;
  onClose: () => void;
  title?: string;
  intro?: React.ReactNode;
}) {
  return (
    <Dialog open={link !== null} onOpenChange={(open) => (!open ? onClose() : undefined)}>
      <DialogContent
        title={title}
        description="Share this link with the client yourself (for example by email or your client portal). It is shown only now."
      >
        {link ? <LinkBody key={link.id} link={link} intro={intro} /> : null}
        <DialogFooter>
          <DialogClose asChild>
            <Button variant="secondary">Done</Button>
          </DialogClose>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/** Keyed by link id, so the copy status starts fresh for every newly issued link. */
function LinkBody({ link, intro }: { link: IssuedLink; intro?: React.ReactNode }) {
  const [copied, setCopied] = React.useState<"copied" | "failed" | null>(null);
  const inputRef = React.useRef<HTMLInputElement>(null);

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(link.url);
      setCopied("copied");
    } catch {
      // Clipboard unavailable (permissions, insecure context): select the text for manual copying.
      inputRef.current?.select();
      setCopied("failed");
    }
  };

  return (
    <div className="flex flex-col gap-4 px-5 py-5">
      {intro}
      <div className="flex flex-col gap-2">
        <label htmlFor="issued-link-url" className="text-[13px] font-medium text-foreground">
          Upload link
        </label>
        <div className="flex flex-col gap-2 sm:flex-row">
          <input
            ref={inputRef}
            id="issued-link-url"
            readOnly
            value={link.url}
            onFocus={(e) => e.currentTarget.select()}
            className="h-9 min-w-0 flex-1 rounded-md border border-border-strong bg-surface-muted/60 px-3 font-mono text-xs text-foreground"
            data-testid="issued-link-url"
            autoComplete="off"
            spellCheck={false}
          />
          <Button onClick={() => void copy()} aria-describedby="copy-status">
            {copied === "copied" ? <Check aria-hidden /> : <Copy aria-hidden />}
            {copied === "copied" ? "Copied" : "Copy link"}
          </Button>
        </div>
        <p id="copy-status" role="status" aria-live="polite" className="text-xs text-muted-foreground">
          {copied === "copied"
            ? "Link copied to the clipboard."
            : copied === "failed"
              ? "Copying is not available here — the link is selected; press Ctrl+C to copy it."
              : `Valid until ${formatDateTime(link.expires_at)} · one upload only.`}
        </p>
      </div>

      <div className="flex items-start gap-2.5 rounded-lg border border-border bg-surface-muted/60 px-3.5 py-3 text-[13px] text-muted-foreground">
        <KeyRound aria-hidden className="mt-0.5 size-4 shrink-0 text-primary" />
        <span>
          Anyone with this link can upload one PDF for this request only — it gives no access to the matter, other documents or notes. The
          firm stores only a fingerprint of it, so it cannot be shown again: if it is lost, generate a replacement (the old link then stops
          working).
        </span>
      </div>

      {link.is_local_url ? (
        <div
          role="note"
          className="flex items-start gap-2.5 rounded-lg border border-warning/30 bg-warning-soft px-3.5 py-3 text-[13px] text-warning"
          data-testid="localhost-warning"
        >
          <AlertTriangle aria-hidden className="mt-0.5 size-4 shrink-0" />
          <span>
            This local development link is only accessible from the development machine unless the application is exposed through a
            configured, secure public hostname.
          </span>
        </div>
      ) : null}
    </div>
  );
}
