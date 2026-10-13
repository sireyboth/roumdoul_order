"use client";

/* Price cards on the start page. Plans and prices come from /admin > Plans, feature names from /admin > Plan features, "Coming soon" from the code (backend App\Enums\Feature::isBuilt()). */

import { useState } from "react";
import { ArrowRight, Check, Clock, Phone, Receipt } from "lucide-react";
import type { Plan } from "@/lib/types";
import { buttonClass } from "../ui";

/** $19 for whole dollars, $19.50 otherwise; riel as 75,000៛. */
function price(minor: number, currency: string) {
  if (currency === "KHR") return `${minor.toLocaleString("en-US")}៛`;
  const dollars = minor / 100;
  return `$${Number.isInteger(dollars) ? dollars : dollars.toFixed(2)}`;
}

function limit(value: number | null, one: string, many: string) {
  if (value === null) return `Unlimited ${many}`;
  return `${value} ${value === 1 ? one : many}`;
}

export default function Pricing({
  plans: all,
  phone,
  signupUrl,
}: {
  plans: Plan[];
  phone: string;
  signupUrl: string;
}) {
  const [yearly, setYearly] = useState(false);
  const call = `tel:${phone.replace(/\s/g, "")}`;
  const plans = all.filter((p) => p.price_per_order === null);
  const perOrder = all.filter((p) => p.price_per_order !== null);

  // Best saving across plans, shown on the "Yearly" switch.
  const saving = Math.max(
    0,
    ...plans
      .filter((p) => p.price_monthly > 0 && p.price_yearly > 0)
      .map((p) =>
        Math.round((1 - p.price_yearly / (p.price_monthly * 12)) * 100),
      ),
  );

  if (all.length === 0) {
    return (
      <div className="mx-auto flex max-w-md flex-col items-center gap-4 rounded-3xl bg-[var(--surface)] p-8 text-center shadow-card">
        <p className="text-[var(--muted)]">
          Call us for today&apos;s prices. We&apos;ll find the right plan for
          your shop.
        </p>
        <a href={call} className={buttonClass("brand", "lg")}>
          <Phone className="size-[18px]" aria-hidden />
          {phone}
        </a>
      </div>
    );
  }

  // The middle plan of an odd list is the one we recommend.
  const featured =
    plans.length >= 3 && plans.length % 2 === 1
      ? Math.floor(plans.length / 2)
      : -1;

  return (
    <div className="flex flex-col items-center gap-10">
      {plans.length > 0 && (
        <div
          role="radiogroup"
          aria-label="Billing period"
          className="inline-flex rounded-full border border-[var(--line)] bg-[var(--surface)] p-1 shadow-soft"
        >
          {[false, true].map((isYearly) => (
            <button
              key={String(isYearly)}
              type="button"
              role="radio"
              aria-checked={yearly === isYearly}
              onClick={() => setYearly(isYearly)}
              className={`flex h-10 items-center gap-2 rounded-full px-5 text-sm font-semibold transition ${
                yearly === isYearly
                  ? "bg-[var(--brand)] text-white dark:text-[#1a0f0f]"
                  : "text-[var(--muted)] hover:text-[var(--fg)]"
              }`}
            >
              {isYearly ? "Yearly" : "Monthly"}
              {isYearly && saving > 0 && (
                <span
                  className={`rounded-full px-2 py-0.5 text-xs ${yearly ? "bg-white/20" : "bg-[var(--accent-soft)] text-[var(--fg)]"}`}
                >
                  Save {saving}%
                </span>
              )}
            </button>
          ))}
        </div>
      )}

      {plans.length > 0 && (
        <div
          className={`grid w-full gap-6 ${plans.length === 1 ? "max-w-sm" : plans.length === 2 ? "max-w-3xl md:grid-cols-2" : "md:grid-cols-2 lg:grid-cols-3"}`}
        >
          {plans.map((plan, i) => {
            const isFeatured = i === featured;
            const amount = yearly ? plan.price_yearly : plan.price_monthly;
            return (
              <article
                key={plan.code}
                style={{ "--i": i } as React.CSSProperties}
                className={`anim-fade-up relative flex flex-col gap-6 rounded-3xl p-7 ${
                  isFeatured
                    ? "bg-[var(--surface)] shadow-float ring-2 ring-[var(--brand)] lg:-my-3 lg:py-10"
                    : "border border-[var(--line)] bg-[var(--surface)] shadow-card"
                }`}
              >
                {isFeatured && (
                  <span className="absolute -top-3.5 left-1/2 -translate-x-1/2 rounded-full bg-[var(--accent)] px-4 py-1 text-xs font-bold whitespace-nowrap text-[#2b1d06]">
                    Most popular
                  </span>
                )}
                <div className="flex flex-col gap-1">
                  <h3 className="text-xl font-bold">{plan.name}</h3>
                  {plan.description && (
                    <p className="text-sm text-[var(--muted)]">
                      {plan.description}
                    </p>
                  )}
                </div>

                <div className="flex flex-col gap-1">
                  <p className="flex items-baseline gap-1.5">
                    <span className="text-5xl font-bold tracking-tight">
                      {price(amount, plan.currency)}
                    </span>
                    <span className="text-[var(--muted)]">
                      / {yearly ? "year" : "month"}
                    </span>
                  </p>
                  <p className="h-5 text-sm text-[var(--muted)]">
                    {yearly && plan.price_yearly > 0
                      ? `About ${price(Math.round(plan.price_yearly / 12), plan.currency)} a month`
                      : " "}
                  </p>
                </div>

                <a
                  href={signupUrl}
                  className={buttonClass(
                    isFeatured ? "brand" : "neutral",
                    "lg",
                    "w-full",
                  )}
                >
                  Start free trial
                  <ArrowRight className="size-[18px]" aria-hidden />
                </a>

                <ul className="flex flex-col gap-3 border-t border-[var(--line)] pt-6 text-[15px]">
                  {[
                    limit(plan.max_branches, "branch", "branches"),
                    limit(plan.max_tables, "table", "tables"),
                    limit(plan.max_staff, "staff account", "staff accounts"),
                    "QR menu, kitchen, waiter and cashier screens",
                  ].map((text) => (
                    <li key={text} className="flex gap-2.5">
                      <Check
                        className="mt-0.5 size-[18px] shrink-0 text-[var(--brand)]"
                        strokeWidth={2.6}
                        aria-hidden
                      />
                      {text}
                    </li>
                  ))}
                  {plan.features.map((feature) => {
                    return (
                      <li key={feature.key} className="flex gap-2.5">
                        {feature.coming_soon ? (
                          <Clock
                            className="mt-0.5 size-[18px] shrink-0 text-[var(--muted)]"
                            strokeWidth={2.2}
                            aria-hidden
                          />
                        ) : (
                          <Check
                            className="mt-0.5 size-[18px] shrink-0 text-[var(--brand)]"
                            strokeWidth={2.6}
                            aria-hidden
                          />
                        )}
                        <span
                          className={feature.coming_soon ? "text-[var(--muted)]" : ""}
                        >
                          {feature.name}
                          {feature.coming_soon && (
                            <span className="ml-1.5 rounded-full bg-[var(--chip)] px-2 py-0.5 text-xs font-medium whitespace-nowrap">
                              Coming soon
                            </span>
                          )}
                        </span>
                      </li>
                    );
                  })}
                </ul>
              </article>
            );
          })}
        </div>
      )}

      {/* Pay-per-order plans: only the starting price, on purpose (details on the phone). */}
      {perOrder.map((plan) => (
        <article
          key={plan.code}
          className="anim-fade-up flex w-full flex-col items-center gap-6 rounded-3xl border border-dashed border-[var(--brand)] bg-[var(--brand-soft)] p-7 text-center md:flex-row md:text-left"
        >
          <span className="grid size-14 shrink-0 place-items-center rounded-2xl bg-[var(--brand)] text-white dark:text-[#1a0f0f]">
            <Receipt className="size-7" strokeWidth={2} aria-hidden />
          </span>
          <div className="flex flex-1 flex-col gap-1">
            <h3 className="text-xl font-bold">{plan.name}</h3>
            <p className="text-[var(--muted)]">
              {plan.description ??
                "No monthly fee. Pay only when guests order."}
            </p>
          </div>
          <p className="flex flex-col items-center md:items-end">
            <span className="text-sm text-[var(--muted)]">From</span>
            <span className="flex items-baseline gap-1.5">
              <span className="text-4xl font-bold tracking-tight">
                {price(plan.price_per_order ?? 0, plan.currency)}
              </span>
              <span className="text-[var(--muted)]">/ order</span>
            </span>
          </p>
          <a href={call} className={buttonClass("brand", "lg")}>
            <Phone className="size-[18px]" aria-hidden />
            Call us
          </a>
        </article>
      ))}
    </div>
  );
}
