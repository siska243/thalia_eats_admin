# Connecteur MCP de Thalia Eats.
#
# Service a part entiere : il ne partage avec le backend PHP que le depot git,
# faute de depot a la racine du monorepo. Il n'importe aucun code PHP, ne touche
# jamais MySQL, et ne parle a Thalia que par l'API REST publique.
#
# Aucun secret n'entre dans l'image : l'URL de l'API et l'URL publique arrivent
# par l'environnement, et le jeton du client arrive par requete.

FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /app

# Les dependances d'abord : elles changent bien moins souvent que le code, et
# cette couche se reutilise d'une reconstruction a l'autre.
COPY pyproject.toml ./
COPY thalia_mcp ./thalia_mcp
RUN pip install --no-cache-dir .

# Le serveur n'ecrit aucun fichier : il tourne sans privileges.
RUN useradd --create-home --uid 10001 mcp
USER mcp

EXPOSE 8097

# Le meme endpoint que le healthcheck du compose, pour que `docker run` seul
# soit deja surveille. Pas de curl dans l'image : Python suffit.
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8097/healthz', timeout=3).read().strip()==b'ok' else 1)"

CMD ["python", "-m", "thalia_mcp"]
