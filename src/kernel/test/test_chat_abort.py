import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from flask import Flask

from kuwa.kernel.routes import chat as chat_module
from kuwa.kernel.routes.chat import chat, parse_history_ids


class TestParseHistoryIds(unittest.TestCase):
    def test_accepts_json_list(self):
        self.assertEqual(parse_history_ids("[1, 2, 3]"), [1, 2, 3])

    def test_accepts_single_history_id(self):
        self.assertEqual(parse_history_ids("1"), [1])

    def test_accepts_json_string_history_id(self):
        self.assertEqual(parse_history_ids('"1"'), [1])

    def test_rejects_non_integer_values(self):
        self.assertIsNone(parse_history_ids('{"id": 1}'))
        self.assertIsNone(parse_history_ids("1.5"))
        self.assertIsNone(parse_history_ids("[1, 1.5]"))
        self.assertIsNone(parse_history_ids("not-a-history-id"))

    def test_rejects_booleans(self):
        self.assertIsNone(parse_history_ids("true"))
        self.assertIsNone(parse_history_ids("[1, false]"))


class TestAbortRoute(unittest.TestCase):
    def setUp(self):
        self.app = Flask(__name__)
        self.app.register_blueprint(chat)
        self.client = self.app.test_client()
        self.original_data = dict(chat_module.data)
        chat_module.data.clear()

    def tearDown(self):
        chat_module.data.clear()
        chat_module.data.update(self.original_data)

    def test_abort_forwards_valid_history_ids(self):
        chat_module.data["test-model"] = [
            ["http://executor.example", "BUSY", "123", "test-user"],
            ["http://other.example", "BUSY", "456", "other-user"],
        ]

        with patch.object(chat_module.requests, "get") as mock_get:
            response = self.client.post(
                "/abort",
                data={
                    "history_id": "[123]",
                    "user_id": "test-user",
                },
            )

        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.data, b"Success")
        mock_get.assert_called_once_with("http://executor.example/abort", timeout=10)

    def test_abort_rejects_eval_payload_without_side_effects(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            marker = Path(tmpdir) / "eval_marker"
            payload = (
                f'(__import__("pathlib").Path("{marker}").write_text("executed"), '
                "[])[1]"
            )

            response = self.client.post(
                "/abort",
                data={
                    "history_id": payload,
                    "user_id": "test-user",
                },
            )

            self.assertEqual(response.status_code, 400)
            self.assertEqual(response.data, b"Invalid history_id")
            self.assertFalse(marker.exists())


if __name__ == "__main__":
    unittest.main()
