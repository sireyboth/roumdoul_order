"use client";

import { useState } from "react";
import type { BillDetail } from "@/lib/staff";
import { newKey } from "@/lib/table-api";
import { cashPreview, formatMoney, parseAmount } from "@/lib/money";

type Method = "cash" | "khqr" | "card";

export type PayBody = {
  idempotency_key?: string;
  method: Method;
  amount?: number;
  tendered_amount?: number;
  tendered_currency?: "USD" | "KHR";
  change_currency?: "USD" | "KHR";
  reference?: string;
};

const QUICK = {
  USD: [500, 1000, 2000, 5000],
  KHR: [10000, 20000, 50000, 100000],
} as const;

/** Take one payment: cash in dollars or riel (with change), or KHQR / card for the amount due or part of it. */
export default function PayPanel({ bill, onPay }: { bill: BillDetail; onPay: (body: PayBody) => Promise<void> }) {
  const [method, setMethod] = useState<Method>("cash");
  const [cashCurrency, setCashCurrency] = useState<"USD" | "KHR">(bill.currency);
  const [changeCurrency, setChangeCurrency] = useState<"USD" | "KHR">(bill.currency);
  const [typed, setTyped] = useState("");
  const [reference, setReference] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // One key per attempt: a retry after a network error can never charge twice.
  const [key, setKey] = useState(() => newKey());

  const fresh = () => setKey(newKey());
  const money = (minor: number, currency: "USD" | "KHR" = bill.currency) => formatMoney(minor, currency);

  const dueInCash = cashCurrency === "KHR" ? bill.remaining_khr : bill.currency === "USD" ? bill.remaining : Math.ceil((bill.remaining * 100) / bill.khr_per_usd);
  const tendered = parseAmount(typed, method === "cash" ? cashCurrency : bill.currency);
  const preview = method === "cash" && tendered ? cashPreview(bill.remaining, bill.currency, bill.khr_per_usd, tendered, cashCurrency, changeCurrency) : null;
  const partAmount = method !== "cash" ? (typed.trim() === "" ? bill.remaining : tendered) : null;
  const tooMuch = method !== "cash" && partAmount !== null && partAmount > bill.remaining;

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      if (method === "cash") {
        if (!tendered) throw new Error("Enter the cash the customer handed over.");
        await onPay({ idempotency_key: key, method, tendered_amount: tendered, tendered_currency: cashCurrency, change_currency: changeCurrency });
      } else {
        if (!partAmount || tooMuch) throw new Error("Enter an amount up to what is left to pay.");
        await onPay({ idempotency_key: key, method, amount: partAmount, reference: reference.trim() || undefined });
      }
      setTyped("");
      setReference("");
      fresh();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Payment failed. Try again.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="flex flex-col gap-3 rounded-xl border border-[var(--line)] p-3">
      <div className="grid grid-cols-3 gap-1 rounded-lg bg-[var(--chip)] p-1 text-sm font-semibold">
        {(["cash", "khqr", "card"] as Method[]).map((m) => (
          <button
            key={m}
            type="button"
            onClick={() => {
              setMethod(m);
              setTyped("");
              setError(null);
              fresh();
            }}
            className={`rounded-md py-2 ${method === m ? "bg-[var(--surface)] shadow" : ""}`}
          >
            {m === "cash" ? "💵 Cash" : m === "khqr" ? "📱 KHQR" : "💳 Card"}
          </button>
        ))}
      </div>

      {method === "cash" ? (
        <>
          <div className="flex items-center gap-2 text-sm">
            <span className="text-[var(--muted)]">Customer gives</span>
            <Toggle value={cashCurrency} onChange={(c) => { setCashCurrency(c); setChangeCurrency(c); setTyped(""); fresh(); }} />
          </div>
          <input
            inputMode="decimal"
            aria-label="Cash received"
            placeholder={cashCurrency === "KHR" ? "e.g. 50000" : "e.g. 20.00"}
            value={typed}
            onChange={(e) => { setTyped(e.target.value); fresh(); }}
            className="rounded-lg border border-[var(--line)] bg-transparent px-3 py-3 text-2xl font-semibold tabular-nums"
          />
          <div className="flex flex-wrap gap-2">
            <button type="button" onClick={() => { setTyped(cashCurrency === "KHR" ? String(dueInCash) : (dueInCash / 100).toFixed(2)); fresh(); }}
              className="rounded-full bg-[var(--brand-soft)] px-3 py-1.5 text-sm font-semibold text-[var(--brand)]">
              Exact {money(dueInCash, cashCurrency)}
            </button>
            {QUICK[cashCurrency].filter((n) => n > dueInCash).slice(0, 3).map((n) => (
              <button key={n} type="button" onClick={() => { setTyped(cashCurrency === "KHR" ? String(n) : String(n / 100)); fresh(); }}
                className="rounded-full bg-[var(--chip)] px-3 py-1.5 text-sm font-medium">
                {money(n, cashCurrency)}
              </button>
            ))}
          </div>
          {preview && preview.change > 0 && (
            <div className="flex items-center justify-between rounded-lg bg-[var(--chip)] px-3 py-2">
              <span className="flex items-center gap-2 text-sm">
                Change in <Toggle value={changeCurrency} onChange={(c) => { setChangeCurrency(c); fresh(); }} />
              </span>
              <strong className="text-xl tabular-nums">{money(preview.change, changeCurrency)}</strong>
            </div>
          )}
          {preview && preview.applied < bill.remaining && (
            <p className="text-sm text-[var(--muted)]">
              Part payment: {money(preview.applied)} now, {money(bill.remaining - preview.applied)} still to pay.
            </p>
          )}
        </>
      ) : (
        <>
          <label className="flex flex-col gap-1 text-sm">
            <span className="text-[var(--muted)]">Amount (leave empty for everything left: {money(bill.remaining)})</span>
            <input
              inputMode="decimal"
              placeholder={bill.currency === "KHR" ? String(bill.remaining) : (bill.remaining / 100).toFixed(2)}
              value={typed}
              onChange={(e) => { setTyped(e.target.value); fresh(); }}
              className="rounded-lg border border-[var(--line)] bg-transparent px-3 py-2.5 text-lg tabular-nums"
            />
          </label>
          {method === "khqr" && (
            <label className="flex flex-col gap-1 text-sm">
              <span className="text-[var(--muted)]">Transaction reference (optional, from the bank app)</span>
              <input value={reference} onChange={(e) => { setReference(e.target.value.slice(0, 100)); fresh(); }}
                className="rounded-lg border border-[var(--line)] bg-transparent px-3 py-2.5" />
            </label>
          )}
          {tooMuch && <p className="text-sm text-[var(--danger)]">That is more than the bill. Only cash can give change.</p>}
        </>
      )}

      {error && <p role="alert" className="rounded-lg bg-[var(--danger-bg)] p-2 text-sm text-[var(--danger)]">{error}</p>}

      <button
        type="button"
        disabled={busy || (method === "cash" ? !tendered : tooMuch || partAmount === null)}
        onClick={submit}
        className="rounded-xl bg-[var(--brand)] px-4 py-3.5 text-lg font-semibold text-white disabled:opacity-40"
      >
        {busy
          ? "Saving..."
          : method === "cash"
            ? preview
              ? `Take ${money(tendered ?? 0, cashCurrency)}`
              : "Take cash"
            : `Paid ${money(partAmount ?? 0)} by ${method === "khqr" ? "KHQR" : "card"}`}
      </button>
    </section>
  );
}

function Toggle({ value, onChange }: { value: "USD" | "KHR"; onChange: (v: "USD" | "KHR") => void }) {
  return (
    <span className="inline-flex rounded-full bg-[var(--chip)] p-0.5 text-sm font-semibold">
      {(["USD", "KHR"] as const).map((c) => (
        <button key={c} type="button" onClick={() => onChange(c)} aria-pressed={value === c}
          className={`rounded-full px-3 py-1 ${value === c ? "bg-[var(--brand)] text-white" : ""}`}>
          {c === "USD" ? "$" : "៛"}
        </button>
      ))}
    </span>
  );
}
