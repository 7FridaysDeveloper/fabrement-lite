#!/bin/sh
# Installs one test site from nothing, inside its container, the way a site is set up by hand:
# the database, WordPress, the plugins activated (their activation hooks run), the theme, pretty
# permalinks, and the administrator the tests act as. Then takes the snapshot every test class
# starts from (snapshot.sh). Prints the administrator's application password on its last line —
# the tests' MCP client signs in with it, as Claude does.
#
#   sh /var/www/setup/install.sh plain https://example.org:18443
#   sh /var/www/setup/install.sh wpml  https://example.org:18444
set -e
site="$1"
url="$2"
# WP-CLI as the web server's user: what it writes (uploads, field files) the site can change later.
wp() { runuser -u www-data -- /usr/local/bin/wp --path=/var/www/html "$@"; }

sh /var/www/setup/sync.sh
rm -rf /var/www/html/wp-content/uploads/* /var/www/html/wp-content/fabrement/* /var/www/html/wp-content/debug.log

wp db drop --yes >/dev/null 2>&1 || true
wp db create >/dev/null
wp core install --url="$url" --title="Fabrement $site site" --admin_user=admin --admin_password=admin \
    --admin_email=admin@example.org --skip-email >/dev/null
wp option update timezone_string 'UTC' >/dev/null
wp theme activate twentytwentyfive >/dev/null 2>&1 || true
wp rewrite structure '/%postname%/' >/dev/null

wp plugin activate contact-form-7 wordpress-seo fabrement >/dev/null
if [ "$site" = "wpml" ]; then
    wp plugin activate sitepress-multilingual-cms wpml-string-translation wp-seo-multilingual >/dev/null
    # What WPML's setup wizard does, in wp-admin (WPML picks its admin classes as it loads).
    runuser -u www-data -- php /var/www/setup/wpml.php
fi
wp rewrite flush >/dev/null

password=$(wp user application-password create admin fabrement-tests --porcelain)
sh /var/www/setup/snapshot.sh take
echo "$password"
