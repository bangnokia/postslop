#!/usr/bin/env python3
"""Create isolated fake browser sessions for the fake sites in tests/fakes."""
import json
import os
from pathlib import Path

root = Path(__file__).resolve().parents[1]

def private_text(path, text):
    path.write_text(text)
    os.chmod(path, 0o600)

def cookie(name, value, domain):
    return {'name': name, 'value': value, 'domain': domain, 'path': '/', 'expires': -1, 'secure': False, 'httpOnly': True, 'sameSite': 'Lax'}

account = {'did': 'did:plc:fixture', 'handle': 'tester.test', 'accessJwt': 'fixture-access', 'refreshJwt': 'fixture-refresh', 'pdsUrl': 'https://bsky.social', 'service': 'https://bsky.social'}
bluesky = {'session': {'currentAccount': {'did': account['did']}, 'accounts': [account]}}
private_text(root / 'storage/test-sessions.json', json.dumps({'accounts': {
    'x': {'handle': 'tester', 'session': {'cookies': [cookie('auth_token', 'fixture-auth', '.x.com'), cookie('ct0', 'fixture-csrf', '.x.com')], 'origins': []}},
    'bluesky': {'handle': 'tester.test', 'session': {'cookies': [], 'origins': [{'origin': 'https://bsky.app', 'localStorage': [{'name': 'BSKY_STORAGE', 'value': json.dumps(bluesky)}]}]}},
    'dev-null': {'handle': 'tester', 'session': {'cookies': [cookie('dev_null_session', 'fixture-session', '127.0.0.1'), cookie('XSRF-TOKEN', 'fixture-xsrf', '127.0.0.1')], 'origins': []}},
}}))
private_text(root / 'storage/test-client-token', 'f' * 64)
print('Prepared isolated fake sessions in storage/test-sessions.json.')
