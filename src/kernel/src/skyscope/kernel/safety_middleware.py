import logging
import inspect
import json
import requests
from typing import List

logger = logging.getLogger(__name__)

def safety_middleware(func, n_max_buffer=50, streaming=True):
    bypass = True
    try:
        from llm_safety_guard import LlmSafetyGuard
        bypass = False
    except ImportError:
        logger.warning('Bypassing safety middleware due to the package "llm-safety-guard" is not installed.')

    def wrap(*args, **kwargs):
        nonlocal func
        if bypass:
            return func(*args, **kwargs)
        
        # Forward path: Flask --[Convert]--> Safety Guard --[Convert]--> Chat completion backend.
        # Normal return path:  Chat completion backend --> Safety Guard --> Flask
        # Return path under violation of pre-filter rules:  Safety Guard --> Flask
        safety_guard = LlmSafetyGuard(n_max_buffer=n_max_buffer, streaming=streaming)
        local_func = to_safety_guard_signature(func)
        local_func = safety_guard.guard(local_func)
        local_func = to_completions_backend_signature(local_func)
        return local_func(*args, **kwargs)
    
    wrap.__signature__ = inspect.signature(func)
    return wrap

def to_safety_guard_signature(func):
    """
    Convert the function signature to the llm-safety-guard compatible one.
    """

    def wrap(chat_history:List[dict], model_id:str, *args, **kwargs):
        chat_history = [
            {'isbot': r['role']=='assistant', 'msg': r['content']}
            for r in chat_history
        ]
        form = dict(kwargs.pop("form"))
        form["input"] = json.dumps(chat_history)
        form["name"] = model_id
        return func(form=form, *args, **kwargs)
    return wrap

def to_completions_backend_signature(func):
    """
    Convert the function signature to the completions_backend() compatible one.
    """

    def wrap(form:dict, *args, **kwargs):
        input = form.get("input", [])
        llm_name = form.get("name", "")
        if isinstance(input, str):
            input = json.loads(input)
        input = [
            {'role': 'assistant' if r['isbot'] else 'user', 'content': r['msg']}
            for r in input
        ]
        def at_exit():
            nonlocal kwargs
            dest = kwargs['dest']
            requests.get(dest[0] + "/abort", timeout=10)
            dest[3] = -1
            dest[2] = -1
            dest[1] = "READY"
            print("Done")

        return func(chat_history=input, model_id=llm_name, at_exit=at_exit, form=form, *args, **kwargs)
    return wrap

def update_safety_guard():
    """
    The cronjob to update the safety guard.
    """

    try:
        from llm_safety_guard import LlmSafetyGuard
        LlmSafetyGuard.update()
    except ImportError:
        pass
