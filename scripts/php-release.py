"""Resolve the pinned baseline or the announced October 2026 PHP 8.2 security release."""

import datetime
import json
import os
from pathlib import Path
import re
import sys
import urllib.request


def security_release(releases):
    candidates = []
    for version, release in releases.items():
        if not re.fullmatch(r"8\.2\.\d+", version):
            continue
        date = datetime.datetime.strptime(release["date"], "%d %b %Y").date()
        if "security" in release.get("tags", []) and date >= datetime.date(2026, 10, 21):
            candidates.append(version)
    return max(candidates, key=lambda value: tuple(map(int, value.split("."))), default="")


def main():
    baseline = re.search(r"^ARG PHP_VERSION=(8\.2\.\d+)$", Path("Dockerfile").read_text(), re.M)[1]
    if os.environ.get("EVENT_NAME") == "schedule":
        today = datetime.datetime.now(datetime.timezone.utc).date()
        if not datetime.date(2026, 10, 21) <= today <= datetime.date(2026, 10, 23):
            print("Security release watch has expired.", file=sys.stderr)
            return
    # Resolve on push/PR too, so a later source deployment cannot restore the baseline
    # after the security watcher has deployed a newer runtime.
    url = "https://www.php.net/releases/index.php?json&version=8.2&max=5"
    with urllib.request.urlopen(url, timeout=30) as response:
        version = security_release(json.load(response))
    if not version and os.environ.get("EVENT_NAME") == "schedule":
        print("Announced security release is not available yet.", file=sys.stderr)
        return
    version = max([baseline, version or baseline], key=lambda value: tuple(map(int, value.split("."))))
    print(f"version={version}")


if __name__ == "__main__":
    main()
