"use client";

import { Box, Maximize, Minus, Plus, RotateCw, Square } from "lucide-react";
import { createContext, useCallback, useContext, useEffect, useLayoutEffect, useRef, useState } from "react";
import { CHAIR, KINDS, MATERIALS, chairsFor, shade, type FloorObject, type FloorPlan, type FloorTable } from "@/lib/floor";

/*
 * The floor plan drawn as a small 3D "diorama" with CSS 3D transforms (no WebGL, works on any
 * tablet). The floor is tilted (rotateX) and turned (rotateZ); every object is a stack of thin
 * layers lifted with translateZ, which reads as a solid block. Without the tilt (2D) the same
 * objects are seen from above, which is easier for precise placing.
 */

const TILT = 56;
const SPINS = [-32, -122, -212, -302];

type Ctx = { flat: boolean; tilt: number; spin: number; scale: number };
const StageCtx = createContext<Ctx>({ flat: true, tilt: 0, spin: 0, scale: 1 });

export type TableStatus = { tone: "free" | "busy" | "bill"; line?: string };

type Drag =
  | { mode: "move" | "resize"; id: string; sx: number; sy: number; start: FloorObject; last: FloorObject; moved: boolean }
  | { mode: "pan"; sx: number; sy: number; start: { x: number; y: number }; moved: boolean; tableId?: number };

