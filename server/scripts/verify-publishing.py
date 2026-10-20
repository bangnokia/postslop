#!/usr/bin/env python3
"""Exercise the real local Postslop server against the fake sites in tests/fakes."""
import base64
import json
import os
from pathlib import Path
import urllib.error
import urllib.request
import uuid

root = Path(__file__).resolve().parents[1]
app = os.environ.get('POSTSLOP_TEST_URL', 'http://127.0.0.1:8091').rstrip('/')
simulation = os.environ.get('POSTSLOP_SIMULATION_URL', 'http://127.0.0.1:4900').rstrip('/')
token = os.environ.get('POSTSLOP_TOKEN', 'test-token')

def request(url, data=None, authenticated=False):
    headers = {'Content-Type': 'application/json'}
    if authenticated:
        headers['Authorization'] = 'Bearer ' + token
    body = json.dumps(data).encode() if data is not None else None
    req = urllib.request.Request(url, data=body, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=120) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as error:
        raw = error.read().decode()
        try:
            return error.code, json.loads(raw)
        except json.JSONDecodeError:
            return error.code, {'error': raw[:300]}

def counts():
    return {platform: len(request(simulation + '/' + platform + '/__stats')[1]['posts']) for platform in ['x', 'bluesky', 'dev-null']}

for platform in ['x', 'bluesky', 'dev-null']:
    code, result = request(simulation + '/' + platform + '/__reset', {})
    assert code == 200, (platform, code, result)
code, result = request(app + '/api/v1/accounts', authenticated=True)
assert code == 200 and all(item['available'] for item in result['platforms']), result
assert {item['id']: item['handle'].lstrip('@') for item in result['platforms']} == {'x': 'tester', 'bluesky': 'tester.test', 'dev-null': 'tester'}, 'Refusing to publish fixture posts to non-fixture accounts'
image = {'mimeType': 'image/png', 'alt': 'A one-pixel fixture image', 'data': 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nWQAAAAASUVORK5CYII='}
post = {'requestId': str(uuid.uuid4()), 'text': 'Postslop local simulation: image contract', 'platforms': ['x', 'bluesky', 'dev-null'], 'images': [image]}
code, result = request(app + '/api/v1/posts', post, True)
assert code == 200 and result['status'] == 'published', result
assert counts() == {'x': 1, 'bluesky': 1, 'dev-null': 1}, counts()
_, x_state = request(simulation + '/x/__stats')
_, bsky_state = request(simulation + '/bluesky/__stats')
_, dev_state = request(simulation + '/dev-null/__stats')
assert list(x_state['media'].values())[0]['alt'] == image['alt']
assert bsky_state['posts'][0]['record']['embed']['images'][0]['alt'] == image['alt']
assert dev_state['posts'][0]['images'][0]['description'] == image['alt']
code, repeated = request(app + '/api/v1/posts', post, True)
assert code == 200 and repeated == result and counts() == {'x': 1, 'bluesky': 1, 'dev-null': 1}, repeated
code, conflict = request(app + '/api/v1/posts', {**post, 'text': 'Changed content with same request ID'}, True)
assert code == 409, conflict
code, invalid = request(app + '/api/v1/posts', {**post, 'requestId': str(uuid.uuid4()), 'images': [{**image, 'data': base64.b64encode(b'not an image').decode()}]}, True)
assert code == 422 and counts() == {'x': 1, 'bluesky': 1, 'dev-null': 1}, invalid
uncertain = {'requestId': str(uuid.uuid4()), 'text': '[simulate:unknown] accepted but response lost', 'platforms': ['x'], 'images': []}
code, unknown = request(app + '/api/v1/posts', uncertain, True)
assert code == 200 and unknown['status'] == 'unknown', unknown
code, repeated_unknown = request(app + '/api/v1/posts', uncertain, True)
assert code == 200 and repeated_unknown == unknown and counts()['x'] == 2, repeated_unknown
print('PASS: authenticated accounts; image and alt text on all three destinations; duplicate suppression; conflicting request IDs; invalid images; uncertain write without retry.')
print('This verifies real local HTTP against observed-contract fixtures, not live social publication.')
