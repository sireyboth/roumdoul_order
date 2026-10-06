"use client";

import { useEffect, useState } from "react";
import { staffApi } from "@/lib/staff";

/**
 * Loads what a print page shows, then (with `auto`) opens the print dialog
 * once fonts and pictures are ready, so Khmer text and the KHQR never print blank.
 */
export function usePrint<T>(path: string, auto: boolean) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    staffApi<T>(path)
      .then((d) => !cancelled && setData(d))
      .catch((e) => !cancelled && setError(e instanceof Error ? e.message : "Can't load."));
    return () => {
      cancelled = true;
    };
  }, [path]);

  useEffect(() => {
    if (!data || !auto) return;
    let cancelled = false;
    const images = [...document.images].map((img) =>
      img.complete
        ? Promise.resolve()
        : new Promise<void>((done) => {
            img.addEventListener("load", () => done(), { once: true });
            img.addEventListener("error", () => done(), { once: true });
          }),
    );
    void Promise.all([document.fonts.ready, ...images]).then(() => {
      if (!cancelled) window.setTimeout(() => window.print(), 100);
    });
    return () => {
      cancelled = true;
    };
  }, [data, auto]);

  return { data, error };
}
