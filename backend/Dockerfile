FROM php:8.5-cli
RUN docker-php-ext-install pdo_mysql bcmath
# Fotos y PDF de documentos recibidos: hasta 10 MB por archivo.
RUN printf 'upload_max_filesize=10M\npost_max_size=11M\nmax_file_uploads=1\n' > /usr/local/etc/php/conf.d/myposlite.ini
WORKDIR /app
COPY backend/ ./backend/
COPY database/ ./database/
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "backend/public"]
