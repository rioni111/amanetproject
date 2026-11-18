# Delivery Checklist — Lexora Workspace (Amanet assignment)

Status at the end of Phase 7. "✅" means done and verified (evidence linked); "⚠️" means done, with a stated limitation;
"⏳" means waiting for the owner's decision.

| # | Item | Status | Evidence / note |
| --- | --- | --- | --- |
| 1 | Private repository available to the authorised reviewer | ⏳ | `github.com/rioni111/amanetproject` is **private**; the owner grants reviewer access on GitHub. Visibility was not changed |
| 2 | Correct branch identified | ⏳ | Phases 1–6 are on `main` (`e770e27`). Phase 7 is on `feature/phase-7-final-qa-delivery`, **not merged, not pushed**, waiting for authorisation |
| 3 | Source code complete | ✅ | backend (Laravel 12), frontend (Next.js 16), scripts, Docker Compose |
| 4 | Installation documented | ✅ | [README](../README.md) (Windows one-click and manual), [DEPLOYMENT_GUIDE](DEPLOYMENT_GUIDE.md) |
| 5 | Environment templates provided | ✅ | `backend/.env.example`, `frontend/.env.example` (no operational secrets) |
| 6 | Demo account documented | ✅ | README "Demo login" (development only; seeders refuse production) |
| 7 | Sample data available | ✅ | `php artisan db:seed`: clients, matters, tasks, notes, documents with versions, requests in every state, signatures ([FINAL_QA_REPORT §9](FINAL_QA_REPORT.md#9-demo-data)) |
| 8 | Demo walkthrough | ✅ | [DEMO_WALKTHROUGH](DEMO_WALKTHROUGH.md) |
| 9 | All required features implemented | ✅ | requirement mapping in [PHASE_7_FINAL_IMPLEMENTATION_REPORT §2](PHASE_7_FINAL_IMPLEMENTATION_REPORT.md#2-original-amanet-assignment-mapping) |
| 10 | Backend tests passing | ✅ | 253 passed (3,698 assertions) |
| 11 | E2E tests passing | ✅ | 66 passed (Chromium desktop + Pixel 7, 18.5 min), plus Firefox/WebKit opt-in 2/2 |
| 12 | Windows verification | ✅ | 14/14, ALL STEPS PASSED (`verify-20261117-200331.log`) |
| 13 | Security audit completed within its stated scope | ✅ | [SECURITY_AUDIT](SECURITY_AUDIT.md) (self-review, not an independent penetration test) |
| 14 | Deployment instructions | ✅ | [DEPLOYMENT_GUIDE](DEPLOYMENT_GUIDE.md) (not exercised on a real server) |
| 15 | Known limitations documented | ✅ | [final report §26](PHASE_7_FINAL_IMPLEMENTATION_REPORT.md#26-known-limitations), README |
| 16 | No secrets committed | ✅ | tracked-file scan ([SECURITY_AUDIT §11](SECURITY_AUDIT.md#11-secrets-and-configuration)) |
| 17 | No private client files committed | ✅ | no PDFs, storage files or logs tracked; all data fictional |
| 18 | Final documentation complete | ✅ | the documentation index in the README |
| 19 | Working tree clean, commits created | ✅ | [final report §25](PHASE_7_FINAL_IMPLEMENTATION_REPORT.md#25-git-state) |

## Optional: short project overview for a submission e-mail

> Not sent anywhere. Copy it if useful.

---

**Lexora Workspace — Amanet Full Stack Engineer assignment**

Lexora Workspace is a legal matter and document workspace (Laravel 12 API, Next.js 16, PostgreSQL 16). It covers:
- administrator sign-in;
- clients, matters, tasks and internal notes, with search and filters;
- a private PDF library with immutable versions;
- secure client document requests: account-free uploads, review, approval and corrections;
- in-house electronic PDF signing by the administrator or by a client through a secure link, with one signer per PDF,
  real progress tracking and an evidence record;
- e-mail delivery over SMTP with honest delivery states;
- scheduled reminders, in-app notifications and a complete audit trail.

**Quality.** The final verification ran the PHPUnit and Playwright suites. They include real SMTP, multi-process race
tests, accessibility checks (axe-core) and cross-phase user journeys. Final results: 253 passed (3,698 assertions),
66 passed (Chromium desktop + Pixel 7, 18.5 min).

**Getting started.** The private repository includes a Windows one-click setup, fictional demo data, a 20–30 minute
demo walkthrough, a deployment guide and a security audit.

**Limitations (stated honestly).**
- The signatures are application-level electronic signatures, not qualified or certificate-based.
- E-mail status ends at acceptance by the mail server.
- Production deployment has been prepared but not exercised.

Start with `README.md` → "Reviewer quick start".

---
