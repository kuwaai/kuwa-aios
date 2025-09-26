import os
import sys
import asyncio
import logging

sys.path.append(os.path.dirname(os.path.abspath(__file__)))

from kuwa.executor import LLMExecutor, Modelfile
from kuwa.executor.util import merge_config

logger = logging.getLogger(__name__)


class RepeatedNumbers:
    """
    A class that stores a sequence of numbers and allows infinite iteration over them.
    When iterated, it repeatedly yields the numbers in the sequence, looping back
    to the beginning once the end of the sequence is reached.

    It supports initialization with:
    - a single integer or float, which is then treated as a list containing that single number.
    - a list of numbers (integers or floats), which are converted to floats internally.

    This class implements the iterator protocol (__iter__ and __next__) to enable
    looping behavior, and also provides a string representation (__str__).
    """

    values = []
    index = 0

    def __init__(self, value):
        if isinstance(value, int) or isinstance(value, float):
            self.values = [value]
        elif isinstance(value, list):
            self.values = [float(v) for v in value]
        else:
            raise TypeError(f"Unsupported type {type(value)}")

    def __iter__(self):
        self.index = 0
        return self

    def __next__(self):
        if len(self.values) == 0:
            return None
        x = self.values[self.index]
        self.index = (self.index + 1) % len(self.values)
        return x

    def __str__(self):
        return str(self.values)


class DebugExecutor(LLMExecutor):
    def __init__(self):
        super().__init__()

    def extend_arguments(self, parser):
        """
        Override this method to add custom command-line arguments.
        """
        parser.add_argument(
            "--delay", type=float, default=0.02, help="Inter-output delay."
        )

    def setup(self):
        self.stop = False

    async def llm_compute(self, history: list[dict], modelfile: Modelfile):
        try:
            self.stop = False
            if history[-1]["content"] == "/crash":
                raise RuntimeError("oiiaioiiiiai")

            config = merge_config(
                modelfile.parameters["llm_"], modelfile.parameters["debug."]
            )
            delay = config.get("delay", self.args.delay)
            if isinstance(delay, str):
                delay = [
                    float(i.strip()) for i in delay.split(",") if len(i.strip()) != 0
                ]
            delay = RepeatedNumbers(delay)
            logger.debug(f"Delay: {delay}")

            await asyncio.sleep(next(delay))
            for i in "".join([i["content"] for i in history]).strip():
                yield i
                if self.stop:
                    self.stop = False
                    break
                await asyncio.sleep(next(delay))
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
