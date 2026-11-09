#!/usr/bin/env bash
# Generates the PHP message classes in app/Grpc/Warehouse from the Warehouse
# service's proto file. Never edit those classes by hand; rerun this script.
#
#   PROTOC=/path/to/protoc WAREHOUSE_SERVICE_PATH=/path/to/warehouse-service \
#     scripts/generate-warehouse-grpc.sh
#
# PROTOC must be the standalone compiler from
# https://github.com/protocolbuffers/protobuf/releases, because the compiler in
# Python's grpc_tools can't generate PHP. Only regenerating needs it; the classes
# are committed.
#
# grpc_php_plugin isn't needed: the client stub is written by hand in
# app/Services/Warehouse/WarehouseStub.php, so update it when RPCs change.
set -euo pipefail
cd "$(dirname "$0")/.."

PROTOC="${PROTOC:-protoc}"
SERVICE_PATH="${WAREHOUSE_SERVICE_PATH:-../../warehouse-service}"

OUTPUT="$(mktemp -d)"
trap 'rm -rf "$OUTPUT"' EXIT

"$PROTOC" -I "$SERVICE_PATH/proto" --php_out="$OUTPUT" "$SERVICE_PATH/proto/warehouse.proto"

rm -rf app/Grpc/Warehouse
mkdir -p app/Grpc
cp -r "$OUTPUT/App/Grpc/Warehouse" app/Grpc/Warehouse

echo "generated app/Grpc/Warehouse with $("$PROTOC" --version)"
