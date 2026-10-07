"use client";

import {
  Armchair,
  Box,
  CircleHelp,
  Copy,
  Layers,
  Minus,
  MousePointerClick,
  Move,
  Plus,
  Redo2,
  RotateCcw,
  RotateCw,
  Save,
  Trash2,
  Undo2,
  type LucideIcon,
} from "lucide-react";
import { useCallback, useEffect, useEffectEvent, useMemo, useRef, useState } from "react";
import {
  GROUPS,
  KINDS,
  MATERIALS,
  TABLE_KINDS,
  emptyPlan,
  floorKey,
  newObjectId,
  type FloorData,
  type FloorKind,
  type FloorMaterial,
  type FloorObject,
  type FloorPlan,
} from "@/lib/floor";
import { SignedOut, staffApi } from "@/lib/staff";
import Sheet from "../sheet";
import { Alert, Button, inputClass } from "../ui";
import { BackLink, SignedOutScreen } from "../staff/chrome";
import FloorStage from "./floor-stage";
import KindPreview from "./kind-preview";

type Floors = Record<string, FloorPlan>;

const HELP_KEY = "ro-floor-help-seen";

const STEPS: { icon: LucideIcon; title: string; km: string; text: string }[] = [
  {
    icon: Layers,
    title: "Pick a floor",
    km: "ជ្រើសជាន់ ឬតំបន់",
    text: "Each area of your shop (Indoor, Terrace, VIP...) has its own floor. Choose it in the tabs at the top. Tap an empty spot to set the floor material (wood, tile, garden...) and its size in metres.",
  },
  {
    icon: MousePointerClick,
    title: "Add things",
    km: "បន្ថែមតុ កៅអី និងរបស់ផ្សេងៗ",
    text: "Tap a material on the left: tables, chairs, stools, sofas, the bar, cashier, kitchen, walls, doors, windows, plants, rugs and lamps. It appears in the middle of the floor.",
  },
  {
    icon: Move,
    title: "Move, turn and resize",
    km: "ផ្លាស់ទី បង្វិល និងប្តូរទំហំ",
    text: "Drag a thing to move it. Drag the green dot on its corner to resize. Use the turn buttons (or the R key) to rotate. Drag the empty floor to look around; scroll or use + / − to zoom.",
  },
  {
    icon: Armchair,
    title: "Tables and chairs",
    km: "តុ និងចំនួនកៅអី",
    text: "Every table on the plan is one of your QR tables (T1, T2...). Select a table to choose which one it is and set the number of chairs with + and −. Chairs place themselves around the table, as many as you like.",
  },
  {
    icon: Box,
    title: "See it in 3D",
    km: "មើលជា 3D",
    text: "Switch between 2D (from above, easiest for placing) and 3D (like a game) with the button at the bottom right. The turn button walks the camera around your shop.",
  },
  {
    icon: Save,
    title: "Save",
    km: "រក្សាទុក",
    text: "Tap Save. The cashier screen then shows this plan live: green tables are eating, amber ones want the bill. Tap a table there to open its bill.",
  },
];

