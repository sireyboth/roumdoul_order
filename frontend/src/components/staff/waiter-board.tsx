"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import {
  Ban,
  BellRing,
  Check,
  CheckCheck,
  Clock,
  ConciergeBell,
  Flame,
  HandPlatter,
  LayoutGrid,
  PackageX,
  Receipt,
  Volume2,
  type LucideIcon,
} from "lucide-react";
import { chime, since, staffApi, type BoardOrder, type BoardRequest } from "@/lib/staff";
import type { Names } from "@/lib/types";
import { useBoard } from "./use-board";
import { Alert, Badge, Button } from "../ui";
import { BackLink, PAGE, SignedOutScreen } from "./chrome";

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

  if (signedOut) return <SignedOutScreen />;

  const requests = board?.requests ?? [];
  const ready = (board?.orders ?? []).filter((o) => o.status === "ready");
  const cooking = (board?.orders ?? []).filter((o) => ["placed", "accepted", "preparing"].includes(o.status));
  const attention = requests.length + ready.length;

  return (
    <main className="flex min-h-dvh flex-col pb-12">
      <header className="sticky top-0 z-20 border-b border-[var(--line)] bg-[var(--surface)]/85 backdrop-blur-md">
        <div className={`${PAGE} flex flex-wrap items-center gap-3 py-3`}>
          <BackLink />
          <span className="grid size-10 place-items-center rounded-2xl bg-[var(--brand-soft)] text-[var(--brand)]">
            <ConciergeBell className="size-5" aria-hidden />
          </span>
          <h1 className="text-xl font-bold tracking-tight">Waiter</h1>

          <div className="ml-auto flex flex-wrap items-center gap-2">
            <div className="flex gap-1 rounded-2xl bg-[var(--chip)] p-1 text-sm font-semibold" role="tablist">
              <TabButton active={tab === "floor"} onClick={() => setTab("floor")} icon={LayoutGrid}>
                Tables
                {attention > 0 && <span className="anim-pop ml-0.5 rounded-full bg-[var(--danger)] px-1.5 text-xs text-white tabular-nums">{attention}</span>}
              </TabButton>
              <TabButton active={tab === "menu"} onClick={() => setTab("menu")} icon={PackageX}>
                Sold out
              </TabButton>
            </div>
          </div>
        </div>
        {!soundOn && (
          <div className={`${PAGE} pb-3`}>
            <button
              type="button"
              onClick={() => {
                audio.current ??= new AudioContext();
                void audio.current.resume();
                chime(audio.current);
                setSoundOn(true);
              }}
              className="flex w-full items-center justify-center gap-2 rounded-2xl bg-[var(--accent)] px-4 py-2.5 text-sm font-bold text-[#2b1d06] transition hover:brightness-105 active:scale-[0.99]"
            >
              <BellRing className="anim-wiggle size-4" aria-hidden />
              Turn on sound for calls
            </button>
          </div>
        )}
      </header>

      <div className={`${PAGE} mt-6 flex flex-col gap-6`}>
        {(error || actionError) && <Alert>{actionError ?? error}</Alert>}
        {soundOn && (
          <p className="anim-fade-in -mb-2 flex items-center gap-1.5 text-xs text-[var(--muted)]">
            <Volume2 className="size-3.5" aria-hidden /> Sound is on for calls and ready orders.
          </p>
        )}

        {tab === "floor" ? (
          <div className="grid gap-6 lg:grid-cols-3">
            <Section title="Calls" icon={BellRing} count={requests.length} tone="warn" empty="No one is calling" loading={!board}>
              {requests.map((r: BoardRequest, i) => {
                const bill = r.type === "bill";
                return (
                  <Row key={r.id} tone={bill ? "brand" : "warn"} index={i}>
                    <span className={`grid size-11 shrink-0 place-items-center rounded-2xl ${bill ? "bg-[var(--brand-soft)] text-[var(--brand)]" : "anim-ring bg-[var(--warn-bg)] text-[var(--warn)]"}`}>
                      {bill ? <Receipt className="size-5" aria-hidden /> : <BellRing className="size-5" aria-hidden />}
                    </span>
                    <div className="min-w-0 flex-1">
                      <p className="text-xl leading-tight font-bold">{r.table ?? "—"}</p>
                      <p className="flex items-center gap-1 text-sm text-[var(--muted)]">
                        {bill ? "Wants the bill" : "Calling waiter"} · <Clock className="size-3.5" aria-hidden /> {since(r.created_at, now).label} ago
                      </p>
                    </div>
                    <Button icon={Check} disabled={busy === `r${r.id}`} onClick={() => act(`r${r.id}`, `requests/${r.id}/done`)}>
                      Done
                    </Button>
                  </Row>
                );
              })}
            </Section>

            <Section title="Ready to serve" icon={HandPlatter} count={ready.length} tone="brand" empty="Nothing waiting" loading={!board}>
              {ready.map((o: BoardOrder, i) => (
                <Row key={o.id} tone="ok" index={i}>
                  <div className="min-w-0 flex-1">
                    <p className="text-xl leading-tight font-bold">
                      {o.table ?? "—"} <span className="text-sm font-medium text-[var(--muted)]">#{o.number}</span>
                    </p>
                    <p className="text-sm text-[var(--muted)]">{o.items.map((it) => `${it.quantity}× ${it.name.en}`).join(", ")}</p>
                  </div>
                  <Button icon={CheckCheck} disabled={busy === `o${o.id}`} onClick={() => act(`o${o.id}`, `orders/${o.id}/status`, { status: "served" })}>
                    Served
                  </Button>
                </Row>
              ))}
            </Section>

            <Section title="Being prepared" icon={Flame} count={cooking.length} tone="neutral" empty="Kitchen is clear" loading={!board}>
              {cooking.map((o: BoardOrder, i) => (
                <Row key={o.id} index={i}>
                  <div className="min-w-0 flex-1">
                    <p className="flex flex-wrap items-center gap-2 font-semibold">
                      {o.table ?? "—"} <span className="text-sm font-medium text-[var(--muted)]">#{o.number}</span>
                      <Badge tone={o.status === "preparing" ? "warn" : "neutral"} className="capitalize">{o.status}</Badge>
                    </p>
                    <p className="text-sm text-[var(--muted)]">{o.items.map((it) => `${it.quantity}× ${it.name.en}`).join(", ")}</p>
                  </div>
                  <span className="flex items-center gap-1 text-sm text-[var(--muted)] tabular-nums">
                    <Clock className="size-3.5" aria-hidden />
                    {since(o.placed_at, now).label}
                  </span>
                </Row>
              ))}
            </Section>
          </div>
        ) : (
          <Section
            title="Tap to mark sold out for today"
            icon={PackageX}
            count={menu?.filter((m) => m.sold_out).length ?? 0}
            tone="danger"
            empty="The menu is empty"
            loading={!menu}
            grid
          >
            {(menu ?? []).map((m, i) => (
              <Row key={m.menu_item_id} tone={m.sold_out ? "danger" : undefined} index={i}>
                <div className="min-w-0 flex-1">
                  <p className={`font-semibold ${m.sold_out ? "text-[var(--muted)] line-through" : ""}`}>{m.name.km || m.name.en}</p>
                  <p className="truncate text-sm text-[var(--muted)]">
                    {m.name.en} · {m.category}
                  </p>
                </div>
                <Button
                  size="sm"
                  tone={m.sold_out ? "danger" : "neutral"}
                  icon={m.sold_out ? Ban : Check}
                  disabled={busy === `m${m.menu_item_id}`}
                  onClick={() => act(`m${m.menu_item_id}`, `branches/${branchId}/menu/${m.menu_item_id}/sold-out`, { sold_out: !m.sold_out }, loadMenu)}
                >
                  {m.sold_out ? "Sold out" : "Available"}
                </Button>
              </Row>
            ))}
          </Section>
        )}
      </div>
    </main>
  );
}

