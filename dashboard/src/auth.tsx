import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { api, getToken, setToken, type User } from "./api";

/** Waits before each retry of /auth/me while the server is down. After the last one, staff retry by hand. */
const RETRY_DELAYS_MS = [1000, 2000, 4000, 8000];

export type ServerState = "ok" | "retrying" | "down";

type AuthContextValue = {
  user: User | null;
  ready: boolean;
  server: ServerState;
  retry: () => void;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [ready, setReady] = useState(false);
  const [server, setServer] = useState<ServerState>("ok");
  const timer = useRef<number | undefined>(undefined);

  const check = useCallback((attempt: number) => {
    window.clearTimeout(timer.current);
    if (!getToken()) {
      setServer("ok");
      setReady(true);
      return;
    }
    api
      .me()
      .then((res) => {
        setUser(res.data);
        setServer("ok");
        setReady(true);
      })
      .catch(() => {
        // The request layer drops the token on 401 or 419. Anything else keeps the session.
        if (!getToken()) {
          setUser(null);
          setServer("ok");
          setReady(true);
          return;
        }
        if (attempt >= RETRY_DELAYS_MS.length) {
          setServer("down");
          return;
        }
        setServer("retrying");
        timer.current = window.setTimeout(() => check(attempt + 1), RETRY_DELAYS_MS[attempt]);
      });
  }, []);

  useEffect(() => {
    check(0);
    return () => window.clearTimeout(timer.current);
  }, [check]);

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      ready,
      server,
      retry: () => {
        setServer("retrying");
        check(0);
      },
      async login(email, password) {
        const res = await api.login(email, password);
        if (!res.data.user.is_admin && !res.data.user.role) {
          setToken(null);
          throw new Error("forbidden");
        }
        setToken(res.data.token);
        setUser(res.data.user);
      },
      async logout() {
        try {
          await api.logout();
        } finally {
          setToken(null);
          setUser(null);
        }
      },
    }),
    [user, ready, server, check],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within AuthProvider");
  return ctx;
}
