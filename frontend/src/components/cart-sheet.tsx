"use client";

/* eslint-disable @next/next/no-img-element */

import { Minus, Plus, Send, ShoppingBag, Sparkles, Trash2, UtensilsCrossed } from "lucide-react";
import { useState } from "react";
import type { CartLine, Lang, MenuItem } from "@/lib/types";
import { formatMoney } from "@/lib/money";
import { pick, t } from "@/lib/i18n";
import Sheet from "./sheet";
import { Alert, inputClass } from "./ui";

export type CartProps = {
  lines: CartLine[];
  lang: Lang;
  currency: "USD" | "KHR";
  subtotal: number;
  service: number;
  vat: number;
  total: number;
  riel: number | null;
  sending: boolean;
  error: string | null;
  suggestions: MenuItem[];
  onPickSuggestion: (item: MenuItem) => void;
  onChangeQuantity: (key: string, delta: number) => void;
  onSend: (note: string) => void;
};

/** The cart itself: lines, suggestions, totals and the send button. */
export function CartPanel({
  lines,
  lang,
  currency,
  subtotal,
  service,
  vat,
  total,
  riel,
  sending,
  error,
  suggestions,
  onPickSuggestion,
  onChangeQuantity,
  onSend,
}: CartProps) {
  const [note, setNote] = useState("");
  const money = (minor: number) => formatMoney(minor, currency);

  if (lines.length === 0) {
    return (
      <div className="flex flex-col items-center gap-3 px-4 py-10 text-center">
        <span className="anim-float grid size-16 place-items-center rounded-3xl bg-[var(--brand-soft)] text-[var(--brand)]">
          <ShoppingBag className="size-7" strokeWidth={1.8} />
        </span>
        <p className="font-semibold">{t("empty", lang)}</p>
        <p className="text-sm text-[var(--muted)]">{t("cartEmptyText", lang)}</p>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-4">
      <ul className="flex flex-col gap-2">
        {lines.map((line, i) => (
          <li key={line.key} className="anim-fade-up flex items-start gap-3 rounded-2xl bg-[var(--surface-2)] p-3" style={{ "--i": i } as React.CSSProperties}>
            <div className="min-w-0 flex-1">
              <p className="leading-snug font-semibold">{pick(line.name, lang)}</p>
              {line.optionNames.length > 0 && <p className="text-sm text-[var(--muted)]">{line.optionNames.map((n) => pick(n, lang)).join(", ")}</p>}
              {line.note && <p className="text-sm text-[var(--muted)] italic">“{line.note}”</p>}
              <p className="mt-1 text-sm font-bold tabular-nums">{money(line.unitPrice * line.quantity)}</p>
            </div>
            <div className="flex items-center gap-0.5 rounded-xl bg-[var(--surface)] p-0.5 shadow-soft">
              <button
                type="button"
                className="grid size-8 place-items-center rounded-lg text-[var(--muted)] transition hover:bg-[var(--chip)] hover:text-[var(--fg)]"
                onClick={() => onChangeQuantity(line.key, -1)}
                aria-label="Less"
              >
                {line.quantity === 1 ? <Trash2 className="size-4" /> : <Minus className="size-4" strokeWidth={2.6} />}
              </button>
              <span key={line.quantity} className="anim-pop w-6 text-center font-bold tabular-nums">{line.quantity}</span>
              <button
                type="button"
                className="grid size-8 place-items-center rounded-lg text-[var(--muted)] transition hover:bg-[var(--chip)] hover:text-[var(--fg)]"
                onClick={() => onChangeQuantity(line.key, 1)}
                aria-label="More"
              >
                <Plus className="size-4" strokeWidth={2.6} />
              </button>
            </div>
          </li>
        ))}
      </ul>

      {suggestions.length > 0 && (
        <section className="flex flex-col gap-2">
          <h3 className="flex items-center gap-1.5 text-sm font-bold text-[var(--muted)]">
            <Sparkles className="size-4 text-[var(--accent)]" /> {t("youMightLike", lang)}
          </h3>
          <div className="no-scrollbar -mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
            {suggestions.map((item) => (
              <button
                key={item.id}
                type="button"
                onClick={() => onPickSuggestion(item)}
                className="group flex w-36 shrink-0 flex-col overflow-hidden rounded-2xl border border-[var(--line)] bg-[var(--surface)] text-left transition hover:-translate-y-0.5 hover:shadow-card"
              >
                {item.image_url ? (
                  <img src={item.image_url} alt="" className="aspect-[4/3] w-full object-cover transition duration-300 group-hover:scale-105" />
                ) : (
                  <span className="grid aspect-[4/3] w-full place-items-center bg-[var(--chip)]">
                    <UtensilsCrossed className="size-6 text-[var(--muted)]" />
                  </span>
                )}
                <span className="flex items-center gap-1 p-2">
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-semibold">{pick(item.name, lang)}</span>
                    <span className="text-xs font-bold text-[var(--brand)] tabular-nums">{money(item.price)}</span>
                  </span>
                  <span className="grid size-7 shrink-0 place-items-center rounded-full bg-[var(--brand)] text-white dark:text-[#06140f]">
                    <Plus className="size-4" strokeWidth={2.8} />
                  </span>
                </span>
              </button>
            ))}
          </div>
        </section>
      )}

      <label className="flex flex-col gap-1.5">
        <span className="text-sm font-semibold">{t("orderNote", lang)}</span>
        <input id="order-note" value={note} onChange={(e) => setNote(e.target.value.slice(0, 200))} className={inputClass} />
      </label>

      <dl className="flex flex-col gap-1.5 rounded-2xl bg-[var(--surface-2)] p-4 text-sm tabular-nums">
        <div className="flex justify-between text-[var(--muted)]">
          <dt>{t("subtotal", lang)}</dt>
          <dd>{money(subtotal)}</dd>
        </div>
        {service > 0 && (
          <div className="flex justify-between text-[var(--muted)]">
            <dt>{t("service", lang)}</dt>
            <dd>{money(service)}</dd>
          </div>
        )}
        {vat > 0 && (
          <div className="flex justify-between text-[var(--muted)]">
            <dt>{t("vat", lang)}</dt>
            <dd>{money(vat)}</dd>
          </div>
        )}
        <div className="mt-1 flex items-baseline justify-between border-t border-dashed border-[var(--line)] pt-2 text-lg font-bold">
          <dt>{t("total", lang)}</dt>
          <dd className="text-right">
            {money(total)}
            {riel !== null && <span className="block text-xs font-medium text-[var(--muted)]">≈ {riel.toLocaleString("en-US")}៛</span>}
          </dd>
        </div>
      </dl>

      {error && <Alert>{error}</Alert>}

      <button
        type="button"
        disabled={sending}
        onClick={() => onSend(note)}
        className="flex h-14 items-center justify-center gap-2 rounded-2xl bg-[var(--brand)] px-5 font-bold text-white shadow-card transition hover:bg-[var(--brand-strong)] active:scale-[0.98] disabled:opacity-50 dark:text-[#06140f]"
      >
        {sending ? (
          <>
            <span className="size-5 animate-spin rounded-full border-2 border-white/40 border-t-white" aria-hidden />
            {t("sending", lang)}
          </>
        ) : (
          <>
            <Send className="size-5" strokeWidth={2.2} />
            {t("send", lang)} · {money(total)}
          </>
        )}
      </button>
    </div>
  );
}

export default function CartSheet({ onClose, ...props }: CartProps & { onClose: () => void }) {
  return (
    <Sheet onClose={onClose} label={t("yourOrder", props.lang)}>
      <h2 className="flex items-center gap-2 text-xl font-bold">
        <ShoppingBag className="size-5 text-[var(--brand)]" /> {t("yourOrder", props.lang)}
      </h2>
      <CartPanel {...props} />
    </Sheet>
  );
}
