"use client";

/* eslint-disable @next/next/no-img-element -- menu photos come from the restaurant's own storage */

import {
  Armchair,
  ChevronRight,
  ConciergeBell,
  Languages,
  MapPin,
  Plus,
  ReceiptText,
  Search,
  ShoppingBag,
  Sparkles,
  UtensilsCrossed,
  X,
} from "lucide-react";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import type { CartLine, Category, Lang, MenuItem, TableMenu, TableSessionState } from "@/lib/types";
import { ApiError, getSession, newKey, placeOrder, requestService } from "@/lib/table-api";
import { LocationError, forgetPhoneLocation, getPhoneLocation, type PhoneLocation } from "@/lib/location";
import LocationSheet, { type LocStage } from "./location-sheet";
import { formatMoney, percentOf, toRiel } from "@/lib/money";
import { pick, t } from "@/lib/i18n";
import ItemSheet, { canQuickAdd, defaultsFor, lineFor } from "./item-sheet";
import CartSheet, { CartPanel } from "./cart-sheet";
import OrdersSheet from "./orders-sheet";
import Sheet from "./sheet";
import { SuccessMark } from "./ui";
import SiteFooter from "./site-footer";
import ThemeToggle from "./theme-toggle";
import { useLive } from "@/lib/live";

function isSoldOut(item: MenuItem, now: number): boolean {
  return item.sold_out_until !== null && new Date(item.sold_out_until).getTime() > now;
}

function readStorage<T>(key: string, fallback: T): T {
  try {
    const raw = window.localStorage.getItem(key);
    return raw ? (JSON.parse(raw) as T) : fallback;
  } catch {
    return fallback;
  }
}

function writeStorage(key: string, value: unknown) {
  try {
    window.localStorage.setItem(key, JSON.stringify(value));
  } catch {
    /* private mode: the cart just won't survive a reload */
  }
}

function matches(item: MenuItem, query: string): boolean {
  const q = query.trim().toLowerCase();
  if (!q) return true;
  return [item.name.km, item.name.en, item.name.zh, item.description.km, item.description.en].some((s) => s?.toLowerCase().includes(q));
}

