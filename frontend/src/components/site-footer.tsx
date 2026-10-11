/* "Made by Roumdoul" footer on every customer page. Colours follow the Roumdoul logo (rose), light and dark. */

import Image from "next/image";
import { Phone } from "lucide-react";
import logo from "@/app/images/logo-roumdoul.png";
import ThemeToggle from "./theme-toggle";

const WEBSITE = "https://roumdoul.com/";

/* Replace "#" with the real page links. */
const SOCIALS = [
  { name: "Facebook", href: "#", icon: FacebookIcon },
  { name: "Instagram", href: "#", icon: InstagramIcon },
  { name: "TikTok", href: "#", icon: TikTokIcon },
  { name: "Telegram", href: "#", icon: TelegramIcon },
];

const PHONES = ["+855 15 57 87 07", "+855 71 26 000 78"];

export default function SiteFooter({ className = "", themeToggle = true }: { className?: string; themeToggle?: boolean }) {
  return (
    <footer className={`border-t border-[var(--line)] bg-[var(--surface)] ${className}`}>
      <div className="mx-auto flex w-full max-w-[1440px] flex-col items-center gap-5 px-4 py-8 text-center sm:px-6 md:flex-row md:justify-between md:text-left lg:px-10">
        <span className="text-lg font-bold text-[var(--rose-strong)]">Made by</span>
        <a href={WEBSITE} target="_blank" rel="noopener noreferrer" className="group flex items-center gap-3">
          <span className="grid size-14 shrink-0 place-items-center rounded-2xl bg-[var(--rose-soft)] transition group-hover:scale-105">
            <Image src={logo} alt="Roumdoul" className="h-11 w-auto" sizes="48px" />
          </span>
          <span className="flex flex-col">
            <span className="text-lg font-bold text-[var(--rose-strong)]">តុ Roumdoul</span>
            <span className="text-xs text-[var(--muted)]">roumdoul.com</span>
          </span>
        </a>

        <div className="flex flex-col items-center gap-3 md:items-end">
          <ul className="flex items-center gap-2">
            {SOCIALS.map(({ name, href, icon: Icon }) => (
              <li key={name}>
                <a
                  href={href}
                  aria-label={name}
                  title={name}
                  {...(href !== "#" && { target: "_blank", rel: "noopener noreferrer" })}
                  className="grid size-10 place-items-center rounded-full bg-[var(--rose-soft)] text-[var(--rose-strong)] transition hover:bg-[var(--rose)] hover:text-white active:scale-95 dark:hover:text-[#1a0f0f]"
                >
                  <Icon className="size-[18px]" />
                </a>
              </li>
            ))}
            {themeToggle && (
              <li className="ml-1 border-l border-[var(--line)] pl-3">
                <ThemeToggle />
              </li>
            )}
          </ul>
          <ul className="flex flex-wrap items-center justify-center gap-x-4 gap-y-1 text-sm">
            {PHONES.map((phone) => (
              <li key={phone}>
                <a href={`tel:${phone.replace(/\s/g, "")}`} className="inline-flex items-center gap-1.5 font-medium text-[var(--fg)] hover:text-[var(--rose-strong)]">
                  <Phone className="size-3.5 text-[var(--rose)]" strokeWidth={2.4} aria-hidden />
                  {phone}
                </a>
              </li>
            ))}
          </ul>
        </div>
      </div>
      <p className="border-t border-[var(--line)] px-4 py-3 text-center text-xs text-[var(--muted)]">
        © {new Date().getFullYear()} Roumdoul · ប្រព័ន្ធកុម្ម៉ង់តាម QR · QR ordering
      </p>
    </footer>
  );
}

/* Brand marks (lucide has no brand logos). */

type IconProps = { className?: string };

function FacebookIcon({ className }: IconProps) {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" className={className} aria-hidden>
      <path d="M13.5 21v-7.5h2.6l.4-3h-3V8.6c0-.9.3-1.5 1.5-1.5h1.6V4.4A21 21 0 0 0 14.3 4c-2.3 0-3.9 1.4-3.9 4v2.5H7.8v3h2.6V21h3.1Z" />
    </svg>
  );
}

function InstagramIcon({ className }: IconProps) {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" className={className} aria-hidden>
      <rect x="3" y="3" width="18" height="18" rx="5" />
      <circle cx="12" cy="12" r="4" />
      <circle cx="17.5" cy="6.5" r="0.6" fill="currentColor" />
    </svg>
  );
}

function TikTokIcon({ className }: IconProps) {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" className={className} aria-hidden>
      <path d="M16.6 3h-3.1v12.2a2.7 2.7 0 1 1-2.7-2.7c.3 0 .5 0 .8.1V9.4a5.8 5.8 0 1 0 5 5.8V9a7.3 7.3 0 0 0 4.2 1.3V7.2A4.2 4.2 0 0 1 16.6 3Z" />
    </svg>
  );
}

function TelegramIcon({ className }: IconProps) {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" className={className} aria-hidden>
      <path d="M20.7 4.1 2.9 11c-1.2.5-1.2 1.2-.2 1.5l4.6 1.4 1.7 5.4c.2.6.4.8.9.8.4 0 .6-.2.9-.4l2.2-2.1 4.6 3.4c.8.5 1.4.2 1.6-.8l3-14.1c.3-1.2-.5-1.8-1.5-1.3ZM8.6 13.6l9.3-5.9c.4-.3.9-.1.5.3l-7.9 7.1-.3 3.3-1.6-4.8Z" />
    </svg>
  );
}
