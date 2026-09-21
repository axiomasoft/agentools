#!/usr/bin/env python3
"""Unit tests for tools/private_rc_gate.py (P9.5)."""

from __future__ import annotations

import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from tools.private_rc_gate import PrivateRcGateTests  # noqa: E402


def main() -> int:
    suite = unittest.defaultTestLoader.loadTestsFromTestCase(PrivateRcGateTests)
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    return 0 if result.wasSuccessful() else 1


if __name__ == "__main__":
    raise SystemExit(main())
