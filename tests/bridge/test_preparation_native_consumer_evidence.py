"""CLI regression for the approved public readiness path, without DB or HTTP."""

import json
from pathlib import Path
import subprocess
import sys
import unittest


PHP_ROOT = Path(__file__).absolute().parents[2]
FAMILY_ROOT = PHP_ROOT.parent
CLI = PHP_ROOT / 'tests/support/preparation_native_consumer_evidence.py'
# The approved dispatch contract is independent of the resolver implementation.
APPROVED = (
    'E:/object/BeiMi/.scratch/native-runtime-recovery-03/'
    'native-claim-consumer-ready-03.json'
)


class ConsumerReadinessPathTest(unittest.TestCase):
    def invoke(self, cwd, path=APPROVED):
        return subprocess.run(
            [sys.executable, '-X', 'utf8', str(CLI), '--consumer-ready-path', path],
            cwd=cwd, capture_output=True, text=True, encoding='utf-8', timeout=10,
        )

    def test_all_execution_directories_observe_the_approved_absolute_path(self):
        for cwd in [FAMILY_ROOT, PHP_ROOT, FAMILY_ROOT / 'BeiMi-uniapp', PHP_ROOT / 'tests/bridge']:
            with self.subTest(cwd=str(cwd)):
                result = self.invoke(cwd)
                self.assertEqual(result.returncode, 0, result.stderr)
                observed = json.loads(result.stdout)
                self.assertEqual(observed['path'], APPROVED)
                self.assertEqual(observed['exists'], Path(APPROVED).exists())
                self.assertIn('observedAt', observed)
                self.assertEqual(observed['cwd'], str(cwd))
                if not observed['exists']:
                    self.assertIsNone(observed['modifiedAt'])

    def test_relative_and_other_absolute_paths_fail_without_observation(self):
        paths = [
            '../.scratch/native-runtime-recovery-03/native-claim-consumer-ready-03.json',
            'E:/object/BeiMi/BeiMi-PHP/.scratch/native-runtime-recovery-03/native-claim-consumer-ready-03.json',
            'E:/object/BeiMi/BeiMi-PHP/../.scratch/native-runtime-recovery-03/native-claim-consumer-ready-03.json',
            'E:/object/BeiMi/BeiMi-uniapp/docs/ui-previews/payable-preparation-20261023/implementation/final-integration-20261026/native-claim-consumer-ready.json',
        ]
        for path in paths:
            with self.subTest(path=path):
                result = self.invoke(PHP_ROOT, path)
                self.assertEqual(result.returncode, 2)
                self.assertEqual(result.stdout, '')
                self.assertEqual(
                    result.stderr,
                    'consumer readiness path rejected or unreadable\n',
                )


if __name__ == '__main__':
    unittest.main(verbosity=2)
