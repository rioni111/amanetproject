"use client";

import * as LabelPrimitive from "@radix-ui/react-label";
import * as React from "react";

import { cn } from "@/lib/utils/cn";

function Label({ className, ...props }: React.ComponentProps<typeof LabelPrimitive.Root>) {
  return (
    <LabelPrimitive.Root
      data-slot="label"
      className={cn("text-[13px] font-medium leading-none text-foreground select-none", className)}
      {...props}
    />
  );
}

export { Label };