export default function MenuApp({ menu, token }: { menu: TableMenu; token: string }) {
  const { company } = menu;
  const [lang, setLang] = useState<Lang>("km");
  const [cart, setCart] = useState<CartLine[]>([]);
  const [openItem, setOpenItem] = useState<MenuItem | null>(null);
  const [cartOpen, setCartOpen] = useState(false);
  const [activeCategory, setActiveCategory] = useState<number | null>(menu.categories[0]?.id ?? null);
  const [query, setQuery] = useState("");
  const [now, setNow] = useState(() => Date.now());
  const [session, setSession] = useState<TableSessionState | null>(null);
  const [ordersOpen, setOrdersOpen] = useState(false);
  const [justSent, setJustSent] = useState(false);
  const [sending, setSending] = useState(false);
  const [sendError, setSendError] = useState<string | null>(null);
  const [calling, setCalling] = useState<"waiter" | "bill" | null>(null);
  const [toast, setToast] = useState<string | null>(null);
  const [thanks, setThanks] = useState(false);
  const [upsell, setUpsell] = useState<{ item: MenuItem; list: MenuItem[] } | null>(null);
  const [locSheet, setLocSheet] = useState<{ stage: LocStage; retry: () => void } | null>(null);
  // One key per cart submission: a retry after a dropped connection can never create a second order.
  const pendingKey = useRef<string | null>(null);
  const sectionRefs = useRef<Record<number, HTMLElement | null>>({});
  const chipRefs = useRef<Record<number, HTMLButtonElement | null>>({});
  const jumping = useRef(false);
  // "Goes well with" shows once per item per visit.
  const upsellShown = useRef<Set<number>>(new Set());
  const cartKey = `tok-cart-${token}-${menu.menu_version.split(".")[0]}`;
  // Remembers that this phone took part in the current visit, so only it says "Paid, thank you".
  const visitKey = `tok-visit-${token}`;

  // Restore language and cart after the first render (server HTML has no access to storage).
  // Reading storage during render would make server and client HTML differ, so it happens once here.
  // Saving waits until restoring is done, or the empty first render would overwrite what was saved.
  const [restored, setRestored] = useState(false);
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setLang(readStorage<Lang>("tok-lang", "km"));
    setCart(readStorage<CartLine[]>(cartKey, []));
    setRestored(true);
  }, [cartKey]);

  useEffect(() => {
    if (restored) writeStorage(cartKey, cart);
  }, [cart, cartKey, restored]);
  useEffect(() => {
    if (restored) writeStorage("tok-lang", lang);
    document.documentElement.lang = lang;
  }, [lang, restored]);

  const refreshSession = useCallback(async () => {
    try {
      const next = await getSession(token);
      setSession(next);
      if (next.status === "open" || next.status === "bill_requested") {
        writeStorage(visitKey, true);
      } else if (next.status === "none" && readStorage<boolean>(visitKey, false)) {
        writeStorage(visitKey, false);
        if (next.last_visit?.result === "paid") {
          setOrdersOpen(false);
          setThanks(true);
        }
      }
    } catch {
      /* keep the last known state; the next poll will try again */
    }
  }, [token, visitKey]);

  // Order status updates: instantly when live updates work, otherwise every 5 seconds while visible.
  const live = useLive(session?.live_channel ?? null, false, () => void refreshSession());
  const pollMs = live ? 30_000 : 5000;

  useEffect(() => {
    const first = window.setTimeout(() => void refreshSession(), 0);
    const id = window.setInterval(() => {
      if (document.visibilityState === "visible") void refreshSession();
    }, pollMs);
    return () => {
      window.clearTimeout(first);
      window.clearInterval(id);
    };
  }, [refreshSession, pollMs]);

  useEffect(() => {
    if (!toast) return;
    const id = window.setTimeout(() => setToast(null), 3000);
    return () => window.clearTimeout(id);
  }, [toast]);

  // Sold-out times expire on their own; re-check every minute.
  useEffect(() => {
    const id = window.setInterval(() => setNow(Date.now()), 60_000);
    return () => window.clearInterval(id);
  }, []);

  // Highlight the category being read while scrolling.
  useEffect(() => {
    if (query) return;
    const observer = new IntersectionObserver(
      (entries) => {
        if (jumping.current) return;
        const visible = entries.filter((e) => e.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0];
        const id = visible ? Number((visible.target as HTMLElement).dataset.category) : null;
        if (id) setActiveCategory(id);
      },
      { rootMargin: "-25% 0px -65% 0px" },
    );
    Object.values(sectionRefs.current).forEach((el) => el && observer.observe(el));
    return () => observer.disconnect();
  }, [query]);

  // Keep the active chip in view on phones.
  useEffect(() => {
    if (activeCategory) chipRefs.current[activeCategory]?.scrollIntoView({ behavior: "smooth", inline: "center", block: "nearest" });
  }, [activeCategory]);

  /**
   * Branches that only take QR orders from inside the shop: get the phone's location first,
   * then run the action with it. Problems (blocked, no GPS, too far) open the location sheet.
   */
  function withLocation(action: (location: PhoneLocation | undefined) => Promise<void>) {
    if (!menu.branch.location_required) {
      void action(undefined);
      return;
    }

    const attempt = async () => {
      setLocSheet({ stage: "checking", retry: attempt });
      let location: PhoneLocation;
      try {
        location = await getPhoneLocation();
      } catch (e) {
        setLocSheet({ stage: e instanceof LocationError ? e.problem : "unavailable", retry: attempt });
        return;
      }
      writeStorage("tok-loc-ok", true);
      setLocSheet(null);
      try {
        await action(location);
      } catch (e) {
        if (e instanceof ApiError && e.code === "location_too_far") {
          // Maybe a bad first GPS fix: the next try asks the phone again.
          forgetPhoneLocation();
          setLocSheet({ stage: "too_far", retry: attempt });
        } else if (e instanceof ApiError && e.code === "location_required") {
          setLocSheet({ stage: "ask", retry: attempt });
        }
      }
    };

    // Explain once before the browser's own permission question; after that, just check.
    if (readStorage<boolean>("tok-loc-ok", false)) void attempt();
    else setLocSheet({ stage: "ask", retry: attempt });
  }

  function sendOrder(note: string) {
    withLocation(async (location) => {
      setSending(true);
      setSendError(null);
      pendingKey.current ??= newKey();

      try {
        await placeOrder(token, {
          idempotency_key: pendingKey.current,
          note: note.trim() || undefined,
          items: cart.map((line) => ({
            menu_item_id: line.itemId,
            quantity: line.quantity,
            option_ids: line.optionIds,
            note: line.note || undefined,
          })),
          location,
        });
        pendingKey.current = null;
        setCart([]);
        setCartOpen(false);
        await refreshSession();
        setJustSent(true);
        setOrdersOpen(true);
      } catch (e) {
        if (e instanceof ApiError && e.code?.startsWith("location")) throw e;
        setSendError(e instanceof Error && e.message !== "Request failed" ? e.message : t("tryAgain", lang));
      } finally {
        setSending(false);
      }
    });
  }

  function call(type: "waiter" | "bill") {
    withLocation(async (location) => {
      setCalling(type);
      try {
        await requestService(token, type, location);
        setToast(type === "waiter" ? t("waiterComing", lang) : t("billComing", lang));
        await refreshSession();
      } catch (e) {
        if (e instanceof ApiError && e.code?.startsWith("location")) throw e;
        setToast(t("tryAgain", lang));
      } finally {
        setCalling(null);
      }
    });
  }

  const orderCount = session?.orders.length ?? 0;
  const waiterCalled = session?.requests.some((r) => r.type === "waiter") ?? false;
  const billCalled = (session?.requests.some((r) => r.type === "bill") ?? false) || session?.status === "bill_requested";

  const count = cart.reduce((n, line) => n + line.quantity, 0);
  const subtotal = cart.reduce((n, line) => n + line.unitPrice * line.quantity, 0);

  const totals = useMemo(() => {
    const service = percentOf(subtotal, company.service_charge_bp);
    const vat = company.prices_include_vat ? 0 : percentOf(subtotal + service, company.vat_bp);
    return { service, vat, total: subtotal + service + vat };
  }, [subtotal, company]);

  const allItems = useMemo(() => menu.categories.flatMap((c) => c.items.map((item) => ({ item, categoryId: c.id }))), [menu.categories]);
  const byId = useMemo(() => new Map(allItems.map(({ item }) => [item.id, item])), [allItems]);
  const categoryOf = useMemo(() => new Map(allItems.map(({ item, categoryId }) => [item.id, categoryId])), [allItems]);
  const qtyInCart = useMemo(() => {
    const m = new Map<number, number>();
    cart.forEach((l) => m.set(l.itemId, (m.get(l.itemId) ?? 0) + l.quantity));
    return m;
  }, [cart]);

  /**
   * What to suggest next to `items`: the owner's "Goes well with" picks first; when there are
   * none, one dish from each other category (photos first), so the pop-up is never empty.
   */
  const suggest = useCallback(
    (items: MenuItem[], exclude: Set<number>, limit: number): MenuItem[] => {
      const ok = (i: MenuItem | undefined): i is MenuItem => !!i && !exclude.has(i.id) && !isSoldOut(i, now);
      const picked = new Map<number, MenuItem>();
      items.flatMap((i) => i.suggestions ?? []).forEach((id) => {
        const s = byId.get(id);
        if (ok(s)) picked.set(s.id, s);
      });
      if (picked.size === 0) {
        const used = new Set(items.map((i) => categoryOf.get(i.id)));
        menu.categories
          .filter((c) => !used.has(c.id))
          .forEach((c) => {
            const best = [...c.items].sort((a, b) => Number(!!b.image_url) - Number(!!a.image_url)).find(ok);
            if (best) picked.set(best.id, best);
          });
      }
      return [...picked.values()].slice(0, limit);
    },
    [byId, categoryOf, menu.categories, now],
  );

  const cartSuggestions = useMemo(() => {
    const items = cart.map((l) => byId.get(l.itemId)).filter((i): i is MenuItem => !!i);
    return items.length ? suggest(items, new Set(items.map((i) => i.id)), 6) : [];
  }, [cart, byId, suggest]);

  function addLine(line: CartLine, offerUpsell = true) {
    pendingKey.current = null;
    setCart((current) => {
      const existing = current.find((l) => l.key === line.key);
      if (existing) {
        return current.map((l) => (l.key === line.key ? { ...l, quantity: l.quantity + line.quantity } : l));
      }
      return [...current, line];
    });

    const item = byId.get(line.itemId);
    if (!offerUpsell || !item || upsellShown.current.has(item.id)) return;
    upsellShown.current.add(item.id);
    const list = suggest([item], new Set([...cart.map((l) => l.itemId), item.id]), 3);
    if (list.length > 0) setUpsell({ item, list });
  }

  /** "+" on a card or a suggestion: one tap for simple items, the item sheet for items with choices. */
  function quickAdd(item: MenuItem, offerUpsell = true) {
    if (canQuickAdd(item)) {
      addLine(lineFor(item, defaultsFor(item.option_groups), 1, ""), offerUpsell);
      setToast(`${t("addedToOrder", lang)} · ${pick(item.name, lang)}`);
    } else {
      setOpenItem(item);
    }
  }

  function changeQuantity(key: string, delta: number) {
    pendingKey.current = null;
    setCart((current) =>
      current.map((l) => (l.key === key ? { ...l, quantity: l.quantity + delta } : l)).filter((l) => l.quantity > 0),
    );
  }

  function jumpTo(categoryId: number) {
    setQuery("");
    setActiveCategory(categoryId);
    jumping.current = true;
    sectionRefs.current[categoryId]?.scrollIntoView({ behavior: "smooth", block: "start" });
    window.setTimeout(() => (jumping.current = false), 700);
  }

  const money = (minor: number) => formatMoney(minor, company.currency);
  const riel = company.currency === "USD" ? toRiel(totals.total, company.khr_per_usd) : null;

  const visible: Category[] = query
    ? menu.categories.map((c) => ({ ...c, items: c.items.filter((i) => matches(i, query)) })).filter((c) => c.items.length > 0)
    : menu.categories;

  const cartProps = {
    lines: cart,
    lang,
    currency: company.currency,
    subtotal,
    service: totals.service,
    vat: totals.vat,
    total: totals.total,
    riel,
    sending,
    error: sendError,
    suggestions: cartSuggestions,
    onPickSuggestion: (item: MenuItem) => quickAdd(item, false),
    onChangeQuantity: changeQuantity,
    onSend: sendOrder,
  };

  return (
    <div className="palette-roumdoul flex min-h-dvh flex-col">
      {/* ---------- Hero: the restaurant's own cover, logo and name ---------- */}
      <header className="relative">
        <div className="relative h-48 overflow-hidden sm:h-64 lg:h-72">
          {company.cover_url ? (
            <img src={company.cover_url} alt="" className="absolute inset-0 size-full scale-105 object-cover" />
          ) : (
            <div
              className="absolute inset-0 bg-[var(--hero-from)]"
              style={{
                backgroundImage:
                  "radial-gradient(circle at 15% 20%, rgb(255 255 255 / .16) 0 2px, transparent 3px), radial-gradient(circle at 80% 0%, rgb(231 163 62 / .55), transparent 45%), linear-gradient(135deg, var(--hero-from) 0%, var(--hero-via) 55%, var(--hero-to) 100%)",
                backgroundSize: "28px 28px, 100% 100%, 100% 100%",
              }}
            />
          )}
          <div className="absolute inset-0 bg-gradient-to-b from-black/35 via-black/10 to-transparent" />
          {/* Fade into the page so the restaurant name reads well in light and dark */}
          <div className="absolute inset-x-0 bottom-0 h-24 bg-gradient-to-t from-[var(--bg)] to-transparent sm:h-28" />

          <div className="relative mx-auto flex h-full w-full max-w-[1440px] items-start justify-end gap-2 px-4 pt-[calc(env(safe-area-inset-top,0px)+14px)] sm:px-6 lg:px-10">
            <button
              type="button"
              onClick={() => setLang(lang === "km" ? "en" : "km")}
              className="flex h-10 items-center gap-2 rounded-full bg-white/15 px-4 text-sm font-semibold text-white ring-1 ring-white/25 backdrop-blur-md transition hover:bg-white/25"
              aria-label="Change language"
            >
              <Languages className="size-4" />
              {lang === "km" ? "English" : "ខ្មែរ"}
            </button>
            <ThemeToggle tone="glass" />
          </div>
        </div>

        <div className="relative mx-auto -mt-16 flex w-full max-w-[1440px] items-end gap-4 px-4 sm:-mt-20 sm:px-6 lg:px-10">
          <div className="anim-scale-in shrink-0 rounded-[28px] bg-[var(--surface)] p-1.5 shadow-float">
            {company.logo_url ? (
              <img src={company.logo_url} alt={company.name} className="size-24 rounded-3xl object-cover sm:size-32" />
            ) : (
              <div className="grid size-24 place-items-center rounded-3xl bg-[var(--brand)] text-4xl font-bold text-white sm:size-32 sm:text-5xl dark:text-[#06140f]">
                {company.name.slice(0, 1)}
              </div>
            )}
          </div>
          <div className="anim-fade-up min-w-0 flex-1 pb-1" style={{ "--i": 2 } as React.CSSProperties}>
            <h1 className="truncate text-2xl leading-tight font-bold text-[var(--fg)] sm:text-4xl">{company.name}</h1>
            {company.tagline && <p className="truncate text-[var(--muted)] sm:text-lg">{company.tagline}</p>}
          </div>
        </div>

        <div className="mx-auto mt-4 flex w-full max-w-[1440px] flex-wrap items-center gap-2 px-4 sm:px-6 lg:px-10">
          <span className="inline-flex items-center gap-1.5 rounded-full bg-[var(--brand)] px-3.5 py-1.5 text-sm font-bold text-white dark:text-[#06140f]">
            <Armchair className="size-4" />
            {t("table", lang)} {menu.table.name}
            {menu.table.area ? <span className="font-medium opacity-80">· {menu.table.area}</span> : null}
          </span>
          <span className="inline-flex items-center gap-1.5 rounded-full bg-[var(--surface)] px-3.5 py-1.5 text-sm font-medium text-[var(--muted)] shadow-soft">
            <MapPin className="size-4" />
            {menu.branch.name}
            {menu.branch.address ? <span className="hidden sm:inline"> · {menu.branch.address}</span> : null}
          </span>
        </div>
      </header>

      <div className="mx-auto w-full max-w-[1440px] px-4 pb-12 sm:px-6 lg:px-10">
        {/* ---------- Service buttons + search ---------- */}
        <div className="mt-5 flex flex-col gap-3 lg:flex-row lg:items-center">
          <div className="grid grid-cols-2 gap-2 sm:flex">
            <button
              type="button"
              disabled={waiterCalled || calling !== null}
              onClick={() => call("waiter")}
              className="flex h-12 items-center justify-center gap-2 rounded-2xl bg-[var(--surface)] px-4 text-sm font-bold shadow-soft ring-1 ring-[var(--line)] transition hover:-translate-y-0.5 hover:shadow-card active:scale-[0.98] disabled:opacity-70 disabled:hover:translate-y-0"
            >
              <ConciergeBell className={`size-5 text-[var(--brand)] ${waiterCalled ? "anim-wiggle" : ""}`} />
              {waiterCalled ? t("waiterComing", lang) : t("callWaiter", lang)}
            </button>
            {orderCount > 0 ? (
              <button
                type="button"
                onClick={() => {
                  setJustSent(false);
                  setOrdersOpen(true);
                }}
                className="anim-scale-in flex h-12 items-center justify-center gap-2 rounded-2xl bg-[var(--brand-soft)] px-4 text-sm font-bold text-[var(--brand-strong)] transition hover:-translate-y-0.5 active:scale-[0.98]"
              >
                <ReceiptText className="size-5" />
                {t("myOrders", lang)}
                <span className="grid size-6 place-items-center rounded-full bg-[var(--brand)] text-xs text-white dark:text-[#06140f]">{orderCount}</span>
              </button>
            ) : (
              <button
                type="button"
                disabled
                className="flex h-12 items-center justify-center gap-2 rounded-2xl bg-[var(--chip)] px-4 text-sm font-semibold text-[var(--muted)] sm:hidden"
              >
                <ReceiptText className="size-5" />
                {t("myOrders", lang)}
              </button>
            )}
            {orderCount > 0 && (
              <button
                type="button"
                disabled={billCalled || calling !== null}
                onClick={() => call("bill")}
                className="col-span-2 flex h-12 items-center justify-center gap-2 rounded-2xl bg-[var(--surface)] px-4 text-sm font-bold shadow-soft ring-1 ring-[var(--line)] transition hover:-translate-y-0.5 active:scale-[0.98] disabled:opacity-70 sm:col-span-1"
              >
                <ReceiptText className="size-5 text-[var(--accent)]" />
                {billCalled ? t("billComing", lang) : t("requestBill", lang)}
              </button>
            )}
          </div>

          <label className="relative flex-1 lg:ml-auto lg:max-w-md">
            <Search className="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-[var(--muted)]" />
            <input
              type="search"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder={t("search", lang)}
              aria-label={t("search", lang)}
              className="h-12 w-full rounded-2xl border border-[var(--line)] bg-[var(--surface)] pr-11 pl-12 text-[15px] shadow-soft outline-none transition focus:border-[var(--brand)] focus:ring-4 focus:ring-[var(--ring)] [&::-webkit-search-cancel-button]:hidden"
            />
            {query && (
              <button
                type="button"
                onClick={() => setQuery("")}
                aria-label="Clear"
                className="absolute top-1/2 right-3 grid size-7 -translate-y-1/2 place-items-center rounded-full bg-[var(--chip)] text-[var(--muted)]"
              >
                <X className="size-4" />
              </button>
            )}
          </label>
        </div>

        {/* ---------- Category chips (phones / tablets) ---------- */}
        {!query && (
          <nav className="sticky top-0 z-20 -mx-4 mt-4 bg-[var(--bg)]/90 backdrop-blur-lg sm:-mx-6 lg:hidden">
            <div className="no-scrollbar flex gap-2 overflow-x-auto px-4 py-3 sm:px-6">
              {menu.categories.map((category) => (
                <button
                  key={category.id}
                  ref={(el) => {
                    chipRefs.current[category.id] = el;
                  }}
                  type="button"
                  onClick={() => jumpTo(category.id)}
                  className={`h-10 shrink-0 rounded-full px-4 text-sm font-semibold transition-all duration-200 ${
                    activeCategory === category.id
                      ? "bg-[var(--fg)] text-[var(--surface)] shadow-card"
                      : "bg-[var(--surface)] text-[var(--fg)] shadow-soft ring-1 ring-[var(--line)]"
                  }`}
                >
                  {pick(category.name, lang)}
                </button>
              ))}
            </div>
          </nav>
        )}

        <div className="mt-4 grid gap-8 lg:mt-8 lg:grid-cols-[220px_minmax(0,1fr)_360px] xl:grid-cols-[240px_minmax(0,1fr)_400px]">
          {/* ---------- Category list (desktop) ---------- */}
          <aside className="hidden lg:block">
            <nav className="sticky top-6 flex flex-col gap-1">
              <p className="mb-2 px-3 text-xs font-bold tracking-wider text-[var(--muted)] uppercase">{t("menu", lang)}</p>
              {menu.categories.map((category) => {
                const on = !query && activeCategory === category.id;
                return (
                  <button
                    key={category.id}
                    type="button"
                    onClick={() => jumpTo(category.id)}
                    className={`group flex items-center justify-between gap-2 rounded-2xl px-3 py-2.5 text-left font-semibold transition ${
                      on ? "bg-[var(--surface)] text-[var(--brand)] shadow-card" : "text-[var(--muted)] hover:bg-[var(--surface)] hover:text-[var(--fg)]"
                    }`}
                  >
                    <span className="truncate">{pick(category.name, lang)}</span>
                    <span className={`text-xs tabular-nums ${on ? "" : "opacity-60"}`}>{category.items.length}</span>
                  </button>
                );
              })}
            </nav>
          </aside>

          {/* ---------- Items ---------- */}
          <main className="flex min-w-0 flex-col gap-10">
            {visible.length === 0 && (
              <div className="anim-fade-in flex flex-col items-center gap-3 py-16 text-center text-[var(--muted)]">
                <Search className="size-10 opacity-50" />
                <p className="font-semibold">{t("noResults", lang)}</p>
              </div>
            )}
            {visible.map((category) => (
              <section
                key={category.id}
                data-category={category.id}
                ref={(el) => {
                  sectionRefs.current[category.id] = el;
                }}
                className="scroll-mt-20 lg:scroll-mt-6"
              >
                <div className="mb-4 flex items-baseline gap-3">
                  <h2 className="text-xl font-bold sm:text-2xl">{pick(category.name, lang)}</h2>
                  <span className="text-sm text-[var(--muted)]">{pick(category.name, lang === "km" ? "en" : "km")}</span>
                </div>
                <ul className="grid gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-3">
                  {category.items.map((item, i) => (
                    <ItemCard
                      key={item.id}
                      index={i}
                      item={item}
                      lang={lang}
                      price={money(item.price)}
                      soldOut={isSoldOut(item, now)}
                      inCart={qtyInCart.get(item.id) ?? 0}
                      onOpen={() => setOpenItem(item)}
                      onAdd={() => quickAdd(item)}
                    />
                  ))}
                </ul>
              </section>
            ))}
          </main>

          {/* ---------- Cart (desktop): always visible ---------- */}
          <aside className="hidden lg:block">
            <div className="sticky top-6 flex max-h-[calc(100dvh-3rem)] flex-col overflow-hidden rounded-3xl bg-[var(--surface)] shadow-card ring-1 ring-[var(--line)]">
              <div className="flex items-center gap-2 border-b border-[var(--line)] px-5 py-4">
                <ShoppingBag className="size-5 text-[var(--brand)]" />
                <h2 className="text-lg font-bold">{t("yourOrder", lang)}</h2>
                {count > 0 && (
                  <span key={count} className="anim-pop ml-auto grid h-6 min-w-6 place-items-center rounded-full bg-[var(--brand)] px-1.5 text-xs font-bold text-white dark:text-[#06140f]">
                    {count}
                  </span>
                )}
              </div>
              <div className="overflow-y-auto p-5">
                <CartPanel {...cartProps} />
              </div>
            </div>
          </aside>
        </div>
      </div>

      {/* ---------- Footer: room below for the phone cart bar ---------- */}
      <SiteFooter themeToggle={false} className={`mt-auto ${count > 0 ? "pb-24 lg:pb-0" : ""}`} />

      {/* ---------- Cart bar (phones / tablets) ---------- */}
      {count > 0 && (
        <div className="anim-sheet-up fixed inset-x-0 bottom-0 z-30 px-4 pb-[calc(env(safe-area-inset-bottom,0px)+14px)] sm:px-6 lg:hidden">
          <button
            type="button"
            onClick={() => setCartOpen(true)}
            className="mx-auto flex h-16 w-full max-w-2xl items-center gap-3 rounded-2xl bg-[var(--brand)] px-4 text-white shadow-float transition active:scale-[0.98] dark:text-[#06140f]"
          >
            <span key={count} className="anim-pop relative grid size-10 place-items-center rounded-xl bg-white/20">
              <ShoppingBag className="size-5" />
              <span className="absolute -top-1.5 -right-1.5 grid h-5 min-w-5 place-items-center rounded-full bg-[var(--accent)] px-1 text-[11px] font-bold text-[#2b1d06]">
                {count}
              </span>
            </span>
            <span className="flex-1 text-left font-bold">{t("viewOrder", lang)}</span>
            <span className="text-right leading-tight font-bold tabular-nums">
              {money(totals.total)}
              {riel !== null && <span className="block text-xs font-medium opacity-80">≈ {riel.toLocaleString("en-US")}៛</span>}
            </span>
            <ChevronRight className="size-5 opacity-80" />
          </button>
        </div>
      )}

      {openItem && (
        <ItemSheet
          key={openItem.id}
          item={openItem}
          lang={lang}
          currency={company.currency}
          onClose={() => setOpenItem(null)}
          onAdd={(line) => {
            setOpenItem(null);
            addLine(line);
          }}
        />
      )}

      {upsell && (
        <Sheet onClose={() => setUpsell(null)} label={t("goesWellWith", lang)} size="lg">
          <div className="flex items-center gap-3 pr-10">
            <SuccessMark className="size-11 shrink-0" />
            <div className="min-w-0">
              <p className="text-sm text-[var(--muted)]">{t("addedToOrder", lang)}</p>
              <p className="truncate font-bold">{pick(upsell.item.name, lang)}</p>
            </div>
          </div>
          <h2 className="flex items-center gap-2 text-xl font-bold">
            <Sparkles className="size-5 text-[var(--accent)]" /> {t("goesWellWith", lang)}
          </h2>
          <ul className="grid gap-3 sm:grid-cols-3">
            {upsell.list.map((item, i) => (
              <li key={item.id} className="anim-fade-up" style={{ "--i": i + 1 } as React.CSSProperties}>
                <UpsellCard
                  item={item}
                  lang={lang}
                  price={money(item.price)}
                  added={(qtyInCart.get(item.id) ?? 0) > 0}
                  onAdd={() => {
                    if (canQuickAdd(item)) {
                      addLine(lineFor(item, defaultsFor(item.option_groups), 1, ""), false);
                    } else {
                      setUpsell(null);
                      setOpenItem(item);
                    }
                  }}
                />
              </li>
            ))}
          </ul>
          <div className="grid grid-cols-2 gap-2">
            <button
              type="button"
              onClick={() => setUpsell(null)}
              className="h-12 rounded-2xl border border-[var(--line)] font-bold transition hover:bg-[var(--surface-2)]"
            >
              {t("noThanks", lang)}
            </button>
            <button
              type="button"
              onClick={() => {
                setUpsell(null);
                if (window.matchMedia("(max-width: 1023px)").matches) setCartOpen(true);
              }}
              className="h-12 rounded-2xl bg-[var(--brand)] font-bold text-white transition hover:bg-[var(--brand-strong)] dark:text-[#06140f]"
            >
              {t("viewOrder", lang)}
            </button>
          </div>
        </Sheet>
      )}

      {cartOpen && (
        <CartSheet
          {...cartProps}
          onClose={() => {
            setCartOpen(false);
            setSendError(null);
          }}
        />
      )}

      {ordersOpen && session && session.orders.length > 0 && (
        <OrdersSheet
          session={session}
          lang={lang}
          currency={company.currency}
          busy={calling}
          justSent={justSent}
          onCall={call}
          onClose={() => {
            setOrdersOpen(false);
            setJustSent(false);
          }}
        />
      )}

      {thanks && (
        <Sheet onClose={() => setThanks(false)} label={t("paidThanks", lang)}>
          <div className="flex flex-col items-center gap-3 py-8 text-center">
            <SuccessMark className="size-24" />
            <h2 className="anim-fade-up text-3xl font-bold" style={{ "--i": 3 } as React.CSSProperties}>{t("paidThanks", lang)}</h2>
            <p className="anim-fade-up text-[var(--muted)]" style={{ "--i": 4 } as React.CSSProperties}>{t("seeYou", lang)}</p>
            <div className="anim-fade-up mt-2 flex items-center gap-2 text-sm text-[var(--muted)]" style={{ "--i": 5 } as React.CSSProperties}>
              {company.logo_url && <img src={company.logo_url} alt="" className="size-7 rounded-lg object-cover" />}
              {company.name}
            </div>
          </div>
          <button
            type="button"
            onClick={() => setThanks(false)}
            className="h-12 rounded-2xl bg-[var(--brand)] font-bold text-white dark:text-[#06140f]"
          >
            {t("close", lang)}
          </button>
        </Sheet>
      )}

      {locSheet && <LocationSheet stage={locSheet.stage} lang={lang} onRetry={locSheet.retry} onClose={() => setLocSheet(null)} />}

      {toast && (
        <div
          role="status"
          key={toast}
          className="anim-fade-up fixed inset-x-0 top-[calc(env(safe-area-inset-top,0px)+14px)] z-50 mx-auto flex w-fit max-w-[92vw] items-center gap-2 rounded-full bg-[var(--fg)] py-2.5 pr-5 pl-3 text-sm font-semibold text-[var(--surface)] shadow-float"
        >
          <span className="grid size-6 place-items-center rounded-full bg-[var(--brand)] text-white">
            <svg viewBox="0 0 24 24" className="size-3.5" fill="none" stroke="currentColor" strokeWidth={3.5} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
              <path d="M5 12.5l4.5 4.5L19 7.5" className="anim-draw" />
            </svg>
          </span>
          {toast}
        </div>
      )}
    </div>
  );
}

