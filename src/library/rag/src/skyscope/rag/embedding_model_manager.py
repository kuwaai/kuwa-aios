import logging
import torch
from langchain_huggingface import HuggingFaceEmbeddings

logger = logging.getLogger(__name__)


class EmbeddingModelManager:
    """
    Manage the reference count of embedding model instances.
    The embedding model is identified by the model name.
    Multiple DocumentStore instance can reference to the same embedding model.
    If no DocumentStore instance reference to a embedding model, the model is unloaded.
    """

    referers = {}  # model name: {referers' id}
    models = {}  # model name: model instance

    def acquire_model(self, caller_id, model_name):
        """
        Request loading a model in identity caller_id.
        Return the loaded model instance.
        If the model instance is unused, it should be release by the release_model method.
        """
        if model_name in self.models:
            logger.info(f'Use cached embedding model "{model_name}"')
        else:
            logger.info(f'Loading embedding model "{model_name}"...')
            self.models[model_name] = HuggingFaceEmbeddings(model_name=model_name)
            self.referers[model_name] = set()
            logger.info(f'Embedding model "{model_name}" loaded.')

        self.referers[model_name].add(caller_id)
        return self.models[model_name]

    def release_model(self, caller_id, model_name=None):
        """
        Release the loaded models.
        If a model is not referenced by any instance, it will be unload from the memory.
        If the model_name is not specified, all of the model acquired by the caller will be released.
        """
        models = [model_name] if model_name is not None else list(self.models.keys())
        for name in models:
            self.referers[name].remove(caller_id)
            if len(self.referers[name]) > 0:
                continue
            del self.models[name]
            torch.cuda.empty_cache()


def get_embedding_model_manager(m=EmbeddingModelManager()):
    return m
