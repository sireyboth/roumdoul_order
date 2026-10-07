/* Small shared building blocks, so every screen uses the same buttons, chips and spacing. */

import type { LucideIcon } from "lucide-react";

type Tone = "brand" | "neutral" | "ghost" | "danger" | "accent" | "dark";

const BUTTON_TONES: Record<Tone, string> = {
  brand: "bg-[var(--brand)] text-white shadow-soft hover:bg-[var(--brand-strong)] dark:text-[#06140f]",
  neutral: "border border-[var(--line)] bg-[var(--surface)] text-[var(--fg)] hover:bg-[var(--surface-2)]",
  ghost: "text-[var(--fg)] hover:bg-[var(--chip)]",
  danger: "bg-[var(--danger)] text-white hover:opacity-90",
  accent: "bg-[var(--accent)] text-[#2b1d06] hover:brightness-105",
  dark: "bg-[var(--fg)] text-[var(--surface)] hover:opacity-90",
};

const BUTTON_SIZES = {
  sm: "h-9 gap-1.5 rounded-full px-3.5 text-sm",
  md: "h-11 gap-2 rounded-2xl px-4 text-[15px]",
  lg: "h-14 gap-2.5 rounded-2xl px-5 text-base",
} as const;

export function buttonClass(tone: Tone = "brand", size: keyof typeof BUTTON_SIZES = "md", extra = "") {
  return `inline-flex shrink-0 items-center justify-center font-semibold whitespace-nowrap transition duration-150 active:scale-[0.97] disabled:pointer-events-none disabled:opacity-45 ${BUTTON_TONES[tone]} ${BUTTON_SIZES[size]} ${extra}`;
}

export function Button({
  tone = "brand",
  size = "md",
  icon: Icon,
  className = "",
  children,
  ...props
}: React.ButtonHTMLAttributes<HTMLButtonElement> & { tone?: Tone; size?: keyof typeof BUTTON_SIZES; icon?: LucideIcon }) {
  return (
    <button type="button" {...props} className={buttonClass(tone, size, className)}>
      {Icon && <Icon className={size === "sm" ? "size-4" : "size-[18px]"} strokeWidth={2.2} aria-hidden />}
      {children}
    </button>
  );
}

const BADGE_TONES = {
  brand: "bg-[var(--brand-soft)] text-[var(--brand-strong)]",
  neutral: "bg-[var(--chip)] text-[var(--muted)]",
  warn: "bg-[var(--warn-bg)] text-[var(--warn)]",
  danger: "bg-[var(--danger-bg)] text-[var(--danger)]",
  solid: "bg-[var(--brand)] text-white dark:text-[#06140f]",
  accent: "bg-[var(--accent)] text-[#2b1d06]",
} as const;

export function Badge({ tone = "neutral", icon: Icon, children, className = "" }: { tone?: keyof typeof BADGE_TONES; icon?: LucideIcon; children: React.ReactNode; className?: string }) {
  return (
    <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap ${BADGE_TONES[tone]} ${className}`}>
      {Icon && <Icon className="size-3.5" strokeWidth={2.4} aria-hidden />}
      {children}
    </span>
  );
}

export function Alert({ tone = "danger", children }: { tone?: "danger" | "warn" | "brand"; children: React.ReactNode }) {
  const tones = {
    danger: "bg-[var(--danger-bg)] text-[var(--danger)]",
    warn: "bg-[var(--warn-bg)] text-[var(--warn)]",
    brand: "bg-[var(--brand-soft)] text-[var(--brand-strong)]",
  };
  return (
    <p role="alert" className={`anim-fade-in rounded-2xl px-4 py-3 text-sm font-medium ${tones[tone]}`}>
      {children}
    </p>
  );
}

export const inputClass =
  "h-11 w-full rounded-2xl border border-[var(--line)] bg-[var(--surface)] px-3.5 text-[15px] outline-none transition placeholder:text-[var(--muted)] focus:border-[var(--brand)] focus:ring-4 focus:ring-[var(--ring)]";

export function Field({ label, children, hint }: { label: string; children: React.ReactNode; hint?: string }) {
  return (
    <label className="flex flex-col gap-1.5">
      <span className="text-sm font-semibold">{label}</span>
      {children}
      {hint && <span className="text-xs text-[var(--muted)]">{hint}</span>}
    </label>
  );
}

/** Round icon tile used in headers and empty states. */
export function IconTile({ icon: Icon, tone = "brand", className = "" }: { icon: LucideIcon; tone?: "brand" | "accent" | "neutral" | "danger"; className?: string }) {
  const tones = {
    brand: "bg-[var(--brand-soft)] text-[var(--brand)]",
    accent: "bg-[var(--accent-soft)] text-[var(--warn)]",
    neutral: "bg-[var(--chip)] text-[var(--muted)]",
    danger: "bg-[var(--danger-bg)] text-[var(--danger)]",
  };
  return (
    <span className={`grid size-11 shrink-0 place-items-center rounded-2xl ${tones[tone]} ${className}`}>
      <Icon className="size-5" strokeWidth={2.2} aria-hidden />
    </span>
  );
}

export function EmptyState({ icon, title, text }: { icon: LucideIcon; title: string; text?: string }) {
  return (
    <div className="anim-fade-in flex flex-col items-center gap-3 rounded-3xl border border-dashed border-[var(--line)] px-6 py-12 text-center">
      <IconTile icon={icon} tone="neutral" className="size-14" />
      <p className="font-semibold">{title}</p>
      {text && <p className="max-w-sm text-sm text-[var(--muted)]">{text}</p>}
    </div>
  );
}

/** Animated check mark for success moments. */
export function SuccessMark({ className = "size-20" }: { className?: string }) {
  return (
    <span className={`anim-scale-in grid place-items-center rounded-full bg-[var(--brand)] text-white shadow-card dark:text-[#06140f] ${className}`}>
      <svg viewBox="0 0 24 24" className="w-1/2" fill="none" stroke="currentColor" strokeWidth={3} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
        <path d="M5 12.5l4.5 4.5L19 7.5" className="anim-draw" />
      </svg>
    </span>
  );
}
