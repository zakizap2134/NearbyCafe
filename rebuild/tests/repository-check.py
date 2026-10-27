"""Run from a Git checkout after staging intended changes: python rebuild/tests/repository-check.py.
Read-only checks; no application database or private file contents are read.
"""
from pathlib import Path
import json
import re
import subprocess
import urllib.parse

root = Path(__file__).resolve().parents[2]

def git(*args):
    return subprocess.check_output(['git', '-C', str(root), *args])

tracked = set(git('ls-files', '-z').decode().rstrip('\0').split('\0'))
for path in tracked:
    parts = Path(path).parts
    assert not any(x in parts for x in ('node_modules', 'test-results', '__pycache__', 'previousPrompts')), path
    assert not path.endswith(('.log', '.sqlite', '.sqlite3', '.ibd', '.pyc', '.zip')), path
    assert not path.startswith(('backend/', 'src/', 'database/')), path
    assert not path.startswith('rebuild/rebuild/'), path
    if path.startswith('rebuild/storage/'):
        assert path == 'rebuild/storage/.gitkeep', path
    if path.startswith('rebuild/config/'):
        assert path in ('rebuild/config/local.example.php', 'rebuild/config/.htaccess'), path
assert 'rebuild/package-lock.json' in tracked
assert 'rebuild/public/index.html' not in tracked
assert not any(p.startswith('rebuild/public/assets/') for p in tracked)
print('PASS staged tree has one application; lockfile/examples retained; no private runtime/dependency/build files tracked')

excluded = [
    'rebuild/config/local.php', 'rebuild/config/launcher.local.json',
    'rebuild/config/nearby-httpd.conf', 'rebuild/storage/launcher/ownership.json',
    'rebuild/storage/app.log', 'rebuild/node_modules/example/index.js',
    'rebuild/backups/client.sql', 'rebuild/database/client.sql.gz',
    'rebuild/test-results/private.json', 'rebuild/public/assets/example.js',
    'rebuild/public/index.html', 'previousPrompts/session.v3.jsonl',
    'rebuild/owner-recovery-code.txt', 'rebuild/.recovery-test-vite.mjs',
]
for p in excluded:
    result = subprocess.run(['git', '-C', str(root), 'check-ignore', '--no-index', '-q', p])
    assert result.returncode == 0, p
print('PASS ignore rules protect synthetic private/config/ownership/backup/test/dependency paths')

docs = [root/'README.md', *sorted((root/'docs').rglob('*.md')), *sorted((root/'rebuild').glob('*.md'))]
for p in docs:
    for href in re.findall(r'\]\(([^)]+)\)', p.read_text()):
        target = href.split('#')[0]
        if not target or re.match(r'\w+://|mailto:', target):
            continue
        assert (p.parent/urllib.parse.unquote(target)).is_file(), (str(p.relative_to(root)), target)
print('PASS local document links resolve; historical course documents are outside current deployment guidance')

pkg = json.loads((root/'rebuild/package.json').read_text())
lock = json.loads((root/'rebuild/package-lock.json').read_text())
assert pkg['devDependencies']['vite'] == '8.3.5'
assert lock['packages']['']['devDependencies'] == pkg['devDependencies']
assert lock['packages']['']['dependencies'] == pkg['dependencies']
for file in (root/'rebuild/launcher').glob('*.ps*'):
    assert file.read_bytes().startswith(b'\xef\xbb\xbf'), file.name
print('PASS package/lock pins agree; launcher PowerShell files preserve UTF-8 BOM')

high_confidence = re.compile(r'gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|sk-[A-Za-z0-9_-]{20,}|-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----')
for p in tracked:
    file = root/p
    if file.suffix in ('.php', '.js', '.jsx', '.json', '.md', '.txt', '.ps1', '.psm1', '.sql'):
        assert not high_confidence.search(file.read_text(errors='ignore')), 'Credential pattern in '+p
print('PASS scoped token/private-key scan of staged source/docs; not a guarantee about historical Git data')
git('diff', '--cached', '--check')
print('PASS staged diff whitespace check')