export default function FloorStage({
  plan,
  tables,
  editable = false,
  selectedId = null,
  onSelect,
  onChange,
  status,
  onTableClick,
  initialFlat = false,
  className = "",
}: {
  plan: FloorPlan;
  tables: Map<number, FloorTable>;
  editable?: boolean;
  selectedId?: string | null;
  onSelect?: (id: string | null) => void;
  /** Live while dragging (`final` false), then once more when the finger lifts (`final` true). */
  onChange?: (object: FloorObject, final: boolean) => void;
  status?: Map<number, TableStatus>;
  onTableClick?: (tableId: number) => void;
  initialFlat?: boolean;
  className?: string;
}) {
  const box = useRef<HTMLDivElement>(null);
  const [size, setSize] = useState({ w: 0, h: 0 });
  const [flat, setFlat] = useState(initialFlat);
  const [spinIndex, setSpinIndex] = useState(0);
  const [zoom, setZoom] = useState(1);
  const [pan, setPan] = useState({ x: 0, y: 0 });
  const drag = useRef<Drag | null>(null);
  const [dragging, setDragging] = useState(false);

  useLayoutEffect(() => {
    const el = box.current;
    if (!el) return;
    const ro = new ResizeObserver(([entry]) => setSize({ w: entry.contentRect.width, h: entry.contentRect.height }));
    ro.observe(el);
    return () => ro.disconnect();
  }, []);

  const tilt = flat ? 0 : TILT;
  const spin = flat ? 0 : SPINS[spinIndex];
  const a = (tilt * Math.PI) / 180;
  const p = (spin * Math.PI) / 180;

  // Fit the turned and tilted floor into the box.
  const corners = [
    [-plan.width / 2, -plan.height / 2],
    [plan.width / 2, -plan.height / 2],
    [plan.width / 2, plan.height / 2],
    [-plan.width / 2, plan.height / 2],
  ].map(([x, y]) => [x * Math.cos(p) - y * Math.sin(p), (x * Math.sin(p) + y * Math.cos(p)) * Math.cos(a)]);
  const pw = Math.max(...corners.map((c) => c[0])) - Math.min(...corners.map((c) => c[0]));
  const ph = Math.max(...corners.map((c) => c[1])) - Math.min(...corners.map((c) => c[1])) + (flat ? 0 : 110 * Math.sin(a));
  const fit = size.w > 0 ? Math.min((size.w - 32) / pw, (size.h - 32) / ph) : 0.5;
  const scale = Math.max(0.05, fit * zoom);

  /** A finger movement on screen, as a movement on the floor (undo scale, tilt and turn). */
  const toFloor = useCallback(
    (dx: number, dy: number) => {
      const ux = dx / scale;
      const uy = dy / scale / Math.cos(a);
      return { x: ux * Math.cos(p) + uy * Math.sin(p), y: -ux * Math.sin(p) + uy * Math.cos(p) };
    },
    [scale, a, p],
  );

  // Mouse wheel / trackpad zoom.
  useEffect(() => {
    const el = box.current;
    if (!el) return;
    const onWheel = (e: WheelEvent) => {
      e.preventDefault();
      setZoom((z) => Math.min(4, Math.max(0.4, z * (e.deltaY > 0 ? 0.9 : 1.1))));
    };
    el.addEventListener("wheel", onWheel, { passive: false });
    return () => el.removeEventListener("wheel", onWheel);
  }, []);

  useEffect(() => {
    const move = (e: PointerEvent) => {
      const d = drag.current;
      if (!d) return;
      const dx = e.clientX - d.sx;
      const dy = e.clientY - d.sy;
      if (!d.moved && Math.hypot(dx, dy) < 4) return;
      if (!d.moved) setDragging(true);
      d.moved = true;
      if (d.mode === "pan") {
        setPan({ x: d.start.x + dx, y: d.start.y + dy });
        return;
      }
      const f = toFloor(dx, dy);
      const snap = (v: number) => (e.altKey ? Math.round(v) : Math.round(v / 10) * 10);
      let next: FloorObject;
      if (d.mode === "move") {
        next = { ...d.start, x: snap(d.start.x + f.x), y: snap(d.start.y + f.y) };
      } else {
        // Resize from the bottom-right corner; the opposite corner stays put even when turned.
        const r = (d.start.rotation * Math.PI) / 180;
        const lx = f.x * Math.cos(r) + f.y * Math.sin(r);
        const ly = -f.x * Math.sin(r) + f.y * Math.cos(r);
        const w = Math.max(16, snap(d.start.w + lx));
        const h = Math.max(10, snap(d.start.h + ly));
        const dw = (w - d.start.w) / 2;
        const dh = (h - d.start.h) / 2;
        const cx = d.start.x + d.start.w / 2 + dw * Math.cos(r) - dh * Math.sin(r);
        const cy = d.start.y + d.start.h / 2 + dw * Math.sin(r) + dh * Math.cos(r);
        next = { ...d.start, w, h, x: Math.round(cx - w / 2), y: Math.round(cy - h / 2) };
      }
      d.last = next;
      onChange?.(next, false);
    };
    const up = () => {
      const d = drag.current;
      drag.current = null;
      setDragging(false);
      if (!d) return;
      if (d.mode !== "pan" && d.moved) onChange?.(d.last, true);
      if (d.mode === "pan" && !d.moved && d.tableId !== undefined) onTableClick?.(d.tableId);
    };
    window.addEventListener("pointermove", move);
    window.addEventListener("pointerup", up);
    window.addEventListener("pointercancel", up);
    return () => {
      window.removeEventListener("pointermove", move);
      window.removeEventListener("pointerup", up);
      window.removeEventListener("pointercancel", up);
    };
  }, [toFloor, onChange, onTableClick]);

  function startObject(e: React.PointerEvent, object: FloorObject, mode: "move" | "resize") {
    e.stopPropagation();
    if (e.button !== 0) return;
    if (!editable) {
      // Viewing: tap a table to open it, drag anywhere to look around.
      drag.current = { mode: "pan", sx: e.clientX, sy: e.clientY, start: pan, moved: false, tableId: object.table_id ?? undefined };
      return;
    }
    onSelect?.(object.id);
    drag.current = { mode, id: object.id, sx: e.clientX, sy: e.clientY, start: object, last: object, moved: false };
  }

  function startPan(e: React.PointerEvent) {
    if (e.button !== 0) return;
    if (editable) onSelect?.(null);
    drag.current = { mode: "pan", sx: e.clientX, sy: e.clientY, start: pan, moved: false };
  }

  const material = MATERIALS[plan.floor] ?? MATERIALS.wood;
  const lift = flat ? 0 : 36 * scale * Math.sin(a);
  // Rugs lie under everything else.
  const ordered = [...plan.objects].sort((x, y) => Number(y.kind === "rug") - Number(x.kind === "rug"));

  return (
    <StageCtx.Provider value={{ flat, tilt, spin, scale }}>
      <div
        ref={box}
        onPointerDown={startPan}
        className={`${className.includes("absolute") ? "" : "relative"} touch-none overflow-hidden select-none ${dragging ? "cursor-grabbing" : "cursor-grab"} ${className}`}
      >
        <div
          className="absolute"
          style={{
            left: size.w / 2 - plan.width / 2 + pan.x,
            top: size.h / 2 - plan.height / 2 + pan.y + lift,
            width: plan.width,
            height: plan.height,
            transform: `scale(${scale}) rotateX(${tilt}deg) rotateZ(${spin}deg)`,
            transformStyle: "preserve-3d",
            transition: dragging ? undefined : "transform 0.6s cubic-bezier(0.2, 0.8, 0.2, 1)",
          }}
        >
          {/* Diorama base: the floor's thickness and a soft shadow under it. */}
          {!flat && (
            <>
              <div className="absolute inset-0" style={{ transform: "translateZ(-40px)", background: "rgb(0 0 0 / .35)", filter: "blur(30px)" }} />
              {[22, 18, 14, 10, 6, 2].map((z) => (
                <div key={z} className="absolute inset-0 rounded-[6px]" style={{ transform: `translateZ(${-z}px)`, background: shade(material.base, -z / 80) }} />
              ))}
            </>
          )}
          <div className="absolute inset-0 rounded-[6px]" style={{ ...material.css, boxShadow: "inset 0 0 0 6px rgb(0 0 0 / .08)" }} />
          {editable && (
            <div
              className="pointer-events-none absolute inset-0"
              style={{
                transform: "translateZ(0.5px)",
                backgroundImage: "linear-gradient(rgb(0 0 0 / .09) 1px, transparent 1px), linear-gradient(90deg, rgb(0 0 0 / .09) 1px, transparent 1px)",
                backgroundSize: "20px 20px",
              }}
            />
          )}

          {ordered.map((object) => (
            <Piece
              key={object.id}
              object={object}
              table={object.table_id ? tables.get(object.table_id) : undefined}
              status={object.table_id ? status?.get(object.table_id) : undefined}
              selected={selectedId === object.id}
              editable={editable}
              onPointerDown={(e, mode) => startObject(e, object, mode)}
            />
          ))}
        </div>

        <div className="absolute right-3 bottom-3 flex items-center gap-1 rounded-2xl bg-[var(--surface)]/90 p-1 shadow-card ring-1 ring-[var(--line)] backdrop-blur" onPointerDown={(e) => e.stopPropagation()}>
          <StageButton label={flat ? "3D view" : "Top view (2D)"} onClick={() => setFlat((f) => !f)}>
            {flat ? <Box className="size-4" /> : <Square className="size-4" />}
            <span className="text-xs font-bold">{flat ? "3D" : "2D"}</span>
          </StageButton>
          {!flat && (
            <StageButton label="Turn the view" onClick={() => setSpinIndex((i) => (i + 1) % SPINS.length)}>
              <RotateCw className="size-4" />
            </StageButton>
          )}
          <span className="mx-0.5 h-5 w-px bg-[var(--line)]" />
          <StageButton label="Zoom out" onClick={() => setZoom((z) => Math.max(0.4, z / 1.2))}>
            <Minus className="size-4" />
          </StageButton>
          <StageButton label="Zoom in" onClick={() => setZoom((z) => Math.min(4, z * 1.2))}>
            <Plus className="size-4" />
          </StageButton>
          <StageButton
            label="Fit to screen"
            onClick={() => {
              setZoom(1);
              setPan({ x: 0, y: 0 });
            }}
          >
            <Maximize className="size-4" />
          </StageButton>
        </div>
      </div>
    </StageCtx.Provider>
  );
}

