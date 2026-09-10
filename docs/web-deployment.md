# Putting the report page on a web server

`web/cucm-reports.php` is one self-contained file. Copy it into a web root,
give it database credentials, and it works — with one caveat that has caught
us already, below.

```bash
cp web/cucm-reports.php /var/www/html/
cp cucm.conf /var/www/html/          # or set CPS_DB_* in the environment
```

The account needs only `SELECT`. Nothing on this page writes.

## The tabs do nothing / every tab shows the same report

The tabs are ordinary links carrying `?r=<report>`. If the page reloads but
always shows the first report, **the query string is not reaching PHP**, and
that is a web server configuration problem rather than anything in the page.

There are two distinct causes, and they need different fixes.

### 1. `location ~ \.php$` is missing `include fastcgi_params;`

This is the common one, and it is total: PHP receives no CGI environment at
all — no `QUERY_STRING`, no `REQUEST_URI`, not even `SCRIPT_NAME`. Nothing in
the page can recover the parameters, so it detects the condition and says so
at the top of the page instead of silently rendering the wrong report.

```nginx
location ~ \.php$ {
    include        fastcgi_params;          # <-- this line
    fastcgi_pass   unix:/run/php-fpm/www.sock;
    fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

`include fastcgi.conf;` works equally well — it is `fastcgi_params` plus
`SCRIPT_FILENAME`, in which case you can drop the explicit `fastcgi_param`
line. Confirmed by serving the page through nginx with the include removed:
every one of those variables is absent and every tab renders the default.

### 2. `try_files` rewriting without `?$query_string`

Only bites when the *fallback* branch is actually taken — a "pretty" URL
that is not a real file. The arguments are dropped from the rewritten
target, though `REQUEST_URI` survives:

```nginx
location / {
    try_files $uri $uri/ /cucm-reports.php?$query_string;   # not just /cucm-reports.php
}
```

The page recovers the parameters from `REQUEST_URI` in this case, so it keeps
working, and shows a note in the footer — the note is worth acting on, since
relying on that fallback is not something to leave in place.

Note that a plain `try_files $uri $uri/` with an `index` directive does *not*
hit this: the index lookup preserves the arguments. It is specifically the
fallback branch that loses them.

## `SQLSTATE[HY000] [2002] Permission denied`

MySQL error **2002 is a connection failure, not an authentication failure** —
the credentials were never tried. "Permission denied" specifically means
`EACCES` from `connect()`. On RHEL there are two causes, and they are easily
confused because both produce exactly this message: SELinux refusing
php-fpm a database connection, and `db_host` selecting a transport that is
not available. Check the `db_host` question below first — it is a one-line
change and costs nothing to rule out.

**The giveaway is that the script works from the command line and fails
through the web server.** Same file, same credentials, same config — so the
difference is not the credentials, it is the domain the process runs in. A
shell runs as `unconfined_t`, which SELinux does not restrict; php-fpm runs
as `httpd_t`, which is confined. An asymmetry between two access paths
points at the path, never at the password.

```bash
ps -eZ | grep php-fpm      # system_u:system_r:httpd_t:s0
```

php-fpm runs in `httpd_t`, and the boolean that permits it is off by default:

```bash
sudo setsebool -P httpd_can_network_connect_db on
```

Use `httpd_can_network_connect` instead if the page also needs to reach
other services. Confirm the diagnosis before changing anything:

```bash
sudo ausearch -m avc -ts recent | grep -iE 'php-fpm|httpd|mysql'
```

or, as a negative control, `setenforce 0`, reload the page, `setenforce 1`.
If it works while permissive, it is SELinux and nothing else.

### Which `db_host` to use, and why it changes the answer

PDO's MySQL driver treats these as two different transports:

```ini
db_host = localhost     # UNIX SOCKET  (db_port is ignored)
db_host = 127.0.0.1     # TCP
```

That matters here because **SELinux gates the two differently**. The
`httpd_can_network_connect_db` boolean governs the *TCP* connection, so with
a database on the same machine:

- `db_host = localhost` connects over the socket and generally just works.
- `db_host = 127.0.0.1` is a network connection as far as SELinux is
  concerned, and fails with 2002 until the boolean is set.

**So for a local database on RHEL, prefer `localhost`.** It is the path of
least resistance, and it is faster than TCP into the bargain. `127.0.0.1`
was the shipped default on the reasoning that it is unambiguous; on RHEL it
is simply the harder road, and was the actual cause of a 2002 in the field.

For a database on **another host** there is no choice: use its name or
address, and set the boolean.

Distinguishing the two causes when you hit 2002: an SELinux denial appears
in the audit log and disappears under `setenforce 0`; a socket-vs-TCP
mismatch does neither, and switching `db_host` fixes it instantly.

## Firewall: the port is open but nothing connects

Worth having the discriminator to hand, because "it's the firewall" and
"nothing is listening" look identical from a browser:

| Client symptom | Meaning |
|---|---|
| hangs, then times out | netfilter `DROP` |
| `No route to host`, instantly | firewalld `REJECT` — the port is not open in the zone that is actually applied |
| `Connection refused`, instantly | the firewall is fine; nothing is listening **on that address** |

`firewall-cmd --add-service=http` without `--zone` applies to the *default*
zone, which is not necessarily the zone the interface is in:

```bash
firewall-cmd --get-active-zones                  # the zone that matters
firewall-cmd --list-all --zone=<that one>
firewall-cmd --permanent --zone=<that one> --add-service=http
firewall-cmd --reload
```

And check what the socket is actually bound to, since `listen 127.0.0.1:80`
gives *refused* and no firewall change will help:

```bash
ss -lntp '( sport = :80 )'
```

SELinux is not a candidate for this one — it does not drop packets, and a
successfully bound port means it already permitted the bind.

## A known-good server block

```nginx
server {
    listen 80;
    server_name cucm-reports.example.local;
    root /var/www/html;
    index cucm-reports.php;

    location / {
        try_files $uri $uri/ /cucm-reports.php?$query_string;
    }

    location ~ \.php$ {
        include        fastcgi_params;
        fastcgi_pass   unix:/run/php-fpm/www.sock;
        fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # cucm.conf holds the database password; never serve it.
    location = /cucm.conf { deny all; return 404; }
}
```

That last block matters if you put `cucm.conf` in the web root. Better still,
keep it outside the root and point at it with `CPS_CONF`, or use the
`CPS_DB_*` environment variables and have no file at all.

## Apache

`mod_php` and `mod_proxy_fcgi` both pass the query string without extra
configuration. If you use `RewriteRule`, keep the `[QSA]` flag.

## Access control

The page has no authentication of its own — it is a read-only view of
collected statistics, and access is left to the web server:

```nginx
auth_basic           "CUCM reports";
auth_basic_user_file /etc/nginx/.htpasswd;
```
