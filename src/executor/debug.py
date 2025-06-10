import os
import sys
import asyncio
import logging

sys.path.append(os.path.dirname(os.path.abspath(__file__)))

from skyscope.executor import LLMExecutor, Modelfile

logger = logging.getLogger(__name__)


class DebugExecutor(LLMExecutor):
    def __init__(self):
        super().__init__()

    def extend_arguments(self, parser):
        """
        Override this method to add custom command-line arguments.
        """
        parser.add_argument(
            "--delay", type=float, default=0.02, help="Inter-token delay"
        )

    def setup(self):
        self.stop = False

    async def llm_compute(self, history: list[dict], modelfile: Modelfile):
        try:
            self.stop = False
            if history[-1]["content"] == "/crash":
                raise RuntimeError("oiiaioiiiiai")
            for i in "".join([i["content"] for i in history]).strip():
                yield i
                if self.stop:
                    self.stop = False
                    break
                await asyncio.sleep(
                    modelfile.parameters.get("llm_delay", self.args.delay)
                )
        except Exception:
            logger.exception("Error occurs during generation.")
            raise
        finally:
            logger.debug("finished")

    async def abort(self):
        self.stop = True
        logger.debug("aborted")
        return "Aborted"


if __name__ == "__main__":
    executor = DebugExecutor()
    executor.run()
