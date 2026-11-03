import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[3]
spec=importlib.util.spec_from_file_location('host_handoff',ROOT/'docs/platform/integrations/handoff.py')
handoff=importlib.util.module_from_spec(spec)
spec.loader.exec_module(handoff)

class HandoffTest(unittest.TestCase):
    def test_missing_and_changed_inputs_are_not_accepted(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory); source=root/'input.txt';source.write_text('first')
            out=root/'handoff.json'
            with patch.object(handoff,'ROOT',root), patch.object(handoff,'FILES',('input.txt',)), patch.object(handoff,'OUT',out):
                with patch('sys.argv',['handoff.py']): handoff.main()
                with patch('sys.argv',['handoff.py','--check']): handoff.main()
                source.write_text('changed')
                with patch('sys.argv',['handoff.py','--check']):
                    with self.assertRaises(SystemExit): handoff.main()
                source.unlink()
                with self.assertRaises(FileNotFoundError): handoff.build()

if __name__=='__main__': unittest.main()
