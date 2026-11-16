"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { AlertCircle, Eye, EyeOff, Loader2 } from "lucide-react";
import * as React from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";

import { FormField } from "@/components/shared/form-field";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ApiError, getErrorMessage } from "@/lib/api/errors";

import { useLogin } from "./hooks";

const loginSchema = z.object({
  email: z.string().trim().min(1, "Enter your email address.").email("Enter a valid email address.").max(255),
  password: z.string().min(1, "Enter your password.").max(255),
  remember: z.boolean(),
});

type LoginValues = z.infer<typeof loginSchema>;

export function LoginForm() {
  const loginMutation = useLogin();
  const [showPassword, setShowPassword] = React.useState(false);
  const [formError, setFormError] = React.useState<string | null>(null);

  const {
    register,
    handleSubmit,
    setError,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<LoginValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: "", password: "", remember: false },
  });

  const pending = isSubmitting || loginMutation.isPending || loginMutation.isSuccess;

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);
    try {
      await loginMutation.mutateAsync(values);
      // Navigation happens in <RedirectIfAuthenticated> once the session is seeded.
    } catch (error) {
      if (error instanceof ApiError && error.isValidation) {
        // Field-specific server validation (e.g. malformed email) vs. generic bad credentials.
        const credentialsMessage = error.field("email");
        if (credentialsMessage && Object.keys(error.fieldErrors).length === 1) {
          setFormError(credentialsMessage);
          setValue("password", "");
        } else {
          (["email", "password"] as const).forEach((field) => {
            const message = error.field(field);
            if (message) setError(field, { message });
          });
        }
        return;
      }
      setFormError(getErrorMessage(error));
    }
  });

  const demoEmail = process.env.NEXT_PUBLIC_DEMO_EMAIL;
  const demoPassword = process.env.NEXT_PUBLIC_DEMO_PASSWORD;

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-5" aria-describedby={formError ? "login-error" : undefined}>
      {formError ? (
        <div
          id="login-error"
          role="alert"
          className="flex items-start gap-2.5 rounded-lg border border-danger/20 bg-danger-soft px-3.5 py-3 text-[13px] text-danger"
        >
          <AlertCircle aria-hidden className="mt-px size-4 shrink-0" />
          <span>{formError}</span>
        </div>
      ) : null}

      <FormField id="email" label="Email address" error={errors.email?.message}>
        {(aria) => (
          <Input
            {...aria}
            {...register("email")}
            type="email"
            autoComplete="username"
            inputMode="email"
            placeholder="you@firm.com"
            autoFocus
          />
        )}
      </FormField>

      <FormField id="password" label="Password" error={errors.password?.message}>
        {(aria) => (
          <div className="relative">
            <Input
              {...aria}
              {...register("password")}
              type={showPassword ? "text" : "password"}
              autoComplete="current-password"
              className="pr-11"
            />
            <button
              type="button"
              onClick={() => setShowPassword((value) => !value)}
              className="absolute top-1/2 right-1.5 inline-flex size-8 -translate-y-1/2 cursor-pointer items-center justify-center rounded-md text-subtle-foreground transition-colors hover:bg-surface-muted hover:text-foreground"
              aria-label={showPassword ? "Hide password" : "Show password"}
              aria-pressed={showPassword}
              aria-controls="password"
            >
              {showPassword ? <EyeOff aria-hidden className="size-4" /> : <Eye aria-hidden className="size-4" />}
            </button>
          </div>
        )}
      </FormField>

      <label className="flex cursor-pointer items-center gap-2.5 text-[13px] text-muted-foreground select-none">
        <input type="checkbox" {...register("remember")} className="size-4 cursor-pointer rounded border-input accent-[var(--primary)]" />
        Keep me signed in on this device
      </label>

      <Button type="submit" size="lg" className="mt-1 w-full" disabled={pending}>
        {pending ? <Loader2 aria-hidden className="animate-spin" /> : null}
        {pending ? "Signing in…" : "Sign in"}
      </Button>

      {demoEmail && demoPassword ? (
        <div className="rounded-lg border border-dashed border-border-strong bg-surface-muted/60 px-4 py-3 text-[13px]">
          <p className="font-medium text-foreground">Demo access (development only)</p>
          <p className="mt-1 text-muted-foreground">
            <span className="font-mono text-xs">{demoEmail}</span> · <span className="font-mono text-xs">{demoPassword}</span>
          </p>
          <Button
            variant="link"
            size="sm"
            className="mt-1 px-0 text-[13px]"
            onClick={() => {
              setValue("email", demoEmail, { shouldValidate: true });
              setValue("password", demoPassword, { shouldValidate: true });
            }}
          >
            Fill in demo credentials
          </Button>
        </div>
      ) : null}
    </form>
  );
}
