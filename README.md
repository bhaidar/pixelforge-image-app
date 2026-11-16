# Pixelforge image app

Laravel 12 + Vue 3 + Inertia (official Vue starter kit). Users generate images through the Pixelforge API and are charged provider cost plus 25%.

## Run (Podman)

```bash
podman machine start
export DOCKER_HOST=$(podman machine inspect --format '{{.ConnectionInfo.PodmanSocket.Path}}' | sed 's#^#unix://#')
docker compose up -d          # app on :8000, plus queue worker and scheduler
docker compose exec laravel.test php artisan migrate
```

Frontend assets are built on the host: `npm install && npm run build` (or `npm run dev`).

## `.env` keys to fill in

| Key | Value |
| --- | --- |
| `PIXELFORGE_API_KEY` | API key from the Pixelforge dashboard |
| `PIXELFORGE_WEBHOOK_SECRET` | The `whsec_...` signing secret of that same key |
| `ADMIN_EMAILS` | Comma separated emails that become admins when they register (or set `users.is_admin` with tinker) |

Expose port 8000 with a tunnel (Expose, ngrok, Cloudflare Tunnel) and set the key's webhook URL in the Pixelforge dashboard to `https://<tunnel>/webhooks/pixelforge`.

## How money works

- All money is integer nanodollars. Provider cost = sum(tokens x rate), using the rates on the generation itself. Charge = cost x 1.25, rounded half up to a nanodollar.
- Submitting holds the maximum estimated charge. When the generation finishes, the hold is released and the exact charge is posted. Failed generations release the hold and cost nothing.
- The ledger is append-only and `(generation_id, type)` is unique, so a generation is never charged twice.
- Webhooks are verified (HMAC, 5 minute tolerance), deduplicated by event id, and applied by status rank, so duplicate and out-of-order events are safe. `generations:sync` runs every minute to poll anything whose webhook never arrived.

## Tests

```bash
docker compose exec laravel.test php artisan test
```
