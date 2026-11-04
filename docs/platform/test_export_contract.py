import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import zipfile

spec = importlib.util.spec_from_file_location('export_contract', Path(__file__).with_name('export_contract.py'))
export = importlib.util.module_from_spec(spec)
spec.loader.exec_module(export)

class BundleTest(unittest.TestCase):
    def test_changed_or_missing_contract_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / 'bundle.zip'
            with zipfile.ZipFile(target, 'w') as archive:
                archive.writestr('contract.json', 'old')
            with patch.object(export, 'OUTPUT', target):
                export.verify({'contract.json': b'old'})
                with self.assertRaises(ValueError):
                    export.verify({'contract.json': b'new'})
                with self.assertRaises(ValueError):
                    export.verify({'missing.json': b'old'})

if __name__ == '__main__':
    unittest.main()
