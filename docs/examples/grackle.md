# Example Grackle Config

If you are running [Grackle](https://github.com/humphreyboagart/grackle) and already have your SSL certificates set up, you should be able to drop this config in:


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
    alias /home/USERNAME/app/composer/twbs/bootstrap/dist/js/bootstrap.bundle.min.js;
    default_type application/javascript;
    access_log off;
    expires 7d;
    add_header Cache-Control "public, max-age=604800, immutable";
  }
  location ^~ /mce/ {
    alias /home/USERNAME/app/composer/tinymce/tinymce/;
    try_files $uri $uri/ =404;
    limit_except GET {
      deny all;
    }
  }
  location ^~ /panel/ {
    try_files $uri $uri/ /index.php?$query_string;
    root /home/USERNAME/app/panel;
    limit_except GET POST {
      deny all;
    }
    fastcgi_pass unix:/run/php/php.USERNAME.sock;
    fastcgi_split_path_info ^(.+\.php)(/.+)$;
    fastcgi_index index.php;
    include /etc/nginx/fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
  }
  location ~ ^/theme/[^/]+/views/.*\.php$ {
    return 404;
  }
  location / {
    try_files $uri $uri/ /index.php?$query_string;
    limit_except GET POST {
      deny all;
    }
  }
  location ~ \.php$ {
    try_files $uri =404;
    limit_except GET POST {
      deny all;
    }
    fastcgi_pass unix:/run/php/php.USERNAME.sock;
    fastcgi_split_path_info ^(.+\.php)(/.+)$;
    fastcgi_index index.php;
    include /etc/nginx/fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
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
  ssl_certificate /etc/letsencrypt/live/WWW.YOURDOMAIN.COM/fullchain.pem; # managed by Certbot
  ssl_certificate_key /etc/letsencrypt/live/WWW.YOURDOMAIN.COM/privkey.pem; # managed by Certbot
  include /etc/nginx/includes/ssl.conf;
  include /etc/nginx/includes/headers.conf;
  include /etc/nginx/includes/util.conf;
  error_log /home/www/logs/nginx/www-error.log warn;
  access_log off;
  #access_log /home/www/logs/nginx/www-access.log main;
  return 301 https://YOURDOMAIN.COM$request_uri;
}
```
