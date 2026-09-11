#!/usr/bin/env python3
"""Build an installable ZIP from committed runtime files. Optional argument verifies a release tag."""
import io
import re
import subprocess
import sys
import zipfile
from pathlib import Path

root = Path(__file__).resolve().parents[1]
if subprocess.check_output(['git', 'status', '--porcelain'], cwd=root).strip():
    raise SystemExit('Commit the working tree before building a release.')
archive = subprocess.check_output(['git', 'archive', '--format=zip', 'HEAD'], cwd=root)
with zipfile.ZipFile(io.BytesIO(archive)) as source:
    main = source.read('sunrise.php').decode()
    version = re.search(r'^ \* Version: (\d+\.\d+\.\d+)$', main, re.M).group(1)
    if f"const VERSION = '{version}';" not in main or f'Stable tag: {version}\n' not in source.read('readme.txt').decode():
        raise SystemExit('Plugin header, VERSION and Stable tag must agree.')
    metadata = source.read('update.json').decode()
    if f'"version": "{version}"' not in metadata or f'/releases/download/v{version}/sunrise.zip' not in metadata:
        raise SystemExit('Static update metadata must match the plugin version.')
    changes = source.read('changes.md').decode()
    section = re.search(r'^## ' + re.escape(version) + r'[^\n]*\n(.*?)(?=^## |\Z)', changes, re.M | re.S)
    if not section:
        raise SystemExit('Add numbered release notes to changes.md for ' + version)
    if len(sys.argv) > 1 and sys.argv[1] != 'v' + version:
        raise SystemExit('Tag must match the plugin version: v' + version)
    names = [name for name in source.namelist() if not name.endswith('/') and
             (name in ('sunrise.php', 'uninstall.php', 'readme.txt', 'changes.md') or name.startswith(('includes/', 'assets/', 'vendor/')))]
    assert 'vendor/plugin-update-checker/plugin-update-checker.php' in names
    output = root / 'dist' / 'sunrise.zip'
    output.parent.mkdir(exist_ok=True)
    (output.parent / 'release-notes.md').write_text(section.group(1).strip() + '\n')
    with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as target:
        for name in names:
            target.writestr('sunrise/' + name, source.read(name))
    with zipfile.ZipFile(output) as result:
        assert result.testzip() is None and 'sunrise/sunrise.php' in result.namelist()
    print(f'{output} ({len(names)} files, version {version})')
