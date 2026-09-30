#!/usr/bin/env python3
"""Join PHPUnit's discovered metadata and executed results to the acceptance matrix."""

import argparse
from collections import Counter, defaultdict
import json
from pathlib import Path
import re
import xml.etree.ElementTree as ET


def build_report(matrix, listing, results):
    rows = re.findall(r"`(PAR-[^`]+)`", matrix)
    duplicate_rows = sorted(key for key, count in Counter(rows).items() if count > 1)
    namespace = {"t": "https://xml.phpunit.de/testSuite"}
    methods = {entry.attrib["id"] for entry in listing.findall("t:tests/t:testClass/t:testMethod", namespace)}
    mappings = defaultdict(set)
    for group in listing.findall("t:groups/t:group", namespace):
        name = group.attrib["name"]
        if name.startswith("PAR-"):
            mappings[name].update(entry.attrib["id"] for entry in group)
    for method in methods:
        for name in re.findall(r"PAR-[A-Z0-9_-]+", method):
            mappings[name].add(method)
    execution = {}
    for case in results.iter("testcase"):
        name = case.attrib["name"]
        match = re.fullmatch(r'(.*?) with data set (?:"(.*)"|#(\d+))', name)
        if match:
            name = match[1] + "#" + (match[2] if match[2] is not None else match[3])
        identity = case.attrib["class"] + "::" + name
        status = "passed"
        if case.find("skipped") is not None:
            status = "skipped"
        if case.find("failure") is not None or case.find("error") is not None:
            status = "failed"
        if identity in execution:
            raise ValueError("Duplicate executed test identity: " + identity)
        execution[identity] = status
    evidence = {}
    for row in rows:
        mapped = sorted(mappings[row])
        states = {method: execution.get(method, "not-run") for method in mapped}
        evidence[row] = {
            "tests": states,
            "passed": any(state == "passed" for state in states.values()),
        }
    failures = {
        "duplicate_rows": duplicate_rows,
        "unknown_ids": sorted(set(mappings) - set(rows)),
        "unknown_tests": sorted(set().union(*mappings.values()) - methods),
        "unmapped_rows": sorted(row for row in rows if not mappings[row]),
        "unproven_rows": sorted(row for row in rows if not evidence[row]["passed"]),
        "failed_tests": sorted(method for method, status in execution.items() if status == "failed"),
        "missing_results": sorted(methods - set(execution)),
        "unexpected_results": sorted(set(execution) - methods),
    }
    return {"passed": not any(failures.values()), "row_count": len(rows), "executed_count": len(execution), "failures": failures, "evidence": evidence}


def main():
    root = Path(__file__).resolve().parents[1]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--matrix", type=Path, default=root / "docs/maintainers/parity.md")
    parser.add_argument("--test-list", type=Path, default=root / "build/test-list.xml")
    parser.add_argument("--junit", type=Path, default=root / "build/phpunit.xml")
    parser.add_argument("--output", type=Path, default=root / "build/parity-evidence.json")
    args = parser.parse_args()
    report = build_report(args.matrix.read_text(), ET.parse(args.test_list).getroot(), ET.parse(args.junit).getroot())
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps({key: value for key, value in report.items() if key != "evidence"}, indent=2))
    raise SystemExit(0 if report["passed"] else 1)


if __name__ == "__main__":
    main()
