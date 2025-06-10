import os
import sys
import asyncio
import logging

sys.path.append(os.path.dirname(os.path.abspath(__file__)))

from skyscope.executor import LLMExecutor, Modelfile

logger = logging.getLogger(__name__)

lorem = """Hello, I am a language model nice to meet you...etc. xD
<<<WARNING>>>
This is a test warning
This is the second test warning
<<</WARNING>>>
Warning can be outputted in the middle
<<<WARNING>>>
Warning 2, hi
<<</WARNING>>>
End of simulated text output
"""


class DummyExecutor(LLMExecutor):
    def __init__(self):
        super().__init__()

    def extend_arguments(self, parser):
        parser.add_argument("--delay", type=int, default=0.02, help="Inter-token delay")

    def setup(self):
        self.stop = False

    async def llm_compute(self, history: list[dict], modelfile: Modelfile):
        try:
            self.stop = False
            for i in lorem:
                yield i
                if self.stop:
                    self.stop = False
                    break
                await asyncio.sleep(
                    modelfile.parameters.get("llm_delay", self.args.delay)
                )
        except Exception as e:
            logger.exception("Error occurs during generation.")
            yield str(e)
        finally:
            logger.debug("finished")

    async def abort(self):
        self.stop = True
        logger.debug("aborted")
        return "Aborted"


if __name__ == "__main__":
    executor = DummyExecutor()
    executor.run()
