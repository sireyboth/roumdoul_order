"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { loadSession, saveSession, staffApi, type StaffSession } from "@/lib/staff";

export default function StaffHome() {
  const [session, setSession] = useState<StaffSession | null>(null);
  const [ready, setReady] = useState(false);
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- storage is only readable after mount
    setSession(loadSession());
    setReady(true);
  }, []);

  async function signIn(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const data = await staffApi<StaffSession>("login", {
        method: "POST",
        body: { email, password, device_name: navigator.userAgent.slice(0, 60) },
        token: null,
      });
      saveSession(data);
      setSession(data);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Sign-in failed.");
    } finally {
      setBusy(false);
    }
  }

  async function signOut() {
    try {
      await staffApi("logout", { method: "POST" });
    } catch {
      /* already signed out */
    }
    saveSession(null);
    setSession(null);
  }

  if (!ready) return null;

  if (!session) {
    return (
      <main className="mx-auto flex min-h-dvh max-w-sm flex-col justify-center gap-6 px-6">
        <div>
          <h1 className="text-2xl font-semibold">Staff sign in</h1>
          <p className="text-[var(--muted)]">Kitchen, waiter and cashier screens</p>
        </div>
        <form onSubmit={signIn} className="flex flex-col gap-3">
          <label className="flex flex-col gap-1.5">
            <span className="text-sm font-medium">Email</span>
            <input id="email" type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)}
              className="rounded-lg border border-[var(--line)] bg-transparent px-3 py-2.5" />
          </label>
          <label className="flex flex-col gap-1.5">
            <span className="text-sm font-medium">Password</span>
            <input id="password" type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)}
              className="rounded-lg border border-[var(--line)] bg-transparent px-3 py-2.5" />
          </label>
          {error && <p role="alert" className="rounded-lg bg-[var(--danger-bg)] p-3 text-sm text-[var(--danger)]">{error}</p>}
          <button type="submit" disabled={busy} className="rounded-xl bg-[var(--brand)] px-4 py-3 font-semibold text-white disabled:opacity-50">
            {busy ? "Signing in..." : "Sign in"}
          </button>
        </form>
      </main>
    );
  }

  return (
    <main className="mx-auto flex min-h-dvh max-w-xl flex-col gap-6 px-6 py-10">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold">Hi, {session.user.name}</h1>
          <p className="text-[var(--muted)]">Choose a screen for this device.</p>
        </div>
        <button type="button" onClick={signOut} className="rounded-full border border-[var(--line)] px-3 py-1.5 text-sm">Sign out</button>
      </div>

      {session.branches.map((branch) => (
        <section key={branch.id} className="flex flex-col gap-3 rounded-2xl border border-[var(--line)] p-4">
          <div>
            <h2 className="font-semibold">{branch.company} · {branch.name}</h2>
            <p className="text-sm capitalize text-[var(--muted)]">Your role: {branch.role}</p>
          </div>
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
            <Link href={`/staff/kitchen?branch=${branch.id}&station=kitchen`} className="rounded-xl bg-[var(--chip)] px-3 py-4 text-center font-medium">🍳 Kitchen</Link>
            <Link href={`/staff/kitchen?branch=${branch.id}&station=bar`} className="rounded-xl bg-[var(--chip)] px-3 py-4 text-center font-medium">☕ Bar</Link>
            <Link href={`/staff/waiter?branch=${branch.id}`} className="rounded-xl bg-[var(--chip)] px-3 py-4 text-center font-medium">🛎️ Waiter</Link>
          </div>
        </section>
      ))}
    </main>
  );
}
