# Deployment

## SSH alias

This machine can connect to the AI server with:

```bash
ssh lda-ai
```

The local SSH config entry points `lda-ai` to `172.16.70.20`.

## CI

GitHub Actions CI is defined in `.github/workflows/ci.yml`.

It runs:

```bash
composer install --no-interaction --prefer-dist
npm ci
php artisan test
npm run build
```

## CD

GitHub Actions CD is defined in `.github/workflows/cd.yml`.

Because `172.16.70.20` is a private IP, CD must run on a `self-hosted` GitHub runner that can already reach that network.

Required GitHub secrets:

- `LDA_AI_SSH_KEY`
- `LDA_AI_USER`
- `LDA_AI_PATH`

The deploy job:

1. Builds the project.
2. Uploads the release to `172.16.70.20`.
3. Runs Composer install on the server.
4. Runs migrations.
5. Caches Laravel config, routes, and views.
6. Restarts the queue worker.

## Server requirements

- PHP 8.2+
- Composer
- Node.js 22+
- MySQL reachable from Laravel
- Python environment with:

```bash
pip install -r requirements.txt
```

- DWG conversion tool:

```bash
which dwg2dxf
```

or set `LIBREDWG_DWG2DXF` / `ODA_CONVERTER` in `.env`.
