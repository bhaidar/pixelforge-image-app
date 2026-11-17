# Pixelforge image app

Laravel 12 + Vue 3 + Inertia (official Vue starter kit). Users generate images through the Pixelforge API and are charged provider cost plus 25%.

## Run with Docker or Podman

Setup (both runtimes): `cp .env.example .env`, set `APP_PORT=8000`, `WWWUSER` and `WWWGROUP` (e.g. `id -u` / `id -g`), and fill in the keys below.

**Docker**

```bash
docker compose up -d          # app on :8000, plus queue worker and scheduler
docker compose exec laravel.test php artisan key:generate
docker compose exec laravel.test php artisan migrate
```

**Podman**

```bash
podman machine start
export DOCKER_HOST=$(podman machine inspect --format '{{.ConnectionInfo.PodmanSocket.Path}}' | sed 's#^#unix://#')
docker compose up -d          # or: podman compose up -d
docker compose exec laravel.test php artisan key:generate
docker compose exec laravel.test php artisan migrate
```

Frontend assets are built on the host: `npm install && npm run build`.

## Public URL with Cloudflare Tunnel (for webhooks)

Pixelforge must reach `/webhooks/pixelforge` over the internet, so expose the app with a tunnel.

1. **Run `npm run build` first.** Through a tunnel the browser cannot reach the Vite dev server (`npm run dev`, port 5173), so the page would load without CSS/JS. Built assets in `public/build` are served by the app itself. Re-run `npm run build` after any frontend change.
2. Install `cloudflared` (`brew install cloudflared`) and start a quick tunnel:

   ```bash
   cloudflared tunnel --url http://localhost:8000
   ```

3. Copy the printed `https://<random>.trycloudflare.com` URL.
4. Set `APP_URL` in `.env` to that URL and restart (`docker compose restart`). Proxies are trusted, so HTTPS URLs are generated correctly.
5. In the Pixelforge dashboard set the key's webhook URL to `https://<random>.trycloudflare.com/webhooks/pixelforge`.

Quick tunnel URLs change every time `cloudflared` restarts; update `APP_URL` and the webhook URL when that happens. Use a named tunnel for a stable hostname.

## `.env` keys to fill in

| Key | Value |
| --- | --- |
| `PIXELFORGE_API_KEY` | API key from the Pixelforge dashboard |
| `PIXELFORGE_WEBHOOK_SECRET` | The `whsec_...` signing secret of that same key |
| `ADMIN_EMAILS` | Comma separated emails that become admins when they register (or set `users.is_admin` with tinker) |

Webhook URL: `https://<tunnel>/webhooks/pixelforge` (see the Cloudflare Tunnel section above; ngrok or Expose also work).

## How money works

- All money is integer nanodollars. Provider cost = sum(tokens x rate), using the rates on the generation itself. Charge = cost x 1.25, rounded half up to a nanodollar.
- Submitting holds the maximum estimated charge. When the generation finishes, the hold is released and the exact charge is posted. Failed generations release the hold and cost nothing.
- The ledger is append-only and `(generation_id, type)` is unique, so a generation is never charged twice.
- Webhooks are verified (HMAC, 5 minute tolerance), deduplicated by event id, and applied by status rank, so duplicate and out-of-order events are safe. `generations:sync` runs every minute to poll anything whose webhook never arrived.

## Tests

```bash
docker compose exec laravel.test php artisan test
```
