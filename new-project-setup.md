# New PHP + SQL Server project on this machine

Everything needed to stand up a new PHP + SQL Server project on this Windows
box. All of it was verified on the machine, not assumed.

**This document is self-contained.** Every file you need is written out in
section 5 — nothing has to be copied from the `TaskManager` folder, which may
already be gone. References to it are historical, explaining *why* a given
step exists.

---

## 1. How "localhost" actually works here

There is no Apache and no XAMPP. Both ports are owned by **PID 4 (`System`)**,
which is Windows' `HTTP.sys` — i.e. **IIS**.

IIS serves one or more *sites*. Each site has a **binding** (a port) and a
**physical path** (a folder). That pairing is the whole mechanism — there is
nothing else to it.

As configured when this was written:

| URL | Physical path | IIS site |
|---|---|---|
| `http://localhost/` | `C:\inetpub\wwwroot` | Default Web Site |
| `http://localhost:8080/` | `C:\inetpub\wwwroot\TaskManager` | a second site |

That is why TaskManager answered on two URLs: `localhost/TaskManager/` as a
subfolder of the Default Web Site, and `localhost:8080/` as the **root** of its
own site. Proven at the time by `:8080/composer.json` returning 200 while
`:8080/TaskManager/composer.json` returned 404.

**Two sites cannot share a port**, so check what is actually free before you
pick one:

```powershell
Get-Website                              # sites, bindings, physical paths
Get-NetTCPConnection -State Listen | Where-Object LocalPort -in 80,8080,8081 |
  Select-Object LocalPort -Unique
```

If the TaskManager site has since been removed, **8080 is free and you can use
it**. If it is still there, use 8081. The commands below say 8081 — change the
number if 8080 is available.

---

## 2. Installed stack (already present — do not reinstall)

| Component | Version / detail |
|---|---|
| Web server | IIS (`W3SVC`), listening on 80 and 8080 |
| PHP | **8.4.16** |
| PHP SQL driver | `pdo_sqlsrv` and `sqlsrv` both loaded |
| Database | **SQL Server 2022** (16.0.1000.6), instance `DESKTOP-KPBMUH3` |
| Existing databases | `infos`, `infos_gn`, `infos_powertech`, `infos_sls`, `infos_woodmans`, `task` |
| Default doc root | `C:\inetpub\wwwroot` |

PHP is registered **globally** in IIS, not per-site — the existing project's
`web.config` had no `<handlers>` section yet `.php` executed fine. A new site
therefore gets PHP with no extra configuration. If `.php` ever downloads as
plain text instead of running, that global handler is what's missing.

PHP lives at **`C:\PHP84`** (`php.exe`, `php-cgi.exe`, and `php.ini`). It is
**not on PATH**, so `php` and `composer` do not work in a plain shell. Either
call it by full path or add it:

```powershell
C:\PHP84\php.exe -v                     # PHP 8.4.16 (cli) NTS VC++ 2022 x64
$env:Path += ";C:\PHP84"                # this shell only
```

`php.ini` to edit for limits: **`C:\PHP84\php.ini`**. Restart the app pool
after changing it.

---

## 3. Create the new site on port 8081

Needs an **elevated** PowerShell once (Run as Administrator).

```powershell
# 1. project folder
New-Item -ItemType Directory C:\inetpub\wwwroot\newproject

# 2. IIS site on its own port
New-WebSite -Name newproject -Port 8081 -PhysicalPath C:\inetpub\wwwroot\newproject

# 3. let your own user edit the files (see gotcha 4.1 — do not skip)
icacls C:\inetpub\wwwroot\newproject /grant "desktop-kpbmuh3\lenovo:(OI)(CI)M"
```

Verify, unelevated:

```powershell
'<?php phpinfo();' | Out-File -Encoding utf8 C:\inetpub\wwwroot\newproject\index.php
(Invoke-WebRequest http://localhost:8081/ -UseBasicParsing).StatusCode   # expect 200
```

Useful follow-ups:

```powershell
Get-Website                                   # list sites + bindings
Restart-WebAppPool -Name newproject           # after php.ini changes
Stop-Website  -Name newproject
Start-Website -Name newproject
```

An **app pool** is the worker process. `New-WebSite` creates one named after
the site, so the two projects already crash independently. Nothing more to do.

### Skipping the port entirely

If a root URL is not important, drop the project in
`C:\inetpub\wwwroot\newproject` and use `http://localhost/newproject` — it
works with **no admin and no IIS changes** (the Default Web Site already serves
that folder). Only add the site + port if you want the app at `/`.

---

## 4. Gotchas, each one hit for real on TaskManager

### 4.1 `C:\inetpub` is read-only to your user — this breaks `git init`

