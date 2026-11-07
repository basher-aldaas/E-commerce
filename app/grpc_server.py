import logging
from concurrent import futures

import grpc

from . import warehouse_pb2, warehouse_pb2_grpc
from .config import settings
from .inventory_service import InventoryService, InvalidRequest

log = logging.getLogger("warehouse.grpc")


def _code(result) -> int:
    return warehouse_pb2.ResultCode.Value(f"RESULT_{result.code}")


class WarehouseServicer(warehouse_pb2_grpc.WarehouseServicer):
    """يحوّل رسائل gRPC لاستدعاءات InventoryService وبالعكس، ولا يحتوي منطق."""

    def __init__(self, service: InventoryService):
        self._service = service

    def _run(self, context, work):
        try:
            return work()
        except InvalidRequest as exc:
            context.abort(grpc.StatusCode.INVALID_ARGUMENT, str(exc))
        except Exception:
            log.exception("internal error")
            context.abort(grpc.StatusCode.INTERNAL, "internal error")

    def GetStock(self, request, context):
        def work():
            levels = self._service.get_stock(request.product_ids)
            return warehouse_pb2.StockResponse(
                items=[
                    warehouse_pb2.StockLevel(
                        product_id=pid, quantity=level.quantity, tracked=level.tracked
                    )
                    for pid, level in levels.items()
                ]
            )
        return self._run(context, work)

    def Reserve(self, request, context):
        def work():
            items = [(i.product_id, i.quantity) for i in request.items]
            result = self._service.reserve(request.reservation_key, items)
            reply = warehouse_pb2.ReserveResponse(
                success=result.success, reason=result.reason, code=_code(result)
            )
            if result.shortage is not None:
                reply.shortage.product_id = result.shortage.product_id
                reply.shortage.requested = result.shortage.requested
                reply.shortage.available = result.shortage.available
            return reply
        return self._run(context, work)

    def Confirm(self, request, context):
        def work():
            result = self._service.confirm(request.reservation_key)
            return warehouse_pb2.ConfirmResponse(
                success=result.success, reason=result.reason, code=_code(result)
            )
        return self._run(context, work)

    def Release(self, request, context):
        def work():
            result = self._service.release(request.reservation_key)
            return warehouse_pb2.ReleaseResponse(
                success=result.success, reason=result.reason, code=_code(result)
            )
        return self._run(context, work)

    def ReturnStock(self, request, context):
        def work():
            result = self._service.return_stock(request.reservation_key)
            return warehouse_pb2.ReturnStockResponse(
                success=result.success, reason=result.reason, code=_code(result)
            )
        return self._run(context, work)

    def InitializeStock(self, request, context):
        def work():
            result = self._service.initialize_stock(request.product_id, request.quantity)
            return warehouse_pb2.InitializeStockResponse(
                success=result.success,
                reason=result.reason,
                code=_code(result),
                quantity=result.quantity,
            )
        return self._run(context, work)

    def SetStock(self, request, context):
        def work():
            result = self._service.set_stock(request.product_id, request.quantity)
            return warehouse_pb2.SetStockResponse(
                success=result.success,
                reason=result.reason,
                code=_code(result),
                quantity=result.quantity,
            )
        return self._run(context, work)


def create_server(
    port: int = settings.grpc_port,
    max_workers: int = settings.max_workers,
    host: str = settings.grpc_host,
):
    """يرجّع (server, bound_port). استخدم port=0 للاختبارات عشان ياخد منفذ حر."""
    server = grpc.server(futures.ThreadPoolExecutor(max_workers=max_workers))
    warehouse_pb2_grpc.add_WarehouseServicer_to_server(
        WarehouseServicer(InventoryService()), server
    )
    bound = server.add_insecure_port(f"{host}:{port}")
    return server, bound


def serve():
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
    server, bound = create_server()
    server.start()
    log.info("warehouse gRPC listening on %s:%d", settings.grpc_host, bound)
    server.wait_for_termination()


if __name__ == "__main__":
    serve()
