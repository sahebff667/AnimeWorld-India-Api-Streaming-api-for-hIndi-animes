FROM php:8.2-cli

WORKDIR /app

COPY . .

EXPOSE 10000

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} -t ."]
