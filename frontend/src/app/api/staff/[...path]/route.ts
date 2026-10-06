import { forward } from "@/lib/backend";

/** Staff screen calls, forwarded with the staff member's sign-in token. */
async function handle(req: Request, ctx: RouteContext<"/api/staff/[...path]">) {
  const { path } = await ctx.params;

  if (path.some((part) => !/^[A-Za-z0-9\-_]+$/.test(part))) {
    return Response.json({ message: "Not found" }, { status: 404 });
  }

  const query = new URL(req.url).search;
  const body = req.method === "POST" ? await req.text() : null;

  return forward(`/api/staff/${path.join("/")}${query}`, {
    method: req.method,
    body,
    auth: req.headers.get("authorization"),
  });
}

export const GET = handle;
export const POST = handle;
