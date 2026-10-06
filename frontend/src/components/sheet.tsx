"use client";

import { useEffect } from "react";

/** Bottom sheet on phones, centered panel on wider screens. Closes on backdrop tap or Escape. */
export default function Sheet({ children, onClose, label }: { children: React.ReactNode; onClose: () => void; label: string }) {
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    document.addEventListener("keydown", onKey);
    const overflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.removeEventListener("keydown", onKey);
      document.body.style.overflow = overflow;
    };
  }, [onClose]);

  return (
    <div className="fixed inset-0 z-30 flex items-end justify-center bg-black/40 sm:items-center" onClick={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        aria-label={label}
        onClick={(e) => e.stopPropagation()}
        className="flex max-h-[90dvh] w-full max-w-xl flex-col gap-4 overflow-y-auto rounded-t-2xl bg-[var(--surface)] px-5 pt-3 pb-[calc(env(safe-area-inset-bottom,0px)+12px)] sm:rounded-2xl"
      >
        <div className="mx-auto h-1 w-10 shrink-0 rounded-full bg-[var(--line)] sm:hidden" aria-hidden />
        {children}
      </div>
    </div>
  );
}
