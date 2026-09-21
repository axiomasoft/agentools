#!/usr/bin/env python3
"""Regression tests for revision-scoped coverage carriers."""

from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from tools import spec_lint


class CoverageCarrierTests(unittest.TestCase):
    def test_coverage_write_targets_requested_revision(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            revision = root / "spec" / "revisions" / "2026-09"
            current_fixtures = root / "fixtures" / "2026-09"
            frozen_fixtures = root / "fixtures" / "2026-08"
            revision.mkdir(parents=True)
            current_fixtures.mkdir(parents=True)
            frozen_fixtures.mkdir(parents=True)
            frozen_coverage = frozen_fixtures / "coverage.md"
            frozen_coverage.write_text("frozen\n", encoding="utf-8")

            errors: list[str] = []
            spec_lint.apply_coverage_mode(
                revision,
                rules=[],
                coverage={},
                errors=errors,
                write=True,
                check=False,
            )

            self.assertEqual([], errors)
            self.assertEqual("frozen\n", frozen_coverage.read_text(encoding="utf-8"))
            rendered = (current_fixtures / "coverage.md").read_text(encoding="utf-8")
            self.assertIn("ревизии `2026-09`", rendered)
            self.assertIn("spec/revisions/2026-09 --coverage --write", rendered)


if __name__ == "__main__":
    unittest.main()
