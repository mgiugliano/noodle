#!/bin/sh
# Builds the Noodle CLI into build/noodle (embeds Info.plist so macOS can show the Calendar permission prompt).
# If an "Apple Development" code-signing identity exists, the program is signed with it. That gives it a stable
# identity across rebuilds, so macOS Keychain/Calendar approvals ("Always Allow") survive a rebuild.
set -e
cd "$(dirname "$0")"
mkdir -p build
swiftc -O client/main.swift client/Slots.swift client/CalendarAccess.swift client/Commands.swift client/Manage.swift \
  -Xlinker -sectcreate -Xlinker __TEXT -Xlinker __info_plist -Xlinker client/Info.plist \
  -o build/noodle

IDENTITY=$(security find-identity -v -p codesigning 2>/dev/null | sed -n 's/.*"\(Apple Development:[^"]*\)".*/\1/p' | head -1)
if [ -n "$IDENTITY" ]; then
  # alarm guards against a hidden key-access dialog blocking the build forever
  if perl -e 'alarm 60; exec @ARGV' codesign --force --sign "$IDENTITY" --identifier com.example.noodle build/noodle 2>/dev/null; then
    echo "built build/noodle (signed: $IDENTITY)"
    exit 0
  fi
  echo "warning: signing with \"$IDENTITY\" failed; keeping the ad-hoc signature" >&2
fi
echo "built build/noodle (ad-hoc signature: macOS will ask again for Keychain access after each rebuild)"
