#!/usr/bin/env python3
"""Capture Chrome Default's X session as a private, unverified candidate.

No network requests, browser changes, or publishing. Verify the expected account
over HTTP before activating storage/sessions/x-chrome.json as Postslop's session.
Only X cookies are decrypted; no Keychain or cookie values are printed.
"""

from __future__ import annotations

import argparse
import ctypes
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import sqlite3
import subprocess
import sys
import time

from import_firefox_sessions import ImportFailure, SESSION_DIRECTORY, atomic_json, database_snapshot


DEFAULT_PROFILE = Path.home() / "Library/Application Support/Google/Chrome/Default"
CHROME_EPOCH_OFFSET = 11644473600


def chrome_key() -> bytes:
    try:
        result = subprocess.run(
            ["/usr/bin/security", "find-generic-password", "-w", "-s", "Chrome Safe Storage"],
            capture_output=True, timeout=30, check=False,
        )
    except subprocess.TimeoutExpired:
        raise ImportFailure("Chrome Safe Storage access timed out; check the macOS Keychain prompt and retry.") from None
    password = result.stdout.removesuffix(b"\n")
    if result.returncode or not password:
        raise ImportFailure("Chrome Safe Storage was unavailable or access was denied.")
    # Chromium's v10 macOS derivation (not the Linux fallback password):
    # https://chromium.googlesource.com/chromium/src/+/ec82d4ab8/components/os_crypt/os_crypt_mac.mm
    return hashlib.pbkdf2_hmac("sha1", password, b"saltysalt", 1003, dklen=16)


def decrypt_cookie(encrypted: bytes, host: str, key: bytes) -> str:
    if not encrypted.startswith(b"v10") or len(encrypted) <= 3 or (len(encrypted) - 3) % 16:
        raise ImportFailure("Unsupported Chrome cookie encryption.")
    library = ctypes.CDLL("/usr/lib/system/libcommonCrypto.dylib")
    crypt = library.CCCrypt
    crypt.argtypes = [
        ctypes.c_uint32, ctypes.c_uint32, ctypes.c_uint32, ctypes.c_void_p,
        ctypes.c_size_t, ctypes.c_void_p, ctypes.c_void_p, ctypes.c_size_t,
        ctypes.c_void_p, ctypes.c_size_t, ctypes.POINTER(ctypes.c_size_t),
    ]
    crypt.restype = ctypes.c_int32
    ciphertext = encrypted[3:]
    output = ctypes.create_string_buffer(len(ciphertext))
    length = ctypes.c_size_t()
    # kCCDecrypt=1, kCCAlgorithmAES=0, kCCOptionPKCS7Padding=1 (CBC default).
    status = crypt(1, 0, 1, key, len(key), b" " * 16, ciphertext, len(ciphertext),
                   output, len(output), ctypes.byref(length))
    if status:
        raise ImportFailure("Chrome cookie decryption failed.")
    plaintext = output.raw[:length.value]
    # Schema 24 binds each encrypted value to its host via a SHA256 prefix.
    # https://chromium.googlesource.com/chromium/src/+/refs/heads/main/net/extras/sqlite/sqlite_persistent_cookie_store.cc
    if not hmac.compare_digest(plaintext[:32], hashlib.sha256(host.encode()).digest()):
        raise ImportFailure("Chrome cookie host verification failed.")
    try:
        return plaintext[32:].decode("utf-8")
    except UnicodeDecodeError:
        raise ImportFailure("Chrome cookie contains invalid text.") from None


