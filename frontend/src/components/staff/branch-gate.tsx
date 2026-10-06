"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { loadSession, type StaffSession } from "@/lib/staff";

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
    <main className="mx-auto flex min-h-dvh max-w-sm flex-col justify-center gap-4 px-6">
      {!session ? (
        <>
          <p className="text-[var(--muted)]">Please sign in first.</p>
          <Link href="/staff" className="rounded-xl bg-[var(--brand)] px-4 py-3 text-center font-semibold text-white">Staff sign in</Link>
        </>
      ) : (
        <>
          <h1 className="text-xl font-semibold">Which branch?</h1>
          {session.branches.map((b) => (
            <Link key={b.id} href={link(b.id)} className="rounded-xl border border-[var(--line)] px-4 py-3 font-medium">
              {b.company} · {b.name}
            </Link>
          ))}
          {session.branches.length === 0 && <p className="text-[var(--muted)]">You are not on the staff of any branch.</p>}
        </>
      )}
    </main>
  );
}

