#!/usr/bin/env python3
"""Asset Watch - translation helper (development only, standard library only).

Usage:
    python tools/i18n.py extract   # scan sources, write locales/assetwatch.pot, merge into *.po
    python tools/i18n.py compile   # compile every locales/*.po into .mo (GLPI loads .mo)
    python tools/i18n.py check     # exit 1 if a .po has untranslated or obsolete entries

Only strings of the "assetwatch" domain are handled:
    __('text', 'assetwatch')
    _n('singular', 'plural', $n, 'assetwatch')
"""

import os
import re
import struct
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LOCALES = os.path.join(ROOT, "locales")
POT = os.path.join(LOCALES, "assetwatch.pot")
SCAN_DIRS = ["inc", "front", "ajax", "templates", "src"]
SCAN_FILES = ["setup.php", "hook.php"]
LANGUAGES = {
    "zh_TW": "Plural-Forms: nplurals=1; plural=0;",
    "en_GB": "Plural-Forms: nplurals=2; plural=(n != 1);",
}

STR = r"'((?:[^'\\]|\\.)*)'"
RE_SINGULAR = re.compile(r"\b__\(\s*" + STR + r"\s*,\s*'assetwatch'\s*\)")
RE_PLURAL = re.compile(r"\b_n\(\s*" + STR + r"\s*,\s*" + STR + r"\s*,[^,()]+?,\s*'assetwatch'\s*\)", re.S)


def unescape(s):
    return s.replace("\\'", "'").replace("\\\\", "\\")


def scan():
    """Return ordered dict: key -> (msgid, msgid_plural or None, [locations])."""
    entries = {}
    paths = [os.path.join(ROOT, f) for f in SCAN_FILES]
    for d in SCAN_DIRS:
        for base, _dirs, files in os.walk(os.path.join(ROOT, d)):
            for name in sorted(files):
                if name.endswith((".php", ".twig")):
                    paths.append(os.path.join(base, name))
    for path in sorted(paths):
        if not os.path.isfile(path):
            continue
        with open(path, encoding="utf-8") as fh:
            text = fh.read()
        rel = os.path.relpath(path, ROOT).replace(os.sep, "/")
        for m in RE_SINGULAR.finditer(text):
            msgid = unescape(m.group(1))
            line = text.count("\n", 0, m.start()) + 1
            entries.setdefault(msgid, [msgid, None, []])[2].append(f"{rel}:{line}")
        for m in RE_PLURAL.finditer(text):
            msgid, plural = unescape(m.group(1)), unescape(m.group(2))
            line = text.count("\n", 0, m.start()) + 1
            entry = entries.setdefault(msgid, [msgid, plural, []])
            entry[1] = plural
            entry[2].append(f"{rel}:{line}")
    return entries


def po_quote(s):
    s = s.replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n")
    return f'"{s}"'


def po_unquote(s):
    s = s.strip()[1:-1]
    out, i = [], 0
    while i < len(s):
        c = s[i]
        if c == "\\" and i + 1 < len(s):
            n = s[i + 1]
            out.append({"n": "\n", "t": "\t", '"': '"', "\\": "\\"}.get(n, n))
            i += 2
        else:
            out.append(c)
            i += 1
    return "".join(out)


def read_po(path):
    """Return (header_lines, {msgid: {'plural': str|None, 'msgstr': [str,...]}})."""
    result = {}
    if not os.path.exists(path):
        return result
    cur, field = None, None
    with open(path, encoding="utf-8") as fh:
        lines = fh.read().splitlines()

    def flush():
        if cur is not None and cur.get("msgid"):
            result[cur["msgid"]] = cur

    for raw in lines:
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        if line.startswith("msgid_plural "):
            cur["plural"] = po_unquote(line[13:])
            field = ("plural", None)
        elif line.startswith("msgid "):
            flush()
            cur = {"msgid": po_unquote(line[6:]), "plural": None, "msgstr": {}}
            field = ("msgid", None)
        elif line.startswith("msgstr["):
            idx = int(line[7:line.index("]")])
            cur["msgstr"][idx] = po_unquote(line[line.index("]") + 2:])
            field = ("msgstr", idx)
        elif line.startswith("msgstr "):
            cur["msgstr"][0] = po_unquote(line[7:])
            field = ("msgstr", 0)
        elif line.startswith('"') and cur is not None:
            value = po_unquote(line)
            if field[0] == "msgstr":
                cur["msgstr"][field[1]] += value
            else:
                cur[field[0]] += value
    flush()
    for entry in result.values():
        entry["msgstr"] = [entry["msgstr"][k] for k in sorted(entry["msgstr"])]
    return result


