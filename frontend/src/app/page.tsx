export default function Home() {
  return (
    <main className="mx-auto flex min-h-dvh max-w-md flex-col items-center justify-center gap-3 px-6 text-center">
      <div className="grid size-14 place-items-center rounded-2xl bg-[var(--brand)] text-2xl font-bold text-white">តុ</div>
      <h1 className="text-2xl font-semibold">Roumdoul Order</h1>
      <p className="text-[var(--muted)]">
        ស្កេន QR នៅលើតុរបស់អ្នក ដើម្បីមើលម៉ឺនុយ។
        <br />
        Scan the QR code on your table to see the menu.
      </p>
    </main>
  );
}
