import "server-only";
import type { TableMenu } from "./types";
import { API_URL } from "./backend";

export type MenuResult =
  | { ok: true; data: TableMenu }
  | { ok: false; reason: "inactive" | "offline" };

/** Loads the menu for a table QR token. Never cached here: sold-out and prices must be live. */
export async function getTableMenu(token: string): Promise<MenuResult> {
  if (!/^[A-Za-z0-9]{16,40}$/.test(token)) {
    return { ok: false, reason: "inactive" };
  }

  try {
    const res = await fetch(`${API_URL}/api/public/tables/${token}`, {
      cache: "no-store",
      headers: { Accept: "application/json" },
    });

    if (res.status === 404) return { ok: false, reason: "inactive" };
    if (!res.ok) return { ok: false, reason: "offline" };

    const body = (await res.json()) as { data: TableMenu };
    return { ok: true, data: body.data };
  } catch {
    return { ok: false, reason: "offline" };
  }
}
