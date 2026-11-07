#!/usr/bin/env bash
# يولّد كلاسات Python من ملف الـ proto داخل مجلد app/
set -euo pipefail
cd "$(dirname "$0")"
python -m grpc_tools.protoc -I proto \
  --python_out=app \
  --grpc_python_out=app \
  proto/warehouse.proto
# تصحيح الاستيراد عشان يشتغل كجزء من الـ package
sed -i 's/^import warehouse_pb2/from . import warehouse_pb2/' app/warehouse_pb2_grpc.py
echo "generated app/warehouse_pb2.py and app/warehouse_pb2_grpc.py"
