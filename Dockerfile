FROM python:3.12-slim

WORKDIR /srv/warehouse

# Install dependencies first so code changes don't reinstall them.
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

# Inside Docker the server must listen on all interfaces so the Laravel
# container can reach it. docker-compose publishes no port for it, so it
# stays reachable only on the internal Docker network.
ENV WAREHOUSE_GRPC_HOST=0.0.0.0 \
    WAREHOUSE_GRPC_PORT=50051 \
    PYTHONUNBUFFERED=1

EXPOSE 50051

CMD ["sh", "docker/entrypoint.sh"]