export default function FloorEditor({ branchId }: { branchId: number }) {
  const [data, setData] = useState<FloorData | null>(null);
  const [floors, setFloors] = useState<Floors>({});
  const [current, setCurrent] = useState("main");
  const [selected, setSelected] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [signedOut, setSignedOut] = useState(false);
  const [help, setHelp] = useState<number | null>(null);
  const [savedAt, setSavedAt] = useState<Record<string, string>>({});
  const [history, setHistory] = useState<{ past: Floors[]; future: Floors[] }>({ past: [], future: [] });
  const dragSnap = useRef<Floors | null>(null);

  const apply = useCallback((d: FloorData) => {
    const known = new Set(d.tables.map((t) => t.id));
    const next: Floors = { main: emptyPlan(null) };
    d.areas.forEach((a) => (next[floorKey(a.id)] = emptyPlan(a.id)));
    d.floors.forEach((f) => {
      // Tables that were switched off since the plan was saved drop out.
      next[floorKey(f.area_id)] = { ...f, objects: f.objects.filter((o) => !o.table_id || known.has(o.table_id)) };
    });
    setData(d);
    setFloors(next);
    setSavedAt(Object.fromEntries(Object.entries(next).map(([k, v]) => [k, JSON.stringify(v)])));
    setHistory({ past: [], future: [] });
    return next;
  }, []);

  useEffect(() => {
    const first = window.setTimeout(async () => {
      try {
        const d = await staffApi<FloorData>(`branches/${branchId}/floor-plan`);
        const next = apply(d);
        // Start on the floor that has something on it.
        const start = Object.keys(next).find((k) => next[k].objects.length > 0) ?? (d.areas.length ? floorKey(d.areas[0].id) : "main");
        setCurrent(start);
        let seen = false;
        try {
          seen = window.localStorage.getItem(HELP_KEY) === "1";
        } catch {
          /* storage blocked: show the guide */
        }
        if (!seen && d.can_edit) setHelp(0);
      } catch (e) {
        if (e instanceof SignedOut) setSignedOut(true);
        else setError(e instanceof Error ? e.message : "Can't load the floor plan.");
      }
    }, 0);
    return () => window.clearTimeout(first);
  }, [branchId, apply]);

  const plan = floors[current];
  const dirtyKeys = Object.keys(floors).filter((k) => JSON.stringify(floors[k]) !== savedAt[k]);
  const dirty = dirtyKeys.length > 0;
  const editable = data?.can_edit ?? false;
  const tables = useMemo(() => new Map((data?.tables ?? []).map((t) => [t.id, t])), [data]);
  const placed = useMemo(() => new Set(Object.values(floors).flatMap((f) => f.objects.map((o) => o.table_id).filter(Boolean))), [floors]);
  const unplaced = (data?.tables ?? []).filter((t) => !placed.has(t.id));
  const selectedObject = plan?.objects.find((o) => o.id === selected) ?? null;

  useEffect(() => {
    if (!dirty) return;
    const warn = (e: BeforeUnloadEvent) => e.preventDefault();
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [dirty]);

  useEffect(() => {
    if (!notice) return;
    const id = window.setTimeout(() => setNotice(null), 4000);
    return () => window.clearTimeout(id);
  }, [notice]);

  /** Change the current floor and remember the previous state for Undo. */
  const commit = (change: (p: FloorPlan) => FloorPlan) => {
      setHistory((h) => ({ past: [...h.past.slice(-60), floors], future: [] }));
      setFloors((f) => ({ ...f, [current]: change(f[current]) }));
  };

  const updateObject = (object: FloorObject, final = true) => {
      if (!final) {
        dragSnap.current ??= floors;
        setFloors((f) => ({ ...f, [current]: { ...f[current], objects: f[current].objects.map((o) => (o.id === object.id ? object : o)) } }));
        return;
      }
      const before = dragSnap.current ?? floors;
      dragSnap.current = null;
      setHistory((h) => ({ past: [...h.past.slice(-60), before], future: [] }));
      setFloors((f) => ({ ...f, [current]: { ...f[current], objects: f[current].objects.map((o) => (o.id === object.id ? object : o)) } }));
  };

  function undo() {
    const prev = history.past[history.past.length - 1];
    if (!prev) return;
    setHistory({ past: history.past.slice(0, -1), future: [floors, ...history.future] });
    setFloors(prev);
  }

  function redo() {
    const [next, ...rest] = history.future;
    if (!next) return;
    setHistory({ past: [...history.past, floors], future: rest });
    setFloors(next);
  }

  function add(kind: FloorKind, tableId?: number) {
    if (!plan) return;
    const info = KINDS[kind];
    let table_id: number | null = null;
    let seats: number | undefined;
    if (info.isTable) {
      const pickFrom = tableId ? unplaced.filter((t) => t.id === tableId) : unplaced;
      // Prefer tables that belong to this area.
      const table = pickFrom.find((t) => t.area_id === plan.area_id) ?? pickFrom[0];
      if (!table) {
        setNotice(
          `All ${data?.tables.length ?? 0} of your tables are already on the plan. To add more, go to the back office → Tables → Add tables, then come back.`,
        );
        return;
      }
      table_id = table.id;
      seats = table.seats ?? info.seats;
    }
    const n = plan.objects.length;
    const object: FloorObject = {
      id: newObjectId(),
      kind,
      w: info.w,
      h: info.h,
      x: Math.round((plan.width / 2 - info.w / 2 + ((n % 6) - 3) * 30) / 10) * 10,
      y: Math.round((plan.height / 2 - info.h / 2 + ((n % 4) - 2) * 30) / 10) * 10,
      rotation: 0,
      table_id,
      seats,
      label: ["bar_counter", "cashier", "kitchen", "door"].includes(kind) ? info.label : null,
      color: null,
    };
    commit((p) => ({ ...p, objects: [...p.objects, object] }));
    setSelected(object.id);
  }

  const remove = (id: string) => {
      commit((p) => ({ ...p, objects: p.objects.filter((o) => o.id !== id) }));
      setSelected(null);
  };

  const duplicate = (object: FloorObject) => {
      let table_id: number | null = null;
      if (object.table_id) {
        const table = unplaced[0];
        if (!table) {
          setNotice("No free QR table left to copy this table onto. Add more tables in the back office first.");
          return;
        }
        table_id = table.id;
      }
      const copy = { ...object, id: newObjectId(), x: object.x + 30, y: object.y + 30, table_id };
      commit((p) => ({ ...p, objects: [...p.objects, copy] }));
      setSelected(copy.id);
  };

  const rotate = (object: FloorObject, by: number) => updateObject({ ...object, rotation: (((object.rotation + by) % 360) + 360) % 360 });

  // Keyboard shortcuts for desktop users.
  const onKey = useEffectEvent((e: KeyboardEvent) => {
    const target = e.target as HTMLElement;
    if (["INPUT", "SELECT", "TEXTAREA"].includes(target.tagName)) return;
    const mod = e.ctrlKey || e.metaKey;
    if (mod && e.key.toLowerCase() === "z") {
      e.preventDefault();
      if (e.shiftKey) redo();
      else undo();
      return;
    }
    if (mod && e.key.toLowerCase() === "y") {
      e.preventDefault();
      redo();
      return;
    }
    if (!selectedObject) return;
    if (e.key === "Delete" || e.key === "Backspace") {
      e.preventDefault();
      remove(selectedObject.id);
    } else if (e.key.toLowerCase() === "r") {
      rotate(selectedObject, e.shiftKey ? -15 : 15);
    } else if (mod && e.key.toLowerCase() === "d") {
      e.preventDefault();
      duplicate(selectedObject);
    } else if (e.key === "Escape") {
      setSelected(null);
    } else if (e.key.startsWith("Arrow")) {
      e.preventDefault();
      const step = e.shiftKey ? 1 : 10;
      const dx = e.key === "ArrowLeft" ? -step : e.key === "ArrowRight" ? step : 0;
      const dy = e.key === "ArrowUp" ? -step : e.key === "ArrowDown" ? step : 0;
      updateObject({ ...selectedObject, x: selectedObject.x + dx, y: selectedObject.y + dy });
    }
  });

  useEffect(() => {
    if (!editable) return;
    const handler = (e: KeyboardEvent) => onKey(e);
    window.addEventListener("keydown", handler);
    return () => window.removeEventListener("keydown", handler);
  }, [editable]);

  async function save() {
    setSaving(true);
    setError(null);
    try {
      let latest: FloorData | null = null;
      for (const key of dirtyKeys) {
        const f = floors[key];
        latest = await staffApi<FloorData>(`branches/${branchId}/floor-plan`, {
          method: "PUT",
          body: { area_id: f.area_id, floor: f.floor, width: f.width, height: f.height, objects: f.objects },
        });
      }
      if (latest) apply(latest);
      setNotice("Saved. The cashier screen now shows this plan.");
    } catch (e) {
      if (e instanceof SignedOut) setSignedOut(true);
      else setError(e instanceof Error ? e.message : "Could not save.");
    } finally {
      setSaving(false);
    }
  }

  function closeHelp() {
    setHelp(null);
    try {
      window.localStorage.setItem(HELP_KEY, "1");
    } catch {
      /* fine: the guide shows again next time */
    }
  }

  if (signedOut) return <SignedOutScreen />;

  const floorTabs = data
    ? [
        ...(data.areas.length === 0 || (floors.main?.objects.length ?? 0) > 0 ? [{ key: "main", name: "Main floor" }] : []),
        ...data.areas.map((a) => ({ key: floorKey(a.id), name: a.name })),
      ]
    : [];

  return (
    <main className="flex min-h-dvh flex-col lg:h-dvh">
      {/* ---------- Top bar ---------- */}
      <header className="z-20 flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-[var(--line)] bg-[var(--surface)] px-4 py-3 sm:px-6">
        <BackLink />
        <div className="min-w-0">
          <h1 className="text-lg leading-tight font-bold">Floor plan</h1>
          <p className="text-xs text-[var(--muted)]">ប្លង់តុ · Design your shop in 3D</p>
        </div>

        <nav className="no-scrollbar order-last flex w-full gap-1 overflow-x-auto rounded-2xl bg-[var(--chip)] p-1 md:order-none md:ml-4 md:w-auto">
          {floorTabs.map((tab) => (
            <button
              key={tab.key}
              type="button"
              onClick={() => {
                setCurrent(tab.key);
                setSelected(null);
              }}
              className={`h-9 shrink-0 rounded-xl px-4 text-sm font-semibold transition ${
                current === tab.key ? "bg-[var(--surface)] text-[var(--fg)] shadow-soft" : "text-[var(--muted)] hover:text-[var(--fg)]"
              }`}
            >
              {tab.name}
              {dirtyKeys.includes(tab.key) && <span className="ml-1.5 inline-block size-1.5 rounded-full bg-[var(--accent)] align-middle" />}
            </button>
          ))}
        </nav>

        <div className="ml-auto flex items-center gap-1.5">
          {editable && (
            <>
              <Button tone="ghost" size="sm" icon={Undo2} onClick={undo} disabled={!history.past.length} aria-label="Undo" title="Undo (Ctrl+Z)" />
              <Button tone="ghost" size="sm" icon={Redo2} onClick={redo} disabled={!history.future.length} aria-label="Redo" title="Redo (Ctrl+Y)" />
            </>
          )}
          <Button tone="neutral" size="sm" icon={CircleHelp} onClick={() => setHelp(0)}>
            How to
          </Button>
          {editable && (
            <Button tone="brand" size="sm" icon={Save} onClick={save} disabled={!dirty || saving}>
              {saving ? "Saving..." : dirty ? "Save" : "Saved"}
            </Button>
          )}
        </div>
      </header>

      {error && (
        <div className="px-4 pt-3 sm:px-6">
          <Alert>{error}</Alert>
        </div>
      )}

      {!data || !plan ? (
        <div className="grid flex-1 gap-4 p-4 sm:p-6 lg:grid-cols-[260px_1fr_300px]">
          <div className="skeleton hidden rounded-3xl lg:block" />
          <div className="skeleton min-h-[60dvh] rounded-3xl" />
          <div className="skeleton hidden rounded-3xl lg:block" />
        </div>
      ) : (
        <div className="grid min-h-0 flex-1 lg:grid-cols-[260px_minmax(0,1fr)_300px] lg:grid-rows-1">
          {/* ---------- Materials ---------- */}
          {editable && (
            <aside className="order-2 border-t border-[var(--line)] bg-[var(--surface)] lg:order-none lg:overflow-y-auto lg:border-t-0 lg:border-r">
              <div className="flex flex-col gap-4 p-4">
                {unplaced.length > 0 && (
                  <section className="anim-fade-in rounded-2xl bg-[var(--accent-soft)] p-3">
                    <p className="text-sm font-bold text-[var(--warn)]">Not on the plan yet</p>
                    <p className="mb-2 text-xs text-[var(--muted)]">Tap a table to place it.</p>
                    <div className="flex flex-wrap gap-1.5">
                      {unplaced.map((t) => (
                        <button
                          key={t.id}
                          type="button"
                          onClick={() => add("table_square", t.id)}
                          className="h-8 rounded-full bg-[var(--surface)] px-3 text-sm font-bold shadow-soft transition hover:-translate-y-0.5"
                        >
                          {t.name}
                        </button>
                      ))}
                    </div>
                  </section>
                )}
                {GROUPS.map((group) => (
                  <section key={group.key}>
                    <h2 className="mb-2 text-xs font-bold tracking-wider text-[var(--muted)] uppercase">{group.label}</h2>
                    <div className="grid grid-cols-4 gap-2 sm:grid-cols-6 lg:grid-cols-3">
                      {(Object.keys(KINDS) as FloorKind[])
                        .filter((k) => KINDS[k].group === group.key)
                        .map((kind, i) => (
                          <button
                            key={kind}
                            type="button"
                            onClick={() => add(kind)}
                            title={`${KINDS[kind].label} · ${KINDS[kind].km}`}
                            className="anim-fade-up group flex flex-col items-center gap-1 rounded-2xl border border-[var(--line)] bg-[var(--surface-2)] px-1 py-2 text-center transition hover:-translate-y-0.5 hover:border-[var(--brand)] hover:shadow-card active:scale-95"
                            style={{ "--i": i } as React.CSSProperties}
                          >
                            <KindPreview kind={kind} className="size-10 transition group-hover:scale-110" />
                            <span className="text-[11px] leading-tight font-semibold">{KINDS[kind].label}</span>
                          </button>
                        ))}
                    </div>
                  </section>
                ))}
              </div>
            </aside>
          )}

          {/* ---------- Stage ---------- */}
          <div className={`relative order-1 min-h-[62dvh] bg-[radial-gradient(circle_at_50%_30%,var(--surface-2),var(--bg))] lg:order-none lg:min-h-0 ${editable ? "" : "lg:col-span-3"}`}>
            <FloorStage
              plan={plan}
              tables={tables}
              editable={editable}
              selectedId={selected}
              onSelect={setSelected}
              onChange={updateObject}
              className="absolute inset-0"
            />
            {!editable && (
              <div className="absolute top-3 left-3">
                <Alert tone="warn">Only owners and managers can change the floor plan.</Alert>
              </div>
            )}
            {notice && (
              <div role="status" className="anim-fade-up absolute top-3 left-1/2 z-10 w-[min(92%,34rem)] -translate-x-1/2 rounded-2xl bg-[var(--fg)] px-4 py-3 text-sm font-semibold text-[var(--surface)] shadow-float">
                {notice}
              </div>
            )}
            {editable && plan.objects.length === 0 && (
              <div className="pointer-events-none absolute inset-x-0 top-6 flex justify-center">
                <p className="anim-fade-up rounded-2xl bg-[var(--surface)] px-4 py-2.5 text-sm font-semibold shadow-card">
                  Empty floor. Tap a table or anything on the left to start.
                </p>
              </div>
            )}
          </div>

          {/* ---------- Inspector ---------- */}
          {editable && (
            <aside className="order-3 border-t border-[var(--line)] bg-[var(--surface)] lg:overflow-y-auto lg:border-t-0 lg:border-l">
              {selectedObject ? (
                <Inspector
                  key={selectedObject.id}
                  object={selectedObject}
                  data={data}
                  unplaced={unplaced}
                  onChange={(o) => updateObject(o)}
                  onRotate={(by) => rotate(selectedObject, by)}
                  onDuplicate={() => duplicate(selectedObject)}
                  onDelete={() => remove(selectedObject.id)}
                />
              ) : (
                <FloorSettings
                  plan={plan}
                  stats={{
                    tables: plan.objects.filter((o) => o.table_id).length,
                    seats: plan.objects.reduce((n, o) => n + (o.table_id ? (o.seats ?? 0) : o.kind === "chair" || o.kind === "stool" ? 1 : 0), 0),
                    total: data.tables.length,
                  }}
                  onChange={(change) => commit((p) => ({ ...p, ...change }))}
                />
              )}
            </aside>
          )}
        </div>
      )}

      {help !== null && (
        <Sheet onClose={closeHelp} label="How to use the floor plan" size="lg">
          <HelpGuide step={help} onStep={setHelp} onDone={closeHelp} />
        </Sheet>
      )}
    </main>
  );
}

/* ---------------- Side panels ---------------- */

function Stepper({ value, onChange, min, max, step = 1, format }: { value: number; onChange: (v: number) => void; min: number; max: number; step?: number; format?: (v: number) => string }) {
  return (
    <div className="flex items-center gap-1 rounded-2xl bg-[var(--chip)] p-1">
      <button
        type="button"
        onClick={() => onChange(Math.max(min, value - step))}
        disabled={value <= min}
        className="grid size-9 place-items-center rounded-xl transition hover:bg-[var(--surface)] disabled:opacity-40"
        aria-label="Less"
      >
        <Minus className="size-4" strokeWidth={2.6} />
      </button>
      <span key={value} className="anim-pop min-w-14 flex-1 text-center font-bold tabular-nums">{format ? format(value) : value}</span>
      <button
        type="button"
        onClick={() => onChange(Math.min(max, value + step))}
        disabled={value >= max}
        className="grid size-9 place-items-center rounded-xl transition hover:bg-[var(--surface)] disabled:opacity-40"
        aria-label="More"
      >
        <Plus className="size-4" strokeWidth={2.6} />
      </button>
    </div>
  );
}

const metres = (cm: number) => `${(cm / 100).toFixed(cm % 100 === 0 ? 0 : 1)} m`;

function Inspector({
  object,
  data,
  unplaced,
  onChange,
  onRotate,
  onDuplicate,
  onDelete,
}: {
  object: FloorObject;
  data: FloorData;
  unplaced: FloorData["tables"];
  onChange: (o: FloorObject) => void;
  onRotate: (by: number) => void;
  onDuplicate: () => void;
  onDelete: () => void;
}) {
  const info = KINDS[object.kind];
  const table = data.tables.find((t) => t.id === object.table_id);
  const choices = [...(table ? [table] : []), ...unplaced];

  return (
    <div className="anim-slide-in-right flex flex-col gap-5 p-4">
      <div className="flex items-center gap-3">
        <span className="grid size-14 place-items-center rounded-2xl bg-[var(--surface-2)] ring-1 ring-[var(--line)]">
          <KindPreview kind={object.kind} className="size-11" />
        </span>
        <div>
          <p className="font-bold">{info.isTable && table ? `Table ${table.name}` : info.label}</p>
          <p className="text-sm text-[var(--muted)]">{info.km}</p>
        </div>
      </div>

      {info.isTable && (
        <>
          <label className="flex flex-col gap-1.5">
            <span className="text-sm font-semibold">Which QR table is this?</span>
            <select
              value={object.table_id ?? ""}
              onChange={(e) => {
                const next = data.tables.find((t) => t.id === Number(e.target.value));
                onChange({ ...object, table_id: next?.id ?? object.table_id });
              }}
              className={inputClass}
            >
              {choices.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name}
                  {t.area_id ? ` · ${data.areas.find((a) => a.id === t.area_id)?.name ?? ""}` : ""}
                </option>
              ))}
            </select>
          </label>

          <div className="flex flex-col gap-1.5">
            <span className="flex items-center gap-1.5 text-sm font-semibold">
              <Armchair className="size-4 text-[var(--muted)]" /> Chairs · កៅអី
            </span>
            <Stepper value={object.seats ?? 0} min={0} max={40} onChange={(seats) => onChange({ ...object, seats })} />
            {object.kind === "booth" && <span className="text-xs text-[var(--muted)]">Booths have benches; this number is just the seat count.</span>}
          </div>

          <div className="flex flex-col gap-1.5">
            <span className="text-sm font-semibold">Shape</span>
            <div className="grid grid-cols-4 gap-1.5">
              {TABLE_KINDS.map((kind) => (
                <button
                  key={kind}
                  type="button"
                  title={KINDS[kind].label}
                  onClick={() => onChange({ ...object, kind, w: KINDS[kind].w, h: KINDS[kind].h })}
                  className={`grid place-items-center rounded-2xl border-2 p-1.5 transition ${object.kind === kind ? "border-[var(--brand)] bg-[var(--brand-soft)]" : "border-[var(--line)] hover:border-[var(--muted)]"}`}
                >
                  <KindPreview kind={kind} className="size-9" />
                </button>
              ))}
            </div>
          </div>
        </>
      )}

      {!info.isTable && (
        <label className="flex flex-col gap-1.5">
          <span className="text-sm font-semibold">Label (optional)</span>
          <input
            value={object.label ?? ""}
            maxLength={40}
            onChange={(e) => onChange({ ...object, label: e.target.value || null })}
            className={inputClass}
            placeholder="e.g. Bar, Entrance"
          />
        </label>
      )}

      <div className="grid grid-cols-2 gap-3">
        <div className="flex flex-col gap-1.5">
          <span className="text-sm font-semibold">Width</span>
          <Stepper value={object.w} min={16} max={2000} step={10} format={metres} onChange={(w) => onChange({ ...object, w })} />
        </div>
        <div className="flex flex-col gap-1.5">
          <span className="text-sm font-semibold">Depth</span>
          <Stepper value={object.h} min={10} max={2000} step={10} format={metres} onChange={(h) => onChange({ ...object, h })} />
        </div>
      </div>

      <div className="flex flex-col gap-1.5">
        <span className="text-sm font-semibold">Turn · {object.rotation}°</span>
        <div className="grid grid-cols-3 gap-1.5">
          <Button tone="neutral" size="sm" icon={RotateCcw} onClick={() => onRotate(-15)}>15°</Button>
          <Button tone="neutral" size="sm" icon={RotateCw} onClick={() => onRotate(15)}>15°</Button>
          <Button tone="neutral" size="sm" icon={RotateCw} onClick={() => onRotate(90)}>90°</Button>
        </div>
      </div>

      {info.palette && (
        <div className="flex flex-col gap-1.5">
          <span className="text-sm font-semibold">Colour</span>
          <div className="flex flex-wrap gap-2">
            {info.palette.map((c) => {
              const on = (object.color ?? info.color) === c;
              return (
                <button
                  key={c}
                  type="button"
                  aria-label={`Colour ${c}`}
                  onClick={() => onChange({ ...object, color: c === info.color ? null : c })}
                  className={`size-9 rounded-full ring-offset-2 ring-offset-[var(--surface)] transition hover:scale-110 ${on ? "ring-2 ring-[var(--brand)]" : "ring-1 ring-black/10"}`}
                  style={{ background: c }}
                />
              );
            })}
          </div>
        </div>
      )}

      <div className="grid grid-cols-2 gap-2 border-t border-[var(--line)] pt-4">
        <Button tone="neutral" icon={Copy} onClick={onDuplicate}>Copy</Button>
        <Button tone="neutral" icon={Trash2} onClick={onDelete} className="text-[var(--danger)]">Delete</Button>
      </div>
      <p className="hidden text-xs leading-relaxed text-[var(--muted)] lg:block">
        Keys: drag to move · arrows nudge · R turn · Ctrl+D copy · Delete remove · Ctrl+Z undo · hold Alt while dragging for fine moves.
      </p>
    </div>
  );
}

