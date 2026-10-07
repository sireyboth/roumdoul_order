"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import {
  Armchair,
  Ban,
  Banknote,
  ChefHat,
  Clock,
  CreditCard,
  LayoutGrid,
  Map as MapIcon,
  Percent,
  Play,
  Printer,
  QrCode,
  Receipt,
  StickyNote,
  Undo2,
  Users,
  Wallet,
  X,
  type LucideIcon,
} from "lucide-react";
import { SignedOut, receiptUrl, since, staffApi, type BillDetail, type CashierTable, type CashierTables, type ShiftState } from "@/lib/staff";
import { newKey } from "@/lib/table-api";
import { formatMoney } from "@/lib/money";
import Sheet from "../sheet";
import PayPanel from "./pay-panel";
import PinForm from "./pin-form";
import ShiftSheet from "./shift-sheet";
import FloorView from "./floor-view";
import { useLive } from "@/lib/live";
import { Alert, Badge, Button, EmptyState, SuccessMark } from "../ui";
import { BackLink, PAGE, SignedOutScreen } from "./chrome";

type View = "tables" | "floor";
const VIEW_KEY = "ro-cashier-view";

/** Tables with what they owe; tap one to open its bill and take payment. */
export default function CashierBoard({ branchId }: { branchId: number }) {
  const [data, setData] = useState<CashierTables | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [signedOut, setSignedOut] = useState(false);
  const [now, setNow] = useState(() => Date.now());
  const [selected, setSelected] = useState<CashierTable | null>(null);
  const [shift, setShift] = useState<ShiftState | null>(null);
  const [shiftOpen, setShiftOpen] = useState(false);
  const [view, setView] = useState<View>("tables");
  const busy = useRef(false);

  useEffect(() => {
    let saved: string | null = null;
    try {
      saved = window.localStorage.getItem(VIEW_KEY);
    } catch {
      /* storage blocked: keep the default */
    }
    // eslint-disable-next-line react-hooks/set-state-in-effect -- storage is only readable after mount
    if (saved === "floor" || saved === "tables") setView(saved);
  }, []);

  function changeView(next: View) {
    setView(next);
    try {
      window.localStorage.setItem(VIEW_KEY, next);
    } catch {
      /* storage blocked: the choice lasts for this visit only */
    }
  }

  const refreshShift = useCallback(async () => {
    try {
      setShift(await staffApi<ShiftState>(`branches/${branchId}/shift`));
    } catch (e) {
      if (e instanceof SignedOut) setSignedOut(true);
    }
  }, [branchId]);

  const refresh = useCallback(async () => {
    if (busy.current) return;
    busy.current = true;
    try {
      setData(await staffApi<CashierTables>(`branches/${branchId}/tables`));
      setError(null);
    } catch (e) {
      if (e instanceof SignedOut) setSignedOut(true);
      else setError(e instanceof Error ? e.message : "Can't load tables.");
    } finally {
      busy.current = false;
    }
  }, [branchId]);

  const live = useLive(`branch.${branchId}`, true, () => {
    void refresh();
    void refreshShift();
  });

  useEffect(() => {
    const first = window.setTimeout(() => {
      void refresh();
      void refreshShift();
    }, 0);
    const poll = window.setInterval(() => void refresh(), live ? 30_000 : 4000);
    const shiftPoll = window.setInterval(() => void refreshShift(), 20_000);
    const tick = window.setInterval(() => setNow(Date.now()), 30_000);
    return () => {
      window.clearTimeout(first);
      window.clearInterval(poll);
      window.clearInterval(shiftPoll);
      window.clearInterval(tick);
    };
  }, [refresh, refreshShift, live]);

  if (signedOut) return <SignedOutScreen />;

  const tables = data?.tables ?? [];
  const busyTables = tables.filter((t) => t.session);
  const billRequested = busyTables.filter((t) => t.session?.status === "bill_requested").length;
  const openTotal = busyTables.reduce((n, t) => n + (t.session ? t.session.total - t.session.paid_total : 0), 0);
  const money = (minor: number) => formatMoney(minor, data?.currency ?? "USD");
  const openedAt = shift?.shift ? new Date(shift.shift.opened_at).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }) : null;

  return (
    <main className="flex min-h-dvh flex-col pb-12">
      <header className="sticky top-0 z-20 border-b border-[var(--line)] bg-[var(--surface)]/85 backdrop-blur-md">
        <div className={`${PAGE} flex flex-wrap items-center gap-3 py-3`}>
          <BackLink />
          <span className="grid size-10 place-items-center rounded-2xl bg-[var(--brand)] text-white shadow-soft dark:text-[#06140f]">
            <Banknote className="size-5" aria-hidden />
          </span>
          <h1 className="text-xl font-bold tracking-tight">Cashier</h1>
          <div className="hidden items-center gap-1.5 sm:flex">
            <Badge tone="brand" icon={Users}>{busyTables.length} busy</Badge>
            {billRequested > 0 && (
              <Badge tone="accent" icon={Receipt} className="anim-pop">
                {billRequested} want the bill
              </Badge>
            )}
          </div>

          <div className="ml-auto flex flex-wrap items-center gap-2">
            <div className="flex gap-1 rounded-2xl bg-[var(--chip)] p-1 text-sm font-semibold" role="tablist" aria-label="View">
              <ViewButton active={view === "tables"} onClick={() => changeView("tables")} icon={LayoutGrid} label="Tables" />
              <ViewButton active={view === "floor"} onClick={() => changeView("floor")} icon={MapIcon} label="Floor plan" />
            </div>
            {shift && (
              <Button
                tone={shift.shift ? "neutral" : "danger"}
                icon={Wallet}
                onClick={() => setShiftOpen(true)}
                className={`h-10 rounded-2xl text-sm ${shift.shift ? "" : "anim-ring"}`}
              >
                {shift.shift ? (
                  <>
                    <span className="hidden md:inline">Shift open since</span>
                    <span className="md:hidden">Shift</span> {openedAt}
                  </>
                ) : (
                  "Start shift"
                )}
              </Button>
            )}
          </div>
        </div>
      </header>

      <div className={`${PAGE} mt-6 flex flex-col gap-6`}>
        {shift && !shift.shift && (
          <Alert>
            No shift is open. Count the cash drawer and tap <strong>Start shift</strong> before taking payments.
          </Alert>
        )}
        {error && <Alert>{error}</Alert>}

        <ul className="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-4">
          <Stat index={0} icon={Users} tone="brand" label="Busy tables" value={data ? `${busyTables.length} / ${tables.length}` : null} />
          <Stat index={1} icon={Receipt} tone="accent" label="Want the bill" value={data ? String(billRequested) : null} pulse={billRequested > 0} />
          <Stat index={2} icon={Wallet} tone="dark" label="Open total" value={data ? money(openTotal) : null} />
          <Stat index={3} icon={Armchair} tone="neutral" label="Free tables" value={data ? String(tables.length - busyTables.length) : null} />
        </ul>

        {!data ? (
          <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:gap-4 xl:grid-cols-5 2xl:grid-cols-6">
            {Array.from({ length: 12 }, (_, i) => (
              <li key={i} className="skeleton h-36 rounded-3xl" />
            ))}
          </ul>
        ) : tables.length === 0 ? (
          <EmptyState icon={Armchair} title="No tables yet" text="This branch has no tables yet. Add them in the back office." />
        ) : view === "floor" ? (
          <div className="anim-fade-in">
            <FloorView branchId={branchId} tables={tables} money={money} onSelect={(t) => t.session && setSelected(t)} />
          </div>
        ) : (
          <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:gap-4 xl:grid-cols-5 2xl:grid-cols-6">
            {tables.map((table, i) => (
              <li key={table.id} className="anim-fade-up" style={{ "--i": Math.min(i, 18) } as React.CSSProperties}>
                <TableCard table={table} now={now} money={money} onOpen={() => setSelected(table)} />
              </li>
            ))}
          </ul>
        )}
      </div>

      {selected?.session && (
        <BillSheet
          key={selected.session.id}
          sessionId={selected.session.id}
          tableName={selected.name}
          shiftOpen={Boolean(shift?.shift)}
          onStartShift={() => setShiftOpen(true)}
          onChanged={async () => {
            await refresh();
            await refreshShift();
          }}
          onClose={() => {
            setSelected(null);
            void refresh();
          }}
        />
      )}
      {shiftOpen && shift && (
        <ShiftSheet
          branchId={branchId}
          state={shift}
          currency={data?.currency ?? "USD"}
          onChanged={refreshShift}
          onClose={() => setShiftOpen(false)}
        />
      )}
    </main>
  );
}

