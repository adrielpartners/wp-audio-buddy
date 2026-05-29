# WP Audio Buddy — Worker Service

Private Python/FastAPI worker for audio transcription and AI excerpt generation.

## Structure

```
worker/
├── app/                        # FastAPI application
│   ├── main.py                 # Entry point
│   ├── api/routes.py           # API endpoints
│   ├── core/                   # Config, logging, security
│   ├── models/payloads.py      # Request/response models
│   ├── services/               # Business logic & providers
│   │   └── providers/          # Transcription provider registry
│   └── workers/                # Background RQ workers
├── tests/                      # Python test suite
├── Dockerfile                  # Container image
├── docker-compose.yml          # Local services (worker, API, Redis, cleanup)
├── requirements.txt            # Python dependencies
└── .env.example                # Environment template
```

## Quick Start

```bash
cd worker
cp .env.example .env    # Edit with your API keys
docker compose up -d    # Start all services
```

This starts:
- **api** — FastAPI server on port 8080
- **worker** — RQ background worker for transcription jobs
- **redis** — Job queue (port 6379)
- **cleanup** — Daemon for purging old audio files

## API

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/transcribe` | Submit a transcription job |
| POST | `/callback` | WordPress callback endpoint |
| GET | `/health` | Health check |

## Deployment

See `scripts/deploy-worker.sh` in the repo root.

## Configuration

All configuration via environment variables (see `.env.example`):

- `REDIS_URL` — Redis connection string
- `OPENAI_API_KEY`, `GROQ_API_KEY`, `OPENROUTER_API_KEY`, `DEEPGRAM_API_KEY` — Provider credentials
- `WPAB_ALLOWED_SITES` — Comma-separated allowed WordPress site origins
- `WPAB_LOG_LEVEL` — Logging level

## Development

```bash
cd worker
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
uvicorn app.main:app --reload
```