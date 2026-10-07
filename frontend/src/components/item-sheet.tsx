"use client";

/* eslint-disable @next/next/no-img-element */

import { Check, Minus, Plus, StickyNote, UtensilsCrossed } from "lucide-react";
import { useMemo, useState } from "react";
import type { CartLine, Lang, MenuItem, OptionGroup } from "@/lib/types";
import { formatMoney } from "@/lib/money";
import { pick, t } from "@/lib/i18n";
import Sheet from "./sheet";
import { inputClass } from "./ui";

export function defaultsFor(groups: OptionGroup[]): Record<number, number[]> {
  const selected: Record<number, number[]> = {};
  for (const group of groups) {
    const defaults = group.options.filter((o) => o.is_default).map((o) => o.id);
    // A required single choice with no default: pre-pick the first so the customer can add in one tap.
    selected[group.id] =
      defaults.length > 0 ? defaults.slice(0, group.max) : group.min > 0 && group.max === 1 && group.options[0] ? [group.options[0].id] : [];
  }
  return selected;
}

/** The cart line for an item with the given choices (also used for one-tap adds). */
export function lineFor(item: MenuItem, selected: Record<number, number[]>, quantity: number, note: string): CartLine {
  const chosen = item.option_groups.flatMap((g) => g.options.filter((o) => (selected[g.id] ?? []).includes(o.id)));
  const optionIds = chosen.map((o) => o.id).sort((a, b) => a - b);
  return {
    key: `${item.id}:${optionIds.join(",")}:${note.trim()}`,
    itemId: item.id,
    name: item.name,
    unitPrice: item.price + chosen.reduce((n, o) => n + o.price_delta, 0),
    optionIds,
    optionNames: chosen.map((o) => o.name),
    quantity,
    note: note.trim(),
  };
}

/** Items without choices can go straight into the cart; the rest open the item sheet. */
export function canQuickAdd(item: MenuItem): boolean {
  return item.option_groups.length === 0;
}

