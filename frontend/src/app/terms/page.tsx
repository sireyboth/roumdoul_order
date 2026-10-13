import type { Metadata, Viewport } from "next";
import Image from "next/image";
import Link from "next/link";
import { ArrowLeft, FileText, Phone } from "lucide-react";
import logo from "@/app/images/logo-roumdoul.png";
import SiteFooter, { PHONES } from "@/components/site-footer";
import { buttonClass } from "@/components/ui";
import { TERMS, TERMS_EFFECTIVE, TERMS_VERSION } from "@/lib/terms";

export const metadata: Metadata = {
  title: "Terms and conditions · តុ Roumdoul",
  description: "The agreement between Roumdoul and restaurants using Roumdoul Order: plans, payment, upgrades, downgrades, refunds and your data.",
};

export const viewport: Viewport = {
  themeColor: [
    { media: "(prefers-color-scheme: light)", color: "#f8f2ef" },
    { media: "(prefers-color-scheme: dark)", color: "#130f0f" },
  ],
};

/* Terms for restaurants (owners agree at sign-up and at every plan change). Text: lib/terms.ts. */
export default function TermsPage() {
  return (
    <div className="palette-roumdoul flex min-h-dvh flex-col">
      <header className="sticky top-0 z-30 border-b border-[var(--line)] bg-[var(--bg)]/80 backdrop-blur-md">
        <div className="mx-auto flex h-16 w-full max-w-[1200px] items-center gap-4 px-4 sm:px-6">
          <Link href="/" className="flex items-center gap-2.5">
            <Image src={logo} alt="" className="h-9 w-auto" sizes="40px" priority />
            <span className="text-lg font-bold text-[var(--rose-strong)]">តុ Roumdoul</span>
          </Link>
          <Link href="/" className={buttonClass("ghost", "sm", "ml-auto")}>
            <ArrowLeft className="size-4" aria-hidden />
            Home
          </Link>
        </div>
      </header>

      <main className="mx-auto grid w-full max-w-[1200px] flex-1 gap-10 px-4 py-10 sm:px-6 lg:grid-cols-[240px_1fr] lg:py-14">
        {/* Contents: a sticky list on wide screens, a wrap of chips on phones. */}
        <nav aria-label="Contents" className="lg:sticky lg:top-24 lg:self-start">
          <p className="mb-3 text-sm font-semibold text-[var(--muted)]">Contents</p>
          <ol className="flex flex-wrap gap-2 lg:flex-col lg:gap-1">
            {TERMS.map((section, i) => (
              <li key={section.id}>
                <a
                  href={`#${section.id}`}
                  className="block rounded-full bg-[var(--surface)] px-3 py-1.5 text-sm text-[var(--fg)] ring-1 ring-[var(--line)] transition hover:text-[var(--rose-strong)] lg:rounded-lg lg:bg-transparent lg:ring-0 lg:hover:bg-[var(--surface)]"
                >
                  {i + 1}. {section.title}
                </a>
              </li>
            ))}
          </ol>
        </nav>

        <article className="min-w-0">
          <div className="anim-fade-up mb-10 flex flex-col gap-3">
            <span className="inline-flex w-fit items-center gap-2 rounded-full bg-[var(--rose-soft)] px-4 py-1.5 text-sm font-semibold text-[var(--rose-strong)]">
              <FileText className="size-4" aria-hidden />
              លក្ខខណ្ឌប្រើប្រាស់
            </span>
            <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">Terms and conditions</h1>
            <p className="text-[var(--muted)]">
              For restaurants and cafés using Roumdoul Order. Effective {TERMS_EFFECTIVE} (version {TERMS_VERSION}). You agree to these when you
              register and each time you choose or change a plan.
            </p>
          </div>

          <div className="flex flex-col gap-6">
            {TERMS.map((section, i) => (
              <section
                key={section.id}
                id={section.id}
                className="scroll-mt-24 rounded-3xl bg-[var(--surface)] p-6 shadow-card sm:p-8"
              >
                <h2 className="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-xl font-bold">
                  <span>
                    {i + 1}. {section.title}
                  </span>
                  <span className="text-base font-medium text-[var(--muted)]">{section.km}</span>
                </h2>
                <ul className="mt-4 flex flex-col gap-3 leading-relaxed">
                  {section.points.map((point) => (
                    <li key={point} className="flex gap-3">
                      <span className="mt-2.5 size-1.5 shrink-0 rounded-full bg-[var(--rose)]" aria-hidden />
                      <span>{point}</span>
                    </li>
                  ))}
                </ul>
              </section>
            ))}
          </div>

          <div className="mt-10 flex flex-col items-start gap-4 rounded-3xl bg-[var(--rose-soft)] p-6 sm:flex-row sm:items-center sm:justify-between sm:p-8">
            <div>
              <p className="font-bold text-[var(--rose-strong)]">Questions about these terms?</p>
              <p className="text-sm text-[var(--muted)]">Call us, we explain everything in Khmer or English.</p>
            </div>
            <a href={`tel:${PHONES[0].replace(/\s/g, "")}`} className={buttonClass("brand", "md")}>
              <Phone className="size-4" aria-hidden />
              {PHONES[0]}
            </a>
          </div>
        </article>
      </main>

      <SiteFooter />
    </div>
  );
}
