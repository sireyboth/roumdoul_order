/*
 * Floor plan: shared types, the catalogue of things an owner can place, floor surfaces,
 * and where chairs go around a table. Units are centimetres: a 1200 × 800 plan is 12 m × 8 m.
 */

import type { CSSProperties } from "react";

export type FloorKind =
  | "table_square"
  | "table_round"
  | "table_long"
  | "booth"
  | "bar_counter"
  | "cashier"
  | "kitchen"
  | "wall"
  | "door"
  | "window"
  | "plant"
  | "pillar"
  | "rug"
  | "sofa"
  | "stairs"
  | "restroom"
  | "divider"
  | "stool"
  | "chair"
  | "lamp";

export type FloorMaterial = "wood" | "tile" | "stone" | "carpet" | "grass" | "concrete";

export type FloorObject = {
  id: string;
  kind: FloorKind;
  x: number;
  y: number;
  w: number;
  h: number;
  rotation: number;
  table_id?: number | null;
  seats?: number;
  label?: string | null;
  color?: string | null;
};

export type FloorPlan = {
  area_id: number | null;
  floor: FloorMaterial;
  width: number;
  height: number;
  objects: FloorObject[];
  updated_at: string | null;
};

export type FloorTable = { id: number; name: string; seats: number | null; area_id: number | null };

export type FloorData = {
  can_edit: boolean;
  areas: { id: number; name: string }[];
  tables: FloorTable[];
  floors: FloorPlan[];
};

export type KindInfo = {
  label: string;
  km: string;
  group: "tables" | "seating" | "service" | "structure" | "decor";
  w: number;
  h: number;
  /** Default colour of the top surface. */
  color: string;
  /** Colours the owner can switch to. */
  palette?: string[];
  isTable?: boolean;
  seats?: number;
};

const WOODS = ["#b9834f", "#8a5a36", "#d4a373", "#5e3b25", "#e9dcc9", "#3d3d3d"];

export const KINDS: Record<FloorKind, KindInfo> = {
  table_square: { label: "Square table", km: "តុការ៉េ", group: "tables", w: 80, h: 80, color: "#b9834f", palette: WOODS, isTable: true, seats: 4 },
  table_round: { label: "Round table", km: "តុមូល", group: "tables", w: 100, h: 100, color: "#b9834f", palette: WOODS, isTable: true, seats: 4 },
  table_long: { label: "Long table", km: "តុវែង", group: "tables", w: 180, h: 80, color: "#b9834f", palette: WOODS, isTable: true, seats: 6 },
  booth: { label: "Booth", km: "តុសាឡុង", group: "tables", w: 160, h: 130, color: "#b9834f", palette: WOODS, isTable: true, seats: 4 },
  chair: { label: "Chair", km: "កៅអី", group: "seating", w: 42, h: 42, color: "#3f6e5b", palette: ["#3f6e5b", "#8a5a36", "#c0392b", "#2c3e50", "#e1b12c", "#7f8c8d"] },
  stool: { label: "Bar stool", km: "កៅអីខ្ពស់", group: "seating", w: 36, h: 36, color: "#2c3e50", palette: ["#2c3e50", "#8a5a36", "#c0392b", "#3f6e5b"] },
  sofa: { label: "Sofa", km: "សាឡុង", group: "seating", w: 160, h: 70, color: "#6d4c41", palette: ["#6d4c41", "#3f6e5b", "#34495e", "#a1887f", "#b03a2e"] },
  bar_counter: { label: "Bar counter", km: "បារ", group: "service", w: 260, h: 70, color: "#5e3b25", palette: WOODS },
  cashier: { label: "Cashier", km: "កន្លែងគិតលុយ", group: "service", w: 170, h: 70, color: "#34495e", palette: ["#34495e", "#5e3b25", "#e9dcc9", "#0d7a5a"] },
  kitchen: { label: "Kitchen pass", km: "ផ្ទះបាយ", group: "service", w: 220, h: 100, color: "#aab4bb" },
  wall: { label: "Wall", km: "ជញ្ជាំង", group: "structure", w: 200, h: 20, color: "#efe9df", palette: ["#efe9df", "#c9b8a3", "#7f8c8d", "#b5651d", "#2f3640"] },
  door: { label: "Door", km: "ទ្វារ", group: "structure", w: 120, h: 30, color: "#8a5a36" },
  window: { label: "Window", km: "បង្អួច", group: "structure", w: 160, h: 16, color: "#9fd3ea" },
  pillar: { label: "Pillar", km: "សសរ", group: "structure", w: 40, h: 40, color: "#ddd5c8" },
  divider: { label: "Divider", km: "របាំង", group: "structure", w: 160, h: 16, color: "#6a994e", palette: ["#6a994e", "#8a5a36", "#efe9df"] },
  stairs: { label: "Stairs", km: "ជណ្ដើរ", group: "structure", w: 120, h: 180, color: "#c9b8a3" },
  restroom: { label: "Restroom", km: "បន្ទប់ទឹក", group: "structure", w: 140, h: 140, color: "#cfd8dc" },
  plant: { label: "Plant", km: "រុក្ខជាតិ", group: "decor", w: 56, h: 56, color: "#4c956c" },
  rug: { label: "Rug", km: "កម្រាល", group: "decor", w: 300, h: 200, color: "#c8a27a", palette: ["#c8a27a", "#a44a3f", "#2a6f97", "#6a994e", "#e9c46a", "#5c4d7d"] },
  lamp: { label: "Lamp", km: "ចង្កៀង", group: "decor", w: 40, h: 40, color: "#f6c453" },
};

export const GROUPS: { key: KindInfo["group"]; label: string }[] = [
  { key: "tables", label: "Tables" },
  { key: "seating", label: "Seating" },
  { key: "service", label: "Service" },
  { key: "structure", label: "Walls & doors" },
  { key: "decor", label: "Decor" },
];

