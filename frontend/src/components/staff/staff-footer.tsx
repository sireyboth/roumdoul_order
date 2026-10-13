"use client";

/* The Roumdoul footer under every staff screen, except the 80 mm print pages. */

import { usePathname } from "next/navigation";
import SiteFooter from "../site-footer";

export default function StaffFooter() {
  const pathname = usePathname();
  if (pathname.startsWith("/staff/print")) return null;

  // The new-order cart bar is fixed to the bottom on phones: keep it from covering the footer.
  const underCartBar = pathname === "/staff/waiter/order" ? "pb-36 lg:pb-0" : "";

  return <SiteFooter className={`no-print ${underCartBar}`} />;
}
