#!/usr/bin/env bash
#
# Run a PHP or Composer command in the project's PHP image.
#
# There is no official GitHub action for PHP — actions/setup-php does not exist —
# so CI used shivammathur/setup-php, which is a third party in the trust path that
# is also handed the job's GITHUB_TOKEN by default, through an input nobody here
# asked for. Both official Docker images exist (docker-library/php and
# composer/docker), so neither is necessary.
#
# Composer's own image ships a much newer PHP than the floor this plugin supports,
# so its binary is lifted out and run under the pinned PHP instead. That way the
# analysis runs on the oldest PHP we claim to support rather than the newest PHP
# some tool happened to bundle.
#
# The same script backs CI and local use, so "works on my machine" and "passes CI"
# are the same statement:
#
#   ./bin/php.sh composer install
#   ./bin/php.sh composer run lint:php
#   ./bin/php.sh php -l screenly-cast/screenly-cast.php

set -euo pipefail

# Floating within the 8.2 patch line is deliberate: 8.2 is the support floor, and
# picking up its security releases is the point. Override to check another version.
PHP_IMAGE="${SRLY_PHP_IMAGE:-php:8.2-cli}"
COMPOSER_IMAGE="${SRLY_COMPOSER_IMAGE:-composer:2}"

if ! command -v docker >/dev/null 2>&1; then
  echo "error: docker is not installed, and this runs PHP in a container" >&2
  echo "       on Debian or Ubuntu: sudo apt-get install -y docker.io" >&2
  exit 1
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CACHE="${SRLY_PHP_CACHE:-${TMPDIR:-/tmp}/srly-php}"
COMPOSER_BIN="$CACHE/composer"

mkdir -p "$CACHE"

# Extracted once and reused. `docker cp` from a created-but-never-started container
# is how you get a file out of an image without running it.
if [ ! -x "$COMPOSER_BIN" ]; then
  cid="$(docker create "$COMPOSER_IMAGE")"
  docker cp "$cid":/usr/bin/composer "$COMPOSER_BIN"
  docker rm -v "$cid" >/dev/null
  chmod +x "$COMPOSER_BIN"
fi

# Composer warns on every invocation when it cannot work out the root package
# version, and five lines of noise per step is how a real warning gets missed. The
# version lives in package.json like everything else.
root_version="$(sed -n 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' \
  "$ROOT/package.json" | head -1)"

# --user keeps anything written to the mount — vendor/, caches — owned by the
# caller rather than by root, which later steps outside the container depend on.
exec docker run --rm -i \
  --user "$(id -u):$(id -g)" \
  --env COMPOSER_HOME=/tmp/composer \
  --env COMPOSER_CACHE_DIR=/tmp/composer/cache \
  --env COMPOSER_ROOT_VERSION="$root_version" \
  --volume "$ROOT":/app \
  --volume "$COMPOSER_BIN":/usr/local/bin/composer:ro \
  --workdir /app \
  "$PHP_IMAGE" "$@"
