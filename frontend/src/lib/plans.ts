import "server-only";
import type { Plan } from "./types";
import { API_URL } from "./backend";

/** Active price plans, as set in /admin > Plans. Read on every visit (not cached at build time), so a price change shows at once; an empty list if the server can't be reached. */
export async function getPlans(): Promise<Plan[]> {
  try {
    const res = await fetch(`${API_URL}/api/public/plans`, {
      cache: "no-store",
      headers: { Accept: "application/json" },
    });
    if (!res.ok) return [];
    const body = (await res.json()) as { data: Plan[] };
    return body.data;
  } catch {
    return [];
  }
}
