"use client";

import Link from "next/link";
import { Map as MapIcon } from "lucide-react";
import { useEffect, useMemo, useState } from "react";
import { floorKey, type FloorData } from "@/lib/floor";
import { SignedOut, loadSession, staffApi, type CashierTable } from "@/lib/staff";
import FloorStage, { type TableStatus } from "../floor/floor-stage";
import { EmptyState, buttonClass } from "../ui";

/** The cashier's tables on the restaurant's own floor plan, coloured by what each table needs. */
export default function FloorView({
  branchId,
  tables,
  money,
  onSelect,
}: {
  branchId: number;
  tables: CashierTable[];
  money: (minor: number) => string;
  onSelect: (table: CashierTable) => void;
}) {
  const [data, setData] = useState<FloorData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [current, setCurrent] = useState<string | null>(null);
  const [canDesign, setCanDesign] = useState(false);

  useEffect(() => {
    const first = window.setTimeout(async () => {
      const role = loadSession()?.branches.find((b) => b.id === branchId)?.role ?? "";
      setCanDesign(["owner", "manager"].includes(role));
      try {
        setData(await staffApi<FloorData>(`branches/${branchId}/floor-plan`));
      } catch (e) {
        if (!(e instanceof SignedOut)) setError(e instanceof Error ? e.message : "Can't load the floor plan.");
      }
    }, 0);
    return () => window.clearTimeout(first);
  }, [branchId]);

  const floors = useMemo(() => (data?.floors ?? []).filter((f) => f.objects.some((o) => o.table_id)), [data]);
  const names = useMemo(() => new Map((data?.areas ?? []).map((a) => [floorKey(a.id), a.name])), [data]);
  const lookup = useMemo(() => new Map((data?.tables ?? []).map((t) => [t.id, t])), [data]);
  const byId = useMemo(() => new Map(tables.map((t) => [t.id, t])), [tables]);

  const status = useMemo(() => {
    const m = new Map<number, TableStatus>();
    tables.forEach((t) => {
      const s = t.session;
      if (!s) m.set(t.id, { tone: "free" });
      else m.set(t.id, { tone: s.status === "bill_requested" ? "bill" : "busy", line: money(s.total - s.paid_total) });
    });
    return m;
  }, [tables, money]);

  if (error) return <p className="rounded-2xl bg-[var(--danger-bg)] p-4 text-sm text-[var(--danger)]">{error}</p>;
  if (!data) return <div className="skeleton h-[65dvh] rounded-3xl" />;

  if (floors.length === 0) {
    return (
      <div className="flex flex-col items-center gap-4">
        <EmptyState icon={MapIcon} title="No floor plan yet" text="Draw your shop once (tables, chairs, bar, walls) and this screen shows every table where it really is." />
        {canDesign && (
          <Link href={`/staff/floor?branch=${branchId}`} className={buttonClass("brand", "md")}>
            <MapIcon className="size-[18px]" /> Design the floor plan
          </Link>
        )}
      </div>
    );
  }

  const active = floors.find((f) => floorKey(f.area_id) === current) ?? floors[0];

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center gap-2">
        {floors.length > 1 && (
          <div className="flex gap-1 rounded-2xl bg-[var(--chip)] p-1">
            {floors.map((f) => {
              const key = floorKey(f.area_id);
              return (
                <button
                  key={key}
                  type="button"
                  onClick={() => setCurrent(key)}
                  className={`h-9 rounded-xl px-4 text-sm font-semibold transition ${active === f ? "bg-[var(--surface)] shadow-soft" : "text-[var(--muted)]"}`}
                >
                  {names.get(key) ?? "Main floor"}
                </button>
              );
            })}
          </div>
        )}
        <div className="flex flex-wrap items-center gap-3 text-xs font-semibold text-[var(--muted)]">
          <Legend color="rgb(20 26 24 / .82)" label="Free" />
          <Legend color="#0d7a5a" label="Eating" />
          <Legend color="#f0a92e" label="Wants the bill" />
        </div>
        {canDesign && (
          <Link href={`/staff/floor?branch=${branchId}`} className={buttonClass("neutral", "sm", "ml-auto")}>
            <MapIcon className="size-4" /> Edit plan
          </Link>
        )}
      </div>
      <FloorStage
        key={floorKey(active.area_id)}
        plan={active}
        tables={lookup}
        status={status}
        onTableClick={(id) => {
          const table = byId.get(id);
          if (table) onSelect(table);
        }}
        className="h-[68dvh] min-h-[420px] rounded-3xl bg-[radial-gradient(circle_at_50%_30%,var(--surface),var(--bg))] ring-1 ring-[var(--line)]"
      />
      <p className="text-center text-xs text-[var(--muted)]">Tap a green or amber table to open its bill. Drag to look around, scroll to zoom.</p>
    </div>
  );
}

function Legend({ color, label }: { color: string; label: string }) {
  return (
    <span className="inline-flex items-center gap-1.5">
      <span className="size-3 rounded-full" style={{ background: color }} />
      {label}
    </span>
  );
}
