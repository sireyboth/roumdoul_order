import PrintTicket from "@/components/staff/print-ticket";

export const metadata = { title: "Ticket · Roumdoul Order" };

export default async function TicketPage({ searchParams }: PageProps<"/staff/print/ticket">) {
  const params = await searchParams;
  const station = params.station === "kitchen" || params.station === "bar" ? params.station : null;

  return <PrintTicket orderId={Number(params.order)} station={station} auto={params.auto === "1"} />;
}
