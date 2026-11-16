import { GeistMono } from "geist/font/mono";
import { GeistSans } from "geist/font/sans";
import { GeistSerif } from "geist/font/serif";
import type { Metadata, Viewport } from "next";

import "./globals.css";
import { Providers } from "./providers";

export const metadata: Metadata = {
  title: { default: "Lexora Workspace", template: "%s · Lexora Workspace" },
  description: "Legal matter workspace for managing matters, clients, documents and signatures.",
  robots: { index: false, follow: false },
};

export const viewport: Viewport = {
  themeColor: "#FAFAF8",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en" className={`${GeistSans.variable} ${GeistMono.variable} ${GeistSerif.variable} h-full antialiased`}>
      <body className="min-h-full">
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