def read_cookies(connection: sqlite3.Connection, key: bytes, domain: str = "x.com") -> tuple[list[dict], dict]:
    version = connection.execute("SELECT value FROM meta WHERE key = 'version'").fetchone()
    required = {"host_key", "name", "value", "encrypted_value", "path", "expires_utc",
                "is_persistent", "is_secure", "is_httponly", "samesite", "top_frame_site_key"}
    columns = {row[1] for row in connection.execute("PRAGMA table_info(cookies)")}
    if version is None or str(version[0]) != "24" or not required.issubset(columns):
        raise ImportFailure("Unsupported Chrome cookie schema; only version 24 is supported.")
    rows = connection.execute("SELECT * FROM cookies WHERE host_key IN (?, ?)", (domain, "." + domain))
    cookies = []
    skipped = {"partitioned": 0, "multiAccount": 0, "expired": 0}
    now = time.time()
    for row in rows:
        if row["top_frame_site_key"]:
            skipped["partitioned"] += 1
            continue
        if domain == "x.com" and row["name"] == "auth_multi":
            skipped["multiAccount"] += 1
            continue
        expires = row["expires_utc"] / 1_000_000 - CHROME_EPOCH_OFFSET if row["is_persistent"] else -1
        if row["is_persistent"] and expires <= now:
            skipped["expired"] += 1
            continue
        same_site = {-1: "Lax", 0: "None", 1: "Lax", 2: "Strict"}.get(row["samesite"])
        if same_site is None or not all(isinstance(row[name], str) for name in ("host_key", "name", "path")):
            raise ImportFailure("Chrome cookie contains unsupported metadata.")
        if row["value"] or not isinstance(row["encrypted_value"], bytes):
            raise ImportFailure("Chrome cookie has an unsupported value format.")
        cookies.append({
            "name": row["name"], "value": decrypt_cookie(row["encrypted_value"], row["host_key"], key),
            "domain": row["host_key"], "path": row["path"], "expires": expires,
            "httpOnly": bool(row["is_httponly"]), "secure": bool(row["is_secure"]), "sameSite": same_site,
        })
    return cookies, skipped


def require_x_sign_in(cookies: list[dict]) -> None:
    for name in ("auth_token", "ct0", "twid"):
        matching = [cookie for cookie in cookies if cookie["name"] == name]
        if len(matching) != 1 or not matching[0]["value"] or matching[0]["path"] != "/":
            raise ImportFailure("Chrome has no unambiguous persisted X sign-in.")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--profile", required=True, type=Path, help="Explicit Chrome Default profile directory")
    parser.add_argument("--expected-handle", required=True, help="X account already verified in Chrome")
    parser.add_argument("--check-only", action="store_true", help="Decrypt and validate without exporting")
    arguments = parser.parse_args()
    handle = arguments.expected_handle.lstrip("@")
    if re.fullmatch(r"[A-Za-z0-9_]{1,15}", handle) is None:
        parser.error("Expected X handle must contain 1–15 letters, numbers, or underscores.")
    if sys.platform != "darwin" or arguments.profile.resolve() != DEFAULT_PROFILE.resolve():
        raise ImportFailure("This importer supports only the explicitly selected Chrome Default profile on macOS.")
    os.umask(0o077)
    with database_snapshot(arguments.profile / "Cookies") as connection:
        cookies, skipped = read_cookies(connection, chrome_key())
    require_x_sign_in(cookies)
    if not arguments.check_only:
        if SESSION_DIRECTORY.is_symlink():
            raise ImportFailure("Session directory must not be a symbolic link.")
        SESSION_DIRECTORY.mkdir(mode=0o700, parents=True, exist_ok=True)
        os.chmod(SESSION_DIRECTORY, 0o700)
        atomic_json(SESSION_DIRECTORY / "x-chrome.json", {"cookies": cookies, "origins": []})
    print(json.dumps({
        "exported": not arguments.check_only, "source": "chrome-default", "expectedHandle": handle,
        "cookieCount": len(cookies), "skippedCookies": skipped, "file": "x-chrome.json",
        "importedSessionVerified": False, "note": "Verify identity over HTTP before activating this candidate.",
    }, indent=2))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except ImportFailure as error:
        print(f"Import failed: {str(error).replace('Firefox', 'Chrome')}", file=sys.stderr)
        raise SystemExit(1)
    except (OSError, sqlite3.Error, subprocess.TimeoutExpired, ValueError, TypeError):
        print("Import failed: unable to capture Chrome X cookies or write the private candidate.", file=sys.stderr)
        raise SystemExit(1)
