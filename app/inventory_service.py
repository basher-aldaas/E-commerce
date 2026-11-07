"""منطق المستودع. ما فيه أي شي عن gRPC هون، فبنقدر نختبره لحاله."""

import logging
import time
from dataclasses import dataclass

import pymysql

from . import repository as repo
from .db import transaction

log = logging.getLogger("warehouse.inventory")

DEADLOCK_ERROR = 1213
DUPLICATE_KEY_ERROR = 1062
MAX_ATTEMPTS = 3


class InvalidRequest(ValueError):
    """طلب مش صالح (مثلاً كمية سالبة). بيتحول لـ INVALID_ARGUMENT بالـ gRPC."""


class _Rejected(Exception):
    """رفض منطقي داخل transaction. بيعمل rollback ويرجع السبب للعميل."""

    def __init__(self, reason: str):
        super().__init__(reason)
        self.reason = reason


@dataclass(frozen=True)
class Result:
    success: bool
    reason: str = ""


class InventoryService:
    # ---------- GetStock ----------
    def get_stock(self, product_ids) -> dict:
        ids = sorted({self._positive(pid, "product_id") for pid in product_ids})
        with transaction() as cur:
            found = repo.get_quantities(cur, ids)
        return {pid: found.get(pid, 0) for pid in ids}

    # ---------- Reserve ----------
    def reserve(self, order_id: int, items) -> Result:
        order_id = self._positive(order_id, "order_id")
        merged = self._merge_items(items)
        return self._with_retry(lambda: self._reserve_once(order_id, merged))

    def _reserve_once(self, order_id, merged) -> Result:
        try:
            with transaction() as cur:
                try:
                    reservation_id = repo.insert_reservation(cur, order_id)
                except pymysql.err.IntegrityError as exc:
                    if exc.args[0] != DUPLICATE_KEY_ERROR:
                        raise
                    # الطلب محجوز قبل (idempotency): نرجع نفس النتيجة بدون خصم جديد
                    existing = repo.get_reservation(cur, order_id)
                    if existing["status"] == repo.RELEASED:
                        return Result(False, "reservation was already released")
                    return Result(True, "already reserved")

                # ترتيب المنتجات ثابت لتفادي الـ deadlock بين طلبات متزامنة
                for product_id, quantity in sorted(merged.items()):
                    if not repo.decrement_if_enough(cur, product_id, quantity):
                        available = repo.get_quantities(cur, [product_id]).get(product_id)
                        if available is None:
                            raise _Rejected(f"product {product_id} not found in stock")
                        raise _Rejected(
                            f"insufficient stock for product {product_id}: "
                            f"requested {quantity}, available {available}"
                        )

                for product_id, quantity in merged.items():
                    repo.insert_item(cur, reservation_id, product_id, quantity)
            return Result(True)
        except _Rejected as rejected:
            return Result(False, rejected.reason)

    # ---------- Confirm ----------
    def confirm(self, order_id: int) -> Result:
        order_id = self._positive(order_id, "order_id")
        with transaction() as cur:
            if repo.confirm_if_reserved(cur, order_id):
                return Result(True)
            current = repo.get_reservation(cur, order_id)

        if current is None:
            return Result(False, "reservation not found")
        if current["status"] == repo.CONFIRMED:
            return Result(True, "already confirmed")
        return Result(False, f"cannot confirm reservation in status {current['status']}")

    # ---------- Release ----------
    def release(self, order_id: int) -> Result:
        order_id = self._positive(order_id, "order_id")
        return self._with_retry(lambda: self._release_once(order_id))

    def _release_once(self, order_id) -> Result:
        try:
            with transaction() as cur:
                reservation = repo.get_reservation_for_update(cur, order_id)
                if reservation is None:
                    raise _Rejected("reservation not found")
                if reservation["status"] == repo.RELEASED:
                    return Result(True, "already released")
                if reservation["status"] == repo.CONFIRMED:
                    # بعد التأكيد ما بنرجّع الكمية تلقائياً (قرار مؤكد)
                    raise _Rejected("cannot release a confirmed reservation")

                for item in repo.get_items(cur, reservation["id"]):
                    repo.increment(cur, item["product_id"], item["quantity"])
                repo.set_status(cur, reservation["id"], repo.RELEASED)
            return Result(True)
        except _Rejected as rejected:
            return Result(False, rejected.reason)

    # ---------- helpers ----------
    @staticmethod
    def _positive(value, name) -> int:
        try:
            number = int(value)
        except (TypeError, ValueError):
            raise InvalidRequest(f"{name} must be an integer")
        if number <= 0:
            raise InvalidRequest(f"{name} must be positive")
        return number

    def _merge_items(self, items) -> dict:
        if not items:
            raise InvalidRequest("items must not be empty")
        merged: dict[int, int] = {}
        for item in items:
            product_id = self._positive(item[0], "product_id")
            quantity = self._positive(item[1], "quantity")
            merged[product_id] = merged.get(product_id, 0) + quantity
        return merged

    @staticmethod
    def _with_retry(work):
        for attempt in range(1, MAX_ATTEMPTS + 1):
            try:
                return work()
            except pymysql.err.OperationalError as exc:
                if exc.args[0] != DEADLOCK_ERROR or attempt == MAX_ATTEMPTS:
                    raise
                log.warning("deadlock detected, retrying (attempt %d)", attempt)
                time.sleep(0.01 * attempt)
