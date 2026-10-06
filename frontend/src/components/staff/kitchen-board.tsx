"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { chime, since, staffApi, type BoardOrder } from "@/lib/staff";
import { useBoard } from "./use-board";

const COLUMNS: { key: string; title: string; statuses: BoardOrder["status"][] }[] = [
  { key: "new", title: "New", statuses: ["placed", "accepted"] },
  { key: "cooking", title: "Preparing", statuses: ["preparing"] },
  { key: "ready", title: "Ready", statuses: ["ready"] },
];

const NEXT: Partial<Record<BoardOrder["status"], { status: string; label: string }>> = {
  placed: { status: "preparing", label: "Start" },
  accepted: { status: "preparing", label: "Start" },
  preparing: { status: "ready", label: "Ready" },
};

export default function KitchenBoard({ branchId, station }: { branchId: number; station: string | null }) {
  const { board, error, signedOut, now, refresh } = useBoard(branchId, station);
  const [soundOn, setSoundOn] = useState(false);
  const [moving, setMoving] = useState<number | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const audio = useRef<AudioContext | null>(null);
  const seen = useRef<Set<number> | null>(null);

  // Chime when an order appears that this screen hasn't seen before.
  useEffect(() => {
    if (!board) return;
    const ids = new Set(board.orders.filter((o) => o.status === "placed").map((o) => o.id));
    if (seen.current && soundOn && [...ids].some((id) => !seen.current!.has(id))) chime(audio.current);
    seen.current = new Set([...(seen.current ?? []), ...ids]);
  }, [board, soundOn]);

  function enableSound() {
    audio.current ??= new AudioContext();
    void audio.current.resume();
    chime(audio.current);
    setSoundOn(true);
  }

  async function move(order: BoardOrder, status: string) {
    setMoving(order.id);
    setActionError(null);
    try {
      await staffApi(`orders/${order.id}/status`, { method: "POST", body: { status } });
      await refresh();
    } catch (e) {
      setActionError(e instanceof Error ? e.message : "Could not update the order.");
    } finally {
      setMoving(null);
    }
  }

  if (signedOut) {
    return (
      <main className="grid min-h-dvh place-items-center bg-[#101413] p-6 text-white">
        <Link href="/staff" className="rounded-xl bg-white px-5 py-3 font-semibold text-black">Sign in again</Link>
      </main>
    );
  }

  const title = station === "bar" ? "Bar" : station === "kitchen" ? "Kitchen" : "All stations";

  return (
    <main className="flex min-h-dvh flex-col bg-[#101413] text-[#eef3f0]">
      <header className="flex flex-wrap items-center gap-3 border-b border-white/10 px-4 py-3">
        <Link href="/staff" className="text-sm text-white/60">← Screens</Link>
        <h1 className="text-lg font-semibold">{title}</h1>
        <div className="ml-auto flex items-center gap-2 text-sm">
          {(["kitchen", "bar", null] as const).map((s) => (
            <Link
              key={s ?? "all"}
              href={`/staff/kitchen?branch=${branchId}${s ? `&station=${s}` : ""}`}
              className={`rounded-full px-3 py-1 ${station === s ? "bg-white text-black" : "bg-white/10"}`}
            >
              {s === null ? "All" : s === "bar" ? "Bar" : "Kitchen"}
            </Link>
          ))}
          {!soundOn && (
            <button type="button" onClick={enableSound} className="rounded-full bg-amber-400 px-3 py-1 font-semibold text-black">
              🔔 Turn on sound
            </button>
          )}
        </div>
      </header>

      {(error || actionError) && (
        <p role="alert" className="bg-red-500/20 px-4 py-2 text-sm text-red-200">{actionError ?? error}</p>
      )}

      <div className="grid flex-1 gap-3 p-3 md:grid-cols-3">
        {COLUMNS.map((column) => {
          const orders = (board?.orders ?? []).filter((o) => column.statuses.includes(o.status));
          return (
            <section key={column.key} className="flex min-w-0 flex-col gap-3 rounded-xl bg-white/5 p-3">
              <h2 className="flex items-center justify-between text-sm font-semibold uppercase tracking-wide text-white/70">
                {column.title}
                <span className="rounded-full bg-white/10 px-2 py-0.5 tabular-nums">{orders.length}</span>
              </h2>
              {orders.length === 0 && <p className="py-6 text-center text-sm text-white/40">Nothing here</p>}
              {orders.map((order) => {
                const age = since(order.placed_at, now);
                const late = order.status !== "ready" && age.minutes >= 15;
                const warn = order.status !== "ready" && age.minutes >= 10;
                const next = NEXT[order.status];
                return (
                  <article
                    key={order.id}
                    className={`flex flex-col gap-2 rounded-lg border-l-4 bg-[#1a201e] p-3 ${
                      late ? "border-red-500" : warn ? "border-amber-400" : order.status === "ready" ? "border-emerald-400" : "border-white/20"
                    }`}
                  >
                    <div className="flex items-baseline justify-between gap-2">
                      <p className="text-xl font-bold">
                        {order.table ?? "—"} <span className="text-base font-medium text-white/50">#{order.number}</span>
                      </p>
                      <span className={`tabular-nums text-sm font-semibold ${late ? "text-red-400" : warn ? "text-amber-300" : "text-white/60"}`}>
                        {age.label}
                      </span>
                    </div>
                    <ul className="flex flex-col gap-1">
                      {order.items.map((item, i) => (
                        <li key={i} className="leading-snug">
                          <span className="font-semibold">{item.quantity} ×</span> {item.name.km || item.name.en}
                          <span className="text-white/50"> · {item.name.en}</span>
                          {item.options.length > 0 && (
                            <div className="text-sm text-white/70">{item.options.map((o) => o.en).join(", ")}</div>
                          )}
                          {item.note && <div className="text-sm font-semibold text-amber-300">📝 {item.note}</div>}
                        </li>
                      ))}
                    </ul>
                    {order.note && <p className="rounded bg-amber-400/15 px-2 py-1 text-sm text-amber-200">📝 {order.note}</p>}
                    {next ? (
                      <button
                        type="button"
                        disabled={moving === order.id}
                        onClick={() => move(order, next.status)}
                        className="mt-1 rounded-lg bg-emerald-500 py-3 text-lg font-bold text-black disabled:opacity-50"
                      >
                        {next.label}
                      </button>
                    ) : (
                      <p className="text-center text-sm text-emerald-300">Waiting for waiter</p>
                    )}
                  </article>
                );
              })}
            </section>
          );
        })}
      </div>
    </main>
  );
}
