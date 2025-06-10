import unittest
import itertools
import logging
from skyscope.executor.llm_executor import (
    to_openai_chat_format,
    rectify_chat_history,
    extract_last_url,
    extract_user_attachment,
)


def format_chat_history(chat_history, target_role, **kwargs):
    new_chat_history = []
    for record in chat_history:
        new_record = record.copy()
        if new_record["role"] == target_role:
            new_record["content"] = record["content"].format(**kwargs)
        new_chat_history.append(new_record)
    return new_chat_history


class TestToOpenAiChatFormat(unittest.TestCase):
    def test_alternative(self):
        kuwa_history = [
            {"isbot": False, "msg": "hello1"},
            {"isbot": True, "msg": "world1"},
            {"isbot": False, "msg": "hello2"},
            {"isbot": True, "msg": "world2"},
        ]
        openai_history = [
            {"role": "user", "content": "hello1"},
            {"role": "assistant", "content": "world1"},
            {"role": "user", "content": "hello2"},
            {"role": "assistant", "content": "world2"},
        ]
        self.assertEqual(to_openai_chat_format(kuwa_history), openai_history)

    def test_multiple_user(self):
        kuwa_history = [
            {"isbot": False, "msg": "hello1"},
            {"isbot": False, "msg": "world1"},
            {"isbot": False, "msg": "hello2"},
            {"isbot": True, "msg": "world2"},
        ]
        openai_history = [
            {"role": "user", "content": "hello1"},
            {"role": "user", "content": "world1"},
            {"role": "user", "content": "hello2"},
            {"role": "assistant", "content": "world2"},
        ]
        self.assertEqual(to_openai_chat_format(kuwa_history), openai_history)

    def test_multiple_assistant(self):
        kuwa_history = [
            {"isbot": False, "msg": "hello1"},
            {"isbot": True, "msg": "world1"},
            {"isbot": True, "msg": "hello2"},
            {"isbot": True, "msg": "world2"},
        ]
        openai_history = [
            {"role": "user", "content": "hello1"},
            {"role": "assistant", "content": "world1"},
            {"role": "assistant", "content": "hello2"},
            {"role": "assistant", "content": "world2"},
        ]
        self.assertEqual(to_openai_chat_format(kuwa_history), openai_history)


class TestRectifyChatHistory(unittest.TestCase):
    def test_alternative(self):
        original_history = [
            {"role": "user", "content": "hello1"},
            {"role": "assistant", "content": "world1"},
            {"role": "user", "content": "hello2"},
            {"role": "assistant", "content": "world2"},
        ]
        expected_history = [
            {"role": "user", "content": "hello1"},
            {"role": "assistant", "content": "world1"},
            {"role": "user", "content": "hello2"},
            {"role": "assistant", "content": "world2"},
        ]
        self.assertEqual(rectify_chat_history(original_history), expected_history)

    def test_assistant_first(self):
        original_history = [
            {"role": "assistant", "content": "world1"},
            {"role": "user", "content": "hello2"},
            {"role": "assistant", "content": "world2"},
        ]
        expected_history = [
            {"role": "user", "content": "hello2"},
            {"role": "assistant", "content": "world2"},
        ]
        self.assertEqual(rectify_chat_history(original_history), expected_history)

    def test_multiple_user(self):
        original_history = [
            {"role": "assistant", "content": "hello1"},
            {"role": "user", "content": "world1"},
            {"role": "user", "content": "hello2"},
            {"role": "assistant", "content": "world2"},
        ]
        expected_history = [
            {"role": "user", "content": "world1"},
            {"role": "user", "content": "hello2"},
            {"role": "assistant", "content": "world2"},
        ]
        self.assertEqual(rectify_chat_history(original_history), expected_history)

    def test_multiple_assistant(self):
        original_history = [
            {"role": "assistant", "content": "hello1"},
            {"role": "assistant", "content": "world1"},
            {"role": "user", "content": "hello2"},
            {"role": "assistant", "content": "world2"},
        ]
        expected_history = [
            {"role": "user", "content": "hello2"},
            {"role": "assistant", "content": "world2"},
        ]
        self.assertEqual(rectify_chat_history(original_history), expected_history)


