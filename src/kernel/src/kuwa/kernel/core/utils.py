from urllib.parse import urlparse


def endpoint_formatter(endpoint):
    return endpoint[:-1] if endpoint.endswith("/") else endpoint


def get_base_url(url):
    parsed_url = urlparse(url)
    base_url = f"{parsed_url.scheme}://{parsed_url.netloc}"
    return base_url
