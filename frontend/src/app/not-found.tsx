import type { Metadata } from "next";
import Link from "next/link";
import { Home, QrCode, SearchX } from "lucide-react";
import SiteFooter from "@/components/site-footer";
import { buttonClass } from "@/components/ui";

export const metadata: Metadata = {
  title: "Page not found · Roumdoul Order",
};

export default function NotFound() {
  return (
    <div className="palette-roumdoul flex min-h-dvh flex-col">
      <main className="relative grid flex-1 place-items-center overflow-hidden px-6 py-16">
        <div className="pointer-events-none absolute -top-40 -right-40 size-[28rem] rounded-full bg-[var(--brand-soft)] blur-3xl" aria-hidden />
        <div className="pointer-events-none absolute -bottom-40 -left-40 size-[24rem] rounded-full bg-[var(--accent-soft)] blur-3xl" aria-hidden />
        <div className="anim-fade-up relative flex max-w-md flex-col items-center gap-5 text-center">
          <span className="anim-float relative grid size-24 place-items-center rounded-[28px] bg-[var(--surface)] text-[var(--brand)] shadow-float">
            <SearchX className="size-11" strokeWidth={1.8} aria-hidden />
          </span>
          <p className="text-6xl font-bold tracking-tight text-[var(--brand)]">404</p>
          <h1 className="text-2xl font-bold sm:text-3xl">រកមិនឃើញទំព័រនេះទេ</h1>
          <p className="text-[var(--muted)]">
            Page not found. The link may be wrong or the page has moved.
            <br />
            ដើម្បីកុម្ម៉ង់ សូមស្កេន QR នៅលើតុរបស់អ្នកម្តងទៀត។
            <br />
            <span className="text-sm">To order, scan the QR code on your table again.</span>
          </p>
          <div className="flex flex-wrap items-center justify-center gap-3">
            <Link href="/" className={buttonClass("brand", "md")}>
              <Home className="size-[18px]" strokeWidth={2.2} aria-hidden />
              ទំព័រដើម · Home
            </Link>
            <Link href="/staff" className={buttonClass("neutral", "md")}>
              <QrCode className="size-[18px]" strokeWidth={2.2} aria-hidden />
              បុគ្គលិក · Staff
            </Link>
          </div>
        </div>
      </main>
      <SiteFooter />
    </div>
  );
}
