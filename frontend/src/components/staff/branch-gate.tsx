"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { Building2, ChevronRight, LogIn, Store } from "lucide-react";
import { loadSession, type StaffSession } from "@/lib/staff";
import { EmptyState, IconTile, buttonClass } from "../ui";

/**
 * Staff screens need ?branch=. When it is missing (a typed or bookmarked address),
 * use the person's only branch, or let them pick one, instead of failing.
 */
export default function BranchGate({ branchId, children }: { branchId: number | null; children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const [session, setSession] = useState<StaffSession | null | undefined>(undefined);

  useEffect(() => {
    if (branchId) return;
    const current = loadSession();
    if (current?.branches.length === 1) {
      const next = new URLSearchParams(params.toString());
      next.set("branch", String(current.branches[0].id));
      router.replace(`${pathname}?${next.toString()}`);
      return;
    }
    // eslint-disable-next-line react-hooks/set-state-in-effect -- storage is only readable after mount
    setSession(current);
  }, [branchId, params, pathname, router]);

  if (branchId) return <>{children}</>;
  if (session === undefined) return null;

  const link = (id: number) => {
    const next = new URLSearchParams(params.toString());
    next.set("branch", String(id));
    return `${pathname}?${next.toString()}`;
  };

  return (
    <main className="grid min-h-dvh place-items-center px-4 py-10 sm:px-6">
      <div className="anim-scale-in flex w-full max-w-lg flex-col gap-5 rounded-3xl bg-[var(--surface)] p-6 shadow-card sm:p-8">
        {!session ? (
          <>
            <IconTile icon={LogIn} className="size-14" />
            <div>
              <h1 className="text-2xl font-bold tracking-tight">Please sign in first</h1>
              <p className="text-[var(--muted)]">This screen is for restaurant staff.</p>
            </div>
            <Link href="/staff" className={buttonClass("brand", "lg", "w-full")}>
              <LogIn className="size-[18px]" aria-hidden />
              Staff sign in
            </Link>
          </>
        ) : (
          <>
            <IconTile icon={Store} className="size-14" />
            <div>
              <h1 className="text-2xl font-bold tracking-tight">Which branch?</h1>
              <p className="text-[var(--muted)]">Pick the branch this device is in.</p>
            </div>
            <ul className="flex flex-col gap-2">
              {session.branches.map((b, i) => (
                <li key={b.id} className="anim-fade-up" style={{ "--i": i } as React.CSSProperties}>
                  <Link
                    href={link(b.id)}
                    className="group flex items-center gap-3 rounded-2xl border border-[var(--line)] px-4 py-3.5 font-medium transition hover:-translate-y-0.5 hover:border-[var(--brand)] hover:shadow-soft"
                  >
                    <Building2 className="size-5 text-[var(--muted)]" aria-hidden />
                    <span className="min-w-0 flex-1 truncate">
                      {b.company} <span className="text-[var(--muted)]">·</span> {b.name}
                    </span>
                    <ChevronRight className="size-5 text-[var(--muted)] transition group-hover:translate-x-0.5 group-hover:text-[var(--brand)]" aria-hidden />
                  </Link>
                </li>
              ))}
            </ul>
            {session.branches.length === 0 && <EmptyState icon={Store} title="No branch yet" text="You are not on the staff of any branch." />}
          </>
        )}
      </div>
    </main>
  );
}
