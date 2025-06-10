from .base import SkyscopeClient


class BotOperations(SkyscopeClient):
    def list_bots(self):
        return self._request("api/user/read/bots")

    def create_bot(self, llm_access_code, bot_name, options={}):
        return self._request(
            "api/user/create/bot",
            "POST",
            {
                "llm_access_code": llm_access_code,
                "bot_name": bot_name,
                "visibility": 3,
                **options,
            },
        )
