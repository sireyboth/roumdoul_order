import type { PlacedOrder, TableSessionState } from "./types";

/** Customer calls, through this site's /api/t proxy. */
async function call<T>(token: string, action: string, init?: RequestInit): Promise<T> {
  const res = await fetch(`/api/t/${token}/${action}`, {
    ...init,
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    cache: "no-store",
  });
  const body = await res.json().catch(() => ({}));

  if (!res.ok) {
    // Laravel validation errors: show the first message as-is (it names the item).
    const first = body?.errors ? (Object.values(body.errors)[0] as string[] | undefined)?.[0] : undefined;
    throw new Error(first ?? body?.message ?? "Request failed");
  }

  return body.data as T;
}

export type OrderLineInput = { menu_item_id: number; quantity: number; option_ids: number[]; note?: string };

export function placeOrder(token: string, payload: { idempotency_key: string; note?: string; items: OrderLineInput[] }) {
  return call<PlacedOrder>(token, "orders", { method: "POST", body: JSON.stringify(payload) });
}

export function getSession(token: string) {
  return call<TableSessionState>(token, "session");
}

export function requestService(token: string, type: "waiter" | "bill") {
  return call<{ type: string; status: string }>(token, "requests", { method: "POST", body: JSON.stringify({ type }) });
}

export function newKey(): string {
  return typeof crypto !== "undefined" && "randomUUID" in crypto
    ? crypto.randomUUID()
    : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
}