def write_po(path, entries, header_extra, existing, nplurals):
    out = [
        "# Asset Watch - GLPI plugin translations",
        "# This file is distributed under the same license as the plugin (GPL-3.0-or-later).",
        'msgid ""',
        'msgstr ""',
        '"Project-Id-Version: assetwatch\\n"',
        '"MIME-Version: 1.0\\n"',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        '"Content-Transfer-Encoding: 8bit\\n"',
    ]
    if header_extra:
        out.append(po_quote(header_extra + "\n"))
    out.append("")
    for msgid, plural, locations in entries.values():
        out.append("#: " + " ".join(locations))
        old = existing.get(msgid, {}).get("msgstr", [])
        out.append("msgid " + po_quote(msgid))
        if plural is None:
            out.append("msgstr " + po_quote(old[0] if old else ""))
        else:
            out.append("msgid_plural " + po_quote(plural))
            for i in range(nplurals):
                value = old[i] if i < len(old) else (old[0] if old else "")
                out.append(f"msgstr[{i}] " + po_quote(value))
        out.append("")
    with open(path, "w", encoding="utf-8", newline="\n") as fh:
        fh.write("\n".join(out))


def extract():
    entries = scan()
    os.makedirs(LOCALES, exist_ok=True)
    write_po(POT, entries, "", {}, 2)
    for lang, plural_header in LANGUAGES.items():
        path = os.path.join(LOCALES, f"{lang}.po")
        nplurals = int(re.search(r"nplurals=(\d+)", plural_header).group(1))
        write_po(path, entries, plural_header, read_po(path), nplurals)
    print(f"{len(entries)} strings extracted")


def compile_mo():
    for name in sorted(os.listdir(LOCALES)):
        if not name.endswith(".po"):
            continue
        lang = name[:-3]
        catalog = read_po(os.path.join(LOCALES, name))
        header = "Content-Type: text/plain; charset=UTF-8\n" + LANGUAGES.get(lang, "") + "\n"
        pairs = [(b"", header.encode("utf-8"))]
        for msgid, entry in catalog.items():
            strs = [s for s in entry["msgstr"] if s]
            if not strs:
                continue  # untranslated: gettext falls back to msgid
            key = msgid if entry["plural"] is None else msgid + "\0" + entry["plural"]
            pairs.append((key.encode("utf-8"), "\0".join(entry["msgstr"]).encode("utf-8")))
        pairs.sort(key=lambda p: p[0])
        write_mo(os.path.join(LOCALES, f"{lang}.mo"), pairs)
        print(f"{lang}.mo: {len(pairs) - 1} translations")


def write_mo(path, pairs):
    n = len(pairs)
    key_table_off = 7 * 4
    value_table_off = key_table_off + n * 8
    data_off = value_table_off + n * 8
    keys_blob, values_blob = b"", b""
    key_entries, value_entries = [], []
    for key, _ in pairs:
        key_entries.append((len(key), data_off + len(keys_blob)))
        keys_blob += key + b"\0"
    values_start = data_off + len(keys_blob)
    for _, value in pairs:
        value_entries.append((len(value), values_start + len(values_blob)))
        values_blob += value + b"\0"
    out = struct.pack("<7I", 0x950412DE, 0, n, key_table_off, value_table_off, 0, 0)
    for length, offset in key_entries:
        out += struct.pack("<2I", length, offset)
    for length, offset in value_entries:
        out += struct.pack("<2I", length, offset)
    out += keys_blob + values_blob
    with open(path, "wb") as fh:
        fh.write(out)


def check():
    expected = set(scan().keys())
    ok = True
    path = os.path.join(LOCALES, "zh_TW.po")
    catalog = read_po(path)
    missing = sorted(k for k in expected if not any(catalog.get(k, {}).get("msgstr", [])))
    obsolete = sorted(set(catalog) - expected)
    if missing:
        ok = False
        print("zh_TW untranslated:\n  " + "\n  ".join(missing))
    if obsolete:
        ok = False
        print("zh_TW obsolete:\n  " + "\n  ".join(obsolete))
    print("i18n OK" if ok else "i18n check failed (run: python tools/i18n.py extract)")
    return 0 if ok else 1


if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else ""
    if cmd == "extract":
        extract()
    elif cmd == "compile":
        compile_mo()
    elif cmd == "check":
        sys.exit(check())
    else:
        print(__doc__)
        sys.exit(2)
