import type { Metadata } from "next";
import MenuApp from "@/components/menu-app";
import { getTableMenu } from "@/lib/api";

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }: PageProps<"/t/[token]">): Promise<Metadata> {
  const { token } = await params;
  const result = await getTableMenu(token);
  return { title: result.ok ? `${result.data.company.name} · ${result.data.table.name}` : "Roumdoul Order" };
}

export default async function TablePage({ params }: PageProps<"/t/[token]">) {
  const { token } = await params;
  const result = await getTableMenu(token);

  if (!result.ok) {
    const offline = result.reason === "offline";
    return (
      <main className="mx-auto flex min-h-dvh max-w-md flex-col items-center justify-center gap-3 px-6 text-center">
        <h1 className="text-xl font-semibold">{offline ? "មិនអាចភ្ជាប់បាន" : "QR នេះមិនដំណើរការទេ"}</h1>
        <p className="text-[var(--muted)]">
          {offline
            ? "The menu can't load right now. Please try again in a moment, or ask a staff member."
            : "This QR code isn't active. Please ask a staff member for help."}
        </p>
      </main>
    );
  }

  return <MenuApp menu={result.data} token={token} />;
}
