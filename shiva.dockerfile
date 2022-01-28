#   hermes;x='alptech/yuzu:shiva';docker build --no-cache -t $x -f shiva.dockerfile .;docker push $x;say pushed;
FROM php:7.4-fpm-alpine
RUN apk -U add findutils procps redis bash curl libstdc++ && docker-php-ext-install sockets && docker-php-ext-install pcntl && docker-php-ext-install pdo_mysql
RUN apk add gcc make g++ autoconf linux-headers libevent libevent-dev openssl-dev curl-dev\
    && yes '' | pecl install redis \
    && yes '' | pecl install -D 'enable-sockets="yes" enable-openssl="yes" enable-http2="yes" enable-mysqlnd="yes" enable-swoole-json="yes" enable-swoole-curl="yes" enable-cares="yes"' openswoole \
    && apk del gcc make g++ autoconf linux-headers libevent libevent-dev openssl-dev curl-dev
#--no-cache --virtual .build-deps #FROM 123Mo to 49.27 MB
#RUN yes no | pecl install ev && yes "" | pecl install event
#RUN apk del --no-network .build-deps
#Cannot find autoconf. Please check your autoconf installation and the
#RUN apk add mysql-client nano vim ncdu supervisor
#RUN apk add gcc g++ curl-dev make linux-headers autoconf openssl-dev libevent libevent-dev && yes no | pecl install ev && yes "" | pecl install event && yes "" | pecl install openswoole
#RUN apk add libzmq zeromq-dev
#RUN git clone git://github.com/mkoppanen/php-zmq.git && cd php-zmq && phpize && ./configure && make && make install && cd .. && rm -rf php-zmq
#ENV http_proxy 'http://http-proxy.infomaniak.ch:3128'
#kill -HUP 1
COPY ex/php.ini /usr/local/etc/php/conf.d/zzz.ini
ENV a 3
COPY . /a
#RUN mkdir /a
WORKDIR /a
RUN chmod +x /a/shiva.sh
#/usr/local/etc/php
EXPOSE 80
EXPOSE 2000
ENTRYPOINT ["/bin/bash","/a/shiva.sh"]
CMD []
#CMD ["sh", "-c", "tail -f /dev/null"]
#igbinary for redis ? was 134mo before virtual no cache