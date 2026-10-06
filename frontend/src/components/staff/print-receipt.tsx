"use client";

/* eslint-disable @next/next/no-img-element -- logo and KHQR come from the restaurant's own storage */

import { formatMoney } from "@/lib/money";
import type { Names } from "@/lib/types";
import { usePrint } from "./use-print";

type Receipt = {
  company: { name: string; logo_url: string | null; khqr_url: string | null };
  branch: { name: string; address: string | null; phone: string | null; header: string | null; footer: string | null };
  bill: {
    number: number;
    status: "open" | "paid" | "void";
    business_date: string;
    opened_at: string;
    paid_at: string | null;
    table: string | null;
    area: string | null;
    currency: "USD" | "KHR";
    khr_per_usd: number;
    subtotal: number;
    adjustments: { label: string; amount: number }[];
    service_charge: number;
    service_charge_bp: number;
    vat: number;
    vat_bp: number;
    prices_include_vat: boolean;
    vat_included: number;
    total: number;
    total_khr: number;
    paid_total: number;
    remaining: number;
    remaining_khr: number;
  };
  lines: { name: Names; options: Names[]; note: string | null; quantity: number; unit_price: number; line_total: number }[];
  payments: {
    method: string;
    amount: number;
    tendered_amount: number | null;
    tendered_currency: "USD" | "KHR" | null;
    change_amount: number;
    change_currency: "USD" | "KHR" | null;
    reference: string | null;
  }[];
  cashier: string;
  printed_at: string;
};

const METHOD: Record<string, string> = { cash: "សាច់ប្រាក់ Cash", khqr: "KHQR", card: "កាត Card", other: "ផ្សេងៗ Other" };

