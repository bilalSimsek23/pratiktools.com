#!/usr/bin/env python3
"""Pre-push secret scan of the commits about to be pushed.

Usage: python3 -I scripts/security/secret-scan.py [<base-ref>]   (default: origin/main)

Scans every added line and every added/renamed file name in <base>..HEAD.
Exit codes: 0 = PASS (no finding), 1 = findings, 2 = the scan itself failed.
Never prints a matched value, only the file, line number and rule.
"""
import re
import subprocess
import sys

RULES = {
    'aws_access_key': re.compile(r'\b(AKIA|ASIA)[0-9A-Z]{16}\b'),
    'private_key': re.compile(r'-----BEGIN [A-Z ]*PRIVATE KEY-----'),
    'onesignal_key': re.compile(r'os_v2_app_[A-Za-z0-9]{40,}'),
    'github_token': re.compile(r'\bgh[pousr]_[A-Za-z0-9]{36,}\b'),
    'slack_token': re.compile(r'\bxox[abprs]-[A-Za-z0-9-]{10,}'),
    'generic_assignment': re.compile(r'(?i)\b(secret|password|api_key|access_key|private_key)\b\s*[=:]\s*["\'][^"\'\s]{20,}["\']'),
    'laravel_app_key': re.compile(r'base64:[A-Za-z0-9+/]{43}='),
}
ALLOW = re.compile(r'(?i)(fake|example|test|dummy|never-real|placeholder|str_repeat|AKIAFAKE|AKIDEXAMPLE)')
SECRET_FILES = re.compile(r'(^|/)(\.env(\.[a-z0-9]+)?|.*\.pem|.*\.key|credentials(\.json)?|id_rsa.*)$')
SAFE_ENV = re.compile(r'\.env\.(example|production\.example|testing)$')


def git(*args):
    return subprocess.run(['git', *args], check=True, capture_output=True, text=True).stdout


def main():
    base = sys.argv[1] if len(sys.argv) > 1 else 'origin/main'
    try:
        names = git('diff', '--name-only', '--diff-filter=AR', f'{base}..HEAD').split()
        diff = git('diff', '--unified=0', '--no-color', f'{base}..HEAD')
    except Exception as e:  # noqa: BLE001
        print(f'SCAN ERROR: {type(e).__name__}', file=sys.stderr)
        return 2

    findings = []
    for name in names:
        if SECRET_FILES.search(name) and not SAFE_ENV.search(name):
            findings.append((name, 0, 'secret_file'))

    current, line_no, scanned = None, 0, set()
    for line in diff.splitlines():
        if line.startswith('+++ '):
            current = line[6:] if line.startswith('+++ b/') else None
            if current:
                scanned.add(current)
            continue
        if line.startswith('@@'):
            m = re.search(r'\+(\d+)', line)
            line_no = int(m.group(1)) if m else 0
            continue
        if line.startswith('+') and current:
            text = line[1:]
            for rule, pattern in RULES.items():
                if pattern.search(text) and not ALLOW.search(text):
                    findings.append((current, line_no, rule))
            line_no += 1

    print(f'scanned_files={len(scanned)} findings={len(findings)}')
    for f, n, rule in findings:
        print(f'  {f}:{n} {rule}')
    return 1 if findings else 0


if __name__ == '__main__':
    sys.exit(main())
