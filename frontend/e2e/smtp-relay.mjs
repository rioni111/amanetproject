// Test-only TCP relay between the E2E API and the isolated E2E Mailpit (Phase 6, scenario F).
//   SMTP   127.0.0.1:RELAY_PORT  → forwards every connection to 127.0.0.1:TARGET_PORT (Mailpit)
//   control 127.0.0.1:CONTROL_PORT: POST /down (new connections are refused), POST /up, GET /health
// "down" destroys incoming connections immediately — a real SMTP connection failure for Laravel, with no
// test code inside the application. Binds to 127.0.0.1 only; never forwards anywhere but the E2E Mailpit.
import http from "node:http";
import net from "node:net";

const RELAY_PORT = Number(process.env.E2E_SMTP_RELAY_PORT ?? 1028);
const CONTROL_PORT = Number(process.env.E2E_SMTP_RELAY_CONTROL_PORT ?? 1029);
const TARGET_PORT = Number(process.env.LEXORA_E2E_MAIL_SMTP_PORT ?? 1027);
let down = false;

net
  .createServer((client) => {
    if (down) {
      client.destroy();
      return;
    }
    const upstream = net.connect(TARGET_PORT, "127.0.0.1");
    client.pipe(upstream).pipe(client);
    const close = () => {
      client.destroy();
      upstream.destroy();
    };
    client.on("error", close);
    upstream.on("error", close);
  })
  .listen(RELAY_PORT, "127.0.0.1");

http
  .createServer((req, res) => {
    if (req.method === "POST" && (req.url === "/down" || req.url === "/up")) down = req.url === "/down";
    res.writeHead(200, { "content-type": "application/json" });
    res.end(JSON.stringify({ down }));
  })
  .listen(CONTROL_PORT, "127.0.0.1");
