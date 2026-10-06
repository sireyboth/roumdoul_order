"use client";

import { useState } from "react";
import { staffApi, type Shift, type ShiftState } from "@/lib/staff";
import { formatMoney, parseAmount } from "@/lib/money";
import Sheet from "../sheet";

const usd = (cents: number) => `${cents < 0 ? "−" : ""}${formatMoney(Math.abs(cents), "USD")}`;
const khr = (riel: number) => `${riel < 0 ? "−" : ""}${formatMoney(Math.abs(riel), "KHR")}`;

/** Start a shift (count the drawer), record cash in / out, and count and close at the end. */
export default function ShiftSheet({
  branchId,
  state,
  currency,
  onChanged,
  onClose,
}: {
  branchId: number;
  state: ShiftState;
  currency: "USD" | "KHR";
  onChanged: () => Promise<void>;
  onClose: () => void;
}) {
  const shift = state.shift;
  const [panel, setPanel] = useState<"none" | "move" | "close">("none");
  const [justClosed, setJustClosed] = useState<Shift | null>(null);

  async function post(path: string, body: unknown): Promise<Shift> {
    const result = await staffApi<Shift>(path, { method: "POST", body });
    await onChanged();
    return result;
  }

  return (
    <Sheet onClose={onClose} label="Cash drawer">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h2 className="text-xl font-semibold">Cash drawer</h2>
          <p className="text-sm text-[var(--muted)]">
            {shift ? `Shift open since ${new Date(shift.opened_at).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })} · ${shift.opened_by ?? ""}` : "No shift open"}
          </p>
        </div>
        <button type="button" onClick={onClose} className="rounded-full border border-[var(--line)] px-3 py-1.5 text-sm">Close</button>
      </div>

      {justClosed && <Result shift={justClosed} />}

      {!shift ? (
        <>
          {!justClosed && state.last_closed && (
            <p className="rounded-lg bg-[var(--chip)] p-3 text-sm text-[var(--muted)]">
              Last shift closed {new Date(state.last_closed.closed_at ?? "").toLocaleString()} with{" "}
              {usd(state.last_closed.counted_usd ?? 0)} · {khr(state.last_closed.counted_khr ?? 0)} counted.
            </p>
          )}
          <OpenForm onOpen={async (u, k, note) => {
            await post(`branches/${branchId}/shift`, { opening_cash_usd: u, opening_cash_khr: k, note });
            setJustClosed(null);
          }} />
        </>
      ) : (
        <>
          {/* Blind count: the expected amount is hidden while counting. */}
          {panel !== "close" && <Summary shift={shift} currency={currency} />}

          {panel === "move" && (
            <MoveForm
              onCancel={() => setPanel("none")}
              onSave={async (body) => {
                await post(`shifts/${shift.id}/movements`, body);
                setPanel("none");
              }}
            />
          )}

          {panel === "close" && (
            <CloseForm
              onCancel={() => setPanel("none")}
              onSave={async (u, k, note) => {
                const closed = await post(`shifts/${shift.id}/close`, { counted_cash_usd: u, counted_cash_khr: k, note });
                setJustClosed(closed);
                setPanel("none");
              }}
            />
          )}

          {panel === "none" && (
            <div className="grid grid-cols-2 gap-2">
              <button type="button" onClick={() => setPanel("move")} className="rounded-xl border border-[var(--line)] px-3 py-3 font-semibold">Cash in / out</button>
              <button type="button" onClick={() => setPanel("close")} className="rounded-xl bg-[var(--fg)] px-3 py-3 font-semibold text-[var(--surface)]">Count &amp; close shift</button>
            </div>
          )}
        </>
      )}
    </Sheet>
  );
}

