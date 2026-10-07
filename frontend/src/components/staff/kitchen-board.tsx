"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { BellRing, Check, ChefHat, CircleAlert, Clock, CupSoda, Flame, Layers, Play, Printer, StickyNote, Timer, Volume2, Wifi, WifiOff, type LucideIcon } from "lucide-react";
import { chime, since, staffApi, ticketUrl, type BoardOrder } from "@/lib/staff";
import { useBoard } from "./use-board";
import { BackLink, SignedOutScreen } from "./chrome";

const COLUMNS: { key: string; title: string; icon: LucideIcon; dot: string; statuses: BoardOrder["status"][] }[] = [
  { key: "new", title: "New", icon: BellRing, dot: "bg-sky-400", statuses: ["placed", "accepted"] },
  { key: "cooking", title: "Preparing", icon: Flame, dot: "bg-amber-400", statuses: ["preparing"] },
  { key: "ready", title: "Ready", icon: Check, dot: "bg-emerald-400", statuses: ["ready"] },
];

const NEXT: Partial<Record<BoardOrder["status"], { status: string; label: string }>> = {
  placed: { status: "preparing", label: "Start" },
  accepted: { status: "preparing", label: "Start" },
  preparing: { status: "ready", label: "Ready" },
};

const STATIONS: { value: "kitchen" | "bar" | null; label: string; icon: LucideIcon }[] = [
  { value: "kitchen", label: "Kitchen", icon: ChefHat },
  { value: "bar", label: "Bar", icon: CupSoda },
  { value: null, label: "All", icon: Layers },
];

/**
 * Prints queued tickets one at a time in a hidden frame. Each frame opens the print dialog
 * itself; with Chrome started as --kiosk-printing it goes straight to the printer.
 */
function printTickets(queue: number[], busy: { current: boolean }, station: string | null) {
  if (busy.current) return;
  const id = queue.shift();
  if (id === undefined) return;
  busy.current = true;
  const frame = document.createElement("iframe");
  frame.style.cssText = "position:fixed;right:0;bottom:0;width:0;height:0;border:0;";
  frame.src = ticketUrl(id, station, true);
  document.body.appendChild(frame);
  window.setTimeout(() => {
    frame.remove();
    busy.current = false;
    printTickets(queue, busy, station);
  }, 8000);
}

