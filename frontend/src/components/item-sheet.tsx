"use client";

/* eslint-disable @next/next/no-img-element */

import { useMemo, useState } from "react";
import type { CartLine, Lang, MenuItem, OptionGroup } from "@/lib/types";
import { formatMoney } from "@/lib/money";
import { pick, t } from "@/lib/i18n";
import Sheet from "./sheet";

function defaultsFor(groups: OptionGroup[]): Record<number, number[]> {
  const selected: Record<number, number[]> = {};
  for (const group of groups) {
    const defaults = group.options.filter((o) => o.is_default).map((o) => o.id);
    // A required single choice with no default: pre-pick the first so the customer can add in one tap.
    selected[group.id] =
      defaults.length > 0 ? defaults.slice(0, group.max) : group.min > 0 && group.max === 1 && group.options[0] ? [group.options[0].id] : [];
  }
  return selected;
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

  const chosen = useMemo(
    () => item.option_groups.flatMap((g) => g.options.filter((o) => (selected[g.id] ?? []).includes(o.id))),
    [item.option_groups, selected],
  );
  const unitPrice = item.price + chosen.reduce((n, o) => n + o.price_delta, 0);
  const valid = item.option_groups.every((g) => (selected[g.id] ?? []).length >= g.min);

  function add() {
    const optionIds = chosen.map((o) => o.id).sort((a, b) => a - b);
    onAdd({
      key: `${item.id}:${optionIds.join(",")}:${note.trim()}`,
      itemId: item.id,
      name: item.name,
      unitPrice,
      optionIds,
      optionNames: chosen.map((o) => o.name),
      quantity,
      note: note.trim(),
    });
  }

  return (
    <Sheet onClose={onClose} label={pick(item.name, lang)}>
      {item.image_url && <img src={item.image_url} alt="" className="aspect-[4/3] w-full rounded-xl object-cover" />}
      <div>
        <h2 className="text-xl font-semibold">{pick(item.name, lang)}</h2>
        <p className="text-sm text-[var(--muted)]">{pick(item.name, lang === "km" ? "en" : "km")}</p>
        {pick(item.description, lang) && <p className="mt-2 text-sm">{pick(item.description, lang)}</p>}
      </div>

      {item.option_groups.map((group) => (
        <fieldset key={group.id} className="flex flex-col gap-2">
          <legend className="mb-1 flex w-full items-center justify-between font-semibold">
            <span>{pick(group.name, lang)}</span>
            <span className="text-xs font-medium text-[var(--muted)]">
              {group.min > 0 ? t("required", lang) : t("optional", lang)}
              {group.max > 1 ? ` · ${t("pickUpTo", lang)} ${group.max}` : ""}
            </span>
          </legend>
          {group.options.map((option) => {
            const on = (selected[group.id] ?? []).includes(option.id);
            return (
              <button
                key={option.id}
                type="button"
                onClick={() => toggle(group, option.id)}
                aria-pressed={on}
                className={`flex items-center justify-between rounded-lg border px-3 py-2.5 text-left ${
                  on ? "border-[var(--brand)] bg-[var(--brand-soft)]" : "border-[var(--line)]"
                }`}
              >
                <span>{pick(option.name, lang)}</span>
                <span className="text-sm tabular-nums text-[var(--muted)]">
                  {option.price_delta ? `+${formatMoney(option.price_delta, currency)}` : ""}
                </span>
              </button>
            );
          })}
        </fieldset>
      ))}

      <label className="flex flex-col gap-1.5">
        <span className="text-sm font-medium">{t("note", lang)}</span>
        <input
          id="item-note"
          value={note}
          onChange={(e) => setNote(e.target.value.slice(0, 120))}
          className="rounded-lg border border-[var(--line)] bg-transparent px-3 py-2.5"
        />
      </label>

      <div className="sticky bottom-0 -mx-5 flex items-center gap-3 border-t border-[var(--line)] bg-[var(--surface)] px-5 py-3">
        <div className="flex items-center gap-3 rounded-full border border-[var(--line)] px-2">
          <button type="button" className="size-9 text-xl" onClick={() => setQuantity((q) => Math.max(1, q - 1))} aria-label="Less">
            −
          </button>
          <span className="w-5 text-center font-semibold tabular-nums">{quantity}</span>
          <button type="button" className="size-9 text-xl" onClick={() => setQuantity((q) => Math.min(50, q + 1))} aria-label="More">
            +
          </button>
        </div>
        <button
          type="button"
          disabled={!valid}
          onClick={add}
          className="flex flex-1 items-center justify-between rounded-xl bg-[var(--brand)] px-4 py-3 font-medium text-white disabled:opacity-40"
        >
          <span>{t("addToOrder", lang)}</span>
          <span className="tabular-nums">{formatMoney(unitPrice * quantity, currency)}</span>
        </button>
      </div>
    </Sheet>
  );
}
