#!/usr/bin/env python3
# The commands of docs/installing-on-a-shared-host.md, as a reader pastes them, for
# release-archive.sh shared-update.
#
#   doc-blocks.py <doc> <sqlite|mysql> <version> <database> <files> [--arm-only] <heading>...
#
# Prints the lines of the fenced blocks under each heading, in the doc's order. A block naming a
# .sqlite file is SQLite's, one naming mysql-backup.cnf is MySQL's, any other both databases' (with
# --arm-only, neither's). The placeholders take the version and the database given, each download
# copies its file from <files>, and the block that checks with shasum is left out. Exits 1 when a
# block of the doc holds what a csh login shell reads otherwise than sh does.
import os
import re
import sys

PLACEHOLDER = '4.0.0-alpha.N'
CSH_UNSAFE = re.compile(r'\$\(|`|2>&1|!|#|<<|^\s*[A-Za-z_][A-Za-z0-9_]*=')


def blocks(path):
    heading, lines = None, None
    with open(path, encoding='utf-8') as doc:
        for line in doc.read().split('\n'):
            if lines is not None:
                if line.startswith('```'):
                    yield heading, lines
                    lines = None
                else:
                    lines.append(line)
            elif line.startswith('```'):
                lines = []
            elif re.match(r'#{1,6} ', line):
                heading = line.lstrip('#').strip()


def main(argv):
    arm_only = '--arm-only' in argv
    argv = [a for a in argv if a != '--arm-only']
    if len(argv) < 6 or argv[1] not in ('sqlite', 'mysql'):
        sys.exit('usage: doc-blocks.py <doc> <sqlite|mysql> <version> <database> <files> [--arm-only] <heading>...')
    doc, db, version, database, files, *headings = argv
    found = list(blocks(doc))

    unsafe = [(h, line) for h, b in found for line in b if CSH_UNSAFE.search(line)]
    for h, line in unsafe:
        print(f'::error::{doc}, "{h}": a csh login shell does not read this as sh does: {line}', file=sys.stderr)
    missing = [h for h in headings if h not in {h for h, _ in found}]
    for h in missing:
        print(f'::error::{doc} has no block under "{h}"', file=sys.stderr)
    if unsafe or missing:
        return 1

    for h, b in found:
        body = '\n'.join(b)
        arm = 'sqlite' if '.sqlite' in body else 'mysql' if 'mysql-backup.cnf' in body else None
        if h not in headings or 'shasum' in body or (arm or ('-' if arm_only else db)) != db:
            continue
        for line in b:
            line = re.sub(r'\bDATABASE\b', database, line.replace(PLACEHOLDER, version))
            if line.startswith('curl '):
                line = "cp '%s' ." % os.path.join(files, line.rsplit('/', 1)[1])
            print(line)
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
