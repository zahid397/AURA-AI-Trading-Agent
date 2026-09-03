.PHONY: bootstrap up down logs demo-reset health test test-backend test-ai-engine test-frontend

bootstrap:
	cp -n .env.example .env || true
	cp -n backend/.env.example backend/.env || true
	cp -n ai-engine/.env.example ai-engine/.env || true
	cp -n frontend/.env.example frontend/.env.development || true
	@echo "Copied .env files. Edit backend/.env and ai-engine/.env if you have real Alpaca paper keys."

up:
	docker compose up --build -d
	@echo "Frontend:  http://localhost:8080"
	@echo "Laravel:   http://localhost:8000"
	@echo "AI Engine: http://localhost:8001"

down:
	docker compose down

logs:
	docker compose logs -f

demo-reset:
	docker compose exec laravel php artisan aura:demo-reset

health:
	bash scripts/health-check.sh

test-backend:
	docker compose exec laravel php artisan test

test-ai-engine:
	cd ai-engine && .venv/Scripts/python.exe -m pytest -q

test-frontend:
	cd frontend && npm run typecheck && npm run test:unit

test: test-ai-engine
	@echo "Run 'make test-backend' and 'make test-frontend' once the stack is up."
