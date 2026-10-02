import copy
import importlib.util
from pathlib import Path
import unittest

SPEC = importlib.util.spec_from_file_location('sarif', Path(__file__).parents[1] / 'check-security-sarif.py')
SARIF = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(SARIF)


class ScannerExecutionTest(unittest.TestCase):
    def report(self):
        return {'runs': [{'tool': {'driver': {'semanticVersion': '1.178.0'}}, 'results': [],
                          'invocations': [{'executionSuccessful': True, 'toolExecutionNotifications': []}]}]}

    def test_successful_pinned_scan_passes(self):
        SARIF.verify(self.report())

    def test_misleading_success_with_engine_error_or_parse_warning_fails(self):
        for level in ('error', 'warning'):
            report = self.report()
            report['runs'][0]['invocations'][0]['toolExecutionNotifications'] = [
                {'level': level, 'message': {'text': 'io_uring_queue_init failed'}}]
            with self.assertRaisesRegex(ValueError, 'coverage is incomplete'):
                SARIF.verify(report)

    def test_missing_execution_wrong_version_failed_execution_and_findings_fail(self):
        valid = self.report()
        for changes in ({'invocations': []}, {'invocations': [{'executionSuccessful': False}]},
                        {'tool': {'driver': {'semanticVersion': 'unreviewed'}}}, {'results': [{}]}):
            report = copy.deepcopy(valid)
            report['runs'][0].update(changes)
            with self.assertRaises(ValueError):
                SARIF.verify(report)
        with self.assertRaises(ValueError):
            SARIF.verify({'runs': []})
