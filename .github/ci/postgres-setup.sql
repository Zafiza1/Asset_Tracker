-- CI database setup: the five roles and the test database from README §1 "Database".
-- Run as the PostgreSQL superuser. Passwords are CI-only throwaway values.

CREATE ROLE ate_owner   LOGIN PASSWORD 'ci_owner';
CREATE ROLE ate_app     LOGIN PASSWORD 'ci_app';
CREATE ROLE ate_session LOGIN PASSWORD 'ci_session';
CREATE ROLE ate_queue   LOGIN PASSWORD 'ci_queue';
CREATE ROLE ate_ctx NOLOGIN BYPASSRLS;
GRANT pg_read_all_stats TO ate_ctx;
GRANT ate_ctx TO ate_owner WITH INHERIT FALSE, SET TRUE;

CREATE DATABASE asset_tracker_v1_test OWNER ate_owner;

\connect asset_tracker_v1_test

REVOKE CREATE ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO ate_owner;
GRANT USAGE ON SCHEMA public TO ate_app;
CREATE EXTENSION IF NOT EXISTS citext;
ALTER DEFAULT PRIVILEGES FOR ROLE ate_owner IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO ate_app;