function FloorSettings({ plan, stats, onChange }: { plan: FloorPlan; stats: { tables: number; seats: number; total: number }; onChange: (c: Partial<FloorPlan>) => void }) {
  return (
    <div className="anim-fade-in flex flex-col gap-5 p-4">
      <div>
        <p className="font-bold">This floor</p>
        <p className="text-sm text-[var(--muted)]">Tap anything on the plan to change it.</p>
      </div>

      <div className="grid grid-cols-2 gap-2">
        <div className="rounded-2xl bg-[var(--brand-soft)] p-3">
          <p className="text-2xl font-bold text-[var(--brand-strong)] tabular-nums">{stats.tables}<span className="text-sm font-semibold opacity-70">/{stats.total}</span></p>
          <p className="text-xs font-semibold text-[var(--muted)]">Tables placed</p>
        </div>
        <div className="rounded-2xl bg-[var(--accent-soft)] p-3">
          <p className="text-2xl font-bold text-[var(--warn)] tabular-nums">{stats.seats}</p>
          <p className="text-xs font-semibold text-[var(--muted)]">Seats</p>
        </div>
      </div>

      <div className="flex flex-col gap-1.5">
        <span className="text-sm font-semibold">Floor</span>
        <div className="grid grid-cols-3 gap-2">
          {(Object.keys(MATERIALS) as FloorMaterial[]).map((m) => (
            <button
              key={m}
              type="button"
              onClick={() => onChange({ floor: m })}
              className={`flex flex-col overflow-hidden rounded-2xl border-2 text-xs font-semibold transition hover:-translate-y-0.5 ${plan.floor === m ? "border-[var(--brand)]" : "border-[var(--line)]"}`}
            >
              <span className="h-12 w-full" style={{ ...MATERIALS[m].css, backgroundSize: MATERIALS[m].css.backgroundSize ?? "auto" }} />
              <span className="py-1">{MATERIALS[m].label}</span>
            </button>
          ))}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3">
        <div className="flex flex-col gap-1.5">
          <span className="text-sm font-semibold">Room width</span>
          <Stepper value={plan.width} min={400} max={4000} step={100} format={metres} onChange={(width) => onChange({ width })} />
        </div>
        <div className="flex flex-col gap-1.5">
          <span className="text-sm font-semibold">Room depth</span>
          <Stepper value={plan.height} min={300} max={4000} step={100} format={metres} onChange={(height) => onChange({ height })} />
        </div>
      </div>
    </div>
  );
}

