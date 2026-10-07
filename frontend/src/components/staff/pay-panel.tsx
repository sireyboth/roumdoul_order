"use client";

import { useState } from "react";
import { ArrowRightLeft, Banknote, CircleCheck, CreditCard, QrCode, type LucideIcon } from "lucide-react";
import type { BillDetail } from "@/lib/staff";
import { newKey } from "@/lib/table-api";
import { cashPreview, formatMoney, parseAmount } from "@/lib/money";
import { Alert, inputClass } from "../ui";

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

const METHODS: { value: Method; label: string; icon: LucideIcon }[] = [
  { value: "cash", label: "Cash", icon: Banknote },
  { value: "khqr", label: "KHQR", icon: QrCode },
  { value: "card", label: "Card", icon: CreditCard },
];

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
    <section className="flex flex-col gap-4 rounded-3xl border border-[var(--line)] bg-[var(--surface-2)] p-4">
      <div className="grid grid-cols-3 gap-1 rounded-2xl bg-[var(--chip)] p-1 text-sm font-semibold" role="tablist" aria-label="Payment method">
        {METHODS.map(({ value: m, label, icon: Icon }) => (
          <button
            key={m}
            type="button"
            role="tab"
            aria-selected={method === m}
            onClick={() => {
              setMethod(m);
              setTyped("");
              setError(null);
              fresh();
            }}
            className={`flex items-center justify-center gap-1.5 rounded-xl py-2.5 transition ${method === m ? "bg-[var(--surface)] text-[var(--fg)] shadow-soft" : "text-[var(--muted)] hover:text-[var(--fg)]"}`}
          >
            <Icon className="size-4" aria-hidden />
            {label}
          </button>
        ))}
      </div>

      {method === "cash" ? (
        <div key="cash" className="anim-fade-in flex flex-col gap-3">
          <div className="flex items-center gap-2 text-sm">
            <span className="font-medium text-[var(--muted)]">Customer gives</span>
            <Toggle value={cashCurrency} onChange={(c) => { setCashCurrency(c); setChangeCurrency(c); setTyped(""); fresh(); }} />
          </div>
          <input
            inputMode="decimal"
            aria-label="Cash received"
            placeholder={cashCurrency === "KHR" ? "e.g. 50000" : "e.g. 20.00"}
            value={typed}
            onChange={(e) => { setTyped(e.target.value); fresh(); }}
            className={`${inputClass} h-16 text-3xl font-bold tabular-nums`}
          />
          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              onClick={() => { setTyped(cashCurrency === "KHR" ? String(dueInCash) : (dueInCash / 100).toFixed(2)); fresh(); }}
              className="rounded-full bg-[var(--brand-soft)] px-3.5 py-2 text-sm font-semibold text-[var(--brand-strong)] transition hover:brightness-95 active:scale-95"
            >
              Exact {money(dueInCash, cashCurrency)}
            </button>
            {QUICK[cashCurrency].filter((n) => n > dueInCash).slice(0, 3).map((n) => (
              <button
                key={n}
                type="button"
                onClick={() => { setTyped(cashCurrency === "KHR" ? String(n) : String(n / 100)); fresh(); }}
                className="rounded-full border border-[var(--line)] bg-[var(--surface)] px-3.5 py-2 text-sm font-semibold tabular-nums transition hover:bg-[var(--chip)] active:scale-95"
              >
                {money(n, cashCurrency)}
              </button>
            ))}
          </div>
          {preview && preview.change > 0 && (
            <div className="anim-scale-in flex items-center justify-between gap-3 rounded-2xl bg-[var(--accent-soft)] px-4 py-3">
              <span className="flex items-center gap-2 text-sm font-semibold text-[var(--warn)]">
                <ArrowRightLeft className="size-4" aria-hidden />
                Change in <Toggle value={changeCurrency} onChange={(c) => { setChangeCurrency(c); fresh(); }} />
              </span>
              <strong className="text-2xl tabular-nums">{money(preview.change, changeCurrency)}</strong>
            </div>
          )}
          {preview && preview.applied < bill.remaining && (
            <p className="text-sm text-[var(--muted)]">
              Part payment: {money(preview.applied)} now, {money(bill.remaining - preview.applied)} still to pay.
            </p>
          )}
        </div>
      ) : (
        <div key="other" className="anim-fade-in flex flex-col gap-3">
          <label className="flex flex-col gap-1.5 text-sm">
            <span className="text-[var(--muted)]">Amount (leave empty for everything left: {money(bill.remaining)})</span>
            <input
              inputMode="decimal"
              placeholder={bill.currency === "KHR" ? String(bill.remaining) : (bill.remaining / 100).toFixed(2)}
              value={typed}
              onChange={(e) => { setTyped(e.target.value); fresh(); }}
              className={`${inputClass} h-14 text-xl font-semibold tabular-nums`}
            />
          </label>
          {method === "khqr" && (
            <label className="flex flex-col gap-1.5 text-sm">
              <span className="text-[var(--muted)]">Transaction reference (optional, from the bank app)</span>
              <input value={reference} onChange={(e) => { setReference(e.target.value.slice(0, 100)); fresh(); }} className={inputClass} />
            </label>
          )}
          {tooMuch && <p className="text-sm font-medium text-[var(--danger)]">That is more than the bill. Only cash can give change.</p>}
        </div>
      )}

      {error && <Alert>{error}</Alert>}

      <button
        type="button"
        disabled={busy || (method === "cash" ? !tendered : tooMuch || partAmount === null)}
        onClick={submit}
        className="flex h-14 items-center justify-center gap-2 rounded-2xl bg-[var(--brand)] px-4 text-lg font-bold text-white shadow-soft transition hover:bg-[var(--brand-strong)] active:scale-[0.98] disabled:opacity-40 dark:text-[#06140f]"
      >
        <CircleCheck className="size-5" aria-hidden />
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
        <button
          key={c}
          type="button"
          onClick={() => onChange(c)}
          aria-pressed={value === c}
          className={`rounded-full px-3 py-1 transition ${value === c ? "bg-[var(--brand)] text-white shadow-soft dark:text-[#06140f]" : "text-[var(--muted)] hover:text-[var(--fg)]"}`}
        >
          {c === "USD" ? "$" : "៛"}
        </button>
      ))}
    </span>
  );
}