`BUILTIN\Users` has only `(I)(RX)` on the webroot. `lenovo` *is* in
`Administrators`, but in an unelevated process that SID is marked
**"deny only"** by UAC, so the effective rights are read-only. Symptom:

```
git init -b main
C:/inetpub/wwwroot/<project>/.git: Permission denied
```

Fix is the `icacls` grant in step 3. `M` (Modify) is enough; `F` also allows
rewriting ACLs. Granting does not remove `IIS_IUSRS:(RX)`, so the site keeps
serving.

### 4.2 A `.git` folder in the webroot is downloadable over HTTP

Request filtering here sets `allowUnlisted="true"` and has no hidden segments,
so static files serve freely — verified: `/TaskManager/docs/cred.txt` returned
200 with its contents. That means `/.git/config` and the packfiles would be
fetchable, i.e. the whole repo and its history.

Add to `web.config` inside `<system.webServer><security><requestFiltering>`:

```xml
<hiddenSegments>
    <add segment=".git" />
</hiddenSegments>
```

Then confirm `/.git/config` returns **404.8** (IIS's hidden-segment code).

### 4.3 Set a `.gitattributes` before the first commit

`core.autocrlf` is `true` on this machine. If the codebase has mixed line
endings (TaskManager had 76 CRLF vs 181 LF `.php` files), git normalises to LF
in the repo and hands back CRLF on checkout — silently rewriting files that are
**also the live deployment**. Prevent it:

```
* -text
```

### 4.4 Keep credentials out of git

The connection file sits in the webroot. Commit a template, ignore the real
one:

```
# .gitignore
/db.php
/vendor/
/uploads/
*.log
.claude/settings.local.json
```

…and commit `db.php.example`. Name it `db.php.example`, **not**
`db.example.php` — the latter ends in `.php` and IIS would execute it.

### 4.5 PHP upload limits are far below what the app may advertise

Current PHP values:

| Setting | Value |
|---|---|
| `upload_max_filesize` | **2M** |
| `post_max_size` | **8M** |
| `max_execution_time` | 30 |

Those are the live values in `C:\PHP84\php.ini`. TaskManager's `web.config`
allows `maxAllowedContentLength` of 100 MB and its code checks for a 100 MB
limit — but PHP rejects anything over 2 MB long before that code runs. If the
new project accepts uploads, raise all three deliberately:

```powershell
# edit C:\PHP84\php.ini, then:
Restart-WebAppPool -Name newproject
```

### 4.6 Extensionless URLs are a rewrite rule, not magic

The `web.config` in section 5 rewrites `/admin/foo` → `/admin/foo.php` and
301-redirects `/admin/foo.php` → `/admin/foo`, which is why links can be
written as `/admin/dashboard`. Keep the rule or delete it — just know it is a
choice, and that `curl` against a `.php` URL will return a 301, not a 200.

---

## 5. Starter files

Create these four by hand in the project root. Nothing needs to be copied from
anywhere — the full contents are below.

### `web.config`

Rewrite rules, MIME types, the `.git` block from gotcha 4.2, and a 100 MB
request ceiling. Delete the `<rewrite>` block if you want ordinary `.php` URLs.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <rewrite>
            <rules>
                <rule name="Hide .php extension" stopProcessing="true">
                    <match url="^([^.]+)$" ignoreCase="true" />
                    <conditions>
                        <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
                        <add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true" />
                    </conditions>
                    <action type="Rewrite" url="{R:1}.php" />
                </rule>
                <rule name="Redirect PHP files" stopProcessing="true">
                    <match url="^(.*)\.php$" ignoreCase="true" />
                    <conditions>
                        <add input="{REQUEST_FILENAME}" matchType="IsFile" />
                        <add input="{REQUEST_METHOD}" pattern="^POST$" negate="true" />
                    </conditions>
                    <action type="Redirect" url="{R:1}" redirectType="Permanent" />
                </rule>
            </rules>
        </rewrite>
        <directoryBrowse enabled="false" />
        <defaultDocument>
            <files>
                <clear />
                <add value="index.php" />
                <add value="index.html" />
            </files>
        </defaultDocument>
        <staticContent>
            <remove fileExtension=".css" />
            <mimeMap fileExtension=".css" mimeType="text/css" />
            <remove fileExtension=".js" />
            <mimeMap fileExtension=".js" mimeType="application/javascript" />
            <remove fileExtension=".json" />
            <mimeMap fileExtension=".json" mimeType="application/json" />
            <remove fileExtension=".svg" />
            <mimeMap fileExtension=".svg" mimeType="image/svg+xml" />
            <remove fileExtension=".woff2" />
            <mimeMap fileExtension=".woff2" mimeType="font/woff2" />
        </staticContent>
        <security>
            <requestFiltering>
                <fileExtensions allowUnlisted="true" />
                <!-- keeps the repo and its history off the web -->
                <hiddenSegments>
                    <add segment=".git" />
                </hiddenSegments>
                <requestLimits maxAllowedContentLength="104857600" />
            </requestFiltering>
        </security>
    </system.webServer>
</configuration>
```

### `.gitattributes`

Write this **before** the first commit (gotcha 4.3).

```
* -text
```

### `.gitignore`

```
# Local configuration & credentials -- copy db.php.example to db.php
/db.php

# Composer
/vendor/

# Runtime data
/uploads/
*.log

# Editor / OS
/.vscode/
Thumbs.db
desktop.ini

# Claude Code local (per-user) settings
.claude/settings.local.json
```

### `db.php.example`

Named `db.php.example`, **not** `db.example.php` — the latter ends in `.php`
and IIS would execute it. Copy to `db.php` and fill in real values.

```php
<?php
// Copy to db.php and fill in real values. db.php is not tracked in git.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$server   = "DESKTOP-KPBMUH3";
$database = "YOUR_DATABASE";
$user     = "YOUR_LOGIN";
$password = "YOUR_PASSWORD";

try {
    $conn = new PDO("sqlsrv:Server=$server;Database=$database", $user, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    date_default_timezone_set('Asia/Dubai');
} catch (PDOException $e) {
    error_log("Connection failed: " . $e->getMessage());
    die('Database connection error');
}
```

### Dependencies — install, do not copy

There is no `composer.phar` on this machine by default. If the project needs
PDF or mail libraries:

```powershell
cd C:\inetpub\wwwroot\newproject
Invoke-WebRequest https://getcomposer.org/composer.phar -OutFile composer.phar
C:\PHP84\php.exe composer.phar require dompdf/dompdf
C:\PHP84\php.exe composer.phar require phpmailer/phpmailer
```

Take current major versions. Pull in a library only when something actually
needs it, and use a CDN for front-end packages rather than vendoring the
source tree.

---

## 6. Database

Same SQL Server instance, new database:

```sql
CREATE DATABASE newdb;
```

Then fill in `db.php` from the template in section 5. Use PDO with the
`sqlsrv:` DSN — not the old `odbc_*` functions.

Create a dedicated least-privilege login for the new database rather than
reusing `sa`:

```sql
CREATE LOGIN newapp WITH PASSWORD = '<strong password>';
USE newdb;
CREATE USER newapp FOR LOGIN newapp;
ALTER ROLE db_datareader ADD MEMBER newapp;
ALTER ROLE db_datawriter ADD MEMBER newapp;
```

Quickest way to run ad-hoc SQL (no `sqlcmd` installed on this machine):

```powershell
$cs = "Server=DESKTOP-KPBMUH3;Database=newdb;User Id=...;Password=...;TrustServerCertificate=True"
$cn = New-Object System.Data.SqlClient.SqlConnection $cs; $cn.Open()
$cmd = $cn.CreateCommand(); $cmd.CommandText = "SELECT 1"
$cmd.ExecuteScalar(); $cn.Close()
```

### If the new project touches the shared `task` database

That database is shared live with an ERP application and will outlive the
TaskManager folder. Two rules learned the hard way:

- **`ac_master.u_key` mirrors the numeric `ac_code` on all 11,600 rows.** The
  ERP reads `u_key` to pick its next account number. Insert a row without it
  and the ERP re-offers a code that is already taken → *"customer id already
  exists"*.
- **Customers, suppliers and other account types share one `ac_code`
  sequence** (they interleave: `…11777 customer, 11778 supplier, 11779
  customer…`). Any "next code" calculation must take `MAX` across *every*
  account in the company, not just one type.

More broadly: 197 tables carry an `ac_code`, but only 27 have a foreign key to
`ac_master`. Deleting a parent row can silently orphan the rest — check
references explicitly before any delete.

---

## 7. Setup checklist

- [ ] Folder created under `C:\inetpub\wwwroot`
- [ ] IIS site bound to a free port (**8081**; check `Get-Website` first)
- [ ] `icacls` Modify granted to your user
- [ ] `http://localhost:8081/` returns 200
- [ ] `web.config`, `.gitattributes`, `.gitignore`, `db.php.example` created (section 5)
- [ ] Database created, dedicated login, `db.php` filled in and connecting
- [ ] `.git` blocked — `/.git/config` returns **404**, not 200
- [ ] Upload limits raised in `C:\PHP84\php.ini` if files are uploaded
- [ ] `git init -b main` and first commit (`.gitattributes` in place first)
