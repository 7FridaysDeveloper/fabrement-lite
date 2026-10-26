#!/bin/sh
# Copies the code into the site's own volume — WordPress core, the themes, the plugins — from the
# read-only /src mounts: only what changed since the last run (rsync), what was removed goes too.
# wp-content's own data (uploads, fabrement/, debug.log) is left alone. Run before every test run.
set -e
rsync -a --delete --exclude=/wp-content/ /src/core/ /var/www/html/
mkdir -p /var/www/html/wp-content/plugins /var/www/html/wp-content/themes
rsync -a --delete /src/themes/ /var/www/html/wp-content/themes/
for dir in /src/plugins/*/; do
    name=$(basename "$dir")
    rsync -a --delete \
        --exclude=node_modules/ --exclude=.git/ --exclude=/tests/ --exclude=/dist/ --exclude=/docs/ --exclude=/.build-*/ --exclude=/tools/ \
        "$dir" "/var/www/html/wp-content/plugins/$name/"
done
# What WordPress writes itself belongs to the web server's user, as on a host.
chown www-data:www-data /var/www/html/wp-content
for dir in uploads fabrement upgrade languages mu-plugins; do
    mkdir -p "/var/www/html/wp-content/$dir"
    chown -R www-data:www-data "/var/www/html/wp-content/$dir"
done
