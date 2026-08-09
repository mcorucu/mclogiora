#!/usr/bin/env bash
# Installs the WordPress PHPUnit test suite and a test database.
#
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
#
# Downloads only from wordpress.org and github.com. No plugin code ever calls
# out to a network; this is development tooling and runs outside the plugin.

set -euo pipefail

DB_NAME=${1-wordpress_test}
DB_USER=${2-root}
DB_PASS=${3-root}
DB_HOST=${4-127.0.0.1}
WP_VERSION=${5-latest}

WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress}

download() {
	if command -v curl >/dev/null 2>&1; then
		curl -s -o "$2" "$1"
	else
		wget -nv -O "$2" "$1"
	fi
}

if [ "$WP_VERSION" = "latest" ]; then
	download https://api.wordpress.org/core/version-check/1.7/ /tmp/wp-latest.json
	WP_VERSION=$(grep -o '"version":"[^"]*' /tmp/wp-latest.json | head -1 | cut -d'"' -f4)
fi

echo "Installing WordPress ${WP_VERSION}"

install_wp() {
	if [ -d "$WP_CORE_DIR" ]; then
		return
	fi

	mkdir -p "$WP_CORE_DIR"
	download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" /tmp/wordpress.tar.gz
	tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
	download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php "$WP_CORE_DIR/wp-content/db.php"
}

install_test_suite() {
	if [ -d "$WP_TESTS_DIR" ]; then
		return
	fi

	mkdir -p "$WP_TESTS_DIR"
	svn co --quiet "https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes" \
		|| svn co --quiet "https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
	svn co --quiet "https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/data/" "$WP_TESTS_DIR/data" \
		|| svn co --quiet "https://develop.svn.wordpress.org/trunk/tests/phpunit/data/" "$WP_TESTS_DIR/data"

	download https://develop.svn.wordpress.org/trunk/wp-tests-config-sample.php "$WP_TESTS_DIR/wp-tests-config.php"

	sed -i.bak "s:dirname( __FILE__ ) . '/src/':'${WP_CORE_DIR}/':" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i.bak "s/youremptytestdbnamehere/${DB_NAME}/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i.bak "s/yourusernamehere/${DB_USER}/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i.bak "s/yourpasswordhere/${DB_PASS}/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i.bak "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR/wp-tests-config.php"
	rm -f "$WP_TESTS_DIR/wp-tests-config.php.bak"
}

create_db() {
	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" --protocol=tcp 2>/dev/null || true
}

install_wp
install_test_suite
create_db

echo "WordPress test environment ready."
