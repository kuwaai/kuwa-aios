from .base import SkyscopeClient


class RoomOperations(SkyscopeClient):
    def list_rooms(self):
        return self._request("api/user/read/rooms")

    def create_room(self, bot_ids):
        return self._request("api/user/create/room", "POST", {"llm": bot_ids})

    def delete_room(self, room_id):
        return self._request("api/user/delete/room", "DELETE", {"id": room_id})