function StageButton({ label, onClick, children }: { label: string; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      type="button"
      title={label}
      aria-label={label}
      onClick={onClick}
      className="flex h-9 min-w-9 items-center justify-center gap-1 rounded-xl px-2 text-[var(--fg)] transition hover:bg-[var(--chip)] active:scale-95"
    >
      {children}
    </button>
  );
}

/* ---------------- Building blocks ---------------- */

/** An extruded shape from height z0 to z1, with `children` drawn on its top. */
function Solid({
  x = 0,
  y = 0,
  w,
  h,
  z0 = 0,
  z1,
  color,
  side,
  round = false,
  radius = 6,
  top,
  children,
  className = "",
}: {
  x?: number;
  y?: number;
  w: number;
  h: number;
  z0?: number;
  z1: number;
  color: string;
  side?: string;
  round?: boolean;
  radius?: number;
  top?: React.CSSProperties;
  children?: React.ReactNode;
  className?: string;
}) {
  const { flat } = useContext(StageCtx);
  const shape = { position: "absolute" as const, left: x, top: y, width: w, height: h, borderRadius: round ? "50%" : radius };
  const layers: number[] = [];
  if (!flat && z1 - z0 > 1) {
    const step = Math.max(2.5, (z1 - z0) / 12);
    for (let z = z0; z < z1 - 0.5; z += step) layers.push(z);
  }
  const sideColor = side ?? shade(color, -0.32);
  return (
    <>
      {layers.map((z, i) => (
        <div key={z} style={{ ...shape, background: shade(sideColor, (i / layers.length) * 0.12 - 0.06), transform: `translateZ(${z}px)` }} />
      ))}
      <div
        className={className}
        style={{
          ...shape,
          background: color,
          transform: `translateZ(${z1}px)`,
          boxShadow: `inset 0 0 0 1.5px ${shade(color, -0.12)}, inset 0 6px 10px -6px rgb(255 255 255 / .45)`,
          ...top,
        }}
      >
        {children}
      </div>
    </>
  );
}

