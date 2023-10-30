#cd $bf/Shiva;x='alptech/yuzu:shiva2';docker build -t $x -f shiva2.dockerfile .;docker push $x;say pushed;
FROM openswoole/swoole:4.12.1-php8.2
RUN mkdir /a
COPY common.php /a/common.php
COPY server.php /a/server.php
COPY server.sh /a/server.sh
WORKDIR /a
#/usr/local/etc/php
#EXPOSE 80
EXPOSE 2000
#ENTRYPOINT ["php ","/a/server.php"]
RUN chmod +x /a/server.sh
ENTRYPOINT ["/bin/bash","/a/server.sh"]
CMD []
