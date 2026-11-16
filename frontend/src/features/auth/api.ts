import { api, ensureCsrfCookie } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { Resource, User } from "@/types/api";

export interface LoginCredentials {
  email: string;
  password: string;
  remember: boolean;
}

export async function login(credentials: LoginCredentials): Promise<User> {
  await ensureCsrfCookie(true);
  const response = await api.post<Resource<User>>("/api/auth/login", credentials);
  return response.data;
}

export async function logout(): Promise<void> {
  await api.post<void>("/api/auth/logout");
}

/** Returns the signed-in administrator, or null when there is no valid session. */
export async function fetchCurrentUser(signal?: AbortSignal): Promise<User | null> {
  try {
    const response = await api.get<Resource<User>>("/api/auth/me", undefined, signal);
    return response.data;
  } catch (error) {
    if (error instanceof ApiError && (error.status === 401 || error.status === 419)) return null;
    throw error;
  }
}