function Summary({ shift, currency }: { shift: Shift; currency: "USD" | "KHR" }) {
  const s = shift.summary;
  const rows: [string, number, number][] = [
    ["Opening count", s.opening_usd, s.opening_khr],
    ["Cash received", s.cash_received_usd, s.cash_received_khr],
    ["Change given", -s.change_given_usd, -s.change_given_khr],
    ["Cash in", s.cash_in_usd, s.cash_in_khr],
    ["Cash out", -s.cash_out_usd, -s.cash_out_khr],
    ["Cash refunded", -s.refunded_usd, -s.refunded_khr],
  ];
  const methods = Object.entries(s.by_method);

  return (
    <>
      <table className="w-full text-sm tabular-nums">
        <thead>
          <tr className="text-left text-[var(--muted)]">
            <th className="py-1 font-medium" />
            <th className="py-1 text-right font-medium">Dollars</th>
            <th className="py-1 text-right font-medium">Riel</th>
          </tr>
        </thead>
        <tbody>
          {rows.filter(([, u, k]) => u !== 0 || k !== 0).map(([label, u, k]) => (
            <tr key={label} className="border-t border-[var(--line)]">
              <td className="py-1.5">{label}</td>
              <td className="py-1.5 text-right">{usd(u)}</td>
              <td className="py-1.5 text-right">{khr(k)}</td>
            </tr>
          ))}
          <tr className="border-t-2 border-[var(--fg)] text-base font-bold">
            <td className="py-2">Should be in drawer</td>
            <td className="py-2 text-right">{usd(s.expected_usd)}</td>
            <td className="py-2 text-right">{khr(s.expected_khr)}</td>
          </tr>
        </tbody>
      </table>

      <div className="flex flex-wrap gap-2 text-sm">
        {methods.length === 0 ? (
          <span className="text-[var(--muted)]">No payments yet this shift.</span>
        ) : (
          methods.map(([method, row]) => (
            <span key={method} className="rounded-full bg-[var(--chip)] px-3 py-1">
              <strong className="uppercase">{method}</strong> {row.count} · {formatMoney(row.amount, currency)}
            </span>
          ))
        )}
        {s.refunds_count > 0 && <span className="rounded-full bg-[var(--danger-bg)] px-3 py-1 text-[var(--danger)]">{s.refunds_count} refund(s)</span>}
      </div>

      {shift.movements.length > 0 && (
        <ul className="flex flex-col gap-1 text-sm">
          {shift.movements.map((m) => (
            <li key={m.id} className="flex justify-between gap-2 rounded-lg border border-[var(--line)] px-3 py-1.5">
              <span>{m.type === "in" ? "➕ In" : "➖ Out"} · {m.reason}</span>
              <span className="tabular-nums">{formatMoney(m.amount, m.currency)}</span>
            </li>
          ))}
        </ul>
      )}
    </>
  );
}

function Result({ shift }: { shift: Shift }) {
  const du = shift.difference_usd ?? 0;
  const dk = shift.difference_khr ?? 0;
  const ok = du === 0 && dk === 0;
  return (
    <div className={`rounded-xl p-4 ${ok ? "bg-[var(--brand)] text-white" : "bg-amber-300 text-black"}`}>
      <p className="text-lg font-bold">{ok ? "Shift closed. Drawer is exact ✓" : "Shift closed with a difference"}</p>
      <p className="text-sm">
        Expected {usd(shift.summary.expected_usd)} · {khr(shift.summary.expected_khr)}; counted {usd(shift.counted_usd ?? 0)} · {khr(shift.counted_khr ?? 0)}
      </p>
      {!ok && (
        <p className="mt-1 font-semibold">
          Difference: {du > 0 ? "+" : ""}{usd(du)} · {dk > 0 ? "+" : ""}{khr(dk)} {du < 0 || dk < 0 ? "(short)" : "(over)"}
        </p>
      )}
    </div>
  );
}

function useSubmit() {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  async function run(fn: () => Promise<void>) {
    setBusy(true);
    setError(null);
    try {
      await fn();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Something went wrong.");
    } finally {
      setBusy(false);
    }
  }
  return { busy, error, run };
}

/** Empty means zero; anything else must be a valid amount. */
function amountOrZero(typed: string, currency: "USD" | "KHR"): number | null {
  return typed.trim() === "" ? 0 : parseAmount(typed, currency);
}

function CashInputs({ usdValue, khrValue, onUsd, onKhr }: { usdValue: string; khrValue: string; onUsd: (v: string) => void; onKhr: (v: string) => void }) {
  return (
    <div className="grid grid-cols-2 gap-2">
      <label className="flex flex-col gap-1 text-sm">
        <span className="text-[var(--muted)]">Dollars ($)</span>
        <input inputMode="decimal" aria-label="Dollars" placeholder="0.00" value={usdValue} onChange={(e) => onUsd(e.target.value)}
          className="rounded-lg border border-[var(--line)] bg-transparent px-3 py-2.5 text-lg tabular-nums" />
      </label>
      <label className="flex flex-col gap-1 text-sm">
        <span className="text-[var(--muted)]">Riel (៛)</span>
        <input inputMode="numeric" aria-label="Riel" placeholder="0" value={khrValue} onChange={(e) => onKhr(e.target.value)}
          className="rounded-lg border border-[var(--line)] bg-transparent px-3 py-2.5 text-lg tabular-nums" />
      </label>
    </div>
  );
}

function OpenForm({ onOpen }: { onOpen: (usdCents: number, riel: number, note?: string) => Promise<void> }) {
  const [u, setU] = useState("");
  const [k, setK] = useState("");
  const { busy, error, run } = useSubmit();
  const cents = amountOrZero(u, "USD");
  const riel = amountOrZero(k, "KHR");

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        if (cents !== null && riel !== null) void run(() => onOpen(cents, riel));
      }}
      className="flex flex-col gap-3 rounded-xl border border-[var(--line)] p-3"
    >
      <h3 className="font-semibold">Start a shift</h3>
      <p className="text-sm text-[var(--muted)]">Count the cash in the drawer now. Payments can be taken once the shift is open.</p>
      <CashInputs usdValue={u} khrValue={k} onUsd={setU} onKhr={setK} />
      {error && <p role="alert" className="rounded-lg bg-[var(--danger-bg)] p-2 text-sm text-[var(--danger)]">{error}</p>}
      <button type="submit" disabled={busy || cents === null || riel === null} className="rounded-xl bg-[var(--brand)] px-4 py-3 font-semibold text-white disabled:opacity-40">
        {busy ? "Starting..." : "Start shift"}
      </button>
    </form>
  );
}

