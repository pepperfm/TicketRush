FROM alpine:3.23 AS build

ARG PGBOUNCER_VERSION=1.26.0

RUN apk add --no-cache \
    build-base \
    c-ares-dev \
    curl \
    libevent-dev \
    openssl-dev \
    pkgconf

WORKDIR /tmp

RUN curl -fsSL "https://www.pgbouncer.org/downloads/files/${PGBOUNCER_VERSION}/pgbouncer-${PGBOUNCER_VERSION}.tar.gz" -o pgbouncer.tar.gz \
    && tar -xzf pgbouncer.tar.gz \
    && cd "pgbouncer-${PGBOUNCER_VERSION}" \
    && ./configure --prefix=/usr --with-cares \
    && make -j"$(getconf _NPROCESSORS_ONLN)" pgbouncer \
    && mkdir -p /out/usr/bin \
    && cp pgbouncer /out/usr/bin/pgbouncer

FROM alpine:3.23

RUN apk add --no-cache c-ares libevent libssl3 postgresql-client \
    && addgroup -S pgbouncer \
    && adduser -S -G pgbouncer pgbouncer \
    && mkdir -p /etc/pgbouncer \
    && chown -R pgbouncer:pgbouncer /etc/pgbouncer

COPY --from=build /out/usr/bin/pgbouncer /usr/bin/pgbouncer
COPY pgbouncer.ini userlist.txt /etc/pgbouncer/

USER pgbouncer

EXPOSE 6432

CMD ["/usr/bin/pgbouncer", "/etc/pgbouncer/pgbouncer.ini"]