export default function KitchenBoard({ branchId, station }: { branchId: number; station: string | null }) {
  const { board, error, signedOut, now, refresh, live } = useBoard(branchId, station);
  const [soundOn, setSoundOn] = useState(false);
  const [moving, setMoving] = useState<number | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const audio = useRef<AudioContext | null>(null);
  const seen = useRef<Set<number> | null>(null);
  const printQueue = useRef<number[]>([]);
  const printing = useRef(false);

  // Chime (and auto-print, if the branch wants it) when an order appears that this screen hasn't seen before.
  useEffect(() => {
    if (!board) return;
    const ids = new Set(board.orders.filter((o) => o.status === "placed").map((o) => o.id));
    const fresh = seen.current ? [...ids].filter((id) => !seen.current!.has(id)) : [];
    if (fresh.length > 0 && soundOn) chime(audio.current);
    if (fresh.length > 0 && board.settings?.auto_print_kitchen) {
      printQueue.current.push(...fresh);
      printTickets(printQueue.current, printing, station);
    }
    seen.current = new Set([...(seen.current ?? []), ...ids]);
  }, [board, soundOn, station]);

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

  if (signedOut) return <SignedOutScreen dark />;

  const title = station === "bar" ? "Bar" : station === "kitchen" ? "Kitchen" : "All stations";
  const TitleIcon = station === "bar" ? CupSoda : station === "kitchen" ? ChefHat : Layers;

  return (
    <main className="flex min-h-dvh flex-col bg-[#0d1110] text-[#eef3f0]">
      <header className="sticky top-0 z-10 border-b border-white/8 bg-[#0d1110]/85 backdrop-blur-md">
        <div className="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-6 lg:px-8">
          <BackLink dark />
          <span className="grid size-10 place-items-center rounded-2xl bg-emerald-400/15 text-emerald-300">
            <TitleIcon className="size-5" aria-hidden />
          </span>
          <div className="min-w-0">
            <h1 className="text-xl leading-tight font-bold tracking-tight">{title}</h1>
            <p className="flex items-center gap-1.5 text-xs text-white/50">
              {live ? <Wifi className="size-3.5 text-emerald-400" aria-hidden /> : <WifiOff className="size-3.5" aria-hidden />}
              {live ? "Live" : "Checking every few seconds"}
            </p>
          </div>

          <div className="ml-auto flex flex-wrap items-center gap-2">
            <nav className="flex gap-1 rounded-2xl bg-white/6 p-1 text-sm font-semibold" aria-label="Station">
              {STATIONS.map((s) => {
                const Icon = s.icon;
                const active = station === s.value;
                return (
                  <Link
                    key={s.value ?? "all"}
                    href={`/staff/kitchen?branch=${branchId}${s.value ? `&station=${s.value}` : ""}`}
                    aria-current={active ? "page" : undefined}
                    className={`flex items-center gap-1.5 rounded-xl px-3 py-1.5 transition ${active ? "bg-white text-black shadow-soft" : "text-white/70 hover:bg-white/10 hover:text-white"}`}
                  >
                    <Icon className="size-4" aria-hidden />
                    {s.label}
                  </Link>
                );
              })}
            </nav>
            {soundOn ? (
              <span className="flex items-center gap-1.5 rounded-2xl bg-emerald-400/10 px-3 py-2 text-sm font-medium text-emerald-300">
                <Volume2 className="size-4" aria-hidden />
                Sound on
              </span>
            ) : (
              <button
                type="button"
                onClick={enableSound}
                className="anim-ring flex items-center gap-2 rounded-2xl bg-amber-400 px-4 py-2 text-sm font-bold text-black transition hover:bg-amber-300 active:scale-95"
              >
                <BellRing className="size-4" aria-hidden />
                Turn on sound
              </button>
            )}
          </div>
        </div>
      </header>

      {(error || actionError) && (
        <p role="alert" className="anim-fade-in mx-4 mt-4 flex items-center gap-2 rounded-2xl bg-red-500/15 px-4 py-3 text-sm font-medium text-red-200 sm:mx-6 lg:mx-8">
          <CircleAlert className="size-4 shrink-0" aria-hidden />
          {actionError ?? error}
        </p>
      )}

      <div className="grid flex-1 gap-4 p-4 sm:p-6 md:grid-cols-3 lg:gap-6 lg:p-8">
        {COLUMNS.map((column) => {
          const orders = (board?.orders ?? []).filter((o) => column.statuses.includes(o.status));
          const ColumnIcon = column.icon;
          return (
            <section key={column.key} className="flex min-w-0 flex-col gap-3 rounded-3xl bg-white/[0.035] p-3 ring-1 ring-white/6 sm:p-4">
              <h2 className="flex items-center gap-2 px-1 pb-1 text-sm font-bold tracking-wider text-white/75 uppercase">
                <span className={`size-2 rounded-full ${column.dot}`} aria-hidden />
                <ColumnIcon className="size-4" aria-hidden />
                {column.title}
                <span className="ml-auto rounded-full bg-white/10 px-2.5 py-0.5 text-sm tabular-nums">{orders.length}</span>
              </h2>

              {!board && [0, 1].map((i) => <div key={i} className="h-40 animate-pulse rounded-2xl bg-white/5" />)}

              {board && orders.length === 0 && (
                <div className="anim-fade-in flex flex-col items-center gap-2 rounded-2xl border border-dashed border-white/10 py-10 text-center text-sm text-white/35">
                  <ColumnIcon className="size-6" aria-hidden />
                  Nothing here
                </div>
              )}

              {orders.map((order, index) => {
                const age = since(order.placed_at, now);
                const late = order.status !== "ready" && age.minutes >= 15;
                const warn = order.status !== "ready" && age.minutes >= 10;
                const next = NEXT[order.status];
                const timer = late
                  ? "bg-red-500/20 text-red-300"
                  : warn
                    ? "bg-amber-400/15 text-amber-300"
                    : order.status === "ready"
                      ? "bg-emerald-400/15 text-emerald-300"
                      : "bg-white/8 text-white/70";
                return (
                  <article
                    key={order.id}
                    style={{ "--i": Math.min(index, 8) } as React.CSSProperties}
                    className={`anim-fade-up flex flex-col gap-3 rounded-2xl border-l-4 bg-[#171e1c] p-4 shadow-[0_8px_24px_-12px_rgb(0_0_0/0.8)] ring-1 ring-white/6 ${
                      late ? "border-red-500" : warn ? "border-amber-400" : order.status === "ready" ? "border-emerald-400" : "border-sky-400/60"
                    }`}
                  >
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <p className="text-2xl leading-tight font-extrabold tracking-tight">
                          {order.table ?? "—"} <span className="text-base font-semibold text-white/45">#{order.number}</span>
                        </p>
                        {(order.area || order.source === "waiter") && (
                          <p className="text-xs text-white/45">{[order.area, order.source === "waiter" ? "by waiter" : null].filter(Boolean).join(" · ")}</p>
                        )}
                      </div>
                      <span className="flex shrink-0 items-center gap-2">
                        <span className={`flex items-center gap-1 rounded-full px-2.5 py-1 text-sm font-bold tabular-nums ${timer} ${late ? "animate-pulse" : ""}`}>
                          {late ? <Timer className="size-4" aria-hidden /> : <Clock className="size-4" aria-hidden />}
                          {age.label}
                        </span>
                        <button
                          type="button"
                          aria-label={`Print ticket #${order.number}`}
                          title="Print ticket"
                          onClick={() => window.open(ticketUrl(order.id, station), "_blank", "width=420,height=600")}
                          className="grid size-9 place-items-center rounded-xl bg-white/8 text-white/70 transition hover:bg-white/15 hover:text-white active:scale-95"
                        >
                          <Printer className="size-4" aria-hidden />
                        </button>
                      </span>
                    </div>

                    <ul className="flex flex-col divide-y divide-white/6">
                      {order.items.map((item, i) => (
                        <li key={i} className="flex gap-3 py-2 leading-snug first:pt-0 last:pb-0">
                          <span className="grid h-8 min-w-8 shrink-0 place-items-center rounded-lg bg-white/10 px-1.5 text-lg font-extrabold tabular-nums">{item.quantity}</span>
                          <div className="min-w-0">
                            <p className="text-lg font-semibold">{item.name.km || item.name.en}</p>
                            {item.name.km && item.name.en && <p className="text-sm text-white/50">{item.name.en}</p>}
                            {item.options.length > 0 && <p className="text-sm text-white/75">{item.options.map((o) => o.en).join(", ")}</p>}
                            {item.note && (
                              <p className="mt-1 flex items-start gap-1.5 text-sm font-semibold text-amber-300">
                                <StickyNote className="mt-0.5 size-4 shrink-0" aria-hidden />
                                {item.note}
                              </p>
                            )}
                          </div>
                        </li>
                      ))}
                    </ul>

                    {order.note && (
                      <p className="flex items-start gap-2 rounded-xl bg-amber-400/12 px-3 py-2 text-sm font-medium text-amber-200">
                        <StickyNote className="mt-0.5 size-4 shrink-0" aria-hidden />
                        {order.note}
                      </p>
                    )}

                    {next ? (
                      <button
                        type="button"
                        disabled={moving === order.id}
                        onClick={() => move(order, next.status)}
                        className={`flex h-14 items-center justify-center gap-2 rounded-2xl text-lg font-bold text-black transition active:scale-[0.98] disabled:opacity-50 ${
                          next.status === "ready" ? "bg-emerald-400 hover:bg-emerald-300" : "bg-sky-300 hover:bg-sky-200"
                        }`}
                      >
                        {next.status === "ready" ? <Check className="size-5" strokeWidth={3} aria-hidden /> : <Play className="size-5" aria-hidden />}
                        {moving === order.id ? "Saving..." : next.label}
                      </button>
                    ) : (
                      <p className="flex items-center justify-center gap-2 rounded-2xl bg-emerald-400/10 py-3 text-sm font-semibold text-emerald-300">
                        <Clock className="size-4" aria-hidden />
                        Waiting for waiter
                      </p>
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
