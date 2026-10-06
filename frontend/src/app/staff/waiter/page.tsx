import WaiterBoard from "@/components/staff/waiter-board";

export const metadata = { title: "Waiter · Roumdoul Order" };

export default async function WaiterPage({ searchParams }: PageProps<"/staff/waiter">) {
  const params = await searchParams;
  return <WaiterBoard branchId={Number(params.branch)} />;
}