class TestExtractLastUrl(unittest.TestCase):
    urls = [
        "http://www.example.com",
        "https://www.example.com",
        "http://www.example.com:8800",
        "https://www.example.com:8800",
        "https://www.example.com/a/b/c/d/e/f/g/h/i.html",
        "https://www.test.com?pageid=123&testid=1524",
        "https://www.test.com/%E6%B8%AC%E8%A9%A6",
        "https://www.test.com/%E6%B8%AC%E8%A9%A6?q=%E6%B8%AC%E8%A9%A6",
        "https://www.test.com/do.html#A",
        "https://www.test.com/do.html#%E6%B8%AC%E8%A9%A6",
    ]

    def test_standalone(self):
        chat_history = [
            {"role": "user", "content": "hello1"},
            {"role": "assistant", "content": "world1"},
            {"role": "user", "content": ""},
            {"role": "assistant", "content": "world2"},
            {"role": "user", "content": "hello2"},
        ]
        expected_history = [
            {"role": "user", "content": ""},
            {"role": "assistant", "content": "world2"},
            {"role": "user", "content": "hello2"},
        ]
        for test_url in self.urls:
            history = chat_history.copy()
            history[2]["content"] = test_url
            url, history = extract_last_url(history)
            self.assertEqual(url, test_url)
            self.assertEqual(history, expected_history)

    def test_separate(self):
        chat_history = [
            {"role": "user", "content": "hello1"},
            {"role": "assistant", "content": "world1"},
            {"role": "user", "content": ""},
            {"role": "assistant", "content": "world2"},
            {"role": "user", "content": "hello2"},
        ]
        expected_history = [
            {"role": "user", "content": ""},
            {"role": "assistant", "content": "world2"},
            {"role": "user", "content": "hello2"},
        ]

        msg = "This is a test message!"
        separator = [" ", "  ", "\n", "\n\n", " \n"]
        template = ["{msg}{sep}{url}", "{url}{sep}{msg}"]
        for test_url, sep, tmpl in itertools.product(self.urls, separator, template):
            history = chat_history.copy()
            exp_history = expected_history.copy()
            test_msg = tmpl.format(msg=msg, url=test_url, sep=sep)
            history[2]["content"] = test_msg
            exp_history[0]["content"] = msg
            url, history = extract_last_url(history)
            self.assertEqual(url, test_url)
            self.assertEqual(history, exp_history)


