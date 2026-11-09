#!/usr/bin/env bash
# The version gate release.yml runs. Client::VERSION is the manifest: Packagist
# takes a release's version from its git tag, so composer.json must not state
# one and the tag is cut from this constant.
#
#   version      print the manifest's version
#   consistency  fail if anything else could declare a different version
#   check        print version= and release=true|false for $GITHUB_OUTPUT
set -euo pipefail

die() {
  echo "release-version: $*" >&2
  exit 1
}

# Only a clear yes or no is an answer. An outage has to fail the job, because
# reading it as "not published" would try to release a version that exists.
lookup() {
  local out
  out=$(curl --silent --show-error --location --max-time 30 --retry 3 --retry-all-errors \
    --write-out '\n%{http_code}' "$1") || die "could not reach $1"
  status=${out##*$'\n'}
  body=${out%$'\n'*}
}

on_registry() {
  lookup "https://repo.packagist.org/p2/fingerly/fingerly-php.json"
  case "$status" in
    200) has_version ;;
    404) return 1 ;;
    *) die "Packagist answered $status" ;;
  esac
}

# jq exits 1 for "false" and something else for a body it cannot read, which
# must not pass for "this version is new".
has_version() {
  local rc=0
  jq -e --arg v "$version" 'any(.packages[][]; .version == $v or .version == "v" + $v)' <<<"$body" >/dev/null || rc=$?
  case "$rc" in
    0) return 0 ;;
    1) return 1 ;;
    *) die "unreadable answer from Packagist" ;;
  esac
}

tag_exists() {
  local rc=0
  git ls-remote --exit-code --tags origin "refs/tags/v$version" >/dev/null || rc=$?
  case "$rc" in
    0) return 0 ;;
    2) return 1 ;;
    *) die "git ls-remote failed with $rc" ;;
  esac
}

version=$(sed -n "s/.*public const VERSION = '\([^']*\)';.*/\1/p" src/Client.php)
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "no X.Y.Z version in the manifest: '$version'"

case "${1:-}" in
  version)
    echo "$version"
    ;;
  consistency)
    jq -e 'has("version") | not' composer.json >/dev/null || die "composer.json must not state a version"
    ;;
  check)
    echo "version=$version"
    if tag_exists; then
      echo "v$version is already tagged" >&2
      echo "release=false"
    elif on_registry; then
      echo "$version is already on Packagist" >&2
      echo "release=false"
    else
      echo "release=true"
    fi
    ;;
  *)
    die "usage: release-version.sh version|consistency|check"
    ;;
esac
