FROM php:8.3-apache

# Install cron, python3, requests, enable mod_rewrite
RUN apt-get update && apt-get install -y \
    cron \
    curl \
    unzip \
    python3 \
    python3-requests \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers \
    && mkdir -p /var/www/html/images && chown www-data:www-data /var/www/html/images \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Copy app files
COPY index.php workspace.php workspace.css workspace.js /var/www/html/
COPY .htaccess /var/www/html/.htaccess
COPY cors.conf /etc/apache2/conf-available/cors.conf
RUN a2enconf cors
COPY sync.py /usr/local/bin/sync.py
COPY watch.py /usr/local/bin/watch.py
COPY sync-worker.py /usr/local/bin/sync-worker.py
RUN chmod +x /usr/local/bin/sync.py /usr/local/bin/watch.py

COPY webhook.php /var/www/html/webhook.php

# Copy entrypoint
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
