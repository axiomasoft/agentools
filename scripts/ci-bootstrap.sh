#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
composer install --working-dir=php --no-interaction --prefer-dist
composer install --working-dir=conformance --no-interaction --prefer-dist
(cd dart/agentools && dart pub get)
(cd dart/agentools_mcp_dart && dart pub get)
npm ci --ignore-scripts --prefix conformance/mcp
conformance/mcp/node_modules/.bin/conformance --version