export const TABLE_KINDS: FloorKind[] = ["table_square", "table_round", "table_long", "booth"];

export const MATERIALS: Record<FloorMaterial, { label: string; css: CSSProperties; base: string }> = {
  wood: {
    label: "Wood",
    base: "#8a5a36",
    css: {
      backgroundColor: "#cfa274",
      backgroundImage:
        "repeating-linear-gradient(0deg, transparent 0 238px, rgb(80 45 20 / .16) 238px 240px), repeating-linear-gradient(90deg, #d4a87a 0 58px, #c4956a 58px 60px, #ca9d70 60px 118px, #bf8f62 118px 120px, #d7ad80 120px 178px, #c4956a 178px 180px)",
    },
  },
  tile: {
    label: "Tile",
    base: "#9c948a",
    css: {
      backgroundColor: "#efe9df",
      backgroundImage: "conic-gradient(#f3eee6 25%, #e3dbcf 0 50%, #f3eee6 0 75%, #e3dbcf 0)",
      backgroundSize: "80px 80px",
    },
  },
  stone: {
    label: "Stone",
    base: "#77736c",
    css: {
      backgroundColor: "#bdb7ad",
      backgroundImage:
        "linear-gradient(rgb(0 0 0 / .08) 2px, transparent 2px), linear-gradient(90deg, rgb(0 0 0 / .08) 2px, transparent 2px), radial-gradient(rgb(255 255 255 / .25) 1px, transparent 1.5px)",
      backgroundSize: "100px 100px, 100px 100px, 14px 14px",
    },
  },
  carpet: {
    label: "Carpet",
    base: "#4b2c33",
    css: {
      backgroundColor: "#7a4b52",
      backgroundImage: "radial-gradient(rgb(255 255 255 / .07) 1px, transparent 1.5px), radial-gradient(rgb(0 0 0 / .1) 1px, transparent 1.5px)",
      backgroundSize: "6px 6px, 9px 9px",
    },
  },
  grass: {
    label: "Garden",
    base: "#4f6b3a",
    css: {
      backgroundColor: "#80b065",
      backgroundImage: "repeating-linear-gradient(0deg, #86b86d 0 50px, #78a85e 50px 100px), radial-gradient(rgb(255 255 255 / .12) 1px, transparent 2px)",
      backgroundSize: "auto, 12px 12px",
    },
  },
  concrete: {
    label: "Concrete",
    base: "#6f7476",
    css: {
      backgroundColor: "#c3c6c6",
      backgroundImage: "radial-gradient(rgb(0 0 0 / .06) 1px, transparent 2px), linear-gradient(90deg, rgb(0 0 0 / .05) 1px, transparent 1px)",
      backgroundSize: "10px 10px, 200px 200px",
    },
  },
};

/** Darken (negative) or lighten (positive) a #rrggbb colour by a fraction. */
export function shade(hex: string, amount: number): string {
  const n = parseInt(hex.slice(1), 16);
  const f = (c: number) => Math.round(Math.min(255, Math.max(0, amount < 0 ? c * (1 + amount) : c + (255 - c) * amount)));
  const r = f((n >> 16) & 255);
  const g = f((n >> 8) & 255);
  const b = f(n & 255);
  return `#${((r << 16) | (g << 8) | b).toString(16).padStart(6, "0")}`;
}

export const CHAIR = 40;

/**
 * Chair positions around a table, in the table's own (unrotated) coordinates.
 * `rot` turns the chair so its back faces away from the table.
 */
export function chairsFor(kind: FloorKind, w: number, h: number, seats: number): { x: number; y: number; rot: number }[] {
  if (seats <= 0 || kind === "booth") return [];
  const gap = 6;

  if (kind === "table_round") {
    const r = Math.max(w, h) / 2 + gap + CHAIR / 2 - 6;
    return Array.from({ length: seats }, (_, i) => {
      const a = (i / seats) * Math.PI * 2 - Math.PI / 2;
      return { x: w / 2 + Math.cos(a) * r, y: h / 2 + Math.sin(a) * r, rot: (a * 180) / Math.PI + 90 };
    });
  }

  // Rectangles: give each seat to the side with the most free room per chair.
  const sides = [
    { key: "top", len: w, n: 0 },
    { key: "bottom", len: w, n: 0 },
    { key: "left", len: h, n: 0 },
    { key: "right", len: h, n: 0 },
  ];
  for (let i = 0; i < seats; i++) {
    const best = sides.reduce((a, b) => (b.len / (b.n + 1) > a.len / (a.n + 1) + 0.01 ? b : a));
    best.n++;
  }
  const out: { x: number; y: number; rot: number }[] = [];
  const off = CHAIR / 2 + gap;
  for (const side of sides) {
    for (let i = 0; i < side.n; i++) {
      const t = (i + 0.5) / side.n;
      if (side.key === "top") out.push({ x: w * t, y: -off, rot: 0 });
      if (side.key === "bottom") out.push({ x: w * t, y: h + off, rot: 180 });
      if (side.key === "left") out.push({ x: -off, y: h * t, rot: 270 });
      if (side.key === "right") out.push({ x: w + off, y: h * t, rot: 90 });
    }
  }
  return out;
}

export function newObjectId(): string {
  return `o${Date.now().toString(36)}${Math.random().toString(36).slice(2, 7)}`;
}

/** The floor key used in the editor's state: "main" or "area-<id>". */
export function floorKey(areaId: number | null): string {
  return areaId === null ? "main" : `area-${areaId}`;
}

export function emptyPlan(areaId: number | null): FloorPlan {
  return { area_id: areaId, floor: "wood", width: 1200, height: 800, objects: [], updated_at: null };
}
