"use client";

import type { Names } from "./types";

export type StaffBranch = { id: number; name: string; company: string; currency: "USD" | "KHR"; role: string };

export type StaffSession = { token: string; user: { id: number; name: string }; branches: StaffBranch[] };

export type BoardItem = {
  name: Names;
  quantity: number;
  unit_price: number;
  line_total: number;
  options: Names[];
  note: string | null;
  station: "kitchen" | "bar";
  menu_item_id: number | null;
};

export type BoardOrder = {
  id: number;
  number: number;
  status: "placed" | "accepted" | "preparing" | "ready" | "served";
  table: string | null;
  area: string | null;
  note: string | null;
  placed_at: string;
  ready_at: string | null;
  served_at: string | null;
  source: "qr" | "waiter";
  items: BoardItem[];
};

export type BoardRequest = { id: number; type: "waiter" | "bill"; table: string | null; created_at: string };

export type Board = { server_time: string; orders: BoardOrder[]; requests: BoardRequest[] };

export type CashierTable = {
  id: number;
  name: string;
  area: string | null;
  session: {
    id: number;
    status: "open" | "bill_requested";
    opened_at: string;
    bill_requested_at: string | null;
    orders_count: number;
    active_orders_count: number;
    total: number;
    paid_total: number;
    bill: { id: number; number: number; status: "open" | "paid" | "void" } | null;
  } | null;
};

export type CashierTables = { server_time: string; currency: "USD" | "KHR"; khr_per_usd: number; tables: CashierTable[] };

export type BillOrder = Omit<BoardOrder, "status"> & {
  status: BoardOrder["status"] | "completed" | "cancelled";
  subtotal: number;
  counts: boolean;
  cancel_reason: string | null;
};

export type BillPayment = {
  id: number;
  method: "cash" | "khqr" | "card" | "other";
  amount: number;
  tendered_amount: number | null;
  tendered_currency: "USD" | "KHR" | null;
  change_amount: number;
  change_currency: "USD" | "KHR" | null;
  reference: string | null;
  status: "confirmed" | "refunded";
  received_by: string | null;
  paid_at: string;
  refund_reason: string | null;
};

export type BillDetail = {
  id: number;
  number: number;
  status: "open" | "paid" | "void";
  table: string | null;
  area: string | null;
  session_id: number;
  currency: "USD" | "KHR";
  khr_per_usd: number;
  subtotal: number;
  discount_total: number;
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
  opened_at: string;
  paid_at: string | null;
  void_reason: string | null;
  orders: BillOrder[];
  adjustments: { id: number; type: "percent" | "fixed"; value: number; amount: number; reason: string; approved_by: string | null }[];
  payments: BillPayment[];
};

/** Roles that may use the cashier screen. */
export const CASHIER_ROLES = ["owner", "manager", "cashier"];

/** Roles that may type in an order for a table. */
export const ORDER_ROLES = ["owner", "manager", "cashier", "waiter"];

const KEY = "ro-staff-session";

export function loadSession(): StaffSession | null {
  try {
    const raw = window.localStorage.getItem(KEY);
    return raw ? (JSON.parse(raw) as StaffSession) : null;
  } catch {
    return null;
  }
}

export function saveSession(session: StaffSession | null) {
  try {
    if (session) window.localStorage.setItem(KEY, JSON.stringify(session));
    else window.localStorage.removeItem(KEY);
  } catch {
    /* storage blocked: the person signs in again next time */
  }
}

export class SignedOut extends Error {}

export async function staffApi<T>(path: string, init: { method?: string; body?: unknown; token?: string | null } = {}): Promise<T> {
  const token = init.token ?? loadSession()?.token;
  const res = await fetch(`/api/staff/${path}`, {
    method: init.method ?? "GET",
    cache: "no-store",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: init.body ? JSON.stringify(init.body) : undefined,
  });
  const body = await res.json().catch(() => ({}));

  if (res.status === 401) {
    saveSession(null);
    throw new SignedOut("Please sign in again.");
  }
  if (!res.ok) {
    const first = body?.errors ? (Object.values(body.errors)[0] as string[] | undefined)?.[0] : undefined;
    throw new Error(first ?? body?.message ?? "Something went wrong.");
  }
  return body.data as T;
}

/** "3m", "12m", "1h 05m" since an ISO time. */
export function since(iso: string, now: number): { label: string; minutes: number } {
  const minutes = Math.max(0, Math.floor((now - new Date(iso).getTime()) / 60000));
  const label = minutes < 60 ? `${minutes}m` : `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, "0")}m`;
  return { label, minutes };
}

/** Short two-tone chime, made in the browser (no sound file needed). */
export function chime(ctx: AudioContext | null) {
  if (!ctx) return;
  const play = (freq: number, at: number) => {
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.frequency.value = freq;
    gain.gain.setValueAtTime(0.0001, ctx.currentTime + at);
    gain.gain.exponentialRampToValueAtTime(0.4, ctx.currentTime + at + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + at + 0.35);
    osc.connect(gain).connect(ctx.destination);
    osc.start(ctx.currentTime + at);
    osc.stop(ctx.currentTime + at + 0.4);
  };
  play(880, 0);
  play(1320, 0.18);
}
