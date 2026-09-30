#!/usr/bin/env python3
"""Exercise repository packages and custom WordPress installer paths in isolation."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]


def run(command, project):
    process = subprocess.run(command, cwd=project, env={key: value for key, value in os.environ.items() if key not in {"COMPOSER", "COMPOSER_VENDOR_DIR"}}, text=True, capture_output=True, timeout=300)
    if process.returncode:
        raise RuntimeError(f"Command exited {process.returncode}: {command[:3]}\n{process.stderr[-2500:]}")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--php", default="php")
    parser.add_argument("--composer", required=True)
    parser.add_argument("--composer-library", help="Pin and verify the recovery library independently of the Composer CLI")
    parser.add_argument("--mode", choices=["normal", "no-plugins", "all"], default="all")
    args = parser.parse_args()
    cli_version = subprocess.check_output([args.php, args.composer, '--version', '--no-ansi'], text=True).strip()
    print(f"Composer CLI: {cli_version}; requested recovery library: {args.composer_library or 'resolved'}", flush=True)
    report = []
    for mode in (["normal", "no-plugins"] if args.mode == "all" else [args.mode]):
        with tempfile.TemporaryDirectory(prefix="runtime-wpackagist-") as directory:
            project = Path(directory)
            manifest = {
                "name": "sympress-fixtures/wpackagist-acceptance",
                "repositories": [
                    {"type": "path", "url": str(ROOT), "options": {"symlink": True, "versions": {"sympress/runtime": "dev-fixture"}}},
                    {"type": "composer", "url": "https://wpackagist.org", "only": ["wpackagist-plugin/*", "wpackagist-theme/*"]},
                ],
                "require": {"sympress/runtime": "dev-fixture", "johnpbloch/wordpress": "^7.0", "wpackagist-plugin/classic-editor": "^1.6", "wpackagist-theme/twentytwentyfive": "^1.5"},
                "config": {"classmap-authoritative": True, "allow-plugins": {"sympress/runtime": True, "composer/installers": True, "johnpbloch/wordpress-core-installer": True}},
                "autoload": {"files": ["autoload-probe.php"]},
                "scripts": {"pre-autoload-dump": "@php composer-hook.php"},
                "extra": {
                    "wordpress-install-dir": "public/wp",
                    "wordpress-content-dir": "public/content",
                    "installer-paths": {"public/content/plugins/{$name}/": ["type:wordpress-plugin"], "public/content/themes/{$name}/": ["type:wordpress-theme"]},
                    "sympress-runtime": {"db-check": False, "require-wp": False, "install-wp-cli": False, "env-example": False},
                },
            }
            if args.composer_library:
                manifest['require']['composer/composer'] = args.composer_library
            (project / "composer.json").write_text(json.dumps(manifest, indent=2) + "\n")
            (project / "autoload-probe.php").write_text('<?php if (!is_file(__DIR__ . "/public/content/plugins/classic-editor/classic-editor.php")) { throw new RuntimeException("Project autoload ran before layout recovery"); } file_put_contents(__DIR__ . "/autoload-count", "1", FILE_APPEND);')
            (project / "composer-hook.php").write_text('<?php file_put_contents(__DIR__ . "/hook-count", "1", FILE_APPEND);')
            command = [args.php, args.composer, "install", "--no-interaction", "--no-progress"]
            if mode == "no-plugins":
                command.append("--no-plugins")
            run(command, project)
            hooks = (project / "hook-count").read_text()
            if mode == "no-plugins":
                run([args.php, str(project / "vendor/bin/runtime"), "--no-interaction"], project)
            paths = {"core": "public/wp/wp-settings.php", "plugin": "public/content/plugins/classic-editor/classic-editor.php", "theme": "public/content/themes/twentytwentyfive/style.css", "configuration": "wp-config.php"}
            hashes = {name: hashlib.sha256((project / path).read_bytes()).hexdigest() for name, path in paths.items()}
            installed = json.loads((project / "vendor/composer/installed.json").read_text())["packages"]
            library_package = next(package for package in installed if package['name'] == 'composer/composer')
            library = library_package['version']
            if args.composer_library:
                version, _, reference = args.composer_library.split(' as ', 1)[0].partition('#')
                assert library.lstrip('v') == version, (library, version)
                if reference:
                    assert library_package['source']['reference'] == reference
            packages = {package["name"]: {"version": package["version"], "type": package["type"], "path": package["install-path"]} for package in installed if package["name"].startswith("wpackagist-")}
            assert len(packages) == 2
            assert not any(package["name"].startswith("wecodemore/") for package in installed)
            count = (project / "autoload-count").read_text()
            run([args.php, str(project / "vendor/bin/runtime"), "--no-interaction"], project)
            assert (project / "autoload-count").read_text() == count + "1"
            assert (project / "hook-count").read_text() == hooks
            assert hashes == {name: hashlib.sha256((project / path).read_bytes()).hexdigest() for name, path in paths.items()}
            report.append({"mode": mode, "composer_cli": cli_version, "composer_library": library, "composer_source": library_package.get('source'), "packages": packages, "paths": paths, "standalone_replay_unchanged": True})
            print(f"PASS {mode}: real WPackagist plugin/theme and Composer WordPress core at configured paths.", flush=True)
    output = ROOT / "build/consumer-smoke/wpackagist-report.json"
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2) + "\n")


if __name__ == "__main__":
    main()
