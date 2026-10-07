"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import {
  ArrowRight,
  Banknote,
  Building2,
  ChefHat,
  ClipboardPen,
  ConciergeBell,
  CupSoda,
  KeyRound,
  LogIn,
  LogOut,
  Map as MapIcon,
  QrCode,
  ShieldCheck,
  Sparkles,
  type LucideIcon,
} from "lucide-react";
import { CASHIER_ROLES, ORDER_ROLES, loadSession, saveSession, staffApi, type StaffBranch, type StaffSession } from "@/lib/staff";
import { Alert, Badge, Button, Field, inputClass } from "../ui";

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
      <main className="grid min-h-dvh lg:grid-cols-[1.1fr_1fr]">
        {/* Brand panel: a short headline on phones, a full panel on desktop. */}
        <section className="relative overflow-hidden bg-[var(--brand)] px-6 pt-10 pb-16 text-white sm:px-10 lg:flex lg:flex-col lg:justify-between lg:px-16 lg:py-14 dark:text-[#06140f]">
          <div aria-hidden className="pointer-events-none absolute -top-24 -right-24 size-80 rounded-full bg-white/10 blur-2xl" />
          <div aria-hidden className="pointer-events-none absolute -bottom-32 -left-20 size-96 rounded-full bg-[var(--accent)]/25 blur-3xl" />

          <div className="anim-fade-up relative flex items-center gap-3">
            <span className="grid size-11 place-items-center rounded-2xl bg-white/15 backdrop-blur">
              <QrCode className="size-6" strokeWidth={2.2} aria-hidden />
            </span>
            <span className="text-lg font-semibold tracking-tight">Roumdoul Order</span>
          </div>

          <div className="relative mt-10 max-w-lg lg:mt-0">
            <h1 className="anim-fade-up text-3xl leading-tight font-bold tracking-tight sm:text-4xl lg:text-5xl" style={{ "--i": 1 } as React.CSSProperties}>
              Every order, from table to kitchen to till.
            </h1>
            <p className="anim-fade-up mt-4 text-base text-white/80 lg:text-lg dark:text-[#06140f]/75" style={{ "--i": 2 } as React.CSSProperties}>
              Kitchen, bar, waiter and cashier screens for your team, updated live.
            </p>
          </div>

          <ul className="relative mt-10 hidden gap-3 lg:flex">
            {[
              { icon: ChefHat, label: "Kitchen" },
              { icon: ConciergeBell, label: "Waiter" },
              { icon: Banknote, label: "Cashier" },
            ].map(({ icon: Icon, label }, i) => (
              <li
                key={label}
                className="anim-fade-up flex items-center gap-2 rounded-full bg-white/12 px-4 py-2 text-sm font-medium backdrop-blur"
                style={{ "--i": 3 + i } as React.CSSProperties}
              >
                <Icon className="size-4" aria-hidden />
                {label}
              </li>
            ))}
          </ul>
        </section>

        {/* Form card */}
        <section className="-mt-8 flex justify-center px-4 pb-10 sm:px-6 lg:mt-0 lg:items-center lg:px-12 lg:py-12">
          <form
            onSubmit={signIn}
            className="anim-scale-in relative flex w-full max-w-md flex-col gap-5 rounded-3xl bg-[var(--surface)] p-6 shadow-float sm:p-8 lg:shadow-card"
          >
            <div className="flex flex-col gap-1">
              <span className="mb-2 grid size-12 place-items-center rounded-2xl bg-[var(--brand-soft)] text-[var(--brand)]">
                <KeyRound className="size-6" strokeWidth={2.2} aria-hidden />
              </span>
              <h2 className="text-2xl font-bold tracking-tight">Staff sign in</h2>
              <p className="text-[var(--muted)]">Use the email and password from your manager.</p>
            </div>

            <Field label="Email">
              <input id="email" type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} className={inputClass} placeholder="you@restaurant.com" />
            </Field>
            <Field label="Password">
              <input id="password" type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} className={inputClass} />
            </Field>

            {error && <Alert>{error}</Alert>}

            <Button type="submit" size="lg" icon={LogIn} disabled={busy} className="w-full">
              {busy ? "Signing in..." : "Sign in"}
            </Button>

            <p className="flex items-center justify-center gap-1.5 text-xs text-[var(--muted)]">
              <ShieldCheck className="size-3.5" aria-hidden />
              This device stays signed in until you sign out.
            </p>
          </form>
        </section>
      </main>
    );
  }

  return (
    <main className="mx-auto flex min-h-dvh w-full max-w-[1600px] flex-col gap-8 px-4 py-6 sm:px-6 sm:py-10 lg:px-10">
      <header className="anim-fade-up flex flex-wrap items-center gap-4">
        <span className="grid size-12 place-items-center rounded-2xl bg-[var(--brand)] text-white shadow-soft dark:text-[#06140f]">
          <QrCode className="size-6" strokeWidth={2.2} aria-hidden />
        </span>
        <div className="min-w-0 flex-1">
          <p className="text-sm font-medium text-[var(--muted)]">Roumdoul Order</p>
          <h1 className="truncate text-2xl font-bold tracking-tight sm:text-3xl">Hi, {session.user.name}</h1>
        </div>
        <Button tone="neutral" size="sm" icon={LogOut} onClick={signOut}>
          Sign out
        </Button>
      </header>

      <p className="anim-fade-up -mt-4 flex items-center gap-2 text-[var(--muted)]" style={{ "--i": 1 } as React.CSSProperties}>
        <Sparkles className="size-4 text-[var(--accent)]" aria-hidden />
        Choose a screen for this device.
      </p>

      {session.branches.length === 0 && (
        <Alert tone="warn">You are not on the staff of any branch yet. Ask the owner to add you on the Staff page.</Alert>
      )}

      {session.branches.map((branch, b) => (
        <section key={branch.id} className="flex flex-col gap-4">
          <div className="anim-fade-up flex flex-wrap items-center gap-3" style={{ "--i": 2 + b } as React.CSSProperties}>
            <span className="grid size-10 place-items-center rounded-xl bg-[var(--chip)] text-[var(--muted)]">
              <Building2 className="size-5" aria-hidden />
            </span>
            <div className="min-w-0">
              <h2 className="text-lg font-semibold">
                {branch.company} <span className="text-[var(--muted)]">·</span> {branch.name}
              </h2>
            </div>
            <Badge tone="brand" className="capitalize">{branch.role}</Badge>
          </div>
          <ul className="grid grid-cols-1 gap-4 min-[480px]:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
            {screensFor(branch).map((screen, i) => (
              <li key={screen.href} className="anim-fade-up" style={{ "--i": 3 + b * 6 + i } as React.CSSProperties}>
                <ScreenCard {...screen} />
              </li>
            ))}
          </ul>
        </section>
      ))}
    </main>
  );
}

