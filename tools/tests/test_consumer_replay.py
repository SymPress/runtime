import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest


spec = importlib.util.spec_from_file_location("consumer_replay", Path(__file__).parents[1] / "consumer-replay.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ConsumerReplayTest(unittest.TestCase):
    def test_native_configuration_changes_are_detected_for_configured_layouts(self):
        for core, settings, configuration in [
            ("public/wp", {}, "public/wp-config.php"),
            ("web/core/wp", {}, "web/core/wp-config.php"),
            ("public/wp", {"wordpress-parent-dir": "web"}, "web/wp-config.php"),
        ]:
            with self.subTest(configuration=configuration), tempfile.TemporaryDirectory() as directory:
                project = Path(directory)
                extra = {"wordpress-install-dir": core, "sympress-runtime": settings}
                (project / "composer.json").write_text(json.dumps({"extra": extra}))
                target = project / configuration
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(b"original configuration")
                before = module.snapshot(project)
                self.assertTrue(any(name in before for name in module.configuration_paths(extra)))
                self.assertEqual(hashlib.sha256(b"original configuration").hexdigest(), before[configuration])
                self.assertNotIn("wp-config.php", before)
                target.write_bytes(b"unexpected replacement")
                self.assertNotEqual(before[configuration], module.snapshot(project)[configuration])

    def test_retained_root_and_parent_configurations_are_both_monitored(self):
        with tempfile.TemporaryDirectory() as directory:
            project = Path(directory)
            (project / "composer.json").write_text('{"extra":{"wordpress-install-dir":"public/wp"}}')
            (project / "wp-config.php").write_text("retained original")
            (project / "public").mkdir()
            (project / "public/wp-config.php").write_text("active configuration")
            baseline = module.snapshot(project)
            self.assertEqual({"wp-config.php", "public/wp-config.php"}, set(baseline))
            for relative in baseline:
                with self.subTest(configuration=relative):
                    target = project / relative
                    original = target.read_bytes()
                    target.write_bytes(b"unexpected replacement")
                    self.assertNotEqual(baseline[relative], module.snapshot(project)[relative])
                    target.write_bytes(original)


if __name__ == "__main__":
    unittest.main()
