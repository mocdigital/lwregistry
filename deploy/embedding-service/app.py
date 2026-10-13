"""
The LW Registry embedding service.

A tiny HTTP wrapper around a self-hosted sentence-embedding model. It runs on
localhost only and never calls out, so a question and the knowledge stay on this
server — the same rule the translator follows. The Laravel app posts text and
gets back one vector per text; the assistant compares them to find the passage
that MEANS the same thing as the question.

Model: all-MiniLM-L6-v2 (384-dim), run through ONNX by fastembed — no PyTorch,
small memory, fast on CPU.
"""

from fastembed import TextEmbedding
from fastapi import FastAPI
from pydantic import BaseModel

MODEL_NAME = "sentence-transformers/all-MiniLM-L6-v2"

app = FastAPI(title="LW Registry Embeddings")

# Loaded once at startup; the first construction downloads the model into the
# cache (see HF_HOME in the service unit) and is the only slow moment.
_model = TextEmbedding(model_name=MODEL_NAME)


class EmbedRequest(BaseModel):
    texts: list[str]


@app.get("/health")
def health() -> dict:
    return {"ok": True, "model": MODEL_NAME}


@app.post("/embed")
def embed(request: EmbedRequest) -> dict:
    vectors = [vector.tolist() for vector in _model.embed(request.texts)]
    return {"vectors": vectors}
