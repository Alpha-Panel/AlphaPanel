# Local Include Services

This folder is for local-only Compose files that should not be pushed to GitHub.

`docker-compose.yaml` includes one file by default:

- `./external-services/empty.yaml` (safe no-op fallback)

To enable your local services, set this variable in local `.env`:

```dotenv
LOCAL_SERVICES_COMPOSE_FILE=./external-services/local-services.yaml
```

Create your local include file from template:

```bash
cp external-services/local-services.example.yaml external-services/local-services.yaml
cp external-services/mongodb.example.yaml external-services/mongodb.yaml
```

After this, normal commands are enough:

```bash
docker compose up -d
docker compose logs -f
docker compose down
```
