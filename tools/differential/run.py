#!/usr/bin/env python3
"""Isolated pinned WP Starter oracles; never changes runtime dependencies."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile


ROOT = Path(__file__).resolve().parents[2]
BASELINES = {
    "release": ("191b5df74920a713a90af9f3f3c7fceaf1f74b83", "release-3.0.1"),
    "dev": ("059bbc9198ac51dee86c5233b6b006ca4d8008a4", "upstream-dev"),
}
SAMPLES = {
    "STRING": "<b>value</b>&",
    "RAW_STRING": "raw'<tag>&\\",
    "BOOL": "false",
    "INT": "12.9",
    "FLOAT": "1.25e2",
    "INT_OR_BOOL": "3",
    "STRING_OR_BOOL": "minor",
    "OCTAL_MOD": "0755",
    "null": "unfiltered",
}


def run(argv, cwd, env=None, expected_file=None):
    result = subprocess.run(argv, cwd=cwd, env=env, text=True, capture_output=True)
    if result.returncode or (expected_file is not None and not expected_file.is_file()):
        raise RuntimeError(f"Command failed ({result.returncode}): {argv[0]}\n{result.stdout[-2000:]}\n{result.stderr[-4000:]}")
    return result.stdout


def write_json(path, value):
    path.write_text(json.dumps(value, indent=2) + "\n")


def install_oracle(work, baseline, sha, args):
    source = work / (baseline + "-source")
    run(["git", "clone", "--quiet", "--no-checkout", "https://github.com/wecodemore/wpstarter.git", str(source)], work)
    run(["git", "checkout", "--quiet", sha], source)
    assert run(["git", "rev-parse", "HEAD"], source).strip() == sha
    fixture = work / (baseline + "-oracle")
    fixture.mkdir()
    write_json(fixture / "composer.json", {
        "name": "sympress-fixtures/wpstarter-oracle",
        "repositories": [{"type": "path", "url": str(source), "options": {"symlink": False, "versions": {"wecodemore/wpstarter": "dev-oracle"}}}],
        "require": {"wecodemore/wpstarter": "dev-oracle", "symfony/dotenv": "6.4.45", "composer/installers": "2.3.0"},
        "config": {"allow-plugins": False},
    })
    run([args.oracle_php, args.composer, "install", "--no-plugins", "--no-scripts", "--no-progress", "--no-interaction"], fixture)
    lock = json.loads((fixture / "composer.lock").read_text())
    return fixture / "vendor/autoload.php", {
        "source": sha,
        "lock_sha256": hashlib.sha256((fixture / "composer.lock").read_bytes()).hexdigest(),
        "packages": {p["name"]: p["version"] for p in lock["packages"]},
    }


def probe(php, autoload, case, profile):
    return json.loads(run([php, str(ROOT / "tools/differential/probe.php"), str(autoload), str(case / "input.json"), profile], case))


def compare(expected, actual, label):
    differences = [name for name in expected if expected[name] != actual.get(name)]
    if differences:
        raise RuntimeError(f"Unregistered differential mismatch in {label}: {', '.join(differences)}")


def core_fixture(root, environment):
    core = root / "public/wp"
    (core / "wp-includes").mkdir(parents=True, exist_ok=True)
    (root / "public/content").mkdir(parents=True, exist_ok=True)
    (core / "wp-includes/plugin.php").write_text("<?php function add_action(...$args) {} function add_filter(...$args) {} function has_filter(...$args) { return false; } function apply_filters($name, $value, ...$args) { return $value; }\n")
    (core / "wp-blog-header.php").write_text("<?php require dirname(__DIR__) . '/wp-config.php';\n")
    (core / "index.php").write_text("<?php require __DIR__ . '/wp-blog-header.php';\n")
    (core / "wp-settings.php").write_text("<?php $GLOBALS['boot_count'] = ($GLOBALS['boot_count'] ?? 0) + 1;\n")
    salts = ["AUTH_KEY", "SECURE_AUTH_KEY", "LOGGED_IN_KEY", "NONCE_KEY", "AUTH_SALT", "SECURE_AUTH_SALT", "LOGGED_IN_SALT", "NONCE_SALT"]
    values = {"WP_ENV": environment, "DB_NAME": "fixture", "DB_USER": "fixture", "DB_PASSWORD": "fixture", "DB_TABLE_PREFIX": "fixture_"}
    values.update({name: "synthetic-" + name.lower() for name in salts})
    (root / ".env").write_text("".join(name + "=" + json.dumps(value) + "\n" for name, value in values.items()))


def compare_generated(work, baseline, autoload, args, report):
    oracle = autoload.parent.parent
    manifest = json.loads((oracle / "composer.json").read_text())
    options = {"db-check": False, "require-wp": False, "cache-env": True, "register-theme-folder": False, "env-dir": "."}
    manifest["extra"] = {"wordpress-install-dir": "public/wp", "wordpress-content-dir": "public/content", "wpstarter": options}
    manifest["config"]["allow-plugins"] = {"wecodemore/wpstarter": True, "composer/installers": False}
    if baseline == "dev":
        manifest["extra"]["wpstarter"] = {name: value for name, value in options.items() if name != "env-dir"}
        write_json(oracle / "composer.json", manifest)
        core_fixture(oracle, "production")
        failure = subprocess.run([args.oracle_php, args.composer, "--no-interaction", "wpstarter", "wpconfig", "index"], cwd=oracle, text=True, capture_output=True)
        message = failure.stdout + failure.stderr
        if failure.returncode != 0 or "Folder name must be in a string." not in message or (oracle / "public/index.php").exists():
            raise RuntimeError("The pinned dev env-dir default failure no longer matches its exact fixture.")
        candidate = work / "dev-default-env-dir"
        candidate.mkdir()
        core_fixture(candidate, "production")
        write_json(candidate / "composer.json", {"extra": {"wordpress-install-dir": "public/wp", "wordpress-content-dir": "public/content", "sympress-runtime": manifest["extra"]["wpstarter"]}})
        runtime_env = dict(os.environ, COMPOSER_VENDOR_DIR=str(ROOT / "vendor"))
        runtime_env.pop("COMPOSER", None)
        run([args.candidate_php, str(ROOT / "bin/sympress-runtime"), "--no-interaction", "wpconfig", "index"], candidate, runtime_env, expected_file=candidate / "public/index.php")
        result = json.loads(run([args.candidate_php, str(ROOT / "tools/differential/boot.php"), str(candidate)], candidate))
        if result["boots"] != 1 or result["DB_NAME"]["value"] != "fixture":
            raise RuntimeError("Candidate default env-dir must resolve to a working root environment.")
        report["cases"].append({"id": "dev/default-env-dir", "differences": [{"id": "D23", "oracle": {"exit": 0, "index": False, "error": "Folder name must be in a string."}, "candidate": {"exit": 0, "index": True, "boots": 1}}]})
        manifest["extra"]["wpstarter"] = options
        print("PASS dev/default-env-dir: pinned upstream defect and working candidate", flush=True)
    write_json(oracle / "composer.json", manifest)
    for environment in ["local", "development", "staging", "production"]:
        core_fixture(oracle, environment)
        steps = ["build-wp-config", "build-index"] if baseline == "release" else ["wpconfig", "index"]
        run([args.oracle_php, args.composer, "--no-interaction", "--verbose", "wpstarter", *steps], oracle, expected_file=oracle / "public/index.php")
        candidate = work / (baseline + "-generated-" + environment)
        candidate.mkdir()
        core_fixture(candidate, environment)
        write_json(candidate / "composer.json", {"extra": {"wordpress-install-dir": "public/wp", "wordpress-content-dir": "public/content", "sympress-runtime": options}})
        runtime_env = dict(os.environ, COMPOSER_VENDOR_DIR=str(ROOT / "vendor"))
        runtime_env.pop("COMPOSER", None)
        run([args.candidate_php, str(ROOT / "bin/sympress-runtime"), "--no-interaction", "wpconfig", "index"], candidate, runtime_env)
        expected = json.loads(run([args.oracle_php, str(ROOT / "tools/differential/boot.php"), str(oracle)], oracle))
        actual = json.loads(run([args.candidate_php, str(ROOT / "tools/differential/boot.php"), str(candidate)], candidate))
        if expected["composer_loaded"] is not True or actual["composer_loaded"] is not False:
            raise RuntimeError("D13 must demonstrate the oracle Composer dependency and candidate independence.")
        expected["composer_loaded"] = False
        compare(expected, actual, baseline + "/generated/" + environment)
        report["cases"].append({"id": baseline + "/generated/" + environment, "constants": len(actual) - 3, "differences": [{"id": "D13", "field": "composer_loaded", "oracle": True, "candidate": False}]})
        print(f"PASS {baseline}/generated/{environment}: index, wp-config runtime and D13 independence", flush=True)
        for project in [oracle, candidate]:
            cached = (project / ".env.cached.php").is_file()
            if cached != (environment != "local"):
                raise RuntimeError(f"Unexpected cache policy for {baseline}/{environment}")
        if environment != "production":
            (oracle / ".env.cached.php").unlink(missing_ok=True)
            continue
        for project in [oracle, candidate]:
            (project / ".env").write_text("malformed fixture: cache must bypass parsing")
        for mode in ["warm", "real-override"]:
            boot_env = dict(os.environ)
            if mode == "real-override":
                boot_env["DB_PASSWORD"] = "external-fixture"
            upstream = json.loads(run([args.oracle_php, str(ROOT / "tools/differential/boot.php"), str(oracle)], oracle, boot_env))
            cached = json.loads(run([args.candidate_php, str(ROOT / "tools/differential/boot.php"), str(candidate)], candidate, boot_env))
            expected_warm = dict(expected, composer_loaded=True)
            compare(expected_warm, upstream, baseline + "/cache/" + mode + "/oracle")
            expected_candidate = json.loads(json.dumps(expected))
            differences = [{"id": "D13", "field": "composer_loaded", "oracle": True, "candidate": False}]
            if mode == "real-override":
                before = expected_candidate["DB_PASSWORD"]["value"]
                expected_candidate["DB_PASSWORD"]["value"] = "external-fixture"
                differences.append({"id": "D06", "field": "DB_PASSWORD.value", "oracle": before, "candidate": "external-fixture"})
            compare(expected_candidate, cached, baseline + "/cache/" + mode + "/candidate")
            report["cases"].append({"id": baseline + "/cache/" + mode, "constants": len(cached) - 3, "differences": differences})
            print(f"PASS {baseline}/cache/{mode}: exact runtime reports", flush=True)


def compare_steps(work, baseline, autoload, args, report):
    oracle = autoload.parent.parent
    manifest = json.loads((oracle / "composer.json").read_text())
    manifest["config"]["allow-plugins"] = {"wecodemore/wpstarter": True, "composer/installers": False}
    runtime_env = dict(os.environ, COMPOSER_VENDOR_DIR=str(ROOT / "vendor"))
    runtime_env.pop("COMPOSER", None)

    def setup(label, options, files):
        candidate = work / (baseline + "-steps-" + label)
        candidate.mkdir()
        for project in [oracle, candidate]:
            shutil.rmtree(project / "public", ignore_errors=True)
            core_fixture(project, "development")
            for name, content in files.items():
                destination = project / name
                destination.parent.mkdir(parents=True, exist_ok=True)
                destination.write_text(content)
        options = dict({"db-check": False, "require-wp": False, "env-dir": ".", "register-theme-folder": False}, **options)
        manifest["extra"] = {"wordpress-install-dir": "public/wp", "wordpress-content-dir": "public/content", "wpstarter": options}
        write_json(oracle / "composer.json", manifest)
        write_json(candidate / "composer.json", {"extra": {"wordpress-install-dir": "public/wp", "wordpress-content-dir": "public/content", "sympress-runtime": options}})
        return candidate

    def execute(candidate, old_steps, new_steps):
        run([args.oracle_php, args.composer, "--no-interaction", "wpstarter", *old_steps], oracle)
        run([args.candidate_php, str(ROOT / "bin/sympress-runtime"), "--no-interaction", *new_steps], candidate, runtime_env)

    def snapshot(project):
        result = {}
        for path in sorted((project / "public/content").rglob("*")):
            name = path.relative_to(project / "public/content").as_posix()
            if path.is_symlink():
                result[name] = {"kind": "link", "target": os.path.relpath(path.resolve(), project)}
            elif path.is_file():
                result[name] = {"kind": "file", "sha256": hashlib.sha256(path.read_bytes()).hexdigest()}
            else:
                result[name] = {"kind": "directory"}
        return result

    dropins = ["advanced-cache.php", "db.php", "db-error.php", "install.php", "maintenance.php", "object-cache.php", "sunrise.php", "blog-deleted.php", "blog-inactive.php", "blog-suspended.php"]
    files = {"sources/" + name: "<?php // synthetic " + name + "\n" for name in dropins}
    files.update({"content-dev/plugins/shop/main.php": "<?php // plugin\n", "content-dev/themes/theme/style.css": "/* theme */\n", "content-dev/mu-plugins/mu.php": "<?php // mu\n", "content-dev/languages/de_DE.mo": "synthetic translation", "content-dev/plugins/.hidden": "must not publish"})
    for operation in ["copy", "symlink"]:
        candidate = setup("publish-" + operation, {"dropins": {name: "sources/" + name for name in dropins}, "dropins-op": operation, "content-dev-dir": "content-dev", "content-dev-op": operation}, files)
        execute(candidate, ["dropins", "publish-content-dev" if baseline == "release" else "publishcontentdev"], ["dropins", "publishcontentdev"])
        expected = snapshot(oracle)
        actual = snapshot(candidate)
        differences = []
        if baseline == "release" and operation == "symlink":
            for name in dropins:
                before = {"kind": "file", "sha256": hashlib.sha256(files["sources/" + name].encode()).hexdigest()}
                after = {"kind": "link", "target": "sources/" + name}
                if expected.get(name) != before or actual.get(name) != after:
                    raise RuntimeError("Release copy-only dropins did not match the exact D02 fixture.")
                differences.append({"id": "D02", "path": name, "oracle": before, "candidate": after})
                expected[name] = after
        if baseline == "release" and operation == "copy":
            hidden = {"kind": "file", "sha256": hashlib.sha256(b"must not publish").hexdigest()}
            if expected.get("plugins/.hidden") != hidden or "plugins/.hidden" in actual:
                raise RuntimeError("Release hidden-file publication fixture did not match D02.")
            differences.append({"id": "D02", "path": "plugins/.hidden", "oracle": hidden, "candidate": None})
            del expected["plugins/.hidden"]
            configuration = json.loads((candidate / "composer.json").read_text())
            configuration["extra"]["sympress-runtime"]["compatibility-profile"] = "release-3.0.1"
            write_json(candidate / "composer.json", configuration)
            run([args.candidate_php, str(ROOT / "bin/sympress-runtime"), "--no-interaction", "publishcontentdev"], candidate, runtime_env)
            compatible = snapshot(candidate)
            if compatible != dict(expected, **{"plugins/.hidden": hidden}):
                raise RuntimeError("Release compatibility must preserve whole-directory copy including hidden files.")
        if expected != actual or len(actual) != (20 if operation == "copy" else 18):
            mismatch = {key: [expected.get(key), actual.get(key)] for key in expected.keys() | actual.keys() if expected.get(key) != actual.get(key)}
            raise RuntimeError(f"Unregistered published file mismatch in {baseline}/{operation} ({len(expected)}/{len(actual)} entries): {mismatch}")
        report["cases"].append({"id": baseline + "/steps/publish-" + operation, "snapshot": actual, "differences": differences})
        print(f"PASS {baseline}/steps/publish-{operation}: exact file hashes and normalized link targets", flush=True)
    candidate = setup("move", {"move-content": True}, {"public/wp/wp-content/plugins/hello.php": "<?php // hello\n", "public/wp/wp-content/themes/theme/style.css": "/* theme */\n", "public/wp/wp-content/languages/de_DE.mo": "translation"})
    execute(candidate, ["move-content" if baseline == "release" else "movecontent"], ["movecontent"])
    if snapshot(oracle) != snapshot(candidate) or (oracle / "public/wp/wp-content").exists() or (candidate / "public/wp/wp-content").exists():
        raise RuntimeError("Core content move does not match the pinned oracle.")
    report["cases"].append({"id": baseline + "/steps/move", "snapshot": snapshot(candidate), "differences": []})
    print(f"PASS {baseline}/steps/move: exact file hashes and source removal", flush=True)

    fake_cli = '<?php file_put_contents(__DIR__ . "/argv.jsonl", json_encode(array_slice($argv, 1)) . "\\n", FILE_APPEND);\n'
    candidate = setup("wpcli", {"install-wp-cli": False, "wp-cli-files": [{"file": "scripts/eval.php", "args": ["alpha", "space value", "0"], "skip-wordpress": True}], "wp-cli-commands": ["wp option get 'site name'"]}, {"wp-cli.phar": fake_cli, "scripts/eval.php": "<?php // eval\n"})
    execute(candidate, ["wp-cli" if baseline == "release" else "wpcli"], ["wpcli"])
    def arguments(project):
        return [[item.replace(str(project), "<ROOT>") for item in json.loads(line)] for line in (project / "argv.jsonl").read_text().splitlines()]
    upstream, actual = arguments(oracle), arguments(candidate)
    expected_upstream = [["cli", "version", "--path=<ROOT>/public/wp"], ["eval-file", "<ROOT>/scripts/eval.php", "alpha", "space", "value", "--skip-wordpress", "--path=<ROOT>/public/wp"], ["option", "get", "site name", "--path=<ROOT>/public/wp"]]
    expected_candidate = json.loads(json.dumps(expected_upstream))
    expected_candidate[1] = ["eval-file", "<ROOT>/scripts/eval.php", "alpha", "space value", "0", "--skip-wordpress", "--path=<ROOT>/public/wp"]
    if baseline == "release":
        del expected_upstream[1]  # D17: release validator mapping never supplies eval-file descriptors.
    if upstream != expected_upstream or actual != expected_candidate:
        raise RuntimeError(f"WP-CLI argument mismatch: {upstream} / {actual}")
    report["cases"].append({"id": baseline + "/steps/wpcli", "differences": [{"id": "D17" if baseline == "release" else "D24", "oracle": upstream, "candidate": actual}]})
    print(f"PASS {baseline}/steps/wpcli: exact argv and registered eval-file differences", flush=True)

    candidate = setup("package-dropin", {"dropins": {"db.php": "sources/db.php"}, "dropins-op": "symlink"}, {"sources/db.php": "<?php // mapped\n", "vendor/fixture/dropins/object-cache.php": "<?php // package\n", "vendor/fixture/dropins/LICENSE": "synthetic license\n"})
    package = {"name": "fixture/dropins", "version": "1.0.0", "version_normalized": "1.0.0.0", "type": "wordpress-dropin", "install-path": "../fixture/dropins"}
    installed_file = oracle / "vendor/composer/installed.json"
    installed = json.loads(installed_file.read_text())
    installed["packages"].append(package)
    write_json(installed_file, installed)
    (candidate / "vendor/composer").mkdir(parents=True)
    write_json(candidate / "vendor/composer/installed.json", {"packages": [package]})
    (candidate / "vendor/autoload.php").write_text("<?php return require " + json.dumps(str(ROOT / "vendor/autoload.php")) + ";\n")
    run([args.oracle_php, args.composer, "--no-interaction", "wpstarter", "dropins"], oracle)
    candidate_env = dict(runtime_env, COMPOSER_VENDOR_DIR=str(candidate / "vendor"))
    run([args.candidate_php, str(ROOT / "bin/sympress-runtime"), "--no-interaction", "dropins"], candidate, candidate_env)
    def package_state(project):
        target = project / "public/content/object-cache.php"
        return {"source": (project / "vendor/fixture/dropins/object-cache.php").is_file(), "license": (project / "vendor/fixture/dropins/LICENSE").is_file(), "target_exists": target.is_file(), "target_link": target.is_symlink(), "target_hash": hashlib.sha256(target.read_bytes()).hexdigest() if target.is_file() else None}
    package_hash = hashlib.sha256(b"<?php // package\n").hexdigest()
    expected_oracle = {"source": False, "license": False, "target_exists": baseline == "release", "target_link": baseline != "release", "target_hash": package_hash if baseline == "release" else None}
    expected_candidate = {"source": True, "license": True, "target_exists": True, "target_link": True, "target_hash": package_hash}
    if package_state(oracle) != expected_oracle or package_state(candidate) != expected_candidate:
        raise RuntimeError(f"Package source retention mismatch: {package_state(oracle)} / {package_state(candidate)}")
    report["cases"].append({"id": baseline + "/steps/package-dropin", "differences": [{"id": "D10", "oracle": expected_oracle, "candidate": expected_candidate}]})
    print(f"PASS {baseline}/steps/package-dropin: exact D10 source, license and link state", flush=True)

    if baseline == "dev":
        candidate = setup("vcs-marker", {"check-vcs-ignore": True}, {".gitignore": "# -~ Generated by WP Starter x~-\n"})
        for project in [oracle, candidate]:
            run(["git", "init", "-q"], project)
        upstream = run([args.oracle_php, args.composer, "--no-interaction", "wpstarter", "vcsignorecheck"], oracle)
        actual = subprocess.run([args.candidate_php, str(ROOT / "bin/sympress-runtime"), "--no-interaction", "vcsignorecheck"], cwd=candidate, env=runtime_env, text=True, capture_output=True)
        if "Found a WP-Starter generated .gitignore file." not in upstream or actual.returncode != 1 or "VCS ignore protection could not be verified" not in actual.stdout + actual.stderr:
            raise RuntimeError("VCS generated-marker fixture did not match exact D18 behavior.")
        report["cases"].append({"id": "dev/steps/vcs-marker", "differences": [{"id": "D18", "oracle": {"exit": 0, "trusted_marker": True}, "candidate": {"exit": 1, "protection_verified": False}}]})
        print("PASS dev/steps/vcs-marker: actual ignore evidence replaces marker trust", flush=True)


def compare_lifecycle(work, baseline, autoload, args, report):
    oracle = autoload.parent.parent
    manifest = json.loads((oracle / "composer.json").read_text())
    manifest["config"]["allow-plugins"] = {"wecodemore/wpstarter": True, "composer/installers": False}
    candidate = work / (baseline + "-lifecycle")
    candidate.mkdir()
    options = {"db-check": False, "require-wp": False, "env-dir": ".", "autoload": "lifecycle.php", "custom-steps": {"fixture": "FixtureLifecycleStep"}, "scripts": {"pre-wpstarter": "fixture_pre_run", "pre-fixture": [["FixtureLifecycleHooks", "before"], "FixtureLifecycleHooks::next"], "post-fixture": "FixtureLifecycleHooks::after", "post-wpstarter": "FixtureLifecycleHooks::postRun"}}
    template = (ROOT / "tools/differential/lifecycle.php.tpl").read_text()
    for project, prefix, interface in [(oracle, "WeCodeMore\\WpStarter", "Step\\Step"), (candidate, "SymPress\\Runtime", "Step\\StepInterface")]:
        source = template.replace("@@STEP_INTERFACE@@", prefix + "\\" + interface).replace("@@CONFIG@@", prefix + "\\Config\\Config").replace("@@PATHS@@", prefix + ("\\Util\\Paths" if project == oracle else "\\Filesystem\\Paths"))
        (project / "lifecycle.php").write_text(source)
        core_fixture(project, "development")
        extension = project / "vendor/fixture/lifecycle-extension"
        (extension / "src").mkdir(parents=True)
        (extension / "srcOther").mkdir()
        for filename, namespace, name in [("src/Available.php", "FixtureExtension", "Available"), ("src/DifferentCase.php", "FixtureExtension", "DifferentCase"), ("srcOther/Sibling.php", "FixtureExtensionOther", "Sibling")]:
            (extension / filename).write_text(f"<?php namespace {namespace}; class {name} {{}}")
        installed = project / "vendor/composer/installed.json"
        installed.parent.mkdir(parents=True, exist_ok=True)
        packages = json.loads(installed.read_text()) if installed.exists() else {"packages": []}
        packages["packages"].append({"name": "fixture/lifecycle-extension", "version": "1.0.0", "version_normalized": "1.0.0.0", "type": "wpstarter-extension", "install-path": "../fixture/lifecycle-extension", "extra": {"wpstarter-autoload": {"psr-4": {"FixtureExtension": "src"}}}})
        write_json(installed, packages)
        if project == candidate:
            (project / "vendor/autoload.php").write_text("<?php return require " + repr(str(ROOT / "vendor/autoload.php")) + ";")
    manifest["extra"] = {"wordpress-install-dir": "public/wp", "wordpress-content-dir": "public/content", "wpstarter": options}
    write_json(oracle / "composer.json", manifest)
    write_json(candidate / "composer.json", {"extra": {"wordpress-install-dir": "public/wp", "wordpress-content-dir": "public/content", "sympress-runtime": options}})
    for mode, result in [("success", 2), ("error", 1), ("partial", 3), ("none", 4), ("callback-error", 2), ("autoload", 2)]:
        observed = []
        for project, php in [(oracle, args.oracle_php), (candidate, args.candidate_php)]:
            trace = project / "lifecycle.jsonl"
            trace.unlink(missing_ok=True)
            environment = dict(os.environ, SYMPRESS_LIFECYCLE_MODE=mode)
            environment.pop("COMPOSER", None)
            command = [php, args.composer, "--no-interaction", "wpstarter", "fixture"]
            if project == candidate:
                environment.pop("COMPOSER_VENDOR_DIR", None)
                command = [php, str(ROOT / "bin/sympress-runtime"), "--no-interaction", "fixture"]
            completed = subprocess.run(command, cwd=project, env=environment, text=True, capture_output=True)
            if not trace.is_file():
                raise RuntimeError(f"Lifecycle fixture did not execute: {completed.stdout[-1500:]} {completed.stderr[-1500:]}")
            observed.append({"exit": completed.returncode, "trace": [json.loads(line) for line in trace.read_text().splitlines()]})
        expected_trace = [["pre-run", 4, "Composer\\Composer"], ["pre-step", 4], ["next-pre-step", 4], ["body"], ["post-step", result], ["post-run", 2]]
        expected_oracle = {"exit": 0, "trace": expected_trace}
        candidate_trace = json.loads(json.dumps(expected_trace))
        candidate_trace[0][2] = "SymPress\\Runtime\\Application\\RunContext"
        if mode == "autoload":
            expected_trace.insert(1, ["autoload", [True, True, True, "error"]])
            candidate_trace.insert(1, ["autoload", [True, False, False, False]])
        aggregate = 3 if mode == "callback-error" else result
        candidate_trace[-1][1] = aggregate
        expected_candidate = {"exit": int(mode in ["error", "partial", "callback-error"]), "trace": candidate_trace}
        if observed != [expected_oracle, expected_candidate]:
            raise RuntimeError(f"Lifecycle mismatch {baseline}/{mode}: {observed}")
        differences = [{"id": "D14", "field": "trace.0.context", "oracle": "Composer\\Composer", "candidate": "SymPress\\Runtime\\Application\\RunContext"}]
        if mode == "autoload":
            differences.append({"id": "D01", "field": "extension_autoload", "oracle": expected_trace[1][1], "candidate": candidate_trace[1][1]})
        if expected_oracle["exit"] != expected_candidate["exit"] or expected_trace[-1] != candidate_trace[-1]:
            differences.append({"id": "D12", "oracle": {"exit": expected_oracle["exit"], "post": expected_trace[-1]}, "candidate": {"exit": expected_candidate["exit"], "post": candidate_trace[-1]}})
        report["cases"].append({"id": baseline + "/lifecycle/" + mode, "trace": candidate_trace, "differences": differences})
        print(f"PASS {baseline}/lifecycle/{mode}: exact callback ordering, results and context boundary", flush=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--oracle-php", default="php8.2")
    parser.add_argument("--candidate-php", default="php8.5")
    parser.add_argument("--composer", required=True)
    parser.add_argument("--scope", choices=["all", "constants", "environment", "generated", "steps", "lifecycle"], default="all")
    args = parser.parse_args()
    build = ROOT / "build/differential"
    build.mkdir(parents=True, exist_ok=True)
    (build / "report.json").unlink(missing_ok=True)
    inventory = json.loads((ROOT / "docs/upstream-inventory.json").read_text())
    candidate_types = {item["name"]: item["type"] for item in inventory["baselines"]["dev"]["constants"]}
    report = {"scope": "constant reader/definitions, environment aliases, generated index/wp-config, warm-cache runtime, exact content trees, package retention, WP-CLI argv, VCS marker, lifecycle and extension autoload behavior; complete generated-template snapshots remain pending", "oracles": {}, "cases": []}
    report["php"] = {key: run([binary, "-r", "echo PHP_VERSION;"], ROOT) for key, binary in [("oracle", args.oracle_php), ("candidate", args.candidate_php)]}
    report["composer"] = run([args.oracle_php, args.composer, "--version", "--no-ansi"], ROOT).strip()
    with tempfile.TemporaryDirectory(prefix="oracle-", dir=build) as temp:
        work = Path(temp)
        for baseline, (sha, profile) in BASELINES.items():
            autoload, metadata = install_oracle(work, baseline, sha, args)
            report["oracles"][baseline] = metadata
            constants = inventory["baselines"][baseline]["constants"]
            names = [item["name"] for item in constants]
            for mode in (["missing", "valid", "unrecognized", "predefined"] if args.scope in ["all", "constants"] else []):
                case = work / (baseline + "-" + mode)
                case.mkdir()
                values = {} if mode == "missing" else {item["name"]: ("unrecognized!" if mode == "unrecognized" else SAMPLES[item["type"]]) for item in constants}
                predefined = {name: "predefined-fixture" for name in names} if mode == "predefined" else {}
                write_json(case / "input.json", {"names": names, "predefined": predefined})
                (case / ".env").write_text("".join(name + "=" + json.dumps(value) + "\n" for name, value in values.items()))
                oracle = probe(args.oracle_php, autoload, case, "oracle")
                compatible = probe(args.candidate_php, ROOT / "vendor/autoload.php", case, profile)
                compare(oracle, compatible, baseline + "/" + mode + "/legacy")
                native = probe(args.candidate_php, ROOT / "vendor/autoload.php", case, "native")
                expected = json.loads(json.dumps(oracle))
                allowed = []
                if mode != "missing":
                    for name, value in values.items():
                        if candidate_types[name] != "RAW_STRING":
                            continue
                        expected[name]["value"] = value
                        expected[name]["type"] = "string"
                        if mode != "predefined":
                            expected[name]["constant"] = value
                            expected[name]["constant_type"] = "string"
                        if expected[name] != oracle[name]:
                            allowed.append({"id": "D09", "name": name, "oracle": oracle[name], "candidate": expected[name]})
                compare(expected, native, baseline + "/" + mode + "/native")
                report["cases"].append({"id": baseline + "/" + mode, "constants": len(names), "differences": allowed})
                print(f"PASS {baseline}/{mode}: {len(names)} constants; {len(allowed)} exact D09 differences", flush=True)
            aliases = ["local", "development", "dev", "develop", "staging", "stage", "pre", "preprod", "pre-prod", "pre-production", "preproduction", "test", "tests", "testing", "uat", "qa", "acceptance", "accept", "production", "prod", "live", "public", "my_dev_one", "my_devone", "prod-dev-local", "PREPROD-EU-1", "custom"]
            for variable in (["WP_ENV", "WORDPRESS_ENV", "WP_ENVIRONMENT_TYPE"] if args.scope in ["all", "environment"] else []):
                for alias in aliases:
                    label = baseline + "/environment/" + variable + "/" + alias
                    case = work / (baseline + "-" + variable + "-" + alias)
                    case.mkdir()
                    write_json(case / "input.json", {"names": ["WP_ENV", "WP_ENVIRONMENT_TYPE"]})
                    (case / ".env").write_text(variable + "=" + alias + "\n")
                    oracle = probe(args.oracle_php, autoload, case, "oracle")
                    compatible = probe(args.candidate_php, ROOT / "vendor/autoload.php", case, profile)
                    native = probe(args.candidate_php, ROOT / "vendor/autoload.php", case, "native")
                    compare(oracle, compatible, label + "/legacy")
                    compare(oracle, native, label + "/native")
                    report["cases"].append({"id": label, "constants": 2, "differences": []})
            if args.scope in ["all", "environment"]:
                print(f"PASS {baseline}/environment: {len(aliases) * 3} name/variable combinations", flush=True)
            if args.scope in ["all", "generated"]:
                compare_generated(work, baseline, autoload, args, report)
            if args.scope in ["all", "steps"]:
                compare_steps(work, baseline, autoload, args, report)
            if args.scope in ["all", "lifecycle"]:
                compare_lifecycle(work, baseline, autoload, args, report)
    report["executed_scope"] = args.scope
    write_json(build / "report.json", report)
    print(f"Report: {build / 'report.json'}")


if __name__ == "__main__":
    main()
