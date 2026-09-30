import importlib.util
from pathlib import Path
import unittest
import xml.etree.ElementTree as ET


spec = importlib.util.spec_from_file_location("parity_evidence", Path(__file__).parents[1] / "parity-evidence.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ParityEvidenceTest(unittest.TestCase):
    matrix = "| Feature | `PAR-CFG-001` | partial |\n| Constant | `PAR-CONST-DB_NAME` | partial |"
    listing = '''<testSuite xmlns="https://xml.phpunit.de/testSuite"><tests><testClass name="Fixture">
    <testMethod id="Fixture::testConfig" name="testConfig"/>
    <testMethod id="Fixture::testConstant#PAR-CONST-DB_NAME" name="testConstant"/>
    </testClass></tests><groups><group name="PAR-CFG-001"><test id="Fixture::testConfig"/></group></groups></testSuite>'''
    results = '''<testsuites><testsuite><testcase class="Fixture" name="testConfig"/>
    <testcase class="Fixture" name='testConstant with data set "PAR-CONST-DB_NAME"'/></testsuite></testsuites>'''

    def report(self, matrix=None, listing=None, results=None):
        return module.build_report(matrix or self.matrix, ET.fromstring(listing or self.listing), ET.fromstring(results or self.results))

    def test_groups_and_named_datasets_require_executed_results(self):
        self.assertTrue(self.report()["passed"])
        report = self.report(results=self.results.replace('<testcase class="Fixture" name="testConfig"/>', ''))
        self.assertFalse(report["passed"])
        self.assertEqual(["PAR-CFG-001"], report["failures"]["unproven_rows"])
        self.assertEqual(["Fixture::testConfig"], report["failures"]["missing_results"])

    def test_skipped_and_failed_only_rows_are_not_evidence(self):
        for status in ["skipped", "failure", "error"]:
            with self.subTest(status=status):
                results = self.results.replace('name="testConfig"/>', f'name="testConfig"><{status}/></testcase>')
                report = self.report(results=results)
                self.assertFalse(report["passed"])
                self.assertEqual(["PAR-CFG-001"], report["failures"]["unproven_rows"])

    def test_duplicate_rows_unknown_ids_and_unmapped_rows_fail(self):
        self.assertFalse(self.report(matrix=self.matrix + " `PAR-CFG-001`")["passed"])
        self.assertFalse(self.report(matrix=self.matrix + " `PAR-CFG-002`")["passed"])
        report = self.report(listing=self.listing.replace('name="PAR-CFG-001"', 'name="PAR-CFG-999"'))
        self.assertEqual(["PAR-CFG-999"], report["failures"]["unknown_ids"])

    def test_undiscovered_and_duplicate_executions_cannot_forge_evidence(self):
        report = self.report(results=self.results.replace("</testsuite>", '<testcase class="Fixture" name="testOther"/></testsuite>'))
        self.assertFalse(report["passed"])
        with self.assertRaisesRegex(ValueError, "Duplicate executed"):
            self.report(results=self.results.replace("</testsuite>", '<testcase class="Fixture" name="testConfig"/></testsuite>'))


if __name__ == "__main__":
    unittest.main()
