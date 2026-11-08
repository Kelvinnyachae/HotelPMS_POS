DO $$
DECLARE
    unprotected_tables text;
BEGIN
    SELECT string_agg(format('%I.%I', table_schema.nspname, table_class.relname), ', ')
    INTO unprotected_tables
    FROM pg_class AS table_class
    JOIN pg_namespace AS table_schema ON table_schema.oid = table_class.relnamespace
    WHERE table_schema.nspname = 'identity'
      AND table_class.relkind IN ('r', 'p')
      AND (
          table_class.relowner <> 'dawineks_owner'::regrole
          OR NOT table_class.relrowsecurity
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
        RAISE EXCEPTION 'Identity tables lack owner separation or forced RLS policies: %', unprotected_tables;
    END IF;

    IF has_table_privilege('dawineks_runtime', 'identity.users', 'SELECT') THEN
        RAISE EXCEPTION 'The runtime role must not directly read identity.users';
    END IF;

    IF NOT has_function_privilege('dawineks_runtime', 'security.authenticate_client_user(text, text)', 'EXECUTE')
       OR NOT has_function_privilege('dawineks_runtime', 'security.load_client_user(uuid)', 'EXECUTE')
       OR NOT has_function_privilege('dawineks_runtime', 'security.save_client_mfa(uuid, text, jsonb)', 'EXECUTE')
       OR NOT has_function_privilege('dawineks_runtime', 'security.consume_client_mfa_recovery_code(uuid, text)', 'EXECUTE')
       OR NOT has_function_privilege('dawineks_runtime', 'security.issue_client_password_reset(text)', 'EXECUTE')
       OR NOT has_function_privilege('dawineks_runtime', 'security.complete_client_password_reset(text, text, text)', 'EXECUTE') THEN
        RAISE EXCEPTION 'Runtime authentication functions are not executable';
    END IF;

    IF NOT (SELECT procedure.prosecdef
            FROM pg_proc AS procedure
            WHERE procedure.oid = 'security.authenticate_client_user(text, text)'::regprocedure)
       OR NOT (SELECT procedure.prosecdef
               FROM pg_proc AS procedure
               WHERE procedure.oid = 'security.load_client_user(uuid)'::regprocedure)
       OR NOT (SELECT procedure.prosecdef
               FROM pg_proc AS procedure
               WHERE procedure.oid = 'security.issue_client_password_reset(text)'::regprocedure)
       OR NOT (SELECT procedure.prosecdef
               FROM pg_proc AS procedure
               WHERE procedure.oid = 'security.complete_client_password_reset(text, text, text)'::regprocedure) THEN
        RAISE EXCEPTION 'Authentication functions must use SECURITY DEFINER';
    END IF;

    IF position('password' IN pg_get_function_result('security.authenticate_client_user(text, text)'::regprocedure)) > 0 THEN
        RAISE EXCEPTION 'The authentication function must not return password material';
    END IF;

    IF position('mfa_secret_ciphertext' IN pg_get_function_result('security.authenticate_client_user(text, text)'::regprocedure)) = 0
       OR position('mfa_recovery_code_hashes' IN pg_get_function_result('security.authenticate_client_user(text, text)'::regprocedure)) > 0 THEN
        RAISE EXCEPTION 'The sign-in function may return the encrypted MFA secret but not recovery hashes';
    END IF;

    IF has_table_privilege('dawineks_runtime', 'security.context_keys', 'SELECT') THEN
        RAISE EXCEPTION 'The runtime role must not read identity signing key material';
    END IF;

    IF has_table_privilege('dawineks_runtime', 'security.client_password_reset_tokens', 'SELECT')
       OR has_table_privilege('dawineks_runtime', 'security.client_password_reset_tokens', 'INSERT')
       OR has_table_privilege('dawineks_runtime', 'security.client_password_reset_tokens', 'UPDATE')
       OR has_table_privilege('dawineks_runtime', 'security.client_password_reset_tokens', 'DELETE') THEN
        RAISE EXCEPTION 'The runtime role must use security functions for password resets';
    END IF;

    IF NOT (SELECT table_class.relowner = 'dawineks_owner'::regrole
                       AND table_class.relrowsecurity
                       AND table_class.relforcerowsecurity
            FROM pg_class AS table_class
            JOIN pg_namespace AS table_schema ON table_schema.oid = table_class.relnamespace
            WHERE table_schema.nspname = 'security'
              AND table_class.relname = 'client_password_reset_tokens') THEN
        RAISE EXCEPTION 'Password reset token storage must be owner-separated and protected by forced RLS';
    END IF;

    IF pg_get_function_result('security.authenticate_client_user(text, text)'::regprocedure) NOT LIKE '%auth_version integer%'
       OR pg_get_function_result('security.load_client_user(uuid)'::regprocedure) NOT LIKE '%auth_version integer%' THEN
        RAISE EXCEPTION 'Authentication functions must expose the session version for stale-session revocation';
    END IF;

    IF NOT has_table_privilege('dawineks_runtime', 'identity.account_memberships', 'SELECT')
       OR has_table_privilege('dawineks_runtime', 'identity.account_memberships', 'INSERT')
       OR has_table_privilege('dawineks_runtime', 'identity.account_memberships', 'UPDATE')
       OR has_table_privilege('dawineks_runtime', 'identity.account_memberships', 'DELETE') THEN
        RAISE EXCEPTION 'Runtime membership grants exceed read-only scoped lookup';
    END IF;

    IF (SELECT rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole
        FROM pg_roles WHERE rolname = 'dawineks_migrator') THEN
        RAISE EXCEPTION 'The migration login has an elevated role attribute';
    END IF;

    IF pg_has_role('dawineks_migrator', 'dawineks_runtime', 'MEMBER') THEN
        RAISE EXCEPTION 'The migration login must not inherit the runtime login';
    END IF;
END;
$$;
