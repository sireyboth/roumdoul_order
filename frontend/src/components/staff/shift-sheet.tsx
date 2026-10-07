"use client";

import { useState } from "react";
import { ArrowDownToLine, ArrowRightLeft, ArrowUpFromLine, CircleAlert, CircleCheck, Lock, Play, Wallet, X } from "lucide-react";
import { staffApi, type Shift, type ShiftState } from "@/lib/staff";
import { formatMoney, parseAmount } from "@/lib/money";
import Sheet from "../sheet";
import { Alert, Badge, Button, inputClass } from "../ui";

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
    <Sheet onClose={onClose} label="Cash drawer" size="lg">
      <div className="flex items-center gap-3 sm:pr-10">
        <span className={`grid size-12 shrink-0 place-items-center rounded-2xl ${shift ? "bg-[var(--brand-soft)] text-[var(--brand)]" : "bg-[var(--chip)] text-[var(--muted)]"}`}>
          <Wallet className="size-6" aria-hidden />
        </span>
        <div className="min-w-0 flex-1">
          <h2 className="text-xl font-bold tracking-tight">Cash drawer</h2>
          <p className="text-sm text-[var(--muted)]">
            {shift ? `Shift open since ${new Date(shift.opened_at).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })} · ${shift.opened_by ?? ""}` : "No shift open"}
          </p>
        </div>
        <button type="button" onClick={onClose} aria-label="Close" className="grid size-9 place-items-center rounded-full bg-[var(--chip)] text-[var(--muted)] sm:hidden">
          <X className="size-4" aria-hidden />
        </button>
      </div>

      {justClosed && <Result shift={justClosed} />}

      {!shift ? (
        <>
          {!justClosed && state.last_closed && (
            <p className="rounded-2xl bg-[var(--chip)] px-4 py-3 text-sm text-[var(--muted)]">
              Last shift closed {new Date(state.last_closed.closed_at ?? "").toLocaleString()} with{" "}
              {usd(state.last_closed.counted_usd ?? 0)} · {khr(state.last_closed.counted_khr ?? 0)} counted.
            </p>
          )}
          <OpenForm
            onOpen={async (u, k, note) => {
              await post(`branches/${branchId}/shift`, { opening_cash_usd: u, opening_cash_khr: k, note });
              setJustClosed(null);
            }}
          />
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
              <Button tone="neutral" size="lg" icon={ArrowRightLeft} onClick={() => setPanel("move")}>
                Cash in / out
              </Button>
              <Button tone="dark" size="lg" icon={Lock} onClick={() => setPanel("close")}>
                Count &amp; close shift
              </Button>
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
    <div className="anim-fade-in flex flex-col gap-4">
      <div className="grid grid-cols-2 gap-3">
        <div className="rounded-2xl bg-[var(--brand-soft)] px-4 py-3">
          <p className="text-xs font-semibold tracking-wide text-[var(--brand-strong)] uppercase">Should be in drawer · $</p>
          <p className="text-2xl font-bold tabular-nums">{usd(s.expected_usd)}</p>
        </div>
        <div className="rounded-2xl bg-[var(--brand-soft)] px-4 py-3">
          <p className="text-xs font-semibold tracking-wide text-[var(--brand-strong)] uppercase">Should be in drawer · ៛</p>
          <p className="text-2xl font-bold tabular-nums">{khr(s.expected_khr)}</p>
        </div>
      </div>

      <div className="overflow-hidden rounded-2xl border border-[var(--line)]">
        <table className="w-full text-sm tabular-nums">
          <thead className="bg-[var(--surface-2)]">
            <tr className="text-left text-[var(--muted)]">
              <th className="px-4 py-2 font-medium" />
              <th className="px-4 py-2 text-right font-medium">Dollars</th>
              <th className="px-4 py-2 text-right font-medium">Riel</th>
            </tr>
          </thead>
          <tbody>
            {rows.filter(([, u, k]) => u !== 0 || k !== 0).map(([label, u, k]) => (
              <tr key={label} className="border-t border-[var(--line)]">
                <td className="px-4 py-2">{label}</td>
                <td className="px-4 py-2 text-right">{usd(u)}</td>
                <td className="px-4 py-2 text-right">{khr(k)}</td>
              </tr>
            ))}
            <tr className="border-t-2 border-[var(--fg)] text-base font-bold">
              <td className="px-4 py-2.5">Should be in drawer</td>
              <td className="px-4 py-2.5 text-right">{usd(s.expected_usd)}</td>
              <td className="px-4 py-2.5 text-right">{khr(s.expected_khr)}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div className="flex flex-wrap gap-2 text-sm">
        {methods.length === 0 ? (
          <span className="text-[var(--muted)]">No payments yet this shift.</span>
        ) : (
          methods.map(([method, row]) => (
            <Badge key={method} tone="neutral" className="px-3 py-1 text-sm text-[var(--fg)]">
              <strong className="uppercase">{method}</strong> {row.count} · {formatMoney(row.amount, currency)}
            </Badge>
          ))
        )}
        {s.refunds_count > 0 && (
          <Badge tone="danger" className="px-3 py-1 text-sm">
            {s.refunds_count} refund(s)
          </Badge>
        )}
      </div>

      {shift.movements.length > 0 && (
        <ul className="flex flex-col gap-1.5 text-sm">
          {shift.movements.map((m) => (
            <li key={m.id} className="flex items-center justify-between gap-2 rounded-2xl border border-[var(--line)] px-4 py-2">
              <span className="flex items-center gap-2">
                {m.type === "in" ? (
                  <ArrowDownToLine className="size-4 text-[var(--brand)]" aria-hidden />
                ) : (
                  <ArrowUpFromLine className="size-4 text-[var(--danger)]" aria-hidden />
                )}
                <span className="font-medium">{m.type === "in" ? "In" : "Out"}</span> · {m.reason}
              </span>
              <span className="font-semibold tabular-nums">{formatMoney(m.amount, m.currency)}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function Result({ shift }: { shift: Shift }) {
  const du = shift.difference_usd ?? 0;
  const dk = shift.difference_khr ?? 0;
  const ok = du === 0 && dk === 0;
  const Icon = ok ? CircleCheck : CircleAlert;
  return (
    <div className={`anim-scale-in flex gap-3 rounded-3xl p-5 ${ok ? "bg-[var(--brand)] text-white dark:text-[#06140f]" : "bg-[var(--warn-bg)] text-[var(--fg)]"}`}>
      <Icon className={`size-7 shrink-0 ${ok ? "" : "text-[var(--warn)]"}`} aria-hidden />
      <div>
        <p className="text-lg font-bold">{ok ? "Shift closed. Drawer is exact" : "Shift closed with a difference"}</p>
        <p className="text-sm opacity-90">
          Expected {usd(shift.summary.expected_usd)} · {khr(shift.summary.expected_khr)}; counted {usd(shift.counted_usd ?? 0)} · {khr(shift.counted_khr ?? 0)}
        </p>
        {!ok && (
          <p className="mt-1 font-semibold">
            Difference: {du > 0 ? "+" : ""}
            {usd(du)} · {dk > 0 ? "+" : ""}
            {khr(dk)} {du < 0 || dk < 0 ? "(short)" : "(over)"}
          </p>
        )}
      </div>
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

const FORM = "anim-scale-in flex flex-col gap-3 rounded-3xl border border-[var(--line)] bg-[var(--surface-2)] p-4";

function CashInputs({ usdValue, khrValue, onUsd, onKhr }: { usdValue: string; khrValue: string; onUsd: (v: string) => void; onKhr: (v: string) => void }) {
  return (
    <div className="grid grid-cols-2 gap-3">
      <label className="flex flex-col gap-1.5 text-sm">
        <span className="font-medium text-[var(--muted)]">Dollars ($)</span>
        <input inputMode="decimal" aria-label="Dollars" placeholder="0.00" value={usdValue} onChange={(e) => onUsd(e.target.value)} className={`${inputClass} h-14 text-xl font-semibold tabular-nums`} />
      </label>
      <label className="flex flex-col gap-1.5 text-sm">
        <span className="font-medium text-[var(--muted)]">Riel (៛)</span>
        <input inputMode="numeric" aria-label="Riel" placeholder="0" value={khrValue} onChange={(e) => onKhr(e.target.value)} className={`${inputClass} h-14 text-xl font-semibold tabular-nums`} />
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
      className={FORM}
    >
      <h3 className="font-bold">Start a shift</h3>
      <p className="text-sm text-[var(--muted)]">Count the cash in the drawer now. Payments can be taken once the shift is open.</p>
      <CashInputs usdValue={u} khrValue={k} onUsd={setU} onKhr={setK} />
      {error && <Alert>{error}</Alert>}
      <Button type="submit" size="lg" icon={Play} disabled={busy || cents === null || riel === null}>
        {busy ? "Starting..." : "Start shift"}
      </Button>
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
      className={FORM}
    >
      <h3 className="font-bold">Cash in or out</h3>
      <div className="flex flex-wrap gap-2">
        {(["out", "in"] as const).map((t) => {
          const Icon = t === "out" ? ArrowUpFromLine : ArrowDownToLine;
          return (
            <button
              key={t}
              type="button"
              aria-pressed={type === t}
              onClick={() => setType(t)}
              className={`flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-semibold transition ${type === t ? "bg-[var(--brand)] text-white shadow-soft dark:text-[#06140f]" : "bg-[var(--chip)] hover:bg-[var(--line)]"}`}
            >
              <Icon className="size-4" aria-hidden />
              {t === "out" ? "Take out" : "Put in"}
            </button>
          );
        })}
        <span className="mx-1 w-px bg-[var(--line)]" aria-hidden />
        {(["USD", "KHR"] as const).map((c) => (
          <button
            key={c}
            type="button"
            aria-pressed={cur === c}
            onClick={() => setCur(c)}
            className={`rounded-full px-3.5 py-1.5 text-sm font-semibold transition ${cur === c ? "bg-[var(--fg)] text-[var(--surface)]" : "bg-[var(--chip)] hover:bg-[var(--line)]"}`}
          >
            {c === "USD" ? "$" : "៛"}
          </button>
        ))}
      </div>
      <input inputMode="decimal" aria-label="Amount" placeholder={cur === "USD" ? "5.00" : "20000"} value={typed} onChange={(e) => setTyped(e.target.value)} className={`${inputClass} h-14 text-xl font-semibold tabular-nums`} />
      <input aria-label="What for" placeholder="What for? (e.g. ice delivery, more small notes)" maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} className={inputClass} />
      {error && <Alert>{error}</Alert>}
      <div className="grid grid-cols-2 gap-2">
        <Button tone="neutral" onClick={onCancel}>
          Cancel
        </Button>
        <Button type="submit" disabled={busy || !amount || reason.trim() === ""}>
          {busy ? "Saving..." : "Save"}
        </Button>
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
      className={FORM}
    >
      <h3 className="font-bold">Count the drawer and close</h3>
      <p className="text-sm text-[var(--muted)]">Count without looking at the expected amount, then save. Any difference is recorded for the owner.</p>
      <CashInputs usdValue={u} khrValue={k} onUsd={setU} onKhr={setK} />
      <input aria-label="Note" placeholder="Note (optional)" maxLength={255} value={note} onChange={(e) => setNote(e.target.value)} className={inputClass} />
      {error && <Alert>{error}</Alert>}
      <div className="grid grid-cols-2 gap-2">
        <Button tone="neutral" onClick={onCancel}>
          Cancel
        </Button>
        <Button type="submit" tone="dark" icon={Lock} disabled={busy || cents === null || riel === null}>
          {busy ? "Closing..." : "Close shift"}
        </Button>
      </div>
    </form>
  );
}
