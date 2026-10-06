import KitchenBoard from "@/components/staff/kitchen-board";

export const metadata = { title: "Kitchen · Roumdoul Order" };

export default async function KitchenPage({ searchParams }: PageProps<"/staff/kitchen">) {
  const params = await searchParams;
  const branchId = Number(params.branch);
  const station = params.station === "kitchen" || params.station === "bar" ? params.station : null;

  return <KitchenBoard key={`${branchId}-${station}`} branchId={branchId} station={station} />;
}
