import io
import base64
import logging
import functools
import mimetypes
import requests
from PIL import Image

from .cache import lru_cache_with_ttl

logger = logging.getLogger(__name__)


def ext2mime(ext: str):
    if not ext.startswith("."):
        ext = f".{ext}"
    return mimetypes.guess_type(f"a{ext}")[0]


@functools.cache
def get_supported_image_mime():
    exts = Image.registered_extensions()
    exts = {ex for ex, f in exts.items() if f in Image.OPEN}
    mimes = {ext2mime(ex) for ex in exts} - {None}
    return mimes


def convert_image(img: Image, output_format: str = "png"):
    byte_stream = io.BytesIO()
    img = img.convert("RGBA")
    img.save(byte_stream, format=output_format)
    logger.debug(f"Image converted. ({len(byte_stream.getvalue())} bytes)")
    return byte_stream.getvalue()


def image_to_data_url(img: Image, output_format: str = "png", add_prefix=True):
    if img is None:
        return None
    byte_content = convert_image(img, output_format)
    base64_content = base64.b64encode(byte_content).decode("utf-8")
    return (
        f"data:{ext2mime(output_format)};base64," if add_prefix else ""
    ) + base64_content


@lru_cache_with_ttl()
def fetch_image(url: str):
    if url is None or url == "":
        return None

    image = None
    response = requests.get(url, stream=True, allow_redirects=True)
    response.raise_for_status()
    content_type = response.headers["content-type"]
    mime_type = content_type.split(";")[0].strip().lower()
    if mime_type not in get_supported_image_mime():
        raise ValueError(f"Unsupported mime type {mime_type}")
    image = Image.open(response.raw)
    logger.info(f"Image {url} fetched.")

    return image


@lru_cache_with_ttl()
def fetch_image_as_data_url(url: str, output_format: str = "png"):
    image = fetch_image(url)
    result = (
        image_to_data_url(image, output_format=output_format)
        if image is not None
        else None
    )
    return result