function ItemCard({
  item,
  index,
  lang,
  price,
  soldOut,
  inCart,
  onOpen,
  onAdd,
}: {
  item: MenuItem;
  index: number;
  lang: Lang;
  price: string;
  soldOut: boolean;
  inCart: number;
  onOpen: () => void;
  onAdd: () => void;
}) {
  const other = pick(item.name, lang === "km" ? "en" : "km");
  return (
    <li className="anim-fade-up" style={{ "--i": Math.min(index, 8) } as React.CSSProperties}>
      <div
        className={`group relative flex h-full gap-3 overflow-hidden rounded-3xl bg-[var(--surface)] p-2.5 shadow-soft ring-1 ring-[var(--line)] transition duration-200 sm:flex-col sm:gap-0 sm:p-0 ${
          soldOut ? "opacity-60" : "hover:-translate-y-1 hover:shadow-card"
        } ${inCart > 0 ? "ring-2 ring-[var(--brand)]" : ""}`}
      >
        <button type="button" disabled={soldOut} onClick={onOpen} className="absolute inset-0 z-[1]" aria-label={pick(item.name, lang)} />
        <div className="relative size-28 shrink-0 overflow-hidden rounded-2xl sm:aspect-[4/3] sm:size-auto sm:w-full sm:rounded-none">
          {item.image_url ? (
            <img src={item.image_url} alt="" loading="lazy" className="size-full object-cover transition duration-500 group-hover:scale-105" />
          ) : (
            <div className="grid size-full place-items-center bg-gradient-to-br from-[var(--brand-soft)] to-[var(--accent-soft)]">
              <UtensilsCrossed className="size-8 text-[var(--brand)] opacity-50" strokeWidth={1.6} />
            </div>
          )}
          {soldOut && (
            <span className="absolute inset-0 grid place-items-center bg-black/45 text-sm font-bold text-white">{t("soldOut", lang)}</span>
          )}
          {inCart > 0 && (
            <span key={inCart} className="anim-pop absolute top-2 left-2 grid h-7 min-w-7 place-items-center rounded-full bg-[var(--brand)] px-2 text-xs font-bold text-white shadow-card dark:text-[#06140f]">
              {inCart}×
            </span>
          )}
        </div>
        <div className="flex min-w-0 flex-1 flex-col py-1 pr-1 sm:p-4">
          <p className="leading-snug font-bold">{pick(item.name, lang)}</p>
          {other && other !== pick(item.name, lang) && <p className="truncate text-sm text-[var(--muted)]">{other}</p>}
          {pick(item.description, lang) && <p className="mt-1 line-clamp-2 hidden text-sm text-[var(--muted)] sm:block">{pick(item.description, lang)}</p>}
          <div className="mt-auto flex items-center justify-between gap-2 pt-2">
            <span className="text-lg font-bold tabular-nums">{price}</span>
            {!soldOut && (
              <button
                type="button"
                onClick={onAdd}
                aria-label={t("add", lang)}
                className="relative z-[2] grid size-10 place-items-center rounded-full bg-[var(--brand)] text-white shadow-card transition hover:scale-110 hover:bg-[var(--brand-strong)] active:scale-95 dark:text-[#06140f]"
              >
                <Plus className="size-5" strokeWidth={2.8} />
              </button>
            )}
          </div>
        </div>
      </div>
    </li>
  );
}

