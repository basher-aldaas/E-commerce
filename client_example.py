"""مثال عميل بسيط يستدعي الخدمة (للتجربة اليدوية، مش جزء من السيرفر).

    python client_example.py                 reads stock only
    python client_example.py --reserve-demo  also reserves and releases one unit

The demo changes stock briefly on whichever database the server is using.
"""

import sys
import uuid

import grpc

from app import warehouse_pb2 as pb
from app import warehouse_pb2_grpc as pb_grpc

TIMEOUT_SECONDS = 3  # نفس فكرة الـ deadline اللي بنستخدمه من Laravel


def main():
    with grpc.insecure_channel("localhost:50051") as channel:
        stub = pb_grpc.WarehouseStub(channel)

        stock = stub.GetStock(pb.StockRequest(product_ids=[1, 2]), timeout=TIMEOUT_SECONDS)
        print("stock:", [(i.product_id, i.quantity, i.tracked) for i in stock.items])

        if "--reserve-demo" not in sys.argv:
            return

        key = str(uuid.uuid4())
        reply = stub.Reserve(
            pb.ReserveRequest(reservation_key=key, items=[pb.StockItem(product_id=1, quantity=1)]),
            timeout=TIMEOUT_SECONDS,
        )
        print("reserve:", reply.success, reply.reason)

        reply = stub.Release(pb.ReleaseRequest(reservation_key=key), timeout=TIMEOUT_SECONDS)
        print("release:", reply.success, reply.reason)


if __name__ == "__main__":
    main()
