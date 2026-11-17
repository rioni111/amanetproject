// Copies the PDF.js worker and standard font data from node_modules into public/pdfjs, so the viewer
// loads them from the app's own origin (CSP 'self') and always matches the installed pdfjs-dist version.
// Runs on `npm install` / `npm ci` (postinstall); the output is git-ignored.
import { cpSync, existsSync, mkdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const source = join(root, "node_modules", "pdfjs-dist");
const target = join(root, "public", "pdfjs");

if (!existsSync(source)) {
  console.warn("pdfjs-dist is not installed; skipping PDF viewer assets.");
  process.exit(0);
}
mkdirSync(target, { recursive: true });
cpSync(join(source, "build", "pdf.worker.min.mjs"), join(target, "pdf.worker.min.mjs"));
cpSync(join(source, "standard_fonts"), join(target, "standard_fonts"), { recursive: true });
console.log("PDF.js assets copied to public/pdfjs");