/** Soft contact shadow on the floor. */
function Shadow({ w, h, round = false, spread = 6 }: { w: number; h: number; round?: boolean; spread?: number }) {
  const { flat } = useContext(StageCtx);
  return (
    <div
      className="pointer-events-none"
      style={{
        position: "absolute",
        left: -spread / 2 + (flat ? 4 : 2),
        top: -spread / 2 + (flat ? 6 : 4),
        width: w + spread,
        height: h + spread,
        borderRadius: round ? "50%" : 14,
        background: "rgb(30 20 10 / .32)",
        filter: `blur(${flat ? 6 : 9}px)`,
        transform: "translateZ(0.6px)",
      }}
    />
  );
}

/** Text that always faces the viewer, standing above a point of the object. */
function Billboard({ x, y, z, rotation, children, center = false }: { x: number; y: number; z: number; rotation: number; children: React.ReactNode; center?: boolean }) {
  const { flat, tilt, spin, scale } = useContext(StageCtx);
  const grow = Math.min(2.6, Math.max(1, 0.85 / scale));
  return (
    <div
      className="pointer-events-none"
      style={{
        position: "absolute",
        left: x,
        top: y,
        width: 0,
        height: 0,
        transformStyle: "preserve-3d",
        transform: flat
          ? `translateZ(${z}px) rotate(${-rotation}deg) scale(${grow})`
          : `translateZ(${z}px) rotateZ(${-rotation}deg) rotateZ(${-spin}deg) rotateX(${-tilt}deg) scale(${grow})`,
      }}
    >
      <div className="absolute top-0 left-0 whitespace-nowrap" style={{ transform: flat || center ? "translate(-50%, -50%)" : "translate(-50%, -100%)" }}>
        {children}
      </div>
    </div>
  );
}

function Chair({ x, y, rot, color }: { x: number; y: number; rot: number; color: string }) {
  return (
    <div style={{ position: "absolute", left: x - CHAIR / 2, top: y - CHAIR / 2, width: CHAIR, height: CHAIR, transform: `rotate(${rot}deg)`, transformStyle: "preserve-3d" }}>
      <Shadow w={CHAIR - 8} h={CHAIR - 8} spread={4} />
      <Solid x={4} y={6} w={CHAIR - 8} h={CHAIR - 10} z1={18} color={color} radius={8} />
      <Solid x={4} y={0} w={CHAIR - 8} h={7} z0={18} z1={44} color={shade(color, -0.1)} radius={4} />
    </div>
  );
}

/* ---------------- Objects ---------------- */

