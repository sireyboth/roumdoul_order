import type { Metadata, Viewport } from "next";
import Image from "next/image";
import Link from "next/link";
import {
  ArrowRight,
  BarChart3,
  Bell,
  ChefHat,
  Banknote,
  Languages,
  LayoutGrid,
  LogIn,
  MapPin,
  Phone,
  Printer,
  QrCode,
  ScanLine,
  Send,
  ShieldCheck,
  Smartphone,
  Wallet,
  Zap,
  type LucideIcon,
} from "lucide-react";
import logo from "@/app/images/logo-roumdoul.png";
import Pricing from "@/components/landing/pricing";
import SiteFooter, { PHONES } from "@/components/site-footer";
import { buttonClass } from "@/components/ui";
import { API_URL } from "@/lib/backend";
import { getPlans } from "@/lib/plans";

export const metadata: Metadata = {
  title: "តុ Roumdoul · QR ordering for cafés and restaurants",
  description: "QR menu, kitchen screen, waiter and cashier app, receipts and daily reports. Built for cafés and restaurants in Cambodia.",
};

/* Phone status bar in the Roumdoul palette (see globals.css). */
export const viewport: Viewport = {
  themeColor: [
    { media: "(prefers-color-scheme: light)", color: "#f8f2ef" },
    { media: "(prefers-color-scheme: dark)", color: "#130f0f" },
  ],
};

/* Owners sign up for a free trial in the back office (Filament registration on Laravel). */
const SIGNUP = `${API_URL}/app/register`;

const FEATURES: { icon: LucideIcon; title: string; km: string; text: string }[] = [
  { icon: QrCode, title: "QR menu on every table", km: "ម៉ឺនុយតាម QR", text: "Guests scan, see photos and prices, pick options and send the order. No app to install." },
  { icon: ChefHat, title: "Kitchen and bar screen", km: "អេក្រង់ផ្ទះបាយ", text: "New orders appear in seconds with a chime and a timer. Tap to move them from New to Preparing to Ready." },
  { icon: Bell, title: "Waiter app", km: "កម្មវិធីអ្នករត់តុ", text: "See who called for help, which dishes are ready to serve, mark items sold out, and take orders at the table." },
  { icon: Wallet, title: "Cashier and bills", km: "គិតលុយ", text: "Cash in dollars or riel with change worked out for you, KHQR and card, discounts with a manager PIN." },
  { icon: Banknote, title: "Cash drawer shifts", km: "វេនបេឡា", text: "Open and close the drawer, record cash in and out, and see the expected cash at the end of every shift." },
  { icon: Printer, title: "Receipts and kitchen tickets", km: "បោះពុម្ពវិក្កយបត្រ", text: "80 mm receipts in Khmer and English with the riel total and your KHQR picture. Tickets print per station." },
  { icon: BarChart3, title: "Sales reports", km: "របាយការណ៍លក់", text: "Today's sales, best sellers and payment methods on the dashboard. Export any period to a spreadsheet." },
  { icon: Send, title: "Telegram day-end summary", km: "សង្ខេបតាម Telegram", text: "Get the day's sales on your phone when the shop closes, even when you are not there." },
  { icon: LayoutGrid, title: "Your floor plan", km: "ប្លង់ហាង", text: "Draw your tables in 2D or 3D. The cashier sees which tables are busy and waiting to pay at a glance." },
];

const REASONS: { icon: LucideIcon; title: string; text: string }[] = [
  { icon: Languages, title: "Made for Cambodia", text: "Khmer and English on every screen, dollars and riel side by side with Cambodian cash rounding, KHQR on the receipt, Telegram for owners." },
  { icon: Smartphone, title: "No new hardware", text: "Runs in the browser on the phones, tablets and computers you already have. Guests never download an app." },
  { icon: Zap, title: "Live, not refresh-and-hope", text: "Orders, calls and payments update on every screen within seconds, and keep checking for changes even if the live connection drops." },
  { icon: MapPin, title: "No prank orders", text: "Optionally accept orders only from guests who are inside your shop, using the phone's location." },
  { icon: ShieldCheck, title: "Money you can trust", text: "Prices are always checked on our server, nothing is ever deleted, and every void, refund and discount needs a reason and is recorded." },
  { icon: Phone, title: "Local support", text: "A Cambodian team you can call or message, who help you set up your menu, tables and QR codes." },
];

