"use client";

import { useSyncExternalStore } from "react";
import { Moon, Sun } from "lucide-react";
import { currentTheme, setTheme, subscribeTheme } from "@/lib/theme";

/** Switches light <-> dark. `tone="glass"` sits on the menu hero photo. */
export default function ThemeToggle({ tone = "surface" }: { tone?: "surface" | "glass" }) {
  // null on the server: the theme is only known in the browser.
  const theme = useSyncExternalStore(subscribeTheme, currentTheme, () => null);
  const next = theme === "dark" ? "light" : "dark";
  const label = next === "dark" ? "ងងឹត · Dark mode" : "ភ្លឺ · Light mode";
  const Icon = theme === "dark" ? Sun : Moon;

  const tones = {
    surface: "bg-[var(--chip)] text-[var(--fg)] hover:bg-[var(--brand-soft)] hover:text-[var(--brand-strong)]",
    glass: "bg-white/15 text-white ring-1 ring-white/25 backdrop-blur-md hover:bg-white/25",
  };

  return (
    <button
      type="button"
      onClick={() => setTheme(next)}
      aria-label={label}
      title={label}
      className={`grid size-10 shrink-0 place-items-center rounded-full transition active:scale-95 ${tones[tone]}`}
    >
      {/* key: replay the pop when the icon swaps */}
      <Icon key={theme ?? "unknown"} className={`size-[18px] ${theme ? "anim-pop" : "opacity-0"}`} strokeWidth={2.2} aria-hidden />
    </button>
  );
}
