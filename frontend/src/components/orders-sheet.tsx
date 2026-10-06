"use client";

import type { Lang, OrderStatus, TableSessionState } from "@/lib/types";
import { formatMoney } from "@/lib/money";
import { pick, t } from "@/lib/i18n";
import Sheet from "./sheet";

const STEPS: OrderStatus[] = ["placed", "preparing", "ready", "served"];

function stepIndex(status: OrderStatus): number {
  if (status === "accepted") return 0;
  if (status === "completed") return 3;
  return STEPS.indexOf(status);
}

export default function OrdersSheet({
  session,
  lang,
  currency,
  busy,
  onCall,
  onClose,
}: {
  session: TableSessionState;
  lang: Lang;
  currency: "USD" | "KHR";
  busy: "waiter" | "bill" | null;
  onCall: (type: "waiter" | "bill") => void;
  onClose: () => void;
}) {
  const waiterOpen = session.requests.some((r) => r.type === "waiter");
  const billOpen = session.requests.some((r) => r.type === "bill") || session.status === "bill_requested";

  return (
    <Sheet onClose={onClose} label={t("myOrders", lang)}>
      <h2 className="text-xl font-semibold">{t("myOrders", lang)}</h2>

      <ul className="flex flex-col gap-3">
        {session.orders.map((order) => {
          const cancelled = order.status === "cancelled";
          const current = stepIndex(order.status);
          return (
            <li key={order.id} className="rounded-xl border border-[var(--line)] p-3">
              <div className="flex items-center justify-between">
                <p className="font-semibold">
                  {t("orderNo", lang)} #{order.number}
                </p>
                <span
                  className={`rounded-full px-2.5 py-1 text-xs font-semibold ${
                    cancelled
                      ? "bg-[var(--danger-bg)] text-[var(--danger)]"
                      : order.status === "ready"
                        ? "bg-[var(--brand)] text-white"
                        : "bg-[var(--chip)]"
                  }`}
                >
                  {t(`st_${order.status}` as Parameters<typeof t>[0], lang)}
                </span>
              </div>

              {!cancelled && (
                <ol className="mt-3 grid grid-cols-4 gap-1" aria-label="Progress">
                  {STEPS.map((step, i) => (
                    <li key={step} className="flex flex-col gap-1">
                      <span className={`h-1.5 rounded-full ${i <= current ? "bg-[var(--brand)]" : "bg-[var(--line)]"}`} />
                      <span className={`text-[11px] ${i === current ? "font-semibold" : "text-[var(--muted)]"}`}>
                        {t(`st_${step}` as Parameters<typeof t>[0], lang)}
                      </span>
                    </li>
                  ))}
                </ol>
              )}

              <ul className="mt-3 flex flex-col gap-1 text-sm">
                {order.items.map((item, i) => (
                  <li key={i} className="flex justify-between gap-3">
                    <span className="min-w-0">
                      {item.quantity} × {pick(item.name, lang)}
                      {item.options.length > 0 && (
                        <span className="text-[var(--muted)]"> · {item.options.map((o) => pick(o, lang)).join(", ")}</span>
                      )}
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
        <dl className="flex flex-col gap-1 border-t border-[var(--line)] pt-3 text-sm tabular-nums">
          <div className="flex justify-between"><dt>{t("subtotal", lang)}</dt><dd>{formatMoney(session.subtotal, currency)}</dd></div>
          {session.bill.discount_total > 0 && (
            <div className="flex justify-between"><dt>{t("discount", lang)}</dt><dd>−{formatMoney(session.bill.discount_total, currency)}</dd></div>
          )}
          {session.bill.service_charge > 0 && (
            <div className="flex justify-between"><dt>{t("service", lang)}</dt><dd>{formatMoney(session.bill.service_charge, currency)}</dd></div>
          )}
          {session.bill.vat > 0 && (
            <div className="flex justify-between"><dt>{t("vat", lang)}</dt><dd>{formatMoney(session.bill.vat, currency)}</dd></div>
          )}
          <div className="flex justify-between text-base font-semibold">
            <dt>{t("bill", lang)} #{session.bill.number}</dt>
            <dd className="text-right">
              {formatMoney(session.bill.total, currency)}
              {currency === "USD" && <span className="block text-xs font-normal text-[var(--muted)]">{formatMoney(session.bill.total_khr, "KHR")}</span>}
            </dd>
          </div>
          {session.bill.paid_total > 0 && (
            <>
              <div className="flex justify-between"><dt>{t("paid", lang)}</dt><dd>{formatMoney(session.bill.paid_total, currency)}</dd></div>
              <div className="flex justify-between font-semibold">
                <dt>{t("leftToPay", lang)}</dt>
                <dd>{formatMoney(Math.max(0, session.bill.total - session.bill.paid_total), currency)}</dd>
              </div>
            </>
          )}
        </dl>
      ) : (
        <div className="flex justify-between border-t border-[var(--line)] pt-3 font-semibold tabular-nums">
          <span>{t("visitTotal", lang)}</span>
          <span>{formatMoney(session.subtotal, currency)}</span>
        </div>
      )}

      <div className="grid grid-cols-2 gap-2">
        <button
          type="button"
          disabled={waiterOpen || busy !== null}
          onClick={() => onCall("waiter")}
          className="rounded-xl border border-[var(--line)] px-3 py-3 text-sm font-semibold disabled:opacity-60"
        >
          {waiterOpen ? t("waiterComing", lang) : t("callWaiter", lang)}
        </button>
        <button
          type="button"
          disabled={billOpen || busy !== null}
          onClick={() => onCall("bill")}
          className="rounded-xl bg-[var(--brand)] px-3 py-3 text-sm font-semibold text-white disabled:opacity-60"
        >
          {billOpen ? t("billComing", lang) : t("requestBill", lang)}
        </button>
      </div>
    </Sheet>
  );
}