export default async function Home() {
  const plans = await getPlans();

  return (
    <div className="palette-roumdoul flex min-h-dvh flex-col">
      <header className="sticky top-0 z-30 border-b border-[var(--line)] bg-[var(--bg)]/80 backdrop-blur-md">
        <div className="mx-auto flex h-16 w-full max-w-[1200px] items-center gap-4 px-4 sm:px-6">
          <Link href="/" className="flex items-center gap-2.5">
            <Image src={logo} alt="" className="h-9 w-auto" sizes="40px" priority />
            <span className="text-lg font-bold text-[var(--rose-strong)]">តុ Roumdoul</span>
          </Link>
          <nav className="ml-auto hidden items-center gap-1 md:flex">
            {[
              ["#pricing", "Pricing"],
              ["#features", "Features"],
              ["#why", "Why us"],
            ].map(([href, label]) => (
              <a key={href} href={href} className={buttonClass("ghost", "sm")}>
                {label}
              </a>
            ))}
          </nav>
          <Link href="/staff" className={buttonClass("neutral", "sm", "ml-auto md:ml-2")}>
            <LogIn className="size-4" aria-hidden />
            Staff sign in
          </Link>
        </div>
      </header>

      <main className="flex-1">
        {/* Hero */}
        <section className="relative overflow-hidden">
          <div className="pointer-events-none absolute -top-40 -right-40 size-[30rem] rounded-full bg-[var(--brand-soft)] blur-3xl" aria-hidden />
          <div className="pointer-events-none absolute -bottom-48 -left-40 size-[26rem] rounded-full bg-[var(--accent-soft)] blur-3xl" aria-hidden />
          <div className="relative mx-auto grid w-full max-w-[1200px] items-center gap-12 px-4 py-16 sm:px-6 lg:grid-cols-[1.15fr_1fr] lg:py-24">
            <div className="anim-fade-up flex flex-col items-start gap-6">
              <span className="rounded-full bg-[var(--rose-soft)] px-4 py-1.5 text-sm font-semibold text-[var(--rose-strong)]">
                ប្រព័ន្ធកុម្ម៉ង់តាម QR សម្រាប់ហាងកាហ្វេ និងភោជនីយដ្ឋាន
              </span>
              <h1 className="text-4xl leading-tight font-bold tracking-tight sm:text-5xl">
                Guests order from their phone. <span className="text-[var(--brand)]">Your team just serves.</span>
              </h1>
              <p className="max-w-xl text-lg text-[var(--muted)]">
                One system for the QR menu, the kitchen, waiters, the cashier and your daily reports. Made for cafés and restaurants in Cambodia.
              </p>
              <div className="flex flex-wrap gap-3">
                <a href={SIGNUP} className={buttonClass("brand", "lg")}>
                  Start free trial
                  <ArrowRight className="size-[18px]" aria-hidden />
                </a>
                <a href="#pricing" className={buttonClass("neutral", "lg")}>
                  See prices
                </a>
              </div>
              <p className="flex items-center gap-2 text-sm text-[var(--muted)]">
                <ScanLine className="size-4 text-[var(--accent)]" aria-hidden />
                Here to eat? Scan the QR code on your table to see the menu. · ស្កេន QR នៅលើតុ ដើម្បីកុម្ម៉ង់។
              </p>
            </div>
            <HeroPreview />
          </div>
        </section>

        {/* Pricing */}
        <section id="pricing" className="scroll-mt-20 px-4 py-16 sm:px-6 lg:py-24">
          <div className="mx-auto flex max-w-[1200px] flex-col gap-10">
            <SectionTitle km="តម្លៃ" title="Simple, fair prices" text="Every plan includes the QR menu and all staff screens. Pay monthly, or only for the orders you get." />
            <Pricing plans={plans} phone={PHONES[0]} signupUrl={SIGNUP} />
          </div>
        </section>

        {/* Features */}
        <section id="features" className="scroll-mt-20 border-y border-[var(--line)] bg-[var(--surface)] px-4 py-16 sm:px-6 lg:py-24">
          <div className="mx-auto flex max-w-[1200px] flex-col gap-12">
            <SectionTitle km="អ្វីដែលយើងធ្វើបាន" title="Everything your shop needs, in one place" text="From the moment a guest sits down to the end-of-day report." />
            <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
              {FEATURES.map(({ icon: Icon, title, km, text }) => (
                <article key={title} className="flex flex-col gap-3 rounded-3xl border border-[var(--line)] bg-[var(--bg)] p-6">
                  <span className="grid size-12 place-items-center rounded-2xl bg-[var(--brand-soft)] text-[var(--brand)]">
                    <Icon className="size-6" strokeWidth={2} aria-hidden />
                  </span>
                  <div>
                    <h3 className="text-lg font-bold">{title}</h3>
                    <p className="text-sm text-[var(--rose-strong)]">{km}</p>
                  </div>
                  <p className="text-[var(--muted)]">{text}</p>
                </article>
              ))}
            </div>
          </div>
        </section>

        {/* Why us */}
        <section id="why" className="scroll-mt-20 px-4 py-16 sm:px-6 lg:py-24">
          <div className="mx-auto flex max-w-[1200px] flex-col gap-12">
            <SectionTitle km="ហេតុអ្វីជ្រើសរើសយើង" title="Why shops choose Roumdoul" text="Most ordering systems are built for other countries and then translated. Ours is built here, for the way Cambodian shops work." />
            <div className="grid gap-x-10 gap-y-8 md:grid-cols-2 lg:grid-cols-3">
              {REASONS.map(({ icon: Icon, title, text }) => (
                <div key={title} className="flex gap-4">
                  <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-[var(--accent-soft)] text-[var(--fg)]">
                    <Icon className="size-5" strokeWidth={2.2} aria-hidden />
                  </span>
                  <div className="flex flex-col gap-1">
                    <h3 className="font-bold">{title}</h3>
                    <p className="text-[var(--muted)]">{text}</p>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* Call to action */}
        <section className="px-4 pb-20 sm:px-6">
          <div className="relative mx-auto flex max-w-[1200px] flex-col items-center gap-5 overflow-hidden rounded-[2rem] bg-[var(--brand)] px-6 py-14 text-center text-white dark:text-[#1a0f0f]">
            <div className="pointer-events-none absolute -top-24 -right-24 size-72 rounded-full bg-white/10" aria-hidden />
            <h2 className="relative text-3xl font-bold sm:text-4xl">Ready to try it in your shop?</h2>
            <p className="relative max-w-xl opacity-90">Start a free trial on your own, or call us and we&apos;ll set up your menu and table QR codes with you.</p>
            <div className="relative flex flex-wrap justify-center gap-3">
              <a href={SIGNUP} className={buttonClass("dark", "lg")}>
                Start free trial
                <ArrowRight className="size-[18px]" aria-hidden />
              </a>
              {PHONES.map((phone) => (
                <a key={phone} href={`tel:${phone.replace(/\s/g, "")}`} className={buttonClass("accent", "lg")}>
                  <Phone className="size-[18px]" aria-hidden />
                  {phone}
                </a>
              ))}
            </div>
          </div>
        </section>
      </main>

      <SiteFooter />
    </div>
  );
}

function SectionTitle({ km, title, text }: { km: string; title: string; text: string }) {
  return (
    <div className="mx-auto flex max-w-2xl flex-col items-center gap-3 text-center">
      <span className="text-sm font-semibold text-[var(--rose-strong)]">{km}</span>
      <h2 className="text-3xl font-bold tracking-tight sm:text-4xl">{title}</h2>
      <p className="text-lg text-[var(--muted)]">{text}</p>
    </div>
  );
}

/* A drawn preview of an order going from a guest's phone to the kitchen. Not real data. */
function HeroPreview() {
  return (
    <div className="anim-fade-up relative mx-auto w-full max-w-sm" style={{ "--i": 3 } as React.CSSProperties} aria-hidden>
      <div className="rounded-[2.2rem] border border-[var(--line)] bg-[var(--surface)] p-5 shadow-float">
        <div className="flex items-center justify-between">
          <span className="font-bold">Table T3</span>
          <span className="rounded-full bg-[var(--brand-soft)] px-3 py-1 text-xs font-semibold text-[var(--brand)]">Order sent</span>
        </div>
        <ul className="mt-4 flex flex-col gap-3">
          {[
            ["Iced latte", "Less sugar · Large", "$3.50"],
            ["Fried rice with pork", "No chili", "$4.00"],
            ["Passion fruit soda", "", "$2.50"],
          ].map(([name, note, cost]) => (
            <li key={name} className="flex items-center gap-3 rounded-2xl bg-[var(--bg)] p-3">
              <span className="size-11 shrink-0 rounded-xl bg-[var(--accent-soft)]" />
              <span className="flex min-w-0 flex-1 flex-col">
                <span className="truncate font-semibold">{name}</span>
                {note && <span className="truncate text-xs text-[var(--muted)]">{note}</span>}
              </span>
              <span className="font-semibold">{cost}</span>
            </li>
          ))}
        </ul>
        <div className="mt-4 flex items-baseline justify-between border-t border-[var(--line)] pt-4">
          <span className="text-[var(--muted)]">Total</span>
          <span className="text-right">
            <span className="block text-2xl font-bold">$10.00</span>
            <span className="text-xs text-[var(--muted)]">41,000៛</span>
          </span>
        </div>
      </div>
      <div className="anim-float absolute -bottom-8 -left-2 flex items-center gap-3 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-3 pr-5 shadow-card sm:-left-12">
        <span className="grid size-10 place-items-center rounded-xl bg-[var(--brand)] text-white dark:text-[#1a0f0f]">
          <ChefHat className="size-5" />
        </span>
        <span className="flex flex-col">
          <span className="text-sm font-bold">Kitchen · New order</span>
          <span className="text-xs text-[var(--muted)]">T3 · 3 items · just now</span>
        </span>
      </div>
    </div>
  );
}
