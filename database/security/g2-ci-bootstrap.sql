-- Ephemeral CI-only roles and database. Never use these credentials locally or in production.
CREATE ROLE dawineks_owner
    NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;

CREATE ROLE dawineks_runtime
    LOGIN PASSWORD 'g2-ci-runtime-only'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;

CREATE ROLE dawineks_migrator
    LOGIN NOINHERIT PASSWORD 'g2-ci-migrator-only'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;

GRANT dawineks_owner TO dawineks_migrator;

CREATE DATABASE dawineks_hospitality_os
    WITH OWNER dawineks_owner ENCODING 'UTF8' TEMPLATE template0;

REVOKE ALL PRIVILEGES ON DATABASE dawineks_hospitality_os FROM PUBLIC;
GRANT CONNECT ON DATABASE dawineks_hospitality_os TO dawineks_runtime;
GRANT CONNECT ON DATABASE dawineks_hospitality_os TO dawineks_migrator;
