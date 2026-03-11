# Example Nginx Config

The bare minimum location routing blocks you will need to make this work in your Nginx environment go like this:

```
location = /bootstrap.bundle.min.js {
  limit_except GET { deny all; }
  alias /path/to/app/composer/twbs/bootstrap/dist/js/bootstrap.bundle.min.js;
  default_type application/javascript;
  access_log off;
  expires 7d;
  add_header Cache-Control "public, max-age=604800, immutable";
}
location ^~ /mce/ {
  limit_except GET { deny all; }
  try_files $uri $uri/ =404;
  alias /path/to/app/composer/tinymce/tinymce/;
}
location ^~ /mde/ {
  limit_except GET { deny all; }
  try_files $uri $uri/ =404;
  alias /home/dev/app/composer/tualo/easymde/lib/;
}
location ^~ /panel/ {
  limit_except GET POST { deny all; }
  try_files $uri $uri/ /index.php?$query_string;
  root /path/to/app/panel;
  fastcgi_pass unix:/path/to/php/socket;
  fastcgi_split_path_info ^(.+\.php)(/.+)$;
  fastcgi_index index.php;
  include /etc/nginx/fastcgi_params;
  fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
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
  fastcgi_pass unix:/path/to/php/socket;
  fastcgi_split_path_info ^(.+\.php)(/.+)$;
  fastcgi_index index.php;
  include /etc/nginx/fastcgi_params;
  fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```
