FROM node:22-bookworm-slim AS backoffice-build

WORKDIR /app
COPY backoffice/package.json backoffice/package-lock.json ./
RUN npm ci
COPY backoffice/ ./
RUN npm run build

FROM nginx:1.27-alpine

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/laravel-php.conf /etc/nginx/snippets/laravel-php.conf
COPY --from=backoffice-build /app/dist /var/www/backoffice/dist
