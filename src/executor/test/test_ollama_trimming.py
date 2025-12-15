import sys
import os
import asyncio
import logging
import unittest
from unittest.mock import MagicMock, AsyncMock

# Mock dependencies that might be missing in the environment
sys.modules['ollama'] = MagicMock()
sys.modules['PIL'] = MagicMock()
sys.modules['PIL.Image'] = MagicMock()
sys.modules['requests'] = MagicMock()
sys.modules['uvicorn'] = MagicMock()
sys.modules['fastapi'] = MagicMock()
sys.modules['fastapi.responses'] = MagicMock()
sys.modules['prometheus_client'] = MagicMock()
sys.modules['pydantic'] = MagicMock()
sys.modules['yaml'] = MagicMock()

# Mock retry
mock_retry = MagicMock()
def retry_decorator(*args, **kwargs):
    def decorator(func):
        return func
    return decorator
mock_retry.retry = retry_decorator
sys.modules['retry'] = mock_retry

# Mock tiktoken
mock_tiktoken = MagicMock()
sys.modules['tiktoken'] = mock_tiktoken
# Setup mock behavior for encode: return list of characters (length = length of string)
mock_encoding = MagicMock()
mock_encoding.encode.side_effect = lambda s: list(s)
mock_tiktoken.encoding_for_model.return_value = mock_encoding
mock_tiktoken.get_encoding.return_value = mock_encoding

# Add the directory containing ollama_proxy.py to path
sys.path.append(os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from ollama_proxy import OllamaExecutor
from kuwa.executor import Modelfile

class TestOllamaTrimming(unittest.TestCase):
    def setUp(self):
        # Prevent actual logging during tests
        logging.basicConfig(level=logging.CRITICAL)
        self.executor = OllamaExecutor()
        
        # Mock dependencies to avoid side effects
        self.executor.client = AsyncMock()
        self.executor.prepare_model = AsyncMock() 
        self.executor.ollama_host = "mock_host"
        self.executor.default_model_name = "mock_model"
        
        # Set a small context window for testing
        self.executor.context_window = 30 

    def test_num_tokens(self):
        """Verify that token counting works (using the mock tiktoken)."""
        messages = [{"role": "user", "content": "hello world"}]
        # Mocked behavior: "hello world" -> 11 chars -> 11 tokens (approx logic for test)
        # Plus overhead (3 per msg + 3 primer) = 11 + 6 = 17
        count = self.executor.num_tokens_from_messages(messages)
        self.assertGreater(count, 0)

    def test_trimming_logic(self):
        """Verify that history is trimmed when it exceeds context window."""
        async def run_test():
            # Message 1 (User, Huge)
            msg1 = {"role": "user", "content": "A" * 50} 
            # Message 2 (Assistant, Huge)
            msg2 = {"role": "assistant", "content": "A" * 50}
            # Message 3 (User, Small)
            msg3 = {"role": "user", "content": "B"}
            
            # Limit = 30.
            # Msg1 (60+) + Msg2 (60+) + Msg3 (11) >> 30.
            # Expect msg1 and msg2 to be trimmed.
            
            history = [msg1, msg2, msg3]
            
            # Mock modelfile
            modelfile = Modelfile()
            modelfile.parameters = {"llm_": {}, "llm.": {}}
            modelfile.messages = []
            modelfile.override_system_prompt = None
            modelfile.before_prompt = ""
            modelfile.after_prompt = ""
            
            # Mock client.chat
            self.executor.client.chat.return_value = AsyncMock()
            self.executor.client.chat.return_value.__aiter__.return_value = [] 
            
            # Execute
            gen = self.executor.llm_compute(history, modelfile)
            async for _ in gen:
                pass
            
            # Verify call
            self.executor.prepare_model.assert_called_with("mock_model")
            
            # Check what messages were actually sent to the client
            call_args = self.executor.client.chat.call_args
            self.assertIsNotNone(call_args)
            
            kwargs = call_args.kwargs
            sent_messages = kwargs['messages']
            
            # Should only contain msg3
            self.assertEqual(len(sent_messages), 1, "History should be trimmed to 1 message")
            self.assertEqual(sent_messages[0]['content'], "B")

        asyncio.run(run_test())

if __name__ == "__main__":
    unittest.main()
