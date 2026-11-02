"""Read only public readiness file metadata; never arm or read credentials."""

import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path


def observe(approved_path):
    # Lexical absolute anchoring avoids cwd and the observed Windows resolve
    # behavior for an absent target. Only the exact dispatch path is accepted.
    family_root = Path(os.path.abspath(__file__)).parents[3]
    target = family_root / (
        '.scratch/native-runtime-recovery-03/'
        'native-claim-consumer-ready-03.json'
    )
    supplied = Path(approved_path)
    if not supplied.is_absolute() or '..' in supplied.parts:
        raise ValueError('an absolute approved path is required')
    if os.path.normcase(str(supplied)) != os.path.normcase(str(target)):
        raise ValueError('path does not match the approved public file')
    try:
        stat = target.stat()
    except FileNotFoundError:
        stat = None
    return {
        'observedAt': datetime.now(timezone.utc).isoformat(),
        'cwd': os.getcwd(),
        'path': target.as_posix(),
        'exists': stat is not None,
        'modifiedAt': (
            datetime.fromtimestamp(stat.st_mtime, timezone.utc).isoformat()
            if stat is not None else None
        ),
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--consumer-ready-path', required=True)
    args = parser.parse_args()
    try:
        result = observe(args.consumer_ready_path)
    except (OSError, ValueError):
        parser.exit(2, 'consumer readiness path rejected or unreadable\n')
    print(json.dumps(result, ensure_ascii=False))


if __name__ == '__main__':
    main()