class TestExtractUserAttachment(unittest.TestCase):
    maxDiff = None
    attachments = [
        {"url": "https://skyscope.ai/", "mime_type": "text/html"},
        {
            "url": "https://skyscope.ai/os/intro",
            "mime_type": "text/html",
        },
        {"url": "https://skyscope.ai/notexist", "mime_type": None},
        {"url": "https://not-exist-domain/", "mime_type": None},
        {"url": "https://skyscope.ai/img/logo.svg", "mime_type": "image/svg+xml"},
    ]

    chat_history = [
        {"role": "user", "content": "hello1 {url}"},
        {"role": "assistant", "content": "world1 {url}"},
        {"role": "user", "content": "hello2"},
        {"role": "assistant", "content": "world2"},
        {"role": "user", "content": "hello3 {url} world"},
    ]

    def test_single_user_attachment(self):
        for attachment in self.attachments:
            url = attachment["url"]
            if attachment["mime_type"] is not None:
                expected_chat_history = [
                    {"role": "user", "content": "hello1 ", "attachments": [attachment]},
                    {"role": "assistant", "content": "world1 {url}"},
                    {"role": "user", "content": "hello2", "attachments": []},
                    {"role": "assistant", "content": "world2"},
                    {
                        "role": "user",
                        "content": "hello3  world",
                        "attachments": [attachment],
                    },
                ]
            else:
                expected_chat_history = [
                    {"role": "user", "content": f"hello1 {url}", "attachments": []},
                    {"role": "assistant", "content": "world1 {url}"},
                    {"role": "user", "content": "hello2", "attachments": []},
                    {"role": "assistant", "content": "world2"},
                    {
                        "role": "user",
                        "content": f"hello3 {url} world",
                        "attachments": [],
                    },
                ]
            chat_history = format_chat_history(
                chat_history=self.chat_history,
                target_role="user",
                url=url,
            )
            history_with_attachments = extract_user_attachment(
                chat_history, allowed_mime_type=["*"]
            )
            self.assertEqual(history_with_attachments, expected_chat_history)

    def test_multi_user_attachment(self):
        url = " ".join([i["url"] for i in self.attachments])

        def attachment_filter(x):
            return x["mime_type"] is not None

        attachments = list(filter(attachment_filter, self.attachments))
        url_in_text_content = " ".join(
            i["url"] for i in self.attachments if not attachment_filter(i)
        )
        expected_chat_history = [
            {
                "role": "user",
                "content": f"hello1   {url_in_text_content} ",
                "attachments": attachments,
            },
            {"role": "assistant", "content": "world1 {url}"},
            {"role": "user", "content": "hello2", "attachments": []},
            {"role": "assistant", "content": "world2"},
            {
                "role": "user",
                "content": f"hello3   {url_in_text_content}  world",
                "attachments": attachments,
            },
        ]
        chat_history = format_chat_history(
            chat_history=self.chat_history,
            target_role="user",
            url=url,
        )
        history_with_attachments = extract_user_attachment(
            chat_history, allowed_mime_type=["*"]
        )
        self.assertEqual(history_with_attachments, expected_chat_history)

    def test_assistant_url(self):
        for attachment in self.attachments:
            url = attachment["url"]
            expected_chat_history = [
                {"role": "user", "content": "hello1 {url}", "attachments": []},
                {"role": "assistant", "content": f"world1 {url}"},
                {"role": "user", "content": "hello2", "attachments": []},
                {"role": "assistant", "content": "world2"},
                {"role": "user", "content": "hello3 {url} world", "attachments": []},
            ]
            chat_history = format_chat_history(
                chat_history=self.chat_history,
                target_role="assistant",
                url=url,
            )
            history_with_attachments = extract_user_attachment(
                chat_history, allowed_mime_type=["*"]
            )
            self.assertEqual(history_with_attachments, expected_chat_history)

    def test_not_allowed_attachment(self):
        url = " ".join([i["url"] for i in self.attachments])

        def allowed_attachment_filter(x):
            return x["mime_type"] is not None and x["mime_type"].startswith("image/")

        attachments = list(filter(allowed_attachment_filter, self.attachments))
        url_in_text_content = " ".join(
            i["url"] for i in self.attachments if not allowed_attachment_filter(i)
        )
        expected_chat_history = [
            {
                "role": "user",
                "content": f"hello1 {url_in_text_content} ",
                "attachments": attachments,
            },
            {"role": "assistant", "content": "world1 {url}"},
            {"role": "user", "content": "hello2", "attachments": []},
            {"role": "assistant", "content": "world2"},
            {
                "role": "user",
                "content": f"hello3 {url_in_text_content}  world",
                "attachments": attachments,
            },
        ]
        chat_history = format_chat_history(
            chat_history=self.chat_history,
            target_role="user",
            url=url,
        )
        history_with_attachments = extract_user_attachment(
            chat_history, allowed_mime_type=["image/*"]
        )
        self.assertEqual(history_with_attachments, expected_chat_history)


if __name__ == "__main__":
    logging.basicConfig(level="DEBUG")
    unittest.main()
