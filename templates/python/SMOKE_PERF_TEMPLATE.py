#!/usr/bin/env python3
"""TAGS: perf,smoke,slow
SCOPE: integration
"""

from time import perf_counter
import unittest


class SmokePerfTemplateTest(unittest.TestCase):
    def test_perf_threshold_template(self):
        max_ms = int(__import__('os').environ.get('TEST_PERF_MAX_MS', '800'))

        t0 = perf_counter()
        _ = sum(range(1000))
        elapsed_ms = int((perf_counter() - t0) * 1000)

        self.assertLessEqual(
            elapsed_ms,
            max_ms,
            f'perf threshold exceeded | elapsed_ms={elapsed_ms} max_ms={max_ms}',
        )


if __name__ == '__main__':
    unittest.main(verbosity=2)
