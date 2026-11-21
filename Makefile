# Makefile: pintasan perintah harian. Target lain (demo, bench, reconcile) menyusul.
COMPOSE ?= docker compose
APP     := $(COMPOSE) exec app

.PHONY: help up down logs setup install migrate test seed fresh shell reconcile load-test

help: ## Tampilkan daftar target
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-10s %s\n", $$1, $$2}'

up: ## Nyalakan semua container (profil dev, termasuk mailpit)
	$(COMPOSE) --profile dev up -d --build

down: ## Matikan semua container
	$(COMPOSE) --profile dev down

logs: ## Ikuti log semua container
	$(COMPOSE) logs -f

setup: up ## Setup pertama kali: dependency, .env, key, migrate
	$(APP) composer setup

install: ## Install dependency JS (pnpm) dan PHP (composer)
	pnpm install
	$(APP) composer install

test: ## Jalankan test API (Pest) dan test JS workspace
	$(APP) php artisan test
	pnpm test

migrate: ## Jalankan migration
	$(APP) php artisan migrate

seed: ## Jalankan seeder database
	$(APP) php artisan db:seed

fresh: ## Reset database + seed
	$(APP) php artisan migrate:fresh --seed

shell: ## Masuk ke shell container app
	$(APP) sh

reconcile: ## Cek saldo semua wallet vs riwayat transaksi (selisih harus 0)
	$(APP) php artisan wallets:reconcile --no-notify

VUS      ?= 50
DURATION ?= 20s

load-test: ## k6: 50 VU tulis ke wallet yang sama (+ client_id duplikat), lalu reconcile
	$(APP) php artisan db:seed --class=LoadTestUserSeeder --force
	docker run --rm --network household-finance_default -v $(CURDIR)/load-tests:/scripts \
		-e VUS=$(VUS) -e DURATION=$(DURATION) grafana/k6:latest run /scripts/concurrent-writes.js
	$(APP) php artisan wallets:reconcile --no-notify
