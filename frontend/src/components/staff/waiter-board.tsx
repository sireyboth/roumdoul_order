"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { chime, since, staffApi, type BoardOrder, type BoardRequest } from "@/lib/staff";
import type { Names } from "@/lib/types";
import { useBoard } from "./use-board";

type MenuRow = { menu_item_id: number; name: Names; category: string | null; sold_out: boolean };

export default function WaiterBoard({ branchId }: { branchId: number }) {
  const { board, error, signedOut, now, refresh } = useBoard(branchId, null, 4000);
  const [tab, setTab] = useState<"floor" | "menu">("floor");
  const [menu, setMenu] = useState<MenuRow[] | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [soundOn, setSoundOn] = useState(false);
  const audio = useRef<AudioContext | null>(null);
  const seen = useRef<Set<string> | null>(null);

  // Chime for new calls and newly ready orders.
  useEffect(() => {
    if (!board) return;
    const keys = new Set([
      ...board.requests.map((r) => `r${r.id}`),
      ...board.orders.filter((o) => o.status === "ready").map((o) => `o${o.id}`),
    ]);
    if (seen.current && soundOn && [...keys].some((k) => !seen.current!.has(k))) chime(audio.current);
    seen.current = new Set([...(seen.current ?? []), ...keys]);
  }, [board, soundOn]);

  const loadMenu = useCallback(async () => {
    try {
      setMenu(await staffApi<MenuRow[]>(`branches/${branchId}/menu`));
    } catch (e) {
      setActionError(e instanceof Error ? e.message : "Can't load the menu.");
    }
  }, [branchId]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- load once when the tab opens
    if (tab === "menu") void loadMenu();
  }, [tab, loadMenu]);

  async function act(key: string, path: string, body?: unknown, after?: () => Promise<void>) {
    setBusy(key);
    setActionError(null);
    try {
      await staffApi(path, { method: "POST", body });
      await (after ?? refresh)();
    } catch (e) {
      setActionError(e instanceof Error ? e.message : "Something went wrong.");
    } finally {
      setBusy(null);
    }
  }

  if (signedOut) {
    return (
      <main className="grid min-h-dvh place-items-center p-6">
        <Link href="/staff" className="rounded-xl bg-[var(--brand)] px-5 py-3 font-semibold text-white">Sign in again</Link>
      </main>
    );
  }

  const requests = board?.requests ?? [];
  const ready = (board?.orders ?? []).filter((o) => o.status === "ready");
  const cooking = (board?.orders ?? []).filter((o) => ["placed", "accepted", "preparing"].includes(o.status));

  return (
    <main className="mx-auto flex min-h-dvh max-w-xl flex-col gap-4 px-4 pb-10">
      <header className="sticky top-0 z-10 -mx-4 flex flex-wrap items-center gap-2 border-b border-[var(--line)] bg-[var(--surface)] px-4 py-3">
        <Link href="/staff" className="text-sm text-[var(--muted)]">← Screens</Link>
        <h1 className="font-semibold">Waiter</h1>
        <div className="ml-auto flex gap-1 rounded-full bg-[var(--chip)] p-1 text-sm">
          <button type="button" onClick={() => setTab("floor")} className={`rounded-full px-3 py-1 ${tab === "floor" ? "bg-[var(--surface)] font-semibold shadow" : ""}`}>
            Tables {requests.length + ready.length > 0 && <span className="ml-1 rounded-full bg-[var(--danger)] px-1.5 text-xs text-white">{requests.length + ready.length}</span>}
          </button>
          <button type="button" onClick={() => setTab("menu")} className={`rounded-full px-3 py-1 ${tab === "menu" ? "bg-[var(--surface)] font-semibold shadow" : ""}`}>
            Sold out
          </button>
        </div>
        {!soundOn && (
          <button
            type="button"
            onClick={() => {
              audio.current ??= new AudioContext();
              void audio.current.resume();
              chime(audio.current);
              setSoundOn(true);
            }}
            className="w-full rounded-lg bg-amber-300 px-3 py-1.5 text-sm font-semibold text-black"
          >
            🔔 Turn on sound for calls
          </button>
        )}
      </header>

      {(error || actionError) && (
        <p role="alert" className="rounded-lg bg-[var(--danger-bg)] p-3 text-sm text-[var(--danger)]">{actionError ?? error}</p>
      )}

      {tab === "floor" ? (
        <>
          <Section title="Calls" count={requests.length} empty="No one is calling">
            {requests.map((r: BoardRequest) => (
              <Row key={r.id} tone={r.type === "bill" ? "brand" : "warn"}>
                <div className="min-w-0 flex-1">
                  <p className="text-lg font-bold">{r.table ?? "—"}</p>
                  <p className="text-sm">{r.type === "bill" ? "💵 Wants the bill" : "🛎️ Calling waiter"} · {since(r.created_at, now).label} ago</p>
                </div>
                <button type="button" disabled={busy === `r${r.id}`} onClick={() => act(`r${r.id}`, `requests/${r.id}/done`)}
                  className="rounded-lg bg-[var(--brand)] px-4 py-2.5 font-semibold text-white disabled:opacity-50">Done</button>
              </Row>
            ))}
          </Section>

          <Section title="Ready to serve" count={ready.length} empty="Nothing waiting">
            {ready.map((o: BoardOrder) => (
              <Row key={o.id} tone="ok">
                <div className="min-w-0 flex-1">
                  <p className="text-lg font-bold">{o.table ?? "—"} <span className="text-sm font-medium text-[var(--muted)]">#{o.number}</span></p>
                  <p className="text-sm">{o.items.map((i) => `${i.quantity}× ${i.name.en}`).join(", ")}</p>
                </div>
                <button type="button" disabled={busy === `o${o.id}`} onClick={() => act(`o${o.id}`, `orders/${o.id}/status`, { status: "served" })}
                  className="rounded-lg bg-[var(--brand)] px-4 py-2.5 font-semibold text-white disabled:opacity-50">Served</button>
              </Row>
            ))}
          </Section>

          <Section title="Being prepared" count={cooking.length} empty="Kitchen is clear">
            {cooking.map((o: BoardOrder) => (
              <Row key={o.id}>
                <div className="min-w-0 flex-1">
                  <p className="font-semibold">{o.table ?? "—"} <span className="text-sm font-medium text-[var(--muted)]">#{o.number} · {o.status}</span></p>
                  <p className="text-sm text-[var(--muted)]">{o.items.map((i) => `${i.quantity}× ${i.name.en}`).join(", ")}</p>
                </div>
                <span className="text-sm tabular-nums text-[var(--muted)]">{since(o.placed_at, now).label}</span>
              </Row>
            ))}
          </Section>
        </>
      ) : (
        <Section title="Tap to mark sold out for today" count={menu?.filter((m) => m.sold_out).length ?? 0} empty="Loading menu...">
          {(menu ?? []).map((m) => (
            <Row key={m.menu_item_id} tone={m.sold_out ? "danger" : undefined}>
              <div className="min-w-0 flex-1">
                <p className="font-medium">{m.name.km || m.name.en}</p>
                <p className="text-sm text-[var(--muted)]">{m.name.en} · {m.category}</p>
              </div>
              <button
                type="button"
                disabled={busy === `m${m.menu_item_id}`}
                onClick={() => act(`m${m.menu_item_id}`, `branches/${branchId}/menu/${m.menu_item_id}/sold-out`, { sold_out: !m.sold_out }, loadMenu)}
                className={`rounded-lg px-3 py-2 text-sm font-semibold disabled:opacity-50 ${m.sold_out ? "bg-[var(--danger)] text-white" : "border border-[var(--line)]"}`}
              >
                {m.sold_out ? "Sold out" : "Available"}
              </button>
            </Row>
          ))}
        </Section>
      )}
    </main>
  );
}

function Section({ title, count, empty, children }: { title: string; count: number; empty: string; children: React.ReactNode }) {
  const hasChildren = Array.isArray(children) ? children.length > 0 : Boolean(children);
  return (
    <section className="flex flex-col gap-2">
      <h2 className="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-[var(--muted)]">
        {title} <span className="rounded-full bg-[var(--chip)] px-2 tabular-nums">{count}</span>
      </h2>
      {hasChildren ? children : <p className="rounded-lg bg-[var(--chip)] p-3 text-sm text-[var(--muted)]">{empty}</p>}
    </section>
  );
}

function Row({ children, tone }: { children: React.ReactNode; tone?: "warn" | "ok" | "brand" | "danger" }) {
  const border = { warn: "border-l-amber-400", ok: "border-l-emerald-500", brand: "border-l-[var(--brand)]", danger: "border-l-[var(--danger)]" }[tone ?? "warn"];
  return (
    <div className={`flex items-center gap-3 rounded-lg border border-[var(--line)] border-l-4 p-3 ${tone ? border : "border-l-[var(--line)]"}`}>
      {children}
    </div>
  );
}
