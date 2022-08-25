#   cd $bf/Shiva;x='alptech/yuzu:shiva';docker build -t $x -f shiva.dockerfile .;docker push $x;say pushed;
FROM php:7.4-fpm-alpine
RUN apk -U add findutils procps redis bash curl libstdc++ && apk add gcc make g++ autoconf linux-headers libevent libevent-dev openssl-dev curl-dev ${PHPIZE_DEPS} \
    && docker-php-ext-install sockets && docker-php-ext-install pcntl && docker-php-ext-install pdo_mysql\
    && yes '' | pecl install redis \
    && yes '' | pecl install -D 'enable-sockets="yes" enable-openssl="yes" enable-http2="yes" enable-mysqlnd="yes" enable-swoole-json="yes" enable-swoole-curl="yes" enable-cares="yes"' openswoole \
    && curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/bin/composer && pecl install mongodb \
    && apk del gcc make g++ autoconf linux-headers libevent libevent-dev openssl-dev curl-dev ${PHPIZE_DEPS}
#now this runs php

RUN mkdir /a && cd /a
# RUN yes | composer require mongodb/mongodb

COPY ex/os411.ini /usr/local/etc/php/conf.d/zzz.ini
COPY demo/common.php /a/common.php
COPY demo/stressTest.php /a/stressTest.php
COPY demo/websocket-server.php /a/websocket-server.php
COPY demo/stressTest.sh /a/stressTest.sh
WORKDIR /a
RUN chmod +x /a/stressTest.sh
#/usr/local/etc/php
EXPOSE 80
#EXPOSE 2000
ENTRYPOINT ["/bin/bash","/a/stressTest.sh"]
CMD []