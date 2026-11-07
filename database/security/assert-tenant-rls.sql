DO $$
DECLARE
    unprotected_tables text;
BEGIN
    SELECT string_agg(format('%I.%I', table_schema.nspname, table_class.relname), ', ')
    INTO unprotected_tables
    FROM pg_class AS table_class
    JOIN pg_namespace AS table_schema ON table_schema.oid = table_class.relnamespace
    WHERE table_schema.nspname = 'tenant'
      AND table_class.relkind IN ('r', 'p')
      AND (
          NOT table_class.relrowsecurity
          OR NOT table_class.relforcerowsecurity
          OR NOT EXISTS (
              SELECT 1
              FROM pg_policies AS policies
              WHERE policies.schemaname = table_schema.nspname
                AND policies.tablename = table_class.relname
                AND policies.qual IS NOT NULL
                AND policies.with_check IS NOT NULL
          )
      );

    IF unprotected_tables IS NOT NULL THEN
        RAISE EXCEPTION 'Tenant tables missing forced RLS/default-deny policy coverage: %', unprotected_tables;
    END IF;
END;
$$;
