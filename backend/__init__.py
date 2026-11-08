"""Backend package marker for FastAPI app.

Ensures that `backend.main:app` can be imported by Uvicorn reload worker
on Windows/macOS when running from the project root.
"""

