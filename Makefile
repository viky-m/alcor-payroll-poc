UID := $(shell id -u)
GID := $(shell id -g)

export UID GID

build:
	docker compose build

install:
	docker compose run --rm php composer install

test:
	docker compose run --rm php composer test

analyse:
	docker compose run --rm php composer analyse

demo:
	docker compose run --rm php php bin/demo.php

shell:
	docker compose run --rm php sh