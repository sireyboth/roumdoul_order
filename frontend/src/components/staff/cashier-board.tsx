"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { SignedOut, receiptUrl, since, staffApi, type BillDetail, type CashierTable, type CashierTables, type ShiftState } from "@/lib/staff";
import { newKey } from "@/lib/table-api";
import { formatMoney } from "@/lib/money";
import Sheet from "../sheet";
import PayPanel from "./pay-panel";
import PinForm from "./pin-form";
import ShiftSheet from "./shift-sheet";
import { useLive } from "@/lib/live";

/** Tables with what they owe; tap one to open its bill and take payment. */
export default function CashierBoard({ branchId }: { branchId: number }) {
  const [data, setData] = useState<CashierTables | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [signedOut, setSignedOut] = useState(false);
  const [now, setNow] = useState(() => Date.now());
  const [selected, setSelected] = useState<CashierTable | null>(null);
  const [shift, setShift] = useState<ShiftState | null>(null);
  const [shiftOpen, setShiftOpen] = useState(false);
  const busy = useRef(false);

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

  if (signedOut) {
    return (
      <main className="grid min-h-dvh place-items-center p-6">
        <Link href="/staff" className="rounded-xl bg-[var(--brand)] px-5 py-3 font-semibold text-white">Sign in again</Link>
      </main>
    );
  }

  const tables = data?.tables ?? [];
  const busyTables = tables.filter((t) => t.session);
  const billRequested = busyTables.filter((t) => t.session?.status === "bill_requested").length;
  const money = (minor: number) => formatMoney(minor, data?.currency ?? "USD");

  return (
    <main className="mx-auto flex min-h-dvh max-w-5xl flex-col gap-4 px-4 pb-10">
      <header className="sticky top-0 z-10 -mx-4 flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-[var(--line)] bg-[var(--surface)] px-4 py-3">
        <Link href="/staff" className="text-sm text-[var(--muted)]">← Screens</Link>
        <h1 className="font-semibold">Cashier</h1>
        <p className="ml-auto text-sm text-[var(--muted)]">
          {busyTables.length} busy
          {billRequested > 0 && <span className="ml-2 rounded-full bg-amber-300 px-2 py-0.5 font-semibold text-black">{billRequested} want the bill</span>}
        </p>
        {shift && (
          <button
            type="button"
            onClick={() => setShiftOpen(true)}
            className={`rounded-full px-3 py-1.5 text-sm font-semibold ${shift.shift ? "border border-[var(--line)]" : "bg-[var(--danger)] text-white"}`}
          >
            {shift.shift
              ? `🗄️ Shift open since ${new Date(shift.shift.opened_at).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}`
              : "🗄️ Start shift"}
          </button>
        )}
      </header>

      {shift && !shift.shift && (
        <p className="rounded-lg bg-[var(--danger-bg)] p-3 text-sm text-[var(--danger)]">
          No shift is open. Count the cash drawer and tap <strong>Start shift</strong> before taking payments.
        </p>
      )}

      {error && <p role="alert" className="rounded-lg bg-[var(--danger-bg)] p-3 text-sm text-[var(--danger)]">{error}</p>}

      {!data ? (
        <p className="text-[var(--muted)]">Loading tables...</p>
      ) : tables.length === 0 ? (
        <p className="rounded-lg bg-[var(--chip)] p-4 text-[var(--muted)]">This branch has no tables yet. Add them in the back office.</p>
      ) : (
        <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
          {tables.map((table) => {
            const s = table.session;
            const wantsBill = s?.status === "bill_requested";
            return (
              <li key={table.id}>
                <button
                  type="button"
                  disabled={!s}
                  onClick={() => setSelected(table)}
                  className={`flex h-full w-full flex-col items-start gap-1 rounded-xl border-2 p-3 text-left disabled:cursor-default ${
                    wantsBill
                      ? "border-amber-400 bg-amber-50 dark:bg-amber-950/30"
                      : s
                        ? "border-[var(--brand)] bg-[var(--brand-soft)]"
                        : "border-[var(--line)] opacity-70"
                  }`}
                >
                  <span className="flex w-full items-center justify-between gap-2">
                    <span className="text-xl font-bold">{table.name}</span>
                    {table.area && <span className="truncate text-xs text-[var(--muted)]">{table.area}</span>}
                  </span>
                  {s ? (
                    <>
                      <span className="text-lg font-semibold tabular-nums">{money(s.total - s.paid_total)}</span>
                      <span className="text-xs text-[var(--muted)]">
                        {s.orders_count} order{s.orders_count === 1 ? "" : "s"} · {since(s.opened_at, now).label}
                        {s.active_orders_count > 0 ? ` · ${s.active_orders_count} cooking` : ""}
                      </span>
                      {wantsBill && <span className="rounded-full bg-amber-300 px-2 py-0.5 text-xs font-semibold text-black">💵 Wants the bill</span>}
                      {s.bill && !wantsBill && <span className="text-xs font-medium">Bill #{s.bill.number}</span>}
                    </>
                  ) : (
                    <span className="text-sm text-[var(--muted)]">Free</span>
                  )}
                </button>
              </li>
            );
          })}
        </ul>
      )}

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

type Panel = "pay" | "discount" | "void" | { refund: number } | { removeDiscount: number };

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
    <Sheet onClose={onClose} label={`Bill for ${tableName}`}>
      <div className="flex items-start justify-between gap-3">
        <div>
          <h2 className="text-xl font-semibold">Table {tableName}</h2>
          {bill && (
            <p className="text-sm text-[var(--muted)]">
              Bill #{bill.number}
              {bill.area ? ` · ${bill.area}` : ""}
            </p>
          )}
        </div>
        <button type="button" onClick={onClose} className="rounded-full border border-[var(--line)] px-3 py-1.5 text-sm">Close</button>
      </div>

      {error && <p role="alert" className="rounded-lg bg-[var(--danger-bg)] p-3 text-sm text-[var(--danger)]">{error}</p>}
      {!bill && !error && <p className="text-[var(--muted)]">Opening bill...</p>}

      {bill && (
        <>
          {bill.status === "paid" && (
            <div className="rounded-xl bg-[var(--brand)] p-4 text-center text-white">
              <p className="text-2xl font-bold">Paid ✓</p>
              {lastChange && lastChange.amount > 0 && (
                <p className="mt-1 text-lg">Give change: <strong>{formatMoney(lastChange.amount, lastChange.currency)}</strong></p>
              )}
            </div>
          )}
          {bill.status !== "void" && (
            <button
              type="button"
              onClick={() => window.open(receiptUrl(bill.id), "_blank", "width=420,height=700")}
              className={`rounded-xl px-4 py-2.5 font-semibold ${bill.status === "paid" ? "bg-[var(--fg)] text-[var(--surface)]" : "border border-[var(--line)]"}`}
            >
              🖨️ {bill.status === "paid" ? "Print receipt" : "Print bill for the table"}
            </button>
          )}
          {bill.status === "void" && (
            <p className="rounded-xl bg-[var(--danger-bg)] p-3 text-[var(--danger)]">Void: {bill.void_reason}</p>
          )}

          <section className="flex flex-col gap-2">
            {bill.orders.map((order) => (
              <div key={order.id} className={`rounded-lg border border-[var(--line)] p-3 ${order.counts ? "" : "opacity-50"}`}>
                <p className="flex justify-between text-sm font-semibold">
                  <span>Order #{order.number}{order.source === "waiter" ? " · by waiter" : ""}</span>
                  <span className={order.counts ? "capitalize" : "text-[var(--danger)]"}>{order.counts ? order.status : `Cancelled: ${order.cancel_reason ?? ""}`}</span>
                </p>
                <ul className="mt-1 flex flex-col gap-0.5 text-sm">
                  {order.items.map((item, i) => (
                    <li key={i} className={`flex justify-between gap-3 ${order.counts ? "" : "line-through"}`}>
                      <span className="min-w-0">
                        {item.quantity} × {item.name.en}
                        {item.options.length > 0 && <span className="text-[var(--muted)]"> · {item.options.map((o) => o.en).join(", ")}</span>}
                        {item.note && <span className="text-[var(--muted)]"> · “{item.note}”</span>}
                      </span>
                      <span className="shrink-0 tabular-nums">{money(item.line_total ?? 0)}</span>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </section>

          <dl className="flex flex-col gap-1 border-t border-[var(--line)] pt-3 tabular-nums">
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
            <Line label="Total" value={money(bill.total)} strong />
            {bill.currency === "USD" && <Line label="Total in riel" value={riel(bill.total_khr)} />}
            {bill.prices_include_vat && bill.vat_included > 0 && <Line label={`Includes VAT ${bill.vat_bp / 100}%`} value={money(bill.vat_included)} muted />}
            {bill.paid_total > 0 && <Line label="Paid" value={money(bill.paid_total)} />}
            {bill.status === "open" && (
              <div className="mt-1 flex items-baseline justify-between rounded-lg bg-[var(--chip)] px-3 py-2">
                <span className="font-semibold">Left to pay</span>
                <span className="text-right">
                  <span className="block text-2xl font-bold">{money(bill.remaining)}</span>
                  {bill.currency === "USD" && <span className="text-sm text-[var(--muted)]">{riel(bill.remaining_khr)}</span>}
                </span>
              </div>
            )}
          </dl>

          {bill.payments.length > 0 && (
            <section className="flex flex-col gap-1">
              <h3 className="text-sm font-semibold uppercase tracking-wide text-[var(--muted)]">Payments</h3>
              {bill.payments.map((p) => (
                <div key={p.id} className={`flex items-center gap-2 rounded-lg border border-[var(--line)] px-3 py-2 text-sm ${p.status === "refunded" ? "opacity-60" : ""}`}>
                  <span className="min-w-0 flex-1">
                    <strong className="uppercase">{p.method}</strong> {money(p.amount)}
                    {p.tendered_amount !== null && p.tendered_currency && (
                      <span className="text-[var(--muted)]"> · given {formatMoney(p.tendered_amount, p.tendered_currency)}</span>
                    )}
                    {p.change_amount > 0 && p.change_currency && (
                      <span className="text-[var(--muted)]"> · change {formatMoney(p.change_amount, p.change_currency)}</span>
                    )}
                    {p.reference && <span className="text-[var(--muted)]"> · ref {p.reference}</span>}
                    {p.status === "refunded" && <span className="text-[var(--danger)]"> · refunded: {p.refund_reason}</span>}
                  </span>
                  {p.status === "confirmed" && bill.status !== "void" && (
                    <button type="button" onClick={() => setPanel({ refund: p.id })} className="rounded-md border border-[var(--line)] px-2 py-1 text-xs">Refund</button>
                  )}
                </div>
              ))}
            </section>
          )}

          {bill.status === "open" && (
            <>
              {panel === "pay" && bill.remaining > 0 && !shiftOpen && (
                <button type="button" onClick={() => { onClose(); onStartShift(); }}
                  className="rounded-xl bg-[var(--danger)] px-4 py-3 font-semibold text-white">
                  Start a shift to take payment
                </button>
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
                  <button type="button" onClick={() => setPanel("discount")} className="rounded-xl border border-[var(--line)] px-3 py-2.5 text-sm font-semibold">Discount</button>
                  <button type="button" onClick={() => setPanel("void")} className="rounded-xl border border-[var(--line)] px-3 py-2.5 text-sm font-semibold text-[var(--danger)]">Void bill</button>
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
        </>
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
          <button type="button" onClick={action.onClick} className="ml-2 text-xs font-medium text-[var(--danger)] underline">{action.label}</button>
        )}
      </dt>
      <dd className="shrink-0">{value}</dd>
    </div>
  );
}
