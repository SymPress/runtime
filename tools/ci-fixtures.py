#!/usr/bin/env python3
"""Fetch pinned, integrity-checked fixtures for the mandatory integration suite."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import tarfile
import tempfile
import urllib.request


FIXTURES = {
    "wordpress.tar.gz": (
        "https://wordpress.org/wordpress-7.1.1.tar.gz",
        "3996fee13448ef12e07e9f0c77db2f655ffa1b7cde71c80a4965d3bf1fb956b3",
    ),
    "wp-cli.phar": (
        "https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar",
        "ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c",
    ),
}


def download(url, target, expected):
    digest = hashlib.sha256()
    total = 0
    with urllib.request.urlopen(url, timeout=60) as response, target.open("wb") as output:
        while chunk := response.read(1024 * 1024):
            total += len(chunk)
            if total > 64 * 1024 * 1024:
                raise RuntimeError("CI fixture exceeds the 64 MiB archive limit")
            digest.update(chunk)
            output.write(chunk)
    if digest.hexdigest() != expected:
        raise RuntimeError("CI fixture checksum mismatch: " + target.name)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--directory", default="build/ci-fixtures")
    args = parser.parse_args()
    destination = Path(args.directory).resolve()
    if destination.exists():
        raise RuntimeError("Use a fresh fixture directory; existing files are not replaced")
    destination.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix=".ci-fixtures-", dir=destination.parent) as temporary:
        stage = Path(temporary)
        for filename, (url, digest) in FIXTURES.items():
            download(url, stage / filename, digest)
        with tarfile.open(stage / "wordpress.tar.gz") as archive:
            archive.extractall(stage, filter="data")
        (stage / "wordpress.tar.gz").unlink()
        if not (stage / "wordpress/wp-settings.php").is_file():
            raise RuntimeError("WordPress fixture is incomplete")
        (stage / "manifest.json").write_text(json.dumps(FIXTURES, indent=2) + "\n")
        stage.rename(destination)
    variables = {
        "RUNTIME_TEST_WORDPRESS_DIR": str(destination / "wordpress"),
        "RUNTIME_TEST_WPCLI_BOOTSTRAP": str(destination / "wp-cli.phar"),
    }
    if env_file := os.environ.get("GITHUB_ENV"):
        with open(env_file, "a", encoding="utf-8") as output:
            for name, value in variables.items():
                output.write(f"{name}={value}\n")
    print(json.dumps(variables))


if __name__ == "__main__":
    main()
