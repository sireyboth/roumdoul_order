/* Shared pieces of the staff screens: the top bar back link and the "signed out" screen. */

import Link from "next/link";
import { ArrowLeft, LogIn, LockKeyhole, Printer, X } from "lucide-react";
import { IconTile, buttonClass } from "../ui";

/** Wide page container: full screen on desktop with comfortable side margins. */
export const PAGE = "mx-auto w-full max-w-[1600px] px-4 sm:px-6 lg:px-10";

export function BackLink({ dark = false }: { dark?: boolean }) {
  return (
    <Link
      href="/staff"
      aria-label="Back to screens"
      className={`grid size-10 shrink-0 place-items-center rounded-2xl transition active:scale-95 ${
        dark ? "bg-white/8 text-white/80 hover:bg-white/15 hover:text-white" : "border border-[var(--line)] bg-[var(--surface)] text-[var(--muted)] hover:bg-[var(--surface-2)] hover:text-[var(--fg)]"
      }`}
    >
      <ArrowLeft className="size-5" aria-hidden />
    </Link>
  );
}

/** Screen-only bar above an 80 mm print page (hidden when printing). */
export function PrintToolbar() {
  return (
    <div className="no-print sticky top-0 z-10 flex justify-center gap-2 border-b border-[var(--line)] bg-[var(--surface)]/85 p-3 backdrop-blur-md">
      <button type="button" onClick={() => window.print()} className={buttonClass("brand", "md")}>
        <Printer className="size-[18px]" aria-hidden />
        Print
      </button>
      <button type="button" onClick={() => window.close()} className={buttonClass("neutral", "md")}>
        <X className="size-[18px]" aria-hidden />
        Close
      </button>
    </div>
  );
}

/** Placeholder for a print page while its data loads. */
export function PrintLoading() {
  return (
    <div className="mx-auto flex w-[72mm] flex-col gap-2 py-8" aria-label="Loading">
      <div className="skeleton h-6 w-2/3 self-center rounded" />
      <div className="skeleton h-4 w-1/2 self-center rounded" />
      <div className="skeleton mt-4 h-40 rounded-xl" />
    </div>
  );
}

export function SignedOutScreen({ dark = false }: { dark?: boolean }) {
  return (
    <main className={`grid min-h-dvh place-items-center p-6 ${dark ? "bg-[#0d1110] text-white" : ""}`}>
      <div className={`anim-scale-in flex w-full max-w-sm flex-col items-center gap-4 rounded-3xl p-8 text-center ${dark ? "bg-white/5" : "bg-[var(--surface)] shadow-card"}`}>
        <IconTile icon={LockKeyhole} className="size-14" />
        <div>
          <h1 className="text-xl font-bold">You were signed out</h1>
          <p className={dark ? "text-white/60" : "text-[var(--muted)]"}>Sign in again to keep using this screen.</p>
        </div>
        <Link href="/staff" className={buttonClass("brand", "lg", "w-full")}>
          <LogIn className="size-[18px]" aria-hidden />
          Sign in again
        </Link>
      </div>
    </main>
  );
}
