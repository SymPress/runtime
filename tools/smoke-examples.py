#!/usr/bin/env python3
"""Install the documented site manifest and execute the custom-step example."""
import argparse
import json
import shutil
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--php", default="php")
    parser.add_argument("--composer", default="composer")
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix="runtime-doc-example-") as temporary:
        project = Path(temporary)
        manifest = json.loads((ROOT / "examples/site/composer.json").read_text())
        # Exercise this checkout while leaving the documented project settings intact.
        manifest["repositories"][0] = {
            "type": "path", "url": str(ROOT),
            "options": {"symlink": False, "versions": {"sympress/runtime": manifest["require"]["sympress/runtime"]}},
        }
        (project / "composer.json").write_text(json.dumps(manifest, indent=2))
        def run(command):
            subprocess.run(command, cwd=project, check=True)
        run([args.composer, "install", "--no-interaction", "--no-progress"])
        binary = [args.php, str(project / "vendor/bin/runtime")]
        run(binary + ["validate"])
        run(binary + ["--list-steps"])
        run(binary + ["--no-interaction"])
        for file in ["wp-config.php", "wp-cli.yml", "public/index.php", "public/wp/wp-settings.php"]:
            if not (project / file).is_file():
                raise RuntimeError("Missing documented artifact: " + file)
        (project / "build-scripts").mkdir()
        shutil.copyfile(ROOT / "examples/WriteBuildMarker.php", project / "build-scripts/WriteBuildMarker.php")
        manifest["autoload"] = {"psr-4": {"Example\\Build\\": "build-scripts/"}}
        manifest["extra"]["sympress-runtime"]["command-steps"] = {"buildmarker": "Example\\Build\\WriteBuildMarker"}
        (project / "composer.json").write_text(json.dumps(manifest, indent=2))
        run([args.composer, "dump-autoload", "--no-interaction"])
        run(binary + ["validate"])
        run(binary + ["--no-interaction", "buildmarker"])
        if (project / "var/build-marker.txt").read_text() != "ready\n":
            raise RuntimeError("The documented custom step did not write its marker.")
        print("PASS: documented site installation, repeated setup and custom step.")


if __name__ == "__main__":
    main()
