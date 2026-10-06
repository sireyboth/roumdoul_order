"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { SignedOut, staffApi, type CashierTables } from "@/lib/staff";
import type { CartLine, Category, Lang, MenuItem } from "@/lib/types";
import { newKey } from "@/lib/table-api";
import { formatMoney } from "@/lib/money";
import { pick } from "@/lib/i18n";
import ItemSheet from "../item-sheet";

type StaffMenu = { currency: "USD" | "KHR"; khr_per_usd: number; menu_version: string; categories: Category[] };

/** A waiter types in an order for a table: pick the table, add items, send to the kitchen. */
export default function WaiterOrder({ branchId }: { branchId: number }) {
  const [tables, setTables] = useState<CashierTables | null>(null);
  const [menu, setMenu] = useState<StaffMenu | null>(null);
  const [tableId, setTableId] = useState<number | null>(null);
  const [lang, setLang] = useState<Lang>("en");
  const [cart, setCart] = useState<CartLine[]>([]);
  const [note, setNote] = useState("");
  const [openItem, setOpenItem] = useState<MenuItem | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [sent, setSent] = useState<string | null>(null);
  const [signedOut, setSignedOut] = useState(false);
  // Checked when the menu loads; sold-out times are short-lived and the screen reloads after each order.
  const [now, setNow] = useState(() => Date.now());
  // Same key until the order goes through, so a retry never doubles the order.
  const pendingKey = useRef<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [t, m] = await Promise.all([
        staffApi<CashierTables>(`branches/${branchId}/tables`),
        staffApi<StaffMenu>(`branches/${branchId}/order-menu`),
      ]);
      setTables(t);
      setMenu(m);
      setNow(Date.now());
      setError(null);
    } catch (e) {
      if (e instanceof SignedOut) setSignedOut(true);
      else setError(e instanceof Error ? e.message : "Can't load the menu.");
    }
  }, [branchId]);

  useEffect(() => {
    const first = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(first);
  }, [load]);

  if (signedOut) {
    return (
      <main className="grid min-h-dvh place-items-center p-6">
        <Link href="/staff" className="rounded-xl bg-[var(--brand)] px-5 py-3 font-semibold text-white">Sign in again</Link>
      </main>
    );
  }

  const currency = menu?.currency ?? "USD";
  const money = (minor: number) => formatMoney(minor, currency);
  const table = tables?.tables.find((t) => t.id === tableId) ?? null;
  const total = cart.reduce((n, l) => n + l.unitPrice * l.quantity, 0);

  function addLine(line: CartLine) {
    pendingKey.current = null;
    setCart((current) => {
      const existing = current.find((l) => l.key === line.key);
      return existing
        ? current.map((l) => (l.key === line.key ? { ...l, quantity: l.quantity + line.quantity } : l))
        : [...current, line];
    });
  }

  function changeQuantity(key: string, delta: number) {
    pendingKey.current = null;
    setCart((current) => current.map((l) => (l.key === key ? { ...l, quantity: l.quantity + delta } : l)).filter((l) => l.quantity > 0));
  }

  async function send() {
    if (!tableId || cart.length === 0) return;
    setBusy(true);
    setError(null);
    pendingKey.current ??= newKey();
    try {
      const order = await staffApi<{ number: number }>(`branches/${branchId}/tables/${tableId}/orders`, {
        method: "POST",
        body: {
          idempotency_key: pendingKey.current,
          note: note.trim() || undefined,
          items: cart.map((l) => ({ menu_item_id: l.itemId, quantity: l.quantity, option_ids: l.optionIds, note: l.note || undefined })),
        },
      });
      pendingKey.current = null;
      setSent(`Order #${order.number} sent for ${table?.name ?? "the table"}`);
      setCart([]);
      setNote("");
      setTableId(null);
      void load();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not send the order.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="mx-auto flex min-h-dvh max-w-xl flex-col gap-4 px-4 pb-40">
      <header className="sticky top-0 z-10 -mx-4 flex items-center gap-3 border-b border-[var(--line)] bg-[var(--surface)] px-4 py-3">
        <Link href="/staff" className="text-sm text-[var(--muted)]">← Screens</Link>
        <h1 className="font-semibold">New order{table ? ` · ${table.name}` : ""}</h1>
        <button type="button" onClick={() => setLang(lang === "km" ? "en" : "km")}
          className="ml-auto rounded-full border border-[var(--line)] px-3 py-1 text-sm">{lang === "km" ? "EN" : "ខ្មែរ"}</button>
      </header>

      {sent && (
        <p role="status" className="flex items-center justify-between rounded-lg bg-[var(--brand-soft)] p-3 text-sm font-semibold text-[var(--brand)]">
          ✓ {sent}
          <button type="button" onClick={() => setSent(null)} className="text-xs underline">OK</button>
        </p>
      )}
      {error && <p role="alert" className="rounded-lg bg-[var(--danger-bg)] p-3 text-sm text-[var(--danger)]">{error}</p>}

      {!tables || !menu ? (
        <p className="text-[var(--muted)]">Loading...</p>
      ) : !table ? (
        <section className="flex flex-col gap-2">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-[var(--muted)]">Which table?</h2>
          <ul className="grid grid-cols-3 gap-2 sm:grid-cols-4">
            {tables.tables.map((t) => (
              <li key={t.id}>
                <button type="button" onClick={() => { setTableId(t.id); setSent(null); }}
                  className={`w-full rounded-xl border-2 px-2 py-4 text-center ${t.session ? "border-[var(--brand)] bg-[var(--brand-soft)]" : "border-[var(--line)]"}`}>
                  <span className="block text-xl font-bold">{t.name}</span>
                  <span className="text-xs text-[var(--muted)]">{t.session ? `${t.session.orders_count} order${t.session.orders_count === 1 ? "" : "s"}` : t.area ?? "Free"}</span>
                </button>
              </li>
            ))}
          </ul>
        </section>
      ) : (
        <>
          <button type="button" onClick={() => setTableId(null)} className="self-start text-sm text-[var(--brand)] underline">Change table</button>
          {menu.categories.map((category) => (
            <section key={category.id}>
              <h2 className="mb-1 font-semibold">{pick(category.name, lang)}</h2>
              <ul className="grid grid-cols-2 gap-2">
                {category.items.map((item) => {
                  const soldOut = item.sold_out_until !== null && new Date(item.sold_out_until).getTime() > now;
                  return (
                    <li key={item.id}>
                      <button type="button" disabled={soldOut} onClick={() => setOpenItem(item)}
                        className="flex h-full w-full flex-col items-start rounded-lg border border-[var(--line)] p-2.5 text-left disabled:opacity-40">
                        <span className="font-medium leading-snug">{pick(item.name, lang)}</span>
                        <span className="text-sm tabular-nums text-[var(--muted)]">{soldOut ? "Sold out" : money(item.price)}</span>
                      </button>
                    </li>
                  );
                })}
              </ul>
            </section>
          ))}
        </>
      )}

      {table && cart.length > 0 && (
        <div className="fixed inset-x-0 bottom-0 z-20 border-t border-[var(--line)] bg-[var(--surface)] px-4 pt-3 pb-[calc(env(safe-area-inset-bottom,0px)+12px)] shadow-lg">
          <div className="mx-auto flex max-w-xl flex-col gap-2">
            <ul className="flex max-h-40 flex-col gap-1 overflow-y-auto text-sm">
              {cart.map((line) => (
                <li key={line.key} className="flex items-center gap-2">
                  <button type="button" aria-label="Less" onClick={() => changeQuantity(line.key, -1)} className="size-7 rounded-full border border-[var(--line)]">−</button>
                  <span className="w-5 text-center tabular-nums">{line.quantity}</span>
                  <button type="button" aria-label="More" onClick={() => changeQuantity(line.key, 1)} className="size-7 rounded-full border border-[var(--line)]">+</button>
                  <span className="min-w-0 flex-1 truncate">
                    {pick(line.name, lang)}
                    {line.optionNames.length > 0 && <span className="text-[var(--muted)]"> · {line.optionNames.map((o) => pick(o, lang)).join(", ")}</span>}
                  </span>
                  <span className="tabular-nums">{money(line.unitPrice * line.quantity)}</span>
                </li>
              ))}
            </ul>
            <input placeholder="Note for the kitchen (optional)" value={note} maxLength={255} onChange={(e) => setNote(e.target.value)}
              className="rounded-lg border border-[var(--line)] bg-transparent px-3 py-2 text-sm" />
            <button type="button" disabled={busy} onClick={send}
              className="flex items-center justify-between rounded-xl bg-[var(--brand)] px-4 py-3 font-semibold text-white disabled:opacity-50">
              <span>{busy ? "Sending..." : `Send to kitchen · ${table.name}`}</span>
              <span className="tabular-nums">{money(total)}</span>
            </button>
          </div>
        </div>
      )}

      {openItem && (
        <ItemSheet
          item={openItem}
          lang={lang}
          currency={currency}
          onClose={() => setOpenItem(null)}
          onAdd={(line) => {
            addLine(line);
            setOpenItem(null);
          }}
        />
      )}
    </main>
  );
}
