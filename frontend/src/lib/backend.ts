import "server-only";

export const API_URL = (process.env.API_URL ?? "http://127.0.0.1:8000").replace(/\/$/, "");

/** Forwards a request to Laravel and passes the answer straight back. */
export async function forward(path: string, init: { method: string; body?: string | null; auth?: string | null }) {
  try {
    const res = await fetch(`${API_URL}${path}`, {
      method: init.method,
      cache: "no-store",
      headers: {
        Accept: "application/json",
        ...(init.body ? { "Content-Type": "application/json" } : {}),
        ...(init.auth ? { Authorization: init.auth } : {}),
      },
      body: init.body ?? undefined,
    });

    return new Response(await res.text(), {
      status: res.status,
      headers: { "Content-Type": "application/json", "Cache-Control": "no-store" },
    });
  } catch {
    return Response.json({ message: "Can't reach the server. Please try again." }, { status: 503 });
  }
}
