#!/usr/bin/env python3
"""Reject incomplete scanner execution even when its CLI exits successfully."""
import argparse
import json
from pathlib import Path


def verify(report, version='1.178.0'):
    runs = report.get('runs')
    if not isinstance(runs, list) or not runs:
        raise ValueError('SARIF must contain a scanner run.')
    for run in runs:
        if run.get('tool', {}).get('driver', {}).get('semanticVersion') != version:
            raise ValueError('SARIF scanner version differs from the reviewed pin.')
        invocations = run.get('invocations')
        if not isinstance(invocations, list) or not invocations:
            raise ValueError('SARIF must contain execution evidence.')
        for invocation in invocations:
            if invocation.get('executionSuccessful') is not True:
                raise ValueError('SARIF records unsuccessful scanner execution.')
            if any(note.get('level') in ('warning', 'error') for note in invocation.get('toolExecutionNotifications', [])):
                raise ValueError('SARIF records scanner warnings or errors; coverage is incomplete.')
        if not isinstance(run.get('results'), list) or run['results']:
            raise ValueError('SARIF records security findings or invalid results.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('report', type=Path)
    args = parser.parse_args()
    try:
        verify(json.loads(args.report.read_text()))
    except (ValueError, OSError, TypeError, AttributeError) as error:
        parser.exit(1, str(error) + '\n')
    print('Pinned scanner execution verified with no findings or execution warnings/errors.')


if __name__ == '__main__':
    main()
