"use client";

import { BellRing, ChefHat, ConciergeBell, ReceiptText, UtensilsCrossed, XCircle, type LucideIcon } from "lucide-react";
import type { Lang, OrderStatus, TableSessionState } from "@/lib/types";
import { formatMoney } from "@/lib/money";
import { pick, t } from "@/lib/i18n";
import Sheet from "./sheet";
import { SuccessMark } from "./ui";

const STEPS: { status: OrderStatus; icon: LucideIcon }[] = [
  { status: "placed", icon: ReceiptText },
  { status: "preparing", icon: ChefHat },
  { status: "ready", icon: BellRing },
  { status: "served", icon: UtensilsCrossed },
];

function stepIndex(status: OrderStatus): number {
  if (status === "accepted") return 0;
  if (status === "completed") return 3;
  return STEPS.findIndex((s) => s.status === status);
}

export default function OrdersSheet({
  session,
  lang,
  currency,
  busy,
  justSent,
  onCall,
  onClose,
}: {
  session: TableSessionState;
  lang: Lang;
  currency: "USD" | "KHR";
  busy: "waiter" | "bill" | null;
  /** Show the "Order sent!" moment at the top (right after sending). */
  justSent: boolean;
  onCall: (type: "waiter" | "bill") => void;
  onClose: () => void;
}) {
  const waiterOpen = session.requests.some((r) => r.type === "waiter");
  const billOpen = session.requests.some((r) => r.type === "bill") || session.status === "bill_requested";
  const orders = [...session.orders].reverse();

  return (
    <Sheet onClose={onClose} label={t("myOrders", lang)} size="lg">
      {justSent ? (
        <div className="flex flex-col items-center gap-2 pt-2 text-center">
          <SuccessMark className="size-16" />
          <h2 className="anim-fade-up text-2xl font-bold" style={{ "--i": 3 } as React.CSSProperties}>{t("orderSent", lang)}</h2>
          <p className="anim-fade-up text-[var(--muted)]" style={{ "--i": 4 } as React.CSSProperties}>{t("orderSentText", lang)}</p>
        </div>
      ) : (
        <h2 className="flex items-center gap-2 text-xl font-bold">
          <ReceiptText className="size-5 text-[var(--brand)]" /> {t("myOrders", lang)}
        </h2>
      )}

      <ul className="flex flex-col gap-3">
        {orders.map((order, oi) => {
          const cancelled = order.status === "cancelled";
          const current = stepIndex(order.status);
          return (
            <li key={order.id} className="anim-fade-up rounded-3xl border border-[var(--line)] bg-[var(--surface-2)] p-4" style={{ "--i": oi + 4 } as React.CSSProperties}>
              <div className="flex items-center justify-between gap-2">
                <p className="font-bold">
                  {t("orderNo", lang)} <span className="text-[var(--muted)]">#{order.number}</span>
                </p>
                <span
                  className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-bold ${
                    cancelled
                      ? "bg-[var(--danger-bg)] text-[var(--danger)]"
                      : order.status === "ready"
                        ? "bg-[var(--brand)] text-white dark:text-[#06140f]"
                        : "bg-[var(--brand-soft)] text-[var(--brand-strong)]"
                  }`}
                >
                  {cancelled && <XCircle className="size-3.5" />}
                  {t(`st_${order.status}` as Parameters<typeof t>[0], lang)}
                </span>
              </div>

              {!cancelled && (
                <ol className="mt-4 grid grid-cols-4" aria-label="Progress">
                  {STEPS.map(({ status, icon: Icon }, i) => {
                    const done = i < current;
                    const now = i === current;
                    return (
                      <li key={status} className="relative flex flex-col items-center gap-1.5 text-center">
                        {i > 0 && (
                          <span className="absolute top-5 right-1/2 -z-0 h-1 w-full -translate-y-1/2 overflow-hidden rounded-full bg-[var(--line)]">
                            <span className={`block h-full bg-[var(--brand)] transition-all duration-700 ${i <= current ? "w-full" : "w-0"}`} />
                          </span>
                        )}
                        <span
                          className={`relative z-[1] grid size-10 place-items-center rounded-full transition-colors duration-500 ${
                            done || now ? "bg-[var(--brand)] text-white dark:text-[#06140f]" : "bg-[var(--chip)] text-[var(--muted)]"
                          } ${now && order.status !== "served" && order.status !== "completed" ? "anim-ring" : ""}`}
                        >
                          <Icon className="size-[18px]" strokeWidth={2.2} />
                        </span>
                        <span className={`text-[11px] leading-tight ${now ? "font-bold" : "text-[var(--muted)]"}`}>{t(`st_${status}` as Parameters<typeof t>[0], lang)}</span>
                      </li>
                    );
                  })}
                </ol>
              )}

              <ul className="mt-4 flex flex-col gap-1 text-sm">
                {order.items.map((item, i) => (
                  <li key={i} className="flex justify-between gap-3">
                    <span className="min-w-0">
                      <span className="font-semibold">{item.quantity}×</span> {pick(item.name, lang)}
                      {item.options.length > 0 && <span className="text-[var(--muted)]"> · {item.options.map((o) => pick(o, lang)).join(", ")}</span>}
                    </span>
                    <span className="shrink-0 tabular-nums">{formatMoney(item.line_total, currency)}</span>
                  </li>
                ))}
              </ul>
            </li>
          );
        })}
      </ul>

      {session.bill ? (
        <dl className="flex flex-col gap-1 rounded-3xl bg-[var(--surface-2)] p-4 text-sm tabular-nums">
          <div className="flex justify-between"><dt>{t("subtotal", lang)}</dt><dd>{formatMoney(session.subtotal, currency)}</dd></div>
          {session.bill.discount_total > 0 && (
            <div className="flex justify-between text-[var(--brand)]"><dt>{t("discount", lang)}</dt><dd>−{formatMoney(session.bill.discount_total, currency)}</dd></div>
          )}
          {session.bill.service_charge > 0 && (
            <div className="flex justify-between"><dt>{t("service", lang)}</dt><dd>{formatMoney(session.bill.service_charge, currency)}</dd></div>
          )}
          {session.bill.vat > 0 && (
            <div className="flex justify-between"><dt>{t("vat", lang)}</dt><dd>{formatMoney(session.bill.vat, currency)}</dd></div>
          )}
          <div className="mt-1 flex justify-between border-t border-dashed border-[var(--line)] pt-2 text-base font-bold">
            <dt>{t("bill", lang)} #{session.bill.number}</dt>
            <dd className="text-right">
              {formatMoney(session.bill.total, currency)}
              {currency === "USD" && <span className="block text-xs font-normal text-[var(--muted)]">{formatMoney(session.bill.total_khr, "KHR")}</span>}
            </dd>
          </div>
          {session.bill.paid_total > 0 && (
            <>
              <div className="flex justify-between"><dt>{t("paid", lang)}</dt><dd>{formatMoney(session.bill.paid_total, currency)}</dd></div>
              <div className="flex justify-between font-bold">
                <dt>{t("leftToPay", lang)}</dt>
                <dd>{formatMoney(Math.max(0, session.bill.total - session.bill.paid_total), currency)}</dd>
              </div>
            </>
          )}
        </dl>
      ) : (
        <div className="flex justify-between rounded-3xl bg-[var(--surface-2)] p-4 font-bold tabular-nums">
          <span>{t("visitTotal", lang)}</span>
          <span>{formatMoney(session.subtotal, currency)}</span>
        </div>
      )}

      <div className="grid grid-cols-2 gap-2">
        <button
          type="button"
          disabled={waiterOpen || busy !== null}
          onClick={() => onCall("waiter")}
          className="flex h-12 items-center justify-center gap-2 rounded-2xl border border-[var(--line)] px-3 text-sm font-bold transition hover:bg-[var(--surface-2)] active:scale-[0.98] disabled:opacity-60"
        >
          <ConciergeBell className="size-[18px]" />
          {waiterOpen ? t("waiterComing", lang) : t("callWaiter", lang)}
        </button>
        <button
          type="button"
          disabled={billOpen || busy !== null}
          onClick={() => onCall("bill")}
          className="flex h-12 items-center justify-center gap-2 rounded-2xl bg-[var(--brand)] px-3 text-sm font-bold text-white transition hover:bg-[var(--brand-strong)] active:scale-[0.98] disabled:opacity-60 dark:text-[#06140f]"
        >
          <ReceiptText className="size-[18px]" />
          {billOpen ? t("billComing", lang) : t("requestBill", lang)}
        </button>
      </div>
    </Sheet>
  );
}
