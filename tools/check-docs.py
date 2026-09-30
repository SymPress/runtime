#!/usr/bin/env python3
"""Check documentation links, JSON examples and public reference coverage."""
import json
import re
import sys
from pathlib import Path
from urllib.parse import unquote

ROOT = Path(__file__).resolve().parents[1]


def check(root=ROOT):
    errors = []
    documents = list(root.glob("*.md")) + list((root / "docs").rglob("*.md"))
    for file in documents:
        text = file.read_text()
        for target in re.findall(r"\]\(([^\s)]+)\)", text):
            if ":" in target or target.startswith("#"):
                continue
            path = unquote(target.split("#", 1)[0])
            if path and not (file.parent / path).exists():
                errors.append(f"{file.relative_to(root)}: missing link target {target}")
        for snippet in re.findall(r"```json\n(.*?)\n```", text, re.S):
            try:
                json.loads(snippet)
            except json.JSONDecodeError as error:
                errors.append(f"{file.relative_to(root)}: invalid JSON example: {error.msg}")
    for file in (root / "examples").rglob("*.json"):
        try:
            json.loads(file.read_text())
        except json.JSONDecodeError as error:
            errors.append(f"{file.relative_to(root)}: invalid JSON: {error.msg}")

    source = (root / "src/Config/Options.php").read_text()
    defaults = source.split("public const array DEFAULTS = [", 1)[1].split("    ];", 1)[0]
    internal = source.split("public const array INTERNAL = [", 1)[1].split("    ];", 1)[0]
    expected = set(re.findall(r"'([^']+)'\s*=>", defaults)) - set(re.findall(r"'([^']+)'", internal))
    documented = set(re.findall(r"^\| `([a-z][a-z0-9-]+)` \|", (root / "docs/configuration.md").read_text(), re.M))
    if expected != documented:
        errors.append(f"Settings mismatch: missing={sorted(expected - documented)}, extra={sorted(documented - expected)}")

    catalog = (root / "src/Env/ConstantCatalog.php").read_text().split("public const array TYPES = [", 1)[1].split("    ];", 1)[0]
    expected_constants = set(re.findall(r"'([^']+)'\s*=>", catalog))
    documented_constants = set(re.findall(r"^\| `([A-Z][A-Z_]+)` \|", (root / "docs/constants.md").read_text(), re.M))
    if expected_constants != documented_constants:
        errors.append(f"Constants mismatch: missing={sorted(expected_constants - documented_constants)}, extra={sorted(documented_constants - expected_constants)}")

    for file in [root / "README.md", *list((root / "docs").glob("*.md"))]:
        if file.name in {"README.md", "compatibility.md", "compatibility-policy.md"}:
            continue
        text = file.read_text()
        if file.name == "api.md":
            text = re.sub(r"IS_WPSTARTER_(?:SELECTED_)?COMMAND", "LEGACY_COMMAND", text)
            text = re.sub(r'"(?:is-wpstarter-(?:selected-)?command|wp-starter)"', '"legacy-name"', text)
        if re.search(r"wp[ -]?starter|wecodemore", text, re.I):
            errors.append(f"{file.relative_to(root)}: compatibility branding belongs in the compatibility guide")
    return errors, len(documents), len(expected), len(expected_constants)


if __name__ == "__main__":
    failures, documents, options, constants = check()
    for failure in failures:
        print(failure, file=sys.stderr)
    if failures:
        sys.exit(1)
    print(f"Documentation verified: {documents} Markdown files, {options} public options, {constants} constants.")
