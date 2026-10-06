"use client";

import type { BoardItem } from "@/lib/staff";
import { usePrint } from "./use-print";

type Ticket = {
  id: number;
  number: number;
  table: string | null;
  area: string | null;
  source: "qr" | "waiter";
  note: string | null;
  placed_at: string;
  station: "kitchen" | "bar" | null;
  branch: string;
  printed_at: string;
  items: BoardItem[];
};

/** Kitchen or bar ticket, 80 mm: big table and number, items in Khmer and English, notes stand out. */
export default function PrintTicket({ orderId, station, auto }: { orderId: number; station: "kitchen" | "bar" | null; auto: boolean }) {
  const { data, error } = usePrint<Ticket>(`orders/${orderId}/ticket${station ? `?station=${station}` : ""}`, auto);

  if (error) return <p className="p-6 text-[var(--danger)]">{error}</p>;
  if (!data) return <p className="p-6 text-[var(--muted)]">Loading...</p>;

  const time = new Date(data.placed_at).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" });

  return (
    <>
      <div className="no-print flex justify-center gap-2 p-3">
        <button type="button" onClick={() => window.print()} className="rounded-xl bg-[var(--brand)] px-5 py-2.5 font-semibold text-white">🖨️ Print</button>
        <button type="button" onClick={() => window.close()} className="rounded-xl border border-[var(--line)] px-5 py-2.5 font-semibold">Close</button>
      </div>
      <div className="paper">
        <div className="flex items-baseline justify-between">
          <p className="text-3xl font-black">{data.table ?? "—"}</p>
          <p className="text-2xl font-bold">#{data.number}</p>
        </div>
        <p className="flex justify-between text-sm">
          <span>{data.station ? data.station.toUpperCase() : "ALL"} · {data.branch}</span>
          <span>{time}{data.source === "waiter" ? " · waiter" : ""}</span>
        </p>
        <hr className="my-1.5 border-0 border-t-2 border-black" />
        {data.items.length === 0 ? (
          <p className="py-2 text-center">Nothing for this station.</p>
        ) : (
          <ul className="flex flex-col gap-2">
            {data.items.map((item, i) => (
              <li key={i}>
                <p className="text-lg font-bold leading-tight">
                  {item.quantity} × {item.name.km || item.name.en}
                </p>
                {item.name.km && item.name.en && <p className="text-sm">{item.name.en}</p>}
                {item.options.length > 0 && <p className="text-sm">— {item.options.map((o) => o.km || o.en).join(", ")}</p>}
                {item.note && <p className="mt-0.5 border border-black px-1 text-sm font-bold">📝 {item.note}</p>}
              </li>
            ))}
          </ul>
        )}
        {data.note && (
          <>
            <hr className="my-1.5 border-0 border-t border-dashed border-black" />
            <p className="font-bold">📝 {data.note}</p>
          </>
        )}
      </div>
    </>
  );
}
