import { Bell, Briefcase, FileText, Inbox, LayoutDashboard, PenLine, Users, type LucideIcon } from "lucide-react";

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
  { title: "Documents", href: "/documents", icon: FileText, description: "PDF library and versions" },
  { title: "Client requests", href: "/document-requests", icon: Inbox, description: "Documents requested from clients" },
  { title: "Signatures", href: "/signatures", icon: PenLine, description: "Signing requests and signed PDFs" },
];

/**
 * Sections planned for later phases. Rendered as clearly disabled, non-interactive
 * items so the navigation structure is stable without advertising unbuilt features.
 */
export const UPCOMING_NAV: { title: string; icon: LucideIcon; phase: number }[] = [];

/** Pages reached from the header (not the sidebar) that still need a breadcrumb title. */
export const HEADER_NAV: NavItem[] = [{ title: "Notifications", href: "/notifications", icon: Bell, description: "Your workspace notifications" }];

export function findNavItem(pathname: string): NavItem | undefined {
  return [...PRIMARY_NAV, ...HEADER_NAV].find((item) => pathname === item.href || pathname.startsWith(`${item.href}/`));
}
