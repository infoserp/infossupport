-- Database permissions for the application login.
-- Run as an administrator against the ERP database, after sql/schema.sql. Safe to re-run.
--


IF USER_ID('support_app') IS NULL
    CREATE USER support_app FOR LOGIN support_app;

GRANT SELECT ON dbo.company_master (company_code, company_name) TO support_app;
GRANT SELECT ON dbo.branch_master  (company_code, branch_code, branch_name) TO support_app;
GRANT SELECT ON dbo.branch_user    (user_id, user_name, user_type, edit_password, branch_code) TO support_app;
GRANT SELECT ON dbo.emp_master     (emp_code, emp_name, emp_pwd, emp_type, active, company_code, branch_code) TO support_app;
GRANT SELECT ON dbo.parent_control (company_code, ac_code, parent_type, set_default) TO support_app;
GRANT SELECT ON dbo.ac_master      (company_code, ac_code, account_code, parent_account_code, child_exist, active,
                                    description, contact_name, contact_no, tel_off, email, address, city,
                                    blocked, blocked_remark, blocked_by, blocked_date, category_code) TO support_app;
GRANT SELECT ON dbo.category_master (category_code, category_name) TO support_app;

GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.support_calls      TO support_app;
GRANT SELECT                         ON dbo.v_support_calls    TO support_app;
GRANT SELECT, INSERT, UPDATE         ON dbo.customer_flags     TO support_app;
GRANT SELECT, INSERT, UPDATE         ON dbo.no_support_history TO support_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.customer_notes     TO support_app;
GRANT SELECT, INSERT                 ON dbo.support_calls_deleted TO support_app;
GRANT SELECT                         ON dbo.admin_emp_map      TO support_app;
GRANT EXECUTE                        ON dbo.fn_user_name       TO support_app;
