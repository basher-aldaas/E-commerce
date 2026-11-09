#!/bin/sh
# Container startup for the Warehouse service.
set -eu

# 1. Create the tables if they don't exist. CREATE TABLE IF NOT EXISTS never
#    touches existing rows, so this is safe on every boot.
python -m app.cli init-db

# 2. Sample stock (INSERT IGNORE: existing products keep their quantity).
#    Set WAREHOUSE_SEED=false to skip it.
if [ "${WAREHOUSE_SEED:-true}" = "true" ]; then
    python -m app.cli seed
fi

# 3. Start the gRPC server.
exec python -m app.grpc_server
