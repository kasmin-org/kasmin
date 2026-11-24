#!/bin/sh
# Hook of uacme: method type identifier token authorisation
[ "$2" = http-01 ] || exit 1
dir=/var/lib/kasmin/challenge
case "$4" in *[!A-Za-z0-9_-]*|"") exit 1 ;; esac
case "$1" in
  begin) printf '%s' "$5" > "$dir/$4" && chmod 644 "$dir/$4" ;;
  done|failed) rm -f -- "$dir/$4" ;;
  *) exit 1 ;;
esac
