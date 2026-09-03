# WRITTEN AS A REFERENCE STARTING POINT -- NOT BUILT OR RUN.
# This sandbox has no `docker` binary at all, so this file has not been
# validated by an actual `docker build`. Treat it as a reasonable draft,
# not a verified artifact. See docs/IMPLEMENTATION_STATUS.md.

FROM python:3.12-slim

WORKDIR /app
COPY backend/requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY backend/ ./backend/
COPY frontend/ ./frontend/

WORKDIR /app/backend
ENV TRADING_ENV=paper
ENV PORT=5001
EXPOSE 5001

# Flask's built-in dev server is used here to match what was actually run and
# tested in this build. For a real deployment, put a production WSGI server
# (gunicorn/waitress) in front of app:app instead -- that substitution was not
# made or tested here.
CMD ["python3", "app.py"]
