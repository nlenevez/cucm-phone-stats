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
