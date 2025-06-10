from .base import SkyscopeClient


class ModelOperations(SkyscopeClient):
    def create_base_model(self, name, access_code, options={}):
        return self._request(
            "api/user/create/base_model",
            "POST",
            {"name": name, "access_code": access_code, **options},
        )

    def list_base_models(self):
        return self._request("api/user/read/models")
