import { QrCode, ScanLine } from "lucide-react";

export default function Home() {
  return (
    <main className="relative grid min-h-dvh place-items-center overflow-hidden px-6">
      <div className="pointer-events-none absolute -top-40 -right-40 size-[28rem] rounded-full bg-[var(--brand-soft)] blur-3xl" aria-hidden />
      <div className="pointer-events-none absolute -bottom-40 -left-40 size-[24rem] rounded-full bg-[var(--accent-soft)] blur-3xl" aria-hidden />
      <div className="anim-fade-up relative flex max-w-md flex-col items-center gap-5 text-center">
        <span className="anim-float relative grid size-24 place-items-center rounded-[28px] bg-[var(--brand)] text-white shadow-float">
          <QrCode className="size-11" strokeWidth={1.8} />
          <ScanLine className="absolute -right-3 -bottom-3 size-10 rounded-2xl bg-[var(--accent)] p-2 text-[#2b1d06]" strokeWidth={2.2} />
        </span>
        <h1 className="text-3xl font-bold sm:text-4xl">Roumdoul Order</h1>
        <p className="text-lg text-[var(--muted)]">
          ស្កេន QR នៅលើតុរបស់អ្នក ដើម្បីមើលម៉ឺនុយ និងកុម្ម៉ង់។
          <br />
          <span className="text-base">Scan the QR code on your table to see the menu and order.</span>
        </p>
      </div>
    </main>
  );
}
