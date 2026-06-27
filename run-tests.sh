#!/bin/sh
#
# Run this module's PHPUnit tests. Must be executed inside a REDCap environment
# (e.g. the redcap-docker-compose web container) where php + redcap_connect.php
# are available.
#
# From the host (redcap-docker-compose), run:
#   cd rdc && docker compose exec web sh /var/www/html/modules/timezone_scheduler_v0.0.0/run-tests.sh
#
# Or from inside the web container:
#   sh /var/www/html/modules/<this-module>/run-tests.sh
#
set -e

DIR=$(cd "$(dirname "$0")" && pwd)

# Locate the External Modules framework bundled in the active redcap_vX.Y.Z release
EM_PATH=$(ls -d "$DIR"/../../redcap_v*/ExternalModules 2>/dev/null | head -1)
if [ -z "$EM_PATH" ]; then
    echo "ERROR: could not locate redcap_v*/ExternalModules above $DIR" >&2
    exit 1
fi

# get-phpunit-path.php resolves the phpunit binary the framework was tested against
PHPUNIT=$(php "$EM_PATH/bin/get-phpunit-path.php")

php "$PHPUNIT" --no-configuration --testdox "$DIR/tests"
