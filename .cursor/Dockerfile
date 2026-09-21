# Base image for the Home of Creativity backend Cloud Agent environment.
# Provides the stable system toolchain (PHP 8.3, Composer, Node.js 22, Python 3)
# so the repository install script only needs to fetch project dependencies.
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl git unzip gnupg \
        php8.3-cli php8.3-mbstring php8.3-xml php8.3-curl php8.3-sqlite3 \
        php8.3-mysql php8.3-bcmath php8.3-gd php8.3-zip php8.3-intl php8.3-gmp \
        python3 python3-venv python3-pip \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Cloud Agent commands run as the `ubuntu` user, which already exists in this base image.
USER ubuntu