export default function ItemSheet({
  item,
  lang,
  currency,
  onClose,
  onAdd,
}: {
  item: MenuItem;
  lang: Lang;
  currency: "USD" | "KHR";
  onClose: () => void;
  onAdd: (line: CartLine) => void;
}) {
  const [selected, setSelected] = useState<Record<number, number[]>>(() => defaultsFor(item.option_groups));
  const [quantity, setQuantity] = useState(1);
  const [note, setNote] = useState("");

  function toggle(group: OptionGroup, optionId: number) {
    setSelected((current) => {
      const picked = current[group.id] ?? [];
      if (group.max === 1) {
        // Radio behaviour; optional groups can be cleared by tapping again.
        return { ...current, [group.id]: picked[0] === optionId && group.min === 0 ? [] : [optionId] };
      }
      if (picked.includes(optionId)) {
        return { ...current, [group.id]: picked.filter((id) => id !== optionId) };
      }
      if (picked.length >= group.max) return current;
      return { ...current, [group.id]: [...picked, optionId] };
    });
  }

  const line = useMemo(() => lineFor(item, selected, quantity, note), [item, selected, quantity, note]);
  const valid = item.option_groups.every((g) => (selected[g.id] ?? []).length >= g.min);
  const other = pick(item.name, lang === "km" ? "en" : "km");
  const description = pick(item.description, lang);

  return (
    <Sheet onClose={onClose} label={pick(item.name, lang)} size="lg" bare>
      <div className="flex flex-col sm:grid sm:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
        <div className="relative sm:sticky sm:top-0 sm:self-start">
          {item.image_url ? (
            <img src={item.image_url} alt="" className="aspect-[4/3] w-full object-cover sm:aspect-square sm:rounded-l-3xl" />
          ) : (
            <div className="grid aspect-[16/9] w-full place-items-center bg-gradient-to-br from-[var(--brand-soft)] to-[var(--accent-soft)] sm:aspect-square sm:rounded-l-3xl">
              <UtensilsCrossed className="size-14 text-[var(--brand)] opacity-60" strokeWidth={1.5} />
            </div>
          )}
        </div>

        <div className="flex min-w-0 flex-col">
          <div className="flex flex-col gap-5 px-5 pt-5 pb-4 sm:px-7 sm:pt-7">
            <div className="anim-fade-up pr-8">
              <h2 className="text-2xl leading-snug font-bold">{pick(item.name, lang)}</h2>
              {other && other !== pick(item.name, lang) && <p className="text-[var(--muted)]">{other}</p>}
              <p className="mt-2 text-xl font-bold text-[var(--brand)] tabular-nums">{formatMoney(item.price, currency)}</p>
              {description && <p className="mt-2 text-[15px] text-[var(--muted)]">{description}</p>}
            </div>

            {item.option_groups.map((group, gi) => {
              const multi = group.max > 1;
              return (
                <fieldset key={group.id} className="anim-fade-up flex flex-col gap-2" style={{ "--i": gi + 1 } as React.CSSProperties}>
                  <legend className="mb-2 flex w-full items-center justify-between gap-2">
                    <span className="font-bold">{pick(group.name, lang)}</span>
                    <span
                      className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${
                        group.min > 0 ? "bg-[var(--accent-soft)] text-[var(--warn)]" : "bg-[var(--chip)] text-[var(--muted)]"
                      }`}
                    >
                      {group.min > 0 ? t("required", lang) : t("optional", lang)}
                      {multi ? ` · ${t("pickUpTo", lang)} ${group.max}` : ""}
                    </span>
                  </legend>
                  <div className="grid gap-2 sm:grid-cols-2">
                    {group.options.map((option) => {
                      const on = (selected[group.id] ?? []).includes(option.id);
                      return (
                        <button
                          key={option.id}
                          type="button"
                          onClick={() => toggle(group, option.id)}
                          aria-pressed={on}
                          className={`flex items-center gap-3 rounded-2xl border-2 px-3.5 py-3 text-left transition duration-150 active:scale-[0.98] ${
                            on ? "border-[var(--brand)] bg-[var(--brand-soft)]" : "border-[var(--line)] hover:border-[var(--muted)]"
                          }`}
                        >
                          <span
                            className={`grid size-5 shrink-0 place-items-center border-2 transition ${multi ? "rounded-md" : "rounded-full"} ${
                              on ? "border-[var(--brand)] bg-[var(--brand)] text-white dark:text-[#06140f]" : "border-[var(--line)]"
                            }`}
                          >
                            {on && <Check className="anim-scale-in size-3.5" strokeWidth={3.5} />}
                          </span>
                          <span className="min-w-0 flex-1 font-medium">{pick(option.name, lang)}</span>
                          {option.price_delta !== 0 && (
                            <span className="text-sm font-semibold text-[var(--muted)] tabular-nums">+{formatMoney(option.price_delta, currency)}</span>
                          )}
                        </button>
                      );
                    })}
                  </div>
                </fieldset>
              );
            })}

            <label className="flex flex-col gap-1.5">
              <span className="flex items-center gap-1.5 text-sm font-semibold">
                <StickyNote className="size-4 text-[var(--muted)]" /> {t("note", lang)}
              </span>
              <input id="item-note" value={note} onChange={(e) => setNote(e.target.value.slice(0, 120))} className={inputClass} />
            </label>
          </div>

          <div className="sticky bottom-0 mt-auto flex items-center gap-3 border-t border-[var(--line)] bg-[var(--surface)]/95 px-5 py-3.5 pb-[calc(env(safe-area-inset-bottom,0px)+14px)] backdrop-blur sm:rounded-br-3xl sm:px-7">
            <div className="flex items-center gap-1 rounded-2xl bg-[var(--chip)] p-1">
              <button
                type="button"
                className="grid size-10 place-items-center rounded-xl transition hover:bg-[var(--surface)] disabled:opacity-40"
                onClick={() => setQuantity((q) => Math.max(1, q - 1))}
                disabled={quantity <= 1}
                aria-label="Less"
              >
                <Minus className="size-4" strokeWidth={2.6} />
              </button>
              <span key={quantity} className="anim-pop w-7 text-center text-lg font-bold tabular-nums">{quantity}</span>
              <button
                type="button"
                className="grid size-10 place-items-center rounded-xl transition hover:bg-[var(--surface)]"
                onClick={() => setQuantity((q) => Math.min(50, q + 1))}
                aria-label="More"
              >
                <Plus className="size-4" strokeWidth={2.6} />
              </button>
            </div>
            <button
              type="button"
              disabled={!valid}
              onClick={() => onAdd(line)}
              className="flex h-12 flex-1 items-center justify-between gap-3 rounded-2xl bg-[var(--brand)] px-5 font-semibold text-white shadow-card transition hover:bg-[var(--brand-strong)] active:scale-[0.98] disabled:opacity-40 dark:text-[#06140f]"
            >
              <span>{t("addToOrder", lang)}</span>
              <span className="tabular-nums">{formatMoney(line.unitPrice * quantity, currency)}</span>
            </button>
          </div>
        </div>
      </div>
    </Sheet>
  );
}
