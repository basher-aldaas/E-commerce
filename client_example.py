"""مثال عميل بسيط يستدعي الخدمة (للتجربة اليدوية، مش جزء من السيرفر).

    python client_example.py
"""

import grpc

from app import warehouse_pb2 as pb
from app import warehouse_pb2_grpc as pb_grpc

TIMEOUT_SECONDS = 3  # نفس فكرة الـ deadline اللي بنستخدمه من Laravel


def main():
    with grpc.insecure_channel("localhost:50051") as channel:
        stub = pb_grpc.WarehouseStub(channel)

        stock = stub.GetStock(pb.StockRequest(product_ids=[1, 2]), timeout=TIMEOUT_SECONDS)
        print("stock:", [(i.product_id, i.quantity) for i in stock.items])

        reply = stub.Reserve(
            pb.ReserveRequest(order_id=5001, items=[pb.StockItem(product_id=1, quantity=2)]),
            timeout=TIMEOUT_SECONDS,
        )
        print("reserve:", reply.success, reply.reason)

        reply = stub.Confirm(pb.ConfirmRequest(order_id=5001), timeout=TIMEOUT_SECONDS)
        print("confirm:", reply.success, reply.reason)


if __name__ == "__main__":
    main()
