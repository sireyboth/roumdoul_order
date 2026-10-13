import StaffFooter from "@/components/staff/staff-footer";

export default function StaffLayout({ children }: LayoutProps<"/staff">) {
  return (
    <>
      {children}
      <StaffFooter />
    </>
  );
}
