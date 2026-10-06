"use client";

import type { Names } from "./types";

export type StaffBranch = { id: number; name: string; company: string; currency: "USD" | "KHR"; role: string };

export type StaffSession = { token: string; user: { id: number; name: string }; branches: StaffBranch[] };

export type BoardItem = {
  name: Names;
  quantity: number;
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
  items: BoardItem[];
};

export type BoardRequest = { id: number; type: "waiter" | "bill"; table: string | null; created_at: string };

export type Board = { server_time: string; orders: BoardOrder[]; requests: BoardRequest[] };

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
