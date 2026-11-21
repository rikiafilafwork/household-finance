# Web SPA → image nginx yang juga mem-proxy /api, /sanctum, /up ke php-fpm (satu origin).
#
#   docker build -f docker/web.Dockerfile -t household-finance-web .
#
# Dev tidak memakai image ini: nginx di docker-compose me-mount apps/web/dist (hasil `make web`).

FROM node:24-alpine AS build
WORKDIR /repo
RUN corepack enable

# Manifest dulu supaya layer dependency ter-cache selama lockfile tidak berubah.
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml .npmrc ./
COPY apps/web/package.json apps/web/
COPY packages/shared/package.json packages/shared/
RUN pnpm install --frozen-lockfile --filter @household-finance/web...

COPY packages/shared packages/shared
COPY apps/web apps/web
RUN pnpm --filter @household-finance/web build

FROM nginx:1.27-alpine
COPY docker/nginx/conf.d/default.conf /etc/nginx/conf.d/default.conf
COPY --from=build /repo/apps/web/dist /var/www/web
