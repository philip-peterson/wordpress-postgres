POD    := wordpress
IMAGE  := wordpress-php
PORT   := 8080
DB     := wordpress
DBUSER := wordpress
DBPASS := wordpress

.PHONY: build up down logs shell db-shell clean wp-config

build:
	podman build -t $(IMAGE) .

up: build
	podman pod exists $(POD) && podman pod rm -f $(POD) || true
	podman pod create --name $(POD) -p $(PORT):80
	podman run -d --pod $(POD) --name $(POD)-db \
		-e POSTGRES_DB=$(DB) \
		-e POSTGRES_USER=$(DBUSER) \
		-e POSTGRES_PASSWORD=$(DBPASS) \
		-v wordpress-pgdata:/var/lib/postgresql/data \
		postgres:16-alpine
	podman run -d --pod $(POD) --name $(POD)-php \
		-v $(CURDIR)/wordpress-develop:/var/www:Z \
		$(IMAGE)
	podman run -d --pod $(POD) --name $(POD)-nginx \
		-v $(CURDIR)/wordpress-develop:/var/www:Z \
		-v $(CURDIR)/nginx.conf:/etc/nginx/conf.d/default.conf:ro,Z \
		nginx:alpine

down:
	podman pod stop $(POD) 2>/dev/null || true
	podman pod rm   $(POD) 2>/dev/null || true

logs:
	podman pod logs -f $(POD)

shell:
	podman exec -it $(POD)-php sh

db-shell:
	podman exec -it $(POD)-db psql -U $(DBUSER) $(DB)

wp-config:
	cp wordpress-develop/wp-config-sample.php wordpress-develop/src/wp-config.php
	sed -i '' \
		-e "s/database_name_here/$(DB)/" \
		-e "s/username_here/$(DBUSER)/" \
		-e "s/password_here/$(DBPASS)/" \
		-e "s/localhost/127.0.0.1/" \
		wordpress-develop/src/wp-config.php
	@echo "wp-config.php written. Open it to add your secret keys."

clean: down
	podman volume rm wordpress-pgdata 2>/dev/null || true
	podman rmi $(IMAGE) 2>/dev/null || true
