"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { SignedOut, staffApi, type Board } from "@/lib/staff";

/** Loads the branch board and refreshes it every few seconds. Live push (Reverb) replaces this later. */
export function useBoard(branchId: number, station: string | null, intervalMs = 3000) {
  const [board, setBoard] = useState<Board | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [signedOut, setSignedOut] = useState(false);
  const [now, setNow] = useState(() => Date.now());
  const busy = useRef(false);

  const refresh = useCallback(async () => {
    if (busy.current) return;
    busy.current = true;
    try {
      const query = station ? `?station=${station}` : "";
      setBoard(await staffApi<Board>(`branches/${branchId}/board${query}`));
      setError(null);
    } catch (e) {
      if (e instanceof SignedOut) setSignedOut(true);
      else setError(e instanceof Error ? e.message : "Can't load orders.");
    } finally {
      busy.current = false;
    }
  }, [branchId, station]);

  useEffect(() => {
    const first = window.setTimeout(() => void refresh(), 0);
    const poll = window.setInterval(() => void refresh(), intervalMs);
    const tick = window.setInterval(() => setNow(Date.now()), 1000);
    return () => {
      window.clearTimeout(first);
      window.clearInterval(poll);
      window.clearInterval(tick);
    };
  }, [refresh, intervalMs]);

  return { board, error, signedOut, now, refresh };
}
