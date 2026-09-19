# Use the official PHP image as a parent image
FROM php:8.2-fpm

ARG UID=33
ARG GID=33
# Set the working directory
WORKDIR /var/www/html

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libicu-dev \
    libonig-dev \
    libzip-dev \
    zip \
    curl \
    libpng-dev   # Add libpng-dev for libpng dependency

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd intl opcache zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN usermod -u $UID www-data && groupmod -g $GID www-data

USER www-data

# Copy existing application directory contents
COPY . /var/www/html

# Set permissions explicitly
#RUN chown -R www-data:www-data /var/www/html && \
#    chmod -R 755 /var/www/html && \
#    chmod 644 /var/www/html/composer.json  # Set permissions for composer.json

# Change current user to www-data

# Expose port 9000 and start php-fpm server
EXPOSE 9000
CMD ["php-fpm"]