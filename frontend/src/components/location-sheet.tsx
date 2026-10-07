"use client";

import { LocateFixed, MapPin, MapPinOff, RotateCw, ShieldCheck } from "lucide-react";
import type { Lang } from "@/lib/types";
import type { LocationProblem } from "@/lib/location";
import { t } from "@/lib/i18n";
import Sheet from "./sheet";

export type LocStage = "ask" | "checking" | "too_far" | LocationProblem;

/** Explains why the shop needs the phone's location, and what to do when it fails. */
export default function LocationSheet({
  stage,
  lang,
  onRetry,
  onClose,
}: {
  stage: LocStage;
  lang: Lang;
  onRetry: () => void;
  onClose: () => void;
}) {
  const problem = stage !== "ask" && stage !== "checking";
  const text =
    stage === "ask" || stage === "checking"
      ? t("locWhy", lang)
      : stage === "too_far"
        ? t("locTooFar", lang)
        : stage === "denied"
          ? t("locDenied", lang)
          : stage === "insecure" || stage === "unsupported"
            ? t("locInsecure", lang)
            : t("locUnavailable", lang);
  const Icon = problem ? MapPinOff : MapPin;
  const title = stage === "too_far" ? t("locTitleFar", lang) : problem ? t("locTitleProblem", lang) : t("locTitle", lang);

  return (
    <Sheet onClose={onClose} label={t("locTitle", lang)}>
      <div className="flex flex-col items-center gap-3 pt-2 text-center">
        <span className="relative grid size-20 place-items-center">
          {!problem && <span className="anim-ring absolute inset-2 rounded-full" aria-hidden />}
          <span
            className={`anim-scale-in relative grid size-16 place-items-center rounded-full ${
              problem ? "bg-[var(--danger-bg)] text-[var(--danger)]" : "bg-[var(--brand-soft)] text-[var(--brand)]"
            }`}
          >
            <Icon className={`size-8 ${stage === "checking" ? "anim-float" : ""}`} strokeWidth={1.9} />
          </span>
        </span>
        <h2 className="text-xl font-bold">{title}</h2>
        <p className="max-w-md text-[15px] text-[var(--muted)]">{text}</p>
        {!problem && (
          <p className="flex items-center gap-1.5 text-xs font-medium text-[var(--muted)]">
            <ShieldCheck className="size-4 text-[var(--brand)]" />
            {lang === "km" ? "ប្រើតែពេលផ្ញើការកុម្ម៉ង់ប៉ុណ្ណោះ" : "Only used when you send an order"}
          </p>
        )}
      </div>

      {stage === "checking" ? (
        <div className="flex h-14 items-center justify-center gap-2 rounded-2xl bg-[var(--chip)] font-bold text-[var(--muted)]">
          <span className="size-5 animate-spin rounded-full border-2 border-[var(--line)] border-t-[var(--brand)]" aria-hidden />
          {t("locChecking", lang)}
        </div>
      ) : stage !== "insecure" && stage !== "unsupported" ? (
        <button
          type="button"
          onClick={onRetry}
          className="flex h-14 items-center justify-center gap-2 rounded-2xl bg-[var(--brand)] font-bold text-white shadow-card transition hover:bg-[var(--brand-strong)] active:scale-[0.98] dark:text-[#06140f]"
        >
          {stage === "ask" ? <LocateFixed className="size-5" /> : <RotateCw className="size-5" />}
          {stage === "ask" ? t("locAllow", lang) : t("tryAgainBtn", lang)}
        </button>
      ) : null}

      <p className="text-center text-sm text-[var(--muted)]">{t("askStaff", lang)}</p>
    </Sheet>
  );
}