function HelpGuide({ step, onStep, onDone }: { step: number; onStep: (n: number) => void; onDone: () => void }) {
  const s = STEPS[step];
  const Icon = s.icon;
  return (
    <div className="flex flex-col gap-5">
      <div className="flex items-center gap-1.5">
        {STEPS.map((_, i) => (
          <button
            key={i}
            type="button"
            onClick={() => onStep(i)}
            aria-label={`Step ${i + 1}`}
            className={`h-1.5 flex-1 rounded-full transition-colors duration-300 ${i <= step ? "bg-[var(--brand)]" : "bg-[var(--line)]"}`}
          />
        ))}
      </div>
      <div key={step} className="anim-slide-in-right flex flex-col items-center gap-4 py-2 text-center sm:flex-row sm:items-start sm:text-left">
        <span className="anim-float grid size-20 shrink-0 place-items-center rounded-3xl bg-[var(--brand-soft)] text-[var(--brand)]">
          <Icon className="size-9" strokeWidth={1.8} />
        </span>
        <div className="flex flex-col gap-1">
          <p className="text-xs font-bold tracking-wider text-[var(--muted)] uppercase">Step {step + 1} of {STEPS.length}</p>
          <h2 className="text-2xl font-bold">{s.title}</h2>
          <p className="font-semibold text-[var(--brand)]">{s.km}</p>
          <p className="mt-1 text-[15px] leading-relaxed text-[var(--muted)]">{s.text}</p>
        </div>
      </div>
      <div className="flex gap-2">
        <Button tone="ghost" onClick={onDone}>Skip</Button>
        <span className="flex-1" />
        {step > 0 && <Button tone="neutral" onClick={() => onStep(step - 1)}>Back</Button>}
        {step < STEPS.length - 1 ? (
          <Button onClick={() => onStep(step + 1)}>Next</Button>
        ) : (
          <Button icon={MousePointerClick} onClick={onDone}>Start designing</Button>
        )}
      </div>
    </div>
  );
}