function UpsellCard({ item, lang, price, added, onAdd }: { item: MenuItem; lang: Lang; price: string; added: boolean; onAdd: () => void }) {
  return (
    <div className={`flex h-full gap-3 overflow-hidden rounded-3xl bg-[var(--surface-2)] p-2.5 ring-1 transition sm:flex-col sm:p-0 ${added ? "ring-2 ring-[var(--brand)]" : "ring-[var(--line)]"}`}>
      <div className="size-20 shrink-0 overflow-hidden rounded-2xl sm:aspect-[4/3] sm:size-auto sm:w-full sm:rounded-none">
        {item.image_url ? (
          <img src={item.image_url} alt="" className="size-full object-cover" />
        ) : (
          <div className="grid size-full place-items-center bg-[var(--chip)]">
            <UtensilsCrossed className="size-7 text-[var(--muted)]" strokeWidth={1.6} />
          </div>
        )}
      </div>
      <div className="flex min-w-0 flex-1 flex-col gap-1 sm:p-3">
        <p className="leading-snug font-bold">{pick(item.name, lang)}</p>
        <div className="mt-auto flex items-center justify-between gap-2">
          <span className="font-bold text-[var(--brand)] tabular-nums">{price}</span>
          <button
            type="button"
            onClick={onAdd}
            className={`flex h-9 items-center gap-1 rounded-full px-3 text-sm font-bold transition active:scale-95 ${
              added ? "bg-[var(--brand-soft)] text-[var(--brand-strong)]" : "bg-[var(--brand)] text-white hover:bg-[var(--brand-strong)] dark:text-[#06140f]"
            }`}
          >
            {added ? (
              <svg viewBox="0 0 24 24" className="size-4" fill="none" stroke="currentColor" strokeWidth={3.2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
                <path d="M5 12.5l4.5 4.5L19 7.5" className="anim-draw" />
              </svg>
            ) : (
              <Plus className="size-4" strokeWidth={2.8} />
            )}
            {t("add", lang)}
          </button>
        </div>
      </div>
    </div>
  );
}
