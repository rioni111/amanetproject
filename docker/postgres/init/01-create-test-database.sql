-- Separate databases for the automated tests, so they never touch the development database (amanet):
--   amanet_test  PHPUnit (backend/phpunit.xml)
--   amanet_e2e   Playwright end-to-end suite (frontend/e2e/env.ts; reset on every run)
-- Runs only when the postgres-data volume is created for the first time.
CREATE DATABASE amanet_test OWNER amanet;
CREATE DATABASE amanet_e2e OWNER amanet;
