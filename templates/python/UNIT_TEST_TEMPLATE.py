#!/usr/bin/env python3
"""TAGS: unit
SCOPE: unit
"""

import unittest


class UnitTemplateTest(unittest.TestCase):
    def test_unit_template(self):
        self.assertEqual(2 + 2, 4)


if __name__ == '__main__':
    unittest.main(verbosity=2)
