import { forward } from "@/lib/backend";

/**
 * Customer calls go through this site so the phone never needs the backend's address.
 * Only these table actions are allowed through.
 */
const ALLOWED: Record<string, string[]> = {
  session: ["GET"],
  orders: ["POST"],
  requests: ["POST"],
};

async function handle(req: Request, ctx: RouteContext<"/api/t/[token]/[...path]">) {
  const { token, path } = await ctx.params;
  const action = path.join("/");

  if (!/^[A-Za-z0-9]{16,40}$/.test(token) || !ALLOWED[action]?.includes(req.method)) {
    return Response.json({ message: "Not found" }, { status: 404 });
  }

  const body = req.method === "POST" ? await req.text() : null;
  return forward(`/api/public/tables/${token}/${action}`, { method: req.method, body });
}

export const GET = handle;
export const POST = handle;
