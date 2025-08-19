# multiflexi-executor

FROM php:8.2-cli
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
 RUN chmod +x /usr/local/bin/install-php-extensions && install-php-extensions gettext intl zip
COPY src /usr/src/multiflexi-executor/src
RUN sed -i -e 's/..\/.env//' /usr/src/multiflexi-executor/src/*.php
COPY composer.json /usr/src/multiflexi-executor
WORKDIR /usr/src/multiflexi-executor
RUN curl -s https://getcomposer.org/installer | php
RUN ./composer.phar install
WORKDIR /usr/src/multiflexi-executor/src
CMD [ "php", "./daemon.php" ]
