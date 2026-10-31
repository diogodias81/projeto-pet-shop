FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y libsqlite3-dev libpng-dev libjpeg62-turbo-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo_sqlite gd \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers

# Pasta de fotos enviadas: nunca executa PHP e não deixa o navegador "adivinhar" o tipo do arquivo
RUN printf '%s\n' \
    '<Directory ${APACHE_DOCUMENT_ROOT}/uploads>' \
    '    php_admin_flag engine off' \
    '    Options -Indexes -ExecCGI' \
    '    <FilesMatch "\.(?i:php\d?|phtml|phar|pht)$">' \
    '        Require all denied' \
    '    </FilesMatch>' \
    '    Header set X-Content-Type-Options nosniff' \
    '</Directory>' \
    > /etc/apache2/conf-available/uploads.conf \
    && a2enconf uploads

WORKDIR /var/www/html

COPY . /var/www/html

# O Apache (www-data) precisa gravar o banco e as fotos dos produtos
RUN mkdir -p storage public/uploads/produtos \
    && chown -R www-data:www-data storage public/uploads

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

# Mesmo fuso do PHP (app/bootstrap.php): o "localtime" do SQLite usa o fuso do sistema, e em UTC
# um lote que vence hoje já contaria como vencido depois das 21h
ENV TZ=America/Belem

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/000-default.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

EXPOSE 80