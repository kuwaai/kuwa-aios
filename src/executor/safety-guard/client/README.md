Safety Guard Client
===

This is the client library of the safety guard system. The library is expected
to be integrated into the proxy server on the primary prompt/response path.

## Usage
1. Set the following environment variable before importing the Safety Guard client library
```shell
# variable=default
SAFETY_GUARD_MANAGER_URL=http://localhost:8000
SAFETY_GUARD_DETECTOR_URL=grpc://localhost:50051
SAFETY_GUARD_DISABLE=false
```
2. Install the client library of the Safety Guard
```shell
pip install .
```
3. Apply the decorator as a middleware to the processing function.
The processing function is expected to have the following signature.
An adaptor may be needed to convert the signature.
```python
from fastapi import FastAPI
from llm_safety_guard import LlmSafetyGuard

app = FastAPI()

def at_exit():
    print('Generation done')

def safety_middleware(func):
    """
    The decorator to apply the functionality of the Safety Guard.
    """

    def wrap(req: dict):
        nonlocal func

        """
        Arguments:
        n_max_buffer: Maximum charters allowed to be stored in the streaming-mode buffer before detection.
        streaming: Running mode. Set to True means the output from the original generator is inspected and output at the chunk basis. Set to False means all the output will be buffered before the detection.
        """
        safety_guard = LlmSafetyGuard(n_max_buffer=100, streaming=True)

        guarded_func = safety_guard.guard(func)

        """
        Convert the chat history to the safety guard accepted format.
        The format of the chat record is {'role': 'user' or 'assistant', 'content': 'the content of the message'};
        """
        chat_history = req['messages']

        """
        The decorated function can accept an optional argument "at_exit" that specifies a function to be called at the end of a generation or terminated session.
        Moreover, the return type of the safety guard is the same as the original function.
        """
        generator = guarded_func(
            chat_history=chat_history,
            model_id=req['model'],
            at_exit=at_exit,
            req=req
        )

        return generator

    return wrap

def safety_guard_adaptor(func):
    """
    The adaptor to convert the function signature.
    """
    def wrap(chat_history:List[dict], model_id:str, *args, **kwargs):
        """
        Parameters:
        chat_history: containing a list of chat record indicating the chat history.
        model_id: containing the identity of the model to be called.
        """

        return func(req=kwargs['req'])

    return wrap

@app.post('/v1/chat/completions')
@safety_middleware
@safety_guard_adaptor
def chat_completion(req: dict):

    chat_history = req['messages']

    def generator(chat_history: List[dict]):
        """
        The example generator that always echo the last message.
        """
        for token in chat_history[-1]['content']:
            yield token

    # Two return type are accepted: (1) A generator; (2) A tuple which the first element is a generator.
    #
    # return generator, {'Content-Type': 'text/plain'}
    # or
    return generator(chat_history)
```

4. Moreover, the client is expected to periodically update the guarded model list from the control plane.
You can achieve that with the [APScheduler](https://pypi.org/project/APScheduler/) package. The example is shown below.

```python
import datetime
from apscheduler.schedulers.background import BackgroundScheduler
from llm_safety_guard import LlmSafetyGuard

if __name__ == "__main__":
    safety_guard_update_interval_sec = 30
    scheduler = BackgroundScheduler()
    scheduler.add_job(
        func=LlmSafetyGuard.update,
        trigger="interval",
        seconds=safety_guard_update_interval_sec,
        next_run_time=datetime.now()
    )
    scheduler.start()
```

## Example Server
- An OpenAI API-compatible server example is in `example/openai_api.py`
1. Install the extra dependency that is listed in `example/requirements.txt` to get this example to work
2. Start the Safety Guard sub-system
3. Start the example server
```shell
python example/openai_api.py
```

4. Issue a test request
```shell
curl http://localhost:8080/v1/chat/completions \
  -H "Content-Type: application/json" \
  -d '{
    "model": "guarded-model",
    "messages": [
      {
        "role": "user",
        "content": "Hello!"
      }
    ]
  }'
```

5. If the model is guarded, the following two lines should be printed on the server console.
```
DEBUG:llm_safety_guard.llm_safety_guard:pre-filter: ...
DEBUG:llm_safety_guard.llm_safety_guard:post-filter: ...
```
