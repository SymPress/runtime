#!/usr/bin/env python3
"""Verify a release using the dispatch commit's independently protected signer list."""
import argparse
import os
import re
import subprocess
import tempfile


def git(*arguments):
    return subprocess.check_output(['git', *arguments], stderr=subprocess.STDOUT).decode().strip()


def verify(tag, trusted_commit, approved_main):
    if not re.fullmatch(r'v[0-9]+\.[0-9]+\.[0-9]+(?:-(?:beta|rc)\.[0-9]+)?', tag):
        raise ValueError('Release tag must use accepted SemVer.')
    if not re.fullmatch(r'[0-9a-f]{40}', trusted_commit) or trusted_commit != approved_main:
        raise ValueError('Dispatch commit must equal the approved main head.')
    if git('rev-parse', 'HEAD') != trusted_commit:
        raise ValueError('Trusted checkout differs from the dispatch commit.')
    tag_object = git('rev-parse', 'refs/tags/' + tag)
    if git('cat-file', '-t', tag_object) != 'tag':
        raise ValueError('Release requires an annotated signed tag.')
    lines = git('cat-file', 'tag', tag_object).splitlines()
    if len(lines) < 3 or lines[1] != 'type commit' or lines[2] != 'tag ' + tag:
        raise ValueError('Release tag must point directly to a commit and match its ref name.')
    commit = lines[0].removeprefix('object ')
    if commit != trusted_commit:
        raise ValueError('Release candidate must equal the reviewed dispatch commit on main.')
    # Load from a verified commit object, never from the mutable worktree or tag tree.
    signers = git('show', trusted_commit + ':.github/release-signers')
    with tempfile.NamedTemporaryFile(mode='w', encoding='utf-8', prefix='runtime-signers-') as allowlist:
        allowlist.write(signers + '\n')
        allowlist.flush()
        subprocess.run(['git', '-c', 'gpg.format=ssh', '-c', 'gpg.ssh.allowedSignersFile=' + allowlist.name,
                        'verify-tag', tag_object], check=True)
    return commit, tag_object


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('tag')
    parser.add_argument('--trusted-commit', required=True)
    parser.add_argument('--approved-main', required=True)
    arguments = parser.parse_args()
    try:
        commit, tag_object = verify(arguments.tag, arguments.trusted_commit, arguments.approved_main)
    except (ValueError, subprocess.CalledProcessError) as error:
        parser.exit(1, str(error) + '\n')
    print('Independent protected-main signer verification passed; this is not GitHub account verification.')
    if os.environ.get('GITHUB_OUTPUT'):
        with open(os.environ['GITHUB_OUTPUT'], 'a', encoding='utf-8') as output:
            output.write('commit=' + commit + '\ntag_object=' + tag_object + '\n')


if __name__ == '__main__':
    main()
