"""اختبار end-to-end: سيرفر gRPC حقيقي + عميل gRPC."""

import socket
import unittest
import uuid

import grpc

from app import warehouse_pb2 as pb
from app import warehouse_pb2_grpc as pb_grpc
from app.grpc_server import create_server
from tests.test_inventory import reset_tables, quantities

P1 = 201


def new_key():
    return str(uuid.uuid4())


class GrpcEndToEndTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server, cls.port = create_server(port=0)
        cls.server.start()
        cls.channel = grpc.insecure_channel(f"localhost:{cls.port}")
        cls.stub = pb_grpc.WarehouseStub(cls.channel)

    @classmethod
    def tearDownClass(cls):
        cls.channel.close()
        cls.server.stop(None)

    def reserve(self, key, quantity=2):
        return self.stub.Reserve(pb.ReserveRequest(
            reservation_key=key, items=[pb.StockItem(product_id=P1, quantity=quantity)]))

    def test_full_flow_over_grpc(self):
        reset_tables({P1: 5})

        stock = self.stub.GetStock(pb.StockRequest(product_ids=[P1, 999]))
        self.assertEqual(
            [(i.product_id, i.quantity, i.tracked) for i in stock.items],
            [(P1, 5, True), (999, 0, False)],
        )

        key = new_key()
        reserve = self.reserve(key)
        self.assertTrue(reserve.success)
        self.assertEqual(reserve.code, pb.RESULT_OK)

        confirm = self.stub.Confirm(pb.ConfirmRequest(reservation_key=key))
        self.assertTrue(confirm.success)
        self.assertEqual(quantities(P1), [3])

        returned = self.stub.ReturnStock(pb.ReturnStockRequest(reservation_key=key))
        self.assertTrue(returned.success)
        self.assertEqual(quantities(P1), [5])

    def test_insufficient_stock_returns_details_not_an_error(self):
        reset_tables({P1: 1})
        reserve = self.reserve(new_key(), quantity=5)
        self.assertFalse(reserve.success)
        self.assertEqual(reserve.code, pb.RESULT_INSUFFICIENT_STOCK)
        self.assertEqual(
            (reserve.shortage.product_id, reserve.shortage.requested, reserve.shortage.available),
            (P1, 5, 1),
        )

    def test_successful_reserve_has_no_shortage(self):
        reset_tables({P1: 5})
        self.assertFalse(self.reserve(new_key()).HasField("shortage"))

    def test_release_records_unknown_keys_over_grpc(self):
        reset_tables({P1: 5})
        key = new_key()
        release = self.stub.Release(pb.ReleaseRequest(reservation_key=key))
        self.assertTrue(release.success)
        self.assertEqual(release.code, pb.RESULT_OK)

        late = self.reserve(key)
        self.assertFalse(late.success)
        self.assertEqual(late.code, pb.RESULT_ALREADY_RELEASED)
        self.assertEqual(quantities(P1), [5])

    def test_initialize_and_set_stock_over_grpc(self):
        reset_tables({})
        created = self.stub.InitializeStock(pb.InitializeStockRequest(product_id=P1, quantity=7))
        self.assertTrue(created.success)
        self.assertEqual(created.quantity, 7)

        refused = self.stub.InitializeStock(pb.InitializeStockRequest(product_id=P1, quantity=99))
        self.assertFalse(refused.success)
        self.assertEqual(refused.code, pb.RESULT_ALREADY_INITIALIZED)
        self.assertEqual(refused.quantity, 7)

        updated = self.stub.SetStock(pb.SetStockRequest(product_id=P1, quantity=3))
        self.assertTrue(updated.success)
        self.assertEqual(updated.quantity, 3)
        self.assertEqual(quantities(P1), [3])

    def test_invalid_input_returns_invalid_argument(self):
        calls = {
            "zero quantity": lambda: self.stub.Reserve(pb.ReserveRequest(
                reservation_key=new_key(), items=[pb.StockItem(product_id=P1, quantity=0)])),
            "bad key": lambda: self.stub.Confirm(pb.ConfirmRequest(reservation_key="nope")),
            "missing key": lambda: self.stub.Release(pb.ReleaseRequest()),
            "negative stock": lambda: self.stub.SetStock(pb.SetStockRequest(product_id=P1, quantity=-1)),
        }
        for name, call in calls.items():
            with self.subTest(name), self.assertRaises(grpc.RpcError) as ctx:
                call()
            self.assertEqual(ctx.exception.code(), grpc.StatusCode.INVALID_ARGUMENT, name)

    def test_expired_deadline_is_reported_to_the_client(self):
        with self.assertRaises(grpc.RpcError) as ctx:
            self.stub.GetStock(pb.StockRequest(product_ids=[P1]), timeout=0.000001)
        self.assertEqual(ctx.exception.code(), grpc.StatusCode.DEADLINE_EXCEEDED)

    def test_server_only_listens_on_loopback_by_default(self):
        address = socket.gethostbyname(socket.gethostname())
        if address.startswith("127."):
            self.skipTest("this machine has no non-loopback address to test against")
        with self.assertRaises(OSError):
            socket.create_connection((address, self.port), timeout=1)


if __name__ == "__main__":
    unittest.main()
