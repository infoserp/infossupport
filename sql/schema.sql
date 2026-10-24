-- Support Call Tracking System schema (SQL Server 2022)
-- Run against the ERP database (the one named in inc/db.php), then run sql/erp_access.sql.
-- Safe to re-run: each object is created only if missing.
--
-- Audit columns follow the ERP's own tables: user_id / user_date = who created the row and when,
-- last_userid / last_userdate = who last changed it and when. Like the ERP, only codes are stored;
-- names come from the ERP through dbo.fn_user_name.


IF OBJECT_ID('dbo.customer_flags') IS NULL
CREATE TABLE dbo.customer_flags (
    company_code  VARCHAR(2)    NOT NULL,
    ac_code       VARCHAR(5)    NOT NULL,
    status        NVARCHAR(20)  NOT NULL CONSTRAINT df_flags_status DEFAULT 'Active'
                  CONSTRAINT ck_flags_status CHECK (status IN ('Active', 'NoSupport', 'Inactive')),
    last_userdate DATETIME2(0)  NOT NULL CONSTRAINT df_flags_updated DEFAULT SYSDATETIME(),
    last_userid   VARCHAR(15)   NULL,
    CONSTRAINT pk_customer_flags PRIMARY KEY (company_code, ac_code)
);

IF OBJECT_ID('dbo.support_calls') IS NULL
CREATE TABLE dbo.support_calls (
    doc_id          INT IDENTITY(1,1) PRIMARY KEY,
    company_code    VARCHAR(2)     NOT NULL,
    branch_code     VARCHAR(2)     NOT NULL,
    doc_date        DATE           NOT NULL,
    ac_code         VARCHAR(5)     NOT NULL,
    customer_name   NVARCHAR(150)  NOT NULL,    -- name when recorded; screens show the current ERP name
    emp_code        VARCHAR(15)    NOT NULL,    -- the supporter who recorded the call
    supporter_name  NVARCHAR(75)   NOT NULL,    -- name when recorded; screens show the current ERP name
    type            NVARCHAR(10)   NOT NULL CONSTRAINT ck_calls_type CHECK (type IN ('Online', 'Onsite')),
    task_completed  NVARCHAR(2000) NOT NULL,
    status          CHAR(1)        NOT NULL CONSTRAINT df_calls_status DEFAULT 'C'   -- P Pending, O Ongoing, C Completed
                    CONSTRAINT ck_calls_status CHECK (status IN ('P', 'O', 'C')),
    comment         NVARCHAR(2000) NULL,
    job_no          VARCHAR(20)    NULL,    -- job number as typed (ERP job_master.job_no)
    time_from       TIME(0)        NULL,    -- optional time the support started / ended
    time_to         TIME(0)        NULL,
    user_date       DATETIME2(0)   NOT NULL CONSTRAINT df_calls_created DEFAULT SYSDATETIME(),
    last_userdate   DATETIME2(0)   NULL,    -- last edit (by the supporter who entered it, or an admin)
    last_userid     VARCHAR(15)    NULL
);

IF OBJECT_ID('dbo.no_support_history') IS NULL
CREATE TABLE dbo.no_support_history (
    id              INT IDENTITY(1,1) PRIMARY KEY,
    company_code    VARCHAR(2)    NOT NULL,
    ac_code         VARCHAR(5)    NOT NULL,
    start_date      DATE          NOT NULL,
    end_date        DATE          NULL,
    reason          NVARCHAR(500) NOT NULL,
    note            NVARCHAR(MAX) NULL,
    user_id         VARCHAR(15)   NOT NULL,    -- who blocked the customer
    ended_by        VARCHAR(15)   NULL,        -- who unblocked them
    user_date       DATETIME2(0)  NOT NULL CONSTRAINT df_nsh_created DEFAULT SYSDATETIME()
);

-- Admin logins (branch_user) recorded under an employee code: one identity per person.
IF OBJECT_ID('dbo.admin_emp_map') IS NULL
CREATE TABLE dbo.admin_emp_map (
    company_code VARCHAR(2)  NOT NULL,
    user_id      VARCHAR(15) NOT NULL,
    emp_code     VARCHAR(15) NOT NULL,
    CONSTRAINT pk_admin_emp_map PRIMARY KEY (company_code, user_id)
);

-- Deleted support calls: each row as it was, with who deleted it and when (call_delete.php).
IF OBJECT_ID('dbo.support_calls_deleted') IS NULL
CREATE TABLE dbo.support_calls_deleted (
    doc_id           INT            NOT NULL,
    company_code     VARCHAR(2)     NOT NULL,
    branch_code      VARCHAR(2)     NOT NULL,
    doc_date         DATE           NOT NULL,
    ac_code          VARCHAR(5)     NOT NULL,
    customer_name    NVARCHAR(150)  NOT NULL,
    emp_code         VARCHAR(15)    NOT NULL,
    supporter_name   NVARCHAR(75)   NOT NULL,
    type             NVARCHAR(10)   NOT NULL,
    task_completed   NVARCHAR(2000) NOT NULL,
    status           CHAR(1)        NOT NULL,
    comment          NVARCHAR(2000) NULL,
    user_date        DATETIME2(0)   NOT NULL,
    last_userdate    DATETIME2(0)   NULL,
    last_userid      VARCHAR(15)    NULL,
    job_no           VARCHAR(20)    NULL,
    time_from        TIME(0)        NULL,
    time_to          TIME(0)        NULL,
    deleted_userid   VARCHAR(15)    NOT NULL,
    deleted_userdate DATETIME2(0)   NOT NULL
);

