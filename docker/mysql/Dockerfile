FROM mysql:8.0

ENV LANG=C.UTF-8

# Copy initialization scripts (only ever run against an empty data directory)
COPY init/ /docker-entrypoint-initdb.d/

CMD ["mysqld", "--default-authentication-plugin=mysql_native_password", "--sql-require-primary-key=OFF"]
