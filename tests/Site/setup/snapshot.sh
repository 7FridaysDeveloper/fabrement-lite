#!/bin/sh
# The state every test class starts from: the site's database and its wp-content data, as they were
# right after install.sh.
#
#   sh /var/www/setup/snapshot.sh take      after install
#   sh /var/www/setup/snapshot.sh restore   before a test class (tests/Site/Support/SiteTestCase.php)
#
# The database is dumped to a file in the container and loaded back whole: every table a test
# created goes, every row it wrote goes — including what a COMMIT or another plugin wrote.
set -e
db=$(php -r 'foreach ( file( "/var/www/wp-config.php" ) as $l ) { if ( preg_match( "/DB_NAME.,\s*.([a-z_]+)/", $l, $m ) ) { echo $m[1]; } }')
dump=/var/www/snapshot/database.sql
data=/var/www/snapshot/wp-content
mysql="mysql -hmysql -uroot -proot --skip-ssl"

case "$1" in
    take)
        mkdir -p /var/www/snapshot
        mysqldump -hmysql -uroot -proot --skip-ssl --single-transaction --add-drop-table "$db" > "$dump" 2>/dev/null
        rm -rf "$data" && mkdir -p "$data"
        for dir in uploads fabrement languages mu-plugins; do
            cp -a "/var/www/html/wp-content/$dir" "$data/$dir"
        done
        ;;
    restore)
        $mysql -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\`" 2>/dev/null
        $mysql "$db" < "$dump" 2>/dev/null
        for dir in uploads fabrement languages mu-plugins; do
            rm -rf "/var/www/html/wp-content/$dir"
            cp -a "$data/$dir" "/var/www/html/wp-content/$dir"
        done
        rm -f /var/www/html/wp-content/debug.log
        ;;
    *)
        echo "usage: snapshot.sh take|restore" >&2
        exit 1
        ;;
esac
