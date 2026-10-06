#!/usr/bin/env python3
"""Unit tests for tools/locked_cli.py."""

from __future__ import annotations

import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from tools.locked_cli import LockedCliTests  # noqa: E402,F401


if __name__ == "__main__":
    unittest.main()
