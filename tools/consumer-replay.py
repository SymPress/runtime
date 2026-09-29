#!/usr/bin/env python3
"""Check real DDEV consumers without displaying credentials or command output."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess


def snapshot(project):
    result = {}
    extra = json.loads((project / "composer.json").read_text()).get("extra", {})
    targets = ["wp-config.php", "wp-cli.yml", "public/index.php", extra.get("wordpress-content-dir", "wp-content"), "var/runtime", "packages/sympress-demo/assets", "packages/sympress-demo/.sympress_asset_compiler.lock"]
    for name in targets:
        target = project / name
        paths = [target, *target.rglob("*")] if target.is_dir() and not target.is_symlink() else [target]
        for path in paths:
            relative = path.relative_to(project).as_posix()
            if "/uploads/" in relative or "/cache/" in relative:
                continue
            if path.is_symlink():
                result[relative] = "link:" + os.readlink(path)
            elif path.is_file():
                result[relative] = hashlib.sha256(path.read_bytes()).hexdigest()
    return result


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, default=Path("build/consumer-smoke/replay-report.json"))
    parser.add_argument("--disable-all-plugins", action="store_true", help="Also disable third-party installers; requires their installation layout to remain valid.")
    parser.add_argument("projects", nargs="+", type=Path)
    args = parser.parse_args()
    reports = []
    for project in args.projects:
        project = project.resolve()
        baseline = snapshot(project)
        assert "wp-config.php" in baseline, "Consumer must already be installed."
        report = {"project": project.name, "artifact_count": len(baseline), "runs": []}
        commands = [
            ["ddev", "composer", "install", "--no-interaction", "--no-progress"],
            ["ddev", "composer", "install", "--no-interaction", "--no-progress"],
            ["ddev", "exec", "php", "vendor/bin/sympress-runtime", "--no-interaction"],
        ]
        if args.disable_all_plugins:
            commands.insert(2, ["ddev", "composer", "install", "--no-plugins", "--no-interaction", "--no-progress"])
        for command in commands:
            process = subprocess.run(command, cwd=project, text=True, capture_output=True, timeout=300)
            if process.returncode != 0:
                raise RuntimeError(f"{project.name}: {command[:3]} exited {process.returncode}; output withheld because setup can contain credentials.")
            after = snapshot(project)
            changed = sorted(name for name in baseline.keys() | after.keys() if baseline.get(name) != after.get(name))
            if changed:
                raise RuntimeError(f"{project.name}: {command[:3]} changed generated artifacts: {changed}")
            report["runs"].append({"command": command, "exit": 0, "changed_artifacts": []})
        reports.append(report)
        print(f"PASS {project.name}: {len(baseline)} artifact hashes/links stable across repeated Composer and standalone setup.", flush=True)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(reports, indent=2) + "\n")


if __name__ == "__main__":
    main()
