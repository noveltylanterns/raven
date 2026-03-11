# Example Grackle Config

If you are running [Grackle](https://github.com/humphreyboagart/grackle) and already have your SSL certificates set up, you should be able to drop this config in `/etc/nginx/active/USERNAME.conf` and run `grackle reset-web` to align your web environment:


```
##############################
### Base Account Config ######
##############################

server {
  listen 80;
  listen [::]:80;
  server_name YOURDOMAIN.COM;
  include /etc/nginx/includes/headers.conf;
  include /etc/nginx/includes/util.conf;
  return 301 https://YOURDOMAIN.COM$request_uri;
}

server {
  listen 443 ssl;
  listen [::]:443 ssl;
  http2 on;
  server_name YOURDOMAIN.COM;
  ssl_certificate /etc/letsencrypt/live/YOURDOMAIN.COM/fullchain.pem;
  ssl_certificate_key /etc/letsencrypt/live/YOURDOMAIN.COM/privkey.pem;
  include /etc/nginx/includes/ssl.conf;
  include /etc/nginx/includes/headers.conf;
  include /etc/nginx/includes/util.conf;
  root /home/USERNAME/app/public;
  index index.php
  error_log /home/USERNAME/logs/nginx/error.log warn;
  access_log off;
  #access_log /home/USERNAME/logs/nginx/access.log main;
  location = /bootstrap.bundle.min.js {
    limit_except GET { deny all; }
    alias /home/USERNAME/app/composer/twbs/bootstrap/dist/js/bootstrap.bundle.min.js;
    default_type application/javascript;
    access_log off;
    expires 7d;
    add_header Cache-Control "public, max-age=604800, immutable";
  }
  location ^~ /mce/ {
    limit_except GET { deny all; }
    try_files $uri $uri/ =404;
    alias /home/USERNAME/app/composer/tinymce/tinymce/;
  }
  location ^~ /mde/ {
    limit_except GET { deny all; }
    try_files $uri $uri/ =404;
    alias /home/USERNAME/app/composer/tualo/easymde/lib/;
  }
  location ^~ /panel/ {
    limit_except GET POST { deny all; }
    try_files $uri $uri/ /index.php?$query_string;
    root /home/USERNAME/app/panel;
    fastcgi_pass unix:/run/php/php.USERNAME.sock;
    fastcgi_split_path_info ^(.+\.php)(/.+)$;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include /etc/nginx/fastcgi_params;
  }
  location ~ ^/theme/[^/]+/vis/.*\.php$ {
    return 404;
  }
  location / {
    limit_except GET POST { deny all; }
    try_files $uri $uri/ /index.php?$query_string;
  }
  location ~ \.php$ {
    limit_except GET POST { deny all; }
    try_files $uri =404;
    fastcgi_pass unix:/run/php/php.USERNAME.sock;
    fastcgi_split_path_info ^(.+\.php)(/.+)$;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include /etc/nginx/fastcgi_params;
  }
}
```


Optional no-www redirect:


```
##############################
### No-WWW Redirects #########
##############################

server {
  listen 80;
  listen [::]:80;
  server_name WWW.YOURDOMAIN.COM;
  include /etc/nginx/includes/headers.conf;
  include /etc/nginx/includes/util.conf;
  return 301 https://YOURDOMAIN.COM$request_uri;
}

server {
  listen 443 ssl;
  listen [::]:443 ssl;
  http2 on;
  server_name WWW.YOURDOMAIN.COM;
  ssl_certificate /etc/letsencrypt/live/WWW.YOURDOMAIN.COM/fullchain.pem;
  ssl_certificate_key /etc/letsencrypt/live/WWW.YOURDOMAIN.COM/privkey.pem;
  include /etc/nginx/includes/ssl.conf;
  include /etc/nginx/includes/headers.conf;
  include /etc/nginx/includes/util.conf;
  error_log /home/www/logs/nginx/www-error.log warn;
  access_log off;
  #access_log /home/www/logs/nginx/www-access.log main;
  return 301 https://YOURDOMAIN.COM$request_uri;
}
```
