#!/bin/bash
# Deploy the worker via Docker.
# Usage: bash scripts/deploy-worker.sh [build|up|down|restart]
# 
# Assumes docker-compose.yml lives in the worker/ directory.

set -euo pipefail

WORKER_DIR="$(cd "$(dirname "$0")/.." && pwd)/worker"
ACTION="${1:-build}"

cd "$WORKER_DIR"

case "$ACTION" in
  build)
    echo "🏗️  Building worker Docker image..."
    docker compose build
    ;;
  up)
    echo "🚀 Starting worker services..."
    docker compose up -d
    ;;
  down)
    echo "🛑 Stopping worker services..."
    docker compose down
    ;;
  restart)
    echo "🔄 Restarting worker services..."
    docker compose down
    docker compose up -d
    ;;
  *)
    echo "Usage: $0 [build|up|down|restart]"
    exit 1
    ;;
esac

echo "✅ Done ($ACTION)."