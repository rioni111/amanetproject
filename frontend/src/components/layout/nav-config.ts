import { Briefcase, FileText, Inbox, LayoutDashboard, PenLine, Users, type LucideIcon } from "lucide-react";

export interface NavItem {
  title: string;
  href: string;
  icon: LucideIcon;
  description: string;
}

/** Sections that work today. */
export const PRIMARY_NAV: NavItem[] = [
  { title: "Dashboard", href: "/dashboard", icon: LayoutDashboard, description: "Workspace overview" },
  { title: "Matters", href: "/matters", icon: Briefcase, description: "All legal matters" },
  { title: "Clients", href: "/clients", icon: Users, description: "Individuals and organizations" },
];

/**
 * Sections planned for later phases. Rendered as clearly disabled, non-interactive
 * items so the navigation structure is stable without advertising unbuilt features.
 */
export const UPCOMING_NAV: { title: string; icon: LucideIcon; phase: number }[] = [
  { title: "Documents", icon: FileText, phase: 3 },
  { title: "Client requests", icon: Inbox, phase: 4 },
  { title: "Signatures", icon: PenLine, phase: 5 },
];

export function findNavItem(pathname: string): NavItem | undefined {
  return PRIMARY_NAV.find((item) => pathname === item.href || pathname.startsWith(`${item.href}/`));
}
