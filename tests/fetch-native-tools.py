"""Fetch only pinned public core sources; execute nothing from the network."""
import hashlib
import json
import pathlib
import sys
import urllib.request

target = pathlib.Path(sys.argv[1]).resolve()
manifest = json.loads(pathlib.Path(__file__).with_name('native-tools-sources.json').read_text())
for entry in manifest['sources']:
    relative = pathlib.PurePosixPath(entry['version'], entry['path'])
    if relative.is_absolute() or '..' in relative.parts:
        raise ValueError('Unsafe source path')
    destination = target.joinpath(*relative.parts)
    payload = destination.read_bytes() if destination.is_file() else b''
    if hashlib.sha256(payload).hexdigest() != entry['sha256']:
        with urllib.request.urlopen(entry['url'], timeout=30) as response:
            payload = response.read()
        if hashlib.sha256(payload).hexdigest() != entry['sha256']:
            raise ValueError('Upstream checksum mismatch: ' + entry['url'])
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes(payload)
    print('VERIFIED', str(relative))
