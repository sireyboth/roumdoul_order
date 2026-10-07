"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { ArrowLeftRight, CircleCheck, ClipboardPen, Languages, Minus, Plus, Send, ShoppingBasket, StickyNote, Users, X } from "lucide-react";
import { SignedOut, staffApi, type CashierTables } from "@/lib/staff";
import type { CartLine, Category, Lang, MenuItem } from "@/lib/types";
import { newKey } from "@/lib/table-api";
import { formatMoney } from "@/lib/money";
import { pick } from "@/lib/i18n";
import ItemSheet from "../item-sheet";
import { Alert, Badge, inputClass } from "../ui";
import { BackLink, PAGE, SignedOutScreen } from "./chrome";

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

  if (signedOut) return <SignedOutScreen />;

  const currency = menu?.currency ?? "USD";
  const money = (minor: number) => formatMoney(minor, currency);
  const table = tables?.tables.find((t) => t.id === tableId) ?? null;
  const total = cart.reduce((n, l) => n + l.unitPrice * l.quantity, 0);
  const count = cart.reduce((n, l) => n + l.quantity, 0);

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
    <main className="flex min-h-dvh flex-col pb-44 lg:pb-12">
      <header className="sticky top-0 z-20 border-b border-[var(--line)] bg-[var(--surface)]/85 backdrop-blur-md">
        <div className={`${PAGE} flex items-center gap-3 py-3`}>
          <BackLink />
          <span className="grid size-10 place-items-center rounded-2xl bg-[var(--accent-soft)] text-[var(--warn)]">
            <ClipboardPen className="size-5" aria-hidden />
          </span>
          <h1 className="text-xl font-bold tracking-tight">New order</h1>
          {table && (
            <Badge tone="solid" className="anim-pop text-sm">
              {table.name}
            </Badge>
          )}
          <button
            type="button"
            onClick={() => setLang(lang === "km" ? "en" : "km")}
            className="ml-auto flex h-10 items-center gap-1.5 rounded-2xl border border-[var(--line)] bg-[var(--surface)] px-3 text-sm font-semibold transition hover:bg-[var(--surface-2)] active:scale-95"
          >
            <Languages className="size-4" aria-hidden />
            {lang === "km" ? "EN" : "ខ្មែរ"}
          </button>
        </div>
      </header>

      <div className={`${PAGE} mt-6 flex flex-col gap-4`}>
        {sent && (
          <p role="status" className="anim-scale-in flex items-center gap-3 rounded-2xl bg-[var(--brand-soft)] px-4 py-3 font-semibold text-[var(--brand-strong)]">
            <CircleCheck className="size-5 shrink-0" aria-hidden />
            <span className="flex-1">{sent}</span>
            <button type="button" onClick={() => setSent(null)} aria-label="Dismiss" className="grid size-8 place-items-center rounded-full transition hover:bg-[var(--brand)]/10">
              <X className="size-4" aria-hidden />
            </button>
          </p>
        )}
        {error && <Alert>{error}</Alert>}

        {!tables || !menu ? (
          <div className="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6 xl:grid-cols-8">
            {Array.from({ length: 12 }, (_, i) => (
              <div key={i} className="skeleton h-24 rounded-2xl" />
            ))}
          </div>
        ) : !table ? (
          <section className="flex flex-col gap-4">
            <div>
              <h2 className="text-2xl font-bold tracking-tight">Which table?</h2>
              <p className="text-[var(--muted)]">Tables with people already seated are green.</p>
            </div>
            <ul className="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6 xl:grid-cols-8">
              {tables.tables.map((t, i) => (
                <li key={t.id} className="anim-fade-up" style={{ "--i": Math.min(i, 16) } as React.CSSProperties}>
                  <button
                    type="button"
                    onClick={() => {
                      setTableId(t.id);
                      setSent(null);
                    }}
                    className={`flex w-full flex-col items-center gap-1 rounded-2xl border-2 px-2 py-5 text-center shadow-soft transition hover:-translate-y-0.5 hover:shadow-card active:scale-[0.97] ${
                      t.session ? "border-[var(--brand)] bg-[var(--brand-soft)]" : "border-transparent bg-[var(--surface)]"
                    }`}
                  >
                    <span className="block text-2xl font-bold">{t.name}</span>
                    <span className="flex items-center gap-1 text-xs text-[var(--muted)]">
                      {t.session && <Users className="size-3.5" aria-hidden />}
                      {t.session ? `${t.session.orders_count} order${t.session.orders_count === 1 ? "" : "s"}` : t.area ?? "Free"}
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          </section>
        ) : (
          <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_380px] xl:grid-cols-[minmax(0,1fr)_420px]">
            <div className="flex min-w-0 flex-col gap-6">
              <button
                type="button"
                onClick={() => setTableId(null)}
                className="flex items-center gap-1.5 self-start rounded-full bg-[var(--chip)] px-3.5 py-1.5 text-sm font-semibold transition hover:bg-[var(--line)]"
              >
                <ArrowLeftRight className="size-4" aria-hidden />
                Change table
              </button>
              {menu.categories.map((category, c) => (
                <section key={category.id} className="anim-fade-up flex flex-col gap-3" style={{ "--i": Math.min(c, 8) } as React.CSSProperties}>
                  <h2 className="text-lg font-bold">{pick(category.name, lang)}</h2>
                  <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                    {category.items.map((item) => {
                      const soldOut = item.sold_out_until !== null && new Date(item.sold_out_until).getTime() > now;
                      const inCart = cart.filter((l) => l.itemId === item.id).reduce((n, l) => n + l.quantity, 0);
                      return (
                        <li key={item.id}>
                          <button
                            type="button"
                            disabled={soldOut}
                            onClick={() => setOpenItem(item)}
                            className={`relative flex h-full min-h-24 w-full flex-col items-start justify-between gap-2 rounded-2xl border bg-[var(--surface)] p-3.5 text-left shadow-soft transition hover:-translate-y-0.5 hover:shadow-card active:scale-[0.98] disabled:translate-y-0 disabled:opacity-45 disabled:shadow-none ${
                              inCart > 0 ? "border-[var(--brand)]" : "border-transparent"
                            }`}
                          >
                            <span className="pr-6 leading-snug font-semibold">{pick(item.name, lang)}</span>
                            <span className={`text-sm font-semibold tabular-nums ${soldOut ? "text-[var(--danger)]" : "text-[var(--brand)]"}`}>
                              {soldOut ? "Sold out" : money(item.price)}
                            </span>
                            {inCart > 0 ? (
                              <span key={inCart} className="anim-pop absolute top-2.5 right-2.5 grid size-6 place-items-center rounded-full bg-[var(--brand)] text-xs font-bold text-white dark:text-[#06140f]">
                                {inCart}
                              </span>
                            ) : (
                              !soldOut && (
                                <span className="absolute top-2.5 right-2.5 grid size-6 place-items-center rounded-full bg-[var(--chip)] text-[var(--muted)]">
                                  <Plus className="size-3.5" aria-hidden />
                                </span>
                              )
                            )}
                          </button>
                        </li>
                      );
                    })}
                  </ul>
                </section>
              ))}
            </div>

            {/* Cart: a bar fixed at the bottom on phones, a sticky side panel on desktop. */}
            <aside
              className={`${cart.length === 0 ? "hidden lg:flex" : "anim-sheet-up flex lg:animate-none"} fixed inset-x-0 bottom-0 z-20 flex-col gap-3 rounded-t-3xl border-t border-[var(--line)] bg-[var(--surface)] px-4 pt-4 pb-[calc(env(safe-area-inset-bottom,0px)+14px)] shadow-float lg:sticky lg:inset-auto lg:top-24 lg:rounded-3xl lg:border lg:p-5 lg:shadow-card`}
            >
              <div className="flex items-center gap-2">
                <ShoppingBasket className="size-5 text-[var(--brand)]" aria-hidden />
                <h2 className="font-bold">Order for {table.name}</h2>
                {count > 0 && <Badge tone="brand" className="ml-auto tabular-nums">{count} item{count === 1 ? "" : "s"}</Badge>}
              </div>

              {cart.length === 0 ? (
                <div className="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-[var(--line)] px-4 py-10 text-center text-sm text-[var(--muted)]">
                  <ShoppingBasket className="size-7 opacity-50" aria-hidden />
                  Tap items on the left to add them.
                </div>
              ) : (
                <ul className="flex max-h-40 flex-col gap-2 overflow-y-auto text-sm lg:max-h-[45vh]">
                  {cart.map((line) => (
                    <li key={line.key} className="anim-fade-in flex items-center gap-2">
                      <span className="flex shrink-0 items-center gap-1 rounded-full bg-[var(--chip)] p-0.5">
                        <button type="button" aria-label="Less" onClick={() => changeQuantity(line.key, -1)} className="grid size-7 place-items-center rounded-full bg-[var(--surface)] shadow-soft transition active:scale-90">
                          <Minus className="size-3.5" aria-hidden />
                        </button>
                        <span className="w-5 text-center font-semibold tabular-nums">{line.quantity}</span>
                        <button type="button" aria-label="More" onClick={() => changeQuantity(line.key, 1)} className="grid size-7 place-items-center rounded-full bg-[var(--surface)] shadow-soft transition active:scale-90">
                          <Plus className="size-3.5" aria-hidden />
                        </button>
                      </span>
                      <span className="min-w-0 flex-1">
                        <span className="block truncate font-medium">{pick(line.name, lang)}</span>
                        {line.optionNames.length > 0 && <span className="block truncate text-xs text-[var(--muted)]">{line.optionNames.map((o) => pick(o, lang)).join(", ")}</span>}
                      </span>
                      <span className="font-semibold tabular-nums">{money(line.unitPrice * line.quantity)}</span>
                    </li>
                  ))}
                </ul>
              )}

              <label className="relative">
                <StickyNote className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-[var(--muted)]" aria-hidden />
                <input
                  placeholder="Note for the kitchen (optional)"
                  value={note}
                  maxLength={255}
                  onChange={(e) => setNote(e.target.value)}
                  className={`${inputClass} pl-10 text-sm`}
                />
              </label>
              <button
                type="button"
                disabled={busy || cart.length === 0}
                onClick={send}
                className="flex h-14 items-center justify-between gap-3 rounded-2xl bg-[var(--brand)] px-5 font-semibold text-white shadow-soft transition hover:bg-[var(--brand-strong)] active:scale-[0.98] disabled:opacity-45 dark:text-[#06140f]"
              >
                <span className="flex items-center gap-2">
                  <Send className="size-[18px]" aria-hidden />
                  {busy ? "Sending..." : `Send to kitchen · ${table.name}`}
                </span>
                <span className="text-lg tabular-nums">{money(total)}</span>
              </button>
            </aside>
          </div>
        )}
      </div>

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