function TabButton({ active, onClick, icon: Icon, children }: { active: boolean; onClick: () => void; icon: LucideIcon; children: React.ReactNode }) {
  return (
    <button
      type="button"
      role="tab"
      aria-selected={active}
      onClick={onClick}
      className={`flex items-center gap-1.5 rounded-xl px-3 py-1.5 transition ${active ? "bg-[var(--surface)] text-[var(--fg)] shadow-soft" : "text-[var(--muted)] hover:text-[var(--fg)]"}`}
    >
      <Icon className="size-4" aria-hidden />
      {children}
    </button>
  );
}

const SECTION_TONES = {
  warn: "bg-[var(--warn-bg)] text-[var(--warn)]",
  brand: "bg-[var(--brand-soft)] text-[var(--brand)]",
  neutral: "bg-[var(--chip)] text-[var(--muted)]",
  danger: "bg-[var(--danger-bg)] text-[var(--danger)]",
} as const;

function Section({
  title,
  icon: Icon,
  count,
  tone,
  empty,
  loading,
  grid,
  children,
}: {
  title: string;
  icon: LucideIcon;
  count: number;
  tone: keyof typeof SECTION_TONES;
  empty: string;
  loading?: boolean;
  grid?: boolean;
  children: React.ReactNode;
}) {
  const hasChildren = Array.isArray(children) ? children.length > 0 : Boolean(children);
  return (
    <section className="flex min-w-0 flex-col gap-3">
      <h2 className="flex items-center gap-2.5 text-sm font-bold tracking-wide text-[var(--muted)] uppercase">
        <span className={`grid size-8 place-items-center rounded-xl ${SECTION_TONES[tone]}`}>
          <Icon className="size-4" aria-hidden />
        </span>
        {title}
        <span className="rounded-full bg-[var(--chip)] px-2.5 py-0.5 text-[var(--fg)] tabular-nums">{count}</span>
      </h2>
      {loading ? (
        <div className={grid ? "grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4" : "flex flex-col gap-3"}>
          {[0, 1, 2].map((i) => (
            <div key={i} className="skeleton h-20 rounded-2xl" />
          ))}
        </div>
      ) : hasChildren ? (
        <div className={grid ? "grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4" : "flex flex-col gap-3"}>{children}</div>
      ) : (
        <div className="anim-fade-in flex items-center gap-3 rounded-2xl border border-dashed border-[var(--line)] px-4 py-6 text-sm text-[var(--muted)]">
          <Icon className="size-5 opacity-60" aria-hidden />
          {empty}
        </div>
      )}
    </section>
  );
}

function Row({ children, tone, index = 0 }: { children: React.ReactNode; tone?: "warn" | "ok" | "brand" | "danger"; index?: number }) {
  const border = { warn: "border-l-[var(--accent)]", ok: "border-l-[var(--brand)]", brand: "border-l-[var(--brand)]", danger: "border-l-[var(--danger)]" }[tone ?? "warn"];
  return (
    <div
      style={{ "--i": Math.min(index, 10) } as React.CSSProperties}
      className={`anim-fade-up flex items-center gap-3 rounded-2xl border border-l-4 border-[var(--line)] bg-[var(--surface)] p-3.5 shadow-soft transition hover:shadow-card ${tone ? border : "border-l-[var(--line)]"}`}
    >
      {children}
    </div>
  );
}
