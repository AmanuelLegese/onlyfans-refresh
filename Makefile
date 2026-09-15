.PHONY: build up down test art composer migrate seed logs shell horizon redis upstream reproduce workload crash

SEED ?= 42
TIMEOUT ?= 150

build:
	docker compose build

up:
	docker compose up -d

down:
	docker compose down

test:
	docker compose run --rm artisan test

art:
	docker compose run --rm artisan $(ARGS)

composer:
	docker compose run --rm composer $(ARGS)

migrate:
	docker compose run --rm artisan migrate:fresh

seed:
	docker compose run --rm artisan db:seed

logs:
	docker compose logs -f

shell:
	docker compose exec php sh

horizon:
	docker compose exec php php artisan horizon:terminate

redis:
	docker compose exec redis redis-cli

upstream:
	docker compose logs -f upstream

reproduce: MODE ?= both
reproduce:
	docker compose run --rm artisan incident:reproduce --mode=$(MODE)

workload: MODE ?= fixed
workload:
	docker compose run --rm artisan workload:run --mode=$(MODE) --seed=$(SEED) --timeout=$(TIMEOUT)

crash:
	docker compose run --rm artisan workload:crash-replay --timeout=$(TIMEOUT)