function ViewButton({ active, onClick, icon: Icon, label }: { active: boolean; onClick: () => void; icon: LucideIcon; label: string }) {
  return (
    <button
      type="button"
      role="tab"
      aria-selected={active}
      onClick={onClick}
      className={`flex items-center gap-1.5 rounded-xl px-3 py-1.5 transition ${active ? "bg-[var(--surface)] text-[var(--fg)] shadow-soft" : "text-[var(--muted)] hover:text-[var(--fg)]"}`}
    >
      <Icon className="size-4" aria-hidden />
      <span className="hidden sm:inline">{label}</span>
    </button>
  );
}

const STAT_TONES = {
  brand: "bg-[var(--brand-soft)] text-[var(--brand)]",
  accent: "bg-[var(--accent-soft)] text-[var(--warn)]",
  dark: "bg-[var(--fg)] text-[var(--surface)]",
  neutral: "bg-[var(--chip)] text-[var(--muted)]",
} as const;

function Stat({ icon: Icon, tone, label, value, pulse, index }: { icon: LucideIcon; tone: keyof typeof STAT_TONES; label: string; value: string | null; pulse?: boolean; index: number }) {
  return (
    <li className="anim-fade-up flex items-center gap-3 rounded-3xl bg-[var(--surface)] p-4 shadow-soft lg:p-5" style={{ "--i": index } as React.CSSProperties}>
      <span className={`grid size-11 shrink-0 place-items-center rounded-2xl lg:size-12 ${STAT_TONES[tone]} ${pulse ? "anim-ring" : ""}`}>
        <Icon className="size-5" aria-hidden />
      </span>
      <span className="min-w-0">
        <span className="block truncate text-xs font-semibold tracking-wide text-[var(--muted)] uppercase">{label}</span>
        {value === null ? (
          <span className="skeleton mt-1 block h-7 w-20 rounded-lg" />
        ) : (
          <span className="block truncate text-xl font-bold tabular-nums lg:text-2xl">{value}</span>
        )}
      </span>
    </li>
  );
}

