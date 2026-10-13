# Embedding service

Self-hosted sentence embeddings for the assistant's semantic search. Localhost
only; nothing leaves the server.

## Setup (once, on the VPS)

```bash
sudo mkdir -p /var/www/embedding-service
sudo chown deploy:deploy /var/www/embedding-service
rsync -a deploy/embedding-service/ deploy@HOST:/var/www/embedding-service/

cd /var/www/embedding-service
python3 -m venv venv
venv/bin/pip install -r requirements.txt

# Warm the model cache (first run downloads ~90MB into ./.cache).
HF_HOME=$PWD/.cache venv/bin/python -c "from fastembed import TextEmbedding; TextEmbedding('sentence-transformers/all-MiniLM-L6-v2')"

sudo cp lw-embeddings.service /etc/systemd/system/lw-embeddings.service
sudo systemctl daemon-reload
sudo systemctl enable --now lw-embeddings
curl -s http://127.0.0.1:8091/health
```

## Turn it on for the app

In the app `.env`:

```
EMBEDDINGS_ENABLED=true
EMBEDDINGS_URL=http://127.0.0.1:8091
```

Then `php artisan optimize` and `php artisan chatbot:embed-knowledge` to give the
passages their vectors. The hourly `chatbot:import-training` re-embeds after
each refresh.

If the service is ever off, the assistant falls back to lexical matching on its
own — the flag and the service are an upgrade, not a dependency.
