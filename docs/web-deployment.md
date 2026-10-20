# Deploying the web page

`web/cucm-reports.php` is a single file. The database account needs only
`SELECT`.

```bash
cp web/cucm-reports.php /var/www/html/
```

Configuration comes from `CPS_DB_*` environment variables, a file named by
`CPS_CONF`, or `cucm.conf` next to the script. Keep `cucm.conf` outside the
web root if you can.

## nginx

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

    location = /cucm.conf { deny all; return 404; }
}
```

## Apache

`mod_php` and `mod_proxy_fcgi` work as is. With `RewriteRule`, keep `[QSA]`.

## Troubleshooting

### Every tab shows the same report

The query string is not reaching PHP.

- `location ~ \.php$` is missing `include fastcgi_params;` (or
  `fastcgi.conf`). The page detects this and names the directive.
- A `try_files` fallback without `?$query_string`. The page recovers from
  `REQUEST_URI` and shows a note in the footer; fix the config anyway.

### `SQLSTATE[HY000] [2002] Permission denied`

A connection failure, not a login failure. On RHEL:

- `db_host = localhost` uses the UNIX socket; `127.0.0.1` uses TCP. For a
  local database, use `localhost`.
- SELinux blocks php-fpm TCP connections to databases by default:

  ```bash
  sudo ausearch -m avc -ts recent | grep -iE 'php-fpm|httpd|mysql'
  sudo setsebool -P httpd_can_network_connect_db on
  ```

### Cannot connect to the page

| Symptom | Cause |
|---|---|
| times out | firewall drop |
| `No route to host` | firewalld reject; port not open in the active zone |
| `Connection refused` | nothing listening on that address |

```bash
firewall-cmd --get-active-zones
firewall-cmd --permanent --zone=<zone> --add-service=http && firewall-cmd --reload
ss -lntp '( sport = :80 )'
```

## Access control

The page has no login. Use the web server:

```nginx
auth_basic           "CUCM reports";
auth_basic_user_file /etc/nginx/.htpasswd;
```
