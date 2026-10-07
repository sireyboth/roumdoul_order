"use client";

import { useState } from "react";
import { KeyRound, ShieldCheck } from "lucide-react";
import { Alert, Button, inputClass } from "../ui";

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
    <form
      onSubmit={submit}
      className={`anim-scale-in flex flex-col gap-3 rounded-3xl border p-4 ${danger ? "border-[var(--danger)]/30 bg-[var(--danger-bg)]/40" : "border-[var(--line)] bg-[var(--surface-2)]"}`}
    >
      <div className="flex items-start gap-3">
        <span className={`grid size-10 shrink-0 place-items-center rounded-2xl ${danger ? "bg-[var(--danger-bg)] text-[var(--danger)]" : "bg-[var(--brand-soft)] text-[var(--brand)]"}`}>
          <ShieldCheck className="size-5" aria-hidden />
        </span>
        <div className="min-w-0">
          <h3 className="font-bold">{title}</h3>
          {note ? <p className="text-sm text-[var(--muted)]">{note}</p> : <p className="text-sm text-[var(--muted)]">A manager must approve with their PIN.</p>}
        </div>
      </div>

      {withDiscount && (
        <div className="flex gap-2">
          <span className="inline-flex shrink-0 rounded-2xl bg-[var(--chip)] p-1 text-sm font-semibold">
            {(["percent", "fixed"] as const).map((t) => (
              <button
                key={t}
                type="button"
                onClick={() => setType(t)}
                aria-pressed={type === t}
                className={`rounded-xl px-3.5 py-1.5 transition ${type === t ? "bg-[var(--surface)] shadow-soft" : "text-[var(--muted)]"}`}
              >
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
            className={`${inputClass} min-w-0 flex-1 tabular-nums`}
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
          className={inputClass}
        />
      )}

      <label className="relative">
        <KeyRound className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-[var(--muted)]" aria-hidden />
        <input
          type="password"
          inputMode="numeric"
          autoComplete="off"
          aria-label="Manager PIN"
          placeholder="Manager PIN"
          value={pin}
          maxLength={6}
          onChange={(e) => setPin(e.target.value.replace(/\D/g, ""))}
          className={`${inputClass} pl-10 tracking-[0.3em]`}
        />
      </label>

      {error && <Alert>{error}</Alert>}

      <div className="grid grid-cols-2 gap-2">
        <Button tone="neutral" onClick={onCancel}>
          Cancel
        </Button>
        <Button type="submit" tone={danger ? "danger" : "brand"} disabled={!ready || busy}>
          {busy ? "Saving..." : submitLabel}
        </Button>
      </div>
    </form>
  );
}
