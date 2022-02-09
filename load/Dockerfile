FROM php:8.0-cli
RUN docker-php-ext-install mysqli
COPY ./load_sql.php /
COPY ./load_json.php /
COPY ./vendor/ /vendor/
COPY ./composer.lock /
COPY ./composer.json /
WORKDIR /
CMD [ "php", "load_json.php", "1000", "16", "1000000" ]
 
