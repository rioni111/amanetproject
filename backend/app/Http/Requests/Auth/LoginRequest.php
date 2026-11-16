<?php

namespace App\Http\Requests\Auth;

use App\Enums\UserRole;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /** Failed attempts allowed per email + IP before a lockout. */
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Attempt to authenticate an administrator. Only failed attempts count towards the limit.
     *
     * @throws ValidationException|ThrottleRequestsException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = [
            'email' => mb_strtolower(trim($this->string('email')->toString())),
            'password' => $this->string('password')->toString(),
            'role' => UserRole::Admin->value,
        ];

        if (! Auth::guard('web')->attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /** @throws ThrottleRequestsException */
    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw new ThrottleRequestsException(
            "Too many login attempts. Please try again in {$seconds} seconds.",
            null,
            ['Retry-After' => $seconds],
        );
    }

    public function throttleKey(): string
    {
        return 'login:'.Str::transliterate(Str::lower($this->string('email')->toString()).'|'.$this->ip());
    }
}
