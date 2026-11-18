# Lexora Workspace — Next.js frontend

Next.js 16 (App Router) + TypeScript + Tailwind CSS 4. Talks to the Laravel API with session cookies.
Setup and testing instructions: see the [root README](../README.md).

```bash
npm install && cp .env.example .env.local
npm run dev            # http://localhost:3000
npm run lint && npm run typecheck && npm run build
npm run test:e2e       # Playwright: starts its own API/web servers; needs the amanet_e2e database and Docker (mailpit-e2e)
```