function Piece({
  object,
  table,
  status,
  selected,
  editable,
  onPointerDown,
}: {
  object: FloorObject;
  table?: FloorTable;
  status?: TableStatus;
  selected: boolean;
  editable: boolean;
  onPointerDown: (e: React.PointerEvent, mode: "move" | "resize") => void;
}) {
  const { scale } = useContext(StageCtx);
  const info = KINDS[object.kind];
  const color = object.color ?? info.color;
  const { w, h } = object;
  const clickable = editable || !!object.table_id;

  return (
    <div
      onPointerDown={clickable ? (e) => onPointerDown(e, "move") : undefined}
      className={clickable ? (editable ? "cursor-move" : "cursor-pointer") : undefined}
      style={{
        position: "absolute",
        left: object.x,
        top: object.y,
        width: w,
        height: h,
        transform: `rotate(${object.rotation}deg)`,
        transformStyle: "preserve-3d",
      }}
    >
      {selected && (
        <div
          className="pointer-events-none"
          style={{ position: "absolute", inset: -10, borderRadius: 14, border: "3px dashed var(--brand)", background: "rgb(13 122 90 / .12)", transform: "translateZ(1px)" }}
        />
      )}

      <Body object={object} color={color} table={table} status={status} />

      {selected && editable && (
        <div
          onPointerDown={(e) => onPointerDown(e, "resize")}
          title="Drag to resize"
          className="cursor-nwse-resize"
          style={{
            position: "absolute",
            left: w - 14,
            top: h - 14,
            width: 28,
            height: 28,
            borderRadius: 999,
            background: "var(--brand)",
            border: "4px solid white",
            boxShadow: "0 2px 8px rgb(0 0 0 / .35)",
            transform: `translateZ(2px) scale(${Math.min(2.5, Math.max(1, 0.8 / scale))})`,
          }}
        />
      )}
    </div>
  );
}

function TableLabel({ object, table, status, z }: { object: FloorObject; table?: FloorTable; status?: TableStatus; z: number }) {
  const name = table?.name ?? object.label ?? "?";
  const tone = status?.tone ?? "free";
  const bg = tone === "bill" ? "#f0a92e" : tone === "busy" ? "#0d7a5a" : "rgb(20 26 24 / .82)";
  const fg = tone === "bill" ? "#2b1d06" : "#fff";
  return (
    <Billboard x={object.w / 2} y={object.h / 2} z={z} rotation={object.rotation}>
      <div className="flex flex-col items-center">
        <div
          className={`flex items-center gap-1.5 rounded-xl px-2.5 py-1 text-[13px] leading-tight font-bold shadow-lg ${tone === "bill" ? "anim-float" : ""}`}
          style={{ background: bg, color: fg }}
        >
          {name}
          {status?.line && <span className="font-semibold opacity-90">· {status.line}</span>}
        </div>
      </div>
    </Billboard>
  );
}

