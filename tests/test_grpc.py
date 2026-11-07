"""اختبار end-to-end: سيرفر gRPC حقيقي + عميل gRPC."""

import unittest

import grpc

from app import warehouse_pb2 as pb
from app import warehouse_pb2_grpc as pb_grpc
from app.grpc_server import create_server
from tests.test_inventory import reset_tables, quantities

P1 = 201


class GrpcEndToEndTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server, port = create_server(port=0)
        cls.server.start()
        cls.channel = grpc.insecure_channel(f"localhost:{port}")
        cls.stub = pb_grpc.WarehouseStub(cls.channel)

    @classmethod
    def tearDownClass(cls):
        cls.channel.close()
        cls.server.stop(None)

    def test_full_flow_over_grpc(self):
        reset_tables({P1: 5})

        stock = self.stub.GetStock(pb.StockRequest(product_ids=[P1]))
        self.assertEqual(stock.items[0].quantity, 5)

        reserve = self.stub.Reserve(pb.ReserveRequest(
            order_id=7001, items=[pb.StockItem(product_id=P1, quantity=2)]))
        self.assertTrue(reserve.success)

        confirm = self.stub.Confirm(pb.ConfirmRequest(order_id=7001))
        self.assertTrue(confirm.success)
        self.assertEqual(quantities(P1), [3])

    def test_insufficient_stock_returns_reason_not_error(self):
        reset_tables({P1: 1})
        reserve = self.stub.Reserve(pb.ReserveRequest(
            order_id=7002, items=[pb.StockItem(product_id=P1, quantity=5)]))
        self.assertFalse(reserve.success)
        self.assertIn("insufficient stock", reserve.reason)

    def test_invalid_quantity_returns_invalid_argument(self):
        with self.assertRaises(grpc.RpcError) as ctx:
            self.stub.Reserve(pb.ReserveRequest(
                order_id=7003, items=[pb.StockItem(product_id=P1, quantity=0)]))
        self.assertEqual(ctx.exception.code(), grpc.StatusCode.INVALID_ARGUMENT)


if __name__ == "__main__":
    unittest.main()
