#!/usr/bin/env python3
"""Isolated pinned WP Starter oracles; never changes runtime dependencies."""

import argparse
import hashlib
import json
import os
from pathlib import Path
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


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--oracle-php", default="php8.2")
    parser.add_argument("--candidate-php", default="php8.5")
    parser.add_argument("--composer", required=True)
    parser.add_argument("--scope", choices=["all", "constants", "environment", "generated"], default="all")
    args = parser.parse_args()
    build = ROOT / "build/differential"
    build.mkdir(parents=True, exist_ok=True)
    (build / "report.json").unlink(missing_ok=True)
    inventory = json.loads((ROOT / "docs/upstream-inventory.json").read_text())
    candidate_types = {item["name"]: item["type"] for item in inventory["baselines"]["dev"]["constants"]}
    report = {"scope": "constant reader/definitions, environment aliases, generated index/wp-config and warm-cache runtime; complete file snapshots remain pending", "oracles": {}, "cases": []}
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
    report["executed_scope"] = args.scope
    write_json(build / "report.json", report)
    print(f"Report: {build / 'report.json'}")


if __name__ == "__main__":
    main()
