#   cd $bf/Shiva;x='alptech/yuzu:shiva8';docker build -t $x -f shiva8.dockerfile .;docker push $x;say pushed;
FROM php:8.0-fpm-alpine
#FROM php:8.2-fpm-alpine
RUN apk -U add htop jq c-ares findutils procps redis bash curl libstdc++ && apk add gcc make g++ autoconf linux-headers libevent libevent-dev openssl-dev curl-dev c-ares c-ares-dev ${PHPIZE_DEPS} \
    && docker-php-ext-install sockets \
    #&& yes '' | pecl install -D 'enable-sockets="yes" enable-openssl="yes" enable-http2="yes" enable-mysqlnd="yes" enable-swoole-json="yes" enable-swoole-curl="yes" enable-cares="yes"' swoole \
    && docker-php-source extract && \
        mkdir /usr/src/php/ext/swoole && \
        curl -sfL https://github.com/swoole/swoole-src/archive/v5.0.0.tar.gz -o swoole.tar.gz && \
        tar xfz swoole.tar.gz --strip-components=1 -C /usr/src/php/ext/swoole && \
        # --enable-http2--enable-swoole-json
        docker-php-ext-configure swoole --enable-mysqlnd --enable-openssl --enable-sockets --enable-swoole-curl --enable-cares && \
        docker-php-ext-install -j$(nproc) swoole && \
        rm -f swoole.tar.gz $HOME/.composer/*-old.phar && \
        docker-php-source delete \

    && docker-php-ext-install pcntl && docker-php-ext-install pdo_mysql\
    && yes '' | pecl install redis \
    # not openswoole anymore
    && curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/bin/composer && pecl install mongodb \
    && apk del gcc make g++ autoconf linux-headers libevent libevent-dev openssl-dev curl-dev c-ares-dev ${PHPIZE_DEPS}

RUN mkdir /a && cd /a
# RUN yes | composer require mongodb/mongodb

COPY ex/php.ini /usr/local/etc/php/conf.d/zzz.ini
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
#CMD ["sh", "-c", "tail -f /dev/null"]
#igbinary for redis ? was 134mo before virtual no cache