-- Customer notes: a log of dated entries; the author or an administrator can edit or delete one.
IF OBJECT_ID('dbo.customer_notes') IS NULL
CREATE TABLE dbo.customer_notes (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    company_code  VARCHAR(2)     NOT NULL,
    ac_code       VARCHAR(5)     NOT NULL,
    note          NVARCHAR(2000) NOT NULL,
    user_id       VARCHAR(15)    NOT NULL,    -- who wrote it
    user_date     DATETIME2(0)   NOT NULL CONSTRAINT df_notes_created DEFAULT SYSDATETIME(),
    last_userid   VARCHAR(15)    NULL,        -- last edit
    last_userdate DATETIME2(0)   NULL
);
IF COL_LENGTH('dbo.customer_notes', 'last_userid') IS NULL
    ALTER TABLE dbo.customer_notes ADD last_userid VARCHAR(15) NULL, last_userdate DATETIME2(0) NULL;

-- Columns added after first release, for databases created before them.
IF COL_LENGTH('dbo.support_calls', 'status') IS NULL
    ALTER TABLE dbo.support_calls ADD status CHAR(1) NOT NULL CONSTRAINT df_calls_status DEFAULT 'C'
        CONSTRAINT ck_calls_status CHECK (status IN ('P', 'O', 'C'));
IF COL_LENGTH('dbo.support_calls', 'comment') IS NULL
    ALTER TABLE dbo.support_calls ADD comment NVARCHAR(2000) NULL;
IF COL_LENGTH('dbo.support_calls', 'job_no') IS NULL
    ALTER TABLE dbo.support_calls ADD job_no VARCHAR(20) NULL, time_from TIME(0) NULL, time_to TIME(0) NULL;
IF COL_LENGTH('dbo.support_calls_deleted', 'job_no') IS NULL
    ALTER TABLE dbo.support_calls_deleted ADD job_no VARCHAR(20) NULL, time_from TIME(0) NULL, time_to TIME(0) NULL;
GO

-- Renamed columns and single-letter status, for databases created before the change.
SET XACT_ABORT ON;
BEGIN TRAN;
IF COL_LENGTH('dbo.support_calls', 'doc_id') IS NULL
    EXEC sp_rename 'dbo.support_calls.id', 'doc_id', 'COLUMN';
IF COL_LENGTH('dbo.support_calls', 'doc_date') IS NULL
    EXEC sp_rename 'dbo.support_calls.support_date', 'doc_date', 'COLUMN';
IF COL_LENGTH('dbo.support_calls', 'emp_code') IS NULL
    EXEC sp_rename 'dbo.support_calls.supporter_code', 'emp_code', 'COLUMN';
IF COL_LENGTH('dbo.support_calls', 'status') > 1
BEGIN
    ALTER TABLE dbo.support_calls DROP CONSTRAINT ck_calls_status, df_calls_status;
    EXEC('UPDATE dbo.support_calls SET status = CASE status WHEN ''Pending'' THEN ''P'' WHEN ''Ongoing'' THEN ''O'' ELSE ''C'' END');
    ALTER TABLE dbo.support_calls ALTER COLUMN status CHAR(1) NOT NULL;
    ALTER TABLE dbo.support_calls ADD CONSTRAINT df_calls_status DEFAULT 'C' FOR status,
        CONSTRAINT ck_calls_status CHECK (status IN ('P', 'O', 'C'));
END

-- ERP-style audit columns; stored names dropped (looked up from the ERP instead).
IF COL_LENGTH('dbo.support_calls', 'user_date') IS NULL
    EXEC sp_rename 'dbo.support_calls.created_at', 'user_date', 'COLUMN';
IF COL_LENGTH('dbo.support_calls', 'last_userdate') IS NULL
    EXEC sp_rename 'dbo.support_calls.updated_at', 'last_userdate', 'COLUMN';
IF COL_LENGTH('dbo.support_calls', 'last_userid') IS NULL
    EXEC sp_rename 'dbo.support_calls.updated_by', 'last_userid', 'COLUMN';
IF COL_LENGTH('dbo.support_calls', 'updated_by_name') IS NOT NULL
    EXEC('ALTER TABLE dbo.support_calls DROP COLUMN updated_by_name');

IF COL_LENGTH('dbo.customer_flags', 'last_userdate') IS NULL
    EXEC sp_rename 'dbo.customer_flags.updated_at', 'last_userdate', 'COLUMN';
