#!/usr/bin/env python3
"""TAGS: integration,critical
SCOPE: integration
"""

import unittest


class IntegrationTemplateTest(unittest.TestCase):
    def test_integration_template(self):
        response = {"ok": True, "code": "OK"}
        self.assertTrue(response["ok"])
        self.assertEqual(response["code"], "OK")


if __name__ == '__main__':
    unittest.main(verbosity=2)