function Body({ object, color, table, status }: { object: FloorObject; color: string; table?: FloorTable; status?: TableStatus }) {
  const { flat } = useContext(StageCtx);
  const { w, h, kind } = object;
  const info = KINDS[kind];

  if (info.isTable) {
    const seats = object.seats ?? table?.seats ?? info.seats ?? 0;
    const round = kind === "table_round";
    const glow =
      status?.tone === "bill"
        ? { boxShadow: "0 0 0 5px #f0a92e, 0 0 28px 6px rgb(240 169 46 / .8)" }
        : status?.tone === "busy"
          ? { boxShadow: "0 0 0 5px #2fb487, 0 0 24px 4px rgb(47 180 135 / .7)" }
          : undefined;

    if (kind === "booth") {
      const tw = w * 0.56;
      const th = h * 0.42;
      const tx = (w - tw) / 2;
      const ty = (h - th) / 2;
      const bench = "#7b4b3a";
      return (
        <>
          <Shadow w={w} h={h} />
          {/* Two padded benches facing each other. */}
          <Solid x={0} y={0} w={w} h={h * 0.24} z1={20} color={bench} radius={10} />
          <Solid x={0} y={0} w={w} h={h * 0.09} z0={20} z1={52} color={shade(bench, -0.08)} radius={6} />
          <Solid x={0} y={h * 0.76} w={w} h={h * 0.24} z1={20} color={bench} radius={10} />
          <Solid x={0} y={h * 0.91} w={w} h={h * 0.09} z0={20} z1={52} color={shade(bench, -0.08)} radius={6} />
          <Solid x={tx + tw * 0.4} y={ty + th * 0.3} w={tw * 0.2} h={th * 0.4} z1={26} color={shade(color, -0.4)} radius={4} />
          <Solid x={tx} y={ty} w={tw} h={th} z0={26} z1={32} color={color} radius={10} top={glow} />
          <TableLabel object={object} table={table} status={status} z={flat ? 40 : 40} />
        </>
      );
    }

    const chairColor = shade(color, -0.45);
    return (
      <>
        {chairsFor(kind, w, h, seats).map((c, i) => (
          <Chair key={i} x={c.x} y={c.y} rot={c.rot} color={chairColor} />
        ))}
        <Shadow w={w} h={h} round={round} />
        <Solid x={w * 0.35} y={h * 0.35} w={w * 0.3} h={h * 0.3} z1={26} color={shade(color, -0.45)} round={round} radius={4} />
        <Solid w={w} h={h} z0={26} z1={32} color={color} round={round} radius={10} top={glow} />
        <TableLabel object={object} table={table} status={status} z={40} />
      </>
    );
  }

  const label = object.label ? (
    <Billboard x={w / 2} y={h / 2} z={flat ? 80 : 70} rotation={object.rotation} center={flat}>
      <span className="rounded-lg bg-black/60 px-2 py-0.5 text-[11px] font-bold text-white">{object.label}</span>
    </Billboard>
  ) : null;

  switch (kind) {
    case "chair":
      return <Chair x={w / 2} y={h / 2} rot={0} color={color} />;
    case "stool":
      return (
        <>
          <Shadow w={w} h={h} round spread={2} />
          <Solid x={w * 0.36} y={h * 0.36} w={w * 0.28} h={h * 0.28} z1={30} color="#555" round />
          <Solid x={2} y={2} w={w - 4} h={h - 4} z0={30} z1={36} color={color} round />
        </>
      );
    case "sofa":
      return (
        <>
          <Shadow w={w} h={h} />
          <Solid w={w} h={h} z1={18} color={color} radius={12} />
          <Solid w={w} h={h * 0.28} z0={18} z1={48} color={shade(color, -0.08)} radius={10} />
          <Solid w={14} h={h} z0={18} z1={32} color={shade(color, -0.05)} radius={8} />
          <Solid x={w - 14} w={14} h={h} z0={18} z1={32} color={shade(color, -0.05)} radius={8} />
          {label}
        </>
      );
    case "bar_counter":
      return (
        <>
          <Shadow w={w} h={h} />
          <Solid w={w} h={h} z1={44} color={shade(color, 0.12)} side={shade(color, -0.25)} radius={8} />
          <Solid x={10} y={10} w={w - 20} h={10} z0={44} z1={46} color="#e9dcc9" radius={4} />
          {label}
        </>
      );
    case "cashier":
      return (
        <>
          <Shadow w={w} h={h} />
          <Solid w={w} h={h} z1={40} color={color} radius={8} />
          <Solid x={w - 56} y={12} w={36} h={26} z0={40} z1={64} color="#1f2a30" radius={4} top={{ background: "#79c3e8" }} />
          {label}
        </>
      );
    case "kitchen":
      return (
        <>
          <Shadow w={w} h={h} />
          <Solid w={w} h={h} z1={46} color={color} side="#7d878e" radius={6}>
            {[0.2, 0.45, 0.7].map((f) => (
              <span key={f} style={{ position: "absolute", left: w * f, top: h * 0.3, width: h * 0.4, height: h * 0.4, borderRadius: "50%", border: "4px solid #444", background: "#2b2b2b" }} />
            ))}
          </Solid>
          {label}
        </>
      );
    case "wall":
      return (
        <>
          <Shadow w={w} h={h} spread={10} />
          <Solid w={w} h={h} z1={92} color={color} side={shade(color, -0.22)} radius={3} />
          {label}
        </>
      );
    case "window":
      return (
        <>
          <Solid w={w} h={h} z1={30} color="#efe9df" radius={2} />
          <Solid w={w} h={h * 0.6} y={h * 0.2} z0={30} z1={86} color={color} side="#8cc3dc" radius={2} top={{ opacity: 0.85 }} />
          <Solid w={w} h={h} z0={86} z1={92} color="#efe9df" radius={2} />
          {label}
        </>
      );
    case "door":
      return (
        <>
          <Solid w={w} h={h} z1={1} color="#6d4c41" radius={4} top={{ backgroundImage: "repeating-linear-gradient(90deg, transparent 0 6px, rgb(0 0 0 / .15) 6px 8px)" }} />
          {flat ? (
            <svg className="pointer-events-none absolute overflow-visible" style={{ left: 0, top: 0, transform: "translateZ(2px)" }} width={w} height={h}>
              <path d={`M 4 ${h / 2} L ${w * 0.6} ${h / 2} A ${w * 0.6} ${w * 0.6} 0 0 0 4 ${h / 2 - w * 0.6}`} fill="rgb(13 122 90 / .12)" stroke="#0d7a5a" strokeWidth={3} strokeDasharray="6 5" />
            </svg>
          ) : (
            <>
              <Solid w={10} h={h} z1={88} color={color} radius={2} />
              <Solid x={w - 10} w={10} h={h} z1={88} color={color} radius={2} />
              <Solid w={w} h={h} z0={88} z1={96} color={color} radius={2} />
            </>
          )}
          {label}
        </>
      );
    case "pillar":
      return (
        <>
          <Shadow w={w} h={h} spread={8} />
          <Solid w={w} h={h} z1={112} color={color} radius={4} />
        </>
      );
    case "divider":
      return (
        <>
          <Shadow w={w} h={h} />
          <Solid w={w} h={h} z1={24} color="#8a5a36" radius={4} />
          <Solid x={2} y={-4} w={w - 4} h={h + 8} z0={24} z1={56} color={color} radius={12} side={shade(color, -0.2)} />
        </>
      );
    case "stairs": {
      const n = 6;
      return (
        <>
          <Shadow w={w} h={h} />
          {Array.from({ length: n }, (_, i) => (
            <Solid key={i} y={(h / n) * i} w={w} h={h / n + 1} z1={14 * (n - i)} color={shade(color, i * 0.03)} radius={2} />
          ))}
          {label}
        </>
      );
    }
    case "restroom":
      return (
        <>
          <Shadow w={w} h={h} />
          <Solid w={w} h={h} z1={74} color={color} radius={8}>
            <span className="absolute inset-0 grid place-items-center text-2xl font-black text-[#546e7a]">WC</span>
          </Solid>
        </>
      );
    case "plant":
      return (
        <>
          <Shadow w={w} h={h} round spread={2} />
          <Solid x={w * 0.22} y={h * 0.22} w={w * 0.56} h={h * 0.56} z1={22} color="#c0703c" round />
          <Solid w={w} h={h} z0={22} z1={50} color={color} side="#2f6b47" round />
          <Solid x={w * 0.18} y={h * 0.12} w={w * 0.6} h={h * 0.6} z0={50} z1={62} color={shade(color, 0.18)} side={shade(color, -0.1)} round />
        </>
      );
    case "lamp":
      return (
        <>
          <div className="pointer-events-none" style={{ position: "absolute", left: -w, top: -h, width: w * 3, height: h * 3, borderRadius: "50%", background: "radial-gradient(rgb(255 214 120 / .55), transparent 65%)", transform: "translateZ(0.8px)" }} />
          <Solid x={w * 0.42} y={h * 0.42} w={w * 0.16} h={h * 0.16} z1={84} color="#444" round />
          <Solid x={2} y={2} w={w - 4} h={h - 4} z0={84} z1={102} color={color} side="#e0a62f" round />
        </>
      );
    case "rug":
      return (
        <Solid
          w={w}
          h={h}
          z1={1}
          color={color}
          radius={10}
          top={{ boxShadow: `inset 0 0 0 8px ${shade(color, -0.18)}, inset 0 0 0 12px ${shade(color, 0.35)}`, opacity: 0.95 }}
        />
      );
    default:
      return <Solid w={w} h={h} z1={20} color={color} />;
  }
}
