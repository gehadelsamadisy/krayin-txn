#!/usr/bin/env bash
# The safe update procedure: same as ./scripts/update-krayin.sh <version>.
#
#   ./scripts/update.sh 2.2.1
#
# Back up first -> pin the new version -> merge the TXN edits -> test on a
# copy of the database -> recreate only the Krayin containers (MySQL keeps
# running) -> migrate -> verify. See README "How updates work".
exec "$(dirname "$0")/update-krayin.sh" "$@"
