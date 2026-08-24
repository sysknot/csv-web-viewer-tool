FROM php:8.3-apache

ARG IMAGE_VERSION=dev
ARG VCS_REF=unknown
LABEL org.opencontainers.image.title="CSV Web Viewer" \
      org.opencontainers.image.description="Visor seguro de fotografías CSV con PHP y SQLite" \
      org.opencontainers.image.version="${IMAGE_VERSION}" \
      org.opencontainers.image.revision="${VCS_REF}"

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_sqlite \
    && a2enmod rewrite headers \
    && mkdir -p /var/www/storage/database /var/www/storage/uploads /var/www/storage/backups /var/www/storage/logs \
    && chown -R www-data:www-data /var/www/storage

WORKDIR /var/www
COPY --chown=www-data:www-data . /var/www

COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini

RUN chown -R www-data:www-data /var/www/storage

RUN chmod 755 /var/www/docker/entrypoint.sh

ENV APACHE_DOCUMENT_ROOT=/var/www/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# The entrypoint prepares the persistent SQLite volume before Apache starts.
# Keep the container process as root until Apache drops privileges to www-data.
USER root

EXPOSE 80
ENTRYPOINT ["/var/www/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