IF COL_LENGTH('dbo.customer_flags', 'last_userid') IS NULL
    EXEC sp_rename 'dbo.customer_flags.updated_by', 'last_userid', 'COLUMN';

IF COL_LENGTH('dbo.no_support_history', 'user_date') IS NULL
    EXEC sp_rename 'dbo.no_support_history.created_at', 'user_date', 'COLUMN';
IF COL_LENGTH('dbo.no_support_history', 'user_id') IS NULL
    EXEC sp_rename 'dbo.no_support_history.created_by', 'user_id', 'COLUMN';
IF COL_LENGTH('dbo.no_support_history', 'created_by_name') IS NOT NULL
    EXEC('ALTER TABLE dbo.no_support_history DROP COLUMN created_by_name');
IF COL_LENGTH('dbo.no_support_history', 'ended_by_name') IS NOT NULL
    EXEC('ALTER TABLE dbo.no_support_history DROP COLUMN ended_by_name');
COMMIT;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ix_calls_branch_date')
    CREATE INDEX ix_calls_branch_date ON dbo.support_calls (company_code, branch_code, doc_date DESC);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ix_calls_customer')
    CREATE INDEX ix_calls_customer ON dbo.support_calls (company_code, ac_code, doc_date DESC);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ix_calls_supporter')
    CREATE INDEX ix_calls_supporter ON dbo.support_calls (emp_code, doc_date DESC);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ix_nsh_customer')
    CREATE INDEX ix_nsh_customer ON dbo.no_support_history (company_code, ac_code, start_date DESC);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'ix_notes_customer')
    CREATE INDEX ix_notes_customer ON dbo.customer_notes (company_code, ac_code, user_date DESC);
GO

-- Notes used to be one editable field on customer_flags: keep each one as the customer's first log entry.
SET XACT_ABORT ON;
BEGIN TRAN;
IF COL_LENGTH('dbo.customer_flags', 'notes') IS NOT NULL
BEGIN
    EXEC('INSERT INTO dbo.customer_notes (company_code, ac_code, note, user_id, user_date)
          SELECT company_code, ac_code, LEFT(LTRIM(RTRIM(notes)), 2000), ISNULL(last_userid, ''''), last_userdate
          FROM dbo.customer_flags WHERE LTRIM(RTRIM(ISNULL(notes, ''''))) <> ''''');
    EXEC('ALTER TABLE dbo.customer_flags DROP COLUMN notes');
END
COMMIT;
GO

-- Dropped and re-created (not CREATE OR ALTER) so this also runs on SQL Server 2012/2014.
-- Dropping clears their permissions: always run sql/erp_access.sql after this file.
IF OBJECT_ID('dbo.v_support_calls') IS NOT NULL DROP VIEW dbo.v_support_calls;
IF OBJECT_ID('dbo.fn_user_name') IS NOT NULL DROP FUNCTION dbo.fn_user_name;
GO

-- Current ERP name for a user code stored by this app: an employee (emp_master), else an
-- administrator login (branch_user), else the code itself.
CREATE FUNCTION dbo.fn_user_name (@company VARCHAR(2), @code VARCHAR(15))
RETURNS NVARCHAR(150)
AS
BEGIN
    RETURN COALESCE(
        (SELECT TOP 1 NULLIF(RTRIM(emp_name), '') FROM dbo.emp_master WHERE company_code = @company AND emp_code = @code),
        (SELECT TOP 1 NULLIF(RTRIM(user_name), '') FROM dbo.branch_user WHERE user_id = @code),
        @code);
END
GO

-- What the app reads: calls with the current customer (ac_master) and employee (emp_master) names,
-- so a rename in the ERP shows everywhere. The stored name is used when the ERP record is missing.
CREATE VIEW dbo.v_support_calls AS
SELECT sc.doc_id, sc.company_code, sc.branch_code, sc.doc_date, sc.ac_code,
       COALESCE(RTRIM(a.description), sc.customer_name) AS customer_name,
       sc.emp_code,
       COALESCE(RTRIM(e.emp_name), sc.supporter_name) AS supporter_name,
       sc.type, sc.task_completed, sc.status, sc.comment,
       sc.user_date, sc.last_userdate, sc.last_userid, sc.job_no, sc.time_from, sc.time_to,
       dbo.fn_user_name(sc.company_code, sc.last_userid) AS last_username
FROM dbo.support_calls sc
LEFT JOIN dbo.ac_master a ON a.company_code = sc.company_code AND a.ac_code = sc.ac_code
LEFT JOIN dbo.emp_master e ON e.company_code = sc.company_code AND e.emp_code = sc.emp_code;
GO

-- Re-creating the view and function above clears their permissions; restore them for the app login
-- (sql/erp_access.sql grants the rest). Skipped where the app uses another login, e.g. sa.
IF USER_ID('support_app') IS NOT NULL
BEGIN
    GRANT SELECT ON dbo.v_support_calls TO support_app;
    GRANT EXECUTE ON dbo.fn_user_name TO support_app;
END
GO
