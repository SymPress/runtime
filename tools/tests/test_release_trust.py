import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SPEC = importlib.util.spec_from_file_location('release', Path(__file__).parents[1] / 'verify-release.py')
RELEASE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RELEASE)


class ReleaseTrustTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.previous = os.getcwd()
        os.chdir(self.directory.name)
        self.git('init', '-q')
        self.git('config', 'user.name', 'Release Test')
        self.git('config', 'user.email', 'release@example.test')
        subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', 'trusted'], check=True)
        subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', 'attacker'], check=True)
        self.git('config', 'gpg.format', 'ssh')
        self.git('config', 'user.signingkey', str(Path('trusted').resolve()))
        Path('.github').mkdir()
        Path('.github/release-signers').write_text('release@example.test ' + Path('trusted.pub').read_text())
        self.git('add', '.github')
        self.git('commit', '-qm', 'Reviewed main')
        self.main = self.git('rev-parse', 'HEAD')
        self.git('tag', '-s', 'v1.2.0', '-m', 'Reviewed release')

    def tearDown(self):
        os.chdir(self.previous)
        self.directory.cleanup()

    def git(self, *args):
        return subprocess.check_output(['git', *args], stderr=subprocess.STDOUT).decode().strip()

    def test_valid_tag_uses_commit_blob_despite_attacker_worktree_allowlist(self):
        Path('.github/release-signers').write_text('release@example.test ' + Path('attacker.pub').read_text())
        self.assertEqual(RELEASE.verify('v1.2.0', self.main, self.main)[0], self.main)

    def test_untrusted_tag_cannot_authorize_its_own_signer(self):
        Path('.github/release-signers').write_text('release@example.test ' + Path('attacker.pub').read_text())
        self.git('add', '.github/release-signers')
        self.git('commit', '-qm', 'Attacker tree')
        self.git('config', 'user.signingkey', str(Path('attacker').resolve()))
        self.git('tag', '-s', 'v9.9.9', '-m', 'Attacker release')
        self.git('checkout', '-q', self.main)
        with self.assertRaisesRegex(ValueError, 'candidate'):
            RELEASE.verify('v9.9.9', self.main, self.main)

    def test_unauthorized_key_at_main_fails_signature_verification(self):
        self.git('config', 'user.signingkey', str(Path('attacker').resolve()))
        self.git('tag', '-s', 'v1.2.1', '-m', 'Unauthorized signer')
        with self.assertRaises(subprocess.CalledProcessError):
            RELEASE.verify('v1.2.1', self.main, self.main)

    def test_stale_dispatch_lightweight_and_nested_tags_fail(self):
        with self.assertRaisesRegex(ValueError, 'approved main'):
            RELEASE.verify('v1.2.0', self.main, '0' * 40)
        self.git('tag', 'v1.2.2')
        with self.assertRaisesRegex(ValueError, 'annotated'):
            RELEASE.verify('v1.2.2', self.main, self.main)
        self.git('tag', '-s', 'v1.2.3', 'v1.2.0', '-m', 'Nested tag')
        with self.assertRaisesRegex(ValueError, 'directly'):
            RELEASE.verify('v1.2.3', self.main, self.main)

    def test_workflow_has_main_only_dispatch_and_environment_gate(self):
        workflow = (Path(__file__).parents[2] / '.github/workflows/release.yml').read_text()
        self.assertNotIn('  push:', workflow)
        self.assertIn("github.ref == 'refs/heads/main'", workflow)
        self.assertIn('environment: release', workflow)
        self.assertIn('tools/verify-release.py', workflow)
        self.assertIn('approved_main=', workflow)
        self.assertNotIn('secrets: inherit', workflow)
