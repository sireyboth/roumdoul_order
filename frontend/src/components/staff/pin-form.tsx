"use client";

import { useState } from "react";

export type PinFields = { pin: string; reason: string; type: "percent" | "fixed"; value: number };

/** Anything that needs a manager: discount, void, refund. The manager types their PIN on this screen. */
export default function PinForm({
  title,
  note,
  submitLabel,
  danger,
  withDiscount,
  noReason,
  currency = "USD",
  onCancel,
  onSubmit,
}: {
  title: string;
  note?: string;
  submitLabel: string;
  danger?: boolean;
  withDiscount?: boolean;
  noReason?: boolean;
  currency?: "USD" | "KHR";
  onCancel: () => void;
  onSubmit: (fields: PinFields) => Promise<void>;
}) {
  const [type, setType] = useState<"percent" | "fixed">("percent");
  const [value, setValue] = useState("");
  const [reason, setReason] = useState("");
  const [pin, setPin] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const number = Number(value);
  const valueOk = !withDiscount || (Number.isFinite(number) && number > 0 && (type === "fixed" || number <= 100));
  const ready = valueOk && (noReason || reason.trim() !== "") && /^\d{4,6}$/.test(pin);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await onSubmit({ pin, reason: reason.trim(), type, value: number });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
      setPin("");
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={submit} className="flex flex-col gap-3 rounded-xl border border-[var(--line)] p-3">
      <h3 className="font-semibold">{title}</h3>
      {note && <p className="text-sm text-[var(--muted)]">{note}</p>}

      {withDiscount && (
        <div className="flex gap-2">
          <span className="inline-flex shrink-0 rounded-lg bg-[var(--chip)] p-0.5 text-sm font-semibold">
            {(["percent", "fixed"] as const).map((t) => (
              <button key={t} type="button" onClick={() => setType(t)} aria-pressed={type === t}
                className={`rounded-md px-3 py-1.5 ${type === t ? "bg-[var(--surface)] shadow" : ""}`}>
                {t === "percent" ? "%" : currency === "KHR" ? "៛" : "$"}
              </button>
            ))}
          </span>
          <input
            inputMode="decimal"
            aria-label="Discount"
            placeholder={type === "percent" ? "10" : currency === "KHR" ? "2000" : "1.50"}
            value={value}
            onChange={(e) => setValue(e.target.value)}
            className="min-w-0 flex-1 rounded-lg border border-[var(--line)] bg-transparent px-3 py-2 tabular-nums"
          />
        </div>
      )}

      {!noReason && (
        <input
          aria-label="Reason"
          placeholder="Reason (saved in the history)"
          value={reason}
          maxLength={255}
          onChange={(e) => setReason(e.target.value)}
          className="rounded-lg border border-[var(--line)] bg-transparent px-3 py-2"
        />
      )}

      <input
        type="password"
        inputMode="numeric"
        autoComplete="off"
        aria-label="Manager PIN"
        placeholder="Manager PIN"
        value={pin}
        maxLength={6}
        onChange={(e) => setPin(e.target.value.replace(/\D/g, ""))}
        className="rounded-lg border border-[var(--line)] bg-transparent px-3 py-2 tracking-widest"
      />

      {error && <p role="alert" className="rounded-lg bg-[var(--danger-bg)] p-2 text-sm text-[var(--danger)]">{error}</p>}

      <div className="grid grid-cols-2 gap-2">
        <button type="button" onClick={onCancel} className="rounded-xl border border-[var(--line)] px-3 py-2.5 font-semibold">Cancel</button>
        <button type="submit" disabled={!ready || busy}
          className={`rounded-xl px-3 py-2.5 font-semibold text-white disabled:opacity-40 ${danger ? "bg-[var(--danger)]" : "bg-[var(--brand)]"}`}>
          {busy ? "Saving..." : submitLabel}
        </button>
      </div>
    </form>
  );
}
