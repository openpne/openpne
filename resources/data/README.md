# common-passwords.txt

Offline blocklist of commonly-used passwords, consumed by `App\Support\CommonPasswordList`
(via `App\Rules\NotCommonPassword`) to satisfy NIST SP 800-63B-4 §3.1.1.2 and ASVS 5.0 6.2.4.

- 100,000 entries, one per line, LF-terminated UTF-8, no BOM.
- All lowercase; each entry is at least 8 characters.
- Byte-sorted ascending (stable diffs); membership is tested as a set, so order is not semantic.

## Source

[SecLists](https://github.com/danielmiessler/SecLists) —
`Passwords/Common-Credentials/10-million-password-list-top-1000000.txt`.

That file was **removed upstream on 2025-05-08** (as a duplicate of
`xato-net-10-million-passwords-1000000.txt`), so it no longer exists at HEAD:

- Commit that last touched the path (the removal): `4ff3ff8ef6b1c429db800e4a9b9deeac7425c5b6`
- Last commit where the file still existed (its parent, downloaded from): `2d5dc7504a40962c53f932a6d9d5ece4b213dfc6`
- Content (git blob) SHA of the retrieved file: `23822b95ef15ee4fdf6d2bd6d24a854056511666`

## License

SecLists is MIT-licensed:

```
MIT License

Copyright (c) 2018 Daniel Miessler

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

## Regeneration

The commands actually run to produce the current file:

```sh
# Pin the SHA (this now returns the REMOVAL commit; download from its parent instead).
SHA=$(gh api 'repos/danielmiessler/SecLists/commits?path=Passwords/Common-Credentials/10-million-password-list-top-1000000.txt&per_page=1' --jq '.[0].sha')
PARENT=$(gh api "repos/danielmiessler/SecLists/commits/$SHA" --jq '.parents[0].sha')

curl -fsSL \
  "https://raw.githubusercontent.com/danielmiessler/SecLists/$PARENT/Passwords/Common-Credentials/10-million-password-list-top-1000000.txt" \
  -o top1m.txt

python3 - top1m.txt resources/data/common-passwords.txt <<'PY'
import sys
src, dst = sys.argv[1], sys.argv[2]
seen = {}
with open(src, 'r', encoding='utf-8') as f:
    for line in f:
        line = line.rstrip('\n').rstrip('\r')   # strip trailing newline + CR
        if len(line) < 8:                        # character length
            continue
        low = line.lower()
        if low not in seen:                      # dedupe, preserving first-seen (frequency) order
            seen[low] = None
        if len(seen) >= 100000:
            break
kept = list(seen.keys())[:100000]
kept.sort(key=lambda s: s.encode('utf-8'))       # byte-sort ascending
with open(dst, 'w', encoding='utf-8', newline='\n') as out:
    for w in kept:
        out.write(w + '\n')
PY
```

Pipeline: strip CR → keep lines of at least 8 characters → lowercase → dedupe preserving
first-seen (frequency) order → take the first 100,000 → byte-sort ascending → write.

## Regeneration policy

Not regenerated on any schedule and never fetched in CI — the build makes no external network
calls by design. Regenerate only when:

- release preparation finds upstream drift (roughly annual), or
- operational signals (reject patterns in the security log, an incident) suggest the floor is weak.
