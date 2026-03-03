# Cloud Run-ready PHP (Apache) image
FROM php:8.2-apache

# Install PostgreSQL PDO driver
RUN apt-get update \
  && apt-get install -y --no-install-recommends libpq-dev \
  && docker-php-ext-install pdo_pgsql \
  && rm -rf /var/lib/apt/lists/*

# Cloud Run expects the container to listen on $PORT (default 8080)
ENV PORT=8080
RUN sed -i 's/Listen 80/Listen 8080/g' /etc/apache2/ports.conf \
  && sed -i 's/:80/:8080/g' /etc/apache2/sites-available/000-default.conf

# Copy app
COPY . /var/www/html/

EXPOSE 8080
