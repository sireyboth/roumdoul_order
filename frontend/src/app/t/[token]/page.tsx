import type { Metadata, Viewport } from "next";
import { QrCode, WifiOff } from "lucide-react";
import MenuApp from "@/components/menu-app";
import SiteFooter from "@/components/site-footer";
import { getTableMenu } from "@/lib/api";

export const dynamic = "force-dynamic";

/* Phone status bar in the Roumdoul palette (see globals.css). */
export const viewport: Viewport = {
  themeColor: [
    { media: "(prefers-color-scheme: light)", color: "#f8f2ef" },
    { media: "(prefers-color-scheme: dark)", color: "#130f0f" },
  ],
};

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
    const Icon = offline ? WifiOff : QrCode;
    return (
      <div className="palette-roumdoul flex min-h-dvh flex-col">
        <main className="grid flex-1 place-items-center px-6 py-16">
          <div className="anim-fade-up flex max-w-md flex-col items-center gap-4 rounded-3xl bg-[var(--surface)] p-8 text-center shadow-card">
            <span className="grid size-16 place-items-center rounded-3xl bg-[var(--danger-bg)] text-[var(--danger)]">
              <Icon className="size-8" strokeWidth={1.8} />
            </span>
            <h1 className="text-xl font-bold">{offline ? "មិនអាចភ្ជាប់បាន" : "QR នេះមិនដំណើរការទេ"}</h1>
            <p className="text-[var(--muted)]">
              {offline
                ? "The menu can't load right now. Please try again in a moment, or ask a staff member."
                : "This QR code isn't active. Please ask a staff member for help."}
            </p>
          </div>
        </main>
        <SiteFooter />
      </div>
    );
  }

  return <MenuApp menu={result.data} token={token} />;
}