function TableCard({ table, now, money, onOpen }: { table: CashierTable; now: number; money: (minor: number) => string; onOpen: () => void }) {
  const s = table.session;
  const wantsBill = s?.status === "bill_requested";
  return (
    <button
      type="button"
      disabled={!s}
      onClick={onOpen}
      className={`group relative flex h-full min-h-36 w-full flex-col items-start gap-2 rounded-3xl border-2 p-4 text-left transition duration-200 disabled:cursor-default ${
        wantsBill
          ? "anim-ring border-[var(--accent)] bg-[var(--accent-soft)] shadow-card hover:-translate-y-1"
          : s
            ? "border-[var(--brand)]/40 bg-[var(--surface)] shadow-soft hover:-translate-y-1 hover:border-[var(--brand)] hover:shadow-card"
            : "border-dashed border-[var(--line)] bg-transparent"
      }`}
    >
      <span className="flex w-full items-start justify-between gap-2">
        <span className={`text-2xl leading-tight font-bold tracking-tight ${s ? "" : "text-[var(--muted)]"}`}>{table.name}</span>
        {wantsBill ? (
          <Badge tone="accent" icon={Receipt}>Bill</Badge>
        ) : s ? (
          <Badge tone="brand">Busy</Badge>
        ) : (
          <Badge tone="neutral">Free</Badge>
        )}
      </span>
      {table.area && <span className="-mt-1.5 truncate text-xs text-[var(--muted)]">{table.area}</span>}
      {s ? (
        <>
          <span className="mt-auto text-xl font-bold tabular-nums">{money(s.total - s.paid_total)}</span>
          <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-[var(--muted)]">
            <span className="flex items-center gap-1">
              <Receipt className="size-3.5" aria-hidden />
              {s.orders_count} order{s.orders_count === 1 ? "" : "s"}
            </span>
            <span className="flex items-center gap-1">
              <Clock className="size-3.5" aria-hidden />
              {since(s.opened_at, now).label}
            </span>
            {s.active_orders_count > 0 && (
              <span className="flex items-center gap-1 font-semibold text-[var(--warn)]">
                <ChefHat className="size-3.5" aria-hidden />
                {s.active_orders_count} cooking
              </span>
            )}
          </span>
          {s.bill && !wantsBill && <span className="text-xs font-semibold text-[var(--brand)]">Bill #{s.bill.number}</span>}
        </>
      ) : (
        <span className="mt-auto flex items-center gap-1.5 text-sm text-[var(--muted)]">
          <Armchair className="size-4" aria-hidden />
          Free
        </span>
      )}
    </button>
  );
}

type Panel = "pay" | "discount" | "void" | { refund: number } | { removeDiscount: number };

const METHOD_ICONS: Record<string, LucideIcon> = { cash: Banknote, khqr: QrCode, card: CreditCard };

function BillSheet({
  sessionId,
  tableName,
  shiftOpen,
  onStartShift,
  onChanged,
  onClose,
}: {
  sessionId: number;
  tableName: string;
  shiftOpen: boolean;
  onStartShift: () => void;
  onChanged: () => Promise<void>;
  onClose: () => void;
}) {
  const [bill, setBill] = useState<BillDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [panel, setPanel] = useState<Panel>("pay");
  const [lastChange, setLastChange] = useState<{ amount: number; currency: "USD" | "KHR" } | null>(null);

  // Opening the bill is safe to repeat: the server returns the same bill with fresh totals.
  const load = useCallback(async () => {
    try {
      setBill(await staffApi<BillDetail>(`sessions/${sessionId}/bill`, { method: "POST" }));
      setError(null);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Can't open the bill.");
    }
  }, [sessionId]);

  const reload = useCallback(async () => {
    if (!bill) return;
    try {
      setBill(await staffApi<BillDetail>(`bills/${bill.id}`));
    } catch {
      /* keep what is on screen; the next refresh tries again */
    }
  }, [bill]);

  useEffect(() => {
    const first = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(first);
  }, [load]);

  // New orders can still arrive while the bill is open.
  useEffect(() => {
    if (!bill || bill.status !== "open") return;
    const id = window.setInterval(() => void reload(), 5000);
    return () => window.clearInterval(id);
  }, [bill, reload]);

  async function send(path: string, body: unknown): Promise<void> {
    const next = await staffApi<BillDetail | { bill: BillDetail }>(path, { method: "POST", body });
    setBill("bill" in next ? next.bill : next);
    setPanel("pay");
    await onChanged();
  }

  const money = (minor: number) => formatMoney(minor, bill?.currency ?? "USD");
  const riel = (khr: number) => formatMoney(khr, "KHR");

  return (
    <Sheet onClose={onClose} label={`Bill for ${tableName}`} size="lg">
      <div className="flex items-center gap-3 sm:pr-10">
        <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-[var(--brand-soft)] text-[var(--brand)]">
          <Receipt className="size-6" aria-hidden />
        </span>
        <div className="min-w-0 flex-1">
          <h2 className="text-xl font-bold tracking-tight">Table {tableName}</h2>
          {bill ? (
            <p className="flex flex-wrap items-center gap-2 text-sm text-[var(--muted)]">
              Bill #{bill.number}
              {bill.area ? ` · ${bill.area}` : ""}
              {bill.status === "paid" && <Badge tone="solid">Paid</Badge>}
              {bill.status === "void" && <Badge tone="danger">Void</Badge>}
            </p>
          ) : (
            !error && <span className="skeleton mt-1 block h-4 w-28 rounded" />
          )}
        </div>
        <button type="button" onClick={onClose} aria-label="Close" className="grid size-9 place-items-center rounded-full bg-[var(--chip)] text-[var(--muted)] sm:hidden">
          <X className="size-4" aria-hidden />
        </button>
      </div>

      {error && <Alert>{error}</Alert>}
      {!bill && !error && (
        <div className="grid gap-4 md:grid-cols-2">
          <div className="flex flex-col gap-2">
            <div className="skeleton h-24 rounded-2xl" />
            <div className="skeleton h-24 rounded-2xl" />
          </div>
          <div className="flex flex-col gap-2">
            <div className="skeleton h-32 rounded-2xl" />
            <div className="skeleton h-14 rounded-2xl" />
          </div>
        </div>
      )}

      {bill && (
        <div className="grid items-start gap-5 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
          {/* Left: what was ordered and what was paid. */}
          <div className="flex min-w-0 flex-col gap-4">
            <section className="flex flex-col gap-2">
              <h3 className="text-xs font-bold tracking-wide text-[var(--muted)] uppercase">Orders</h3>
              {bill.orders.map((order, i) => (
                <div
                  key={order.id}
                  style={{ "--i": i } as React.CSSProperties}
                  className={`anim-fade-up rounded-2xl border border-[var(--line)] bg-[var(--surface-2)] p-3.5 ${order.counts ? "" : "opacity-55"}`}
                >
                  <p className="flex items-center justify-between gap-2 text-sm font-semibold">
                    <span>
                      Order #{order.number}
                      {order.source === "waiter" ? <span className="font-normal text-[var(--muted)]"> · by waiter</span> : ""}
                    </span>
                    {order.counts ? (
                      <Badge tone="neutral" className="capitalize">{order.status}</Badge>
                    ) : (
                      <span className="text-xs text-[var(--danger)]">Cancelled: {order.cancel_reason ?? ""}</span>
                    )}
                  </p>
                  <ul className="mt-2 flex flex-col gap-1 text-sm">
                    {order.items.map((item, j) => (
                      <li key={j} className={`flex justify-between gap-3 ${order.counts ? "" : "line-through"}`}>
                        <span className="min-w-0">
                          <span className="font-semibold tabular-nums">{item.quantity}×</span> {item.name.en}
                          {item.options.length > 0 && <span className="text-[var(--muted)]"> · {item.options.map((o) => o.en).join(", ")}</span>}
                          {item.note && (
                            <span className="ml-1 inline-flex items-center gap-1 text-[var(--muted)]">
                              <StickyNote className="size-3" aria-hidden />
                              {item.note}
                            </span>
                          )}
                        </span>
                        <span className="shrink-0 tabular-nums">{money(item.line_total ?? 0)}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </section>

            {bill.payments.length > 0 && (
              <section className="flex flex-col gap-2">
                <h3 className="text-xs font-bold tracking-wide text-[var(--muted)] uppercase">Payments</h3>
                {bill.payments.map((p) => {
                  const Icon = METHOD_ICONS[p.method] ?? Wallet;
                  return (
                    <div key={p.id} className={`anim-fade-in flex items-center gap-3 rounded-2xl border border-[var(--line)] px-3.5 py-2.5 text-sm ${p.status === "refunded" ? "opacity-60" : ""}`}>
                      <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-[var(--chip)] text-[var(--muted)]">
                        <Icon className="size-4" aria-hidden />
                      </span>
                      <span className="min-w-0 flex-1">
                        <strong className="uppercase">{p.method}</strong> <span className="font-semibold tabular-nums">{money(p.amount)}</span>
                        {p.tendered_amount !== null && p.tendered_currency && (
                          <span className="text-[var(--muted)]"> · given {formatMoney(p.tendered_amount, p.tendered_currency)}</span>
                        )}
                        {p.change_amount > 0 && p.change_currency && <span className="text-[var(--muted)]"> · change {formatMoney(p.change_amount, p.change_currency)}</span>}
                        {p.reference && <span className="text-[var(--muted)]"> · ref {p.reference}</span>}
                        {p.status === "refunded" && <span className="text-[var(--danger)]"> · refunded: {p.refund_reason}</span>}
                      </span>
                      {p.status === "confirmed" && bill.status !== "void" && (
                        <Button size="sm" tone="ghost" icon={Undo2} onClick={() => setPanel({ refund: p.id })} className="text-[var(--danger)]">
                          Refund
                        </Button>
                      )}
                    </div>
                  );
                })}
              </section>
            )}
          </div>

          {/* Right: totals and taking payment. */}
          <div className="flex min-w-0 flex-col gap-4 md:sticky md:top-0">
            {bill.status === "paid" && (
              <div className="anim-scale-in flex flex-col items-center gap-2 rounded-3xl bg-[var(--brand-soft)] p-5 text-center">
                <SuccessMark className="size-16" />
                <p className="text-2xl font-bold text-[var(--brand-strong)]">Paid</p>
                {lastChange && lastChange.amount > 0 && (
                  <p className="text-lg">
                    Give change: <strong className="tabular-nums">{formatMoney(lastChange.amount, lastChange.currency)}</strong>
                  </p>
                )}
              </div>
            )}
            {bill.status === "void" && (
              <p className="flex items-center gap-2 rounded-2xl bg-[var(--danger-bg)] px-4 py-3 font-medium text-[var(--danger)]">
                <Ban className="size-5 shrink-0" aria-hidden />
                Void: {bill.void_reason}
              </p>
            )}

            <dl className="flex flex-col gap-1.5 rounded-3xl bg-[var(--surface-2)] p-4 tabular-nums ring-1 ring-[var(--line)]">
              <Line label="Subtotal" value={money(bill.subtotal)} />
              {bill.adjustments.map((a) => (
                <Line
                  key={a.id}
                  label={`Discount ${a.type === "percent" ? `${a.value / 100}%` : ""} · ${a.reason}`}
                  value={`−${money(a.amount)}`}
                  action={bill.status === "open" ? { label: "Remove", onClick: () => setPanel({ removeDiscount: a.id }) } : undefined}
                />
              ))}
              {bill.service_charge > 0 && <Line label={`Service charge ${bill.service_charge_bp / 100}%`} value={money(bill.service_charge)} />}
              {bill.vat > 0 && <Line label={`VAT ${bill.vat_bp / 100}%`} value={money(bill.vat)} />}
              <div className="my-1 border-t border-dashed border-[var(--line)]" />
              <Line label="Total" value={money(bill.total)} strong />
              {bill.currency === "USD" && <Line label="Total in riel" value={riel(bill.total_khr)} muted />}
              {bill.prices_include_vat && bill.vat_included > 0 && <Line label={`Includes VAT ${bill.vat_bp / 100}%`} value={money(bill.vat_included)} muted />}
              {bill.paid_total > 0 && <Line label="Paid" value={money(bill.paid_total)} />}
              {bill.status === "open" && (
                <div className="mt-2 flex items-center justify-between gap-3 rounded-2xl bg-[var(--fg)] px-4 py-3 text-[var(--surface)]">
                  <span className="font-semibold">Left to pay</span>
                  <span className="text-right">
                    <span key={bill.remaining} className="anim-fade-in block text-3xl font-bold">{money(bill.remaining)}</span>
                    {bill.currency === "USD" && <span className="text-sm opacity-70">{riel(bill.remaining_khr)}</span>}
                  </span>
                </div>
              )}
            </dl>

            {bill.status !== "void" && (
              <Button
                tone={bill.status === "paid" ? "dark" : "neutral"}
                icon={Printer}
                onClick={() => window.open(receiptUrl(bill.id), "_blank", "width=420,height=700")}
              >
                {bill.status === "paid" ? "Print receipt" : "Print bill for the table"}
              </Button>
            )}

            {bill.status === "open" && (
              <>
                {panel === "pay" && bill.remaining > 0 && !shiftOpen && (
                  <Button
                    tone="danger"
                    size="lg"
                    icon={Play}
                    onClick={() => {
                      onClose();
                      onStartShift();
                    }}
                  >
                    Start a shift to take payment
                  </Button>
                )}

                {panel === "pay" && bill.remaining > 0 && shiftOpen && (
                  <PayPanel
                    bill={bill}
                    onPay={async (body) => {
                      const res = await staffApi<{ payment: { change_amount: number; change_currency: "USD" | "KHR" | null }; bill: BillDetail }>(
                        `bills/${bill.id}/payments`,
                        { method: "POST", body: { ...body, idempotency_key: body.idempotency_key ?? newKey() } },
                      );
                      setLastChange(res.payment.change_currency ? { amount: res.payment.change_amount, currency: res.payment.change_currency } : null);
                      setBill(res.bill);
                      await onChanged();
                    }}
                  />
                )}

                {panel === "discount" && (
                  <PinForm
                    title="Add a discount"
                    submitLabel="Apply discount"
                    withDiscount
                    currency={bill.currency}
                    onCancel={() => setPanel("pay")}
                    onSubmit={(f) => send(`bills/${bill.id}/discounts`, { type: f.type, value: f.value, reason: f.reason, pin: f.pin })}
                  />
                )}

                {panel === "void" && (
                  <PinForm
                    title="Void this bill"
                    note="Use this when nothing will be paid (customer left, opened by mistake). The table becomes free."
                    submitLabel="Void bill"
                    danger
                    onCancel={() => setPanel("pay")}
                    onSubmit={(f) => send(`bills/${bill.id}/void`, { reason: f.reason, pin: f.pin })}
                  />
                )}

                {typeof panel === "object" && "removeDiscount" in panel && (
                  <PinForm
                    title="Remove this discount"
                    submitLabel="Remove discount"
                    noReason
                    onCancel={() => setPanel("pay")}
                    onSubmit={(f) => send(`bills/${bill.id}/discounts/${panel.removeDiscount}/remove`, { pin: f.pin })}
                  />
                )}

                {panel === "pay" && (
                  <div className="grid grid-cols-2 gap-2">
                    <Button tone="neutral" icon={Percent} onClick={() => setPanel("discount")}>
                      Discount
                    </Button>
                    <Button tone="neutral" icon={Ban} onClick={() => setPanel("void")} className="text-[var(--danger)]">
                      Void bill
                    </Button>
                  </div>
                )}
              </>
            )}

            {typeof panel === "object" && "refund" in panel && (
              <PinForm
                title="Refund this payment"
                note="The money goes back to the customer. A manager must approve."
                submitLabel="Refund"
                danger
                onCancel={() => setPanel("pay")}
                onSubmit={(f) => send(`payments/${panel.refund}/refund`, { reason: f.reason, pin: f.pin })}
              />
            )}
          </div>
        </div>
      )}
    </Sheet>
  );
}

function Line({ label, value, strong, muted, action }: { label: string; value: string; strong?: boolean; muted?: boolean; action?: { label: string; onClick: () => void } }) {
  return (
    <div className={`flex items-baseline justify-between gap-3 ${strong ? "text-lg font-bold" : muted ? "text-sm text-[var(--muted)]" : ""}`}>
      <dt className="min-w-0 truncate">
        {label}
        {action && (
          <button type="button" onClick={action.onClick} className="ml-2 rounded-full px-2 py-0.5 text-xs font-semibold text-[var(--danger)] transition hover:bg-[var(--danger-bg)]">
            {action.label}
          </button>
        )}
      </dt>
      <dd className="shrink-0">{value}</dd>
    </div>
  );
}