type Screen = { href: string; title: string; text: string; icon: LucideIcon; tone: "brand" | "accent" | "dark" | "neutral" };

function screensFor(branch: StaffBranch): Screen[] {
  const list: (Screen | false)[] = [
    { href: `/staff/kitchen?branch=${branch.id}&station=kitchen`, title: "Kitchen", text: "Food tickets: start, cook and mark ready.", icon: ChefHat, tone: "neutral" },
    { href: `/staff/kitchen?branch=${branch.id}&station=bar`, title: "Bar", text: "Drinks tickets for the bar counter.", icon: CupSoda, tone: "neutral" },
    { href: `/staff/waiter?branch=${branch.id}`, title: "Waiter", text: "Table calls, ready dishes and sold-out items.", icon: ConciergeBell, tone: "neutral" },
    ORDER_ROLES.includes(branch.role) && {
      href: `/staff/waiter/order?branch=${branch.id}`,
      title: "New order",
      text: "Type in an order for a table.",
      icon: ClipboardPen,
      tone: "accent",
    },
    CASHIER_ROLES.includes(branch.role) && {
      href: `/staff/cashier?branch=${branch.id}`,
      title: "Cashier",
      text: "Bills, payments and the cash drawer.",
      icon: Banknote,
      tone: "brand",
    },
    ["owner", "manager"].includes(branch.role) && {
      href: `/staff/floor?branch=${branch.id}`,
      title: "Floor plan",
      text: "Design your tables in 3D",
      icon: MapIcon,
      tone: "dark",
    },
  ];
  return list.filter((s): s is Screen => Boolean(s));
}

const CARD_TONES = {
  neutral: { card: "bg-[var(--surface)]", tile: "bg-[var(--brand-soft)] text-[var(--brand)]", text: "text-[var(--muted)]" },
  accent: { card: "bg-[var(--surface)]", tile: "bg-[var(--accent-soft)] text-[var(--warn)]", text: "text-[var(--muted)]" },
  brand: { card: "bg-[var(--brand)] text-white dark:text-[#06140f]", tile: "bg-white/15 text-current", text: "opacity-80" },
  dark: { card: "bg-[var(--fg)] text-[var(--surface)]", tile: "bg-white/10 text-current dark:bg-black/10", text: "opacity-75" },
} as const;

function ScreenCard({ href, title, text, icon: Icon, tone }: Screen) {
  const t = CARD_TONES[tone];
  return (
    <Link
      href={href}
      className={`group flex h-full min-h-44 flex-col justify-between gap-6 rounded-3xl border border-[var(--line)] p-6 shadow-soft transition duration-200 hover:-translate-y-1 hover:shadow-card active:scale-[0.98] ${t.card}`}
    >
      <span className={`grid size-14 place-items-center rounded-2xl transition duration-300 group-hover:scale-110 ${t.tile}`}>
        <Icon className="size-7" strokeWidth={2} aria-hidden />
      </span>
      <span className="flex items-end justify-between gap-3">
        <span className="flex flex-col gap-1">
          <span className="text-xl font-bold tracking-tight">{title}</span>
          <span className={`text-sm leading-snug ${t.text}`}>{text}</span>
        </span>
        <ArrowRight className="size-5 shrink-0 opacity-40 transition duration-200 group-hover:translate-x-1 group-hover:opacity-100" aria-hidden />
      </span>
    </Link>
  );
}
