FROM alpine:3.9
LABEL description="Download CardDAV VCards and upload as phonebook to AVM FRITZ!Box"

VOLUME [ "/data" ]

# Install dependencies for carddav2fb
RUN set -xe && \
    apk update && apk upgrade && \
    apk add --no-cache --virtual=run-deps \
    php7-cli php7-curl php7-dom php7-mbstring php7-xml php7-simplexml php7-tokenizer php7-xmlwriter composer && \
    apk del --progress --purge && \
    rm -rf /var/cache/apk/*

# Copy carddav2fb and install php dependencies
COPY . /srv
RUN mkdir -p /data && \
    cd /srv && \
    composer install --no-dev && \
    chmod +x /srv/carddav2fb /srv/docker-entrypoint && \
    ln -s /data/config.php .

ENTRYPOINT [ "/srv/docker-entrypoint" ]
