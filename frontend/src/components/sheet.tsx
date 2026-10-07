"use client";

import { X } from "lucide-react";
import { useCallback, useEffect, useRef, useState } from "react";

const WIDTHS = { md: "sm:max-w-xl", lg: "sm:max-w-3xl", xl: "sm:max-w-5xl" } as const;

/**
 * Bottom sheet on phones, centered panel on wider screens. Closes on backdrop tap, Escape
 * or the close button, and plays a short exit animation before `onClose` runs.
 */
export default function Sheet({
  children,
  onClose,
  label,
  size = "md",
  bare = false,
}: {
  children: React.ReactNode;
  onClose: () => void;
  label: string;
  size?: keyof typeof WIDTHS;
  /** No padding / gap: the content lays itself out (e.g. a photo edge to edge). */
  bare?: boolean;
}) {
  const [closing, setClosing] = useState(false);
  const timer = useRef<number | null>(null);

  const close = useCallback(() => {
    if (timer.current !== null) return;
    setClosing(true);
    timer.current = window.setTimeout(onClose, 200);
  }, [onClose]);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && close();
    document.addEventListener("keydown", onKey);
    const overflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.removeEventListener("keydown", onKey);
      document.body.style.overflow = overflow;
    };
  }, [close]);

  useEffect(() => () => {
    if (timer.current !== null) window.clearTimeout(timer.current);
  }, []);

  return (
    <div
      className="sheet-backdrop anim-fade-in fixed inset-0 z-40 flex items-end justify-center bg-black/45 backdrop-blur-[2px] sm:items-center sm:p-6"
      data-closing={closing || undefined}
      onClick={close}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-label={label}
        onClick={(e) => e.stopPropagation()}
        data-closing={closing || undefined}
        className={`sheet-panel anim-sheet-up relative flex max-h-[92dvh] w-full flex-col overflow-y-auto rounded-t-3xl bg-[var(--surface)] shadow-float sm:rounded-3xl ${WIDTHS[size]} ${
          bare ? "" : "gap-4 px-5 pt-3 pb-[calc(env(safe-area-inset-bottom,0px)+16px)] sm:px-7 sm:pt-7 sm:pb-7"
        }`}
      >
        <div className={`mx-auto h-1.5 w-10 shrink-0 rounded-full bg-[var(--line)] sm:hidden ${bare ? "absolute top-2 left-1/2 z-10 -translate-x-1/2 bg-white/70" : ""}`} aria-hidden />
        <button
          type="button"
          onClick={close}
          aria-label="Close"
          className="absolute top-3 right-3 z-10 hidden size-9 place-items-center rounded-full bg-[var(--chip)] text-[var(--muted)] transition hover:bg-[var(--line)] hover:text-[var(--fg)] sm:grid"
        >
          <X className="size-4" />
        </button>
        {children}
      </div>
    </div>
  );
}