function MoveForm({ onCancel, onSave }: { onCancel: () => void; onSave: (body: { type: "in" | "out"; amount: number; currency: "USD" | "KHR"; reason: string }) => Promise<void> }) {
  const [type, setType] = useState<"in" | "out">("out");
  const [cur, setCur] = useState<"USD" | "KHR">("USD");
  const [typed, setTyped] = useState("");
  const [reason, setReason] = useState("");
  const { busy, error, run } = useSubmit();
  const amount = parseAmount(typed, cur);

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        if (amount) void run(() => onSave({ type, amount, currency: cur, reason: reason.trim() }));
      }}
      className="flex flex-col gap-3 rounded-xl border border-[var(--line)] p-3"
    >
      <h3 className="font-semibold">Cash in or out</h3>
      <div className="flex flex-wrap gap-2">
        {(["out", "in"] as const).map((t) => (
          <button key={t} type="button" aria-pressed={type === t} onClick={() => setType(t)}
            className={`rounded-full px-3 py-1.5 text-sm font-semibold ${type === t ? "bg-[var(--brand)] text-white" : "bg-[var(--chip)]"}`}>
            {t === "out" ? "➖ Take out" : "➕ Put in"}
          </button>
        ))}
        {(["USD", "KHR"] as const).map((c) => (
          <button key={c} type="button" aria-pressed={cur === c} onClick={() => setCur(c)}
            className={`rounded-full px-3 py-1.5 text-sm font-semibold ${cur === c ? "bg-[var(--fg)] text-[var(--surface)]" : "bg-[var(--chip)]"}`}>
            {c === "USD" ? "$" : "៛"}
          </button>
        ))}
      </div>
      <input inputMode="decimal" aria-label="Amount" placeholder={cur === "USD" ? "5.00" : "20000"} value={typed} onChange={(e) => setTyped(e.target.value)}
        className="rounded-lg border border-[var(--line)] bg-transparent px-3 py-2.5 text-lg tabular-nums" />
      <input aria-label="What for" placeholder="What for? (e.g. ice delivery, more small notes)" maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)}
        className="rounded-lg border border-[var(--line)] bg-transparent px-3 py-2.5" />
      {error && <p role="alert" className="rounded-lg bg-[var(--danger-bg)] p-2 text-sm text-[var(--danger)]">{error}</p>}
      <div className="grid grid-cols-2 gap-2">
        <button type="button" onClick={onCancel} className="rounded-xl border border-[var(--line)] px-3 py-2.5 font-semibold">Cancel</button>
        <button type="submit" disabled={busy || !amount || reason.trim() === ""} className="rounded-xl bg-[var(--brand)] px-3 py-2.5 font-semibold text-white disabled:opacity-40">
          {busy ? "Saving..." : "Save"}
        </button>
      </div>
    </form>
  );
}

function CloseForm({ onCancel, onSave }: { onCancel: () => void; onSave: (usdCents: number, riel: number, note?: string) => Promise<void> }) {
  const [u, setU] = useState("");
  const [k, setK] = useState("");
  const [note, setNote] = useState("");
  const { busy, error, run } = useSubmit();
  const cents = amountOrZero(u, "USD");
  const riel = amountOrZero(k, "KHR");

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        if (cents !== null && riel !== null) void run(() => onSave(cents, riel, note.trim() || undefined));
      }}
      className="flex flex-col gap-3 rounded-xl border border-[var(--line)] p-3"
    >
      <h3 className="font-semibold">Count the drawer and close</h3>
      <p className="text-sm text-[var(--muted)]">Count without looking at the expected amount, then save. Any difference is recorded for the owner.</p>
      <CashInputs usdValue={u} khrValue={k} onUsd={setU} onKhr={setK} />
      <input aria-label="Note" placeholder="Note (optional)" maxLength={255} value={note} onChange={(e) => setNote(e.target.value)}
        className="rounded-lg border border-[var(--line)] bg-transparent px-3 py-2.5" />
      {error && <p role="alert" className="rounded-lg bg-[var(--danger-bg)] p-2 text-sm text-[var(--danger)]">{error}</p>}
      <div className="grid grid-cols-2 gap-2">
        <button type="button" onClick={onCancel} className="rounded-xl border border-[var(--line)] px-3 py-2.5 font-semibold">Cancel</button>
        <button type="submit" disabled={busy || cents === null || riel === null} className="rounded-xl bg-[var(--fg)] px-3 py-2.5 font-semibold text-[var(--surface)] disabled:opacity-40">
          {busy ? "Closing..." : "Close shift"}
        </button>
      </div>
    </form>
  );
}