/** Customer bill (before paying) or receipt (after), 80 mm wide, Khmer + English. */
export default function PrintReceipt({ billId, auto }: { billId: number; auto: boolean }) {
  const { data, error } = usePrint<Receipt>(`bills/${billId}/receipt`, auto);

  if (error) return <p className="p-6 text-[var(--danger)]">{error}</p>;
  if (!data) return <p className="p-6 text-[var(--muted)]">Loading...</p>;

  const { company, branch, bill } = data;
  const money = (minor: number) => formatMoney(minor, bill.currency);
  const when = new Date(bill.paid_at ?? data.printed_at).toLocaleString("en-GB", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
  const paid = bill.status === "paid";

  return (
    <>
      <Toolbar />
      <div className="paper">
        <header className="flex flex-col items-center gap-0.5 text-center">
          {company.logo_url && <img src={company.logo_url} alt="" className="mb-1 h-14 w-14 rounded-full object-cover grayscale" />}
          <p className="text-base font-bold">{company.name}</p>
          <p>{branch.name}</p>
          {branch.address && <p>{branch.address}</p>}
          {branch.phone && <p>☎ {branch.phone}</p>}
          {branch.header && <p className="whitespace-pre-line">{branch.header}</p>}
        </header>

        <Rule />
        <p className="text-center text-sm font-bold">
          {bill.status === "void" ? "VOID" : paid ? "បង្កាន់ដៃ · RECEIPT" : "វិក្កយបត្រ · BILL"}
        </p>
        <Row left={`No. ${bill.number}`} right={when} />
        <Row left={`តុ Table ${bill.table ?? "—"}${bill.area ? ` · ${bill.area}` : ""}`} right={paid ? data.cashier : ""} />
        <Rule />

        <ul className="flex flex-col gap-1">
          {data.lines.map((line, i) => (
            <li key={i}>
              <div className="flex justify-between gap-2">
                <span className="min-w-0">
                  {line.quantity} × {line.name.km || line.name.en}
                </span>
                <span className="shrink-0 tabular-nums">{money(line.line_total)}</span>
              </div>
              <div className="pl-4 text-[11px]">
                {line.name.km && line.name.en ? line.name.en : ""}
                {line.options.length > 0 && ` · ${line.options.map((o) => o.en || o.km).join(", ")}`}
                {line.quantity > 1 && ` · @${money(line.unit_price)}`}
                {line.note && ` · “${line.note}”`}
              </div>
            </li>
          ))}
        </ul>

        <Rule />
        <Row left="សរុបរង Subtotal" right={money(bill.subtotal)} />
        {bill.adjustments.map((a, i) => (
          <Row key={i} left={`បញ្ចុះតម្លៃ ${a.label}`} right={`−${money(a.amount)}`} />
        ))}
        {bill.service_charge > 0 && <Row left={`សេវា Service ${bill.service_charge_bp / 100}%`} right={money(bill.service_charge)} />}
        {bill.vat > 0 && <Row left={`អាករ VAT ${bill.vat_bp / 100}%`} right={money(bill.vat)} />}
        <div className="my-1 flex justify-between border-y border-black py-1 text-base font-bold">
          <span>សរុប TOTAL</span>
          <span className="tabular-nums">{money(bill.total)}</span>
        </div>
        {bill.currency === "USD" && <Row left={`ជាប្រាក់រៀល In riel (${bill.khr_per_usd.toLocaleString("en-US")}៛/$)`} right={formatMoney(bill.total_khr, "KHR")} bold />}
        {bill.prices_include_vat && bill.vat_included > 0 && <Row left={`Includes VAT ${bill.vat_bp / 100}%`} right={money(bill.vat_included)} small />}

        {data.payments.length > 0 && (
          <>
            <Rule />
            {data.payments.map((p, i) => (
              <div key={i}>
                <Row left={METHOD[p.method] ?? p.method} right={money(p.amount)} />
                {p.tendered_amount !== null && p.tendered_currency && (
                  <Row left="  ទទួល Received" right={formatMoney(p.tendered_amount, p.tendered_currency)} small />
                )}
                {p.change_amount > 0 && p.change_currency && (
                  <Row left="  ប្រាក់អាប់ Change" right={formatMoney(p.change_amount, p.change_currency)} small />
                )}
                {p.reference && <Row left="  Ref" right={p.reference} small />}
              </div>
            ))}
          </>
        )}

        {bill.status === "open" && bill.remaining > 0 && (
          <>
            <Rule />
            {bill.paid_total > 0 && <Row left="នៅសល់ Left to pay" right={money(bill.remaining)} bold />}
            {company.khqr_url && (
              <div className="mt-2 flex flex-col items-center gap-1">
                <img src={company.khqr_url} alt="KHQR" className="w-44" />
                <p className="text-center text-[11px]">ស្កេនដើម្បីបង់ · Scan to pay {money(bill.remaining)}{bill.currency === "USD" ? ` / ${formatMoney(bill.remaining_khr, "KHR")}` : ""}</p>
              </div>
            )}
          </>
        )}

        <Rule />
        <footer className="text-center">
          {branch.footer ? <p className="whitespace-pre-line">{branch.footer}</p> : <p>សូមអរគុណ! Thank you!</p>}
          <p className="mt-1 text-[10px]">Roumdoul Order</p>
        </footer>
      </div>
    </>
  );
}

function Toolbar() {
  return (
    <div className="no-print flex justify-center gap-2 p-3">
      <button type="button" onClick={() => window.print()} className="rounded-xl bg-[var(--brand)] px-5 py-2.5 font-semibold text-white">🖨️ Print</button>
      <button type="button" onClick={() => window.close()} className="rounded-xl border border-[var(--line)] px-5 py-2.5 font-semibold">Close</button>
    </div>
  );
}

function Rule() {
  return <hr className="my-1.5 border-0 border-t border-dashed border-black" />;
}

function Row({ left, right, bold, small }: { left: string; right: string; bold?: boolean; small?: boolean }) {
  return (
    <div className={`flex justify-between gap-2 ${bold ? "font-bold" : ""} ${small ? "text-[11px]" : ""}`}>
      <span className="min-w-0 whitespace-pre-wrap">{left}</span>
      <span className="shrink-0 tabular-nums">{right}</span>
    </div>
  );
}
