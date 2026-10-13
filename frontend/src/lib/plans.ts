import "server-only";
import type { Plan } from "./types";
import { API_URL } from "./backend";

/** Active price plans, as set in /admin > Plans. Refreshed every minute; an empty list if the server can't be reached. */
export async function getPlans(): Promise<Plan[]> {
  try {
    const res = await fetch(`${API_URL}/api/public/plans`, {
      next: { revalidate: 60 },
      headers: { Accept: "application/json" },
    });
    if (!res.ok) return [];
    const body = (await res.json()) as { data: Plan[] };
    return body.data;
  } catch {
    return [];
  }
}
