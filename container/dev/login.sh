export U_ID=$(id -u)
export G_ID=$(id -g)
export DOCKER_GID=$(grep docker /etc/group | cut -d: -f3)
export COMPOSE_PROJECT_NAME=$(basename "$(dirname dirname "$PWD")")
docker compose exec --index=${1:-1} dev bash

